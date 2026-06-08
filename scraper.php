<?php
/**
 * WooCommerce Easy Scraper - Live AJAX Version
 * With Smart Link Finder, Live Preview, Visual Selector, Auto-Suggest, Advanced Pagination, Price/Export Management, Server-Side Profiles, Mobile Tabs & Detail Fields Extraction
 */

ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(600);

const DEFAULT_URL = 'https://barfbox.ir/search/?page=1';
const FETCH_MISSING_IMAGES = true;
const PROFILES_FILE = __DIR__ . '/profiles.json';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function persianToEnglish(string $str): string {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($arabic, $english, str_replace($persian, $english, $str));
}

function normalize_text(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function normalize_html(string $html): string {
    $html = preg_replace('~<script[^>]*>.*?</script>~is', '', $html);
    $html = preg_replace('~<style[^>]*>.*?</style>~is', '', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\s+/u', ' ', $html);
    return trim($html);
}

function make_absolute_url(string $url, string $base): string {
    $url = trim($url);
    if ($url === '' || preg_match('~^(data:|javascript:|#)~i', $url)) return '';
    if (preg_match('~^https?://~i', $url)) return $url;
    if (strpos($url, '//') === 0) return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
    $bp = parse_url($base);
    if (!$bp || empty($bp['host'])) return $url;
    $root = ($bp['scheme'] ?? 'https') . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
    if (isset($url[0]) && $url[0] === '/') return $root . $url;
    $dir = preg_replace('~/[^/]*$~', '/', $bp['path'] ?? '/');
    return $root . $dir . $url;
}

function profileKey(string $url): string {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return md5($url);
    $host = strtolower($parts['host']);
    $path = trim($parts['path'] ?? '/', '/');
    $path = preg_replace('~/page/\d+/?$~i', '', $path);
    $path = preg_replace('~\.(html|htm|php)$~i', '', $path);
    return $host . ($path ? '_' . preg_replace('~[^a-z0-9]+~i', '_', $path) : '');
}

function loadProfiles(): array {
    if (!file_exists(PROFILES_FILE)) return [];
    $json = @file_get_contents(PROFILES_FILE);
    if (!$json) return [];
    $data = @json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveProfiles(array $profiles): bool {
    $json = json_encode($profiles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return @file_put_contents(PROFILES_FILE, $json, LOCK_EX) !== false;
}

function fetch_html(string $url, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: fa,en;q=0.9'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    curl_close($ch);
    if (!$body) return ['ok' => false, 'error' => $err ?: 'Empty', 'code' => $code, 'url' => $finalUrl, 'html' => ''];
    if ($code >= 400) return ['ok' => false, 'error' => 'HTTP ' . $code, 'code' => $code, 'url' => $finalUrl, 'html' => $body];
    return ['ok' => true, 'error' => '', 'code' => $code, 'url' => $finalUrl, 'html' => $body];
}

if (!empty($_GET['image_proxy'])) {
    $url = trim($_GET['image_proxy']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); exit; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $body = curl_exec($ch);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
    curl_close($ch);
    if (!$body) { http_response_code(404); exit; }
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=86400');
    echo $body;
    exit;
}

if (isset($_GET['profiles'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $profiles = loadProfiles();
    $list = [];
    foreach ($profiles as $key => $p) {
        $list[] = [
            'key' => $key,
            'name' => $p['name'] ?? $key,
            'url' => $p['url'] ?? '',
            'updatedAt' => $p['updatedAt'] ?? 0
        ];
    }
    usort($list, fn($a, $b) => ($b['updatedAt'] ?? 0) <=> ($a['updatedAt'] ?? 0));
    echo json_encode(['ok' => true, 'profiles' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_GET['load_profile'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $url = trim($_GET['load_profile']);
    $key = profileKey($url);
    $profiles = loadProfiles();
    if (isset($profiles[$key])) {
        echo json_encode(['ok' => true, 'profile' => $profiles[$key]], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Not found', 'key' => $key], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_POST['action'] ?? '') === 'save_profile') {
    header('Content-Type: application/json; charset=UTF-8');
    $url = trim($_POST['url'] ?? '');
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
        exit;
    }
    $key = profileKey($url);
    $profiles = loadProfiles();
    $detailSelectors = json_decode($_POST['detailSelectors'] ?? '{}', true) ?: [];
    $profiles[$key] = [
        'url' => $url,
        'name' => trim($_POST['name'] ?? '') ?: parse_url($url, PHP_URL_HOST),
        'pages' => max(1, min(100, (int)($_POST['pages'] ?? 10))),
        'pagType' => $_POST['pagType'] ?? 'query_page',
        'pagVal' => $_POST['pagVal'] ?? '',
        'selectors' => json_decode($_POST['selectors'] ?? '{}', true) ?: [],
        'detailSelectors' => $detailSelectors,
        'titleSuffix' => $_POST['titleSuffix'] ?? '',
        'priceMode' => $_POST['priceMode'] ?? 'none',
        'priceVal' => (float)($_POST['priceVal'] ?? 0),
        'roundPrice' => $_POST['roundPrice'] ?? '0',
        'minPrice' => (int)($_POST['minPrice'] ?? 10000),
        'useCustomCol' => !empty($_POST['useCustomCol']),
        'customColName' => $_POST['customColName'] ?? '',
        'customColVal' => $_POST['customColVal'] ?? '',
        'updatedAt' => time()
    ];
    if (saveProfiles($profiles)) {
        echo json_encode(['ok' => true, 'key' => $key, 'message' => 'پروفایل ذخیره شد']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'خطا در نوشتن فایل']);
    }
    exit;
}

if (($_POST['action'] ?? '') === 'delete_profile') {
    header('Content-Type: application/json; charset=UTF-8');
    $url = trim($_POST['url'] ?? '');
    if (!$url) {
        echo json_encode(['ok' => false, 'error' => 'No URL']);
        exit;
    }
    $key = profileKey($url);
    $profiles = loadProfiles();
    if (isset($profiles[$key])) {
        unset($profiles[$key]);
        saveProfiles($profiles);
        echo json_encode(['ok' => true, 'key' => $key, 'message' => 'پروفایل حذف شد']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Not found']);
    }
    exit;
}

// Test selector - fetch URL and extract sample value
if (!empty($_GET['test_selector'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $url = trim($_GET['test_selector']);
    $type = $_GET['type'] ?? 'text';
    $selector = $_GET['selector'] ?? '';
    
    if (!filter_var($url, FILTER_VALIDATE_URL) || !$selector) {
        echo json_encode(['ok' => false, 'error' => 'Invalid params']);
        exit;
    }
    
    $res = fetch_html($url, 15);
    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'error' => $res['error']]);
        exit;
    }
    
    [$dom, $xp] = load_dom($res['html']);
    $baseUrl = $res['url'];
    
    // Test the selector on the page - try to find a container first
    $xpath = cssToXpath($selector);
    $nodes = @$xp->query($xpath);
    
    if (!$nodes || !$nodes->length) {
        echo json_encode(['ok' => false, 'error' => 'المان یافت نشد']);
        exit;
    }
    
    $node = $nodes->item(0);
    $result = ['ok' => true, 'count' => $nodes->length];
    
    if ($type === 'title') {
        $result['value'] = normalize_text($node->textContent);
    } elseif ($type === 'price') {
        $result['value'] = extractPrice($node->textContent);
    } elseif ($type === 'link') {
        $link = '';
        if ($node instanceof DOMElement) {
            if ($node->tagName === 'a') {
                $link = $node->getAttribute('href') ?: $node->getAttribute('data-href') ?: '';
            } else {
                // Look for link in children or data attributes
                foreach (['data-href', 'data-link', 'data-url', 'data-product-url'] as $attr) {
                    $v = $node->getAttribute($attr);
                    if ($v && $v !== '#') { $link = $v; break; }
                }
                if (!$link) {
                    $aNodes = @$xp->query('.//a[@href]', $node);
                    if ($aNodes && $aNodes->length) {
                        $link = $aNodes->item(0)->getAttribute('href');
                    }
                }
                if (!$link) {
                    // Check parent elements for link (up to 5 levels)
                    $parent = $node->parentNode;
                    for ($i = 0; $i < 5 && $parent instanceof DOMElement; $i++) {
                        if ($parent->tagName === 'a' && $parent->getAttribute('href')) {
                            $link = $parent->getAttribute('href');
                            break;
                        }
                        foreach (['data-href', 'data-link', 'data-url', 'data-product-url'] as $attr) {
                            $v = $parent->getAttribute($attr);
                            if ($v && $v !== '#') { $link = $v; break 2; }
                        }
                        $parent = $parent->parentNode;
                    }
                }
            }
        }
        $result['value'] = make_absolute_url($link, $baseUrl);
    } elseif ($type === 'image') {
        $img = '';
        if ($node instanceof DOMElement) {
            if ($node->tagName === 'img') {
                foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                    $v = $node->getAttribute($attr);
                    if ($v && url_is_image($v)) { $img = $v; break; }
                }
            } else {
                $imgNodes = @$xp->query('.//img', $node);
                if ($imgNodes && $imgNodes->length) {
                    foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                        $v = $imgNodes->item(0)->getAttribute($attr);
                        if ($v && url_is_image($v)) { $img = $v; break; }
                    }
                }
            }
        }
        $result['value'] = make_absolute_url($img, $baseUrl);
    } else {
        $result['value'] = normalize_text($node->textContent);
    }
    
    $result['value'] = mb_substr($result['value'], 0, 500);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_GET['suggest_selectors'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $url = trim($_GET['suggest_selectors']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
        exit;
    }
    
    $res = fetch_html($url, 15);
    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'error' => $res['error']]);
        exit;
    }
    
    $html = $res['html'];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML('<?xml encoding="UTF-8"><meta charset="UTF-8">' . $html, LIBXML_NOERROR);
    $xp = new DOMXPath($dom);
    libxml_clear_errors();
    
    $suggestions = ['container' => [], 'title' => [], 'price' => [], 'link' => [], 'image' => []];
    
    $containerPatterns = [
        ['selector' => 'li.product', 'xpath' => "//li[contains(@class,'product')]"],
        ['selector' => 'div.product', 'xpath' => "//div[contains(@class,'product')]"],
        ['selector' => 'div.product-card', 'xpath' => "//div[contains(@class,'product-card')]"],
        ['selector' => 'div.product-item', 'xpath' => "//div[contains(@class,'product-item')]"],
        ['selector' => 'article.product', 'xpath' => "//article[contains(@class,'product')]"],
        ['selector' => 'div.card', 'xpath' => "//div[contains(@class,'card')]"],
        ['selector' => 'div.col', 'xpath' => "//div[contains(@class,'col') and .//a and .//img]"],
        ['selector' => 'div.item', 'xpath' => "//div[contains(@class,'item') and .//a and .//img]"],
        ['selector' => 'li.col', 'xpath' => "//li[contains(@class,'col')]"],
    ];
    
    foreach ($containerPatterns as $p) {
        $nodes = @$xp->query($p['xpath']);
        if ($nodes && $nodes->length >= 2) {
            $suggestions['container'][] = ['selector' => $p['selector'], 'count' => $nodes->length];
        }
    }
    
    $titlePatterns = [
        ['selector' => 'h2.title', 'xpath' => ".//h2[contains(@class,'title')]"],
        ['selector' => 'h3.title', 'xpath' => ".//h3[contains(@class,'title')]"],
        ['selector' => 'h2.product-title', 'xpath' => ".//h2[contains(@class,'product-title')]"],
        ['selector' => 'a.product-title', 'xpath' => ".//a[contains(@class,'product-title')]"],
        ['selector' => 'h2 a', 'xpath' => ".//h2//a"],
        ['selector' => 'h3 a', 'xpath' => ".//h3//a"],
        ['selector' => 'h4 a', 'xpath' => ".//h4//a"],
        ['selector' => 'a.title', 'xpath' => ".//a[contains(@class,'title')]"],
        ['selector' => 'span.title', 'xpath' => ".//span[contains(@class,'title')]"],
        ['selector' => 'div.title a', 'xpath' => ".//div[contains(@class,'title')]//a"],
    ];
    
    $suggestions['title'] = array_map(fn($p) => ['selector' => $p['selector']], $titlePatterns);
    
    $pricePatterns = [
        ['selector' => 'span.price', 'xpath' => ".//span[contains(@class,'price')]"],
        ['selector' => 'div.price', 'xpath' => ".//div[contains(@class,'price')]"],
        ['selector' => 'span.amount', 'xpath' => ".//span[contains(@class,'amount')]"],
        ['selector' => 'ins .amount', 'xpath' => ".//ins//span[contains(@class,'amount')]"],
        ['selector' => 'span.woocommerce-Price-amount', 'xpath' => ".//span[contains(@class,'woocommerce-Price')]"],
        ['selector' => 'p.price', 'xpath' => ".//p[contains(@class,'price')]"],
    ];
    
    $suggestions['price'] = array_map(fn($p) => ['selector' => $p['selector']], $pricePatterns);
    
    $suggestions['link'] = [
        ['selector' => 'a.woocommerce-LoopProduct-link'],
        ['selector' => 'a.product-link'],
        ['selector' => 'a[href*="product"]'],
        ['selector' => 'h2 a'],
        ['selector' => 'h3 a'],
        ['selector' => 'a.title'],
    ];
    
    $suggestions['image'] = [
        ['selector' => 'img.wp-post-image'],
        ['selector' => 'img.product-image'],
        ['selector' => 'img.attachment-woocommerce'],
        ['selector' => 'img[data-src]'],
        ['selector' => 'img.lazy'],
        ['selector' => 'img'],
    ];
    
    echo json_encode(['ok' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_GET['suggest_detail_selectors'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $url = trim($_GET['suggest_detail_selectors']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
        exit;
    }
    
    $res = fetch_html($url, 15);
    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'error' => $res['error']]);
        exit;
    }
    
    $html = $res['html'];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML('<?xml encoding="UTF-8"><meta charset="UTF-8">' . $html, LIBXML_NOERROR);
    $xp = new DOMXPath($dom);
    libxml_clear_errors();
    
    $suggestions = [
        'shortDesc' => [
            'div.woocommerce-product-details__short-description',
            'div.product-short-description',
            '.summary .woocommerce-product-details__short-description',
            '.summary > p',
        ],
        'longDesc' => [
            'div.woocommerce-Tabs-panel--description',
            'div#tab-description',
            '.woocommerce-tabs .panel',
            'div.product-description',
            '#description',
        ],
        'sku' => [
            'span.sku',
            'span.sku_wrapper',
            '.product_meta .sku',
        ],
        'category' => [
            'span.posted_in a',
            '.product_meta .posted_in a',
            '.product_meta .posted_in',
        ],
        'tags' => [
            'span.tagged_as a',
            '.product_meta .tagged_as a',
        ],
        'weight' => [
            'span.weight',
            '.product_meta .weight',
        ],
        'stock' => [
            'p.stock',
            'span.stock',
            '.stock.in-stock',
        ],
        'brand' => [
            'span.posted_in a',
            '.product_meta .brand',
        ],
    ];
    
    $found = [];
    foreach ($suggestions as $field => $selectors) {
        $found[$field] = [];
        foreach ($selectors as $sel) {
            $xpath = cssToXpath($sel);
            if (!$xpath) continue;
            $nodes = @$xp->query($xpath);
            if ($nodes && $nodes->length) {
                $text = trim($nodes->item(0)->textContent);
                if ($text && mb_strlen($text) < 5000) {
                    $found[$field][] = [
                        'selector' => $sel,
                        'preview' => mb_substr($text, 0, 80) . (mb_strlen($text) > 80 ? '...' : '')
                    ];
                }
            }
        }
    }
    
    echo json_encode(['ok' => true, 'suggestions' => $found], JSON_UNESCAPED_UNICODE);
    exit;
}

// Detail visual proxy with Smart Link Finder and Live Preview
if (!empty($_GET['detail_proxy'])) {
    $url = trim($_GET['detail_proxy']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); echo 'Invalid URL'; exit; }
    
    $res = fetch_html($url, 15);
    if (!$res['ok']) { http_response_code(500); echo 'Failed: ' . $res['error']; exit; }
    
    $html = $res['html'];
    $baseUrl = $res['url'];
    
    $html = preg_replace('~<script[^>]*>.*?</script>~is', '', $html);
    $html = preg_replace('~<iframe[^>]*>.*?</iframe>~is', '', $html);
    $html = preg_replace('~<video[^>]*>.*?</video>~is', '', $html);
    $html = preg_replace('~<audio[^>]*>.*?</audio>~is', '', $html);
    
    $baseTag = '<base href="' . h($baseUrl) . '">';
    
    $script = <<<'SCRIPT'
<style>
*{cursor:crosshair!important}
.__h{outline:3px solid #a855f7!important;outline-offset:2px}
.__s{outline:3px solid #22c55e!important;background:rgba(168,85,247,.08)!important}
.__bar{position:fixed;top:0;left:0;right:0;background:linear-gradient(180deg,#581c87,#3b0764);color:#fff;padding:0;z-index:999999;font:13px Tahoma,sans-serif;box-shadow:0 4px 20px rgba(0,0,0,.6)}
.__row{display:flex;gap:8px;align-items:center;padding:8px 14px;flex-wrap:wrap}
.__row2{display:flex;gap:6px;align-items:center;padding:4px 14px 8px;flex-wrap:wrap;border-top:1px solid #6b21a8}
.__bar select,.__bar button{padding:7px 12px;border-radius:6px;border:1px solid #7e22ce;background:#6b21a8;color:#fff;font:inherit;cursor:pointer;white-space:nowrap}
.__bar button:hover{background:#7e22ce;border-color:#c084fc}
.ok{background:#22c55e!important;color:#000!important;border-color:#22c55e!important}
.no{background:#ef4444!important;border-color:#ef4444!important}
.__sel{background:#1e1b4b;padding:5px 10px;border-radius:4px;font-family:monospace;font-size:11px;color:#f0abfc;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.__tag{background:#4c1d95;padding:3px 8px;border-radius:4px;font-family:monospace;font-size:11px;color:#e9d5ff}
.__preview{background:#0f172a;padding:6px 10px;border-radius:4px;font-size:11px;color:#86efac;max-width:500px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border:1px solid #22c55e;font-weight:700}
.__preview-warn{background:#78350f;color:#fbbf24;border-color:#f59e0b}
.__preview-err{background:#7f1d1d;color:#fca5a5;border-color:#ef4444}
body{padding-top:100px!important}
</style>
<div class="__bar">
<div class="__row">
  <select id="__m">
    <option value="shortDesc">📝 توضیحات کوتاه</option>
    <option value="longDesc">📄 توضیحات بلند</option>
    <option value="sku">🏷️ SKU</option>
    <option value="category">📂 دسته‌بندی</option>
    <option value="tags">🔖 برچسب‌ها</option>
    <option value="weight">⚖️ وزن</option>
    <option value="stock">📦 موجودی</option>
    <option value="brand">🏭 برند</option>
  </select>
  <span class="__sel" id="__sel">کلیک کنید...</span>
  <button class="ok" onclick="__done()">✅ اتمام</button>
  <button class="no" onclick="parent.postMessage({type:'cancel_detail'},'*')">✕</button>
</div>
<div class="__row2">
  <span class="__tag" id="__tag">-</span>
  <span id="__preview" class="__preview">در انتظار انتخاب...</span>
</div>
</div>
<script>
(function(){
var S={},cur=null,picked=null;

function gs(el){
  if(!el||el.tagName=='BODY'||el.tagName=='HTML')return'';
  var t=el.tagName.toLowerCase();
  var c=Array.from(el.classList).filter(function(x){return !x.startsWith('__')&&x.length<40});
  if(c.length)return t+'.'+c.slice(0,3).join('.');
  if(el.id&&el.id.length<30&&!/^__/.test(el.id))return t+'#'+el.id;
  return t;
}

function extractPrice(t){
  t=t.replace(/\s+/g,' ').trim();
  var m=t.match(/([\d۰-۹٠-٩][,،٬\s\d۰-۹٠-٩]*[\d۰-۹٠-٩])\s*(تومان|تومن|ریال)/);
  if(m)return m[1].trim();
  m=t.match(/([\d۰-۹٠-٩]{1,3}[,،٬][\d۰-۹٠-٩]{3}(?:[,،٬][\d۰-۹٠-٩]{3})*)/);
  if(m)return m[1].trim();
  return '';
}

function getImageUrl(el){
  if(!el)return '';
  if(el.tagName==='IMG'){
    var attrs=['data-src','data-lazy-src','data-original','src'];
    for(var i=0;i<attrs.length;i++){
      var v=el.getAttribute(attrs[i]);
      if(v&&v.indexOf('placeholder')<0&&v.indexOf('1x1')<0)return v;
    }
  }
  var img=el.querySelector('img');
  if(img){
    var attrs=['data-src','data-lazy-src','data-original','src'];
    for(var i=0;i<attrs.length;i++){
      var v=img.getAttribute(attrs[i]);
      if(v&&v.indexOf('placeholder')<0&&v.indexOf('1x1')<0)return v;
    }
  }
  return '';
}

function getPreview(el, mode){
  if(!el)return '';
  if(mode==='shortDesc'||mode==='longDesc'){
    var t=(el.textContent||'').replace(/\s+/g,' ').trim();
    return t||'(خالی)';
  }
  if(mode==='sku'){
    var t=(el.textContent||'').replace(/\s+/g,' ').trim();
    return t.replace(/[^a-zA-Z0-9-_]/g,'')||t;
  }
  if(mode==='category'||mode==='tags'||mode==='brand'){
    var links=el.querySelectorAll('a');
    var arr=[];
    links.forEach(function(l){arr.push(l.textContent.trim());});
    if(arr.length)return arr.join('، ');
    return (el.textContent||'').replace(/\s+/g,' ').trim();
  }
  if(mode==='weight'){
    var t=(el.textContent||'').replace(/\s+/g,' ').trim();
    return t;
  }
  if(mode==='stock'){
    var t=(el.textContent||'').replace(/\s+/g,' ').trim();
    return t;
  }
  return (el.textContent||'').replace(/\s+/g,' ').trim().substring(0,120);
}

function selectEl(el){
  if(!el||el.tagName=='BODY'||el.tagName=='HTML'||el.closest('.__bar'))return;
  var m=document.getElementById('__m').value;
  var s=gs(el);
  if(picked)picked.classList.remove('__s');
  el.classList.add('__s');
  el.classList.remove('__h');
  picked=el;S[m]=s;
  document.getElementById('__sel').textContent=s;
  document.getElementById('__tag').textContent=el.tagName.toLowerCase()+(el.className?' .'+Array.from(el.classList).slice(0,2).join('.'):'');
  
  var preview=getPreview(el,m);
  var previewEl=document.getElementById('__preview');
  previewEl.textContent=(preview||'(خالی)').substring(0,150);
  previewEl.className='__preview '+(preview?'':'__preview-warn');
}

document.addEventListener('mouseover',function(e){
  if(e.target.closest('.__bar'))return;
  if(cur&&cur!==picked)cur.classList.remove('__h');
  cur=e.target;
  if(cur!==picked)cur.classList.add('__h');
},true);

document.addEventListener('mouseout',function(e){
  if(e.target&&e.target!==picked)e.target.classList.remove('__h');
},true);

document.addEventListener('click',function(e){
  if(e.target.closest('.__bar'))return;
  e.preventDefault();e.stopPropagation();
  selectEl(e.target);
},true);

window.__done=function(){
  parent.postMessage({type:'detail_selectors',data:S},'*');
};

})();
</script>
SCRIPT;

    if (stripos($html, '<head') !== false) {
        $html = preg_replace('~(<head[^>]*>)~i', '$1' . $baseTag, $html, 1);
    } else {
        $html = $baseTag . $html;
    }
    
    $html = preg_replace('~</body>~i', $script . '</body>', $html);
    if (stripos($html, '</body>') === false) $html .= $script;
    
    $html = preg_replace('~<a ([^>]*)href=~i', '<a $1data-href=', $html);
    
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// Main visual proxy with Smart Link Finder and Live Preview
if (!empty($_GET['visual_proxy'])) {
    $url = trim($_GET['visual_proxy']);
    if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); echo 'Invalid URL'; exit; }
    
    $res = fetch_html($url, 15);
    if (!$res['ok']) { http_response_code(500); echo 'Failed: ' . $res['error']; exit; }
    
    $html = $res['html'];
    $baseUrl = $res['url'];
    
    $html = preg_replace('~<script[^>]*>.*?</script>~is', '', $html);
    $html = preg_replace('~<iframe[^>]*>.*?</iframe>~is', '', $html);
    $html = preg_replace('~<video[^>]*>.*?</video>~is', '', $html);
    $html = preg_replace('~<audio[^>]*>.*?</audio>~is', '', $html);
    
    $baseTag = '<base href="' . h($baseUrl) . '">';
    
    $script = <<<'SCRIPT'
<style>
*{cursor:crosshair!important}
.__h{outline:3px solid #3b82f6!important;outline-offset:2px}
.__s{outline:3px solid #22c55e!important;background:rgba(34,197,94,.08)!important}
.__bar{position:fixed;top:0;left:0;right:0;background:linear-gradient(180deg,#1e293b,#0f172a);color:#fff;padding:0;z-index:999999;font:13px Tahoma,sans-serif;box-shadow:0 4px 20px rgba(0,0,0,.6)}
.__row{display:flex;gap:8px;align-items:center;padding:8px 14px;flex-wrap:wrap}
.__row2{display:flex;gap:6px;align-items:center;padding:4px 14px 8px;flex-wrap:wrap;border-top:1px solid #334155}
.__row3{display:flex;gap:6px;align-items:center;padding:4px 14px 8px;flex-wrap:wrap;border-top:1px solid #1e293b;background:#0f172a}
.__bar select,.__bar button{padding:7px 12px;border-radius:6px;border:1px solid #475569;background:#334155;color:#fff;font:inherit;cursor:pointer;white-space:nowrap}
.__bar button:hover{background:#475569;border-color:#60a5fa}
.ok{background:#22c55e!important;color:#000!important;border-color:#22c55e!important}
.no{background:#ef4444!important;border-color:#ef4444!important}
.__nav{background:#1e3a5f!important;border-color:#3b82f6!important;color:#93c5fd!important;font-weight:700}
.__nav:hover{background:#2563eb!important}
.__sel{background:#0f172a;padding:5px 10px;border-radius:4px;font-family:monospace;font-size:11px;color:#67e8f9;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.__tag{background:#312e81;padding:3px 8px;border-radius:4px;font-family:monospace;font-size:11px;color:#c4b5fd}
.__cnt{background:#0f172a;padding:2px 8px;border-radius:4px;font-size:11px;color:#f59e0b}
.__preview-label{font-size:10px;color:#64748b;min-width:60px}
.__preview{background:#0f172a;padding:6px 10px;border-radius:4px;font-size:11px;color:#86efac;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border:1px solid #22c55e;font-weight:700;min-width:200px;direction:ltr;text-align:left}
.__preview.title-prev{color:#60a5fa;border-color:#3b82f6;font-weight:700;font-family:Tahoma,sans-serif;direction:rtl;text-align:right}
.__preview.price-prev{color:#fbbf24;border-color:#f59e0b}
.__preview.link-prev{color:#a78bfa;border-color:#8b5cf6;font-size:10px}
.__preview.img-prev{color:#f472b6;border-color:#ec4899;font-size:10px}
.__preview-warn{background:#78350f!important;color:#fbbf24!important;border-color:#f59e0b!important}
.__preview-err{background:#7f1d1d!important;color:#fca5a5!important;border-color:#ef4444!important}
body{padding-top:130px!important}
</style>
<div class="__bar">
<div class="__row">
  <select id="__m"><option value="container">📦 کانتینر</option><option value="title">📝 عنوان</option><option value="price">💰 قیمت</option><option value="link">🔗 لینک</option><option value="image">🖼️ تصویر</option></select>
  <span class="__sel" id="__sel">کلیک کنید...</span>
  <button onclick="__ok()">✓ بعدی</button>
  <button class="ok" onclick="__done()">✅ اتمام</button>
  <button class="no" onclick="parent.postMessage({type:'cancel'},'*')">✕</button>
</div>
<div class="__row2">
  <button class="__nav" onclick="__goParent()" title="والد">⬆</button>
  <button class="__nav" onclick="__goChild()" title="فرزند">⬇</button>
  <button class="__nav" onclick="__goPrev()" title="قبلی">⬅</button>
  <button class="__nav" onclick="__goNext()" title="بعدی">➡</button>
  <span class="__tag" id="__tag">-</span>
  <span class="__cnt" id="__cnt"></span>
</div>
<div class="__row3">
  <span class="__preview-label" id="__prevLabel">پیش‌نمایش:</span>
  <span id="__preview" class="__preview">در انتظار انتخاب...</span>
</div>
</div>
<script>
(function(){
var S={container:'',title:'',price:'',link:'',image:''},E={},cur=null,picked=null;

function gs(el){
  if(!el||el.tagName=='BODY'||el.tagName=='HTML')return'';
  var t=el.tagName.toLowerCase();
  var c=Array.from(el.classList).filter(function(x){return !x.startsWith('__')&&x.length<40});
  if(c.length)return t+'.'+c.slice(0,3).join('.');
  if(el.id&&el.id.length<30&&!/^__/.test(el.id))return t+'#'+el.id;
  return t;
}

function elInfo(el){
  if(!el)return '';
  var tag=el.tagName.toLowerCase();
  var cls=Array.from(el.classList).filter(function(x){return !x.startsWith('__')}).join('.');
  var id=el.id&&!/^__/.test(el.id)?'#'+el.id:'';
  return tag+(cls?'.'+cls:'')+(id||'');
}

function countSimilar(el){
  if(!el)return 0;
  var s=gs(el);
  if(!s)return 0;
  try{return document.querySelectorAll(s).length;}catch(e){return 0;}
}

// Smart Link Finder - searches for link in element, its attributes, children, and ancestors
function findSmartLink(el){
  if(!el)return {url:'',source:'هیچ لینکی یافت نشد'};
  
  // 1. If element is <a> itself
  if(el.tagName==='A'){
    var h=el.getAttribute('href')||el.getAttribute('data-href')||el.getAttribute('data-url')||'';
    if(h&&h!=='#'&&h.indexOf('javascript:')!==0)return {url:h,source:'لینک مستقیم href'};
  }
  
  // 2. Check data attributes on element itself
  var dataAttrs=['data-href','data-link','data-url','data-product-url','data-product-link'];
  for(var i=0;i<dataAttrs.length;i++){
    var v=el.getAttribute(dataAttrs[i]);
    if(v&&v!=='#'&&v.indexOf('javascript:')!==0)return {url:v,source:'ویژگی '+dataAttrs[i]};
  }
  
  // 3. Check <a> children
  var childA=el.querySelector('a[href]');
  if(childA){
    var h=childA.getAttribute('href');
    if(h&&h!=='#'&&h.indexOf('javascript:')!==0)return {url:h,source:'<a> فرزند'};
  }
  
  // 4. Check ancestor <a> tags (up to 7 levels)
  var parent=el.parentElement;
  for(var i=0;i<7&&parent&&parent.tagName!=='BODY'&&parent.tagName!=='HTML';i++){
    if(parent.tagName==='A'){
      var h=parent.getAttribute('href');
      if(h&&h!=='#'&&h.indexOf('javascript:')!==0)return {url:h,source:'<a> والد ('+(i+1)+' سطح)'};
    }
    // Check data attributes on parent
    for(var j=0;j<dataAttrs.length;j++){
      var v=parent.getAttribute(dataAttrs[j]);
      if(v&&v!=='#'&&v.indexOf('javascript:')!==0)return {url:v,source:'ویژگی '+(dataAttrs[j])+' والد ('+(i+1)+' سطح)'};
    }
    parent=parent.parentElement;
  }
  
  // 5. Check for onclick pattern (common in some sites)
  var oc=el.getAttribute('onclick')||'';
  if(oc){
    var m=oc.match(/window\.location\s*=\s*['"]([^'"]+)['"]/);
    if(m)return {url:m[1],source:'onclick'};
    m=oc.match(/location\.href\s*=\s*['"]([^'"]+)['"]/);
    if(m)return {url:m[1],source:'onclick'};
  }
  
  // 6. Try data-url on any ancestor
  parent=el.parentElement;
  for(var i=0;i<5&&parent&&parent.tagName!=='BODY';i++){
    var durl=parent.getAttribute('data-url')||parent.getAttribute('data-product-url')||'';
    if(durl&&durl!=='#')return {url:durl,source:'data-url والد'};
    parent=parent.parentElement;
  }
  
  return {url:'',source:'هیچ لینکی یافت نشد'};
}

function getImageUrl(el){
  if(!el)return {url:'',source:'تصویری یافت نشد'};
  var attrs=['data-src','data-lazy-src','data-original','data-bg','src'];
  
  if(el.tagName==='IMG'){
    for(var i=0;i<attrs.length;i++){
      var v=el.getAttribute(attrs[i]);
      if(v&&v.indexOf('placeholder')<0&&v.indexOf('1x1')<0&&v.indexOf('transparent')<0&&v.indexOf('loading')<0){
        return {url:v,source:'ویژگی '+attrs[i]};
      }
    }
  }
  
  var img=el.querySelector('img');
  if(img){
    for(var i=0;i<attrs.length;i++){
      var v=img.getAttribute(attrs[i]);
      if(v&&v.indexOf('placeholder')<0&&v.indexOf('1x1')<0&&v.indexOf('transparent')<0&&v.indexOf('loading')<0){
        return {url:v,source:'<img> فرزند ('+attrs[i]+')'};
      }
    }
  }
  
  // Check background-image
  var bg=window.getComputedStyle(el).backgroundImage||'';
  var m=bg.match(/url\(['"]?([^'")\s]+)['"]?\)/);
  if(m&&m[1]&&m[1].indexOf('placeholder')<0){
    return {url:m[1],source:'background-image'};
  }
  
  return {url:'',source:'تصویری یافت نشد'};
}

function extractPrice(t){
  t=(t||'').replace(/\s+/g,' ').trim();
  if(!t)return '';
  var m=t.match(/([\d۰-۹٠-٩](?:[\d۰-۹٠-٩]|[,،٬\s])*[\d۰-۹٠-٩])\s*(تومان|تومن|ریال)/);
  if(m)return m[1].trim();
  m=t.match(/([\d۰-۹٠-٩]{1,3}(?:[,،٬][\d۰-۹٠-٩]{3})+)/);
  if(m)return m[1].trim();
  // Last resort: find largest number
  var all=t.match(/[\d۰-۹٠-٩]+(?:[,،٬][\d۰-۹٠-٩]{3})+/g);
  if(all&&all.length){
    all.sort(function(a,b){return b.replace(/[^\d]/g,'').length-a.replace(/[^\d]/g,'').length;});
    return all[0];
  }
  return '';
}

function cleanTitle(t){
  return (t||'').replace(/\s+/g,' ').trim().substring(0,150);
}

function updateUI(){
  var m=document.getElementById('__m').value;
  var el=picked||cur;
  document.getElementById('__tag').textContent=el?elInfo(el):'—';
  var n=countSimilar(el);
  document.getElementById('__cnt').textContent=n?n+' مشابه':'';
}

function updatePreview(el,mode){
  var prevEl=document.getElementById('__preview');
  var labelEl=document.getElementById('__prevLabel');
  
  if(!el){
    prevEl.textContent='در انتظار انتخاب...';
    prevEl.className='__preview';
    labelEl.textContent='پیش‌نمایش:';
    return;
  }
  
  if(mode==='container'){
    var n=countSimilar(el);
    labelEl.textContent='کانتینر:';
    prevEl.textContent='تعداد المان‌های مشابه: '+n+(n>=2?' ✓':' (حداقل 2 نیاز است)');
    prevEl.className='__preview '+(n>=2?'':'__preview-warn');
    return;
  }
  
  if(mode==='title'){
    var t=cleanTitle(el.textContent);
    labelEl.textContent='عنوان استخراج‌شده:';
    prevEl.textContent=t||'(خالی)';
    prevEl.className='__preview title-prev '+(t?'':'__preview-warn');
    return;
  }
  
  if(mode==='price'){
    var t=extractPrice(el.textContent);
    labelEl.textContent='قیمت استخراج‌شده:';
    prevEl.textContent=t?t+' تومان':'(قیمت یافت نشد - سلکتور را تغییر دهید)';
    prevEl.className='__preview price-prev '+(t?'':'__preview-warn');
    return;
  }
  
  if(mode==='link'){
    var info=findSmartLink(el);
    labelEl.textContent='لینک ('+info.source+'):';
    prevEl.textContent=info.url||'(لینک یافت نشد - روی عکس/عنوان/کانتینر کلیک کنید)';
    prevEl.className='__preview link-prev '+(info.url?'':'__preview-err');
    return;
  }
  
  if(mode==='image'){
    var info=getImageUrl(el);
    labelEl.textContent='تصویر ('+info.source+'):';
    prevEl.textContent=info.url||'(تصویر یافت نشد)';
    prevEl.className='__preview img-prev '+(info.url?'':'__preview-warn');
    return;
  }
}

function selectEl(el){
  if(!el||el.tagName=='BODY'||el.tagName=='HTML'||el.closest('.__bar'))return;
  var m=document.getElementById('__m').value;
  var s=gs(el);
  if(E[m])E[m].classList.remove('__s');
  if(picked)picked.classList.remove('__h');
  el.classList.add('__s');
  el.classList.remove('__h');
  E[m]=el;S[m]=s;picked=el;
  document.getElementById('__sel').textContent=s||'(none)';
  updateUI();
  updatePreview(el,m);
}

document.addEventListener('mouseover',function(e){
  if(e.target.closest('.__bar'))return;
  if(cur&&cur!==picked)cur.classList.remove('__h');
  cur=e.target;
  if(cur!==picked)cur.classList.add('__h');
  updatePreview(cur,document.getElementById('__m').value);
},true);

document.addEventListener('mouseout',function(e){
  if(e.target&&e.target!==picked)e.target.classList.remove('__h');
  if(picked)updatePreview(picked,document.getElementById('__m').value);
},true);

document.addEventListener('click',function(e){
  if(e.target.closest('.__bar'))return;
  e.preventDefault();e.stopPropagation();
  selectEl(e.target);
},true);

document.getElementById('__m').addEventListener('change',function(){
  updatePreview(picked||cur,this.value);
});

window.__goParent=function(){
  var el=picked||cur;
  if(!el)return;
  var p=el.parentElement;
  if(p&&p.tagName!=='BODY'&&p.tagName!=='HTML'&&!p.closest('.__bar')){
    if(el===picked)el.classList.remove('__s');
    el.classList.remove('__h');
    selectEl(p);
    p.scrollIntoView({behavior:'smooth',block:'center'});
  }
};

window.__goChild=function(){
  var el=picked||cur;
  if(!el)return;
  var children=Array.from(el.children).filter(function(c){return !c.classList.contains('__bar')&&c.tagName!=='SCRIPT'&&c.tagName!=='STYLE'});
  if(children.length>0){
    if(el===picked)el.classList.remove('__s');
    el.classList.remove('__h');
    selectEl(children[0]);
    children[0].scrollIntoView({behavior:'smooth',block:'center'});
  }
};

window.__goPrev=function(){
  var el=picked||cur;
  if(!el)return;
  var prev=el.previousElementSibling;
  while(prev&&(prev.classList.contains('__bar')||prev.tagName==='SCRIPT'||prev.tagName==='STYLE')){
    prev=prev.previousElementSibling;
  }
  if(prev){
    if(el===picked)el.classList.remove('__s');
    el.classList.remove('__h');
    selectEl(prev);
    prev.scrollIntoView({behavior:'smooth',block:'center'});
  }
};

window.__goNext=function(){
  var el=picked||cur;
  if(!el)return;
  var next=el.nextElementSibling;
  while(next&&(next.classList.contains('__bar')||next.tagName==='SCRIPT'||next.tagName==='STYLE')){
    next=next.nextElementSibling;
  }
  if(next){
    if(el===picked)el.classList.remove('__s');
    el.classList.remove('__h');
    selectEl(next);
    next.scrollIntoView({behavior:'smooth',block:'center'});
  }
};

window.__ok=function(){
  var m=document.getElementById('__m').value;
  if(!S[m]){alert('ابتدا المانی انتخاب کنید');return;}
  var modes=['container','title','price','link','image'];
  var i=modes.indexOf(m);
  if(i<modes.length-1){
    document.getElementById('__m').value=modes[i+1];
    picked=null;
    updatePreview(cur,modes[i+1]);
  }
  updateUI();
};

window.__done=function(){
  if(!S.container){alert('کانتینر را انتخاب کنید');return;}
  parent.postMessage({type:'selectors',data:S},'*');
};

})();
</script>
SCRIPT;

    if (stripos($html, '<head') !== false) {
        $html = preg_replace('~(<head[^>]*>)~i', '$1' . $baseTag, $html, 1);
    } else {
        $html = $baseTag . $html;
    }
    
    $html = preg_replace('~</body>~i', $script . '</body>', $html);
    if (stripos($html, '</body>') === false) $html .= $script;
    
    $html = preg_replace('~<a ([^>]*)href=~i', '<a $1data-href=', $html);
    
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

function url_is_image(string $url): bool {
    $u = trim($url);
    if ($u === '' || preg_match('~^(data:|blob:|javascript:|#)~i', $u)) return false;
    if (preg_match('~(placeholder|spacer|transparent|loading|no-?image|blank\.|1x1)~i', $u)) return false;
    return true;
}

function load_dom(string $html): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    @$dom->loadHTML('<?xml encoding="UTF-8"><meta charset="UTF-8">' . $html, LIBXML_NOERROR);
    libxml_clear_errors();
    return [$dom, new DOMXPath($dom)];
}

function extractPrice(string $text): string {
    $text = normalize_text($text);
    if (!$text) return '';
    $sep = '[,،٬\s]';
    
    if (preg_match('~([\d۰-۹٠-٩](?:[\d۰-۹٠-٩]|'.$sep.')*[\d۰-۹٠-٩])\s*(تومان|تومن|ریال|ر\.ی)~u', $text, $m)) {
        return trim($m[1] . ' ' . $m[2]);
    }
    if (preg_match('~([\d۰-۹٠-٩]{1,3}(?:'.$sep.'[\d۰-۹٠-٩]{3})+)~u', $text, $m)) {
        return trim($m[1]) . ' تومان';
    }
    
    if (preg_match_all('~([\d۰-۹٠-٩]{1,3}(?:'.$sep.'[\d۰-۹٠-٩]{3})+)~u', $text, $matches)) {
        $candidates = [];
        foreach ($matches[1] as $match) {
            $clean = preg_replace('~[^\d۰-۹٠-٩]~u', '', $match);
            if (mb_strlen($clean) >= 3) {
                $candidates[] = ['raw' => $match, 'clean' => $clean, 'len' => mb_strlen($clean)];
            }
        }
        if ($candidates) {
            // Sort by length (longest = most likely the price)
            usort($candidates, fn($a, $b) => $b['len'] <=> $a['len']);
            return trim($candidates[0]['raw']) . ' تومان';
        }
    }
    return '';
}

function productKey(array $p): string {
    if (!empty($p['link'])) {
        $url = preg_replace('~[?#].*$~', '', $p['link']);
        $url = rtrim($url, '/');
        return md5('url:' . $url);
    }
    $title = mb_strtolower(trim($p['title'] ?? ''));
    $title = preg_replace('~\s+~u', ' ', $title);
    return md5('title:' . $title . '|' . ($p['price'] ?? ''));
}

function cssToXpath(string $css): string {
    $css = trim($css);
    if (!$css) return '';
    
    if (preg_match('~^#([\w-]+)$~', $css, $m)) {
        return "//*[@id='" . $m[1] . "']";
    }
    
    if (preg_match('~^([\w]+)?((?:\.[\w-]+)+)$~', $css, $m)) {
        $tag = $m[1] ?: '*';
        $classes = array_filter(explode('.', $m[2]));
        $cond = implode(' and ', array_map(fn($c) => "contains(@class,'$c')", $classes));
        return "//" . $tag . "[$cond]";
    }
    
    if (preg_match('~^[\w]+$~', $css)) {
        return "//" . $css;
    }
    
    if (preg_match('~^([\w]+)\[(\w+)=["\']?([^"\']+)["\']?\]$~', $css, $m)) {
        return "//" . $m[1] . "[@" . $m[2] . "='" . $m[3] . "']";
    }
    
    if (preg_match('~^([\w]+)\[(\w+)\*=["\']?([^"\']+)["\']?\]$~', $css, $m)) {
        return "//" . $m[1] . "[contains(@" . $m[2] . ",'" . $m[3] . "')]";
    }
    
    $parts = preg_split('~\s+~', $css);
    if (count($parts) > 1) {
        $xpath = '';
        foreach ($parts as $i => $part) {
            $part = trim($part);
            if (preg_match('~^([\w]+)?(\.[\w-]+)?$~', $part, $m)) {
                $tag = $m[1] ?: '*';
                $class = isset($m[2]) ? ltrim($m[2], '.') : '';
                $xpath .= ($i === 0 ? '//' : '//');
                $xpath .= $tag;
                if ($class) $xpath .= "[contains(@class,'$class')]";
            }
        }
        return $xpath;
    }
    
    return "//*";
}

// Smart link extraction from a DOM node - uses multiple fallbacks
function extractSmartLink($node, $xp, string $baseUrl): string {
    if (!$node instanceof DOMElement) return '';
    
    // 1. If node itself is <a>
    if ($node->tagName === 'a') {
        $h = $node->getAttribute('href') ?: $node->getAttribute('data-href') ?: $node->getAttribute('data-url') ?: '';
        if ($h && $h !== '#' && !preg_match('~^(javascript:|data:)~i', $h)) {
            return make_absolute_url($h, $baseUrl);
        }
    }
    
    // 2. Check data attributes on element itself
    foreach (['data-href', 'data-link', 'data-url', 'data-product-url', 'data-product-link'] as $attr) {
        $v = $node->getAttribute($attr);
        if ($v && $v !== '#' && !preg_match('~^(javascript:|data:)~i', $v)) {
            return make_absolute_url($v, $baseUrl);
        }
    }
    
    // 3. Check <a> children
    $aNodes = @$xp->query('.//a[@href]', $node);
    if ($aNodes && $aNodes->length) {
        $h = $aNodes->item(0)->getAttribute('href');
        if ($h && $h !== '#' && !preg_match('~^(javascript:|data:)~i', $h)) {
            return make_absolute_url($h, $baseUrl);
        }
    }
    
    // 4. Check ancestor <a> tags (up to 7 levels)
    $parent = $node->parentNode;
    for ($i = 0; $i < 7 && $parent instanceof DOMElement; $i++) {
        if ($parent->tagName === 'a') {
            $h = $parent->getAttribute('href');
            if ($h && $h !== '#' && !preg_match('~^(javascript:|data:)~i', $h)) {
                return make_absolute_url($h, $baseUrl);
            }
        }
        foreach (['data-href', 'data-link', 'data-url', 'data-product-url'] as $attr) {
            $v = $parent->getAttribute($attr);
            if ($v && $v !== '#' && !preg_match('~^(javascript:|data:)~i', $v)) {
                return make_absolute_url($v, $baseUrl);
            }
        }
        $parent = $parent->parentNode;
    }
    
    // 5. Check onclick patterns
    $oc = $node->getAttribute('onclick') ?: '';
    if ($oc) {
        if (preg_match('~window\.location\s*=\s*[\'"]([^\'"]+)[\'"]~', $oc, $m)) {
            return make_absolute_url($m[1], $baseUrl);
        }
        if (preg_match('~location\.href\s*=\s*[\'"]([^\'"]+)[\'"]~', $oc, $m)) {
            return make_absolute_url($m[1], $baseUrl);
        }
    }
    
    return '';
}

function parse_with_selectors(string $html, string $baseUrl, array $sel): array {
    [$dom, $xp] = load_dom($html);
    $products = [];
    
    $containerXpath = cssToXpath($sel['container'] ?? '');
    if (!$containerXpath) return [];
    
    $containers = @$xp->query($containerXpath);
    if (!$containers || $containers->length === 0) return [];
    
    foreach ($containers as $container) {
        $p = ['title' => '', 'price' => '', 'link' => '', 'image' => '', 'sku' => ''];
        
        if (!empty($sel['title'])) {
            $xpath = cssToXpath($sel['title']);
            $nodes = @$xp->query('.' . $xpath, $container);
            if (!$nodes || !$nodes->length) $nodes = @$xp->query($xpath, $container);
            if ($nodes && $nodes->length) {
                $p['title'] = normalize_text($nodes->item(0)->textContent);
            }
        }
        
        if (!$p['title']) {
            foreach (['.//h2', './/h3', './/h4', './/*[contains(@class,"title")]', './/a[@title]'] as $q) {
                $nodes = @$xp->query($q, $container);
                if ($nodes && $nodes->length) {
                    $text = normalize_text($nodes->item(0)->textContent);
                    if ($text && mb_strlen($text) > 2 && mb_strlen($text) < 200) {
                        $p['title'] = $text;
                        break;
                    }
                }
            }
        }
        
        if (!empty($sel['price'])) {
            $xpath = cssToXpath($sel['price']);
            $nodes = @$xp->query('.' . $xpath, $container);
            if (!$nodes || !$nodes->length) $nodes = @$xp->query($xpath, $container);
            if ($nodes && $nodes->length) {
                $p['price'] = extractPrice($nodes->item(0)->textContent);
            }
        }
        
        if (!$p['price']) {
            foreach (['.//ins//*[contains(@class,"amount")]', './/*[contains(@class,"price")]', './/*[contains(@class,"amount")]'] as $q) {
                $nodes = @$xp->query($q, $container);
                if ($nodes && $nodes->length) {
                    $price = extractPrice($nodes->item(0)->textContent);
                    if ($price) { $p['price'] = $price; break; }
                }
            }
        }
        
        // Use Smart Link Finder if selector provided
        if (!empty($sel['link'])) {
            $xpath = cssToXpath($sel['link']);
            $nodes = @$xp->query('.' . $xpath, $container);
            if (!$nodes || !$nodes->length) $nodes = @$xp->query($xpath, $container);
            if ($nodes && $nodes->length && $nodes->item(0) instanceof DOMElement) {
                $p['link'] = extractSmartLink($nodes->item(0), $xp, $baseUrl);
            }
        }
        
        if (!$p['link']) {
            $p['link'] = extractSmartLink($container, $xp, $baseUrl);
        }
        
        if (!empty($sel['image'])) {
            $xpath = cssToXpath($sel['image']);
            $nodes = @$xp->query('.' . $xpath, $container);
            if (!$nodes || !$nodes->length) $nodes = @$xp->query($xpath, $container);
            if ($nodes && $nodes->length && $nodes->item(0) instanceof DOMElement) {
                foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                    $v = $nodes->item(0)->getAttribute($attr);
                    if ($v && url_is_image($v)) {
                        $p['image'] = make_absolute_url($v, $baseUrl);
                        break;
                    }
                }
            }
        }
        
        if (!$p['image']) {
            $nodes = @$xp->query('.//img', $container);
            if ($nodes && $nodes->length) {
                foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                    $v = $nodes->item(0)->getAttribute($attr);
                    if ($v && url_is_image($v)) {
                        $p['image'] = make_absolute_url($v, $baseUrl);
                        break;
                    }
                }
            }
        }
        
        if (!$p['title'] && !$p['link']) continue;
        $p['title'] = mb_substr($p['title'], 0, 200);
        
        $key = productKey($p);
        if (!isset($products[$key])) {
            $products[$key] = $p;
        }
    }
    
    return $products;
}

function parse_products(string $html, string $baseUrl): array {
    [$dom, $xp] = load_dom($html);
    $products = [];
    
    $containerQueries = [
        "//li[contains(@class,'product')]",
        "//div[contains(@class,'product-card')]",
        "//div[contains(@class,'product-item')]",
        "//div[contains(@class,'product') and not(contains(@class,'products'))]",
        "//article[contains(@class,'product')]",
        "//div[contains(@class,'card') and .//a and .//img]",
        "//div[contains(@class,'col') and .//a and .//img and .//*[contains(@class,'price') or contains(text(),'تومان')]]",
        "//li[contains(@class,'col') and .//a and .//img]",
        "//div[contains(@class,'item') and .//a and .//img]",
    ];
    
    $nodes = [];
    $seen = [];
    
    foreach ($containerQueries as $q) {
        $result = @$xp->query($q);
        if ($result && $result->length >= 2) {
            foreach ($result as $n) {
                $h = spl_object_hash($n);
                if (!isset($seen[$h])) {
                    $seen[$h] = 1;
                    $nodes[] = $n;
                }
            }
            break;
        }
    }
    
    if (empty($nodes)) {
        $result = @$xp->query("//a[.//img][contains(@href,'product') or contains(@href,'/p/') or contains(@href,'/کالا/')]");
        if ($result) {
            foreach ($result as $n) {
                $h = spl_object_hash($n);
                if (!isset($seen[$h])) {
                    $seen[$h] = 1;
                    $nodes[] = $n;
                }
            }
        }
    }
    
    foreach ($nodes as $node) {
        $p = ['title' => '', 'price' => '', 'link' => '', 'image' => '', 'sku' => ''];
        
        // Use Smart Link Finder
        $p['link'] = extractSmartLink($node, $xp, $baseUrl);
        
        if (!$p['link']) {
            $linkNodes = @$xp->query('.//a[@href]', $node);
            if ($linkNodes && $linkNodes->length) {
                $p['link'] = make_absolute_url($linkNodes->item(0)->getAttribute('href'), $baseUrl);
            }
        }
        
        $titleQueries = [
            './/h2[contains(@class,"title")]', './/h3[contains(@class,"title")]',
            './/a[contains(@class,"title")]', './/*[contains(@class,"product-title")]',
            './/h2', './/h3', './/h4',
            './/a[@title]/@title',
        ];
        
        foreach ($titleQueries as $q) {
            $ns = @$xp->query($q, $node);
            if ($ns && $ns->length) {
                $text = normalize_text($ns->item(0)->textContent);
                if ($text && mb_strlen($text) > 2 && mb_strlen($text) < 200) {
                    $p['title'] = $text;
                    break;
                }
            }
        }
        
        $priceQueries = [
            './/ins//*[contains(@class,"amount")]',
            './/*[contains(@class,"price")]//*[contains(@class,"amount")]',
            './/*[contains(@class,"price")]',
            './/*[contains(@class,"amount")]',
        ];
        
        foreach ($priceQueries as $q) {
            $ns = @$xp->query($q, $node);
            if ($ns && $ns->length) {
                $price = extractPrice($ns->item(0)->textContent);
                if ($price) { $p['price'] = $price; break; }
            }
        }
        
        if (!$p['price']) {
            $fullText = normalize_text($node->textContent);
            $p['price'] = extractPrice($fullText);
        }
        
        $imgNodes = @$xp->query('.//img', $node);
        if ($imgNodes && $imgNodes->length) {
            foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $attr) {
                $v = $imgNodes->item(0)->getAttribute($attr);
                if ($v && url_is_image($v)) {
                    $p['image'] = make_absolute_url($v, $baseUrl);
                    break;
                }
            }
        }
        
        if (!$p['title'] && !$p['link']) continue;
        
        $key = productKey($p);
        if (!isset($products[$key])) {
            $products[$key] = $p;
        }
    }
    
    if (empty($products)) {
        $scripts = @$xp->query("//script[@type='application/ld+json']");
        if ($scripts) {
            foreach ($scripts as $script) {
                $data = @json_decode($script->textContent, true);
                if (!$data) continue;
                $items = [];
                $walk = function($d) use (&$walk, &$items) {
                    if (!is_array($d)) return;
                    if (stripos($d['@type'] ?? '', 'Product') !== false) $items[] = $d;
                    foreach ($d as $v) if (is_array($v)) $walk($v);
                };
                $walk($data);
                foreach ($items as $item) {
                    $img = is_array($item['image'] ?? null) ? ($item['image'][0] ?? '') : ($item['image'] ?? '');
                    $p = [
                        'title' => $item['name'] ?? '',
                        'price' => ($item['offers']['price'] ?? '') . ' تومان',
                        'link' => make_absolute_url($item['url'] ?? '', $baseUrl),
                        'image' => make_absolute_url($img, $baseUrl),
                        'sku' => $item['sku'] ?? ''
                    ];
                    $key = productKey($p);
                    if (!isset($products[$key])) $products[$key] = $p;
                }
            }
        }
    }
    
    return $products;
}

function build_page_url_custom(string $currentUrl, string $baseUrl, int $page, string $type, string $val): string {
    if ($type === 'query_custom') {
        $param = $val ?: 'paged';
        $parts = parse_url($currentUrl);
        if (!$parts) return $currentUrl;
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '/');
        parse_str($parts['query'] ?? '', $q);
        $q[$param] = $page;
        return $base . '?' . http_build_query($q);
    }
    
    if ($type === 'path_pattern') {
        $pattern = $val ?: '/page/{page}/';
        $replacement = str_replace('{page}', $page, $pattern);
        $parts = parse_url($baseUrl);
        $root = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $basePath = rtrim($parts['path'] ?? '/', '/');
        $basePath = preg_replace('~/page/\d+/?$~i', '', $basePath);
        return $root . $basePath . $replacement;
    }
    
    if ($type === 'full_pattern') {
        return str_replace('{page}', $page, $val);
    }
    
    $parts = parse_url($currentUrl);
    if (!$parts) return $currentUrl;
    $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '/');
    parse_str($parts['query'] ?? '', $q);
    $q['page'] = $page;
    return $base . '?' . http_build_query($q);
}

function send_sse($type, $data) {
    echo "event: {$type}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @ob_flush(); @flush();
}

if (isset($_GET['detail_stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (@ob_get_level()) @ob_end_clean();
    
    $keys = isset($_GET['keys']) ? array_filter(explode(',', $_GET['keys'])) : [];
    $detailSelectors = isset($_GET['detailSelectors']) ? json_decode($_GET['detailSelectors'], true) : [];
    
    if (empty($keys) || empty($detailSelectors)) {
        send_sse('error', ['message' => 'Invalid parameters']);
        send_sse('done', []);
        exit;
    }
    
    $urlMap = isset($_POST['urlMap']) ? json_decode($_POST['urlMap'], true) : [];
    if (empty($urlMap)) {
        send_sse('error', ['message' => 'No URL map']);
        send_sse('done', []);
        exit;
    }
    
    $processed = 0;
    $total = count($keys);
    
    foreach ($keys as $key) {
        if (!isset($urlMap[$key]) || empty($urlMap[$key])) continue;
        
        $processed++;
        send_sse('detail_progress', ['current' => $processed, 'total' => $total, 'key' => $key]);
        
        $productUrl = $urlMap[$key];
        $res = fetch_html($productUrl, 15);
        
        $extracted = ['key' => $key];
        
        if ($res['ok']) {
            [$dom, $xp] = load_dom($res['html']);
            
            foreach ($detailSelectors as $field => $config) {
                if (empty($config['enabled']) || empty($config['selector'])) continue;
                
                $xpath = cssToXpath($config['selector']);
                if (!$xpath) continue;
                
                $nodes = @$xp->query($xpath);
                if ($nodes && $nodes->length) {
                    $node = $nodes->item(0);
                    if (in_array($field, ['longDesc', 'shortDesc'])) {
                        $value = trim(@$dom->saveHTML($node));
                        $value = preg_replace('~\s+~', ' ', $value);
                        $extracted[$field] = $value;
                    } else {
                        $value = normalize_text($node->textContent);
                        $extracted[$field] = $value;
                    }
                }
            }
        }
        
        send_sse('detail_extracted', $extracted);
        usleep(150000);
    }
    
    send_sse('detail_complete', ['total' => $processed]);
    send_sse('done', []);
    exit;
}

if (isset($_GET['stream'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (@ob_get_level()) @ob_end_clean();
    
    $url = trim($_GET['url'] ?? DEFAULT_URL);
    $maxPages = max(1, min(100, (int)($_GET['pages'] ?? 20)));
    $selectors = isset($_GET['selectors']) ? json_decode($_GET['selectors'], true) : null;
    $pagType = $_GET['pagType'] ?? 'query_page';
    $pagVal = trim($_GET['pagVal'] ?? '');
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        send_sse('error', ['message' => 'Invalid URL']);
        send_sse('done', []);
        exit;
    }
    
    $allProducts = [];
    $seenKeys = [];
    $nextUrl = null;
    
    for ($page = 1; $page <= $maxPages; $page++) {
        if ($page === 1) {
            $pageUrl = $url;
        } elseif ($pagType === 'next_selector' && $nextUrl) {
            $pageUrl = $nextUrl;
        } elseif ($pagType === 'next_selector' && !$nextUrl) {
            send_sse('page', ['page' => $page, 'url' => '', 'ok' => false]);
            break;
        } else {
            $pageUrl = build_page_url_custom($url, $url, $page, $pagType, $pagVal);
        }
        
        $res = fetch_html($pageUrl, 20);
        
        send_sse('page', ['page' => $page, 'url' => $res['url'], 'ok' => $res['ok']]);
        
        if (!$res['ok']) {
            if ($page === 1) send_sse('error', ['message' => 'Failed: ' . $res['error']]);
            break;
        }
        
        $pageProducts = ($selectors && !empty($selectors['container']))
            ? parse_with_selectors($res['html'], $res['url'], $selectors)
            : parse_products($res['html'], $res['url']);
        
        $newCount = 0;
        foreach ($pageProducts as $key => $p) {
            if (isset($seenKeys[$key])) continue;
            $seenKeys[$key] = 1;
            $allProducts[$key] = $p;
            $newCount++;
            send_sse('product', array_merge($p, ['key' => $key]));
        }
        
        send_sse('page_done', ['page' => $page, 'new' => $newCount, 'total' => count($allProducts)]);
        
        if ($pagType === 'next_selector' && !empty($pagVal)) {
            [$dom, $xp] = load_dom($res['html']);
            $xpath = cssToXpath($pagVal);
            $nodes = @$xp->query($xpath);
            if ($nodes && $nodes->length && $nodes->item(0) instanceof DOMElement) {
                $href = $nodes->item(0)->getAttribute('href');
                if ($href && $href !== '#' && !preg_match('~^(javascript:|data:)~i', $href)) {
                    $nextUrl = make_absolute_url($href, $res['url']);
                } else {
                    $nextUrl = null;
                }
            } else {
                $nextUrl = null;
            }
        }
        
        if ($page > 1 && $newCount === 0) break;
        usleep(300000);
    }
    
    if (FETCH_MISSING_IMAGES) {
        $need = [];
        foreach ($allProducts as $key => $p) {
            if ((empty($p['image']) || empty($p['price'])) && !empty($p['link'])) {
                $need[$key] = $p;
            }
        }
        
        $total = count($need);
        $i = 0;
        
        foreach ($need as $key => $p) {
            $i++;
            send_sse('missing_start', ['current' => $i, 'total' => $total, 'key' => $key]);
            
            $detail = fetch_html($p['link'], 12);
            $updates = ['image' => '', 'price' => '', 'key' => $key];
            
            if ($detail['ok']) {
                [$dom, $xp] = load_dom($detail['html']);
                
                if (empty($allProducts[$key]['image'])) {
                    $imgQueries = [
                        "//meta[@property='og:image']/@content",
                        "//img[contains(@class,'wp-post-image')]",
                        "//img[contains(@class,'product')]",
                        "//*[contains(@class,'gallery')]//img",
                    ];
                    foreach ($imgQueries as $q) {
                        $ns = @$xp->query($q);
                        if ($ns && $ns->length) {
                            $v = $ns->item(0)->nodeValue ?: ($ns->item(0)->getAttribute('src') ?: '');
                            if ($v && url_is_image($v)) {
                                $allProducts[$key]['image'] = make_absolute_url($v, $detail['url']);
                                $updates['image'] = $allProducts[$key]['image'];
                                break;
                            }
                        }
                    }
                }
                
                if (empty($allProducts[$key]['price'])) {
                    $ns = @$xp->query("//*[contains(@class,'price')]//*[contains(@class,'amount')]");
                    if ($ns && $ns->length) {
                        $price = extractPrice($ns->item(0)->textContent);
                        if ($price) {
                            $allProducts[$key]['price'] = $price;
                            $updates['price'] = $price;
                        }
                    }
                }
            }
            
            send_sse('missing_done', $updates);
            usleep(200000);
        }
    }
    
    send_sse('complete', ['total' => count($allProducts), 'products' => array_values($allProducts)]);
    send_sse('done', []);
    exit;
}

if (($_POST['action'] ?? '') === 'csv') {
    $products = json_decode($_POST['products'] ?? '[]', true) ?: [];
    $useCustom = !empty($_POST['useCustom']);
    $customName = $_POST['customName'] ?? 'Custom';
    $detailFields = json_decode($_POST['detailFields'] ?? '[]', true) ?: [];
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="products-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    
    $headers = ['#', 'Title', 'Original Price', 'Final Price', 'URL', 'Image'];
    foreach ($detailFields as $f) {
        $headers[] = $f['label'];
    }
    if ($useCustom) $headers[] = $customName;
    fputcsv($out, $headers);
    
    foreach ($products as $i => $p) {
        $row = [$i + 1, $p['title'] ?? '', $p['origPrice'] ?? '', $p['price'] ?? '', $p['link'] ?? '', $p['image'] ?? ''];
        foreach ($detailFields as $f) {
            $val = $p[$f['key']] ?? '';
            if (in_array($f['key'], ['shortDesc', 'longDesc'])) {
                $val = strip_tags($val);
            }
            $row[] = $val;
        }
        if ($useCustom) $row[] = $p['custom'] ?? '';
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

if (($_POST['action'] ?? '') === 'excel') {
    $products = json_decode($_POST['products'] ?? '[]', true) ?: [];
    $useCustom = !empty($_POST['useCustom']);
    $customName = $_POST['customName'] ?? 'Custom';
    $detailFields = json_decode($_POST['detailFields'] ?? '[]', true) ?: [];
    
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="products-' . date('Ymd-His') . '.xls"');
    echo '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Styles><Style ss:ID="h"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2563EB" ss:Pattern="Solid"/></Style></Styles>';
    echo '<Worksheet ss:Name="Products"><Table>';
    echo '<Row>';
    $headers = ['#', 'Title', 'Original Price', 'Final Price', 'URL', 'Image'];
    foreach ($detailFields as $f) $headers[] = $f['label'];
    if ($useCustom) $headers[] = $customName;
    foreach ($headers as $h) echo '<Cell ss:StyleID="h"><Data ss:Type="String">' . h($h) . '</Data></Cell>';
    echo '</Row>';
    foreach ($products as $i => $p) {
        echo '<Row>';
        echo '<Cell><Data ss:Type="Number">' . ($i + 1) . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . h($p['title'] ?? '') . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . h($p['origPrice'] ?? '') . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . h($p['price'] ?? '') . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . h($p['link'] ?? '') . '</Data></Cell>';
        echo '<Cell><Data ss:Type="String">' . h($p['image'] ?? '') . '</Data></Cell>';
        foreach ($detailFields as $f) {
            $val = $p[$f['key']] ?? '';
            if (in_array($f['key'], ['shortDesc', 'longDesc'])) {
                $val = strip_tags($val);
                $val = mb_substr($val, 0, 32000);
            }
            echo '<Cell><Data ss:Type="String">' . h($val) . '</Data></Cell>';
        }
        if ($useCustom) echo '<Cell><Data ss:Type="String">' . h($p['custom'] ?? '') . '</Data></Cell>';
        echo '</Row>';
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

$initialProfiles = [];
foreach (loadProfiles() as $key => $p) {
    $initialProfiles[] = [
        'key' => $key,
        'name' => $p['name'] ?? $key,
        'url' => $p['url'] ?? '',
        'updatedAt' => $p['updatedAt'] ?? 0
    ];
}
usort($initialProfiles, fn($a, $b) => ($b['updatedAt'] ?? 0) <=> ($a['updatedAt'] ?? 0));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>اسکرپر ووکامرس</title>
<style>
*{box-sizing:border-box;margin:0;-webkit-tap-highlight-color:transparent}
html,body{overflow-x:hidden}
body{font-family:Tahoma,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;padding:12px;padding-bottom:90px;direction:rtl}
.container{max-width:1400px;margin:0 auto}
h1{font-size:18px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:14px;margin-bottom:14px}
.row{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}
input,select{background:#0f172a;border:1px solid #475569;color:#fff;padding:10px 12px;border-radius:8px;font-size:13px;font-family:inherit;width:100%}
input[type="checkbox"]{width:auto}
select{min-width:90px;width:auto}
.btn{padding:11px 14px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:12px;font-family:inherit;transition:.15s;white-space:nowrap}
.btn:hover{opacity:.9}.btn:active{transform:scale(.97)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-blue{background:linear-gradient(135deg,#3b82f6,#06b6d4);color:#000}
.btn-red{background:#ef4444;color:#fff}
.btn-green{background:#22c55e;color:#000}
.btn-purple{background:#a855f7;color:#fff}
.btn-orange{background:#f97316;color:#000}
.btn-gray{background:#475569;color:#fff}
.btn-yellow{background:#eab308;color:#000}
.btn-cyan{background:#06b6d4;color:#000}
.btn-pink{background:#ec4899;color:#fff}
.btn-indigo{background:#6366f1;color:#fff}
.hidden{display:none!important}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}
.stat{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px;text-align:center}
.stat b{font-size:20px;display:block}
.stat span{color:#64748b;font-size:10px}
.progress{height:5px;background:#334155;border-radius:5px;margin:10px 0;overflow:hidden}
.progress-bar{height:100%;background:linear-gradient(90deg,#3b82f6,#a855f7);width:0;transition:.3s}
.progress-bar.pink{background:linear-gradient(90deg,#ec4899,#f59e0b)}
.status{color:#94a3b8;font-size:12px;margin-bottom:8px}
.logs{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px;max-height:140px;overflow-y:auto;font-family:monospace;font-size:11px;margin-bottom:10px;direction:ltr;text-align:left}
.log{padding:2px 0;border-bottom:1px solid #1e293b}.log-ok{color:#4ade80}.log-err{color:#f87171}.log-info{color:#60a5fa}.log-detail{color:#f0abfc}

.main-tabs{position:fixed;bottom:0;left:0;right:0;background:#0f172a;border-top:1px solid #334155;display:flex;z-index:1000;box-shadow:0 -4px 20px rgba(0,0,0,.5);padding-bottom:env(safe-area-inset-bottom)}
.main-tab{flex:1;padding:10px 4px 8px;border:none;background:transparent;color:#64748b;font-size:11px;font-family:inherit;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;position:relative;transition:color .2s}
.main-tab .t-icon{font-size:20px}
.main-tab .t-label{font-weight:600}
.main-tab.active{color:#3b82f6;background:#1e293b}
.main-tab .badge{position:absolute;top:4px;right:calc(50% - 20px);background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:10px;min-width:16px;text-align:center}
.main-tab .badge.ok{background:#22c55e;color:#000}

.tab-pane{display:none;animation:fadeIn .3s ease}
.tab-pane.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}

.sub-tabs{display:flex;gap:3px;background:#0f172a;padding:3px;border-radius:10px;margin-bottom:12px}
.sub-tab{flex:1;padding:9px;border:none;border-radius:8px;font-weight:600;cursor:pointer;background:transparent;color:#94a3b8;font-size:12px;font-family:inherit;text-align:center}
.sub-tab.active{background:#3b82f6;color:#000}

.mode-tabs{display:flex;gap:3px;background:#0f172a;padding:3px;border-radius:10px;margin-bottom:12px}
.mode-tab{flex:1;padding:9px;border:none;border-radius:8px;font-weight:600;cursor:pointer;background:transparent;color:#94a3b8;font-size:12px;font-family:inherit;text-align:center}
.mode-tab.active{background:#3b82f6;color:#000}

.visual-container{display:grid;grid-template-columns:1fr;gap:14px}
.iframe-wrap{background:#0f172a;border:1px solid #334155;border-radius:10px;overflow:hidden;height:420px;position:relative}
.iframe-wrap iframe{width:100%;height:100%;border:none;background:#fff}
.iframe-wrap .if-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:13px}
.selector-panel{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:12px}
.selector-panel h3{margin:0 0 10px;font-size:14px;color:#67e8f9}
.sel-item{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px;margin-bottom:8px;transition:border-color .2s}
.sel-item.has{border-color:#22c55e;background:#14532d20}.sel-item.has label{color:#4ade80}
.sel-item label{display:flex;align-items:center;gap:6px;font-size:11px;margin-bottom:4px;color:#94a3b8}
.sel-item input{width:100%;font-family:monospace;font-size:11px;padding:6px 8px}
.sel-item .sel-preview{font-size:10px;color:#86efac;padding:4px 8px;background:#0f172a;border:1px solid #22c55e;border-radius:4px;margin-top:6px;font-family:Tahoma,sans-serif;word-break:break-word;max-height:60px;overflow:hidden;line-height:1.4}
.sel-item .sel-preview.price-prev{color:#fbbf24;border-color:#f59e0b;font-family:monospace;direction:ltr;text-align:left}
.sel-item .sel-preview.link-prev{color:#a78bfa;border-color:#8b5cf6;font-family:monospace;font-size:9px;direction:ltr;text-align:left}
.sel-item .sel-preview.img-prev{color:#f472b6;border-color:#ec4899;font-family:monospace;font-size:9px;direction:ltr;text-align:left}
.sel-item .sel-preview.empty{color:#fca5a5;border-color:#ef4444;background:#7f1d1d30}
.sel-item .sel-actions-row{display:flex;gap:4px;margin-top:6px}
.sel-item .sel-actions-row .btn{padding:4px 8px;font-size:10px;flex:1}
.sel-actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.suggest-list{max-height:150px;overflow-y:auto;background:#1e293b;border:1px solid #334155;border-radius:6px;margin-top:6px}
.suggest-item{padding:8px;font-size:11px;cursor:pointer;font-family:monospace;border-bottom:1px solid #334155}
.suggest-item:hover{background:#334155}

.detail-field{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px;margin-bottom:8px;transition:border-color .2s}
.detail-field.enabled{border-color:#a855f7;background:#2d1b4e}
.detail-field-row{display:flex;gap:8px;align-items:center;margin-bottom:6px}
.detail-field-row .fname{flex:0 0 110px;font-size:12px;font-weight:700;color:#c4b5fd}
.detail-field-row .ftoggle{flex:0 0 auto}
.detail-field-row .fselector{flex:1;font-family:monospace;font-size:11px;padding:6px 8px}
.detail-field-meta{font-size:10px;color:#64748b;display:flex;gap:10px;align-items:center}
.detail-field-meta .preview{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.product{background:#1e293b;border:1px solid #334155;border-radius:12px;overflow:hidden}
.thumb{height:140px;background:linear-gradient(135deg,#1e3a5f,#312e81);display:flex;align-items:center;justify-content:center}
.thumb img{width:100%;height:100%;object-fit:cover}
.noimg{color:#64748b;font-weight:600;font-size:11px}
.pbody{padding:10px}
.ptitle{font-weight:700;font-size:12px;margin-bottom:6px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:36px}
.pdetail-short{font-size:10px;color:#cbd5e1;line-height:1.4;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-height:30px}
.price{display:inline-block;padding:4px 8px;background:#166534;color:#86efac;border-radius:6px;font-weight:700;font-size:12px;margin-bottom:4px}
.price-orig{display:block;font-size:10px;color:#64748b;text-decoration:line-through;margin-bottom:4px;direction:ltr;text-align:right;font-family:monospace}
.no-price{background:#7f1d1d;color:#fca5a5}
.plink{display:block;text-align:center;padding:6px;background:#1e3a5f;border-radius:6px;color:#60a5fa;text-decoration:none;font-weight:600;font-size:11px}

.table-wrap{overflow-x:auto;border:1px solid #334155;border-radius:10px}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:750px}
th,td{padding:8px 10px;text-align:right;border-bottom:1px solid #334155}
th{background:#1e3a5f;color:#93c5fd;font-size:10px}
.td-orig{color:#94a3b8;text-decoration:line-through;font-size:11px;font-family:monospace;direction:ltr;text-align:right}
.td-detail{font-size:10px;color:#cbd5e1;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.text-view{position:relative}
.text-content{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:12px;font-family:monospace;font-size:11px;white-space:pre-wrap;max-height:400px;overflow:auto;direction:ltr;text-align:left}
.copy-btn{position:absolute;top:6px;left:6px}
.copied{background:#22c55e!important}
.alert{padding:10px;border-radius:8px;margin-bottom:10px;font-size:12px}
.alert-info{background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd}
.alert-purple{background:#3b0764;border:1px solid #a855f7;color:#e9d5ff}
.alert-success{background:#14532d;border:1px solid #22c55e;color:#86efac}
.settings-card h3{margin-bottom:12px;font-size:14px;color:#67e8f9}
.profile-row{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.profile-row select{flex:2;min-width:150px;font-weight:600}
.profile-row input{flex:1;min-width:120px}
.profile-row .btn{flex:0 0 auto}
.profile-indicator{display:inline-block;padding:3px 8px;border-radius:4px;font-size:10px}
.saved{background:#14532d;color:#86efac;border:1px solid #22c55e}
.unsaved{background:#78350f;color:#fbbf24;border:1px solid #f59e0b}
.toast{position:fixed;top:80px;left:50%;transform:translateX(-50%);background:#14532d;color:#86efac;padding:12px 20px;border-radius:8px;border:1px solid #22c55e;box-shadow:0 8px 20px rgba(0,0,0,.5);z-index:99999;font-weight:700;font-size:12px;opacity:0;transition:opacity .3s,top .3s;pointer-events:none;max-width:90%;text-align:center}
.toast.show{opacity:1;top:60px}
.toast.error{background:#7f1d1d;color:#fca5a5;border-color:#ef4444}
.row label{color:#94a3b8;font-size:12px;min-width:80px;display:flex;align-items:center}
input[type="checkbox"]{margin-left:5px}
.section-title{font-size:13px;color:#67e8f9;margin-bottom:8px;font-weight:700;display:flex;align-items:center;gap:6px}
.section-title.purple{color:#c4b5fd}
.empty-state{text-align:center;padding:40px 20px;color:#64748b}
.empty-state .icon{font-size:48px;margin-bottom:10px;opacity:.5}
.empty-state p{font-size:13px}
.switch{position:relative;display:inline-block;width:36px;height:20px}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:#475569;transition:.2s;border-radius:20px}
.slider:before{position:absolute;content:"";height:14px;width:14px;right:3px;bottom:3px;background:#fff;transition:.2s;border-radius:50%}
input:checked+.slider{background:#a855f7}
input:checked+.slider:before{transform:translateX(-16px)}

@media(min-width:900px){
    body{padding:16px;padding-bottom:16px}
    h1{font-size:22px}
    .main-tabs{position:static;border-top:none;box-shadow:none;background:#1e293b;border:1px solid #334155;border-radius:12px;margin-bottom:14px;padding:3px}
    .main-tab{padding:12px;border-radius:8px;flex-direction:row;gap:8px;font-size:13px}
    .main-tab .t-icon{font-size:16px}
    .main-tab.active{background:#3b82f6}
    .main-tab .badge{position:static;margin-right:4px;min-width:auto}
    .visual-container{grid-template-columns:1fr 320px}
    .iframe-wrap{height:500px}
    .grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
    .btn{padding:10px 16px}
    .profile-row{flex-wrap:nowrap}
}
</style>
</head>
<body>
<div class="container">
<h1>🛒 اسکرپر ووکامرس <span id="profileStatus" class="profile-indicator unsaved">جدید</span></h1>

<div class="main-tabs" id="mainTabs">
    <button class="main-tab active" data-tab="start" onclick="switchMainTab('start')">
        <span class="t-icon">🎯</span>
        <span class="t-label">شروع</span>
    </button>
    <button class="main-tab" data-tab="settings" onclick="switchMainTab('settings')">
        <span class="t-icon">⚙️</span>
        <span class="t-label">تنظیمات</span>
    </button>
    <button class="main-tab" data-tab="selectors" onclick="switchMainTab('selectors')">
        <span class="t-icon">🎨</span>
        <span class="t-label">سلکتورها</span>
    </button>
    <button class="main-tab" data-tab="results" onclick="switchMainTab('results')">
        <span class="t-icon">📊</span>
        <span class="t-label">نتایج</span>
        <span class="badge hidden" id="resultsBadge">0</span>
    </button>
</div>

<!-- TAB: START -->
<div class="tab-pane active" id="pane-start">
    <div class="card">
        <div class="section-title">📚 پروفایل‌های ذخیره شده</div>
        <div class="profile-row">
            <select id="profileSelect" onchange="selectProfile(this.value)">
                <option value="">-- انتخاب سایت ذخیره شده --</option>
            </select>
            <input type="text" id="profileName" placeholder="🏷️ نام دلخواه" oninput="scheduleSave()">
            <button class="btn btn-cyan" onclick="saveProfile()">💾</button>
            <button class="btn btn-red" onclick="deleteProfile()">🗑️</button>
        </div>
    </div>

    <div class="card">
        <div class="section-title">🔗 آدرس و محدوده</div>
        <div class="row">
            <input type="url" id="url" value="<?=h(DEFAULT_URL)?>" placeholder="آدرس فروشگاه..." oninput="onUrlChange()">
            <select id="pages" onchange="scheduleSave()" style="max-width:120px"><?php for($i=1;$i<=100;$i++): ?><option value="<?=$i?>" <?=$i==10?'selected':''?>><?=$i?> صفحه</option><?php endfor;?></select>
        </div>

        <div class="row" style="align-items:center;margin-top:4px">
            <label>صفحه‌بندی:</label>
            <select id="pagType" onchange="updatePagUI();scheduleSave()" style="flex:1">
                <option value="query_page" selected>?page=x (پیش‌فرض)</option>
                <option value="query_custom">پارامتر دلخواه</option>
                <option value="path_pattern">الگوی مسیر</option>
                <option value="full_pattern">الگوی کامل URL</option>
                <option value="next_selector">سلکتور دکمه بعد</option>
            </select>
        </div>
        <div class="row" id="pagValRow" style="display:none">
            <input type="text" id="pagVal" placeholder="مقدار..." oninput="scheduleSave()">
        </div>
    </div>

    <div class="card">
        <div class="mode-tabs">
            <button class="mode-tab active" onclick="setMode('auto')">🤖 خودکار</button>
            <button class="mode-tab" onclick="setMode('visual')">👆 دستی</button>
        </div>

        <div id="autoCtrl">
            <div class="row">
                <button class="btn btn-blue" id="startBtn" onclick="start()" style="flex:1">▶ شروع اسکرپ</button>
                <button class="btn btn-red hidden" id="stopBtn" onclick="stop()" style="flex:1">⏹ توقف</button>
                <button class="btn btn-gray" onclick="reset()">↺</button>
            </div>
        </div>

        <div id="visualCtrl" class="hidden">
            <div class="alert alert-info">به تب «سلکتورها» بروید و المان‌ها را انتخاب کنید.</div>
            <div class="row">
                <button class="btn btn-blue" id="startVisBtn" onclick="startVisual()" style="flex:1" disabled>▶ شروع (نیاز به کانتینر)</button>
                <button class="btn btn-gray" onclick="switchMainTab('selectors')">🎨 انتخاب</button>
            </div>
        </div>

        <div class="progress hidden" id="progress"><div class="progress-bar" id="progressBar"></div></div>
        <div class="status" id="status">آماده</div>
        <div class="stats">
            <div class="stat"><b id="numP">۰</b><span>محصول</span></div>
            <div class="stat"><b id="numPg">۰</b><span>صفحه</span></div>
            <div class="stat"><b id="numD">۰</b><span>جزئیات</span></div>
        </div>
    </div>
</div>

<!-- TAB: SETTINGS -->
<div class="tab-pane" id="pane-settings">
    <div class="card settings-card">
        <div class="section-title">📝 عنوان محصول</div>
        <div class="row" style="align-items:center">
            <label>پسوند عنوان:</label>
            <input type="text" id="titleSuffix" placeholder="مثال: - فروشگاه من" oninput="refreshViews();scheduleSave()">
        </div>
    </div>

    <div class="card settings-card">
        <div class="section-title">💰 مدیریت قیمت</div>
        <div class="row" style="align-items:center">
            <label>روش:</label>
            <select id="priceMode" onchange="refreshViews();scheduleSave()" style="flex:1">
                <option value="none">بدون تغییر</option>
                <option value="percent">درصد (مثلا 20+)</option>
                <option value="multiplier">ضریب (مثلا 1.5)</option>
            </select>
            <input type="number" id="priceVal" value="0" step="0.01" placeholder="مقدار" style="max-width:120px" oninput="refreshViews();scheduleSave()">
        </div>
        <div class="row" style="align-items:center;margin-top:8px">
            <label>گرد کردن:</label>
            <select id="roundPrice" onchange="refreshViews();scheduleSave()" style="flex:1">
                <option value="0" selected>بدون گرد کردن</option>
                <option value="1000">هزار (1,000)</option>
                <option value="10000">ده هزار (10,000)</option>
                <option value="100000">صد هزار (100,000)</option>
            </select>
        </div>
        <div style="font-size:11px;color:#64748b;margin-top:6px;line-height:1.8">
            💡 <b>درصد:</b> ۲۰ = افزایش ۲۰٪ | -۱۰ = کاهش ۱۰٪<br>
            💡 <b>ضریب:</b> ۱.۵ = ۵۰٪ افزایش | ۰.۹ = ۱۰٪ کاهش<br>
            💡 <b>گرد کردن:</b> بعد از اعمال درصد/ضریب به نزدیک‌ترین ضریب گرد می‌شود
        </div>
    </div>

    <div class="card settings-card">
        <div class="section-title">📋 ستون سفارشی</div>
        <div class="row" style="align-items:center">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                <input type="checkbox" id="useCustomCol" onchange="refreshViews();scheduleSave()"> فعال
            </label>
        </div>
        <div class="row">
            <input type="text" id="customColName" placeholder="نام ستون" value="وضعیت" oninput="refreshViews();scheduleSave()">
            <input type="text" id="customColVal" placeholder="مقدار ثابت" value="موجود" oninput="refreshViews();scheduleSave()">
        </div>
    </div>

    <div class="card settings-card">
        <div class="section-title">🧹 پاکسازی</div>
        <div class="row" style="align-items:center;margin-bottom:8px">
            <label>حداقل قیمت:</label>
            <input type="number" id="minPrice" value="10000" step="1000" placeholder="10000" style="flex:1" oninput="scheduleSave()">
        </div>
        <div style="font-size:11px;color:#64748b;margin-bottom:10px;line-height:1.6">
            💡 محصولاتی که قیمت نهایی آن‌ها کمتر از این مقدار باشد، با دکمه زیر حذف می‌شوند.
        </div>
        <div class="row">
            <button class="btn btn-orange" onclick="removeBelowMinPrice()" style="flex:1">🚫 حذف زیر حداقل</button>
            <button class="btn btn-red" onclick="removeNoPrice()" style="flex:1">🗑️ حذف بدون قیمت</button>
        </div>
    </div>
</div>

<!-- TAB: SELECTORS -->
<div class="tab-pane" id="pane-selectors">
    <div class="card">
        <div class="section-title">🎨 سلکتورهای لیست محصولات</div>
        <div class="alert alert-info">
            💡 <b>نکته مهم:</b> در پنجره پایین، پس از کلیک روی هر المان، یک <b>پیش‌نمایش زنده</b> از متنی که در خروجی نمایش داده می‌شود را می‌بینید. اگر متن اشتباه بود، از دکمه‌های ⬆⬇⬅➡ برای تغییر المان استفاده کنید.
        </div>
        <div class="row">
            <button class="btn btn-orange" onclick="loadVisual()" style="flex:1">🔄 بارگذاری صفحه</button>
            <button class="btn btn-yellow" onclick="suggestSelectors()" style="flex:1">💡 پیشنهاد</button>
            <button class="btn btn-gray" onclick="clearSel()">🗑️</button>
        </div>
    </div>

    <div class="visual-container">
        <div class="iframe-wrap">
            <iframe id="vFrame"></iframe>
        </div>
        <div class="selector-panel">
            <h3>📋 سلکتورها + پیش‌نمایش</h3>
            <div class="sel-item" id="s-container">
                <label><span>📦</span> کانتینر (ضروری)</label>
                <input id="selContainer" readonly placeholder="کلیک در صفحه..." oninput="scheduleSave()">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('container')">👁 تست</button>
                </div>
                <div class="sel-preview empty" id="prev-container">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-title">
                <label><span>📝</span> عنوان</label>
                <input id="selTitle" readonly oninput="scheduleSave()">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('title')">👁 تست</button>
                </div>
                <div class="sel-preview empty" id="prev-title">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-price">
                <label><span>💰</span> قیمت</label>
                <input id="selPrice" readonly oninput="scheduleSave()">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('price')">👁 تست</button>
                </div>
                <div class="sel-preview price-prev empty" id="prev-price">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-link">
                <label><span>🔗</span> لینک <span style="color:#fbbf24">(Smart Finder)</span></label>
                <input id="selLink" readonly oninput="scheduleSave()">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('link')">👁 تست</button>
                </div>
                <div class="sel-preview link-prev empty" id="prev-link">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-image">
                <label><span>🖼️</span> تصویر</label>
                <input id="selImage" readonly oninput="scheduleSave()">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('image')">👁 تست</button>
                </div>
                <div class="sel-preview img-prev empty" id="prev-image">در انتظار...</div>
            </div>
            <div id="suggestions" class="hidden">
                <h3 style="margin-top:12px">💡 پیشنهادها</h3>
                <div id="suggestList"></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:14px;border-color:#a855f7">
        <div class="section-title purple">📄 سلکتورهای صفحه جزئیات محصول</div>
        <div class="alert alert-purple">
            💡 ابتدا حداقل یک محصول را در لیست استخراج کنید، سپس با دکمه زیر صفحه نمونه آن را باز کنید و روی هر فیلد کلیک کنید.
        </div>
        <div class="row">
            <button class="btn btn-pink" onclick="openDetailProxy()" style="flex:1" id="detailProxyBtn">🎯 باز کردن نمونه</button>
            <button class="btn btn-purple" onclick="suggestDetailSelectors()" style="flex:1">💡 پیشنهاد</button>
            <button class="btn btn-gray" onclick="clearDetailSel()">🗑️</button>
        </div>
        <div id="detailFieldsList" style="margin-top:12px"></div>
    </div>

    <div class="iframe-wrap hidden" id="detailFrameWrap" style="margin-top:14px">
        <iframe id="detailFrame"></iframe>
    </div>
</div>

<!-- TAB: RESULTS -->
<div class="tab-pane" id="pane-results">
    <div class="card" id="resultsCard">
        <div class="sub-tabs">
            <button class="sub-tab active" data-v="grid" onclick="switchView('grid')">📊 کارت</button>
            <button class="sub-tab" data-v="table" onclick="switchView('table')">📋 جدول</button>
            <button class="sub-tab" data-v="text" onclick="switchView('text')">📝 متن</button>
        </div>
        <div class="row">
            <button class="btn btn-pink" onclick="startDetailExtraction()" id="btnExtractDetail" style="flex:2">🔍 استخراج تفصیلی</button>
            <button class="btn btn-red hidden" onclick="stopDetailExtraction()" id="btnStopDetail" style="flex:1">⏹ توقف</button>
        </div>
        <div class="progress hidden" id="detailProgress"><div class="progress-bar pink" id="detailProgressBar"></div></div>
        <div class="status" id="detailStatus" style="color:#c4b5fd"></div>
        <div class="row">
            <button class="btn btn-gray" onclick="copyCSV()" style="flex:1">📋 کپی</button>
            <button class="btn btn-green" onclick="dlCSV()" style="flex:1">📄 CSV</button>
            <button class="btn btn-purple" onclick="dlExcel()" style="flex:1">📊 Excel</button>
        </div>
        <div id="vGrid" class="grid">
            <div class="empty-state" id="emptyState">
                <div class="icon">📭</div>
                <p>هنوز محصولی اسکرپ نشده است.</p>
            </div>
        </div>
        <div id="vTable" class="table-wrap hidden"><table><thead><tr></tr></thead><tbody id="tBody"></tbody></table></div>
        <div id="vText" class="text-view hidden"><button class="btn btn-blue copy-btn" onclick="copyTxt()">📋</button><div class="text-content" id="txtContent"></div></div>
    </div>

    <div class="card">
        <div class="section-title">📜 لاگ عملیات</div>
        <div class="logs" id="logs" style="max-height:200px">
            <div class="log log-info">آماده برای شروع...</div>
        </div>
    </div>
</div>

</div>

<div id="toast" class="toast"></div>

<script>
const $=id=>document.getElementById(id);
let es=null,detailEs=null,products=new Map(),order=[],pages=0,details=0,running=false,detailRunning=false,mode='auto';
let sel={container:'',title:'',price:'',link:'',image:''};
const DETAIL_FIELDS = [
    {key:'shortDesc', label:'توضیحات کوتاه', icon:'📝'},
    {key:'longDesc',  label:'توضیحات بلند',   icon:'📄'},
    {key:'sku',       label:'SKU',             icon:'🏷️'},
    {key:'category',  label:'دسته‌بندی',      icon:'📂'},
    {key:'tags',      label:'برچسب‌ها',        icon:'🔖'},
    {key:'weight',    label:'وزن',             icon:'⚖️'},
    {key:'stock',     label:'موجودی',          icon:'📦'},
    {key:'brand',     label:'برند',            icon:'🏭'},
];
let detailSel = {};
DETAIL_FIELDS.forEach(f => detailSel[f.key] = {enabled:false, selector:''});

let profiles = <?=json_encode($initialProfiles, JSON_UNESCAPED_UNICODE)?>;
let currentProfileKey = null;
let isDirty = false;
let saveTimer = null;
let urlChangeTimer = null;
let testTimers = {};

const toFa=n=>String(n).replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
const toEn=s=>(s||'').replace(/[۰-۹]/g,d=>'0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(d)]).replace(/[٠-٩]/g,d=>'0123456789'['٠١٢٣٤٥٦٧٨٩'.indexOf(d)]);

function showToast(msg, isError=false) {
    const t = $('toast');
    t.textContent = msg;
    t.className = 'toast show' + (isError ? ' error' : '');
    setTimeout(() => t.className = 'toast' + (isError ? ' error' : ''), 2500);
}

function markDirty() {
    isDirty = true;
    const s = $('profileStatus');
    s.textContent = 'تغییرات';
    s.className = 'profile-indicator unsaved';
}

function markClean(key) {
    isDirty = false;
    currentProfileKey = key;
    const s = $('profileStatus');
    s.textContent = 'ذخیره';
    s.className = 'profile-indicator saved';
}

function scheduleSave() {
    if (!$('url').value.trim()) return;
    markDirty();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveProfileSilent, 2000);
}

function switchMainTab(name) {
    document.querySelectorAll('.main-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + name));
    window.scrollTo({top:0,behavior:'smooth'});
    try { history.replaceState(null, '', '#' + name); } catch(e) {}
}

(function(){
    const hash = window.location.hash.replace('#','');
    if (['start','settings','selectors','results'].includes(hash)) {
        setTimeout(() => switchMainTab(hash), 50);
    }
})();

function extractNumber(str) {
    if (!str) return 0;
    let en = toEn(str);
    en = en.replace(/[^0-9]/g, '');
    return parseInt(en, 10) || 0;
}

function getOriginalPrice(str) {
    const base = extractNumber(str);
    if (base === 0) return '0';
    return base.toLocaleString('en-US');
}

function getFinalPriceNum(str) {
    let base = extractNumber(str);
    if (base === 0) return 0;
    
    let mode = $('priceMode') ? $('priceMode').value : 'none';
    let val = parseFloat($('priceVal') ? $('priceVal').value : 0) || 0;
    
    let finalNum = base;
    if (mode === 'percent') {
        finalNum = base * (1 + (val / 100));
    } else if (mode === 'multiplier') {
        finalNum = base * val;
    }
    
    finalNum = Math.round(finalNum);
    
    const roundMode = $('roundPrice') ? $('roundPrice').value : '0';
    if (roundMode !== '0') {
        const factor = parseInt(roundMode, 10);
        if (factor > 0) {
            finalNum = Math.round(finalNum / factor) * factor;
        }
    }
    
    return finalNum;
}

function getFinalPrice(str) {
    const num = getFinalPriceNum(str);
    return num === 0 ? '0' : num.toLocaleString('en-US');
}

function getFinalTitle(title) {
    let suffix = $('titleSuffix') ? $('titleSuffix').value.trim() : '';
    return (title || '') + (suffix ? ' ' + suffix : '');
}

function isCustomColEnabled() {
    return $('useCustomCol') && $('useCustomCol').checked;
}

function getCustomColName() {
    return $('customColName') ? ($('customColName').value.trim() || 'Custom') : 'Custom';
}

function getCustomColVal() {
    return $('customColVal') ? $('customColVal').value : '';
}

function getMinPrice() {
    return parseInt($('minPrice') ? $('minPrice').value : 10000) || 10000;
}

function stripHtml(html) {
    if (!html) return '';
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').trim();
}

function shortText(s, len=80) {
    if (!s) return '';
    return s.length > len ? s.substring(0, len) + '...' : s;
}

function getEnabledDetailFields() {
    return DETAIL_FIELDS.filter(f => detailSel[f.key] && detailSel[f.key].enabled && detailSel[f.key].selector);
}

function renderDetailFieldsList() {
    const container = $('detailFieldsList');
    if (!container) return;
    let html = '';
    DETAIL_FIELDS.forEach(f => {
        const cfg = detailSel[f.key] || {enabled:false, selector:''};
        html += `
            <div class="detail-field ${cfg.enabled ? 'enabled' : ''}" data-f="${f.key}">
                <div class="detail-field-row">
                    <span class="fname">${f.icon} ${f.label}</span>
                    <label class="switch ftoggle">
                        <input type="checkbox" ${cfg.enabled ? 'checked' : ''} onchange="toggleDetailField('${f.key}', this.checked)">
                        <span class="slider"></span>
                    </label>
                    <input class="fselector" type="text" value="${esc(cfg.selector||'')}" placeholder="سلکتور CSS" oninput="updateDetailSelector('${f.key}', this.value)">
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
}

function toggleDetailField(key, enabled) {
    if (!detailSel[key]) detailSel[key] = {enabled:false, selector:''};
    detailSel[key].enabled = enabled;
    renderDetailFieldsList();
    refreshViews();
    scheduleSave();
}

function updateDetailSelector(key, val) {
    if (!detailSel[key]) detailSel[key] = {enabled:false, selector:''};
    detailSel[key].selector = val.trim();
    if (val.trim()) {
        detailSel[key].enabled = true;
    }
    renderDetailFieldsList();
    refreshViews();
    scheduleSave();
}

function clearDetailSel() {
    if (!confirm('پاک کردن همه سلکتورهای صفحه جزئیات؟')) return;
    DETAIL_FIELDS.forEach(f => detailSel[f.key] = {enabled:false, selector:''});
    renderDetailFieldsList();
    $('detailFrameWrap').classList.add('hidden');
    $('detailFrame').src = 'about:blank';
    refreshViews();
    scheduleSave();
}

function openDetailProxy() {
    let sampleUrl = null;
    for (const [k, p] of products) {
        if (p.link) { sampleUrl = p.link; break; }
    }
    if (!sampleUrl) {
        showToast('ابتدا محصولات را استخراج کنید', true);
        return;
    }
    $('detailFrameWrap').classList.remove('hidden');
    $('detailFrame').src = '?detail_proxy=' + encodeURIComponent(sampleUrl);
    $('detailStatus').textContent = '✓ روی فیلدهای دلخواه کلیک کنید';
    switchMainTab('selectors');
    setTimeout(() => $('detailFrameWrap').scrollIntoView({behavior:'smooth',block:'center'}), 200);
}

function suggestDetailSelectors() {
    let sampleUrl = null;
    for (const [k, p] of products) {
        if (p.link) { sampleUrl = p.link; break; }
    }
    if (!sampleUrl) {
        showToast('ابتدا محصولات را استخراج کنید', true);
        return;
    }
    $('detailStatus').textContent = 'در حال تحلیل صفحه جزئیات...';
    fetch('?suggest_detail_selectors=' + encodeURIComponent(sampleUrl))
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                showToast('خطا: ' + d.error, true);
                return;
            }
            let applied = 0;
            for (const field of Object.keys(d.suggestions)) {
                if (d.suggestions[field].length > 0 && (!detailSel[field].selector || !detailSel[field].enabled)) {
                    detailSel[field].selector = d.suggestions[field][0].selector;
                    detailSel[field].enabled = true;
                    applied++;
                }
            }
            renderDetailFieldsList();
            refreshViews();
            scheduleSave();
            $('detailStatus').textContent = applied > 0 ? `✓ ${applied} فیلد تنظیم شد` : 'هیچ فیلد جدیدی یافت نشد';
            showToast(applied > 0 ? `✓ ${applied} فیلد شناسایی شد` : 'پیشنهادی یافت نشد');
        })
        .catch(() => showToast('خطا در ارتباط', true));
}

// Test selector - fetch sample value
function testSel(type) {
    const url = $('url').value.trim();
    const selector = sel[type];
    const prevEl = $('prev-' + type);
    
    if (!url) { showToast('URL وارد کنید', true); return; }
    if (!selector) { showToast('ابتدا سلکتور را انتخاب کنید', true); return; }
    
    prevEl.textContent = '⏳ در حال تست...';
    prevEl.className = prevEl.className.replace(/empty|__preview-\w+/g, '').trim();
    
    // Debounce
    if (testTimers[type]) clearTimeout(testTimers[type]);
    testTimers[type] = setTimeout(() => {
        const params = new URLSearchParams({
            test_selector: url,
            type: type,
            selector: selector
        });
        fetch('?' + params.toString())
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    prevEl.textContent = '❌ ' + (d.error || 'خطا');
                    prevEl.classList.add('empty');
                    return;
                }
                
                let display = d.value || '(خالی)';
                let extra = d.count > 1 ? ` (${toFa(d.count)} مورد یافت شد)` : '';
                
                if (type === 'container') {
                    display = `✓ ${toFa(d.count)} کانتینر مشابه یافت شد`;
                    prevEl.className = 'sel-preview' + (d.count >= 2 ? '' : ' empty');
                } else if (type === 'title') {
                    prevEl.className = 'sel-preview' + (d.value ? '' : ' empty');
                } else if (type === 'price') {
                    display = d.value ? d.value + ' تومان' : '(قیمت یافت نشد)';
                    prevEl.className = 'sel-preview price-prev' + (d.value ? '' : ' empty');
                } else if (type === 'link') {
                    display = d.value || '(لینک یافت نشد)';
                    prevEl.className = 'sel-preview link-prev' + (d.value ? '' : ' empty');
                } else if (type === 'image') {
                    display = d.value || '(تصویر یافت نشد)';
                    prevEl.className = 'sel-preview img-prev' + (d.value ? '' : ' empty');
                }
                
                prevEl.textContent = display + extra;
            })
            .catch(e => {
                prevEl.textContent = '❌ خطا در ارتباط';
                prevEl.classList.add('empty');
            });
    }, 300);
}

function updatePagUI(){
    const t = $('pagType').value;
    const v = $('pagVal');
    const row = $('pagValRow');
    if(t === 'query_page') {
        row.style.display = 'none';
        v.value = '';
    } else {
        row.style.display = 'flex';
        if(t === 'query_custom') {
            v.placeholder = 'نام پارامتر (مثال: paged)';
            if(!v.value) v.value = 'paged';
        } else if(t === 'path_pattern') {
            v.placeholder = 'الگوی مسیر (/page/{page}/)';
            if(!v.value) v.value = '/page/{page}/';
        } else if(t === 'full_pattern') {
            v.placeholder = 'الگوی کامل URL';
        } else if(t === 'next_selector') {
            v.placeholder = 'سلکتور CSS (a.next)';
            if(!v.value) v.value = 'a.next';
        }
    }
}
updatePagUI();

function onUrlChange() {
    clearTimeout(urlChangeTimer);
    urlChangeTimer = setTimeout(() => {
        const url = $('url').value.trim();
        if (!url) return;
        const match = profiles.find(p => p.url === url);
        if (match) {
            loadProfileFromServer(url);
        } else {
            currentProfileKey = null;
            const s = $('profileStatus');
            s.textContent = 'جدید';
            s.className = 'profile-indicator unsaved';
            $('profileName').value = '';
            $('profileSelect').value = '';
        }
    }, 500);
}

function renderProfileDropdown() {
    const sel = $('profileSelect');
    sel.innerHTML = '<option value="">-- انتخاب سایت (' + profiles.length + ') --</option>';
    profiles.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.url;
        opt.textContent = p.name;
        sel.appendChild(opt);
    });
}
renderProfileDropdown();

function selectProfile(url) {
    if (!url) return;
    $('url').value = url;
    loadProfileFromServer(url);
}

function loadProfileFromServer(url) {
    fetch('?load_profile=' + encodeURIComponent(url))
        .then(r => r.json())
        .then(d => {
            if (d.ok && d.profile) {
                applyProfile(d.profile);
            } else {
                showToast('پروفایل یافت نشد', true);
            }
        })
        .catch(() => showToast('خطا در بارگذاری', true));
}

function applyProfile(p) {
    $('url').value = p.url || '';
    $('profileName').value = p.name || '';
    $('pages').value = p.pages || 10;
    $('pagType').value = p.pagType || 'query_page';
    $('pagVal').value = p.pagVal || '';
    $('titleSuffix').value = p.titleSuffix || '';
    $('priceMode').value = p.priceMode || 'none';
    $('priceVal').value = p.priceVal || 0;
    $('roundPrice').value = p.roundPrice || '0';
    $('minPrice').value = p.minPrice || 10000;
    $('useCustomCol').checked = !!p.useCustomCol;
    $('customColName').value = p.customColName || 'وضعیت';
    $('customColVal').value = p.customColVal || 'موجود';
    
    if (p.selectors) {
        sel = {...sel, ...p.selectors};
        ['container','title','price','link','image'].forEach(k=>{
            $('sel'+k.charAt(0).toUpperCase()+k.slice(1)).value = sel[k] || '';
            $('s-'+k).classList.toggle('has', !!sel[k]);
            // Auto-test each selector
            if (sel[k]) {
                setTimeout(() => testSel(k), 300);
            }
        });
    }
    
    if (p.detailSelectors) {
        DETAIL_FIELDS.forEach(f => {
            if (p.detailSelectors[f.key]) {
                detailSel[f.key] = {
                    enabled: !!p.detailSelectors[f.key].enabled,
                    selector: p.detailSelectors[f.key].selector || ''
                };
            } else {
                detailSel[f.key] = {enabled:false, selector:''};
            }
        });
        renderDetailFieldsList();
    }
    
    updatePagUI();
    refreshViews();
    
    try {
        const u = new URL(p.url);
        let path = u.pathname.replace(/\/$/, '');
        path = path.replace(/\/page\/\d+\/?$/i, '');
        path = path.replace(/\.(html|htm|php)$/i, '');
        let key = u.host.toLowerCase() + (path ? '_' + path.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') : '');
        currentProfileKey = key;
    } catch(e) {
        currentProfileKey = null;
    }
    
    markClean(currentProfileKey);
    $('profileSelect').value = p.url;
    showToast('✓ پروفایل "' + (p.name || '') + '" بارگذاری شد');
}

function collectProfileData() {
    return {
        url: $('url').value.trim(),
        name: $('profileName').value.trim(),
        pages: parseInt($('pages').value) || 10,
        pagType: $('pagType').value,
        pagVal: $('pagVal').value,
        selectors: {...sel},
        detailSelectors: JSON.parse(JSON.stringify(detailSel)),
        titleSuffix: $('titleSuffix').value,
        priceMode: $('priceMode').value,
        priceVal: parseFloat($('priceVal').value) || 0,
        roundPrice: $('roundPrice').value,
        minPrice: parseInt($('minPrice').value) || 10000,
        useCustomCol: $('useCustomCol').checked,
        customColName: $('customColName').value,
        customColVal: $('customColVal').value
    };
}

function saveProfileSilent() {
    const data = collectProfileData();
    if (!data.url) return;
    
    const fd = new FormData();
    fd.append('action', 'save_profile');
    for (const k in data) {
        if (k === 'selectors' || k === 'detailSelectors') {
            fd.append(k, JSON.stringify(data[k]));
        } else {
            fd.append(k, data[k]);
        }
    }
    
    fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                markClean(d.key);
                const existingIdx = profiles.findIndex(p => p.url === data.url);
                const entry = {key: d.key, name: data.name || parseUrlHost(data.url), url: data.url, updatedAt: Math.floor(Date.now()/1000)};
                if (existingIdx >= 0) {
                    profiles[existingIdx] = entry;
                } else {
                    profiles.unshift(entry);
                }
                renderProfileDropdown();
                $('profileSelect').value = data.url;
            }
        })
        .catch(() => {});
}

function parseUrlHost(url) {
    try { return new URL(url).host; } catch(e) { return url; }
}

function saveProfile() {
    const data = collectProfileData();
    if (!data.url) {
        showToast('URL وارد کنید', true);
        return;
    }
    
    if (!data.name) {
        data.name = parseUrlHost(data.url);
        $('profileName').value = data.name;
    }
    
    const fd = new FormData();
    fd.append('action', 'save_profile');
    for (const k in data) {
        if (k === 'selectors' || k === 'detailSelectors') {
            fd.append(k, JSON.stringify(data[k]));
        } else {
            fd.append(k, data[k]);
        }
    }
    
    fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                markClean(d.key);
                const existingIdx = profiles.findIndex(p => p.url === data.url);
                const entry = {key: d.key, name: data.name, url: data.url, updatedAt: Math.floor(Date.now()/1000)};
                if (existingIdx >= 0) {
                    profiles[existingIdx] = entry;
                } else {
                    profiles.unshift(entry);
                }
                renderProfileDropdown();
                $('profileSelect').value = data.url;
                showToast('✓ ذخیره شد');
            } else {
                showToast('خطا: ' + (d.error || ''), true);
            }
        })
        .catch(() => showToast('خطا در ارتباط', true));
}

function deleteProfile() {
    const url = $('url').value.trim();
    if (!url) {
        showToast('URL وارد کنید', true);
        return;
    }
    if (!confirm('حذف این پروفایل؟')) return;
    
    const fd = new FormData();
    fd.append('action', 'delete_profile');
    fd.append('url', url);
    
    fetch('', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                profiles = profiles.filter(p => p.url !== url);
                renderProfileDropdown();
                $('profileSelect').value = '';
                currentProfileKey = null;
                const s = $('profileStatus');
                s.textContent = 'جدید';
                s.className = 'profile-indicator unsaved';
                showToast('✓ حذف شد');
            } else {
                showToast('خطا: ' + (d.error || ''), true);
            }
        })
        .catch(() => showToast('خطا', true));
}

function setMode(m){
  mode=m;
  document.querySelectorAll('.mode-tab').forEach((t,i)=>t.classList.toggle('active',(i===0&&m==='auto')||(i===1&&m==='visual')));
  $('autoCtrl').classList.toggle('hidden',m!=='auto');
  $('visualCtrl').classList.toggle('hidden',m!=='visual');
  
  if (m === 'visual') {
      switchMainTab('selectors');
  }
}

function loadVisual(){
  const url=$('url').value.trim();
  if(!url){showToast('URL وارد کنید',true);return;}
  $('status').textContent='در حال بارگذاری...';
  $('vFrame').src='?visual_proxy='+encodeURIComponent(url);
  $('vFrame').onload=()=>{
      $('status').textContent='✓ روی المان‌ها کلیک کنید - پیش‌نمایش در پایین نوار';
      showToast('صفحه آماده است - پیش‌نمایش زنده فعال');
  };
}

function suggestSelectors(){
  const url=$('url').value.trim();
  if(!url){showToast('URL وارد کنید',true);return;}
  $('status').textContent='در حال تحلیل...';
  
  fetch('?suggest_selectors='+encodeURIComponent(url))
    .then(r=>r.json())
    .then(d=>{
      if(!d.ok){$('status').textContent='خطا: '+d.error;return;}
      $('suggestions').classList.remove('hidden');
      let html='';
      
      if(d.suggestions.container.length){
        html+='<div style="font-size:11px;color:#67e8f9;padding:6px 8px">📦 کانتینر:</div>';
        d.suggestions.container.forEach(s=>{
          html+=`<div class="suggest-item" onclick="setSel('container','${s.selector}')">${s.selector} <span style="color:#64748b">(${s.count})</span></div>`;
        });
      }
      
      html+='<div style="font-size:11px;color:#67e8f9;padding:6px 8px;border-top:1px solid #334155">📝 عنوان:</div>';
      d.suggestions.title.slice(0,5).forEach(s=>{
        html+=`<div class="suggest-item" onclick="setSel('title','${s.selector}')">${s.selector}</div>`;
      });
      
      html+='<div style="font-size:11px;color:#67e8f9;padding:6px 8px;border-top:1px solid #334155">💰 قیمت:</div>';
      d.suggestions.price.slice(0,4).forEach(s=>{
        html+=`<div class="suggest-item" onclick="setSel('price','${s.selector}')">${s.selector}</div>`;
      });
      
      $('suggestList').innerHTML=html;
      $('status').textContent='✓ پیشنهادها آماده';
      showToast('پیشنهادها در پنل کناری');
    })
    .catch(e=>{$('status').textContent='خطا';showToast('خطا در دریافت',true);});
}

function setSel(type,val){
  sel[type]=val;
  $('sel'+type.charAt(0).toUpperCase()+type.slice(1)).value=val;
  $('s-'+type).classList.toggle('has',!!val);
  updateStartVisBtn();
  scheduleSave();
  
  // Auto-test the selector
  if (val) {
      setTimeout(() => testSel(type), 200);
  }
}

function updateStartVisBtn() {
    const btn = $('startVisBtn');
    if (!btn) return;
    if (sel.container) {
        btn.disabled = false;
        btn.textContent = '▶ شروع اسکرپ';
    } else {
        btn.disabled = true;
        btn.textContent = '▶ نیاز به کانتینر';
    }
}

window.addEventListener('message',e=>{
  if(e.data && e.data.type==='selectors'){
    sel=e.data.data;
    ['container','title','price','link','image'].forEach(k=>{
      $('sel'+k.charAt(0).toUpperCase()+k.slice(1)).value=sel[k]||'';
      $('s-'+k).classList.toggle('has',!!sel[k]);
      // Auto-test
      if (sel[k]) {
          setTimeout(() => testSel(k), 300);
      }
    });
    $('status').textContent='✓ سلکتورها ذخیره شدند';
    updateStartVisBtn();
    scheduleSave();
    showToast('✓ سلکتورها دریافت شد');
  } else if(e.data && e.data.type==='detail_selectors'){
    const data = e.data.data || {};
    let applied = 0;
    for (const k of Object.keys(data)) {
        if (data[k] && DETAIL_FIELDS.some(f => f.key === k)) {
            detailSel[k] = {enabled: true, selector: data[k]};
            applied++;
        }
    }
    renderDetailFieldsList();
    refreshViews();
    scheduleSave();
    showToast(`✓ ${applied} فیلد انتخاب شد`);
    $('detailStatus').textContent = `✓ ${applied} فیلد دریافت شد`;
  } else if(e.data && e.data.type==='cancel'){
    $('vFrame').src='about:blank';
  } else if(e.data && e.data.type==='cancel_detail'){
    $('detailFrameWrap').classList.add('hidden');
    $('detailFrame').src='about:blank';
  }
});

function clearSel(){
  if(!confirm('پاک کردن همه سلکتورها؟')) return;
  sel={container:'',title:'',price:'',link:'',image:''};
  ['container','title','price','link','image'].forEach(k=>{
    $('sel'+k.charAt(0).toUpperCase()+k.slice(1)).value='';
    $('s-'+k).classList.remove('has');
    const prev = $('prev-' + k);
    if (prev) {
        prev.textContent = 'در انتظار...';
        prev.classList.add('empty');
    }
  });
  $('vFrame').src='about:blank';
  $('suggestions').classList.add('hidden');
  updateStartVisBtn();
  scheduleSave();
}

function startVisual(){
  if(!sel.container){showToast('کانتینر انتخاب کنید',true);return;}
  switchMainTab('start');
  setTimeout(() => start(true), 300);
}

function log(m,t='info'){
  const logs = $('logs');
  logs.innerHTML += `<div class="log log-${t}">${new Date().toLocaleTimeString('fa-IR')} ${m}</div>`;
  logs.scrollTop = logs.scrollHeight;
}

function update(){
  $('numP').textContent=toFa(products.size);
  $('numPg').textContent=toFa(pages);
  $('numD').textContent=toFa(details);
  
  const badge = $('resultsBadge');
  if (products.size > 0) {
      badge.classList.remove('hidden');
      badge.textContent = products.size > 99 ? '99+' : products.size;
      badge.className = 'badge ok';
  } else {
      badge.classList.add('hidden');
  }
  
  const empty = $('emptyState');
  if (empty) {
      empty.style.display = products.size > 0 ? 'none' : 'block';
  }
}

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

function renderCard(p){
  const el=document.querySelector(`[data-k="${p.key}"]`);
  let title = getFinalTitle(p.title);
  let price = getFinalPrice(p.price);
  let origPrice = getOriginalPrice(p.price);
  let origDiffers = origPrice !== '0' && origPrice !== price;
  let shortDesc = '';
  if (detailSel.shortDesc && detailSel.shortDesc.enabled && p.shortDesc) {
      shortDesc = stripHtml(p.shortDesc);
  }
  const html=`<div class="thumb">${p.image?`<img src="?image_proxy=${encodeURIComponent(p.image)}" loading="lazy">`:'<div class="noimg">بدون تصویر</div>'}</div>
  <div class="pbody"><div class="ptitle">${esc(title||'بدون عنوان')}</div>
  ${shortDesc ? `<div class="pdetail-short">${esc(shortDesc)}</div>` : ''}
  ${origDiffers ? `<span class="price-orig">${esc(origPrice)}</span>` : ''}
  <div class="price ${price!=='0'?'':'no-price'}">${price!=='0'?esc(price):'؟'}</div>
  ${p.link?`<a class="plink" href="${esc(p.link)}" target="_blank">مشاهده</a>`:''}</div>`;
  if(el)el.innerHTML=html;
  else{const d=document.createElement('div');d.className='product';d.dataset.k=p.key;d.innerHTML=html;$('vGrid').appendChild(d);}
}

function renderRow(p,i){
  const el=$('tBody').querySelector(`[data-k="${p.key}"]`);
  let title = getFinalTitle(p.title);
  let price = getFinalPrice(p.price);
  let origPrice = getOriginalPrice(p.price);
  let customTd = isCustomColEnabled() ? `<td>${esc(getCustomColVal())}</td>` : '';
  let detailTds = '';
  const enabledFields = getEnabledDetailFields();
  enabledFields.forEach(f => {
      const val = p[f.key] || '';
      let display = val;
      if (f.key === 'shortDesc' || f.key === 'longDesc') {
          display = shortText(stripHtml(val), 60);
      }
      detailTds += `<td class="td-detail" title="${esc(stripHtml(val))}">${esc(display) || '-'}</td>`;
  });
  
  const html=`<td>${toFa(i)}</td><td>${esc(title)}</td><td class="td-orig">${esc(origPrice)}</td><td style="direction:ltr;text-align:right">${esc(price)}</td>
  <td>${p.link?`<a href="${esc(p.link)}" target="_blank">لینک</a>`:'-'}</td><td>${p.image?'✓':'-'}</td>${detailTds}${customTd}`;
  if(el)el.innerHTML=html;
  else{const tr=document.createElement('tr');tr.dataset.k=p.key;tr.innerHTML=html;$('tBody').appendChild(tr);}
}

function updateTableHeaders() {
    let thead = $('tBody').parentElement.querySelector('thead tr');
    if(!thead) return;
    let baseHeaders = '<th>#</th><th>عنوان</th><th>قیمت اولیه</th><th>قیمت نهایی</th><th>لینک</th><th>تصویر</th>';
    const enabledFields = getEnabledDetailFields();
    enabledFields.forEach(f => {
        baseHeaders += `<th>${f.icon} ${esc(f.label)}</th>`;
    });
    if (isCustomColEnabled()) {
        baseHeaders += `<th>${esc(getCustomColName())}</th>`;
    }
    thead.innerHTML = baseHeaders;
}

function refreshViews() {
    updateTableHeaders();
    $('vGrid').innerHTML = '';
    $('tBody').innerHTML = '';
    if (products.size === 0) {
        $('vGrid').innerHTML = '<div class="empty-state" id="emptyState"><div class="icon">📭</div><p>هنوز محصولی اسکرپ نشده است.</p></div>';
    } else {
        order.forEach((k, i) => {
            renderCard(products.get(k));
            renderRow(products.get(k), i + 1);
        });
    }
    if ($('txtContent')) $('txtContent').textContent = genTxt();
    update();
}

function removeNoPrice() {
    let keysToDelete = [];
    products.forEach((p, k) => {
        if (getFinalPriceNum(p.price) === 0) {
            keysToDelete.push(k);
        }
    });
    if (keysToDelete.length === 0) {
        showToast('محصول بدون قیمت یافت نشد', true);
        return;
    }
    if (!confirm(`حذف ${keysToDelete.length} محصول بدون قیمت؟`)) return;
    
    keysToDelete.forEach(k => {
        products.delete(k);
        order = order.filter(x => x !== k);
    });
    
    refreshViews();
    log(`✓ ${keysToDelete.length} محصول بدون قیمت حذف شد`, 'ok');
    showToast(`${keysToDelete.length} محصول حذف شد`);
}

function removeBelowMinPrice() {
    const minPrice = getMinPrice();
    let keysToDelete = [];
    products.forEach((p, k) => {
        const finalNum = getFinalPriceNum(p.price);
        if (finalNum > 0 && finalNum < minPrice) {
            keysToDelete.push(k);
        }
    });
    if (keysToDelete.length === 0) {
        showToast(`هیچ محصولی زیر ${toFa(minPrice.toLocaleString('en-US'))} یافت نشد`, true);
        return;
    }
    if (!confirm(`حذف ${keysToDelete.length} محصول با قیمت زیر ${minPrice.toLocaleString('en-US')}؟`)) return;
    
    keysToDelete.forEach(k => {
        products.delete(k);
        order = order.filter(x => x !== k);
    });
    
    refreshViews();
    log(`✓ ${keysToDelete.length} محصول زیر حداقل قیمت حذف شد`, 'ok');
    showToast(`${keysToDelete.length} محصول حذف شد`);
}

function genTxt(){
  let headers = '# | Title | Original | Final | URL';
  const enabledFields = getEnabledDetailFields();
  enabledFields.forEach(f => headers += ` | ${f.label}`);
  if (isCustomColEnabled()) headers += ` | ${getCustomColName()}`;
  let t = headers + '\n' + '-'.repeat(120) + '\n';
  order.forEach((k,i)=>{
      const p=products.get(k);
      if(!p)return;
      let row = `${i+1} | ${(getFinalTitle(p.title)||'').substring(0,25)} | ${getOriginalPrice(p.price)} | ${getFinalPrice(p.price)} | ${p.link||'-'}`;
      enabledFields.forEach(f => {
          let val = p[f.key] || '';
          if (f.key === 'shortDesc' || f.key === 'longDesc') val = shortText(stripHtml(val), 40);
          row += ` | ${val}`;
      });
      if (isCustomColEnabled()) row += ` | ${getCustomColVal()}`;
      t += row + '\n';
  });
  return t;
}

function switchView(v){
  document.querySelectorAll('.sub-tab').forEach(t=>t.classList.toggle('active',t.dataset.v===v));
  $('vGrid').classList.toggle('hidden',v!=='grid');
  $('vTable').classList.toggle('hidden',v!=='table');
  $('vText').classList.toggle('hidden',v!=='text');
  if(v==='table') updateTableHeaders();
  if(v==='text')$('txtContent').textContent=genTxt();
}

function start(useSel=false){
  if(running)return;
  const url=$('url').value.trim();
  if(!url){showToast('URL وارد کنید',true);return;}
  
  if (isDirty) saveProfileSilent();
  
  products.clear();order=[];pages=0;details=0;running=true;
  $('vGrid').innerHTML='';$('tBody').innerHTML='';
  log('▶ شروع: '+url,'info');
  
  $('startBtn').classList.add('hidden');$('stopBtn').classList.remove('hidden');
  $('progress').classList.remove('hidden');$('progressBar').style.width='0%';
  update();
  
  let sUrl=`?stream=1&url=${encodeURIComponent(url)}&pages=${$('pages').value}&pagType=${encodeURIComponent($('pagType').value)}&pagVal=${encodeURIComponent($('pagVal').value)}`;
  if(useSel&&sel.container){
    sUrl+='&selectors='+encodeURIComponent(JSON.stringify(sel));
    log('🎯 سلکتور: '+sel.container,'info');
  }
  
  es=new EventSource(sUrl);
  
  es.addEventListener('page',e=>{const d=JSON.parse(e.data);pages=d.page;log(`📄 صفحه ${d.page}: ${d.ok?'✓':'✗'}`,d.ok?'ok':'err');update();});
  es.addEventListener('product',e=>{const p=JSON.parse(e.data);if(!products.has(p.key)){products.set(p.key,p);order.push(p.key);renderCard(p);renderRow(p,order.length);update();}});
  es.addEventListener('page_done',e=>{const d=JSON.parse(e.data);log(`✓ صفحه ${d.page}: +${d.new} جدید، کل: ${d.total}`,'ok');});
  es.addEventListener('missing_start',e=>{const d=JSON.parse(e.data);$('status').textContent=`دریافت جزئیات ${toFa(d.current)}/${toFa(d.total)}`;$('progressBar').style.width=(d.current/d.total*100)+'%';});
  es.addEventListener('missing_done',e=>{const d=JSON.parse(e.data);details++;const p=products.get(d.key);if(p){if(d.image)p.image=d.image;if(d.price)p.price=d.price;products.set(d.key,p);renderCard(p);renderRow(p,order.indexOf(d.key)+1);}update();});
  es.addEventListener('complete',e=>{
      const d=JSON.parse(e.data);
      log(`✅ تکمیل: ${d.total} محصول`,'ok');
      $('status').textContent=`✓ ${toFa(d.total)} محصول`;
      $('progressBar').style.width='100%';
      showToast(`✓ ${d.total} محصول استخراج شد`);
      switchMainTab('results');
  });
  es.addEventListener('error',e=>{if(e.data)log('❌ '+JSON.parse(e.data).message,'err');});
  es.addEventListener('done',finish);
  es.onerror=()=>{if(running)finish();};
}

function stop(){if(es)es.close();log('⏹ متوقف شد','err');finish();}
function finish(){running=false;if(es){es.close();es=null;}$('startBtn').classList.remove('hidden');$('stopBtn').classList.add('hidden');$('txtContent').textContent=genTxt();}

function startDetailExtraction(){
    if(detailRunning)return;
    const enabledFields = getEnabledDetailFields();
    if (enabledFields.length === 0) {
        showToast('ابتدا فیلدهای مورد نظر را فعال کنید', true);
        switchMainTab('selectors');
        return;
    }
    if (products.size === 0) {
        showToast('ابتدا محصولات را استخراج کنید', true);
        return;
    }
    
    const keys = order.filter(k => {
        const p = products.get(k);
        if (!p.link) return false;
        return enabledFields.some(f => !p[f.key]);
    });
    
    if (keys.length === 0) {
        showToast('همه فیلدها از قبل استخراج شده‌اند', true);
        return;
    }
    
    const urlMap = {};
    keys.forEach(k => urlMap[k] = products.get(k).link);
    
    detailRunning = true;
    $('btnExtractDetail').classList.add('hidden');
    $('btnStopDetail').classList.remove('hidden');
    $('detailProgress').classList.remove('hidden');
    $('detailProgressBar').style.width = '0%';
    $('detailStatus').textContent = `در حال استخراج ${toFa(keys.length)} محصول...`;
    
    log(`🔍 شروع استخراج تفصیلی ${keys.length} محصول`, 'detail');
    
    const params = new URLSearchParams({
        detail_stream: '1',
        keys: keys.join(','),
        detailSelectors: JSON.stringify(detailSel)
    });
    
    const fd = new FormData();
    fd.append('urlMap', JSON.stringify(urlMap));
    
    fetch('?detail_stream=1&' + params.toString(), {method:'POST', body:fd})
        .then(response => {
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            
            function read() {
                reader.read().then(({done, value}) => {
                    if (done) {
                        finishDetailExtraction();
                        return;
                    }
                    buffer += decoder.decode(value, {stream:true});
                    const events = buffer.split('\n\n');
                    buffer = events.pop();
                    events.forEach(ev => parseSSEEvent(ev));
                    read();
                }).catch(err => {
                    log('❌ خطا در استخراج: ' + err.message, 'err');
                    finishDetailExtraction();
                });
            }
            read();
        })
        .catch(err => {
            log('❌ خطا: ' + err.message, 'err');
            finishDetailExtraction();
        });
}

function parseSSEEvent(ev) {
    if (!ev.trim()) return;
    let type = '', data = '';
    ev.split('\n').forEach(line => {
        if (line.startsWith('event:')) type = line.substring(6).trim();
        else if (line.startsWith('data:')) data = line.substring(5).trim();
    });
    if (!type || !data) return;
    
    try {
        const d = JSON.parse(data);
        if (type === 'detail_progress') {
            $('detailStatus').textContent = `استخراج ${toFa(d.current)} از ${toFa(d.total)}...`;
            $('detailProgressBar').style.width = (d.current/d.total*100) + '%';
        } else if (type === 'detail_extracted') {
            const p = products.get(d.key);
            if (p) {
                let added = 0;
                DETAIL_FIELDS.forEach(f => {
                    if (d[f.key] !== undefined && d[f.key] !== null) {
                        p[f.key] = d[f.key];
                        added++;
                    }
                });
                products.set(d.key, p);
                if (added > 0) {
                    renderCard(p);
                    renderRow(p, order.indexOf(d.key) + 1);
                }
            }
        } else if (type === 'detail_complete') {
            log(`✅ استخراج تفصیلی تکمیل: ${d.total} محصول`, 'ok');
            showToast(`✓ استخراج تفصیلی ${d.total} محصول تکمیل شد`);
            $('detailStatus').textContent = `✓ استخراج ${toFa(d.total)} محصول تکمیل شد`;
            $('txtContent').textContent = genTxt();
        } else if (type === 'error') {
            log('❌ ' + d.message, 'err');
        } else if (type === 'done') {
            finishDetailExtraction();
        }
    } catch(e) {}
}

function stopDetailExtraction(){
    if (detailEs) { detailEs.close(); detailEs = null; }
    detailRunning = false;
    log('⏹ استخراج تفصیلی متوقف شد', 'err');
    finishDetailExtraction();
}

function finishDetailExtraction(){
    detailRunning = false;
    $('btnExtractDetail').classList.remove('hidden');
    $('btnStopDetail').classList.add('hidden');
}

function reset(){
  stop();$('url').value='<?=h(DEFAULT_URL)?>';products.clear();order=[];pages=0;details=0;
  $('vGrid').innerHTML='<div class="empty-state" id="emptyState"><div class="icon">📭</div><p>هنوز محصولی اسکرپ نشده است.</p></div>';
  $('tBody').innerHTML='';$('txtContent').textContent='';
  $('logs').innerHTML='<div class="log log-info">ریست شد</div>';
  $('progress').classList.add('hidden');$('status').textContent='آماده';update();switchView('grid');
  clearSel();
  clearDetailSel();
  currentProfileKey = null;
  isDirty = false;
  const s = $('profileStatus');
  s.textContent = 'جدید';
  s.className = 'profile-indicator unsaved';
  switchMainTab('start');
  showToast('✓ ریست شد');
}

function getCSV(){
  let headers = ['"#"', '"Title"', '"Original Price"', '"Final Price"', '"URL"', '"Image"'];
  const enabledFields = getEnabledDetailFields();
  enabledFields.forEach(f => headers.push(`"${f.label}"`));
  if (isCustomColEnabled()) headers.push(`"${getCustomColName()}"`);
  let c = headers.join(',') + '\n';
  
  order.forEach((k,i)=>{
      const p=products.get(k);
      const e=v=>'"'+(v||'').replace(/"/g,'""')+'"';
      let row = [i+1, e(getFinalTitle(p.title)), e(getOriginalPrice(p.price)), e(getFinalPrice(p.price)), e(p.link), e(p.image)];
      enabledFields.forEach(f => {
          let val = p[f.key] || '';
          if (f.key === 'shortDesc' || f.key === 'longDesc') val = stripHtml(val);
          row.push(e(val));
      });
      if (isCustomColEnabled()) row.push(e(getCustomColVal()));
      c += row.join(',') + '\n';
  });
  return c;
}

function copyCSV(){navigator.clipboard.writeText(getCSV()).then(()=>showToast('✓ کپی شد'));}
function copyTxt(){navigator.clipboard.writeText(genTxt()).then(()=>showToast('✓ کپی شد'));}

function dl(action){
    const f=document.createElement('form');
    f.method='POST';
    const enabledFields = getEnabledDetailFields();
    f.innerHTML=`<input type="hidden" name="action" value="${action}">
                 <input type="hidden" name="products">
                 <input type="hidden" name="useCustom" value="${isCustomColEnabled() ? 1 : 0}">
                 <input type="hidden" name="customName" value="${esc(getCustomColName())}">
                 <input type="hidden" name="detailFields">`;
    
    let exportData = [];
    order.forEach(k => {
        let p = products.get(k);
        let row = {
            title: getFinalTitle(p.title),
            origPrice: getOriginalPrice(p.price),
            price: getFinalPrice(p.price),
            link: p.link,
            image: p.image
        };
        enabledFields.forEach(ff => {
            row[ff.key] = p[ff.key] || '';
        });
        if (isCustomColEnabled()) row.custom = getCustomColVal();
        exportData.push(row);
    });
    
    f.querySelector('[name="products"]').value=JSON.stringify(exportData);
    f.querySelector('[name="detailFields"]').value=JSON.stringify(enabledFields);
    document.body.appendChild(f);
    f.submit();
    f.remove();
}
function dlCSV(){dl('csv');}
function dlExcel(){dl('excel');}

renderDetailFieldsList();

if ($('url').value) {
    setTimeout(() => {
        const match = profiles.find(p => p.url === $('url').value.trim());
        if (match) loadProfileFromServer($('url').value.trim());
    }, 100);
}
</script>
</body>
</html>
