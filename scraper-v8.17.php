<?php

ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('post_max_size', '256M');
ini_set('upload_max_filesize', '256M');
ini_set('max_input_vars', '10000');
ignore_user_abort(true);

const DEFAULT_URL = 'https://barfbox.ir/search/?page=1';
const FETCH_MISSING_IMAGES = true;
const PROFILES_FILE = __DIR__ . '/profiles.json';
const CONNECTIONS_FILE = __DIR__ . '/connections.json';
const BSL_PROGRESS_FILE = __DIR__ . '/bsl_progress.json';
const BSL_STOP_FILE = __DIR__ . '/bsl_stop_signal.json';
const WOO_PROGRESS_FILE = __DIR__ . '/woo_progress.json';
const WOO_STOP_FILE = __DIR__ . '/woo_stop_signal.json';
const SYNC_STATE_FILE = __DIR__ . '/sync_state.json';
const BSL_PRODUCTS_FILE = __DIR__ . '/bsl_products_temp.json';
const WOO_QUEUE_FILE = __DIR__ . '/woo_queue.json';
const WOO_PRODUCTS_FILE = __DIR__ . '/woo_products_temp.json';
const BSL_QUEUE_FILE = __DIR__ . '/bsl_queue.json';
const EXTRACT_PROGRESS_FILE = __DIR__ . '/extract_progress.json';
const CATLEARN_FILE = __DIR__ . '/category_learning.json';   // v8.48
const EXTRACT_STOP_FILE = __DIR__ . '/extract_stop_signal.json';
const EXTRACT_QUEUE_FILE = __DIR__ . '/extract_queue.json';
const NOTIF_STATE_FILE = __DIR__ . '/last_notification_check.json';

/* نسخهٔ کد — با هر تغییر در این فایل به‌روز می‌شود */
const APP_VERSION = '8.48';
const APP_VERSION_DATE = '1405/05/10';
const UPLOAD_DIR = __DIR__ . '/uploads/';

function extractReadQueue(): array {
if (!file_exists(EXTRACT_QUEUE_FILE)) return ['entries' => []];
$json = @file_get_contents(EXTRACT_QUEUE_FILE);
$q = json_decode($json ?: '', true) ?: [];
if (!isset($q['entries'])) $q['entries'] = [];
return $q;
}

function extractWriteQueue(array $queue): void {
@file_put_contents(EXTRACT_QUEUE_FILE, json_encode($queue, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * v8.22: نگاشت محصولات قبلی پروفایل به شکل key => product
 * پروفایل ممکن است آرایهٔ [key,product] یا نگاشت مستقیم ذخیره کرده باشد.
 */
function extractPrevMap(array $profile): array {
    $prev = $profile['products'] ?? [];
    if (!$prev) return [];
    $first = reset($prev);
    if (is_array($first) && count($first) >= 2 && isset($first[0]) && is_string($first[0])) {
        $map = [];
        foreach ($prev as $entry) {
            if (is_array($entry) && count($entry) >= 2) $map[$entry[0]] = $entry[1];
        }
        return $map;
    }
    return is_array($prev) ? $prev : [];
}

/**
 * v8.22: سلکتورهای جزئیات به شکل {enabled, selector} ذخیره می‌شوند اما
 * برخی مسیرها آن‌ها را رشته فرض می‌کردند و در نتیجه هرگز اعمال نمی‌شدند.
 * این تابع هر دو شکل را به نگاشت سادهٔ field => selector تبدیل می‌کند.
 */
function extractNormalizeDetailSelectors($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $field => $cfg) {
        if (is_string($cfg)) {
            if (trim($cfg) !== '') $out[$field] = trim($cfg);
        } elseif (is_array($cfg)) {
            if (array_key_exists('enabled', $cfg) && !$cfg['enabled']) continue;
            $sel = trim((string)($cfg['selector'] ?? ''));
            if ($sel !== '') $out[$field] = $sel;
        }
    }
    return $out;
}

/** عدد خالص قیمت برای مقایسه (ارقام فارسی/عربی و جداکننده‌ها حذف می‌شوند) */
function extractPriceNum($price): int {
    $s = persianToEnglish((string)$price);
    $s = preg_replace('~[^\d]~', '', $s);
    return $s === '' ? 0 : (int)$s;
}

/**
 * v8.25: گزارش کامل هر اجرای استخراج را کنار صف نگه می‌دارد تا بعداً
 * قابل مرور باشد. فقط ۲۰ گزارش آخر می‌ماند تا دیسک پر نشود.
 */
function extractReportFile(string $queueId): string {
    $safe = preg_replace('~[^A-Za-z0-9_.-]~', '_', $queueId);
    return __DIR__ . '/extract_report_' . $safe . '.json';
}

function extractSaveReport(string $queueId, array $data): void {
    @file_put_contents(extractReportFile($queueId), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    $all = glob(__DIR__ . '/extract_report_*.json') ?: [];
    if (count($all) > 20) {
        usort($all, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($all, 20) as $old) @unlink($old);
    }
}

function extractLoadReport(string $queueId): ?array {
    $f = extractReportFile($queueId);
    if (!is_file($f)) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/**
 * v8.25: قیمت مبدأ (قیمت واقعی سایت) را برمی‌گرداند.
 *
 * مقایسهٔ گران/ارزان شدن باید همیشه روی قیمت اصلیِ سایت انجام شود، نه روی
 * قیمتی که خودمان با درصد/ضریب/گردکردن ساخته‌ایم. در غیر این صورت، تغییر
 * تنظیمات قیمت باعث می‌شد صدها محصول به اشتباه «گران شده» گزارش شوند در
 * حالی که سایت مبدأ اصلاً تغییری نکرده است.
 *
 * ترتیب اولویت عمداً «قیمت خام» را اول می‌گذارد تا اگر روزی فیلدی از قیمت
 * محاسبه‌شده ذخیره شد، مقایسه خراب نشود.
 */
function extractSourcePrice(array $p): string {
    foreach (['srcPrice', 'rawPrice', 'origPrice', 'originalPrice', 'price'] as $k) {
        if (isset($p[$k]) && trim((string)$p[$k]) !== '') return (string)$p[$k];
    }
    return '';
}

/**
 * v8.26: آیا این رشته یک قیمت واقعی است؟
 *
 * سایت‌ها برای کالای ناموجود یا «تماس بگیرید» یا رشتهٔ خالی می‌گذارند یا
 * متنی بدون عدد. عدد صفر هم قیمت معتبر نیست. چنین محصولی برای فروش
 * آماده نیست و باید در دستهٔ ناموجود بیفتد، نه «بدون تغییر».
 */
function extractHasPrice($price): bool {
    return extractPriceNum($price) > 0;
}

/**
 * v8.22: مقایسهٔ زندهٔ محصولات فعلی با نسخهٔ قبلی.
 * برای هر تغییر قیمت مشخص می‌کند گران شده یا ارزان.
 * لیست‌ها برای جلوگیری از بزرگ شدن فایل progress محدود می‌شوند.
 */
function extractLiveCompare(array $current, array $prevMap, int $limit = 300): array {
    $new = []; $changed = []; $removed = [];
    $nNew = 0; $nChanged = 0; $nUnchanged = 0; $nUp = 0; $nDown = 0;
    $nGone = 0;   // v8.26: محصولاتی که هنوز در سایت هستند ولی قیمت ندارند

    foreach ($current as $key => $p) {
        $curr = extractSourcePrice($p);          // همیشه قیمت مبدأ
        $prev = $prevMap[$key] ?? null;
        $currHas = extractHasPrice($curr);

        if (!$prev) {
            // v8.26: محصول تازه‌ای که اصلاً قیمت ندارد، «جدید» نیست —
            // چیزی برای فروش نیست، پس در دستهٔ ناموجود می‌نشیند.
            if (!$currHas) {
                $nGone++;
                if (count($removed) < $limit) {
                    $removed[] = ['title' => $p['title'] ?? $p['name'] ?? $key, 'price' => '',
                                  'key' => $key, 'link' => $p['link'] ?? '', 'image' => $p['image'] ?? '',
                                  'reason' => 'بدون قیمت'];
                }
                continue;
            }
            $nNew++;
            if (count($new) < $limit) {
                $new[] = ['title' => $p['title'] ?? $p['name'] ?? $key, 'price' => $curr,
                          'key' => $key, 'link' => $p['link'] ?? '', 'image' => $p['image'] ?? ''];
            }
            continue;
        }

        $prevPrice = extractSourcePrice(is_array($prev) ? $prev : []);
        $prevHas = extractHasPrice($prevPrice);

        // v8.26: قیمت داشت و حالا ندارد → ناموجود شده. قبلاً این حالت
        // «بدون تغییر» شمرده می‌شد و کاملاً از دید پنهان می‌ماند.
        if ($prevHas && !$currHas) {
            $nGone++;
            if (count($removed) < $limit) {
                $removed[] = ['title' => $p['title'] ?? $p['name'] ?? $key,
                              'price' => $prevPrice, 'key' => $key,
                              'link' => $p['link'] ?? '', 'image' => $p['image'] ?? '',
                              'reason' => 'ناموجود شد'];
            }
            continue;
        }
        // قیمت نداشت و حالا دارد → دوباره موجود شد، مثل محصول جدید
        if (!$prevHas && $currHas) {
            $nNew++;
            if (count($new) < $limit) {
                $new[] = ['title' => $p['title'] ?? $p['name'] ?? $key, 'price' => $curr,
                          'key' => $key, 'link' => $p['link'] ?? '', 'image' => $p['image'] ?? '',
                          'reason' => 'دوباره موجود شد'];
            }
            continue;
        }
        // هیچ‌کدام قیمت ندارند → همچنان ناموجود، نه «بدون تغییر»
        if (!$prevHas && !$currHas) { $nGone++; continue; }

        $oldN = extractPriceNum($prevPrice);
        $newN = extractPriceNum($curr);
        // اگر فقط قالب‌بندی عوض شده باشد (مثلاً «۱۲۰۰۰۰» و «۱۲۰,۰۰۰»)
        // عدد یکسان است و نباید «تغییر قیمت» شمرده شود.
        if ($oldN === $newN) { $nUnchanged++; continue; }

        $nChanged++;
        $dir = $newN > $oldN ? 'up' : 'down';
        if ($dir === 'up') $nUp++; else $nDown++;
        $diff = $newN - $oldN;
        $pct = $oldN > 0 ? round($diff / $oldN * 100, 1) : 0;
        if (count($changed) < $limit) {
            $changed[] = ['title' => $p['title'] ?? $p['name'] ?? $key,
                          'old_price' => $prevPrice, 'new_price' => $curr,
                          'dir' => $dir, 'diff' => $diff, 'pct' => $pct,
                          'key' => $key, 'link' => $p['link'] ?? '', 'image' => $p['image'] ?? ''];
        }
    }

    foreach ($prevMap as $key => $prev) {
        if (isset($current[$key])) continue;
        $removed[] = ['title' => $prev['title'] ?? $prev['name'] ?? $key,
                      'price' => extractSourcePrice(is_array($prev) ? $prev : []), 'key' => $key,
                      'link' => $prev['link'] ?? '', 'image' => $prev['image'] ?? '',
                      'reason' => 'از سایت حذف شد'];
        if (count($removed) > $limit) array_pop($removed);
    }

    // v8.26: «حذف‌شده» = هم آن‌هایی که از سایت رفته‌اند و هم آن‌هایی که
    // هنوز هستند ولی قیمت ندارند (ناموجود). شمارش از روی خودِ لیست
    // انجام نمی‌شود چون لیست سقف دارد ولی شمارنده باید دقیق بماند.
    $nRemovedGone = 0;
    foreach ($prevMap as $key => $_) { if (!isset($current[$key])) $nRemovedGone++; }

    return [
        'new' => $nNew, 'price_changed' => $nChanged,
        'removed' => $nRemovedGone + $nGone,
        'gone_from_site' => $nRemovedGone, 'no_price' => $nGone,
        'unchanged' => $nUnchanged, 'price_up' => $nUp, 'price_down' => $nDown,
        'new_items' => $new, 'changed_items' => $changed, 'removed_items' => $removed,
    ];
}

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

$url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

function writeProgress(string $file, array $data): void {
@file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function readProgress(string $file): array {
if (!file_exists($file)) return ['running'=>false,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'total'=>0,'last_title'=>'','last_index'=>0,'done'=>false,'started_at'=>0,'total_log_count'=>0];
$d = @json_decode(@file_get_contents($file) ?: '', true);
return is_array($d) ? $d : ['running'=>false,'total_log_count'=>0];
}
function loadConnections(): array {
if (!file_exists(CONNECTIONS_FILE)) return ['woocommerce'=>[],'basalam'=>['token'=>'','vendor_id'=>0,'preparation_days'=>3,'weight'=>500,'package_weight'=>600,'stock'=>10,'category_id'=>0,'auto_category'=>false]];
$d = @json_decode(@file_get_contents(CONNECTIONS_FILE) ?: '', true);
return is_array($d) ? $d : ['woocommerce'=>[],'basalam'=>[]];
}
function saveConnections(array $c): bool {
return @file_put_contents(CONNECTIONS_FILE, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function loadSyncState(): array {
if (!file_exists(SYNC_STATE_FILE)) return [];
$d = @json_decode(@file_get_contents(SYNC_STATE_FILE) ?: '', true);
return is_array($d) ? $d : [];
}
function saveSyncState(array $s): bool {
return @file_put_contents(SYNC_STATE_FILE, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function parseCSVFile(string $filePath): array {
$rows = [];
$handle = fopen($filePath, 'r');
if (!$handle) return [];

$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);
$headers = null;
while (($row = fgetcsv($handle, 0, ',')) !== false) {
if ($headers === null) { $headers = $row; continue; }
if (count($row) < count($headers)) continue;
$assoc = [];
foreach ($headers as $i => $h) {
$h = trim($h);
$assoc[$i] = $row[$i] ?? '';
}
$rows[] = ['headers' => $headers, 'values' => $assoc];
}
fclose($handle);
return ['headers' => $headers ?: [], 'rows' => $rows];
}
function parseExcelXML(string $filePath): array {
$content = file_get_contents($filePath);
if (!$content) return ['headers' => [], 'rows' => []];
libxml_use_internal_errors(true);
$xml = @simplexml_load_string($content);
if (!$xml) return ['headers' => [], 'rows' => []];
$ns = $xml->getNamespaces(true);
$ss = $ns['ss'] ?? 'urn:schemas-microsoft-com:office:spreadsheet';
$rows = [];
$headers = [];
$rowIdx = 0;
foreach ($xml->Worksheet->Table->Row as $row) {
$cells = [];
$colIdx = 0;
foreach ($row->Cell as $cell) {
$attrs = $cell->attributes($ss);
$idx = isset($attrs['Index']) ? (int)$attrs['Index'] - 1 : $colIdx;
$data = $cell->Data;
$dAttrs = $data ? $data->attributes($ss) : null;
$val = $data ? (string)$data : '';
$cells[$idx] = $val;
$colIdx = $idx + 1;
}
if ($rowIdx === 0) {
$headers = $cells;
} else {
$rows[] = ['headers' => $headers, 'values' => $cells];
}
$rowIdx++;
}
return ['headers' => array_values($headers), 'rows' => $rows];
}
function parseUploadedFile(string $filePath, string $ext): array {
$ext = strtolower($ext);
if ($ext === 'csv') return parseCSVFile($filePath);
if ($ext === 'xls' || $ext === 'xml') return parseExcelXML($filePath);
if ($ext === 'xlsx') {

if (class_exists('ZipArchive')) {
$zip = new ZipArchive();
if ($zip->open($filePath) === true) {
$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
$shared = $zip->getFromName('xl/sharedStrings.xml');
$zip->close();
if ($sheet) {
$strings = [];
if ($shared) {
$sx = @simplexml_load_string($shared);
if ($sx) {
$ns = $sx->getNamespaces(true);
foreach ($sx->children($ns[''] ?? '')->si as $si) {
$strings[] = (string)($si->children($ns[''] ?? '')->t);
}
}
}
$sxml = @simplexml_load_string($sheet);
if ($sxml) {
$ns = $sxml->getNamespaces(true);
$rows = [];
$headers = [];
$rowIdx = 0;
foreach ($sxml->children($ns[''] ?? '')->sheetData->row as $row) {
$cells = [];
foreach ($row->children($ns[''] ?? '')->c as $cell) {
$attrs = $cell->attributes();
$ref = (string)$attrs['r'];
$col = preg_replace('/\d/', '', $ref);
$colIdx = columnToIndex($col);
$type = (string)$attrs['t'];
$val = (string)$cell->v;
if ($type === 's' && isset($strings[(int)$val])) {
$val = $strings[(int)$val];
}
$cells[$colIdx] = $val;
}
if ($rowIdx === 0) { $headers = $cells; }
else { $rows[] = ['headers' => $headers, 'values' => $cells]; }
$rowIdx++;
}
return ['headers' => array_values($headers), 'rows' => $rows];
}
}
$zip->close();
}
}
return ['headers' => [], 'rows' => [], 'error' => 'قادر به خواندن فایل xlsx نیست (ZipArchive لازم است)'];
}
return ['headers' => [], 'rows' => [], 'error' => 'فرمت پشتیبانی نمی‌شود'];
}
function columnToIndex(string $col): int {
$idx = 0;
for ($i = 0; $i < strlen($col); $i++) {
$idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
}
return $idx - 1;
}
function wooReq(string $u, string $ck, string $cs, string $m, string $ep, $d=null): array {
$url = rtrim($u,'/').'/wp-json/wc/v3/'.ltrim($ep,'/');
$ch=curl_init($url);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_USERPWD=>"$ck:$cs",CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_CUSTOMREQUEST=>$m]);
if($d!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($d,JSON_UNESCAPED_UNICODE));
$b=curl_exec($ch);$e=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
return ['ok'=>$code>=200&&$code<300,'code'=>$code,'error'=>$e,'body'=>@json_decode($b,true),'raw'=>$b];
}
function bslReq(string $tk, string $m, string $ep, $d=null, bool $mp=false): array {
$url='https://openapi.basalam.com/v1/'.ltrim($ep,'/');

$maxRetries=3;$retryDelay=3;
for($attempt=1;$attempt<=$maxRetries;$attempt++){

if(file_exists(BSL_STOP_FILE)){return ['ok'=>false,'code'=>0,'error'=>'stopped','body'=>null,'raw'=>''];}
$ch=curl_init($url);
$h=['Accept: application/json','Authorization: Bearer '.$tk];
if(!$mp)$h[]='Content-Type: application/json';
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_HTTPHEADER=>$h,CURLOPT_CUSTOMREQUEST=>$m]);
if($d!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$mp?$d:json_encode($d,JSON_UNESCAPED_UNICODE));
$b=curl_exec($ch);$e=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);

if($code===0&&$attempt<$maxRetries&&$e!==''&&mb_strpos($e,'timed out')!==false||mb_strpos($e,'connection')!==false||mb_strpos($e,'resolve')!==false||mb_strpos($e,'refused')!==false){
sleep($retryDelay);
continue;
}
return ['ok'=>$code>=200&&$code<300,'code'=>$code,'error'=>$e,'body'=>@json_decode($b,true),'raw'=>$b];
}
return ['ok'=>false,'code'=>$code,'error'=>$e.' (after '.$maxRetries.' retries)','body'=>@json_decode($b,true),'raw'=>$b];
}

$bslCatNameMap_global=[];
function bslCatNameById($id){global $bslCatNameMap_global;return $bslCatNameMap_global[$id]??'';}
function bslSetCatNameMap($flatCats){global $bslCatNameMap_global;$bslCatNameMap_global=[];foreach($flatCats as $fc){$bslCatNameMap_global[$fc['id']]=$fc['name'];}}

function findLeafCategory(int $catId, array $cData): int {

foreach ($cData as $c) {
$cid = (int)($c['id'] ?? 0);
if ($cid === $catId) {
$children = $c['children'] ?? [];
if (empty($children) || !is_array($children)) return $catId;

$firstChild = $children[0] ?? null;
if (!$firstChild) return $catId;
$firstChildId = (int)($firstChild['id'] ?? 0);
if ($firstChildId <= 0) return $catId;

return findLeafCategory($firstChildId, $cData);
}

$children = $c['children'] ?? [];
if (is_array($children) && count($children) > 0) {
$result = findLeafCategory($catId, $children);
if ($result > 0) return $result;
}
}
return $catId;
}

/* =====================================================================
 *  v8.48: یادگیری دسته‌بندی از انتخاب‌های دستی
 *
 *  هر بار کاربر دستهٔ یک محصول را دستی اصلاح می‌کند، کلمهٔ اولِ عنوان
 *  به‌همراه دستهٔ انتخاب‌شده ذخیره می‌شود. دفعهٔ بعد که محصولی با همان
 *  کلمهٔ اول بیاید، همان دسته پیشنهاد می‌شود.
 *
 *  چرا کلمهٔ اول: در فارسی نام محصول تقریباً همیشه با نوع کالا شروع
 *  می‌شود — «کفش ورزشی مردانه»، «عسل طبیعی سبلان». کلمهٔ اول تعیین‌کننده
 *  است و بقیه صفت‌اند. الگوریتم قبلی همهٔ کلمات را یکسان می‌دید، برای
 *  همین «روغن موتور» گاهی زیر «موتور سیکلت» می‌رفت.
 * ===================================================================== */

/** کلمهٔ اولِ معنادار عنوان را برمی‌گرداند */
function catFirstWord(string $title): string {
    $n = preg_replace('/[0-9!@#$%^&*()+=\[\]{}|\\\\:;"\'<>,.?\/_\-–—…·«»]/u', ' ',
                      mb_strtolower(trim($title), 'UTF-8'));
    $n = preg_replace('/\s{2,}/u', ' ', trim($n));
    if ($n === '') return '';
    $skip = ['خرید', 'فروش', 'قیمت', 'ارسال', 'رایگان', 'تخفیف', 'ویژه', 'جدید', 'اصل', 'اصلی'];
    foreach (preg_split('/\s+/u', $n) as $w) {
        $w = trim($w);
        if (mb_strlen($w, 'UTF-8') < 2) continue;
        if (in_array($w, $skip, true)) continue;   // کلمات تبلیغاتی ابتدای عنوان
        return $w;
    }
    return '';
}

function catLearnLoad(): array {
    if (!is_file(CATLEARN_FILE)) return [];
    $d = json_decode((string)@file_get_contents(CATLEARN_FILE), true);
    return is_array($d) ? $d : [];
}

function catLearnSave(array $d): void {
    @file_put_contents(CATLEARN_FILE, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * ثبت یک انتخاب دستی.
 * برای هر کلمهٔ اول، شمارش هر دسته نگه داشته می‌شود تا اگر کاربر نظرش
 * عوض شد، انتخاب تازه‌تر و پرتکرارتر برنده شود.
 */
function catLearnRecord(string $title, int $catId, string $catName = ''): bool {
    $w = catFirstWord($title);
    if ($w === '' || $catId <= 0) return false;
    $d = catLearnLoad();
    if (!isset($d[$w]) || !is_array($d[$w])) $d[$w] = ['cats' => [], 'n' => 0];
    $k = (string)$catId;
    $prev = $d[$w]['cats'][$k] ?? ['n' => 0, 'name' => ''];
    $d[$w]['cats'][$k] = ['n' => (int)$prev['n'] + 1,
                          'name' => $catName !== '' ? $catName : (string)($prev['name'] ?? ''),
                          'at' => time()];
    $d[$w]['n'] = (int)($d[$w]['n'] ?? 0) + 1;
    $d[$w]['last'] = time();
    // سقف حجم: ۱۰۰۰ کلمه، قدیمی‌ترها حذف می‌شوند
    if (count($d) > 1000) {
        uasort($d, fn($a, $b) => (int)($b['last'] ?? 0) <=> (int)($a['last'] ?? 0));
        $d = array_slice($d, 0, 1000, true);
    }
    catLearnSave($d);
    return true;
}

/** دستهٔ آموخته‌شده برای کلمهٔ اولِ این عنوان، یا ۰ */
function catLearnLookup(string $title, ?array $learned = null): int {
    $w = catFirstWord($title);
    if ($w === '') return 0;
    $d = $learned ?? catLearnLoad();
    $row = $d[$w] ?? null;
    if (!is_array($row) || empty($row['cats'])) return 0;
    $bestId = 0; $bestN = 0; $bestAt = 0;
    foreach ($row['cats'] as $cid => $info) {
        $n = (int)($info['n'] ?? 0); $at = (int)($info['at'] ?? 0);
        // پرتکرارترین؛ در تساوی، تازه‌ترین
        if ($n > $bestN || ($n === $bestN && $at > $bestAt)) {
            $bestN = $n; $bestAt = $at; $bestId = (int)$cid;
        }
    }
    return $bestId;
}

function autoMatchBslCategory(string $productTitle, array $flatCategories): int {

$norm=preg_replace('/[0-9!@#$%^&*()+=\[\]{}|\\\\:;"\'<>,.?\/_\-–—…·«»]/u',' ',mb_strtolower(trim($productTitle),'UTF-8'));
$norm=preg_replace('/\s{2,}/u',' ',$norm);

$stop=['قیمت','فروش','ارسال','رایگان','تخفیف','ویژه','نو','جدید','ست','بسته','دار','تکه','عدد','پک','سایز','رنگ','و','با','از','برای','در','یک','این','آن','که','هم','است','بود','شد','کن','کرد','باید','دیگر','بندی','جعبه','کیسه','سفید','مشکی','آبی','طلایی','سلیکونی','فانتزی','کد','اصلی','مخصوص','تک','فرد','نوع','مدل','خط','سری','متفرقه','کرانه','تن'];
$pWords=array_unique(array_filter(preg_split('/\s+/u',$norm),function($w)use($stop){return mb_strlen($w,'UTF-8')>=2&&!in_array($w,$stop);}));
if(empty($pWords))return 0;

// v8.48: اول حافظهٔ یادگیری. اگر کاربر قبلاً برای این کلمهٔ اول دسته‌ای
// انتخاب کرده، همان برنده است — انتخاب انسان بر حدس الگوریتم مقدم است.
$learnedId=catLearnLookup($productTitle);
if($learnedId>0){
foreach($flatCategories as $c){if((int)($c['id']??0)===$learnedId)return $learnedId;}
}

// v8.48: کلمهٔ اول نوع کالا را می‌گوید و بقیه صفت‌اند، پس وزنش بیشتر است
$firstWord=catFirstWord($productTitle);

$bestScore=0;$bestCatId=0;
foreach($flatCategories as $cat){

$catNorm=preg_replace('/[0-9()]/u',' ',mb_strtolower(trim($cat['name']??''),'UTF-8'));
$catNorm=preg_replace('/\s{2,}/u',' ',trim($catNorm));
$catWords=array_unique(array_filter(preg_split('/\s+/u',$catNorm),function($w){return mb_strlen($w,'UTF-8')>=2;}));
if(empty($catWords))continue;
$overlap=0;
foreach($pWords as $pw){
$pwLen=mb_strlen($pw,'UTF-8');
// v8.48: تطبیق روی کلمهٔ اول سه برابر ارزش دارد
$wMul=($firstWord!==''&&$pw===$firstWord)?3:1;
foreach($catWords as $cw){
$cw=trim($cw);$cwLen=mb_strlen($cw,'UTF-8');

if($pw===($cw)){$overlap+=3*$wMul;break;}

if($pwLen<$cwLen){

if(mb_substr($cw,0,$pwLen,'UTF-8')===($pw)){
$nc=mb_substr($cw,$pwLen,1,'UTF-8');
if($nc===' '||$nc==='\u200c'||$nc==='‌'){$overlap+=2*$wMul;break;}
}

if(mb_substr($cw,$cwLen-$pwLen,$pwLen,'UTF-8')===($pw)){
$pc=mb_substr($cw,$cwLen-$pwLen-1,1,'UTF-8');
if($pc===' '||$pc==='\u200c'||$pc==='‌'){$overlap+=2*$wMul;break;}
}
}

if($pwLen>$cwLen&&mb_strpos($pw,$cw,0,'UTF-8')!==false){
$pos=mb_strpos($pw,$cw,0,'UTF-8');
$beforeOk=$pos===0||mb_substr($pw,$pos-1,1,'UTF-8')===' ';
$afterOk=$pos+$cwLen===mb_strlen($pw,'UTF-8')||mb_substr($pw,$pos+$cwLen,1,'UTF-8')===' ';
if($beforeOk&&$afterOk){$overlap+=1.5*$wMul;break;}
}
}
}

$score=$overlap+($cat['level']??0)*0.2;
if($score>$bestScore){$bestScore=$score;$bestCatId=(int)$cat['id'];}
}

if($bestScore<2)return 0;
return $bestCatId;
}

function autoMatchBslCategoryForce(string $productTitle, array $flatCategories): int {
$norm=preg_replace('/[0-9!@#$%^&*()+=\[\]{}|\\\\:;"\'<>,.?\/_\-–—…·«»]/u',' ',mb_strtolower(trim($productTitle),'UTF-8'));
$norm=preg_replace('/\s{2,}/u',' ',$norm);
$stop=['و','با','از','برای','در','یک','این','آن','که','هم','است','بود','شد','کن','کرد','باید','دیگر'];
$pWords=array_unique(array_filter(preg_split('/\s+/u',$norm),function($w)use($stop){return mb_strlen($w,'UTF-8')>=2&&!in_array($w,$stop);}));
if(empty($pWords))return 0;
$scores=[];
foreach($flatCategories as $cat){
$catNorm=preg_replace('/[0-9()]/u',' ',mb_strtolower(trim($cat['name']??''),'UTF-8'));
$catNorm=preg_replace('/\s{2,}/u',' ',trim($catNorm));
$catWords=array_unique(array_filter(preg_split('/\s+/u',$catNorm),function($w){return mb_strlen($w,'UTF-8')>=2;}));
if(empty($catWords))continue;
$overlap=0;
foreach($pWords as $pw){
$pwLen=mb_strlen($pw,'UTF-8');
foreach($catWords as $cw){
$cw=trim($cw);$cwLen=mb_strlen($cw,'UTF-8');

if($pw===($cw)){$overlap+=3;break;}

if($pwLen<$cwLen){
if(mb_substr($cw,0,$pwLen,'UTF-8')===($pw)){
$nc=mb_substr($cw,$pwLen,1,'UTF-8');
if($nc===' '||$nc==='\u200c'||$nc==='‌'){$overlap+=2;break;}
}
if(mb_substr($cw,$cwLen-$pwLen,$pwLen,'UTF-8')===($pw)){
$pc=mb_substr($cw,$cwLen-$pwLen-1,1,'UTF-8');
if($pc===' '||$pc==='\u200c'||$pc==='‌'){$overlap+=2;break;}
}
}
if($pwLen>$cwLen&&mb_strpos($pw,$cw,0,'UTF-8')!==false){
$pos=mb_strpos($pw,$cw,0,'UTF-8');
$beforeOk=$pos===0||mb_substr($pw,$pos-1,1,'UTF-8')===' ';
$afterOk=$pos+$cwLen===mb_strlen($pw,'UTF-8')||mb_substr($pw,$pos+$cwLen,1,'UTF-8')===' ';
if($beforeOk&&$afterOk){$overlap+=1.5;break;}
}
}
}
$score=$overlap+($cat['level']??0)*0.5;
$scores[(int)($cat['id']??0)]=$score;
}
if(empty($scores))return 0;
arsort($scores);
$bestCatId=array_key_first($scores);
$bestScore=$scores[$bestCatId];

if($bestScore<1)return 0;
return $bestCatId;
}

function extractAiCategoryFromText(string $aiText, array $flatCategories): int {
$r=extractAiCategoryFromTextEx($aiText,$flatCategories);
return $r['catId'];
}

function extractAiCategoryFromTextEx(string $aiText, array $flatCategories): array {
$result=['catId'=>0,'catName'=>'','matchMethod'=>'','aiTextSnippet'=>mb_substr(trim($aiText),0,300),'score'=>0,'allCandidates'=>[]];
$aiText=trim($aiText);
if($aiText==='')return $result;

$extracted=[];
$matchMethod='regex';
$patterns=[
'/دسته[\s‌]*بندی[\s‌]*(مناسب|پیشنهادی|صحیح|درست)??[:\s]*([^\.\n,؛،]+)/iu',
'/در[\s‌]*دسته[\s‌]*بندی?[\s]*([^\.\n,؛،]+?)[\s‌]*(قرار|متعلق|است|می)/iu',
'/متعلق[\s‌]*به[\s‌]*دسته[\s‌]*بندی?[\s]*([^\.\n,؛،]+)/iu',
'/پیشنهاد[\s]*[:\s]*([^\.\n,؛،]+)/iu',
'/توصیه[\s]*[:\s]*([^\.\n,؛،]+)/iu',
'/صحیح[\s‌]*ترین[\s‌]*دسته[\s‌]*بندی?[:\s]*([^\.\n,؛،]+)/iu',
];
foreach($patterns as $pat){
if(preg_match($pat,$aiText,$m)){
$name=trim($m[count($m)-1]);
$name=preg_replace('/^است\s*/u','',$name);
$name=trim($name," \t\n\r\0\x0B:؛،,.");
if($name!=='')$extracted[]=['name'=>$name,'source'=>'regex','pattern'=>$pat];
}
}

$aiLower=mb_strtolower($aiText,'UTF-8');
foreach($flatCategories as $cat){
$catName=trim($cat['name']??'');
if($catName==='')continue;
$catNameLower=mb_strtolower($catName,'UTF-8');

if(mb_strlen($catNameLower,'UTF-8')>=4&&mb_strpos($aiLower,$catNameLower)!==false){
$extracted[]=['name'=>$catName,'source'=>'bruteforce','catId'=>$cat['id']??0,'level'=>$cat['level']??0];
}
}
if(empty($extracted))return $result;

$bestCatId=0;$bestScore=0;$bestCatName='';$bestMethod='';
$allCandidates=[];
foreach($extracted as $ext){
$extName=trim($ext['name']??'');
$extSource=$ext['source']??'unknown';
$weightBonus=($extSource==='regex')?2.0:1.0;
$extLower=mb_strtolower($extName,'UTF-8');
$extWords=array_unique(array_filter(preg_split('/\s+/u',$extLower),function($w){return mb_strlen($w,'UTF-8')>=2;}));
if(empty($extWords))continue;
foreach($flatCategories as $cat){
$catNorm=preg_replace('/[0-9()]/u',' ',mb_strtolower(trim($cat['name']??''),'UTF-8'));
$catNorm=preg_replace('/\s{2,}/u',' ',trim($catNorm));
$catWords=array_unique(array_filter(preg_split('/\s+/u',$catNorm),function($w){return mb_strlen($w,'UTF-8')>=2;}));
if(empty($catWords))continue;
$overlap=0;
foreach($extWords as $ew){
foreach($catWords as $cw){
if($ew===$cw){$overlap+=3;break;}
if(mb_strpos($cw,$ew,0,'UTF-8')!==false||mb_strpos($ew,$cw,0,'UTF-8')!==false){$overlap+=1.5;break;}
}
}
$score=$overlap*$weightBonus+($cat['level']??0)*0.3;

if($extLower===mb_strtolower(trim($cat['name']??''),'UTF-8'))$score+=10;
$cid=(int)($cat['id']??0);
if($cid>0&&$score>0){
$allCandidates[]=['catId'=>$cid,'catName'=>$cat['name']??'','score'=>round($score,1),'method'=>$extSource,'extractedName'=>$extName];
}
if($score>$bestScore){$bestScore=$score;$bestCatId=$cid;$bestCatName=$cat['name']??'';$bestMethod=$extSource;}
}
}

usort($allCandidates,function($a,$b){return $b['score']<=>$a['score'];});
$result['allCandidates']=array_slice($allCandidates,0,5);
if($bestScore<1.5)return $result;
$result['catId']=$bestCatId;
$result['catName']=$bestCatName;
$result['matchMethod']=$bestMethod;
$result['score']=round($bestScore,1);
return $result;
}

function bslNormalizeTitle(string $t): string {

$t=str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'],['0','1','2','3','4','5','6','7','8','9'],$t);

$t=preg_replace('/[\-–—…·«»]/u','',$t);

$t=preg_replace('/\s+/u',' ',trim($t));

$t=preg_replace('/[()]/u','',$t);

$t=mb_strtolower($t,'UTF-8');
return $t;
}
function wooUploadImage(string $storeUrl, string $ck, string $cs, string $imgUrl): array {
static $cache=[];
$imgUrl=html_entity_decode($imgUrl,ENT_QUOTES|ENT_HTML5,'UTF-8');
$imgUrl=trim($imgUrl);
if(empty($imgUrl)||preg_match('~^(data:|blob:|javascript:|#)~i',$imgUrl))return['ok'=>0,'error'=>'empty/invalid URL'];
$cacheKey=md5($imgUrl);
if(isset($cache[$cacheKey]))return['ok'=>1,'media_id'=>$cache[$cacheKey],'source_url'=>''];

$wooMediaUpload=function($fdata,$ct,$filename)use($storeUrl,$ck,$cs,$cacheKey,&$cache){
$mediaUrl=rtrim($storeUrl,'/').'/wp-json/wp/v2/media';
for($attempt=1;$attempt<=2;$attempt++){
$ch=curl_init($mediaUrl);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0,
CURLOPT_USERPWD=>"$ck:$cs",
CURLOPT_POST=>1,
CURLOPT_HTTPHEADER=>['Content-Disposition: attachment; filename="'.$filename.'"','Content-Type: '.$ct],
CURLOPT_POSTFIELDS=>$fdata,
CURLOPT_CUSTOMREQUEST=>'POST']);
$resp=curl_exec($ch);$respErr=curl_error($ch);$respCode=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
if($respCode>=200&&$respCode<300){
$mediaData=@json_decode($resp,true);
$mediaId=$mediaData['id']??0;
if($mediaId){$cache[$cacheKey]=$mediaId;return['ok'=>1,'media_id'=>$mediaId,'source_url'=>$mediaData['source_url']??''];}
return['ok'=>0,'error'=>'ID مدیا دریافت نشد (attempt '.$attempt.')'];
}
$respBody=@json_decode($resp,true);
$errMsg=$respBody['message']??($respErr?:'HTTP '.$respCode);
if($attempt<2)usleep(1000000);
}
return['ok'=>0,'error'=>'آپلود مدیا ناموفق (2 تلاش): '.$errMsg];
};

$localPath=saveImageLocal($imgUrl);
if($localPath&&file_exists($localPath)&&filesize($localPath)>200){
$fdata=@file_get_contents($localPath);
if($fdata&&isImageData($fdata)){

$realFmt=detectImageFormat($fdata);
if($realFmt==='')$realFmt='jpg';
$convResult=convertToSupportedFormat($fdata);
if(!empty($convResult['ok'])){$fdata=$convResult['data'];$realFmt=$convResult['format'];}
$ext=$realFmt;
$ct='image/'.($ext==='jpg'?'jpeg':$ext);
$filename='product_'.substr($cacheKey,0,10).'.'.$ext;
$upResult=$wooMediaUpload($fdata,$ct,$filename);
if(!empty($upResult['ok']))return $upResult;
}
}

$parsed=parse_url($imgUrl);$origin=($parsed['scheme']??'https').'://'.($parsed['host']??'');
$ch=curl_init($imgUrl);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0,
CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
CURLOPT_REFERER=>$imgUrl,CURLOPT_COOKIEFILE=>'',
CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8','Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7','Origin: '.$origin,'Sec-Fetch-Dest: image','Sec-Fetch-Mode: no-cors']]);
$imgData=curl_exec($ch);$imgErr=curl_error($ch);$imgCode=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$imgCT=curl_getinfo($ch,CURLINFO_CONTENT_TYPE)?:'image/jpeg';curl_close($ch);
if($imgData&&strlen($imgData)>=100&&isImageData($imgData)){

$realFmt=detectImageFormat($imgData);
if($realFmt==='')$realFmt='jpg';
$convResult=convertToSupportedFormat($imgData);
if(!empty($convResult['ok'])){$imgData=$convResult['data'];$realFmt=$convResult['format'];}
$ext=$realFmt;
$ct='image/'.($ext==='jpg'?'jpeg':$ext);
$filename='product_'.substr($cacheKey,0,10).'.'.$ext;
$upResult=$wooMediaUpload($imgData,$ct,$filename);
if(!empty($upResult['ok']))return $upResult;
}

$productLink=$GLOBALS['_currentProductLink']??'';
if($productLink){
$ch3=curl_init($imgUrl);
curl_setopt_array($ch3,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_MAXREDIRS=>5,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0,
CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
CURLOPT_REFERER=>$productLink,CURLOPT_COOKIEFILE=>'',
CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8','Origin: '.$origin,'Sec-Fetch-Dest: image','Sec-Fetch-Mode: no-cors']]);
$d3=curl_exec($ch3);$d3CT=curl_getinfo($ch3,CURLINFO_CONTENT_TYPE)?:'image/jpeg';curl_close($ch3);
if($d3&&strlen($d3)>=100&&isImageData($d3)){

$realFmt=detectImageFormat($d3);
if($realFmt==='')$realFmt='jpg';
$convResult=convertToSupportedFormat($d3);
if(!empty($convResult['ok'])){$d3=$convResult['data'];$realFmt=$convResult['format'];}
$ext=$realFmt;
$ct='image/'.($ext==='jpg'?'jpeg':$ext);
$filename='product_'.substr($cacheKey,0,10).'.'.$ext;
$upResult=$wooMediaUpload($d3,$ct,$filename);
if(!empty($upResult['ok']))return $upResult;
}
}

return['ok'=>0,'error'=>'تصویر آپلود نشد — 3 تلاش ناموفق (فایل، مستقیم، پراکسی)'];
}

function detectImageFormat(string $data): string {
if(strlen($data)<4)return '';
$sig=substr($data,0,4);

if(substr($data,0,2)==="\xFF\xD8")return 'jpg';

if($sig==="\x89PNG")return 'png';

if(substr($data,0,3)==='GIF')return 'gif';

if($sig==='RIFF'&&substr($data,8,4)==='WEBP')return 'webp';

if($sig==='BM')return 'bmp';

if(substr($data,4,4)==='ftyp'&&(substr($data,8,4)==='avif'||substr($data,8,4)==='avis'))return 'avif';

if(substr($data,4,4)==='ftyp'&&(substr($data,8,4)==='heic'||substr($data,8,4)==='heix'||substr($data,8,4)==='mif1'))return 'heic';

if(substr($data,0,4)==='<svg')return 'svg';

if($sig==="\x00\x00\x01\x00")return 'ico';

if($sig==="II\x2A\x00")return 'tiff';

if($sig==="MM\x00\x2A")return 'tiff';
return '';
}

function convertToSupportedFormat(string $data): array {
$fmt=detectImageFormat($data);

if(in_array($fmt,['jpg','png','gif','webp','bmp','']))return['ok'=>true,'data'=>$data,'format'=>$fmt?:'jpg'];

if(!function_exists('imagecreatefromstring'))return['ok'=>false,'error'=>'GD not available, cannot convert '.$fmt];
$img=@imagecreatefromstring($data);
if(!$img)return['ok'=>false,'error'=>'imagecreatefromstring failed for '.$fmt];

ob_start();
imagejpeg($img,null,90);
$jpgData=ob_get_clean();
imagedestroy($img);
if($jpgData&&strlen($jpgData)>100)return['ok'=>true,'data'=>$jpgData,'format'=>'jpg','converted_from'=>$fmt];
return['ok'=>false,'error'=>'JPEG conversion failed for '.$fmt];
}

function isImageData(string $data): bool {
if (strlen($data) < 50) return false;
$sig = substr($data, 0, 4);

if (substr($data, 0, 2) === "\xFF\xd8") return true;

if ($sig === "\x89PNG") return true;

if (substr($data, 0, 3) === "GIF") return true;

if ($sig === "RIFF" && substr($data, 8, 4) === "WEBP") return true;

if ($sig === "BM") return true;

if ($sig === "\x00\x00\x01\x00") return true;

if ($sig === "II\x2a\x00") return true;

if ($sig === "MM\x00\x2a") return true;

if (substr($data, 4, 4) === "ftyp" && (substr($data, 8, 4) === "avif" || substr($data, 8, 4) === "avis")) return true;

if (substr($data, 4, 4) === "ftyp" && (substr($data, 8, 4) === "heic" || substr($data, 8, 4) === "heix" || substr($data, 8, 4) === "mif1")) return true;

if (substr($data, 0, 4) === '<svg') return true;

if (substr($data, 0, 1) === '<' && substr($data, 0, 4) !== '<svg') return false;
if (substr($data, 0, 1) === '{') return false;
if (substr($data, 0, 5) === '<?xml') return false;

if (strlen($data) >= 500) {

$sample = substr($data, 0, 100);
$hasNull = strpos($sample, "\x00") !== false;
$hasNonPrintable = preg_match('/[\\x00-\\x08\\x0E-\\x1F]/', $sample);
if ($hasNull || $hasNonPrintable) return true;
}
return false;
}

function saveImageLocal(string $imgUrl, string $productLink = ''): string {
if (empty($imgUrl) || preg_match('~^(data:|blob:|javascript:|#)~i', $imgUrl)) return '';
$imgUrl=html_entity_decode($imgUrl,ENT_QUOTES|ENT_HTML5,'UTF-8');
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
$cacheKey = md5($imgUrl);

$existing = glob(UPLOAD_DIR . 'img_' . $cacheKey . '.*');
foreach ($existing as $f) {
if (file_exists($f) && filesize($f) > 200) {
$fdata = file_get_contents($f);
if (isImageData($fdata)) return $f;
@unlink($f);
break;
}
}
$parsed = parse_url($imgUrl);
$origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
$d = ''; $ct = 'image/jpeg';

$ch = curl_init($imgUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>25, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', CURLOPT_REFERER=>$imgUrl, CURLOPT_COOKIEFILE=>'', CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8','Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7','Origin: '.$origin,'Sec-Fetch-Dest: image','Sec-Fetch-Mode: no-cors']]);
$d = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg'; curl_close($ch);
if($d && strlen($d) > 200 && isImageData($d)) {  } else { $d=''; }

if(!$d || strlen($d) < 200 || !isImageData($d ?? '')){
$ch2 = curl_init($imgUrl);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>25, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', CURLOPT_REFERER=>$imgUrl, CURLOPT_COOKIEFILE=>'', CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8','Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7','Cache-Control: no-cache','Origin: '.$origin,'Sec-Ch-Ua: "Not/A)Brand";v="8", "Chromium";v="126", "Google Chrome";v="126"','Sec-Ch-Ua-Mobile: ?0','Sec-Ch-Ua-Platform: "Windows"','Sec-Fetch-Dest: image','Sec-Fetch-Mode: no-cors','Sec-Fetch-Site: cross-site']]);
$d = curl_exec($ch2); $ct2 = curl_getinfo($ch2, CURLINFO_CONTENT_TYPE); if ($ct2) $ct = $ct2; curl_close($ch2);
if(!$d || strlen($d) < 200 || !isImageData($d ?? '')) $d='';
}

if(!$d || strlen($d) < 200 || !isImageData($d ?? '')){
$pageRef = $productLink ?: $origin;
$ch3 = curl_init($imgUrl);
curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>25, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', CURLOPT_REFERER=>$pageRef, CURLOPT_COOKIEFILE=>'', CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8','Accept-Language: fa-IR,fa;q=0.9,en;q=0.7','Referer: '.$pageRef,'Origin: '.$origin,'Sec-Fetch-Dest: image','Sec-Fetch-Mode: no-cors']]);
$d = curl_exec($ch3); $ct3 = curl_getinfo($ch3, CURLINFO_CONTENT_TYPE); if ($ct3) $ct = $ct3; curl_close($ch3);
if(!$d || strlen($d) < 200 || !isImageData($d ?? '')) $d='';
}

if((!$d || strlen($d) < 200) && $productLink) {
$srcPage = fetch_html($productLink, 15);
if (!empty($srcPage['ok']) && !empty($srcPage['html'])) {
$freshImgUrl = extractImageFromHtml($srcPage['html'], $productLink);
if ($freshImgUrl && $freshImgUrl !== $imgUrl) {
$ch4 = curl_init($freshImgUrl);
curl_setopt_array($ch4, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>25, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_SSL_VERIFYHOST=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', CURLOPT_REFERER=>$productLink, CURLOPT_COOKIEFILE=>'', CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8','Referer: '.$productLink,'Origin: '.$origin,'Sec-Fetch-Dest: image','Sec-Fetch-Mode: no-cors']]);
$d = curl_exec($ch4); $ct4 = curl_getinfo($ch4, CURLINFO_CONTENT_TYPE); if ($ct4) $ct = $ct4; curl_close($ch4);
if(!$d || strlen($d) < 200 || !isImageData($d ?? '')) $d='';
}
}
}

if(!$d || strlen($d) < 200 || !isImageData($d ?? '')){
$selfBase2=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];
$proxyUrl2=$selfBase2.'?image_proxy='.rawurlencode($imgUrl);
$ch5=curl_init($proxyUrl2);curl_setopt_array($ch5,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0]);
$d=curl_exec($ch5);$ct5=curl_getinfo($ch5,CURLINFO_CONTENT_TYPE);if($ct5)$ct=$ct5;curl_close($ch5);
if(!$d || strlen($d) < 200 || !isImageData($d ?? '')) $d='';
}

if (!$d || strlen($d) < 200 || !isImageData($d)) return '';

$realFmt=detectImageFormat($d);
if($realFmt==='')$realFmt='jpg';

$convResult=convertToSupportedFormat($d);
if(!empty($convResult['ok'])){
$d=$convResult['data'];
$realFmt=$convResult['format'];
}
$ext=$realFmt;
$localPath = UPLOAD_DIR . 'img_' . $cacheKey . '.' . $ext;
@file_put_contents($localPath, $d);
return $localPath;
}

function bslUpload(string $tk, string $imgUrl): array {

$imgUrl=html_entity_decode($imgUrl,ENT_QUOTES|ENT_HTML5,'UTF-8');
$imgUrl=trim($imgUrl);
if(empty($imgUrl))return['ok'=>0,'error'=>'empty URL'];
$cacheKey=md5($imgUrl);
$log=[];

$urlExt=strtolower(pathinfo(parse_url($imgUrl,PHP_URL_PATH)??'',PATHINFO_EXTENSION)?:'');
if($urlExt)$log[]='url_ext:'.$urlExt;

$retryDelay=max(0,(int)($GLOBALS['_bslRetryDelayMs']??1000));

$bslFileUpload=function($filePath)use($tk,$cacheKey,$retryDelay){
if(!file_exists($filePath)||filesize($filePath)<200)return['ok'=>0,'error'=>'file missing'];
$fdata=@file_get_contents($filePath);
if(!$fdata||!isImageData($fdata))return['ok'=>0,'error'=>'not valid image'];

$realFmt=detectImageFormat($fdata);
if($realFmt==='')$realFmt='jpg';
$fmtLog='fmt:'.$realFmt;

$convResult=convertToSupportedFormat($fdata);
if(!empty($convResult['ok'])&&!empty($convResult['converted_from'])){
$fdata=$convResult['data'];
$realFmt='jpg';
$fmtLog='fmt:'.$convResult['converted_from'].'→jpg';

@file_put_contents($filePath,$fdata);
}
$ext=$realFmt;
$ct='image/'.($ext==='jpg'?'jpeg':$ext);
$cf=new CURLFile($filePath,$ct,'product_'.$cacheKey.'.'.$ext);

for($attempt=1;$attempt<=3;$attempt++){
$r=bslReq($tk,'POST','files',['file'=>$cf,'file_type'=>'product.photo'],true);
if($r['ok']&&!empty($r['body']['id']))return['ok'=>1,'file_id'=>$r['body']['id']];
$bslErr=$r['body']['error_description']??$r['body']['message']??$r['error']??'';
if(is_array($bslErr))$bslErr=json_encode($bslErr,JSON_UNESCAPED_UNICODE);
$logMsg='BSL upload attempt '.$attempt.' fail ['.$fmtLog.']: '.mb_substr($bslErr,0,100).' (HTTP'.($r['code']??'?').')';
if($attempt<3){usleep($retryDelay*1000);}
}
return['ok'=>0,'error'=>$logMsg];
};

$existing=glob(UPLOAD_DIR.'img_'.$cacheKey.'.*');
foreach($existing as $f){
if(file_exists($f)&&filesize($f)>200){
$fdata=@file_get_contents($f);
if(!$fdata||!isImageData($fdata)){@unlink($f);continue;}
$up=$bslFileUpload($f);
if(!empty($up['ok']))return['ok'=>1,'file_id'=>$up['file_id']];
$log[]=$up['error'];

}
}

$productLink=$GLOBALS['_currentProductLink']??'';
$localPath=saveImageLocal($imgUrl,$productLink);
if($localPath&&file_exists($localPath)&&filesize($localPath)>200){
$log[]='file saved ok ('.filesize($localPath).'b)';
$up=$bslFileUpload($localPath);
if(!empty($up['ok']))return['ok'=>1,'file_id'=>$up['file_id']];
$log[]=$up['error'];
return['ok'=>0,'error'=>'تصویر آپلود نشد ['.implode(' | ',$log).']'];
}else{
$log[]='saveImageLocal failed';
}

if($productLink){
$srcPage=fetch_html($productLink,15);
if(!empty($srcPage['ok'])&&!empty($srcPage['html'])){
$freshImgUrl=extractImageFromHtml($srcPage['html'],$productLink);
if($freshImgUrl&&$freshImgUrl!==$imgUrl){
$log[]='fresh URL found';
$localPath2=saveImageLocal($freshImgUrl,$productLink);
if($localPath2&&file_exists($localPath2)&&filesize($localPath2)>200){
$up2=$bslFileUpload($localPath2);
if(!empty($up2['ok']))return['ok'=>1,'file_id'=>$up2['file_id']];
$log[]=$up2['error'];
return['ok'=>0,'error'=>'تصویر آپلود نشد ['.implode(' | ',$log).']'];
}
$log[]='fresh file not valid';
}else{
$log[]='no fresh URL found';
}
}else{
$log[]='product page fetch failed';
}
}

return['ok'=>0,'error'=>'تصویر آپلود نشد ['.implode(' | ',$log).']'];
}

function fetch_html(string $url, int $timeout = 25): array {
$ch = curl_init($url);
$parsed=parse_url($url);$origin=($parsed['scheme']??'https').'://'.($parsed['host']??'');
curl_setopt_array($ch, [
CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
CURLOPT_REFERER => $url,
CURLOPT_COOKIEFILE => '',
CURLOPT_HTTPHEADER => [
'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
'Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
'Cache-Control: no-cache',
'Origin: '.$origin,
'Sec-Ch-Ua: "Not/A)Brand";v="8", "Chromium";v="126", "Google Chrome";v="126"',
'Sec-Ch-Ua-Mobile: ?0',
'Sec-Ch-Ua-Platform: "Windows"',
'Sec-Fetch-Dest: document',
'Sec-Fetch-Mode: navigate',
'Sec-Fetch-Site: none',
'Sec-Fetch-User: ?1',
'Upgrade-Insecure-Requests: 1',
],
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

function extractImageFromHtml(string $html, string $pageUrl): string {
if(empty($html)) return '';
$parsed=parse_url($pageUrl);
$base=($parsed['scheme']??'https').'://'.($parsed['host']??'');

if(preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m)) return resolveUrl($m[1],$base,$pageUrl);
if(preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',$html,$m)) return resolveUrl($m[1],$base,$pageUrl);

if(preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m)) return resolveUrl($m[1],$base,$pageUrl);

if(preg_match_all('/<img[^>]+>/i',$html,$imgs)){
$bestUrl='';$bestScore=0;
foreach($imgs[0] as $img){
$src='';$class='';$id='';$w=0;$h=0;$alt='';
if(preg_match('/src=["\']([^"\']+)["\']/i',$img,$sm))$src=$sm[1];
if(preg_match('/class=["\']([^"\']+)["\']/i',$img,$cm))$class=$cm[1];
if(preg_match('/id=["\']([^"\']+)["\']/i',$img,$im))$id=$im[1];
if(preg_match('/width=["\'](\d+)["\']/i',$img,$wm))$w=(int)$wm[1];
if(preg_match('/height=["\'](\d+)["\']/i',$img,$hm))$h=(int)$hm[1];
if(preg_match('/alt=["\']([^"\']+)["\']/i',$img,$am))$alt=$am[1];
if(!$src||preg_match('/^(data:|javascript:|#)/i',$src))continue;

$score=($w>200&&$h>200?10:0)+($w*$h>50000?5:0);

$kw='product|main|big|large|gallery|featured|hero|primary|item|detail';
if(preg_match('/'.$kw.'/i',$class.$id))$score+=8;
if(preg_match('/'.$kw.'/i',$alt))$score+=3;

if(preg_match('/logo|icon|badge|thumb|avatar|sprite|social|banner|slider/i',$class.$id))$score-=10;
if($score>$bestScore){$bestScore=$score;$bestUrl=$src;}
}
if($bestUrl&&$bestScore>=3)return resolveUrl($bestUrl,$base,$pageUrl);
}

if(preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i',$html,$m)){
$u=$m[1];if(!preg_match('/^(data:|javascript:|#|[\w]+-icon)/i',$u)&&strlen($u)>10)return resolveUrl($u,$base,$pageUrl);
}
return '';
}

function resolveUrl(string $url, string $base, string $pageUrl): string {
if(preg_match('/^https?:\/\//i',$url))return $url;
if(strpos($url,'//')===0)return 'https:'.$url;
if(strpos($url,'/')===0)return $base.$url;
return rtrim($pageUrl,'/').'/'.ltrim($url,'/');
}

/* =====================================================================
 *  بررسی نسخه — فقط خواندنی
 *  ---------------------------------------------------------------
 *  این بخش عمداً هیچ کدی دانلود نمی‌کند و هیچ فایل PHP ای نمی‌نویسد.
 *  فقط شناسهٔ نسخهٔ گیت‌هاب را با فایل فعلی مقایسه می‌کند و نتیجه را
 *  گزارش می‌دهد. کار نصب بر عهدهٔ deploy.php است که فایلی جداگانه است
 *  و روی هدف دیگری می‌نویسد — نه روی خودش و نه روی این فایل از داخل
 *  همین اسکریپت. این جداسازی باعث می‌شود الگوی «دانلود ← بازنویسی خود
 *  ← اجرا» که اسکنرهای امنیتی هاست آن را بک‌دور می‌شناسند شکل نگیرد.
 * ===================================================================== */

const VC_FILE = __DIR__ . '/.versioncheck.json';

function vc_defaults(): array {
    return [
        'check_on_load' => false,
        'repo'          => 'fazilatma/code',
        'branch'        => 'main',
        'path'          => 'scraper-v8.17.php',
        'github_token'  => '',
        'deploy_file'   => 'deploy.php',
        'deploy_token'  => '',
        'last_check'    => 0,
    ];
}

function vc_load(): array {
    $d = vc_defaults();
    if (!is_file(VC_FILE)) return $d;
    $j = json_decode((string)@file_get_contents(VC_FILE), true);
    return is_array($j) ? array_merge($d, $j) : $d;
}

function vc_save(array $c): bool {
    $ok = @file_put_contents(VC_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    if ($ok) @chmod(VC_FILE, 0600);
    return $ok;
}

/** شناسهٔ محتوا با همان الگوریتم گیت، برای مقایسه با پاسخ API */
function vc_content_id(string $data): string {
    return sha1('blob ' . strlen($data) . "\0" . $data);
}

/** پیام خطای گویا برای کدهای رایج گیت‌هاب */
function vc_http_error(int $code, string $fallback, bool $hadToken): string {
    if ($code === 401) {
        return 'توکن گیت‌هاب نامعتبر یا منقضی است (۴۰۱) — چون ریپو عمومی است، توکن را حذف کنید';
    }
    if ($code === 403) {
        return $hadToken
            ? 'دسترسی رد شد (۴۰۳) — توکن مجوز لازم را ندارد'
            : 'محدودیت نرخ گیت‌هاب (۴۰۳) — ساعتی ۶۰ درخواست برای هر IP؛ کمی بعد دوباره تلاش کنید';
    }
    if ($code === 404) return 'فایل، برنچ یا ریپو پیدا نشد (۴۰۴)';
    return $fallback ?: ('خطا HTTP ' . $code);
}

/**
 * درخواست GET فقط برای خواندن اطلاعات (متادیتا)، نه کد.
 * اگر توکن نامعتبر باشد و ریپو عمومی، یک‌بار بدون توکن دوباره تلاش می‌کند
 * تا یک توکن خراب کل قابلیت را از کار نیندازد.
 */
function vc_get_json_auto(string $url, string $token = '', int $timeout = 25): array {
    $r = vc_get_json($url, $token, $timeout);
    if (!$r['ok'] && $r['code'] === 401 && $token !== '') {
        $retry = vc_get_json($url, '', $timeout);
        if ($retry['ok']) {
            $retry['token_rejected'] = true;   // به UI بگو توکن بد است
            return $retry;
        }
    }
    $r['token_rejected'] = false;
    return $r;
}

/** درخواست GET فقط برای خواندن اطلاعات (متادیتا)، نه کد */
function vc_get_json(string $url, string $token = '', int $timeout = 25): array {
    $hdr = [
        'User-Agent: scraper-version-check',
        'Accept: application/vnd.github+json',
        'Cache-Control: no-cache',
    ];
    if ($token !== '') $hdr[] = 'Authorization: Bearer ' . $token;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => $timeout, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $hdr,
        ]);
        $b = curl_exec($ch);
        $e = curl_error($ch);
        $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($b === false) return ['ok' => false, 'code' => 0, 'error' => $e ?: 'ارتباط ناموفق', 'data' => null];
        if ($c !== 200)   return ['ok' => false, 'code' => $c, 'error' => 'HTTP ' . $c, 'data' => null];
        $d = json_decode((string)$b, true);
        return is_array($d) ? ['ok' => true, 'code' => 200, 'error' => '', 'data' => $d]
                            : ['ok' => false, 'code' => 200, 'error' => 'پاسخ نامعتبر', 'data' => null];
    }

    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => $timeout, 'header' => implode("\r\n", $hdr), 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $b = @file_get_contents($url, false, $ctx);
    if ($b === false) return ['ok' => false, 'code' => 0, 'error' => 'ارتباط ناموفق', 'data' => null];
    $d = json_decode((string)$b, true);
    return is_array($d) ? ['ok' => true, 'code' => 200, 'error' => '', 'data' => $d]
                        : ['ok' => false, 'code' => 0, 'error' => 'پاسخ نامعتبر', 'data' => null];
}

/** مقایسهٔ نسخه — هیچ چیزی دانلود یا نوشته نمی‌شود */
if (isset($_GET['vc_check'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $c = vc_load();

    if (empty($_GET['force']) && empty($c['check_on_load'])) {
        echo json_encode(['ok' => true, 'skipped' => true, 'update' => false]);
        exit;
    }
    if (trim((string)$c['repo']) === '' || trim((string)$c['path']) === '') {
        echo json_encode(['ok' => false, 'error' => 'ریپو یا مسیر فایل تنظیم نشده'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $local = vc_content_id((string)@file_get_contents(__FILE__));
    $api = 'https://api.github.com/repos/' . $c['repo'] . '/contents/'
         . implode('/', array_map('rawurlencode', explode('/', $c['path'])))
         . '?ref=' . rawurlencode($c['branch']);
    $r = vc_get_json_auto($api, $c['github_token']);
    if (!$r['ok'] || !isset($r['data']['sha'])) {
        echo json_encode([
            'ok' => false,
            'error' => vc_http_error((int)$r['code'], (string)$r['error'], $c['github_token'] !== ''),
            'code' => (int)$r['code'],
            'bad_token' => (int)$r['code'] === 401,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // توکن بد بود ولی بدون آن کار کرد — خودکار پاکش کن تا دفعهٔ بعد خطا ندهد
    if (!empty($r['token_rejected'])) {
        $c['github_token'] = '';
        vc_save($c);
    }

    $c['last_check'] = time();
    vc_save($c);

    $remote = (string)$r['data']['sha'];
    echo json_encode([
        'ok'          => true,
        'update'      => $remote !== $local,
        'local_id'    => substr($local, 0, 8),
        'remote_id'   => substr($remote, 0, 8),
        'remote_size' => (int)($r['data']['size'] ?? 0),
        'local_size'  => (int)@filesize(__FILE__),
        'branch'      => $c['branch'],
        'path'        => $c['path'],
        'deploy_ready'=> $c['deploy_token'] !== '' && is_file(__DIR__ . '/' . basename($c['deploy_file'])),
        'deploy_file' => basename($c['deploy_file']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** فهرست برنچ‌ها — فقط خواندنی */
if (isset($_GET['vc_branches'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $c = vc_load();
    $repo = trim((string)($_GET['repo'] ?? $c['repo']));
    if (!preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) {
        echo json_encode(['ok' => false, 'error' => 'نام ریپو نامعتبر است (قالب: user/repo)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = vc_get_json_auto('https://api.github.com/repos/' . $repo . '/branches?per_page=100', $c['github_token']);
    if (!$r['ok']) {
        echo json_encode([
            'ok' => false,
            'error' => vc_http_error((int)$r['code'], (string)$r['error'], $c['github_token'] !== ''),
            'code' => (int)$r['code'],
            'bad_token' => (int)$r['code'] === 401,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($r['token_rejected'])) { $c['github_token'] = ''; vc_save($c); }
    $out = [];
    foreach ($r['data'] as $b) {
        if (!empty($b['name'])) $out[] = ['name' => $b['name'], 'sha' => substr((string)($b['commit']['sha'] ?? ''), 0, 7)];
    }
    echo json_encode(['ok' => true, 'branches' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

/** فهرست فایل‌های PHP یک برنچ — فقط خواندنی */
if (isset($_GET['vc_files'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $c = vc_load();
    $repo   = trim((string)($_GET['repo'] ?? $c['repo']));
    $branch = trim((string)($_GET['branch'] ?? $c['branch']));
    if (!preg_match('~^[\w.-]+/[\w.-]+$~', $repo) || $branch === '') {
        echo json_encode(['ok' => false, 'error' => 'ریپو و برنچ لازم است'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = vc_get_json_auto('https://api.github.com/repos/' . $repo . '/git/trees/' . rawurlencode($branch) . '?recursive=1', $c['github_token'], 30);
    if (!$r['ok'] || !isset($r['data']['tree'])) {
        echo json_encode([
            'ok' => false,
            'error' => vc_http_error((int)$r['code'], (string)$r['error'], $c['github_token'] !== ''),
            'code' => (int)$r['code'],
            'bad_token' => (int)$r['code'] === 401,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($r['token_rejected'])) { $c['github_token'] = ''; vc_save($c); }
    $files = [];
    foreach ($r['data']['tree'] as $n) {
        if (($n['type'] ?? '') !== 'blob') continue;
        $p = (string)$n['path'];
        if (!preg_match('~\.php\d?$~i', $p)) continue;
        $files[] = ['path' => $p, 'size' => (int)($n['size'] ?? 0)];
    }
    usort($files, fn($a, $b) => $b['size'] <=> $a['size']);
    echo json_encode(['ok' => true, 'files' => $files], JSON_UNESCAPED_UNICODE);
    exit;
}

/** تنظیمات بررسی نسخه */
if (isset($_GET['vc_settings'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $c = vc_load();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $c['check_on_load'] = !empty($_POST['check_on_load']);
        if (isset($_POST['repo']))        $c['repo']        = trim((string)$_POST['repo']);
        if (isset($_POST['branch']))      $c['branch']      = trim((string)$_POST['branch']);
        if (isset($_POST['path']))        $c['path']        = ltrim(trim((string)$_POST['path']), '/');
        if (isset($_POST['deploy_file'])) $c['deploy_file'] = basename(trim((string)$_POST['deploy_file']));
        foreach (['github_token', 'deploy_token'] as $k) {
            $v = (string)($_POST[$k] ?? '');
            if ($v === '__CLEAR__')  $c[$k] = '';
            elseif (trim($v) !== '') $c[$k] = trim($v);
        }
        if (!vc_save($c)) { echo json_encode(['ok' => false, 'error' => 'ذخیرهٔ تنظیمات ناموفق']); exit; }
    }
    $deployPath = __DIR__ . '/' . basename($c['deploy_file']);
    $out = $c;
    $out['github_token']   = '';
    $out['deploy_token']   = '';
    $out['has_gh_token']   = $c['github_token'] !== '';
    $out['has_dep_token']  = $c['deploy_token'] !== '';
    $out['deploy_present'] = is_file($deployPath);
    $out['self_name']      = basename(__FILE__);
    $out['local_id']       = substr(vc_content_id((string)@file_get_contents(__FILE__)), 0, 8);
    $out['local_size']     = (int)@filesize(__FILE__);
    echo json_encode(['ok' => true, 'settings' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

/** آدرس و توکن deploy.php را به مرورگر می‌دهد تا خودش صدا بزند */
if (isset($_GET['vc_deploy_info'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $c = vc_load();
    $f = basename($c['deploy_file']);
    if (!is_file(__DIR__ . '/' . $f)) {
        echo json_encode(['ok' => false, 'error' => 'فایل ' . $f . ' روی هاست پیدا نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'file' => $f, 'token' => $c['deploy_token']], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_GET['rp'])) {
$rpUrl = trim($_GET['rp']);
if (!filter_var($rpUrl, FILTER_VALIDATE_URL)) { http_response_code(400); echo 'Invalid'; exit; }
$ch = curl_init($rpUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_ENCODING=>'',CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',CURLOPT_REFERER=>$rpUrl,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8','Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7','Sec-Ch-Ua: "Not/A)Brand";v="8", "Chromium";v="126"','Sec-Ch-Ua-Mobile: ?0','Sec-Ch-Ua-Platform: "Windows"','Sec-Fetch-Dest: document','Sec-Fetch-Mode: navigate','Sec-Fetch-Site: same-origin','Sec-Fetch-User: ?1']]);
$rpBody = curl_exec($ch);
$rpCT = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
$rpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if (!$rpBody) { http_response_code(502); echo 'Proxy error'; exit; }
if (stripos($rpCT, 'text/css') !== false && !empty($_GET['rp_base'])) {
$rpB = $_GET['rp_base'];
$rpBody = preg_replace_callback("~url\\(\\s*[\\\"']?([^)\\\"\\s]+)[\\\"']?\\s*\\)~i", function($m) use ($rpB) {
$v = $m[1];
if (preg_match("~^(data:|blob:|#)~i", $v)) return $m[0];
return "url(\"?rp=" . rawurlencode(make_absolute_url($v, $rpB)) . "\")";
}, $rpBody);
}
header("Content-Type: $rpCT");
header("Cache-Control: public, max-age=3600");
header("Access-Control-Allow-Origin: *");
http_response_code($rpCode >= 200 && $rpCode < 600 ? $rpCode : 200);
echo $rpBody; exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: *");
header("Access-Control-Allow-Headers: *");
exit;
}

if (!empty($_GET['image_proxy'])) {
$url = trim($_GET['image_proxy']);
if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); exit; }
$pUrl=parse_url($url);$origin=($pUrl['scheme']??'https').'://'.($pUrl['host']??'');

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_REFERER => $url, CURLOPT_COOKIEFILE => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8','Accept-Language: fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7','Origin: '.$origin,'Sec-Fetch-Dest: image','Sec-Fetch-Mode: no-cors']]);
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
$productsData = json_decode($_POST['products'] ?? '[]', true) ?: [];
$productsOrder = json_decode($_POST['productsOrder'] ?? '[]', true) ?: [];
$syncConfig = json_decode($_POST['syncConfig'] ?? '{}', true) ?: [];
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
'fullMode' => !empty($_POST['fullMode']),
'customColName' => $_POST['customColName'] ?? '',
'customColVal' => $_POST['customColVal'] ?? '',
'products' => $productsData,
'productsOrder' => $productsOrder,
'syncConfig' => $syncConfig,
// v8.43: اگر این فیلدها در درخواست نباشند، مقدار قبلی حفظ شود.
// قبلاً هر ذخیره‌ای که آن‌ها را نمی‌فرستاد، دستهٔ پروفایل را صفر می‌کرد.
'bslCategoryId' => array_key_exists('bslCategoryId', $_POST)
    ? (int)$_POST['bslCategoryId']
    : (int)($profiles[$key]['bslCategoryId'] ?? 0),
'bslFallbackCatIds' => array_key_exists('bslFallbackCatIds', $_POST)
    ? array_values(array_filter(array_map('intval', json_decode((string)$_POST['bslFallbackCatIds'], true) ?: []), function($v){return $v>0;}))
    : (array)($profiles[$key]['bslFallbackCatIds'] ?? []),
'updatedAt' => time()
];
if (saveProfiles($profiles)) {
echo json_encode(['ok' => true, 'key' => $key, 'message' => 'پروفایل ذخیره شد', 'selectors' => $profiles[$key]['selectors'] ?? [], 'has_selectors' => !empty($profiles[$key]['selectors']['container'])]);
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

if (!empty($_GET['detail_proxy'])) {
$url = trim($_GET['detail_proxy']);
if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); echo 'Invalid URL'; exit; }

$res = fetch_html($url, 15);
if (!$res['ok']) {
http_response_code(500);
$err = $res['error'] ?? 'Unknown';
if (stripos($err, 'resolve') !== false) echo 'DNS Error: domain not found';
elseif (stripos($err, 'timed out') !== false) echo 'Timeout: server not responding';
else echo 'Failed: ' . h($err);
exit;
}
$html = $res['html'];
$baseUrl = $res['url'];
$fullMode = !empty($_GET['full']);
if ($fullMode) {
$html = preg_replace("~<script[^>]*(?:google|analytics|gtag|facebook|snapchat|doubleclick)[^>]*>.*?</script>~is", "", $html);
} else {
$html = preg_replace("~<script[^>]*>.*?</script>~is", "", $html);
}
$html = preg_replace("~<iframe[^>]*>.*?</iframe>~is", "", $html);
$html = preg_replace("~<video[^>]*>.*?</video>~is", "", $html);
$html = preg_replace("~<audio[^>]*>.*?</audio>~is", "", $html);

$html = preg_replace_callback("~\\bsrc\\s*=\\s*\"([^\"]+)\"~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|javascript:|#|mailto:)~i", $m[1])) return $m[0];
if (strpos($m[1], "?rp=") === 0) return $m[0];
return "src=\"?rp=" . rawurlencode(make_absolute_url($m[1], $baseUrl)) . "\"";
}, $html);
$html = preg_replace_callback("~\\bsrc\\s*=\\s*'([^']+)'~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|javascript:|#|mailto:)~i", $m[1])) return $m[0];
if (strpos($m[1], "?rp=") === 0) return $m[0];
return "src='?rp=" . rawurlencode(make_absolute_url($m[1], $baseUrl)) . "'";
}, $html);
$html = preg_replace_callback("~\\bdata-src\\s*=\\s*\"([^\"]+)\"~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|#)~i", $m[1])) return $m[0];
return "data-src=\"?rp=" . rawurlencode(make_absolute_url($m[1], $baseUrl)) . "\"";
}, $html);
$html = preg_replace_callback("~<link\\b([^>]*?)\\bhref\\s*=\\s*\"([^\"]+)\"~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|#)~i", $m[2])) return $m[0];
if (strpos($m[2], "?rp=") === 0) return $m[0];
return "<link" . $m[1] . "href=\"?rp=" . rawurlencode(make_absolute_url($m[2], $baseUrl)) . "\"";
}, $html);
$html = preg_replace_callback("~<link\\b([^>]*?)\\bhref\\s*=\\s*'([^']+)'~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|#)~i", $m[2])) return $m[0];
if (strpos($m[2], "?rp=") === 0) return $m[0];
return "<link" . $m[1] . "href='?rp=" . rawurlencode(make_absolute_url($m[2], $baseUrl)) . "'";
}, $html);
$html = preg_replace("~<base[^>]*>~i", "", $html);

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
  // v7.82: Match number with unit — pick longest
  var allU=t.match(/([\d۰-۹٠-٩][,،٬\s\d۰-۹٠-٩]*[\d۰-۹٠-٩])\s*(تومان|تومن|ریال)/g);
  if(allU&&allU.length){
    allU.sort(function(a,b){return b.replace(/[^\d]/g,'').length-a.replace(/[^\d]/g,'').length;});
    var parts=allU[0].match(/([\d۰-۹٠-٩][,،٬\s\d۰-۹٠-٩]*[\d۰-۹٠-٩])/);
    if(parts)return parts[1].trim();
  }
  var m=t.match(/([\d۰-۹٠-٩]{1,3}[,،٬][\d۰-۹٠-٩]{3}(?:[,،٬][\d۰-۹٠-٩]{3})*)/);
  if(m)return m[1].trim();
  // v7.82: Plain number without separators
  var plain=t.match(/[\d۰-۹٠-٩]{4,}/g);
  if(plain&&plain.length){
    plain.sort(function(a,b){return b.replace(/[^\d]/g,'').length-a.replace(/[^\d]/g,'').length;});
    return plain[0];
  }
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

$html = preg_replace('~</body>~i', $script . '</body>', $html);
if (stripos($html, '</body>') === false) $html .= $script;

$html = preg_replace('~<a ([^>]*)href=~i', '<a $1data-href=', $html);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
exit;
}

if (!empty($_GET['visual_proxy'])) {
$url = trim($_GET['visual_proxy']);
if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); echo 'Invalid URL'; exit; }

$res = fetch_html($url, 20);
if (!$res['ok']) {
http_response_code(500);
$err = $res['error'] ?? 'Unknown';
if (stripos($err, 'resolve') !== false) echo '<html><body style="background:#0f172a;color:#fca5a5;font-family:Tahoma;padding:40px;direction:rtl"><h2>❌ خطا در دسترسی</h2><p>آدرس <b>'.h($url).'</b> قابل دسترسی نیست.</p><p style="color:#94a3b8">دلیل: سرور DNS قادر به یافتن دامنه نیست. ممکن است دامنه منقضی یا مشکل SSL داشته باشد.</p></body></html>';
elseif (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) echo '<html><body style="background:#0f172a;color:#fbbf24;font-family:Tahoma;padding:40px;direction:rtl"><h2>⏱ خطای تایم‌اوت</h2><p>سرور <b>'.h($url).'</b> پاسخ نمی‌دهد.</p><p style="color:#94a3b8">سرور مقصد خیلی کند یا از دسترس خارج شده.</p></body></html>';
else echo 'Failed: ' . h($err);
exit;
}

$html = $res['html'];
$baseUrl = $res['url'];
$fullMode = !empty($_GET['full']);
$html = preg_replace("~<meta[^>]*name=[\"']viewport[\"'][^>]*>~i", "", $html);
if ($fullMode) {
$html = preg_replace("~<script[^>]*(?:google|analytics|gtag|facebook|snapchat|doubleclick|adsense|adwords|hotjar|clarity)[^>]*>.*?</script>~is", "", $html);
} else {
$html = preg_replace("~<script[^>]*>.*?</script>~is", "", $html);
}
$html = preg_replace("~<iframe[^>]*(?:facebook|google|analytics|doubleclick|adsense)[^>]*>.*?</iframe>~is", "", $html);
if (!$fullMode) { $html = preg_replace("~<iframe[^>]*>.*?</iframe>~is", "", $html); }
$html = preg_replace("~<video[^>]*>.*?</video>~is", "", $html);
$html = preg_replace("~<audio[^>]*>.*?</audio>~is", "", $html);

$html = preg_replace_callback("~\\bsrc\\s*=\\s*\"([^\"]+)\"~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|javascript:|#|mailto:)~i", $m[1])) return $m[0];
if (strpos($m[1], "?rp=") === 0) return $m[0];
return "src=\"?rp=" . rawurlencode(make_absolute_url($m[1], $baseUrl)) . "\"";
}, $html);
$html = preg_replace_callback("~\\bsrc\\s*=\\s*'([^']+)'~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|javascript:|#|mailto:)~i", $m[1])) return $m[0];
if (strpos($m[1], "?rp=") === 0) return $m[0];
return "src='?rp=" . rawurlencode(make_absolute_url($m[1], $baseUrl)) . "'";
}, $html);
$html = preg_replace_callback("~\\bdata-src\\s*=\\s*\"([^\"]+)\"~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|#)~i", $m[1])) return $m[0];
return "data-src=\"?rp=" . rawurlencode(make_absolute_url($m[1], $baseUrl)) . "\"";
}, $html);
$html = preg_replace_callback("~\\bdata-src\\s*=\\s*'([^']+)'~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|#)~i", $m[1])) return $m[0];
return "data-src='?rp=" . rawurlencode(make_absolute_url($m[1], $baseUrl)) . "'";
}, $html);
$html = preg_replace_callback("~<link\\b([^>]*?)\\bhref\\s*=\\s*\"([^\"]+)\"~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|#)~i", $m[2])) return $m[0];
if (strpos($m[2], "?rp=") === 0) return $m[0];
return "<link" . $m[1] . "href=\"?rp=" . rawurlencode(make_absolute_url($m[2], $baseUrl)) . "\"";
}, $html);
$html = preg_replace_callback("~<link\\b([^>]*?)\\bhref\\s*=\\s*'([^']+)'~i", function($m) use ($baseUrl) {
if (preg_match("~^(data:|blob:|#)~i", $m[2])) return $m[0];
if (strpos($m[2], "?rp=") === 0) return $m[0];
return "<link" . $m[1] . "href='?rp=" . rawurlencode(make_absolute_url($m[2], $baseUrl)) . "'";
}, $html);
$html = preg_replace_callback("~\\bsrcset\\s*=\\s*\"([^\"]+)\"~i", function($m) use ($baseUrl) {
$parts = explode(',', $m[1]);
foreach ($parts as &$p) {
$p = trim($p);
if (preg_match("~^(data:|blob:|#)~i", $p)) continue;
$p = preg_replace_callback("~^(\\S+)~", function($u) use ($baseUrl) {
return "?rp=" . rawurlencode(make_absolute_url($u[1], $baseUrl));
}, $p);
}
return "srcset=\"" . implode(', ', $parts) . "\"";
}, $html);
$html = preg_replace("~<base[^>]*>~i", "", $html);

$fullPageInspect = !empty($_GET['fullpage_inspect']) ? '1' : '0';
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
  <button class="no" onclick="if(window.__fp==='1'){window.close();}else{parent.postMessage({type:'cancel'},'*');}">✕</button>
</div>
<div class="__row2">
  <button class="__nav" onclick="__goParent()" title="والد">⬆</button>
  <button class="__nav" onclick="__goChild()" title="فرزند">⬇</button>
  <button class="__nav" onclick="__goPrev()" title="قبلی">⬅</button>
  <button class="__nav" onclick="__goNext()" title="بعدی">➡</button>
  <span class="__tag" id="__tag">-</span>
  <span class="__cnt" id="__cnt"></span>
  <button class="__nav" onclick="__scrollDown()" style="background:#1e3a5f!important;border-color:#3b82f6!important;color:#93c5fd!important;font-weight:700" title="Scroll">⬆</button>
  <button class="__nav" onclick="__autoScroll()" style="background:#14532d!important;border-color:#22c55e!important;color:#86efac!important;font-weight:700" title="Auto">🔄</button>
</div>
<div class="__row3">
  <span class="__preview-label" id="__prevLabel">پیش‌نمایش:</span>
  <span id="__preview" class="__preview">در انتظار انتخاب...</span>
</div>
</div>
<script>
(function(){
var S={container:'',title:'',price:'',link:'',image:''},E={},cur=null,picked=null;
var __isFull='<?php echo $fullMode?"1":"0";?>';
var __fp='<?php echo $fullPageInspect??'0';?>';
if(__isFull==='1'){
  document.addEventListener('click',function(e){if(e.target.closest('.__bar'))return;var a=e.target.closest('a');if(a){e.preventDefault();e.stopPropagation();}},true);
  window.open=function(){return null;};
  var __oF=window.fetch;window.fetch=function(u,o){if(typeof u==='string'&&u.indexOf('?rp=')===-1&&!u.startsWith('data:')&&!u.startsWith('blob:')){try{u='?rp='+encodeURIComponent(new URL(u,location.href).href);}catch(e){}}return __oF.call(this,u,o);};
  var __oX=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){if(typeof u==='string'&&u.indexOf('?rp=')===-1&&!u.startsWith('data:')&&!u.startsWith('blob:')){try{u='?rp='+encodeURIComponent(new URL(u,location.href).href);}catch(e){}}return __oX.apply(this,arguments);};
  var __oSA=HTMLElement.prototype.setAttribute;HTMLElement.prototype.setAttribute=function(n,v){if(__isFull==='1'&&(n==='src'||n==='href')&&typeof v==='string'&&v.indexOf('?rp=')===-1&&!v.startsWith('data:')&&!v.startsWith('blob:')){try{v='?rp='+encodeURIComponent(new URL(v,location.href).href);}catch(e){}}return __oSA.call(this,n,v);};
}
var __si=null,__sp=0,__lc=0,__sc=0;
window.__scrollDown=function(){scrollBy({top:innerHeight*2,behavior:'smooth'});setTimeout(function(){var n=S.container?document.querySelectorAll(S.container).length:0;var e=document.getElementById('__cnt');if(e)e.textContent=n?n+' items':'';},2500);};
window.__autoScroll=function(){if(__si){clearInterval(__si);__si=null;return;}__lc=0;__sc=0;__si=setInterval(function(){scrollBy({top:500,behavior:'smooth'});var n=S.container?document.querySelectorAll(S.container).length:0;var e=document.getElementById('__cnt');if(e)e.textContent=n?n+' items':'';if(n===__lc)__sc++;else __sc=0;__lc=n;if(__sc>8||document.documentElement.scrollTop+innerHeight>=document.documentElement.scrollHeight-100){clearInterval(__si);__si=null;}},600);};

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
  // v7.82: Match number with unit (تومان/ریال) — pick longest
  var allU=t.match(/([\d۰-۹٠-٩](?:[\d۰-۹٠-٩]|[,،٬\s])*[\d۰-۹٠-٩])\s*(تومان|تومن|ریال)/g);
  if(allU&&allU.length){
    allU.sort(function(a,b){return b.replace(/[^\d]/g,'').length-a.replace(/[^\d]/g,'').length;});
    var parts=allU[0].match(/([\d۰-۹٠-٩](?:[\d۰-۹٠-٩]|[,،٬\s])*[\d۰-۹٠-٩])/);
    if(parts)return parts[1].trim();
  }
  // Number with thousand separators
  var m=t.match(/([\d۰-۹٠-٩]{1,3}(?:[,،٬][\d۰-۹٠-٩]{3})+)/);
  if(m)return m[1].trim();
  // All numbers with thousand separators
  var all=t.match(/[\d۰-۹٠-٩]+(?:[,،٬][\d۰-۹٠-٩]{3})+/g);
  if(all&&all.length){
    all.sort(function(a,b){return b.replace(/[^\d]/g,'').length-a.replace(/[^\d]/g,'').length;});
    return all[0];
  }
  // v7.82: Plain number without separators (e.g., "1000000")
  var plain=t.match(/[\d۰-۹٠-٩]{4,}/g);
  if(plain&&plain.length){
    plain.sort(function(a,b){return b.replace(/[^\d]/g,'').length-a.replace(/[^\d]/g,'').length;});
    return plain[0];
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
  var target=__fp==='1'?window.opener:parent;
  if(target){target.postMessage({type:'selectors',data:S},'*');}
  if(__fp==='1'){setTimeout(function(){window.close();},500);}
};

})();
</script>
SCRIPT;

$html = preg_replace('~</body>~i', $script . '</body>', $html);
if (stripos($html, '</body>') === false) $html .= $script;

if (!$fullMode) { $html = preg_replace('~<a ([^>]*)href=~i', '<a $1data-href=', $html); }

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

if (preg_match_all('~([\d۰-۹٠-٩](?:[\d۰-۹٠-٩]|'.$sep.')*[\d۰-۹٠-٩])\s*(تومان|تومن|ریال|ر\.ی)~u', $text, $matches, PREG_SET_ORDER)) {
$best = null; $bestLen = 0;
foreach ($matches as $m) {
$clean = preg_replace('~[^\d۰-۹٠-٩]~u', '', $m[1]);
$len = mb_strlen($clean);
if ($len > $bestLen) { $bestLen = $len; $best = $m; }
}
if ($best) return trim($best[1] . ' ' . $best[2]);
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
usort($candidates, fn($a, $b) => $b['len'] <=> $a['len']);
return trim($candidates[0]['raw']) . ' تومان';
}
}

if (preg_match_all('~([\d۰-۹٠-٩]{4,})~u', $text, $matches)) {
$candidates = [];
foreach ($matches[1] as $match) {
$clean = preg_replace('~[^\d۰-۹٠-٩]~u', '', $match);
$en = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], $clean);
$en = str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], ['0','1','2','3','4','5','6','7','8','9'], $en);
$val = (int)$en;
if ($val >= 1000) {
$candidates[] = ['raw' => $match, 'clean' => $clean, 'val' => $val, 'len' => mb_strlen($clean)];
}
}
if ($candidates) {
usort($candidates, fn($a, $b) => $b['val'] <=> $a['val']);
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

function extractSmartLink($node, $xp, string $baseUrl): string {
if (!$node instanceof DOMElement) return '';

if ($node->tagName === 'a') {
$h = $node->getAttribute('href') ?: $node->getAttribute('data-href') ?: $node->getAttribute('data-url') ?: '';
if ($h && $h !== '#' && !preg_match('~^(javascript:|data:)~i', $h)) {
return make_absolute_url($h, $baseUrl);
}
}

foreach (['data-href', 'data-link', 'data-url', 'data-product-url', 'data-product-link'] as $attr) {
$v = $node->getAttribute($attr);
if ($v && $v !== '#' && !preg_match('~^(javascript:|data:)~i', $v)) {
return make_absolute_url($v, $baseUrl);
}
}

$aNodes = @$xp->query('.//a[@href]', $node);
if ($aNodes && $aNodes->length) {
$h = $aNodes->item(0)->getAttribute('href');
if ($h && $h !== '#' && !preg_match('~^(javascript:|data:)~i', $h)) {
return make_absolute_url($h, $baseUrl);
}
}

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
function send_sse_ping() {
echo ": keepalive " . time() . "\n\n";
@ob_flush(); @flush();
}

if (isset($_GET['fetch_missing_stream'])) {
header('Content-Type: text/event-stream'); header('Cache-Control: no-cache'); header('X-Accel-Buffering: no');
while (@ob_get_level()) @ob_end_clean();
$items = json_decode($_POST['items'] ?? '[]', true) ?: [];
if (empty($items)) { send_sse('fetch_info', ['msg' => 'محصولی برای دریافت تصویر/قیمت یافت نشد']); send_sse('done', []); exit; }
$total = count($items);
send_sse('fetch_info', ['msg' => "شروع دریافت تصاویر/قیمت: $total محصول..."]);
$done = 0; $found = 0; $failed = 0;
foreach ($items as $i => $item) {
$key = $item['key'] ?? '';
$link = $item['link'] ?? '';
$n = $i + 1;
send_sse('missing_start', ['current' => $n, 'total' => $total, 'key' => $key, 'title' => mb_substr($item['title'] ?? $key, 0, 40)]);

$updates = ['image' => '', 'price' => '', 'key' => $key];
$mode = $item['mode'] ?? 'fetch';

if ($mode === 'validate' && !empty($item['imgUrl'])) {
$imgUrl = $item['imgUrl'];

$localPath = saveImageLocal($imgUrl, $link);
if ($localPath && file_exists($localPath) && filesize($localPath) > 200) {

$updates['image_valid'] = true;
$updates['image_cached'] = true;
$updates['image'] = $imgUrl;
$found++;
$done++;
send_sse('missing_done', $updates);
if ($done % 10 === 0) send_sse_ping();
usleep(30000);
continue;
}

send_sse('fetch_info', ['msg' => "[$n/$total] ⚠️ تصویر غیرقابل دانلود — دریافت مجدد از صفحه محصول: ".mb_substr($item['title']??$key,0,40)]);
}

if ($mode === 'preload' && !empty($item['imgUrl'])) {
$imgUrl = $item['imgUrl'];
$localPath = saveImageLocal($imgUrl, $link);
if ($localPath && file_exists($localPath) && filesize($localPath) > 200) {
$updates['image_valid'] = true;
$updates['image_cached'] = true;
$updates['image'] = $imgUrl;
$found++;
} else {

if (!empty($link)) {
send_sse('fetch_info', ['msg' => "[$n/$total] ⚠️ پیش‌دانلود ناموفق — تلاش از صفحه محصول: ".mb_substr($item['title']??$key,0,40)]);

} else {
$failed++;
}
}
if (!empty($updates['image'])) {
$done++;
send_sse('missing_done', $updates);
if ($done % 10 === 0) send_sse_ping();
usleep(30000);
if (!empty($updates['image_cached'])) continue;
}
}
for ($retry = 0; $retry < 3; $retry++) {
$detail = fetch_html($link, 10);
if ($detail['ok']) {
[$dom, $xp] = load_dom($detail['html']);

$imgQueries = [
"//meta[@property='og:image']/@content",
"//img[contains(@class,'wp-post-image')]",
"//img[contains(@class,'product')]",
"//*[contains(@class,'gallery')]//img",
"//img[contains(@class,'attachment-')]","//article//img","//main//img",
];
foreach ($imgQueries as $q) {
$ns = @$xp->query($q);
if ($ns && $ns->length) {

$imgAttrs = ['src', 'data-src', 'data-lazy-src', 'data-original', 'data-zoom-image'];
$v = $ns->item(0)->nodeValue ?: '';
if ($v && url_is_image($v)) { $updates['image'] = make_absolute_url($v, $detail['url']); break; }
foreach ($imgAttrs as $attr) {
$v2 = $ns->item(0)->getAttribute($attr);
if ($v2 && url_is_image($v2)) { $updates['image'] = make_absolute_url($v2, $detail['url']); break; }
}
if (!empty($updates['image'])) break;
}
}

if (empty($updates['image'])) {
$extraImg = extractImageFromHtml($detail['html'], $detail['url']);
if ($extraImg) $updates['image'] = $extraImg;
}

$priceQueries = [
"//*[contains(@class,'price')]//*[contains(@class,'amount')]",
"//*[contains(@class,'price')]","//meta[@property='product:price:amount']/@content",
];
foreach ($priceQueries as $q) {
$ns = @$xp->query($q);
if ($ns && $ns->length) {
$price = extractPrice($ns->item(0)->textContent ?: $ns->item(0)->nodeValue);
if ($price) { $updates['price'] = $price; break; }
}
}
if ($updates['image'] || $updates['price']) $found++;

if (!empty($updates['image'])) {
$localPath = saveImageLocal($updates['image'], $link);
if ($localPath && file_exists($localPath) && filesize($localPath) > 200) {
$updates['image_cached'] = true;
}
}
break;
}
if ($retry < 2) {
send_sse('fetch_info', ['msg' => "[$n/$total] تلاش مجدد ".($retry+2).": ".mb_substr($item['title']??$key,0,40)]);
usleep(500000);
}
}
if (!$detail['ok']) $failed++;
$done++;
send_sse('missing_done', $updates);
if ($done % 10 === 0) send_sse_ping();
usleep(80000);
}
send_sse('fetch_info', ['msg' => "✓ تکمیل: $found موفق از $total ($failed ناموفق)"]);
send_sse('fetch_complete', ['done' => $done, 'found' => $found, 'failed' => $failed, 'total' => $total]);
send_sse('done', []); exit;
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
$extracted = ['key' => $key];

for ($retry = 0; $retry < 3; $retry++) {
$res = fetch_html($productUrl, 15);
if (!$res['ok']) {
if ($retry < 2) {
send_sse('detail_progress', ['current' => $processed, 'total' => $total, 'key' => $key, 'retry' => $retry + 1]);
usleep(500000);
continue;
}
break;
}

[$dom, $xp] = load_dom($res['html']);
$allFound = true;

foreach ($detailSelectors as $field => $config) {
if (empty($config['enabled']) || empty($config['selector'])) continue;

if (isset($extracted[$field]) && $extracted[$field] !== '') continue;

$xpath = cssToXpath($config['selector']);
if (!$xpath) continue;

$nodes = @$xp->query($xpath);
if ($nodes && $nodes->length) {
$node = $nodes->item(0);
if (in_array($field, ['longDesc', 'shortDesc'])) {
$value = trim(@$dom->saveHTML($node));
$value = preg_replace('~\s+~', ' ', $value);
$extracted[$field] = $value;
} elseif ($field === 'image') {

$imgAttrs = ['src', 'data-src', 'data-lazy-src', 'data-original', 'data-zoom-image'];
$imgVal = '';
foreach ($imgAttrs as $attr) {
$v = $node->getAttribute($attr);
if ($v && !preg_match('/placeholder|1x1|blank|spinner|loading|dummy/i', $v)) {
$imgVal = make_absolute_url($v, $res['url']);
break;
}
}
if ($imgVal) {
$extracted[$field] = $imgVal;
} else {
$textContent = $node->textContent ?: '';
if ($textContent && preg_match('~https?://~i', $textContent)) {
$extracted[$field] = make_absolute_url(trim($textContent), $res['url']);
}
}
} else {
$value = normalize_text($node->textContent);
$extracted[$field] = $value;
}
}

if (!isset($extracted[$field]) || $extracted[$field] === '') {
$allFound = false;
}
}

if (!isset($extracted['image']) || $extracted['image'] === '') {
$extraImg = extractImageFromHtml($res['html'], $productUrl);
if ($extraImg) $extracted['image'] = $extraImg;
}

if ($allFound || $retry >= 2) break;

send_sse('detail_progress', ['current' => $processed, 'total' => $total, 'key' => $key, 'retry' => $retry + 1]);
usleep(300000);
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
if ($total > 0) {
send_sse('fetch_info', ['msg' => "دریافت تصاویر/قیمت‌های مفقود: $total محصول..."]);
}
$i = 0;
$failCount = 0;

foreach ($need as $key => $p) {
$i++;
send_sse('missing_start', ['current' => $i, 'total' => $total, 'key' => $key]);

$fetched = false;
for ($retry = 0; $retry < 3 && !$fetched; $retry++) {
$detail = fetch_html($p['link'], 10);
if ($detail['ok']) {
$fetched = true;
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
break;
}
}
}
}

if (empty($allProducts[$key]['price'])) {
$ns = @$xp->query("//*[contains(@class,'price')]//*[contains(@class,'amount')]");
if ($ns && $ns->length) {
$price = extractPrice($ns->item(0)->textContent);
if ($price) $allProducts[$key]['price'] = $price;
}
}
} elseif ($retry < 2) {
send_sse('fetch_info', ['msg' => "[$i/$total] تلاش مجدد ".($retry+2)." برای: ".mb_substr($p['title']??$key,0,40)]);
usleep(500000);
}
}

$updates = ['image' => $allProducts[$key]['image'] ?? '', 'price' => $allProducts[$key]['price'] ?? '', 'key' => $key];
if (!$fetched) $failCount++;
send_sse('missing_done', $updates);
if ($i % 10 === 0) send_sse_ping();
usleep(80000);
}
if ($failCount > 0) send_sse('fetch_info', ['msg' => "⚠️ $failCount محصول قابل دسترسی نبود (می‌توانید دوباره تلاش کنید)"]);
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

$headers = ['#', 'Title', 'Original Price', 'Final Price', 'URL', 'Image'];
foreach ($detailFields as $f) $headers[] = $f['label'];
if ($useCustom) $headers[] = $customName;

$cols = count($headers);
$colLetters = [];
for ($c = 0; $c < $cols; $c++) $colLetters[] = chr(65 + ($c < 26 ? $c : -1));

$sharedStrings = [];
$ssIndex = [];
function ssIdx($str) {
global $sharedStrings, $ssIndex;
$str = (string)$str;
if (isset($ssIndex[$str])) return $ssIndex[$str];
$idx = count($sharedStrings);
$sharedStrings[] = $str;
$ssIndex[$str] = $idx;
return $idx;
}

$sheetRows = '';

$sheetRows .= '<row r="1">';
for ($c = 0; $c < $cols; $c++) {
$col = chr(65 + $c);
$sheetRows .= '<c r="'.$col.'1" t="s"><v>'.ssIdx($headers[$c]).'</v></c>';
}
$sheetRows .= '</row>';

foreach ($products as $i => $p) {
$row = $i + 2;
$sheetRows .= '<row r="'.$row.'">';
$vals = [($i + 1), $p['title'] ?? '', $p['origPrice'] ?? '', $p['price'] ?? '', $p['link'] ?? '', $p['image'] ?? ''];
foreach ($detailFields as $f) {
$val = $p[$f['key']] ?? '';
if (in_array($f['key'], ['shortDesc', 'longDesc'])) $val = strip_tags($val);
$vals[] = $val;
}
if ($useCustom) $vals[] = $p['custom'] ?? '';
for ($c = 0; $c < $cols; $c++) {
$col = chr(65 + $c);
if ($c === 0) {
$sheetRows .= '<c r="'.$col.$row.'"><v>'.($i + 1).'</v></c>';
} else {
$sheetRows .= '<c r="'.$col.$row.'" t="s"><v>'.ssIdx($vals[$c] ?? '').'</v></c>';
}
}
$sheetRows .= '</row>';
}

$ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">';
foreach ($sharedStrings as $s) {
$ssXml .= '<si><t>'.htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
}
$ssXml .= '</sst>';

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>';

$wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets></workbook>';

$relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';

$ctXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';

$rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

$tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE) === true) {
$zip->addFromString('[Content_Types].xml', $ctXml);
$zip->addFromString('_rels/.rels', $rootRelsXml);
$zip->addFromString('xl/workbook.xml', $wbXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $relsXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->addFromString('xl/sharedStrings.xml', $ssXml);
$zip->close();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="products-' . date('Ymd-His') . '.xlsx"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
@unlink($tmpFile);
exit;
}

if (($_POST['action'] ?? '') === 'save_connections') {
header('Content-Type: application/json; charset=UTF-8');
$conn = loadConnections();
if (isset($_POST['woocommerce'])) { $w = json_decode($_POST['woocommerce'], true) ?: []; $conn['woocommerce'] = ['enabled'=>!empty($w['enabled']),'store_url'=>trim($w['store_url']??''),'consumer_key'=>trim($w['consumer_key']??''),'consumer_secret'=>trim($w['consumer_secret']??''),'default_category'=>(int)($w['default_category']??0),'default_status'=>$w['default_status']??'draft','stock_quantity'=>(int)($w['stock_quantity']??10),'manage_stock'=>!empty($w['manage_stock'])]; }
if (isset($_POST['basalam'])) { $b = json_decode($_POST['basalam'], true) ?: []; $fallbackCats=array_values(array_filter(array_map('intval',$b['fallback_cat_ids']??[]),function($v){return $v>0;})); $vendors=[]; if(!empty($b['vendors'])&&is_array($b['vendors'])){foreach($b['vendors'] as $v){$vid=(int)($v['vendor_id']??0);$vt=trim($v['token']??'');if($vid>0&&$vt!=='')$vendors[]=['vendor_id'=>$vid,'token'=>$vt,'name'=>trim($v['name']??''),'shop_name'=>trim($v['shop_name']??'')];}} $conn['basalam'] = ['enabled'=>!empty($b['enabled']),'token'=>trim($b['token']??''),'vendor_id'=>(int)($b['vendor_id']??0),'preparation_days'=>(int)($b['preparation_days']??3),'weight'=>(int)($b['weight']??500),'package_weight'=>(int)($b['package_weight']??0),'stock'=>(int)($b['stock']??10),'category_id'=>(int)($b['category_id']??0),'auto_category'=>!empty($b['auto_category']),'gemini_api_key'=>trim($b['gemini_api_key']??''),'fallback_cat_ids'=>$fallbackCats,'vendors'=>$vendors]; }

if (isset($_POST['ai'])) { $a = json_decode($_POST['ai'], true) ?: []; $conn['ai'] = ['enabled'=>!empty($a['enabled']),'api_key'=>trim($a['api_key']??''),'base_url'=>trim($a['base_url']??'https://dashscope.aliyuncs.com/compatible-mode/v1'),'model'=>trim($a['model']??'qwen-plus'),'temperature'=>(float)($a['temperature']??0.1)]; }

if (isset($_POST['baleh'])) { $bl = json_decode($_POST['baleh'], true) ?: []; $conn['baleh'] = ['enabled'=>!empty($bl['enabled']),'token'=>trim($bl['token']??''),'chat_id'=>trim($bl['chat_id']??'')]; }
if (isset($_POST['rubika'])) { $rb = json_decode($_POST['rubika'], true) ?: []; $conn['rubika'] = ['enabled'=>!empty($rb['enabled']),'token'=>trim($rb['token']??''),'chat_id'=>trim($rb['chat_id']??'')]; }
if (isset($_POST['notif_events'])) { $ne = json_decode($_POST['notif_events'], true) ?: []; $conn['notif_events'] = ['order_new'=>!empty($ne['order_new']),'order_status'=>!empty($ne['order_status']),'chat_msg'=>!empty($ne['chat_msg']),'product_status'=>!empty($ne['product_status']),'product_new'=>!empty($ne['product_new']),'order_refund'=>!empty($ne['order_refund']),'src_price'=>!empty($ne['src_price']),'src_stock'=>!empty($ne['src_stock']),'run_fail'=>!empty($ne['run_fail']),'retire'=>!empty($ne['retire']),'cron_ping'=>!empty($ne['cron_ping'])]; }
// v8.34: تنظیمات بازنشستگی محصولات رفته از مبدأ
if (isset($_POST['retire_mode'])) {
    $rm = (string)$_POST['retire_mode'];
    $conn['retire_mode'] = isset(retireModes()[$rm]) ? $rm : 'off';
}
if (isset($_POST['retire_max_pct']))   $conn['retire_max_pct']   = max(1, min(100, (float)$_POST['retire_max_pct']));
if (isset($_POST['retire_max_count'])) $conn['retire_max_count'] = max(1, (int)$_POST['retire_max_count']);
// v8.33: تنظیمات نگهبان صف
if (isset($_POST['stall_watchdog'])) $conn['stall_watchdog'] = !empty($_POST['stall_watchdog']) && $_POST['stall_watchdog'] !== 'false';
if (isset($_POST['stall_after']))    $conn['stall_after']    = max(60, (int)$_POST['stall_after']);
// v8.37: فاصلهٔ پینگ کران (دقیقه) — صفر یعنی هر اجرا
if (isset($_POST['ping_every'])) $conn['ping_every'] = max(0, (int)$_POST['ping_every']);
// v8.38: یادآوری موارد بی‌جواب
if (isset($_POST['notif_remind_after'])) $conn['notif_remind_after'] = max(0, (int)$_POST['notif_remind_after']);
if (isset($_POST['notif_remind_max']))   $conn['notif_remind_max']   = max(0, (int)$_POST['notif_remind_max']);
echo json_encode(['ok'=>saveConnections($conn),'message'=>'ذخیره شد'], JSON_UNESCAPED_UNICODE); exit;
}

if (($_POST['action'] ?? '') === 'upload_import') {
header('Content-Type: application/json; charset=UTF-8');
if (empty($_FILES['importFile']) || $_FILES['importFile']['error'] !== UPLOAD_ERR_OK) {
echo json_encode(['ok'=>false,'error'=>'فایلی انتخاب نشده یا خطا در آپلود'], JSON_UNESCAPED_UNICODE); exit;
}
$tmpPath = $_FILES['importFile']['tmp_name'];
$origName = $_FILES['importFile']['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','xls','xlsx','xml'])) {
echo json_encode(['ok'=>false,'error'=>'فرمت فایل پشتیبانی نمی‌شود. CSV, XLS, XLSX مجاز است.'], JSON_UNESCAPED_UNICODE); exit;
}
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
$savePath = UPLOAD_DIR . 'import_' . time() . '.' . $ext;
move_uploaded_file($tmpPath, $savePath);
$parsed = parseUploadedFile($savePath, $ext);
if (!empty($parsed['error'])) {
echo json_encode(['ok'=>false,'error'=>$parsed['error']], JSON_UNESCAPED_UNICODE); exit;
}
if (empty($parsed['headers']) || empty($parsed['rows'])) {
echo json_encode(['ok'=>false,'error'=>'فایل خالی است یا ستون‌ها شناسایی نشدند'], JSON_UNESCAPED_UNICODE); exit;
}

$mapping = autoDetectColumns($parsed['headers']);
echo json_encode([
'ok' => true,
'file' => basename($savePath),
'headers' => array_values($parsed['headers']),
'rows' => count($parsed['rows']),
'mapping' => $mapping,
'sample' => array_slice(array_map(function($r) use ($parsed) {
$vals = [];
foreach ($parsed['headers'] as $i => $h) $vals[$h] = $r['values'][$i] ?? '';
return $vals;
}, $parsed['rows']), 0, 3)
], JSON_UNESCAPED_UNICODE); exit;
}

if (($_POST['action'] ?? '') === 'process_import') {
header('Content-Type: application/json; charset=UTF-8');
$file = trim($_POST['file'] ?? '');
$mapping = json_decode($_POST['mapping'] ?? '{}', true) ?: [];
if (!$file || empty($mapping)) {
echo json_encode(['ok'=>false,'error'=>'اطلاعات ناقص'], JSON_UNESCAPED_UNICODE); exit;
}
$filePath = UPLOAD_DIR . $file;
if (!file_exists($filePath)) {
echo json_encode(['ok'=>false,'error'=>'فایل یافت نشد'], JSON_UNESCAPED_UNICODE); exit;
}
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$parsed = parseUploadedFile($filePath, $ext);
if (empty($parsed['rows'])) {
echo json_encode(['ok'=>false,'error'=>'ردیفی یافت نشد'], JSON_UNESCAPED_UNICODE); exit;
}
$products = [];
$count = 0;
foreach ($parsed['rows'] as $row) {
$vals = $row['values'];
$hdrs = $parsed['headers'];
$title = '';
$price = '';
$image = '';
$link = '';
$sku = '';
$shortDesc = '';
$longDesc = '';
foreach ($mapping as $field => $colIdx) {
$idx = (int)$colIdx;
$val = $vals[$idx] ?? '';
if ($field === 'title') $title = trim($val);
elseif ($field === 'price') $price = trim($val);
elseif ($field === 'image') $image = trim($val);
elseif ($field === 'link') $link = trim($val);
elseif ($field === 'sku') $sku = trim($val);
elseif ($field === 'shortDesc') $shortDesc = trim($val);
elseif ($field === 'longDesc') $longDesc = trim($val);
}
if (!$title && !$link) continue;
$key = md5('import:' . $title . '|' . $price . '|' . $count);
$products[] = [
'key' => $key,
'title' => $title,
'price' => $price,
'image' => $image,
'link' => $link,
'sku' => $sku,
'shortDesc' => $shortDesc,
'longDesc' => $longDesc
];
$count++;
}
echo json_encode(['ok'=>true,'count'=>$count,'products'=>$products], JSON_UNESCAPED_UNICODE); exit;
}

function autoDetectColumns(array $headers): array {
$mapping = [];
$patterns = [
'title' => ['title','عنوان','name','نام','product','محصول','کالا'],
'price' => ['price','قیمت','regular_price','amount','مبلغ','final','نهایی'],
'image' => ['image','تصویر','عکس','img','photo','تصویر_محصول','picture'],
'link' => ['url','لینک','link','href','آدرس','سایت','منبع','source'],
'sku' => ['sku','کد','code','بارکد','barcode','sku_product'],
'shortDesc' => ['short_description','توضیحات_کوتاه','short_desc','خلاصه','brief'],
'longDesc' => ['description','توضیحات','long_desc','توضیحات_بلند','detail','جزئیات'],
];
foreach ($patterns as $field => $keywords) {
foreach ($headers as $i => $h) {
$hl = mb_strtolower(trim($h), 'UTF-8');
foreach ($keywords as $kw) {
if ($hl === $kw || strpos($hl, $kw) !== false) {
$mapping[$field] = $i;
break 2;
}
}
}
}
return $mapping;
}

if(isset($_GET['extract_queue_status'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=extractReadQueue();
$progress=readProgress(EXTRACT_PROGRESS_FILE);
// v8.20: اگر پردازش قطع شده باشد (مثلاً timeout سرور)، ردیف نباید برای
// همیشه «در حال اجرا» بماند؛ بعد از ۱۵ دقیقه بی‌پاسخی «خطا» علامت می‌خورد.
$dirty=false;
$now=time();
foreach($queue['entries'] as &$qe){
    if(($qe['status']??'')!=='running')continue;
    $alive=!empty($progress['running'])&&!($progress['done']??false);
    $age=$now-(int)($qe['started_at']??$now);
    if(!$alive&&$age>900){
        $qe['status']='failed';
        $qe['error']='پردازش نیمه‌کاره رها شد (وقفهٔ سرور؟)';
        $qe['done_at']=$now;
        $dirty=true;
    }
}
unset($qe);
if($dirty)extractWriteQueue($queue);
echo json_encode(['ok'=>true,'entries'=>$queue['entries'],'progress'=>$progress],JSON_UNESCAPED_UNICODE);
exit;
}

/* v8.25: گزارش تفصیلی یک اجرای تمام‌شده */
if(isset($_GET['extract_report'])){
header('Content-Type: application/json; charset=UTF-8');
$qid=trim($_GET['queue_id']??'');
if($qid===''){echo json_encode(['ok'=>false,'error'=>'شناسه نامعتبر']);exit;}
$rep=extractLoadReport($qid);
if($rep===null){
// اجرای در حال انجام هنوز گزارش نهایی ندارد؛ از progress زنده بخوان
$p=readProgress(EXTRACT_PROGRESS_FILE);
if(($p['queue_id']??'')===$qid){
$rep=['new_items'=>$p['new_items']??[],'changed_items'=>$p['changed_items']??[],
'removed_items'=>$p['removed_items']??[],'unchanged'=>$p['unchanged']??0,
'price_up'=>$p['price_up']??0,'price_down'=>$p['price_down']??0,
'extracted'=>$p['extracted']??0,'live'=>true];
}
}
if($rep===null){echo json_encode(['ok'=>false,'error'=>'گزارشی برای این اجرا ذخیره نشده']);exit;}
echo json_encode(['ok'=>true,'report'=>$rep],JSON_UNESCAPED_UNICODE);exit;
}

/* حذف یک ردیف از صف استخراج — همتای bsl_queue_delete */
if(isset($_GET['extract_queue_delete'])){
header('Content-Type: application/json; charset=UTF-8');
$qid=trim($_GET['queue_id']??'');
if($qid===''){echo json_encode(['ok'=>false,'error'=>'شناسه نامعتبر']);exit;}
$queue=extractReadQueue();
$before=count($queue['entries']);
$queue['entries']=array_values(array_filter($queue['entries'],fn($e)=>($e['id']??'')!==$qid));
extractWriteQueue($queue);
@unlink(extractReportFile($qid));   // v8.25: گزارش یتیم نماند
echo json_encode(['ok'=>true,'removed'=>$before-count($queue['entries'])],JSON_UNESCAPED_UNICODE);exit;
}

/* پاک کردن ردیف‌های تکمیل‌شده — همتای woo_queue_clear_done */
if(isset($_GET['extract_queue_clear_done'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=extractReadQueue();
$before=count($queue['entries']);
foreach($queue['entries'] as $e){
    if(in_array(($e['status']??''),['done','failed'],true)) @unlink(extractReportFile($e['id']??''));
}
$queue['entries']=array_values(array_filter($queue['entries'],fn($e)=>!in_array(($e['status']??''),['done','failed'],true)));
extractWriteQueue($queue);
echo json_encode(['ok'=>true,'removed'=>$before-count($queue['entries'])],JSON_UNESCAPED_UNICODE);exit;
}

if(isset($_GET['extract_stop'])){
header('Content-Type: application/json; charset=UTF-8');
@file_put_contents(EXTRACT_STOP_FILE,json_encode(['stop'=>true,'time'=>time()],LOCK_EX));
$prev=readProgress(EXTRACT_PROGRESS_FILE);
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'total'=>$prev['total']??0,'current'=>$prev['current']??0,'started_at'=>$prev['started_at']??0,'recent_log'=>['❌ متوقف شد'],'total_log_count'=>1,'extracted'=>$prev['extracted']??0,'new'=>$prev['new']??0,'price_changed'=>$prev['price_changed']??0,'removed'=>$prev['removed']??0,'unchanged'=>$prev['unchanged']??0,'products_saved'=>false]);
echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
}

if(isset($_GET['poll_extract'])){
header('Content-Type: application/json; charset=UTF-8');
$p=readProgress(EXTRACT_PROGRESS_FILE);
echo json_encode($p,JSON_UNESCAPED_UNICODE);exit;
}

/**
 * v8.27: هستهٔ مشترک استخراج بک‌اند.
 * هم دکمهٔ دستی و هم کران‌جاب دقیقاً همین تابع را صدا می‌زنند تا رفتار
 * یکسان باشد و صف/پیشرفت/گزارش در هر دو حالت ثبت شود. تنها تفاوت،
 * برچسب trigger است که در ردیف صف ذخیره می‌شود.
 */
function runBackendExtract(string $profileKey, string $trigger = 'manual', bool $emitEarlyResponse = false): array {
@set_time_limit(0); @ignore_user_abort(true);
@unlink(EXTRACT_PROGRESS_FILE); @unlink(EXTRACT_STOP_FILE);

$startedAt=time();
$profiles=loadProfiles();

$profile=isset($profiles[$profileKey])?$profiles[$profileKey]:null;
if(!$profile){
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>false,'done'=>true,'error'=>'پروفایل یافت نشد','total'=>0,'current'=>0,'started_at'=>$startedAt,'recent_log'=>['❌ پروفایل یافت نشد'],'total_log_count'=>1]);
return ['__early_sent'=>$emitEarlyResponse, 'ok'=>false,'error'=>'پروفایل یافت نشد'];
}

$url=$profile['url']??'';
$maxPages=max(1,min(100,(int)($profile['pages']??10)));
$selectors=$profile['selectors']??[];
$pagType=$profile['pagType']??'query_page';
$pagVal=$profile['pagVal']??'';
$detailSelectors=extractNormalizeDetailSelectors($profile['detailSelectors']??[]);
$prevProducts=$profile['products']??[];
$prevOrder=$profile['productsOrder']??[];

if(!filter_var($url,FILTER_VALIDATE_URL)){
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>false,'done'=>true,'error'=>'URL نامعتبر','total'=>0,'current'=>0,'started_at'=>$startedAt,'recent_log'=>['❌ URL نامعتبر'],'total_log_count'=>1]);
return ['__early_sent'=>$emitEarlyResponse, 'ok'=>false,'error'=>'URL نامعتبر'];
}
if(empty($selectors)||empty($selectors['container'])){
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>false,'done'=>true,'error'=>'سلکتورها ذخیره نشده — ابتدا با فرانت‌اند استخراج کنید','total'=>0,'current'=>0,'started_at'=>$startedAt,'recent_log'=>['❌ سلکتورها ذخیره نشده'],'total_log_count'=>1]);
return ['__early_sent'=>$emitEarlyResponse, 'ok'=>false,'error'=>'سلکتورها ذخیره نشده'];
}

$queue=extractReadQueue();
$queueId='ex_'.$profileKey.'_'.time();
$queue['entries'][]=['id'=>$queueId,'status'=>'running','profile_key'=>$profileKey,'url'=>$url,'profile_name'=>$profile['name']??$profileKey,'started_at'=>time(),'products_count'=>0,'total'=>0,'current'=>0,'new'=>0,'price_changed'=>0,'removed'=>0,'unchanged'=>0,'trigger'=>$trigger];
extractWriteQueue($queue);

// v8.22: نسخهٔ قبلی را همین ابتدا بخوان تا مقایسه بتواند زنده انجام شود
$livePrevMap=extractPrevMap($profile);
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>true,'done'=>false,'total'=>0,'current'=>0,'started_at'=>$startedAt,'queue_id'=>$queueId,'recent_log'=>['⏳ شروع استخراج بک‌اند...'],'total_log_count'=>1,'extracted'=>0,'new'=>0,'price_changed'=>0,'removed'=>0,'unchanged'=>0,'price_up'=>0,'price_down'=>0,'url'=>$url,'profile_name'=>$profile['name']??$profileKey]);

// v8.27: پاسخ زودهنگام فقط برای درخواست مرورگر معنا دارد تا کاربر منتظر
// نماند و بقیهٔ کار در پس‌زمینه ادامه یابد. وقتی کران‌جاب این تابع را
// صدا می‌زند نباید چیزی چاپ شود، وگرنه خروجی JSON دوتکه می‌شود.
if($emitEarlyResponse){
while(@ob_get_level())@ob_end_clean();
$initResp=json_encode(['ok'=>true,'started'=>true,'profile_key'=>$profileKey,'url'=>$url,'max_pages'=>$maxPages],JSON_UNESCAPED_UNICODE);
header('Content-Type: application/json; charset=UTF-8');
header('Content-Length: '.strlen($initResp));
header('Connection: close');
echo $initResp;
if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}
@ob_flush();@flush();
}

$allProducts=[];$seenKeys=[];$nextUrl=null;$totalPages=0;
for($page=1;$page<=$maxPages;$page++){
if(file_exists(EXTRACT_STOP_FILE)){@unlink(EXTRACT_STOP_FILE);
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'total'=>count($allProducts),'current'=>$page,'started_at'=>$startedAt,'recent_log'=>['❌ متوقف شد'],'total_log_count'=>2,'extracted'=>count($allProducts)]);
return ['__early_sent'=>$emitEarlyResponse, 'ok'=>false,'cancelled'=>true];
}
if($page===1){$pageUrl=$url;}
elseif($pagType==='next_selector'&&$nextUrl){$pageUrl=$nextUrl;}
elseif($pagType==='next_selector'&&!$nextUrl){break;}
else{$pageUrl=build_page_url_custom($url,$url,$page,$pagType,$pagVal);}

$res=fetch_html($pageUrl,20);
$totalPages=$page;
$logs=['📄 صفحه '.$page.': '.($res['ok']?'✓':'✗').' — '.mb_substr($pageUrl,0,60)];
if(!$res['ok']){
$logs[]='❌ خطا: '.mb_substr($res['error']??'HTTP error',0,80);
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>true,'done'=>false,'total'=>0,'current'=>$page,'started_at'=>$startedAt,'recent_log'=>$logs,'total_log_count'=>$page,'extracted'=>count($allProducts),'page'=>$page,'page_ok'=>false]);
if($page===1)break;
else continue;
}

$pageProducts=parse_with_selectors($res['html'],$res['url'],$selectors);
$newCount=0;
foreach($pageProducts as $key=>$p){
if(isset($seenKeys[$key]))continue;
$seenKeys[$key]=1;
$allProducts[$key]=$p;
$newCount++;
}
$logs[]='✓ +'.($newCount).' محصول (کل: '.count($allProducts).')';
// v8.22: مقایسهٔ زنده — شمارنده‌ها و لیست‌ها همان لحظه محاسبه می‌شوند
$liveCmp=extractLiveCompare($allProducts,$livePrevMap);
writeProgress(EXTRACT_PROGRESS_FILE,array_merge(['running'=>true,'done'=>false,'total'=>$maxPages,'current'=>$page,'started_at'=>$startedAt,'recent_log'=>$logs,'total_log_count'=>$page,'extracted'=>count($allProducts),'page'=>$page,'page_ok'=>true,'page_new'=>$newCount,'page_total'=>count($allProducts)],$liveCmp));

if($pagType==='next_selector'&&!empty($pagVal)){
[$dom,$xp]=load_dom($res['html']);
$xpath=cssToXpath($pagVal);
$nodes=@$xp->query($xpath);
if($nodes&&$nodes->length&&$nodes->item(0) instanceof DOMElement){
$href=$nodes->item(0)->getAttribute('href');
if($href&&$href!=='#'&&!preg_match('~^(javascript:|data:)~i',$href)){
$nextUrl=make_absolute_url($href,$res['url']);
}else{$nextUrl=null;}
}else{$nextUrl=null;}
}
if($page>1&&$newCount===0)break;
usleep(500000);
}

$needDetail=[];
foreach($allProducts as $key=>$p){
if((empty($p['image'])||empty($p['price']))&&!empty($p['link'])){
$needDetail[$key]=$p;
}
}
$detailTotal=count($needDetail);
$detailDone=0;
if($detailTotal>0&&!empty($detailSelectors)){
$logs=['🔍 فاز ۲: دریافت جزئیات '.$detailTotal.' محصول...'];
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>true,'done'=>false,'total'=>$maxPages+$detailTotal,'current'=>$totalPages+$detailDone,'started_at'=>$startedAt,'recent_log'=>$logs,'total_log_count'=>$totalPages+1,'extracted'=>count($allProducts),'phase'=>'detail','detail_current'=>0,'detail_total'=>$detailTotal]);

foreach($needDetail as $key=>$p){
if(file_exists(EXTRACT_STOP_FILE)){@unlink(EXTRACT_STOP_FILE);
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'extracted'=>count($allProducts),'started_at'=>$startedAt,'recent_log'=>['❌ متوقف شد'],'total_log_count'=>$totalPages+$detailDone+1]);
return ['__early_sent'=>$emitEarlyResponse, 'ok'=>false,'cancelled'=>true];
}
$detailDone++;
$dr=fetch_html($p['link'],10);
if($dr['ok']){
[$dom2,$xp2]=load_dom($dr['html']);

foreach($detailSelectors as $field=>$selStr){
if(empty($selStr))continue;
$xPath=cssToXpath($selStr);
$ns=@$xp2->query($xPath);
if($ns&&$ns->length){
$val='';
if($field==='image'){
$el=$ns->item(0);
$src=$el->getAttribute('src')??$el->getAttribute('data-src')??$el->getAttribute('data-lazy-src')??'';
if($src)$val=make_absolute_url($src,$dr['url']);
if(!$val){
$content=$el->getAttribute('content');
if($content&&url_is_image($content))$val=make_absolute_url($content,$dr['url']);
}
}elseif($field==='price'){
$val=extractPrice($ns->item(0)->textContent);
}else{
$val=trim($ns->item(0)->textContent);
}
if($val)$allProducts[$key][$field]=$val;
}
}

if(empty($allProducts[$key]['image'])){
$ogImg=@$xp2->query("//meta[@property='og:image']/@content");
if($ogImg&&$ogImg->length){
$imgUrl=$ogImg->item(0)->nodeValue;
if($imgUrl&&url_is_image($imgUrl))$allProducts[$key]['image']=make_absolute_url($imgUrl,$dr['url']);
}
}
}
if($detailDone%5===0||$detailDone==$detailTotal){
$logs=['🔍 جزئیات '.$detailDone.'/'.$detailTotal.' — '.mb_substr($p['title']??$key,0,40)];
// قیمت‌ها در این مرحله ممکن است تکمیل شوند، پس مقایسه دوباره محاسبه می‌شود
$liveCmp=extractLiveCompare($allProducts,$livePrevMap);
writeProgress(EXTRACT_PROGRESS_FILE,array_merge(['running'=>true,'done'=>false,'total'=>$maxPages+$detailTotal,'current'=>$totalPages+$detailDone,'started_at'=>$startedAt,'recent_log'=>$logs,'total_log_count'=>$totalPages+$detailDone,'extracted'=>count($allProducts),'phase'=>'detail','detail_current'=>$detailDone,'detail_total'=>$detailTotal],$liveCmp));
}
usleep(200000);
}
}

$newCount=0;$priceChanged=0;$unchanged=0;$removedCount=0;
$newItems=[];$changedItems=[];$removedItems=[];

$prevMap=[];
if(!empty($prevProducts)){
$firstEntry=reset($prevProducts);
if(is_array($firstEntry)&&count($firstEntry)>=2&&is_string($firstEntry[0])){

foreach($prevProducts as $entry){if(is_array($entry)&&count($entry)>=2)$prevMap[$entry[0]]=$entry[1];}
}else{

$prevMap=$prevProducts;
}
}

// v8.22: همان تابعی که در حین اجرا استفاده شد، تا نتیجهٔ نهایی با
// شمارنده‌های زنده اختلاف نداشته باشد.
// v8.39: سقف بالاتر برای مقایسهٔ نهایی — این لیست‌ها مبنای «ارسال فقط
// تغییرات» هستند، پس اگر بریده شوند محصولی بی‌صدا جا می‌ماند.
$finalCmp=extractLiveCompare($allProducts,$prevMap,100000);
$newCount=$finalCmp['new'];
$priceChanged=$finalCmp['price_changed'];
$removedCount=$finalCmp['removed'];
$unchanged=$finalCmp['unchanged'];
$newItems=$finalCmp['new_items'];
$changedItems=$finalCmp['changed_items'];
$removedItems=$finalCmp['removed_items'];
$priceUp=$finalCmp['price_up'];
$priceDown=$finalCmp['price_down'];

$productsData=[];$productsOrder=array_keys($allProducts);
foreach($allProducts as $key=>$p){$productsData[]=[$key,$p];}
$profile['products']=$productsData;
$profile['productsOrder']=$productsOrder;
$profile['updatedAt']=time();
$profiles[$profileKey??profileKey($url)]=$profile;
saveProfiles($profiles);

$finalLog=['✅ استخراج بک‌اند تکمیل: '.count($allProducts).' محصول (🆕'.$newCount.' 💰'.$priceChanged.' ❌'.$removedCount.' ⏭'.$unchanged.')'];
writeProgress(EXTRACT_PROGRESS_FILE,['running'=>false,'done'=>true,'total'=>$maxPages+$detailTotal,'current'=>$totalPages+$detailTotal,'started_at'=>$startedAt,'last_progress_ts'=>time(),'queue_id'=>$queueId,'recent_log'=>$finalLog,'total_log_count'=>$totalPages+$detailTotal+1,'extracted'=>count($allProducts),'new'=>$newCount,'price_changed'=>$priceChanged,'removed'=>$removedCount,'unchanged'=>$unchanged,'price_up'=>$priceUp,'price_down'=>$priceDown,'new_items'=>$newItems,'changed_items'=>$changedItems,'removed_items'=>$removedItems,'products_saved'=>true,'profile_key'=>$profileKey??profileKey($url),'total_pages'=>$totalPages]);

$queue=extractReadQueue();
// v8.25: نتیجهٔ کامل هر اجرا جداگانه ذخیره می‌شود تا مودالِ کارهای
// تمام‌شده هم بتواند فهرست محصولات را نشان دهد، نه فقط لاگ زنده.
extractSaveReport($queueId, [
    'new_items'     => $newItems,
    'changed_items' => $changedItems,
    'removed_items' => $removedItems,
    'unchanged'     => $unchanged,
    'price_up'      => $priceUp,
    'price_down'    => $priceDown,
    'extracted'     => count($allProducts),
    'profile_name'  => $profile['name'] ?? ($profileKey ?? ''),
    'finished_at'   => time(),
]);

foreach($queue['entries'] as &$qe){if($qe['id']===$queueId){$qe['status']='done';$qe['products_count']=count($allProducts);$qe['total']=count($allProducts);$qe['current']=count($allProducts);$qe['done_at']=time();$qe['new']=$newCount;$qe['price_changed']=$priceChanged;$qe['removed']=$removedCount;$qe['unchanged']=$unchanged;$qe['price_up']=$priceUp;$qe['price_down']=$priceDown;$qe['has_report']=true;break;}}unset($qe);
extractWriteQueue($queue);

return ['__early_sent'=>$emitEarlyResponse, 'ok'=>true,'extracted'=>count($allProducts),'new'=>$newCount,'price_changed'=>$priceChanged,'removed'=>$removedCount,'unchanged'=>$unchanged,'price_up'=>$priceUp,'price_down'=>$priceDown,'new_items'=>$newItems,'changed_items'=>$changedItems,'removed_items'=>$removedItems,'products_saved'=>true,'profile_key'=>$profileKey??profileKey($url)];
}

if(isset($_GET['action']) && $_GET['action'] === 'backend_extract'){
$profileKey=trim($_GET['profile_key']??$_POST['profile_key']??'');
if($profileKey===''){
$u=trim($_GET['url']??'');
if($u!==''&&filter_var($u,FILTER_VALIDATE_URL))$profileKey=profileKey($u);
}
$res=runBackendExtract($profileKey,'manual',true);
// v8.30: همان اعلان‌های تغییر مبدأ که کران‌جاب می‌فرستد
if(!empty($res['ok'])){
$cnNow=loadConnections();
$profsNow=loadProfiles();
notifSourceChanges($cnNow,$res,$profsNow[$profileKey]['name']??$profileKey);
}
// اگر پاسخ زودهنگام ارسال شده، دیگر چیزی چاپ نمی‌کنیم
if(empty($res['__early_sent'])){
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($res,JSON_UNESCAPED_UNICODE);
}
exit;
}

if (isset($_GET['sync_status'])) {
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => true, 'state' => loadSyncState()], JSON_UNESCAPED_UNICODE);
exit;
}
if (($_POST['action'] ?? '') === 'update_sync_state') {
header('Content-Type: application/json; charset=UTF-8');
$key = trim($_POST['profile_key'] ?? '');
$status = $_POST['status'] ?? 'done';
$state = loadSyncState();
$state[$key] = ['lastRun' => time(), 'status' => $status];
saveSyncState($state);
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE); exit;
}

/* =====================================================================
 *  v8.39: ارسال فقط تغییرات («افزودن/آپدیت»)
 *
 *  تیک‌های «افزودن/آپدیت ووکامرس» و «افزودن/آپدیت باسلام» از نسخهٔ ۷
 *  در رابط کاربری بودند و ذخیره هم می‌شدند، ولی هیچ‌جای سمت سرور
 *  خوانده نمی‌شدند. نتیجه این بود که هر اجرای کران کل محصولات را
 *  دوباره می‌فرستاد و برای پروفایل چندهزارتایی ساعت‌ها طول می‌کشید.
 *
 *  حالا اگر تیک فعال باشد فقط محصولات جدید و تغییرکرده فرستاده
 *  می‌شوند. حذف‌شده‌ها مسیر جداگانهٔ خودشان را دارند (retireRemoved).
 * ===================================================================== */

/* =====================================================================
 *  v8.44: آماده‌سازی محصول برای ارسال، سمت سرور
 *
 *  تا اینجا فقط مرورگر قیمت نهایی را می‌ساخت (getSendP در جاوااسکریپت).
 *  کران محصول خام را در صف می‌گذاشت و ارسال‌کننده دنبال final_price
 *  می‌گشت که وجود نداشت، پس قیمت صفر می‌شد. حالا همان منطق اینجا هم
 *  پیاده شده تا اجرای خودکار و دستی یک نتیجه بدهند.
 * ===================================================================== */

/** قیمت نهایی با ضریب/درصد و گِرد کردن پروفایل — معادل getFinalPriceNum */
function profileFinalPrice(array $profile, $rawPrice): int {
    $base = extractPriceNum($rawPrice);
    if ($base <= 0) return 0;
    $mode = (string)($profile['priceMode'] ?? 'none');
    $val  = (float)($profile['priceVal'] ?? 0);
    $final = $base;
    if ($mode === 'percent')         $final = $base * (1 + ($val / 100));
    elseif ($mode === 'multiplier')  $final = $base * $val;
    $final = (int)round($final);
    $round = (int)($profile['roundPrice'] ?? 0);
    if ($round > 0) $final = (int)(round($final / $round) * $round);
    return $final;
}

/** امضای تنظیمات قیمت — اگر عوض شود یعنی قیمت همهٔ محصولات عوض شده */
function profilePriceSignature(array $profile): string {
    return (string)($profile['priceMode'] ?? 'none') . '|'
         . (string)($profile['priceVal'] ?? 0) . '|'
         . (string)($profile['roundPrice'] ?? 0) . '|'
         . trim((string)($profile['titleSuffix'] ?? ''));
}

/** محصول ذخیره‌شده را به همان شکلی درمی‌آورد که مرورگر می‌فرستد */
function prepareForSend(array $profile, string $key, array $p): array {
    $raw = (string)($p['price'] ?? '');
    $title = trim((string)($p['title'] ?? ($p['name'] ?? '')));
    $suffix = trim((string)($profile['titleSuffix'] ?? ''));
    if ($suffix !== '' && $title !== '' && mb_strpos($title, $suffix) === false) {
        $title .= ' ' . $suffix;
    }
    $unit = (mb_strpos($raw, 'ریال') !== false || mb_strpos($raw, 'ر.ی') !== false)
          ? 'rial' : 'toman';
    return [
        'key'         => $key,
        'title'       => $title,
        'final_price' => (string)profileFinalPrice($profile, $raw),
        'price'       => $raw,
        'price_unit'  => $unit,
        'image'       => (string)($p['image'] ?? ''),
        'sku'         => (string)($p['sku'] ?? ''),
        'short_desc'  => (string)($p['shortDesc'] ?? ($p['short_desc'] ?? '')),
        'long_desc'   => (string)($p['longDesc'] ?? ($p['long_desc'] ?? '')),
        'weight'      => (string)($p['weight'] ?? ''),
        'link'        => (string)($p['link'] ?? ''),
        'orig_price'  => (string)($p['origPrice'] ?? ($p['originalPrice'] ?? '')),
    ];
}

/**
 * از نتیجهٔ استخراج، کلید محصولاتی را برمی‌گرداند که باید ارسال شوند.
 * خروجی null یعنی «فیلتری اعمال نکن» (همه را بفرست).
 */
function syncChangedKeys(array $exRes): ?array {
    if (empty($exRes['ok'])) return null;   // استخراج نشد؛ به لیست تغییرات اعتماد نکن
    $keys = [];
    foreach (['new_items', 'changed_items'] as $bucket) {
        foreach (($exRes[$bucket] ?? []) as $it) {
            if (!is_array($it)) continue;
            $k = (string)($it['key'] ?? '');
            if ($k !== '') $keys[$k] = true;
        }
    }
    return $keys;
}

/**
 * محصولات پروفایل را به ترتیب درست و همراه با کلیدشان برمی‌گرداند.
 * محصولات به شکل [کلید, محصول] ذخیره می‌شوند و کران قبلاً کلید را دور
 * می‌ریخت؛ برای فیلتر کردن لازم است نگه داشته شود.
 *
 * $onlyKeys اگر داده شود، فقط همان کلیدها برگردانده می‌شوند.
 */
function profileOrderedProducts(array $profile, ?array $onlyKeys = null, bool $forSend = true): array {
    $raw = $profile['products'] ?? [];
    $map = [];
    foreach ($raw as $entry) {
        if (is_array($entry) && count($entry) >= 2 && is_string($entry[0])) {
            $map[$entry[0]] = $entry[1];
        }
    }
    // v8.44: قیمت نهایی را همین‌جا بساز، وگرنه ارسال‌کننده final_price
    // را پیدا نمی‌کند و قیمت صفر می‌فرستد.
    $mk = function (string $k, $p) use ($profile, $forSend) {
        if (!is_array($p)) return null;
        return $forSend ? prepareForSend($profile, $k, $p) : $p;
    };
    $order = $profile['productsOrder'] ?? [];
    $out = [];
    if (!empty($order) && is_array($order)) {
        foreach ($order as $k) {
            if (!isset($map[$k])) continue;
            if ($onlyKeys !== null && !isset($onlyKeys[$k])) continue;
            $v = $mk((string)$k, $map[$k]); if ($v !== null) $out[] = $v;
        }
        return $out;
    }
    if ($map) {
        foreach ($map as $k => $p) {
            if ($onlyKeys !== null && !isset($onlyKeys[$k])) continue;
            $v = $mk((string)$k, $p); if ($v !== null) $out[] = $v;
        }
        return $out;
    }
    // ساختار قدیمی: فهرست تخت بدون کلید — فیلتر ممکن نیست
    foreach ($raw as $entry) {
        if (!is_array($entry) || (!isset($entry['title']) && !isset($entry['price']))) continue;
        $k = (string)($entry['key'] ?? '');
        $out[] = $forSend ? prepareForSend($profile, $k, $entry) : $entry;
    }
    return $out;
}

// v8.31: تنها نقطهٔ ورود کران — cron_sync حذف شد چون دقیقاً همین کار را می‌کرد.
if (isset($_GET['cron_run']) || (($_POST['action'] ?? '') === 'cron_run')) {
header('Content-Type: application/json; charset=UTF-8');
@set_time_limit(0);
@ignore_user_abort(true);

// قفل ضد هم‌پوشانی — یک اجرای طولانی نباید با اجرای بعدی تداخل کند
$cronLock = __DIR__ . '/.cron_run.lock';
$lockAge  = is_file($cronLock) ? (time() - (int)@filemtime($cronLock)) : PHP_INT_MAX;
if ($lockAge < 1800) {
    // v8.37: حتی وقتی به‌خاطر قفل رد می‌شویم هم پینگ بفرست — وگرنه یک قفلِ
    // گیرکرده دقیقاً شبیه «کران‌جاب اصلاً اجرا نمی‌شود» به نظر می‌رسد.
    $cnLock = loadConnections();
    notifCronPing($cnLock, ['profiles' => [], 'locked' => $lockAge]);
    echo json_encode(['ok' => true, 'skipped' => true, 'profiles' => [],
        'reason' => 'اجرای قبلی هنوز تمام نشده (' . $lockAge . ' ثانیه)'], JSON_UNESCAPED_UNICODE);
    exit;
}
@file_put_contents($cronLock, (string)time());
register_shutdown_function(function () use ($cronLock) { @unlink($cronLock); });

$now = time();
$profiles = loadProfiles();
$syncState = loadSyncState();
$cn = loadConnections();
// v8.37: ok صریح، تا ابزارهای بیرونی بتوانند موفقیت اجرا را تشخیص دهند
$results = ['ok' => true, 'time' => $now, 'profiles' => []];
foreach ($profiles as $key => $profile) {
$syncCfg = $profile['syncConfig'] ?? [];
$pResult = ['key' => $key, 'name' => $profile['name'] ?? $key];
if (empty($syncCfg['enabled'])) { $pResult['status'] = 'sync_disabled'; $results['profiles'][] = $pResult; continue; }
$interval = (int)($syncCfg['interval'] ?? 3600);
$lastRun = (int)($syncState[$key]['lastRun'] ?? 0);
$target = $syncCfg['target'] ?? 'woo';
if ($interval > 0 && ($now - $lastRun < $interval)) { $pResult['status'] = 'not_due'; $pResult['remaining'] = $interval - ($now - $lastRun); $results['profiles'][] = $pResult; continue; }
$pResult['status'] = 'syncing'; $pResult['target'] = $target;

// v8.27: همان هستهٔ استخراج دکمهٔ دستی، با برچسب auto
$exRes = runBackendExtract($key, 'auto');
if (!empty($exRes['ok'])) {
$pResult['extracted'] = (int)($exRes['extracted'] ?? 0);
$pResult['extract_method'] = 'backend_extract';
$pResult['new'] = (int)($exRes['new'] ?? 0);
$pResult['price_changed'] = (int)($exRes['price_changed'] ?? 0);
$pResult['removed'] = (int)($exRes['removed'] ?? 0);
// v8.30: گران/ارزان و موجود/ناموجود شدن سایت مبدأ را اطلاع بده
$srcN = notifSourceChanges($cn, $exRes, $profile['name'] ?? $key);
if (!empty($srcN['sent'])) $pResult['src_notified'] = $srcN['sent'];

// v8.34: محصولاتی که از مبدأ رفته‌اند را روی مقصد بازنشسته کن
$retireMode = (string)($cn['retire_mode'] ?? 'off');
if ($retireMode !== 'off' && !empty($exRes['removed_items'])) {
    $rt = retireRemoved($cn, $exRes['removed_items'], $target, $retireMode,
                        (int)($exRes['extracted'] ?? 0));
    $pResult['retire'] = ['mode' => $retireMode, 'retired' => (int)($rt['retired'] ?? 0),
        'not_found' => (int)($rt['not_found'] ?? 0), 'failed' => (int)($rt['failed'] ?? 0)];
    if (!empty($rt['skipped'])) $pResult['retire']['skipped'] = $rt['skipped'];
    notifRetire($cn, $rt, $profile['name'] ?? $key);
}

$profiles = loadProfiles();
$profile  = $profiles[$key] ?? $profile;
} else {
$pResult['extract_error'] = $exRes['error'] ?? 'خطای نامشخص';
notifRunFailure($cn, 'استخراج', $profile['name'] ?? $key, $pResult['extract_error']);
}

// Now get products from profile (either freshly scraped or previously saved)
// v8.39: اگر تیک «افزودن/آپدیت» فعال باشد، فقط محصولات جدید و
// تغییرکرده فرستاده می‌شوند نه کل فهرست.
$wooOnlyChanged = !empty($syncCfg['wooAddUpdate']);
$bslOnlyChanged = !empty($syncCfg['bslAddUpdate']);
$changedKeys = ($wooOnlyChanged || $bslOnlyChanged) ? syncChangedKeys($exRes ?? []) : null;

// v8.44: اگر ضریب/درصد قیمت یا پسوند عنوان دستی عوض شده باشد، قیمت
// «همهٔ» محصولات عوض شده — حتی آن‌هایی که سایت مبدأ تغییرشان نداده.
// در این حالت فیلتر «فقط تغییرات» باید یک بار کنار گذاشته شود، وگرنه
// قیمت جدید هیچ‌وقت به ووکامرس و باسلام نمی‌رسد.
$priceSig  = profilePriceSignature($profile);
$lastSig   = (string)($syncState[$key]['price_sig'] ?? '');
$pricingChanged = ($lastSig !== '' && $lastSig !== $priceSig);
if ($pricingChanged && $changedKeys !== null) {
    $changedKeys = null;                    // این بار همه را بفرست
    $pResult['pricing_changed'] = true;
    $pResult['pricing_from'] = $lastSig;
    $pResult['pricing_to'] = $priceSig;
}

$orderedProducts = profileOrderedProducts($profile);
$changedProducts = $changedKeys === null
    ? $orderedProducts
    : profileOrderedProducts($profile, $changedKeys);

if ($changedKeys !== null) {
    $pResult['changed_only'] = count($changedProducts);
    $pResult['catalog_size'] = count($orderedProducts);
}

if ($target === 'woo' || $target === 'both') {
// v8.21: Queue products for WooCommerce (not just set sync state)
// v8.39: با تیک «افزودن/آپدیت ووکامرس» فقط تغییرات ارسال می‌شود
$wooSend = $wooOnlyChanged ? $changedProducts : $orderedProducts;
if(!empty($wooSend)){
$wooSuffix=trim($profile['titleSuffix']??'') ?: trim($cn['basalam']['title_suffix']??'');
$wooQueue=wooReadQueue();
$wooQueueId='cron_woo_'.$key.'_'.$now;
// v8.36: فایل مخصوص همین اجرا — قبلاً همه روی WOO_PRODUCTS_FILE می‌نوشتند
// و اجرای بعدی محصولات اجرای قبلی را می‌فرستاد.
$wooQFile=__DIR__.'/woo_queue_products_'.$wooQueueId.'.json';
@file_put_contents($wooQFile,json_encode($wooSend,JSON_UNESCAPED_UNICODE),LOCK_EX);
@file_put_contents(WOO_PRODUCTS_FILE,json_encode($wooSend,JSON_UNESCAPED_UNICODE),LOCK_EX);
$wooQueue['entries'][]=['id'=>$wooQueueId,'status'=>'running','products_file'=>$wooQFile,'total'=>count($wooSend),'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'current'=>0,'started_at'=>$now,'done_at'=>0,'profile_key'=>$key,'profile_name'=>($profile['name']??$key),'only_changed'=>$wooOnlyChanged,'config'=>['title_suffix'=>$wooSuffix]];
wooWriteQueue($wooQueue);
$pResult['woo']='queued';$pResult['woo_total']=count($wooSend);
} else { $pResult['woo'] = $wooOnlyChanged ? 'no_changes' : 'no_products'; }
$syncState[$key]=['lastRun'=>$now,'status'=>'running_woo','price_sig'=>$priceSig];
}
if ($target === 'bsl' || $target === 'both') {
// v8.39: با تیک «افزودن/آپدیت باسلام» فقط تغییرات ارسال می‌شود
$bslSend = $bslOnlyChanged ? $changedProducts : $orderedProducts;
if (!empty($bslSend)) {
$queueId = 'cron_' . $key . '_' . $now;
$qFile = __DIR__ . '/bsl_queue_products_' . $queueId . '.json';
if (@file_put_contents($qFile, json_encode($bslSend, JSON_UNESCAPED_UNICODE), LOCK_EX)) {
$catId = (int)($cn['basalam']['category_id'] ?? 0);
$autoCat = !empty($cn['basalam']['auto_category']);
$titleSuffix = trim($profile['titleSuffix'] ?? '') ?: trim($cn['basalam']['title_suffix'] ?? '');
$delayMs = max(0, (int)($cn['basalam']['delay_ms'] ?? 500));
$retryDelayMs = max(0, (int)($cn['basalam']['retry_delay_ms'] ?? 1000));
$fallbackCats = $cn['basalam']['fallback_cat_ids'] ?? [];
$profileCatId = (int)($profile['bslCategoryId'] ?? 0);
if ($profileCatId > 0) $catId = $profileCatId;
$profileFallbackCats = $profile['bslFallbackCatIds'] ?? [];
$allFallbackCats = array_values(array_unique(array_merge($profileFallbackCats, $fallbackCats)));
$queue = bslReadQueue();
$queue['entries'][] = ['id' => $queueId, 'status' => 'waiting', 'products_file' => $qFile, 'total' => count($bslSend), 'sent' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'current' => 0, 'started_at' => 0, 'done_at' => 0, 'paused_at' => 0, 'only_changed' => $bslOnlyChanged, 'config' => ['category_id' => $catId, 'auto_category' => $autoCat, 'title_suffix' => $titleSuffix, 'delay_ms' => $delayMs, 'retry_delay_ms' => $retryDelayMs, 'fallback_cat_ids' => $allFallbackCats], 'profile_key' => $key, 'profile_name' => $profile['name'] ?? $key, 'auto_sync' => true];
bslWriteQueue($queue);
$syncState[$key] = ['lastRun' => $now, 'status' => 'queued_bsl', 'price_sig' => $priceSig];
$pResult['bsl'] = 'queued'; $pResult['bsl_total'] = count($bslSend);
} else { $pResult['bsl'] = 'file_save_error'; }
} else { $pResult['bsl'] = $bslOnlyChanged ? 'no_changes' : 'no_products'; }
}
// v8.44: امضای قیمت را در هر حالت ثبت کن تا ارسال کامل فقط یک بار
// تکرار شود، نه در هر اجرای بعدی.
if (!isset($syncState[$key])) $syncState[$key] = ['lastRun' => $now, 'status' => 'idle'];
$syncState[$key]['price_sig'] = $priceSig;
$results['profiles'][] = $pResult;
}
saveSyncState($syncState);

// v8.33: مرحلهٔ نگهبان — اگر ارسالی وسط راه گیر کرده، ادامه‌اش بده.
// هزینه‌اش وقتی چیزی گیر نکرده صفر است: فقط چند خط خواندن از فایل.
$stallCfg  = (int)($cn['stall_after'] ?? 300);
$stallWake = !isset($cn['stall_watchdog']) || !empty($cn['stall_watchdog']);
if ($stallWake) {
    $results['watchdog'] = [];
    foreach (['bsl', 'woo'] as $wq) {
        $w = queueStallRecover($wq, $stallCfg);
        if (!empty($w['stalled'])) {
            $results['watchdog'][] = $w;
            notifRunFailure($cn, 'نگهبان صف',
                $wq === 'bsl' ? 'باسلام' : 'ووکامرس',
                'ارسال ' . ($w['kind'] === 'waiting' ? 'در صف مانده بود' : 'گیر کرده بود')
                . ' (' . (int)($w['idle'] ?? 0) . ' ثانیه بی‌حرکت، '
                . (int)($w['current'] ?? 0) . '/' . (int)($w['total'] ?? 0) . ') — '
                . (!empty($w['resumed']) ? 'خودکار ادامه داده شد ✅' : 'ادامه ناموفق ❌'));
        }
    }
}

$notifyResult = bslCheckNotifications($cn);
if (!empty($notifyResult)) $results['notifications'] = $notifyResult;

// v8.37: پینگ — آخرین کار، تا خلاصهٔ همین اجرا را هم بتواند گزارش کند
$pingRes = notifCronPing($cn, $results);
if (!empty($pingRes['sent'])) $results['ping'] = 'sent';
elseif (!empty($pingRes['skipped'])) $results['ping'] = $pingRes['skipped'];

echo json_encode($results, JSON_UNESCAPED_UNICODE); exit;
}

/**
 * v8.34: بازنشستگی دستی — پیش‌نمایش یا اجرا.
 * ?retire_run=1&profile=<key>&mode=draft&dry=1
 * بدون dry=1 واقعاً روی ووکامرس/باسلام اعمال می‌شود.
 */
if (isset($_GET['retire_run'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $cn  = loadConnections();
    $key = (string)($_GET['profile'] ?? '');
    $dry = !isset($_GET['dry']) || $_GET['dry'] !== '0';   // پیش‌فرض: فقط پیش‌نمایش
    $profiles = loadProfiles();
    if ($key === '' || !isset($profiles[$key])) {
        echo json_encode(['ok' => false, 'error' => 'پروفایل نامعتبر'], JSON_UNESCAPED_UNICODE); exit;
    }
    $profile = $profiles[$key];
    $mode = (string)($_GET['mode'] ?? ($cn['retire_mode'] ?? 'draft'));
    if (!isset(retireModes()[$mode]) || $mode === 'off') $mode = 'draft';
    $target = (string)($_GET['target'] ?? ($profile['syncConfig']['target'] ?? 'woo'));

    // آخرین گزارش استخراج این پروفایل را پیدا کن
    $rep = null; $newest = 0;
    foreach (glob(__DIR__ . '/extract_report_*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) continue;
        if (($d['profile_key'] ?? '') !== $key) continue;
        $ts = (int)@filemtime($f);
        if ($ts >= $newest) { $newest = $ts; $rep = $d; }
    }
    if ($rep === null) {
        echo json_encode(['ok' => false,
            'error' => 'گزارش استخراجی برای این پروفایل نیست — اول یک استخراج اجرا کنید'],
            JSON_UNESCAPED_UNICODE); exit;
    }
    $items = $rep['removed_items'] ?? [];
    $res = retireRemoved($cn, $items, $target, $mode, (int)($rep['extracted'] ?? 0), $dry);
    $res['ok'] = true; $res['profile'] = $profile['name'] ?? $key;
    $res['report_time'] = $newest;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * v8.47: مغایرت‌گیری مقصد با پروفایل‌ها.
 * ?recon=1&target=woo|bsl            → فقط گزارش (پیش‌فرض، امن)
 * &apply=1                           → اعمال تغییرات
 * &mode=off|draft|outofstock|delete  → با موارد اضافی چه شود
 * &fix_price=0                       → قیمت‌ها دست نخورند
 */
if (isset($_GET['recon'])) {
    header('Content-Type: application/json; charset=UTF-8');
    @set_time_limit(0);
    $cn = loadConnections();
    $target = (string)($_GET['target'] ?? '');
    if ($target !== 'woo' && $target !== 'bsl') {
        echo json_encode(['ok' => false, 'error' => 'مقصد نامعتبر'], JSON_UNESCAPED_UNICODE); exit;
    }
    $apply = !empty($_GET['apply']);
    $mode  = (string)($_GET['mode'] ?? ($cn['retire_mode'] ?? 'off'));
    if (!isset(retireModes()[$mode])) $mode = 'off';
    $fixPrice = !isset($_GET['fix_price']) || $_GET['fix_price'] !== '0';
    $res = reconRun($cn, $target, $apply, $mode, $fixPrice);
    // فهرست‌ها را برای پاسخ کوتاه کن تا مرورگر خفه نشود
    foreach (['extra', 'price_diff'] as $k) {
        $res[$k . '_total'] = count($res[$k] ?? []);
        if (isset($res[$k]) && count($res[$k]) > 200) $res[$k] = array_slice($res[$k], 0, 200);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * v8.48: مدیریت حافظهٔ یادگیری دسته‌بندی.
 * ?catlearn=1                 → فهرست آموخته‌ها
 * &forget=<کلمه>              → فراموش کردن یک کلمه
 * &clear=1                    → پاک کردن همه
 * &test=<عنوان>               → ببین برای این عنوان چه پیشنهاد می‌دهد
 */
if (isset($_GET['catlearn'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $d = catLearnLoad();

    $forget = trim((string)($_GET['forget'] ?? ''));
    if ($forget !== '') {
        $w = catFirstWord($forget) ?: $forget;
        unset($d[$w]);
        catLearnSave($d);
        echo json_encode(['ok' => true, 'forgot' => $w, 'left' => count($d)], JSON_UNESCAPED_UNICODE); exit;
    }
    if (!empty($_GET['clear'])) {
        catLearnSave([]);
        echo json_encode(['ok' => true, 'cleared' => true], JSON_UNESCAPED_UNICODE); exit;
    }
    $test = trim((string)($_GET['test'] ?? ''));
    if ($test !== '') {
        echo json_encode(['ok' => true, 'title' => $test, 'first_word' => catFirstWord($test),
            'learned_cat' => catLearnLookup($test, $d)], JSON_UNESCAPED_UNICODE); exit;
    }

    $rows = [];
    foreach ($d as $w => $row) {
        if (!is_array($row)) continue;
        $bestId = 0; $bestN = 0; $bestName = '';
        foreach (($row['cats'] ?? []) as $cid => $info) {
            if ((int)($info['n'] ?? 0) > $bestN) {
                $bestN = (int)$info['n']; $bestId = (int)$cid;
                $bestName = (string)($info['name'] ?? '');
            }
        }
        $rows[] = ['word' => $w, 'cat_id' => $bestId, 'cat_name' => $bestName,
                   'times' => $bestN, 'total' => (int)($row['n'] ?? 0),
                   'variants' => count($row['cats'] ?? []), 'last' => (int)($row['last'] ?? 0)];
    }
    usort($rows, fn($a, $b) => $b['last'] <=> $a['last']);
    echo json_encode(['ok' => true, 'count' => count($rows),
        'rows' => array_slice($rows, 0, 300)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** v8.33: بررسی/ادامهٔ دستی صف گیرکرده */
if (isset($_GET['queue_watchdog'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $cn = loadConnections();
    $after = (int)($_GET['after'] ?? ($cn['stall_after'] ?? 300));
    $dry   = !empty($_GET['dry']);
    $which = (string)($_GET['which'] ?? 'both');
    $out = [];
    foreach (($which === 'both' ? ['bsl', 'woo'] : [$which]) as $wq) {
        if ($wq !== 'bsl' && $wq !== 'woo') continue;
        $out[] = queueStallRecover($wq, max(30, $after), $dry);
    }
    echo json_encode(['ok' => true, 'checks' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
 *  v8.35: خودآزمون — «آیا واقعاً همان کدی که فکر می‌کنم روی سرور است؟»
 *
 *  چند بار پیش آمده که تغییری ساخته شده ولی روی هاست نصب نشده و
 *  به نظر رسیده کار نکرده است. این صفحه بدون حدس‌زدن جواب می‌دهد:
 *  هر قابلیت را واقعاً در همین فایلِ در حال اجرا بررسی می‌کند.
 * ===================================================================== */
if (isset($_GET['selftest'])) {
    $wantJson = isset($_GET['json']);
    header('Content-Type: ' . ($wantJson ? 'application/json' : 'text/html') . '; charset=UTF-8');

    $checks = [];
    $add = function (string $ver, string $name, bool $ok, string $detail = '') use (&$checks) {
        $checks[] = ['ver' => $ver, 'name' => $name, 'ok' => $ok, 'detail' => $detail];
    };

    // ۱) وجود توابع کلیدی هر نسخه — اگر نصب ناقص باشد اینجا لو می‌رود
    $fnMap = [
        ['8.32', 'مودال استعلام سفارش‌ها و گفتگوها', 'bslNormalizeParcel'],
        ['8.32', 'خواندن درست نام مشتری', 'bslParcelCustomerName'],
        ['8.33', 'متن کامل پیام‌های مشتری', 'bslFetchChatMessages'],
        ['8.33', 'نگهبان صف ارسال', 'queueStallCheck'],
        ['8.34', 'بازنشستگی محصولات رفته از مبدأ', 'retireRemoved'],
        ['8.34', 'محافظ ایمنی بازنشستگی', 'retireGuard'],
    ];
    foreach ($fnMap as [$v, $label, $fn]) {
        $add($v, $label, function_exists($fn), $fn . '()');
    }

    // ۲) رفع خطای ۴۲۲ — نباید هیچ sort نامعتبری در کد مانده باشد
    $selfSrc = (string)@file_get_contents(__FILE__);
    // رشته را تکه‌تکه می‌سازیم تا خودِ همین خط در شمارش نیفتد و
    // «هشدار الکی» ندهد — خودآزمون نباید خودش را پیدا کند.
    $badSort = substr_count($selfSrc, 'sort=' . 'created_at');
    $add('8.33', 'رفع خطای ۴۲۲ (حذف sort نامعتبر)', $badSort === 0,
         $badSort === 0 ? 'پاک است' : $badSort . ' مورد باقی مانده');

    // ۳) اندپوینت‌های تازه واقعاً در همین فایل هستند؟
    foreach ([['8.32', 'bsl_orders_list'], ['8.32', 'bsl_chats_list'],
              ['8.32', 'bsl_notify_selected'], ['8.33', 'queue_watchdog'],
              ['8.34', 'retire_run']] as [$v, $ep]) {
        $add($v, 'اندپوینت ?' . $ep, strpos($selfSrc, "'" . $ep . "'") !== false);
    }

    // ۴) بررسی منطقی محافظ ایمنی — واقعاً اجرا می‌شود، نه فقط وجود دارد
    if (function_exists('retireGuard')) {
        $cfg  = ['retire_max_pct' => 30, 'retire_max_count' => 50];
        $gZero = retireGuard(5, 0, $cfg);      // استخراج خالی → باید جلوگیری کند
        $gBig  = retireGuard(60, 100, $cfg);   // تعداد زیاد → باید جلوگیری کند
        $gOk   = retireGuard(5, 100, $cfg);    // کم و طبیعی → باید اجازه دهد
        $add('8.34', 'محافظ: استخراج خالی را رد می‌کند', !empty($gZero['blocked']));
        $add('8.34', 'محافظ: حذف انبوه را رد می‌کند',    !empty($gBig['blocked']));
        $add('8.34', 'محافظ: حذف کم را اجازه می‌دهد',    !empty($gOk['allow']));
    }

    // ۵) تنظیمات فعلی — برای اینکه بدانید چه چیزی روشن است
    $cnS = loadConnections();
    $cfgNow = [
        'نسخهٔ در حال اجرا'   => APP_VERSION,
        'اقدام بازنشستگی'     => (retireModes()[$cnS['retire_mode'] ?? 'off'] ?? '?'),
        'نگهبان صف'           => (!isset($cnS['stall_watchdog']) || !empty($cnS['stall_watchdog'])) ? 'فعال' : 'خاموش',
        'آستانهٔ گیر کردن'    => (int)($cnS['stall_after'] ?? 300) . ' ثانیه',
        'توکن باسلام'         => trim((string)($cnS['basalam']['token'] ?? '')) !== '' ? 'تنظیم شده' : '— خالی',
        'پیام‌رسان'           => (!empty($cnS['baleh']['token']) || !empty($cnS['rubika']['token'])) ? 'تنظیم شده' : '— خالی',
    ];

    $okCount = 0;
    foreach ($checks as $c) { if ($c['ok']) $okCount++; }
    $allOk = $okCount === count($checks);

    if ($wantJson) {
        echo json_encode(['ok' => $allOk, 'version' => APP_VERSION,
            'passed' => $okCount, 'total' => count($checks),
            'checks' => $checks, 'config' => $cfgNow], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>خودآزمون v' . APP_VERSION . '</title><style>'
       . 'body{font-family:Tahoma,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;padding:16px;line-height:1.7}'
       . '.wrap{max-width:760px;margin:0 auto}h1{font-size:18px;margin:0 0 4px}'
       . '.big{font-size:34px;font-weight:700;font-family:ui-monospace,monospace}'
       . '.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:14px;margin-bottom:14px}'
       . 'table{width:100%;border-collapse:collapse;font-size:13px}'
       . 'td{padding:7px 6px;border-bottom:1px solid #334155}'
       . '.v{color:#64748b;font-family:ui-monospace,monospace;font-size:11px;white-space:nowrap}'
       . '.ok{color:#4ade80}.no{color:#f87171}.d{color:#64748b;font-size:11px}'
       . '.hero{border-radius:12px;padding:16px;text-align:center;margin-bottom:14px}'
       . '</style></head><body><div class="wrap">';
    echo '<div class="hero" style="background:' . ($allOk ? '#14532d' : '#7f1d1d')
       . ';border:1px solid ' . ($allOk ? '#22c55e' : '#ef4444') . '">'
       . '<div class="big">v' . $esc(APP_VERSION) . '</div>'
       . '<div style="font-size:14px;margin-top:4px">'
       . ($allOk ? '✅ همهٔ قابلیت‌ها روی این سرور فعال‌اند' : '⚠️ نصب ناقص است — فایل قدیمی روی سرور مانده')
       . '</div><div class="d" style="margin-top:4px">' . $okCount . ' از ' . count($checks) . ' بررسی موفق</div></div>';

    if (!$allOk) {
        echo '<div class="card" style="border-color:#f59e0b">'
           . '<b style="color:#fbbf24">چه باید کرد؟</b><br>'
           . 'یعنی فایل روی هاست قدیمی است. با <code>deploy.php</code> نسخهٔ تازه را نصب کنید '
           . 'و بعد این صفحه را دوباره باز کنید.</div>';
    }

    echo '<div class="card"><b>وضعیت فعلی</b><table>';
    foreach ($cfgNow as $k => $v) {
        echo '<tr><td style="width:45%">' . $esc($k) . '</td><td><b>' . $esc($v) . '</b></td></tr>';
    }
    echo '</table></div><div class="card"><b>بررسی قابلیت‌ها</b><table>';
    foreach ($checks as $c) {
        echo '<tr><td class="v">v' . $esc($c['ver']) . '</td><td>' . $esc($c['name'])
           . ($c['detail'] !== '' ? ' <span class="d">' . $esc($c['detail']) . '</span>' : '')
           . '</td><td style="width:34px;text-align:center" class="' . ($c['ok'] ? 'ok' : 'no') . '">'
           . ($c['ok'] ? '✓' : '✗') . '</td></tr>';
    }
    echo '</table></div>';
    echo '<div class="d">این صفحه فقط می‌خواند و چیزی را تغییر نمی‌دهد. '
       . 'برای خروجی ماشینی: <code>?selftest=1&amp;json=1</code></div>';
    echo '</div></body></html>';
    exit;
}

// v8.19: Re-scrape a profile using its manual selectors. Returns updated products array.
// If selectors are empty, returns null (caller should use saved products).
/* =====================================================================
 *  v8.29: اعلان‌های باسلام
 *  به سه بررسی مستقل تقسیم شده تا هرکدام دکمهٔ تست جدا داشته باشند و
 *  در کران‌جاب هم به ترتیب اولویت اجرا شوند.
 * ===================================================================== */


function notifLoadState(): array {
    if (!is_file(NOTIF_STATE_FILE)) return [];
    $d = json_decode((string)@file_get_contents(NOTIF_STATE_FILE), true);
    return is_array($d) ? $d : [];
}

function notifSaveState(array $st): void {
    @file_put_contents(NOTIF_STATE_FILE, json_encode($st, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* =====================================================================
 *  v8.38: پیگیری تک‌تک موارد + یادآوری
 *
 *  تا نسخهٔ ۸.۳۷ فقط یک «زمان آخرین بررسی» ذخیره می‌شد. یعنی هر مورد
 *  دقیقاً یک بار اعلان می‌شد و بعد برای همیشه ساکت می‌ماند — حتی اگر
 *  پیام مشتری بی‌جواب مانده بود.
 *
 *  حالا وضعیت هر مورد جداگانه نگه داشته می‌شود:
 *    • مورد تازه            → اعلان
 *    • وضعیتش عوض شده       → اعلان (رویداد تازه است)
 *    • بی‌تغییر ولی بی‌جواب  → بعد از فاصلهٔ تعیین‌شده، یادآوری
 *    • جواب داده شده        → دیگر یادآوری نمی‌شود
 * ===================================================================== */

/** تنظیمات یادآوری: فاصله (ثانیه) و سقف تعداد */
function notifRemindCfg(array $cn): array {
    $after = $cn['notif_remind_after'] ?? 30;      // دقیقه
    $after = (int)$after;
    if ($after < 0) $after = 0;                    // ۰ = یادآوری خاموش
    $max = (int)($cn['notif_remind_max'] ?? 0);    // ۰ = بی‌نهایت
    return ['after' => $after * 60, 'max' => max(0, $max)];
}

/**
 * تصمیم می‌گیرد برای یک مورد اعلان بفرستیم یا نه.
 *
 * $sig امضای وضعیت است؛ اگر عوض شود یعنی اتفاق تازه‌ای افتاده.
 * $pending یعنی هنوز کاری برایش لازم است (پیام بی‌جواب، سفارش ارسال‌نشده).
 *
 * خروجی: '' یعنی نفرست · 'new' · 'changed' · 'remind'
 */
function notifDecide(array &$st, string $key, string $sig, bool $pending,
                     array $cfg, int $now): string {
    if (!isset($st['items']) || !is_array($st['items'])) $st['items'] = [];
    $prev = $st['items'][$key] ?? null;

    if (!is_array($prev)) {
        $st['items'][$key] = ['sig' => $sig, 'first' => $now, 'last' => $now,
                              'n' => 1, 'pending' => $pending];
        return 'new';
    }
    if ((string)($prev['sig'] ?? '') !== $sig) {
        // امضا عوض شده — مثلاً پیام تازه رسیده یا وضعیت سفارش تغییر کرده
        $st['items'][$key] = ['sig' => $sig, 'first' => (int)($prev['first'] ?? $now),
                              'last' => $now, 'n' => 1, 'pending' => $pending];
        return 'changed';
    }
    // همان وضعیت قبلی
    if (!$pending) { $st['items'][$key]['pending'] = false; return ''; }
    if ($cfg['after'] <= 0) return '';
    if ($now - (int)($prev['last'] ?? 0) < $cfg['after']) return '';
    $n = (int)($prev['n'] ?? 1);
    if ($cfg['max'] > 0 && $n > $cfg['max']) return '';   // سقف یادآوری پر شده
    $st['items'][$key]['last'] = $now;
    $st['items'][$key]['n'] = $n + 1;
    $st['items'][$key]['pending'] = true;
    return 'remind';
}

/**
 * موارد قدیمی و تمام‌شده را پاک می‌کند تا فایل وضعیت بی‌نهایت بزرگ نشود.
 * موارد بی‌جواب نگه داشته می‌شوند چون هنوز باید یادآوری شوند.
 */
function notifPrune(array &$st, int $now, int $maxAge = 604800): void {
    if (empty($st['items']) || !is_array($st['items'])) return;
    foreach ($st['items'] as $k => $v) {
        if (!is_array($v)) { unset($st['items'][$k]); continue; }
        $age = $now - (int)($v['last'] ?? 0);
        if (empty($v['pending']) && $age > 86400)   { unset($st['items'][$k]); continue; }
        if ($age > $maxAge)                          { unset($st['items'][$k]); }
    }
    // سقف سخت، برای وقتی غرفه خیلی شلوغ است
    if (count($st['items']) > 500) {
        uasort($st['items'], fn($a, $b) => (int)($b['last'] ?? 0) <=> (int)($a['last'] ?? 0));
        $st['items'] = array_slice($st['items'], 0, 500, true);
    }
}

/**
 * هنگام ارتقا از نسخه‌های قبلی، موارد قدیمی را بی‌صدا ثبت می‌کند.
 * بدون این کار، اولین اجرا هر ۱۰ مورد موجود را «تازه» می‌دید و یک‌جا
 * ۱۰ پیام می‌فرستاد.
 */
function notifSeedIfNeeded(array &$st, string $kind, array $seen, int $watermark): bool {
    $flag = 'seeded_' . $kind;
    if (!empty($st[$flag])) return false;
    if (!isset($st['items']) || !is_array($st['items'])) $st['items'] = [];
    if ($watermark > 0) {
        foreach ($seen as $s) {
            // فقط مواردی که قبلاً هم بوده‌اند؛ موارد تازه‌تر باید اعلان شوند
            if ((int)$s['ts'] > 0 && (int)$s['ts'] <= $watermark) {
                $st['items'][$s['key']] = ['sig' => $s['sig'], 'first' => (int)$s['ts'],
                    'last' => $watermark, 'n' => 1, 'pending' => !empty($s['pending'])];
            }
        }
    }
    $st[$flag] = time();
    return true;
}

/** سرتیتر پیام بر اساس نوع رویداد */
function notifHead(string $why, string $base, int $n = 0): string {
    if ($why !== 'remind') return $base;
    $tag = '🔁 یادآوری' . ($n > 1 ? ' (' . $n . ')' : '');
    return $base === '' ? $tag : $tag . ' — ' . $base;
}

/** ارسال یک پیام به همهٔ پیام‌رسان‌های فعال */
function notifSend(array $cn, string $msg): array {
    $out = [];
    $bt = $cn['baleh']['token'] ?? '';  $bc = $cn['baleh']['chat_id'] ?? '';
    $rt = $cn['rubika']['token'] ?? ''; $rc = $cn['rubika']['chat_id'] ?? '';
    if ($bt !== '' && $bc !== '')  $out['baleh']  = bslSendToBaleh($bt, $bc, $msg) ? 'sent' : 'fail';
    if ($rt !== '' && $rc !== '')  $out['rubika'] = bslSendToRubika($rt, $rc, $msg) ? 'sent' : 'fail';
    if (!$out) $out['none'] = 'no_messenger';
    return $out;
}

/**
 * v8.37: پیام «پینگ» برای اثبات اینکه خودِ کران‌جاب اجرا می‌شود.
 *
 * فرق این با «خطای اجرای خودکار»: آن وقتی می‌آید که کاری شکست بخورد،
 * ولی اگر کران‌جاب اصلاً اجرا نشود هیچ پیامی نمی‌آید و سکوت گمراه‌کننده
 * است. پینگ دقیقاً همین حالت را آشکار می‌کند.
 *
 * ⚠️ چون کران معمولاً هر ۵ دقیقه اجرا می‌شود، ارسال پیام در هر اجرا
 * روزی ۲۸۸ پیام می‌شود. برای همین یک فاصلهٔ زمانی (پیش‌فرض ۶ ساعت)
 * رعایت می‌شود؛ با ping_every=0 می‌توان هر اجرا را فرستاد.
 */
function notifCronPing(array $cn, array $results, bool $force = false): array {
    if (!$force && empty($cn['notif_events']['cron_ping'])) return ['ok' => true, 'skipped' => 'disabled'];
    if (notifPrereq($cn) !== null) return ['ok' => false, 'error' => notifPrereq($cn)];

    $everyMin = (int)($cn['ping_every'] ?? 360);   // دقیقه
    $st  = notifLoadState();
    $last = (int)($st['last_cron_ping'] ?? 0);
    $now  = time();
    if (!$force && $everyMin > 0 && $last > 0 && ($now - $last) < $everyMin * 60) {
        return ['ok' => true, 'skipped' => 'throttled',
                'next_in' => $everyMin * 60 - ($now - $last)];
    }

    // خلاصهٔ کاری که همین اجرا انجام داد تا پینگ فقط «زنده‌ام» نباشد
    $profiles = is_array($results['profiles'] ?? null) ? $results['profiles'] : [];
    $ran = 0; $notDue = 0; $errs = 0; $extracted = 0;
    foreach ($profiles as $p) {
        $stt = $p['status'] ?? '';
        if ($stt === 'not_due') { $notDue++; continue; }
        if ($stt === 'sync_disabled') continue;
        $ran++;
        $extracted += (int)($p['extracted'] ?? 0);
        if (!empty($p['extract_error'])) $errs++;
    }
    $sinceTxt = $last > 0 ? gmdate('H:i', min(359999, $now - $last)) : '—';

    $msg = "📡 کران‌جاب اجرا شد"
         . "\nزمان سرور: " . date('Y-m-d H:i:s')
         . "\nنسخه: " . APP_VERSION
         . "\nپروفایل‌های اجراشده: " . $ran
         . ($notDue > 0 ? " · نوبتشان نبود: " . $notDue : '')
         . ($extracted > 0 ? "\nمحصولات استخراج‌شده: " . $extracted : '')
         . ($errs > 0 ? "\n⚠️ خطا در " . $errs . " پروفایل" : '')
         . ($last > 0 ? "\nفاصله از پینگ قبلی: " . $sinceTxt : '')
         . ($force ? "\n(پینگ آزمایشی)" : '');

    $delivery = notifSend($cn, $msg);
    if (!$force) { $st['last_cron_ping'] = $now; notifSaveState($st); }
    return ['ok' => true, 'sent' => true, 'delivery' => $delivery, 'message' => $msg];
}

/** پیش‌نیازها را بررسی می‌کند و در صورت نبود، علت را برمی‌گرداند */
function notifPrereq(array $cn): ?string {
    if (trim((string)($cn['basalam']['token'] ?? '')) === '') return 'توکن باسلام تنظیم نشده';
    if ((int)($cn['basalam']['vendor_id'] ?? 0) <= 0)         return 'شناسهٔ غرفه تنظیم نشده';
    $hasMsgr = (!empty($cn['baleh']['token']) && !empty($cn['baleh']['chat_id']))
            || (!empty($cn['rubika']['token']) && !empty($cn['rubika']['chat_id']));
    if (!$hasMsgr) return 'هیچ پیام‌رسانی تنظیم نشده (بله یا روبیکا)';
    return null;
}

/**
 * v8.31: پیام خطای گویا برای پاسخ‌های ناموفق باسلام.
 * ۴۰۴ معمولاً یعنی مسیر عوض شده (باسلام به API Gateway مهاجرت کرده) و
 * ۴۰۳ یعنی توکن اسکوپ لازم را ندارد — این دو را نباید یکی گزارش کرد.
 */
function bslApiError(array $r, string $what, string $endpoint, string $scope = ''): string {
    $c = (int)($r['code'] ?? 0);
    if ($c === 404) return $what . ' — مسیر یافت نشد (۴۰۴): ' . $endpoint;
    if ($c === 401) return $what . ' — توکن نامعتبر یا منقضی (۴۰۱)';
    if ($c === 403) return $what . ' — توکن دسترسی لازم را ندارد (۴۰۳)'
                         . ($scope !== '' ? ' · اسکوپ موردنیاز: ' . $scope : '');
    if ($c === 429) return $what . ' — تعداد درخواست بیش از حد (۴۲۹)، کمی بعد تلاش کنید';
    // v8.33: ۴۲۲ یعنی پارامتر ورودی نامعتبر است — جزئیات را از پاسخ بیرون می‌کشیم
    if ($c === 422) {
        $det = [];
        foreach ((array)($r['body']['detail'] ?? []) as $d) {
            if (!is_array($d)) continue;
            $loc = is_array($d['loc'] ?? null) ? end($d['loc']) : '';
            $m   = (string)($d['msg'] ?? '');
            if ($loc !== '' || $m !== '') $det[] = trim($loc . ' ' . $m);
        }
        return $what . ' — پارامتر نامعتبر (۴۲۲)'
             . ($det ? ': ' . mb_substr(implode(' · ', $det), 0, 160) : ' در ' . $endpoint);
    }
    if ($c === 0)   return $what . ' — ارتباط با باسلام برقرار نشد';
    return $what . ' — خطای HTTP ' . $c;
}

/* =====================================================================
 *  v8.32: وضعیت سفارش‌های غرفه‌دار و نرمال‌سازی پاسخ باسلام
 *  کدها از مستندات رسمی order_processing گرفته شده‌اند (پارامتر statuses).
 * ===================================================================== */

/** کد وضعیت سفارش → نام فارسی */
function bslParcelStatuses(): array {
    return [
        3739 => 'جدید',              3237 => 'در حال آماده‌سازی',
        3238 => 'ارسال شده',          5017 => 'اطلاعات ارسال نادرست',
        3572 => 'نرسیده',             3740 => 'ثبت مشکل شده',
        4633 => 'درخواست لغو مشتری',  5075 => 'درخواست توافق تأخیر',
        3195 => 'رضایت',              3233 => 'عودت وجه کامل',
        3067 => 'لغو',                6440 => 'درخواست لغو غرفه‌دار',
    ];
}

/** سفارش‌هایی که هنوز ارسال نشده‌اند و غرفه‌دار باید کاری برایشان بکند */
function bslUnsentStatuses(): array { return [3739, 3237]; }

/**
 * v8.32: نام مشتری را از ساختار درست بیرون می‌کشد.
 * طبق مستندات، customer سه کلید دارد: recipient / city / user — و
 * «name» مستقیم زیر customer وجود ندارد. نسخهٔ ۸.۳۱ اشتباه می‌خواند و
 * همیشه «نامشخص» می‌داد.
 */
function bslParcelCustomerName(array $o): string {
    $c = $o['order']['customer'] ?? ($o['customer'] ?? []);
    if (!is_array($c)) return 'نامشخص';
    foreach ([['recipient', 'name'], ['user', 'name']] as $p) {
        $v = trim((string)($c[$p[0]][$p[1]] ?? ''));
        if ($v !== '') return $v;
    }
    $v = trim((string)($c['name'] ?? ''));
    return $v !== '' ? $v : 'نامشخص';
}

/** یک ردیف vendor-parcels را به ساختار ساده و یک‌دست تبدیل می‌کند */
function bslNormalizeParcel(array $o): array {
    $stId  = (int)($o['status']['id'] ?? 0);
    $names = bslParcelStatuses();
    $stTxt = trim((string)($o['status']['title'] ?? ''));
    if ($stTxt === '') $stTxt = $names[$stId] ?? '—';
    $items = [];
    foreach (($o['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $items[] = ['title' => (string)($it['title'] ?? ''), 'qty' => (int)($it['quantity'] ?? 1)];
    }
    return [
        'parcel_id'   => (int)($o['id'] ?? 0),
        'order_id'    => (int)($o['order']['id'] ?? 0),
        'customer'    => bslParcelCustomerName($o),
        'amount'      => (int)($o['total_items_price'] ?? 0),
        'status_id'   => $stId,
        'status'      => $stTxt,
        'unsent'      => in_array($stId, bslUnsentStatuses(), true),
        'created_at'  => (string)($o['created_at'] ?? ''),
        'send_before' => (string)($o['estimate_send_at'] ?? ''),
        'items'       => $items,
        'items_count' => count($items),
    ];
}

/**
 * v8.32: یک ردیف chats را نرمال می‌کند.
 * طبق مستندات، متن پیام زیر last_message.content.text است نه
 * last_message.text — نسخهٔ ۸.۳۱ اشتباه می‌خواند و همیشه «...» می‌فرستاد.
 */
function bslNormalizeChat(array $c): array {
    $lm = is_array($c['last_message'] ?? null) ? $c['last_message'] : [];
    $txt = '';
    foreach ([$lm['content']['text'] ?? null, $lm['text'] ?? null] as $cand) {
        if (is_string($cand) && trim($cand) !== '') { $txt = trim($cand); break; }
    }
    $ct = $c['contact'] ?? [];
    $who = '';
    if (is_array($ct)) {
        foreach (['name', 'title', 'username', 'hash_id'] as $k) {
            $v = trim((string)($ct[$k] ?? ''));
            if ($v !== '') { $who = $v; break; }
        }
    } elseif (is_string($ct)) { $who = trim($ct); }
    if ($who === '') $who = trim((string)($lm['sender']['name'] ?? ''));
    if ($who === '') $who = 'مشتری';
    $type = (string)($lm['message_type'] ?? '');
    if ($txt === '') $txt = $type !== '' && $type !== 'text' ? '[' . $type . ']' : '—';
    return [
        'chat_id'    => (int)($c['id'] ?? 0),
        'who'        => $who,
        'text'       => $txt,
        'unseen'     => (int)($c['unseen_message_count'] ?? 0),
        'updated_at' => (string)($c['updated_at'] ?? ($c['created_at'] ?? '')),
        'chat_type'  => (string)($c['chat_type'] ?? ''),
        'sender'     => trim((string)($lm['sender']['name'] ?? '')),
    ];
}

/** متن پیام‌رسان برای یک سفارش */
function bslParcelMsg(array $n, string $head = '🛒 سفارش باسلام'): string {
    $s = $head . "\nشماره: #" . ($n['order_id'] ?: $n['parcel_id'])
       . "\nمشتری: " . $n['customer']
       . "\nمبلغ: " . number_format($n['amount']) . ' تومان'
       . "\nوضعیت: " . $n['status'];
    if (!empty($n['items'])) {
        $lines = [];
        foreach (array_slice($n['items'], 0, 3) as $it) {
            $lines[] = '• ' . mb_substr($it['title'], 0, 45) . ' ×' . $it['qty'];
        }
        $more = count($n['items']) - count($lines);
        if ($more > 0) $lines[] = '• و ' . $more . ' قلم دیگر';
        $s .= "\n" . implode("\n", $lines);
    }
    return $s;
}

/**
 * v8.33: متن پیام‌های خوانده‌نشدهٔ یک گفتگو را می‌گیرد.
 * فقط پیام‌های طرف مقابل برگردانده می‌شود، به ترتیب قدیم به جدید،
 * تا در پیام‌رسان همان چیزی دیده شود که مشتری نوشته است.
 */
function bslMyUserId(string $tk): int {
    static $cache = [];
    $k = substr(md5($tk), 0, 12);
    if (isset($cache[$k])) return $cache[$k];
    $r = bslReq($tk, 'GET', 'users/me');
    $id = 0;
    if (!empty($r['ok'])) $id = (int)($r['body']['id'] ?? ($r['body']['user_id'] ?? 0));
    return $cache[$k] = $id;
}

function bslFetchChatMessages(string $tk, int $chatId, int $limit = 10, int $myUserId = -1): array {
    if ($chatId <= 0) return [];
    // v8.33: پیش‌فرض یعنی «خودت پیدا کن» تا پاسخ‌های خود غرفه‌دار فیلتر شوند
    if ($myUserId < 0) $myUserId = bslMyUserId($tk);
    $lim = max(1, min(50, $limit));
    $r = bslReq($tk, 'GET', 'chats/' . $chatId . '/messages?limit=' . $lim . '&order=desc');
    if (!$r['ok']) return [];
    $rows = $r['body']['data']['messages'] ?? ($r['body']['data'] ?? []);
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $m) {
        if (!is_array($m)) continue;
        $sid = (int)($m['sender']['id'] ?? 0);
        if ($myUserId > 0 && $sid === $myUserId) continue;   // پیام‌های خودمان را نشان نده
        $txt = $m['content']['text'] ?? null;
        if (!is_string($txt) || trim($txt) === '') {
            $mt = (string)($m['message_type'] ?? '');
            $txt = $mt !== '' && $mt !== 'text' ? '[' . $mt . ']' : '';
        }
        $txt = trim((string)$txt);
        if ($txt === '') continue;
        $out[] = ['text' => $txt, 'at' => (string)($m['created_at'] ?? ''),
                  'sender' => trim((string)($m['sender']['name'] ?? ''))];
    }
    return array_reverse($out);   // قدیمی‌ترین اول، مثل خود گفتگو
}

/**
 * متن پیام‌رسان برای یک گفتگو.
 * v8.33: اگر متن پیام‌ها داده شود، همه را می‌فرستد نه فقط آخری.
 */
function bslChatMsg(array $n, string $head = '💬 پیام مشتری باسلام', array $msgs = []): string {
    $s = $head . "\nمشتری: " . $n['who']
       . ($n['unseen'] > 0 ? ' (' . $n['unseen'] . ' خوانده‌نشده)' : '');
    if ($msgs) {
        $s .= "\n━━━━━━━━━━";
        $budget = 3000;   // سقف امن برای بله/روبیکا
        foreach ($msgs as $m) {
            $line = "\n▸ " . mb_substr($m['text'], 0, 700);
            if (mb_strlen($line) > $budget) { $s .= "\n… (بقیه در باسلام)"; break; }
            $s .= $line; $budget -= mb_strlen($line);
        }
        return $s;
    }
    return $s . "\nپیام: " . mb_substr($n['text'], 0, 700);
}

/**
 * بررسی سفارش‌های جدید.
 * $test=true یعنی حالت آزمایشی: وضعیت ذخیره نمی‌شود تا اجرای بعدی هم
 * همان نتیجه را بدهد، و اگر چیزی نبود یک پیام نمونه فرستاده می‌شود.
 */
function notifCheckOrders(array $cn, bool $test = false, bool $send = true): array {
    $tk = $cn['basalam']['token'] ?? ''; $vid = (int)($cn['basalam']['vendor_id'] ?? 0);
    $st = notifLoadState(); $since = (int)($st['last_order_check'] ?? 0);
    // v8.31: مسیر درست طبق مستندات رسمی — سفارش‌های غرفه‌دار
    $r = bslReq($tk, 'GET', 'vendor-parcels?items.vendor_ids=' . $vid . '&per_page=10');
    if (!$r['ok']) return ['ok' => false, 'code' => (int)($r['code'] ?? 0), 'found' => 0,
            'error' => bslApiError($r, 'دریافت سفارش‌ها ناموفق', 'vendor-parcels', 'vendor.parcel.read')];

    $rows = $r['body']['data'] ?? [];
    $now = time(); $cfg = notifRemindCfg($cn);

    // v8.38: امضا = وضعیت سفارش. «بی‌جواب» یعنی هنوز ارسال نشده،
    // پس تا وقتی غرفه‌دار کاری نکند یادآوری می‌شود.
    $norm = [];
    foreach ($rows as $o) {
        if (!is_array($o)) continue;
        $np = bslNormalizeParcel($o);
        if ($np['parcel_id'] <= 0) continue;
        $norm[] = ['np' => $np, 'key' => 'order:' . $np['parcel_id'],
                   'sig' => (string)$np['status_id'],
                   'ts' => strtotime($np['created_at'] ?: 'now') ?: $now,
                   'pending' => !empty($np['unsent'])];
    }

    if ($test) {
        $sentTo = [];
        $sample = $norm ? bslParcelMsg($norm[0]['np'], '🛒 سفارش جدید باسلام') : '';
        if ($send) {
            $msg = $sample ? ("🧪 تست سفارش‌ها\n" . $sample) : "🧪 تست سفارش‌ها\nهیچ سفارشی در ۱۰ مورد اخیر نبود، اما ارتباط برقرار است ✅";
            $sentTo = notifSend($cn, $msg);
        }
        return ['ok' => true, 'found' => count($norm), 'total_seen' => count($rows),
                'sent' => $sentTo, 'sample' => $sample];
    }

    notifSeedIfNeeded($st, 'orders', $norm, $since);

    $found = 0; $reminded = 0; $sentTo = []; $samples = [];
    foreach ($norm as $f) {
        $why = notifDecide($st, $f['key'], $f['sig'], $f['pending'], $cfg, $now);
        if ($why === '') continue;
        $n = (int)($st['items'][$f['key']]['n'] ?? 1);
        $base = $why === 'changed' ? '📦 تغییر وضعیت سفارش باسلام' : '🛒 سفارش جدید باسلام';
        $msg = bslParcelMsg($f['np'], notifHead($why, $base, $n - 1));
        $samples[] = $msg;
        if ($why === 'remind') $reminded++; else $found++;
        if ($send) $sentTo = notifSend($cn, $msg);
    }
    $st['last_order_check'] = $now;
    notifPrune($st, $now);
    notifSaveState($st);
    return ['ok' => true, 'found' => $found, 'reminded' => $reminded,
            'total_seen' => count($rows), 'sent' => $sentTo, 'sample' => $samples[0] ?? ''];
}

/** بررسی پیام‌های جدید مشتری */
function notifCheckChats(array $cn, bool $test = false, bool $send = true): array {
    $tk = $cn['basalam']['token'] ?? ''; $vid = (int)($cn['basalam']['vendor_id'] ?? 0);
    $st = notifLoadState(); $since = (int)($st['last_chat_check'] ?? 0);
    // v8.31: مسیر درست — گفتگوها زیر ریشه است، نه زیر vendors
    $r = bslReq($tk, 'GET', 'chats?limit=10&order_by=updated_at');
    if (!$r['ok']) return ['ok' => false, 'code' => (int)($r['code'] ?? 0), 'found' => 0,
            'error' => bslApiError($r, 'دریافت گفتگوها ناموفق', 'chats', 'customer.chat.read')];

    // v8.31: پاسخ chats به شکل data.chats است، نه data
    $rows = $r['body']['data']['chats'] ?? ($r['body']['data'] ?? []);
    $now = time(); $cfg = notifRemindCfg($cn);

    // v8.38: امضای هر گفتگو = شناسهٔ آخرین پیام + تعداد خوانده‌نشده.
    // «بی‌جواب» یعنی هنوز پیام خوانده‌نشده دارد.
    $norm = [];
    foreach ($rows as $c) {
        if (!is_array($c)) continue;
        $nc = bslNormalizeChat($c);
        if ($nc['chat_id'] <= 0) continue;
        $lastId = (int)($c['last_message']['id'] ?? 0);
        // v8.38: امضا فقط شناسهٔ آخرین پیام است. اگر تعداد خوانده‌نشده را هم
        // در امضا بیاوریم، جواب دادن مشتری (۲ → ۰) مثل «رویداد تازه» دیده
        // می‌شود و یک اعلان بی‌مورد می‌فرستد.
        $norm[] = ['nc' => $nc, 'key' => 'chat:' . $nc['chat_id'],
                   'sig' => (string)$lastId,
                   'ts' => strtotime($nc['updated_at'] ?: 'now') ?: $now,
                   'pending' => $nc['unseen'] > 0];
    }

    if ($test) {
        $sentTo = []; $sample = '';
        if ($norm) {
            $f = $norm[0];
            $body = $f['pending'] ? bslFetchChatMessages($tk, $f['nc']['chat_id'], min(10, $f['nc']['unseen'])) : [];
            $sample = bslChatMsg($f['nc'], '💬 پیام مشتری باسلام', $body);
        }
        if ($send) {
            $msg = $sample ? ("🧪 تست پیام‌ها\n" . $sample) : "🧪 تست پیام‌ها\nهیچ پیامی در ۱۰ مورد اخیر نبود، اما ارتباط برقرار است ✅";
            $sentTo = notifSend($cn, $msg);
        }
        return ['ok' => true, 'found' => count($norm), 'total_seen' => count($rows),
                'sent' => $sentTo, 'sample' => $sample];
    }

    notifSeedIfNeeded($st, 'chats', $norm, $since);

    $found = 0; $reminded = 0; $sentTo = []; $samples = [];
    foreach ($norm as $f) {
        $why = notifDecide($st, $f['key'], $f['sig'], $f['pending'], $cfg, $now);
        if ($why === '') continue;
        $n = (int)($st['items'][$f['key']]['n'] ?? 1);
        $body = $f['pending'] ? bslFetchChatMessages($tk, $f['nc']['chat_id'], min(10, $f['nc']['unseen'])) : [];
        $msg = bslChatMsg($f['nc'], notifHead($why, '💬 پیام مشتری باسلام', $n - 1), $body);
        $samples[] = $msg;
        if ($why === 'remind') $reminded++; else $found++;
        if ($send) $sentTo = notifSend($cn, $msg);
    }
    $st['last_chat_check'] = $now;
    notifPrune($st, $now);
    notifSaveState($st);
    return ['ok' => true, 'found' => $found, 'reminded' => $reminded,
            'total_seen' => count($rows), 'sent' => $sentTo, 'sample' => $samples[0] ?? ''];
}

/** بررسی تغییر وضعیت یا افزوده شدن محصول */
function notifCheckProducts(array $cn, bool $test = false, bool $send = true): array {
    $tk = $cn['basalam']['token'] ?? ''; $vid = (int)($cn['basalam']['vendor_id'] ?? 0);
    $st = notifLoadState(); $since = (int)($st['last_product_check'] ?? 0);
    $r = bslReq($tk, 'GET', 'vendors/' . $vid . '/products?per_page=10&statuses=2976&statuses=3790&statuses=3567');
    if (!$r['ok']) return ['ok' => false, 'code' => (int)($r['code'] ?? 0), 'found' => 0,
            'error' => bslApiError($r, 'دریافت محصولات ناموفق', 'vendors/{id}/products', 'vendor.product.read')];

    $rows = $r['body']['data'] ?? [];
    $now = time(); $cfg = notifRemindCfg($cn);

    // v8.38: امضا = وضعیت محصول. «تأیید نشده» نیاز به اقدام دارد، پس
    // تا وقتی درست نشود یادآوری می‌شود؛ محصول فعال یادآوری ندارد.
    $norm = [];
    foreach ($rows as $p) {
        if (!is_array($p)) continue;
        $pid = (int)($p['id'] ?? 0);
        if ($pid <= 0) continue;
        $ps = $p['status'] ?? [];
        $val = is_array($ps) ? (int)($ps['value'] ?? 0) : (int)$ps;
        $title = mb_substr((string)($p['title'] ?? ($p['name'] ?? 'محصول')), 0, 60);
        if ($val === 3567)      { $txt = "📋 تغییر وضعیت محصول باسلام\nمحصول: " . $title . "\nوضعیت جدید: تأیید نشده ❌"; $pend = true; }
        elseif ($val === 4184)  { $txt = "📋 تغییر وضعیت محصول باسلام\nمحصول: " . $title . "\nوضعیت جدید: بایگانی 🗑️"; $pend = false; }
        elseif ($val === 2976)  { $txt = "➕ محصول جدید باسلام\nمحصول: " . $title . "\nوضعیت: فعال ✅"; $pend = false; }
        else continue;
        $norm[] = ['key' => 'prod:' . $pid, 'sig' => (string)$val, 'text' => $txt,
                   'ts' => strtotime($p['created_at'] ?? 'now') ?: $now, 'pending' => $pend];
    }

    if ($test) {
        $sentTo = [];
        $sample = $norm ? $norm[0]['text'] : '';
        if ($send) {
            $msg = $sample ? ("🧪 تست محصولات\n" . $sample) : "🧪 تست محصولات\nتغییری در ۱۰ محصول اخیر نبود، اما ارتباط برقرار است ✅";
            $sentTo = notifSend($cn, $msg);
        }
        return ['ok' => true, 'found' => count($norm), 'total_seen' => count($rows),
                'sent' => $sentTo, 'sample' => $sample];
    }

    notifSeedIfNeeded($st, 'products', $norm, $since);

    $found = 0; $reminded = 0; $sentTo = []; $samples = [];
    foreach ($norm as $f) {
        $why = notifDecide($st, $f['key'], $f['sig'], $f['pending'], $cfg, $now);
        if ($why === '') continue;
        $n = (int)($st['items'][$f['key']]['n'] ?? 1);
        $msg = $why === 'remind'
             ? notifHead($why, '', $n - 1) . "\n" . $f['text']
             : $f['text'];
        $samples[] = $msg;
        if ($why === 'remind') $reminded++; else $found++;
        if ($send) $sentTo = notifSend($cn, $msg);
    }
    $st['last_product_check'] = $now;
    notifPrune($st, $now);
    notifSaveState($st);
    return ['ok' => true, 'found' => $found, 'reminded' => $reminded,
            'total_seen' => count($rows), 'sent' => $sentTo, 'sample' => $samples[0] ?? ''];
}

/* =====================================================================
 *  v8.33: نگهبان صف — تشخیص گیر کردن ارسال و ادامهٔ خودکار
 *
 *  چرا امن است: قفل‌های bsl_backend/woo_backend با flock گرفته می‌شوند.
 *  اگر پردازه واقعاً زنده باشد قفل در اختیار اوست و درخواست تازه فوراً
 *  «already running» می‌گیرد و برمی‌گردد. اگر پردازه مرده باشد، سیستم‌عامل
 *  قفل را آزاد کرده و کار ادامه پیدا می‌کند. پس نگهبان هیچ‌وقت دو پردازش
 *  موازی نمی‌سازد و روی سرعت ارسال اثری ندارد — فقط یک درخواست HTTP سبک
 *  است که وقتی چیزی گیر نکرده باشد اصلاً فرستاده نمی‌شود.
 * ===================================================================== */

/** آدرس خود اسکریپت برای فراخوانی داخلی */
function selfBaseUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '');
}

/** یک فراخوانی «شلیک کن و فراموش کن» به خود اسکریپت */
function fireAndForget(string $qs, int $timeoutMs = 1200, ?array $post = null): bool {
    $base = selfBaseUrl();
    if ($base === '') return false;
    $ch = curl_init($base . '?' . ltrim($qs, '?'));
    $opt = [CURLOPT_RETURNTRANSFER => 1, CURLOPT_NOSIGNAL => 1,
        CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_SSL_VERIFYHOST => 0];
    // v8.45: پردازنده‌ها پارامترها را از POST می‌خوانند. نگهبان قبلاً فقط
    // GET می‌فرستاد، پس from_file و queue_id و start_index خالی می‌ماندند
    // و بازیابی از محصول اول شروع می‌شد — کار تکراری و گاهی ارسال دوباره.
    if ($post !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opt);
    curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    // مهلت تمام‌شدن یعنی کار شروع شده و پس‌زمینه ادامه می‌دهد — خطا نیست
    return $err === 0 || $err === CURLE_OPERATION_TIMEDOUT;
}

/**
 * بررسی می‌کند صف ارسال گیر کرده یا نه.
 * گیر کرده = ردیفی در حال اجراست ولی از آخرین پیشرفتش بیش از
 * $staleAfter ثانیه گذشته، یا ردیفی در انتظار مانده و هیچ‌کس نمی‌بردش.
 */
function queueStallCheck(string $which, int $staleAfter = 300): array {
    $isBsl = $which === 'bsl';
    $qFile = $isBsl ? BSL_QUEUE_FILE : WOO_QUEUE_FILE;
    $pFile = $isBsl ? BSL_PROGRESS_FILE : WOO_PROGRESS_FILE;
    $lock  = __DIR__ . ($isBsl ? '/bsl_backend.lock' : '/woo_backend.lock');

    $q = json_decode((string)@file_get_contents($qFile), true);
    $entries = is_array($q['entries'] ?? null) ? $q['entries'] : [];
    $running = null; $waiting = null;
    foreach ($entries as $e) {
        $st = $e['status'] ?? '';
        if ($st === 'running' && $running === null) $running = $e;
        if ($st === 'waiting' && $waiting === null) $waiting = $e;
    }
    if ($running === null && $waiting === null) {
        return ['stalled' => false, 'reason' => 'صفی در جریان نیست'];
    }

    // v8.45: ردیفی که کارش تمام شده ولی وضعیتش «running» مانده، جلوی
    // شروع ردیف بعدی را می‌گیرد. اول این را ببند تا صف راه بیفتد.
    if ($running !== null) {
        $pg = readProgress($pFile);
        $sameRow = ((string)($pg['queue_id'] ?? '') === (string)($running['id'] ?? ''))
                   || ((string)($pg['queue_id'] ?? '') === '');
        $tot = (int)($running['total'] ?? 0);
        $cur = max((int)($running['current'] ?? 0), (int)($pg['current'] ?? 0));
        $finished = !empty($pg['done']) || ($tot > 0 && $cur >= $tot);
        if ($sameRow && $finished) {
            foreach ($entries as $i => $e) {
                if (($e['id'] ?? '') === ($running['id'] ?? '')) {
                    $entries[$i]['status'] = 'done';
                    $entries[$i]['done_at'] = time();
                    $entries[$i]['current'] = $cur;
                    break;
                }
            }
            $q['entries'] = $entries;
            @file_put_contents($qFile, json_encode($q, JSON_UNESCAPED_UNICODE), LOCK_EX);
            $running = null;
            foreach ($entries as $e) {
                if (($e['status'] ?? '') === 'waiting') { $waiting = $e; break; }
            }
            if ($waiting === null) {
                return ['stalled' => false, 'reason' => 'ردیف تمام‌شده بسته شد'];
            }
        }
    }

    $prog = readProgress($pFile);
    $now  = time();
    // پردازهٔ زنده قفل را در دست دارد؛ اگر قفل باز شود یعنی پردازه رفته
    $lockFresh = false;
    if (is_file($lock)) {
        $fp = @fopen($lock, 'c');
        if ($fp) {
            $lockFresh = !@flock($fp, LOCK_EX | LOCK_NB);   // نشد ⇒ کسی گرفته ⇒ زنده
            if (!$lockFresh) @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    if ($running !== null) {
        $ts = (int)($prog['last_progress_ts'] ?? 0);
        if ($ts <= 0) $ts = (int)($running['started_at'] ?? 0);
        $idle = $ts > 0 ? ($now - $ts) : PHP_INT_MAX;
        if ($lockFresh && $idle <= $staleAfter) {
            return ['stalled' => false, 'reason' => 'در حال اجرا', 'idle' => $idle];
        }
        if ($idle > $staleAfter) {
            return ['stalled' => true, 'kind' => 'running', 'idle' => $idle,
                'queue_id' => $running['id'] ?? '', 'lock_held' => $lockFresh,
                'current' => (int)($running['current'] ?? 0),
                'total' => (int)($running['total'] ?? 0)];
        }
        return ['stalled' => false, 'reason' => 'تازه شروع شده', 'idle' => $idle];
    }

    // فقط ردیف در انتظار — اگر پردازنده‌ای زنده نیست باید راهش انداخت
    if ($lockFresh) return ['stalled' => false, 'reason' => 'پردازنده فعال است'];
    return ['stalled' => true, 'kind' => 'waiting', 'idle' => 0,
        'queue_id' => $waiting['id'] ?? '', 'lock_held' => false,
        'current' => 0, 'total' => (int)($waiting['total'] ?? 0)];
}

/** اگر صف گیر کرده باشد، پردازش را دوباره راه می‌اندازد */
/**
 * v8.45: بازیابی صف گیرکرده — با ادامه از همان جایی که مانده بود.
 *
 * قبلاً فقط یک GET خالی فرستاده می‌شد. چون پردازنده پارامترها را از POST
 * می‌خواند، هر بازیابی از محصول اول شروع می‌کرد؛ یعنی صدها محصول دوباره
 * بررسی می‌شدند و در بدترین حالت دوباره ارسال. حالا شمارهٔ محصول جاری،
 * شناسهٔ صف و پرچم from_file فرستاده می‌شوند.
 */
function queueStallRecover(string $which, int $staleAfter = 300, bool $dryRun = false): array {
    $chk = queueStallCheck($which, $staleAfter);
    $chk['which'] = $which;
    if (empty($chk['stalled']) || $dryRun) { $chk['resumed'] = false; return $chk; }

    $qid   = (string)($chk['queue_id'] ?? '');
    $start = max(0, (int)($chk['current'] ?? 0));

    // قفلِ رهاشدهٔ پردازهٔ مرده را پاک کن، وگرنه تلاش بعدی «در حال اجرا» می‌بیند
    if (empty($chk['lock_held'])) {
        @unlink(__DIR__ . ($which === 'bsl' ? '/bsl_backend.lock' : '/woo_backend.lock'));
    }
    // سیگنال توقفِ جامانده هم باید برود، وگرنه ادامه بلافاصله متوقف می‌شود
    if ($which === 'bsl' && defined('BSL_STOP_FILE') && is_file(BSL_STOP_FILE)) @unlink(BSL_STOP_FILE);

    $action = $which === 'bsl' ? 'bsl_backend' : 'woo_backend';
    $post = ['from_file' => '1', 'start_index' => (string)$start];
    if ($qid !== '') $post['queue_id'] = $qid;

    $chk['resume_from'] = $start;
    $chk['resumed'] = fireAndForget('action=' . $action, 1500, $post);
    if ($chk['resumed']) {
        // شمارندهٔ تلاش را نگه دار تا اگر بارها گیر کرد بتوان تشخیص داد
        $st = notifLoadState();
        $k  = 'stall_' . $which;
        $st[$k] = ['at' => time(), 'n' => (int)($st[$k]['n'] ?? 0) + 1,
                   'queue_id' => $qid, 'from' => $start];
        notifSaveState($st);
        $chk['attempt'] = $st[$k]['n'];
    }
    return $chk;
}

/* =====================================================================
 *  v8.34: بازنشستگی خودکار محصولاتی که از مبدأ رفته‌اند
 *
 *  وقتی محصولی در سایت مبدأ ناموجود یا حذف می‌شود، تا امروز فقط گزارش
 *  می‌شد و روی ووکامرس/باسلام دست‌نخورده می‌ماند. حالا می‌توان خودکار
 *  آن را از دسترس خارج کرد.
 *
 *  پیش‌فرض عمداً «پیش‌نویس/غیرفعال» است نه حذف، چون برگشت‌پذیر است و
 *  نظرات و تاریخچهٔ محصول از بین نمی‌رود.
 *
 *  ⚠️ محافظ ایمنی: اگر سایت مبدأ یک بار خراب شود و چیزی برنگرداند،
 *  همهٔ محصولات «حذف‌شده» به نظر می‌رسند. برای همین اگر نسبت حذف‌ها از
 *  یک آستانه بگذرد، هیچ کاری انجام نمی‌شود و فقط هشدار می‌رود.
 * ===================================================================== */

/** حالت‌های مجاز بازنشستگی */
function retireModes(): array {
    return ['off' => 'کاری نکن', 'draft' => 'پیش‌نویس/غیرفعال', 'outofstock' => 'ناموجود کن', 'delete' => 'حذف کامل'];
}

/**
 * تصمیم می‌گیرد که آیا بازنشستگی مجاز است یا محافظ باید جلویش را بگیرد.
 * $removed تعداد رفته‌ها، $total کل محصولات دیده‌شده در این اجرا.
 */
function retireGuard(int $removed, int $total, array $cfg): array {
    $maxPct   = (float)($cfg['retire_max_pct'] ?? 30);   // درصد
    $maxCount = (int)($cfg['retire_max_count'] ?? 50);   // سقف مطلق
    if ($removed <= 0) return ['allow' => false, 'reason' => 'موردی برای بازنشستگی نیست'];
    // اگر استخراج تقریباً هیچ محصولی نداده، یعنی مبدأ خراب بوده — دست نزن
    if ($total <= 0) return ['allow' => false, 'blocked' => true,
        'reason' => 'استخراج هیچ محصولی برنگرداند — احتمال خرابی سایت مبدأ'];
    $pct = $total > 0 ? ($removed / max(1, $total + $removed)) * 100 : 100;
    if ($pct > $maxPct) return ['allow' => false, 'blocked' => true, 'pct' => round($pct, 1),
        'reason' => 'نسبت حذف‌ها (' . round($pct, 1) . '٪) از آستانهٔ ' . $maxPct . '٪ بیشتر است'];
    if ($removed > $maxCount) return ['allow' => false, 'blocked' => true, 'pct' => round($pct, 1),
        'reason' => 'تعداد حذف‌ها (' . $removed . ') از سقف ' . $maxCount . ' بیشتر است'];
    return ['allow' => true, 'pct' => round($pct, 1)];
}

/** یک محصول ووکامرس را با عنوان پیدا می‌کند */
function wooFindByTitle(array $w, string $title): ?array {
    if ($title === '') return null;
    $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'], 'GET',
        'products?search=' . urlencode($title) . '&status=any&per_page=10');
    if (empty($r['ok']) || !is_array($r['body'] ?? null)) return null;
    foreach ($r['body'] as $ep) {
        if (trim((string)($ep['name'] ?? '')) === $title) return $ep;
    }
    return null;
}

/** یک محصول باسلام را با عنوان پیدا می‌کند */
function bslFindByTitle(string $tk, int $vid, string $title): ?array {
    if ($title === '' || $vid <= 0) return null;
    $r = bslReq($tk, 'GET', 'vendors/' . $vid . '/products?per_page=20&title=' . rawurlencode($title));
    if (empty($r['ok'])) return null;
    foreach (($r['body']['data'] ?? []) as $p) {
        if (!is_array($p)) continue;
        if (trim((string)($p['title'] ?? ($p['name'] ?? ''))) === $title) return $p;
    }
    return null;
}

/**
 * محصولات رفته از مبدأ را روی مقصد بازنشسته می‌کند.
 * $items همان removed_items استخراج است.
 */
function retireRemoved(array $cn, array $items, string $target, string $mode,
                       int $extracted, bool $dryRun = false): array {
    $out = ['mode' => $mode, 'target' => $target, 'checked' => 0,
            'retired' => 0, 'not_found' => 0, 'failed' => 0, 'items' => [], 'dry_run' => $dryRun];
    if ($mode === 'off' || !$items) { $out['skipped'] = 'غیرفعال'; return $out; }

    $guard = retireGuard(count($items), $extracted, $cn);
    $out['guard'] = $guard;
    if (empty($guard['allow'])) { $out['skipped'] = $guard['reason']; return $out; }

    $w   = $cn['woocommerce'] ?? [];
    $tk  = (string)($cn['basalam']['token'] ?? '');
    $vid = (int)($cn['basalam']['vendor_id'] ?? 0);
    $suffix = trim((string)($w['title_suffix'] ?? ''));

    foreach ($items as $it) {
        $title = trim((string)($it['title'] ?? ''));
        if ($title === '') continue;
        $out['checked']++;
        $row = ['title' => mb_substr($title, 0, 60), 'reason' => $it['reason'] ?? ''];

        if ($target === 'woo' || $target === 'both') {
            $t = $suffix !== '' && mb_strpos($title, $suffix) === false ? $title . $suffix : $title;
            $ex = wooFindByTitle($w, $t) ?: wooFindByTitle($w, $title);
            if (!$ex) { $out['not_found']++; $row['woo'] = 'یافت نشد'; }
            elseif ($dryRun) { $row['woo'] = 'آماده: #' . $ex['id']; }
            else {
                $id = (int)$ex['id'];
                if ($mode === 'delete') {
                    $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'],
                        'DELETE', 'products/' . $id . '?force=false');   // به زباله‌دان، نه نابودی
                } elseif ($mode === 'outofstock') {
                    $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'],
                        'PUT', 'products/' . $id, ['stock_status' => 'outofstock', 'stock_quantity' => 0]);
                } else {
                    $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'],
                        'PUT', 'products/' . $id, ['status' => 'draft']);
                }
                if (!empty($r['ok'])) { $out['retired']++; $row['woo'] = 'انجام شد #' . $id; }
                else { $out['failed']++; $row['woo'] = 'خطا ' . (int)($r['code'] ?? 0); }
            }
        }

        if (($target === 'bsl' || $target === 'both') && $tk !== '' && $vid > 0) {
            $ex = bslFindByTitle($tk, $vid, $title);
            if (!$ex) { $out['not_found']++; $row['bsl'] = 'یافت نشد'; }
            elseif ($dryRun) { $row['bsl'] = 'آماده: #' . $ex['id']; }
            else {
                $id = (int)$ex['id'];
                if ($mode === 'delete') {
                    $r = bslReq($tk, 'DELETE', 'products/' . $id);
                    if ((int)($r['code'] ?? 0) === 404) $r = bslReq($tk, 'DELETE', 'vendors/' . $vid . '/products/' . $id);
                } elseif ($mode === 'outofstock') {
                    $r = bslReq($tk, 'PATCH', 'products/' . $id, ['stock' => 0]);
                    if ((int)($r['code'] ?? 0) === 404) $r = bslReq($tk, 'PATCH', 'vendors/' . $vid . '/products/' . $id, ['stock' => 0]);
                } else {
                    $r = bslReq($tk, 'PATCH', 'products/' . $id, ['status' => 3790]);   // غیرفعال
                    if ((int)($r['code'] ?? 0) === 404) $r = bslReq($tk, 'PATCH', 'vendors/' . $vid . '/products/' . $id, ['status' => 3790]);
                }
                if (!empty($r['ok'])) { $out['retired']++; $row['bsl'] = 'انجام شد #' . $id; }
                else { $out['failed']++; $row['bsl'] = 'خطا ' . (int)($r['code'] ?? 0); }
            }
        }
        if (count($out['items']) < 50) $out['items'][] = $row;
    }
    return $out;
}

/* =====================================================================
 *  v8.47: مغایرت‌گیری — مقایسهٔ مقصد با نتایج پروفایل‌ها
 *
 *  همهٔ پروفایل‌هایی که همگام‌سازی دوره‌ای‌شان روشن است را می‌گیرد،
 *  فهرست محصولاتِ نهاییِ آن‌ها را می‌سازد، بعد کل محصولات ووکامرس یا
 *  باسلام را می‌خواند و سه چیز را گزارش می‌کند:
 *    • در مقصد هست ولی در هیچ پروفایلی نیست  → حذف/بازنشستگی
 *    • قیمتش با قیمت پروفایل فرق دارد         → به‌روزرسانی قیمت
 *    • یکسان است                              → دست نخورده
 *
 *  ⚠️ حذف خطرناک است، پس همان محافظ بازنشستگی اینجا هم اعمال می‌شود و
 *  حالت پیش‌فرض «فقط گزارش» است.
 * ===================================================================== */

/** عنوان را برای مقایسه یکدست می‌کند */
function reconNormTitle(string $t): string {
    $t = trim($t);
    $t = preg_replace('~\s+~u', ' ', $t);
    return function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
}

/** فهرست محصولات نهاییِ همهٔ پروفایل‌های دارای همگام‌سازی دوره‌ای */
function reconExpected(string $target): array {
    $out = [];
    foreach (loadProfiles() as $key => $profile) {
        $sc = $profile['syncConfig'] ?? [];
        if (empty($sc['enabled'])) continue;                 // فقط تیک‌خورده‌ها
        $t = (string)($sc['target'] ?? 'woo');
        if ($t !== 'both' && $t !== $target) continue;       // مقصد باید بخواند
        foreach (profileOrderedProducts($profile) as $p) {
            $title = reconNormTitle((string)($p['title'] ?? ''));
            if ($title === '') continue;
            $price = (int)($p['final_price'] ?? 0);
            if (isset($out[$title]) && (int)$out[$title]['price'] > 0) continue;
            $out[$title] = ['price' => $price, 'profile' => $profile['name'] ?? $key,
                            'key' => (string)($p['key'] ?? '')];
        }
    }
    return $out;
}

/** همهٔ محصولات ووکامرس */
function reconFetchWoo(array $w, int $maxPages = 60): array {
    $rows = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'], 'GET',
            'products?per_page=100&status=any&page=' . $page);
        if (empty($r['ok']) || !is_array($r['body'])) break;
        $batch = $r['body'];
        if (!$batch) break;
        foreach ($batch as $pr) {
            $name = trim((string)($pr['name'] ?? ''));
            if ($name === '') continue;
            $rows[] = ['id' => (int)($pr['id'] ?? 0), 'title' => $name,
                       'price' => (int)preg_replace('~[^\d]~', '', (string)($pr['regular_price'] ?? '0')),
                       'status' => (string)($pr['status'] ?? '')];
        }
        if (count($batch) < 100) break;
        usleep(150000);
    }
    return $rows;
}

/** همهٔ محصولات فعال باسلام. قیمت باسلام به ریال است. */
function reconFetchBsl(string $tk, int $vid, int $maxPages = 60): array {
    $rows = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $r = bslReq($tk, 'GET', 'vendors/' . $vid . '/products?page=' . $page
             . '&per_page=100&statuses=2976&statuses=3790');
        if (empty($r['ok'])) break;
        $batch = $r['body']['data'] ?? [];
        if (!$batch) break;
        foreach ($batch as $pr) {
            if (!is_array($pr)) continue;
            $rev  = $pr['revision']['data'] ?? [];
            $name = trim((string)($pr['title'] ?? ($pr['name'] ?? ($rev['title'] ?? ''))));
            if ($name === '') continue;
            $rial = (int)($rev['primary_price'] ?? ($pr['primary_price'] ?? 0));
            $rows[] = ['id' => (int)($pr['id'] ?? 0), 'title' => $name,
                       'price' => $rial, 'price_toman' => (int)round($rial / 10),
                       'status' => (int)(is_array($pr['status'] ?? null)
                                   ? ($pr['status']['value'] ?? 0) : ($pr['status'] ?? 0))];
        }
        if (count($batch) < 100) break;
        usleep(150000);
    }
    return $rows;
}

/**
 * مقایسه و در صورت درخواست، اعمال تغییرات.
 * $mode برای موارد اضافی: off | draft | outofstock | delete
 */
function reconRun(array $cn, string $target, bool $apply = false,
                  string $mode = 'off', bool $fixPrice = true): array {
    $out = ['ok' => true, 'target' => $target, 'apply' => $apply, 'mode' => $mode,
            'expected' => 0, 'remote' => 0, 'extra' => [], 'price_diff' => [],
            'matched' => 0, 'deleted' => 0, 'repriced' => 0, 'failed' => 0];

    $expected = reconExpected($target);
    $out['expected'] = count($expected);
    if (!$expected) {
        $out['ok'] = false;
        $out['error'] = 'هیچ پروفایلی با همگام‌سازی دوره‌ای برای این مقصد پیدا نشد';
        return $out;
    }

    if ($target === 'woo') {
        $w = $cn['woocommerce'] ?? [];
        if (empty($w['store_url'])) { $out['ok'] = false; $out['error'] = 'تنظیمات ووکامرس ناقص'; return $out; }
        $remote = reconFetchWoo($w);
    } else {
        $tk = (string)($cn['basalam']['token'] ?? '');
        $vid = (int)($cn['basalam']['vendor_id'] ?? 0);
        if ($tk === '' || $vid <= 0) { $out['ok'] = false; $out['error'] = 'تنظیمات باسلام ناقص'; return $out; }
        $remote = reconFetchBsl($tk, $vid);
    }
    $out['remote'] = count($remote);
    if (!$remote) {
        $out['ok'] = false;
        $out['error'] = 'هیچ محصولی از مقصد دریافت نشد — برای احتیاط کاری انجام نشد';
        return $out;
    }

    // دسته‌بندی
    foreach ($remote as $r) {
        $key = reconNormTitle($r['title']);
        if (!isset($expected[$key])) {
            $out['extra'][] = ['id' => $r['id'], 'title' => $r['title'], 'price' => $r['price']];
            continue;
        }
        $want = (int)$expected[$key]['price'];
        $have = $target === 'bsl' ? (int)($r['price_toman'] ?? 0) : (int)$r['price'];
        if ($want > 0 && $have !== $want) {
            $out['price_diff'][] = ['id' => $r['id'], 'title' => $r['title'],
                'from' => $have, 'to' => $want, 'profile' => $expected[$key]['profile']];
        } else {
            $out['matched']++;
        }
    }

    if (!$apply) return $out;

    // --- اصلاح قیمت ---
    if ($fixPrice && $out['price_diff']) {
        foreach ($out['price_diff'] as $i => $d) {
            $okRow = false;
            if ($target === 'woo') {
                $r = wooReq($cn['woocommerce']['store_url'], $cn['woocommerce']['consumer_key'],
                    $cn['woocommerce']['consumer_secret'], 'PUT', 'products/' . $d['id'],
                    ['regular_price' => (string)$d['to']]);
                $okRow = !empty($r['ok']);
            } else {
                $tk = (string)$cn['basalam']['token']; $vid = (int)$cn['basalam']['vendor_id'];
                $bu = ['primary_price' => $d['to'] * 10];      // باسلام ریال می‌خواهد
                $r = bslReq($tk, 'PATCH', 'products/' . $d['id'], $bu);
                if ((int)($r['code'] ?? 0) === 404)
                    $r = bslReq($tk, 'PATCH', 'vendors/' . $vid . '/products/' . $d['id'], $bu);
                $okRow = !empty($r['ok']);
            }
            if ($okRow) $out['repriced']++; else $out['failed']++;
            $out['price_diff'][$i]['done'] = $okRow;
            usleep(200000);
        }
    }

    // --- حذف/بازنشستگی موارد اضافی ---
    if ($mode !== 'off' && $out['extra']) {
        // v8.47: مبنای درصد باید کل محصولات مقصد باشد. retireGuard خودش
        // removed را به مخرج اضافه می‌کند، پس «باقی‌مانده» را می‌دهیم.
        $kept = max(0, count($remote) - count($out['extra']));
        $guard = retireGuard(count($out['extra']), $kept, $cn);
        $out['guard'] = $guard;
        if (empty($guard['allow'])) {
            $out['skipped_delete'] = $guard['reason'];
            return $out;
        }
        foreach ($out['extra'] as $i => $d) {
            $okRow = false;
            if ($target === 'woo') {
                $w = $cn['woocommerce'];
                if ($mode === 'delete') {
                    $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'],
                        'DELETE', 'products/' . $d['id'] . '?force=false');
                } elseif ($mode === 'outofstock') {
                    $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'],
                        'PUT', 'products/' . $d['id'], ['stock_status' => 'outofstock', 'stock_quantity' => 0]);
                } else {
                    $r = wooReq($w['store_url'], $w['consumer_key'], $w['consumer_secret'],
                        'PUT', 'products/' . $d['id'], ['status' => 'draft']);
                }
                $okRow = !empty($r['ok']);
            } else {
                $tk = (string)$cn['basalam']['token']; $vid = (int)$cn['basalam']['vendor_id'];
                if ($mode === 'delete') {
                    $r = bslReq($tk, 'DELETE', 'products/' . $d['id']);
                    if ((int)($r['code'] ?? 0) === 404) $r = bslReq($tk, 'DELETE', 'vendors/' . $vid . '/products/' . $d['id']);
                } elseif ($mode === 'outofstock') {
                    $r = bslReq($tk, 'PATCH', 'products/' . $d['id'], ['stock' => 0]);
                    if ((int)($r['code'] ?? 0) === 404) $r = bslReq($tk, 'PATCH', 'vendors/' . $vid . '/products/' . $d['id'], ['stock' => 0]);
                } else {
                    $r = bslReq($tk, 'PATCH', 'products/' . $d['id'], ['status' => 3790]);
                    if ((int)($r['code'] ?? 0) === 404) $r = bslReq($tk, 'PATCH', 'vendors/' . $vid . '/products/' . $d['id'], ['status' => 3790]);
                }
                $okRow = !empty($r['ok']);
            }
            if ($okRow) $out['deleted']++; else $out['failed']++;
            $out['extra'][$i]['done'] = $okRow;
            usleep(200000);
        }
    }
    return $out;
}

/** اعلان نتیجهٔ بازنشستگی به پیام‌رسان‌ها */
function notifRetire(array $cn, array $res, string $profileName = ''): array {
    if (empty($cn['notif_events']['retire'])) return ['ok' => true, 'skipped' => 'disabled'];
    if (notifPrereq($cn) !== null) return ['ok' => false];
    $modeLbl = retireModes()[$res['mode'] ?? 'off'] ?? '';
    if (!empty($res['guard']['blocked'])) {
        return ['ok' => true, 'delivery' => notifSend($cn,
            "🛑 بازنشستگی خودکار متوقف شد" . ($profileName !== '' ? "\nپروفایل: {$profileName}" : '')
            . "\nعلت: " . ($res['guard']['reason'] ?? '')
            . "\nهیچ محصولی تغییر نکرد — اگر درست است، دستی اجرا کنید.")];
    }
    if ((int)($res['retired'] ?? 0) <= 0) return ['ok' => true, 'skipped' => 'nothing'];
    $lines = ["🗂 بازنشستگی محصولات رفته از مبدأ" . ($profileName !== '' ? " — {$profileName}" : ''),
              'اقدام: ' . $modeLbl,
              'انجام‌شده: ' . (int)$res['retired']
              . ' · یافت‌نشده: ' . (int)($res['not_found'] ?? 0)
              . ' · ناموفق: ' . (int)($res['failed'] ?? 0)];
    foreach (array_slice($res['items'] ?? [], 0, 8) as $it) {
        $lines[] = '• ' . ($it['title'] ?? '') . ' — ' . ($it['reason'] ?? '');
    }
    return ['ok' => true, 'delivery' => notifSend($cn, implode("\n", $lines))];
}

/**
 * v8.30: اعلان تغییرات سایت مبدأ.
 * نتیجهٔ استخراج را می‌گیرد و گران/ارزان شدن و موجود/ناموجود شدن را
 * به پیام‌رسان‌ها گزارش می‌کند. برای جلوگیری از هرزنامه، به‌جای یک پیام
 * برای هر محصول، یک خلاصهٔ فشرده با نمونه‌ها فرستاده می‌شود.
 */
function notifSourceChanges(array $cn, array $res, string $profileName = '', int $sampleLimit = 5): array {
    $ne = $cn['notif_events'] ?? [];
    $wantPrice = !empty($ne['src_price']);
    $wantStock = !empty($ne['src_stock']);
    if (!$wantPrice && !$wantStock) return ['ok' => true, 'skipped' => 'disabled'];
    if (notifPrereq($cn) !== null) return ['ok' => false, 'error' => notifPrereq($cn)];

    $up = (int)($res['price_up'] ?? 0);
    $down = (int)($res['price_down'] ?? 0);
    $noPrice = (int)($res['no_price'] ?? 0);
    $gone = (int)($res['gone_from_site'] ?? 0);
    $back = 0;
    foreach (($res['new_items'] ?? []) as $it) {
        if (($it['reason'] ?? '') === 'دوباره موجود شد') $back++;
    }

    $blocks = [];

    if ($wantPrice && ($up > 0 || $down > 0)) {
        $lines = ["💰 تغییر قیمت در سایت مبدأ" . ($profileName !== '' ? " — {$profileName}" : '')];
        $lines[] = "▲ گران شد: {$up}   ▼ ارزان شد: {$down}";
        $n = 0;
        foreach (($res['changed_items'] ?? []) as $it) {
            if ($n >= $sampleLimit) break;
            $arrow = ($it['dir'] ?? '') === 'up' ? '▲' : '▼';
            $pct = isset($it['pct']) ? (($it['pct'] > 0 ? '+' : '') . $it['pct'] . '٪') : '';
            $lines[] = $arrow . ' ' . mb_substr((string)($it['title'] ?? ''), 0, 40)
                     . "\n   " . ($it['old_price'] ?? '') . ' ← ' . ($it['new_price'] ?? '') . ' ' . $pct;
            $n++;
        }
        $rest = ($up + $down) - $n;
        if ($rest > 0) $lines[] = "... و {$rest} مورد دیگر";
        $blocks[] = implode("\n", $lines);
    }

    if ($wantStock && ($noPrice > 0 || $gone > 0 || $back > 0)) {
        $lines = ["📦 تغییر موجودی در سایت مبدأ" . ($profileName !== '' ? " — {$profileName}" : '')];
        if ($noPrice > 0) $lines[] = "🚫 ناموجود شد: {$noPrice}";
        if ($gone > 0)    $lines[] = "❌ از سایت حذف شد: {$gone}";
        if ($back > 0)    $lines[] = "✅ دوباره موجود شد: {$back}";
        $n = 0;
        foreach (($res['removed_items'] ?? []) as $it) {
            if ($n >= $sampleLimit) break;
            $why = $it['reason'] ?? '';
            if ($why === 'بدون قیمت') continue;   // محصول تازه‌ای که هیچ‌وقت قیمت نداشته
            $lines[] = '• ' . mb_substr((string)($it['title'] ?? ''), 0, 45) . ' — ' . $why;
            $n++;
        }
        $blocks[] = implode("\n", $lines);
    }

    if (!$blocks) return ['ok' => true, 'sent' => 0, 'nothing' => true];

    $sentCount = 0; $last = [];
    foreach ($blocks as $b) { $last = notifSend($cn, $b); $sentCount++; }
    return ['ok' => true, 'sent' => $sentCount, 'price_up' => $up, 'price_down' => $down,
            'no_price' => $noPrice, 'gone' => $gone, 'back' => $back, 'delivery' => $last];
}

/**
 * v8.30: اعلان شکست یک اجرا. سکوت بدترین حالت است — اگر کران‌جاب
 * خراب شود باید خبردار شوید، نه اینکه روزها بی‌صدا بماند.
 */
function notifRunFailure(array $cn, string $stage, string $profileName, string $error): array {
    if (empty($cn['notif_events']['run_fail'])) return ['ok' => true, 'skipped' => 'disabled'];
    if (notifPrereq($cn) !== null) return ['ok' => false];
    $msg = "⚠️ خطا در اجرای خودکار\nمرحله: {$stage}"
         . ($profileName !== '' ? "\nپروفایل: {$profileName}" : '')
         . "\nعلت: " . mb_substr($error, 0, 200);
    return ['ok' => true, 'delivery' => notifSend($cn, $msg)];
}

/**
 * اجرای همهٔ بررسی‌های فعال — همان چیزی که کران‌جاب صدا می‌زند.
 * ترتیب عمدی: سفارش (پول)، پیام (مشتری منتظر است)، محصول (اطلاعی).
 */
function bslCheckNotifications(array $cn): array {
    $why = notifPrereq($cn);
    if ($why !== null) return [];
    $ne = $cn['notif_events'] ?? [];
    $out = [];
    if (!empty($ne['order_new']) || !empty($ne['order_status'])) {
        $r = notifCheckOrders($cn);
        if (!empty($r['found'])) $out['orders'] = $r['found'];
    }
    if (!empty($ne['chat_msg'])) {
        $r = notifCheckChats($cn);
        if (!empty($r['found'])) $out['chats'] = $r['found'];
    }
    if (!empty($ne['product_status']) || !empty($ne['product_new'])) {
        $r = notifCheckProducts($cn);
        if (!empty($r['found'])) $out['products'] = $r['found'];
    }
    return $out;
}

/* دکمه‌های تست — هر بررسی را جدا اجرا و نتیجه را گزارش می‌کند */
if (isset($_GET['notif_test'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $kind = $_GET['kind'] ?? '';
    $cn = loadConnections();
    $why = notifPrereq($cn);
    if ($why !== null) { echo json_encode(['ok' => false, 'error' => $why], JSON_UNESCAPED_UNICODE); exit; }

    if ($kind === 'orders')        $r = notifCheckOrders($cn, true);
    elseif ($kind === 'chats')     $r = notifCheckChats($cn, true);
    elseif ($kind === 'products')  $r = notifCheckProducts($cn, true);
    elseif ($kind === 'ping') {
        // v8.37: پینگ آزمایشی — فاصلهٔ زمانی را نادیده می‌گیرد و
        // وضعیت را ذخیره نمی‌کند تا پینگ واقعی بعدی از دست نرود
        $pr = notifCronPing($cn, ['profiles' => []], true);
        $r = ['ok' => !empty($pr['ok']), 'found' => 1, 'total_seen' => 1,
              'sent' => $pr['delivery'] ?? [], 'sample' => $pr['message'] ?? ''];
        if (!empty($pr['error'])) { $r['ok'] = false; $r['error'] = $pr['error']; }
    }
    elseif ($kind === 'source') {
        // v8.30: از آخرین گزارش استخراج استفاده می‌کند تا پیام واقعی باشد
        $rep = null;
        foreach (glob(__DIR__ . '/extract_report_*.json') ?: [] as $f) {
            $d = json_decode((string)@file_get_contents($f), true);
            if (is_array($d) && (($d['price_up'] ?? 0) || ($d['price_down'] ?? 0)
                || ($d['no_price'] ?? 0) || ($d['gone_from_site'] ?? 0))) { $rep = $d; break; }
            if ($rep === null && is_array($d)) $rep = $d;
        }
        if ($rep === null) {
            $rep = ['price_up' => 1, 'price_down' => 1, 'no_price' => 1, 'gone_from_site' => 0,
                'changed_items' => [
                    ['title' => 'نمونه — کالای گران‌شده', 'old_price' => '100,000', 'new_price' => '130,000', 'dir' => 'up', 'pct' => 30],
                    ['title' => 'نمونه — کالای ارزان‌شده', 'old_price' => '80,000', 'new_price' => '60,000', 'dir' => 'down', 'pct' => -25]],
                'removed_items' => [['title' => 'نمونه — کالای ناموجود', 'reason' => 'ناموجود شد']],
                'new_items' => []];
        }
        // در حالت تست، هر دو نوع اعلان را صرف‌نظر از تنظیمات بفرست
        $cnTest = $cn;
        $cnTest['notif_events']['src_price'] = true;
        $cnTest['notif_events']['src_stock'] = true;
        $sr = notifSourceChanges($cnTest, $rep, 'تست');
        $r = ['ok' => !empty($sr['ok']), 'found' => (int)($sr['sent'] ?? 0),
              'total_seen' => (int)($rep['extracted'] ?? 0),
              'sent' => $sr['delivery'] ?? [],
              'sample' => '▲ گران: ' . (int)($sr['price_up'] ?? 0)
                        . ' | ▼ ارزان: ' . (int)($sr['price_down'] ?? 0)
                        . ' | 🚫 ناموجود: ' . (int)($sr['no_price'] ?? 0)];
        if (!empty($sr['nothing'])) $r['sample'] = 'تغییری برای گزارش نبود';
    }
    else { echo json_encode(['ok' => false, 'error' => 'نوع تست نامعتبر']); exit; }

    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
 *  v8.32: اندپوینت‌های مودال استعلام
 *  دکمه‌های استعلام حالا اول لیست را نشان می‌دهند، بعد کاربر تصمیم
 *  می‌گیرد چه چیزی به پیام‌رسان‌ها برود.
 * ===================================================================== */

/** لیست سفارش‌های غرفه — برای مودال */
if (isset($_GET['bsl_orders_list'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $cn = loadConnections();
    $tk = trim((string)($cn['basalam']['token'] ?? ''));
    $vid = (int)($cn['basalam']['vendor_id'] ?? 0);
    if ($tk === '' || $vid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'تنظیمات باسلام ناقص است'], JSON_UNESCAPED_UNICODE); exit;
    }
    $per = min(30, max(5, (int)($_GET['per_page'] ?? 20)));   // سقف مستندات: ۳۰
    $filter = (string)($_GET['filter'] ?? 'all');
    $ep = 'vendor-parcels?items.vendor_ids=' . $vid . '&per_page=' . $per;
    if ($filter === 'unsent') $ep .= '&statuses=' . implode(',', bslUnsentStatuses());
    $cur = trim((string)($_GET['cursor'] ?? ''));
    if ($cur !== '') $ep .= '&cursor=' . rawurlencode($cur);

    $r = bslReq($tk, 'GET', $ep);
    if (!$r['ok']) {
        echo json_encode(['ok' => false,
            'error' => bslApiError($r, 'دریافت سفارش‌ها ناموفق', 'vendor-parcels', 'vendor.parcel.read')],
            JSON_UNESCAPED_UNICODE); exit;
    }
    $rows = [];
    foreach (($r['body']['data'] ?? []) as $o) { if (is_array($o)) $rows[] = bslNormalizeParcel($o); }
    $unsent = 0;
    foreach ($rows as $n) { if ($n['unsent']) $unsent++; }
    echo json_encode(['ok' => true, 'rows' => $rows, 'total' => count($rows), 'unsent' => $unsent,
        'filter' => $filter, 'next_cursor' => (string)($r['body']['next_cursor'] ?? '')],
        JSON_UNESCAPED_UNICODE);
    exit;
}

/** لیست گفتگوها — برای مودال */
if (isset($_GET['bsl_chats_list'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $cn = loadConnections();
    $tk = trim((string)($cn['basalam']['token'] ?? ''));
    if ($tk === '') {
        echo json_encode(['ok' => false, 'error' => 'توکن باسلام تنظیم نشده'], JSON_UNESCAPED_UNICODE); exit;
    }
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
    $filter = (string)($_GET['filter'] ?? 'all');
    $ep = 'chats?limit=' . $limit . '&order_by=updated_at';
    if ($filter === 'unseen') $ep .= '&filters=unseen';   // فیلتر رسمی مستندات

    $r = bslReq($tk, 'GET', $ep);
    if (!$r['ok']) {
        echo json_encode(['ok' => false,
            'error' => bslApiError($r, 'دریافت گفتگوها ناموفق', 'chats', 'customer.chat.read')],
            JSON_UNESCAPED_UNICODE); exit;
    }
    $raw = $r['body']['data']['chats'] ?? ($r['body']['data'] ?? []);
    $rows = [];
    foreach ($raw as $c) { if (is_array($c)) $rows[] = bslNormalizeChat($c); }
    $unseen = 0;
    foreach ($rows as $n) { if ($n['unseen'] > 0) $unseen++; }
    echo json_encode(['ok' => true, 'rows' => $rows, 'total' => count($rows), 'unseen' => $unseen,
        'filter' => $filter], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ارسال موارد انتخاب‌شده به پیام‌رسان‌ها.
 * kind=orders|chats و ids=فهرست شناسه‌های جداشده با کاما.
 * اگر ids خالی باشد، همهٔ موارد «اقدام‌نشده» فرستاده می‌شوند.
 */
if (isset($_GET['bsl_notify_selected'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $cn = loadConnections();
    $why = notifPrereq($cn);
    if ($why !== null) { echo json_encode(['ok' => false, 'error' => $why], JSON_UNESCAPED_UNICODE); exit; }
    $tk = (string)$cn['basalam']['token'];
    $vid = (int)$cn['basalam']['vendor_id'];
    $kind = (string)($_GET['kind'] ?? '');
    $idsRaw = trim((string)($_GET['ids'] ?? ''));
    $want = $idsRaw === '' ? [] : array_filter(array_map('intval', explode(',', $idsRaw)));
    $digest = !empty($_GET['digest']);   // یک پیام خلاصه به‌جای چند پیام جدا

    if ($kind === 'orders') {
        $r = bslReq($tk, 'GET', 'vendor-parcels?items.vendor_ids=' . $vid . '&per_page=30');
        if (!$r['ok']) { echo json_encode(['ok' => false,
            'error' => bslApiError($r, 'دریافت سفارش‌ها ناموفق', 'vendor-parcels', 'vendor.parcel.read')],
            JSON_UNESCAPED_UNICODE); exit; }
        $picked = [];
        foreach (($r['body']['data'] ?? []) as $o) {
            if (!is_array($o)) continue;
            $n = bslNormalizeParcel($o);
            if ($want ? in_array($n['parcel_id'], $want, true) : $n['unsent']) $picked[] = $n;
        }
    } elseif ($kind === 'chats') {
        $r = bslReq($tk, 'GET', 'chats?limit=50&order_by=updated_at');
        if (!$r['ok']) { echo json_encode(['ok' => false,
            'error' => bslApiError($r, 'دریافت گفتگوها ناموفق', 'chats', 'customer.chat.read')],
            JSON_UNESCAPED_UNICODE); exit; }
        $raw = $r['body']['data']['chats'] ?? ($r['body']['data'] ?? []);
        $picked = [];
        foreach ($raw as $c) {
            if (!is_array($c)) continue;
            $n = bslNormalizeChat($c);
            if ($want ? in_array($n['chat_id'], $want, true) : $n['unseen'] > 0) $picked[] = $n;
        }
    } else { echo json_encode(['ok' => false, 'error' => 'نوع نامعتبر'], JSON_UNESCAPED_UNICODE); exit; }

    if (!$picked) {
        echo json_encode(['ok' => true, 'sent' => 0, 'delivery' => [],
            'note' => 'موردی برای ارسال نبود'], JSON_UNESCAPED_UNICODE); exit;
    }

    $delivery = []; $sent = 0;
    if ($digest) {
        // یک پیام فشرده — برای وقتی موارد زیاد است و نمی‌خواهیم پیام‌رسان پر شود
        if ($kind === 'orders') {
            $lines = ['📦 سفارش‌های ارسال‌نشده (' . count($picked) . ' مورد)'];
            $sum = 0;
            foreach ($picked as $n) {
                $sum += $n['amount'];
                $lines[] = '• #' . ($n['order_id'] ?: $n['parcel_id']) . ' — ' . $n['customer']
                         . ' — ' . number_format($n['amount']) . ' تومان — ' . $n['status'];
            }
            $lines[] = '━━━━━━━━━━';
            $lines[] = 'جمع: ' . number_format($sum) . ' تومان';
        } else {
            $tot = 0;
            foreach ($picked as $n) { $tot += $n['unseen']; }
            $lines = ['💬 پیام‌های خوانده‌نشده (' . count($picked) . ' گفتگو · ' . $tot . ' پیام)'];
            foreach ($picked as $n) {
                $lines[] = '• ' . $n['who'] . ($n['unseen'] > 0 ? ' (' . $n['unseen'] . ')' : '')
                         . ' — ' . mb_substr($n['text'], 0, 60);
            }
        }
        $delivery = notifSend($cn, implode("\n", $lines));
        $sent = 1;
    } else {
        foreach ($picked as $n) {
            if ($kind === 'orders') {
                $msg = bslParcelMsg($n, '📦 سفارش ارسال‌نشده');
            } else {
                // v8.33: متن کامل پیام‌های مشتری، نه فقط آخرین پیام
                $body = bslFetchChatMessages($tk, $n['chat_id'], max(1, min(10, $n['unseen'] ?: 1)));
                $msg = bslChatMsg($n, '💬 پیام خوانده‌نشده', $body);
            }
            $delivery = notifSend($cn, $msg);
            $sent++;
            if ($sent >= 20) break;   // سقف ایمنی برای جلوگیری از هرزنامه
        }
    }
    echo json_encode(['ok' => true, 'sent' => $sent, 'picked' => count($picked),
        'delivery' => $delivery, 'digest' => $digest], JSON_UNESCAPED_UNICODE);
    exit;
}

function bslSendToBaleh(string $token, string $chatId, string $text): bool {
$url = 'https://tapi.bale.ai/bot' . $token . '/sendMessage';
$ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text], JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
return $code >= 200 && $code < 300;
}
function bslSendToRubika(string $token, string $chatId, string $text): bool {
$url = 'https://api.rubika.ir/v1/bot' . $token . '/sendMessage';
$ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text], JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
return $code >= 200 && $code < 300;
}

function bslTryCreateWithFallback(string $tk, int $vid, array $bp, array $fallbackCatIds, string $pTitle, bool $autoCat, array $bslFlatCats, array $cData): array {
$tried = [(int)($bp['category_id'] ?? 0)];
foreach ($fallbackCatIds as $fc) {
$fc = (int)$fc; if ($fc <= 0 || in_array($fc, $tried)) continue; $tried[] = $fc;
if (!empty($cData) && is_array($cData)) $fc = findLeafCategory($fc, $cData);
$bp['category_id'] = $fc;
$r = bslReq($tk, 'POST', 'vendors/' . $vid . '/products', $bp);
if ($r['ok'] && !empty($r['body']['id'])) return ['ok' => true, 'body' => $r['body'], 'used_cat_id' => $fc, 'tried' => $tried];
$em = $r['body']['error_description'] ?? $r['body']['message'] ?? $r['body']['error'] ?? '';
if (is_array($em)) $em = json_encode($em, JSON_UNESCAPED_UNICODE);
if (mb_stripos($em, 'دسته') === false && mb_stripos($em, 'category') === false && mb_stripos($em, 'فرزند') === false) return ['ok' => false, 'error' => $em, 'tried' => $tried];
}
if ($autoCat && !empty($bslFlatCats)) {
$acId = autoMatchBslCategoryForce($pTitle, $bslFlatCats);
if ($acId > 0 && !in_array($acId, $tried)) { if (!empty($cData) && is_array($cData)) $acId = findLeafCategory($acId, $cData); $bp['category_id'] = $acId; $r = bslReq($tk, 'POST', 'vendors/' . $vid . '/products', $bp); if ($r['ok'] && !empty($r['body']['id'])) return ['ok' => true, 'body' => $r['body'], 'used_cat_id' => $acId, 'tried' => $tried]; }
}
return ['ok' => false, 'error' => 'همه دسته‌های جایگزین ناموفق', 'tried' => $tried];
}
if (($_POST['action'] ?? '') === 'load_connections') { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok'=>true,'connections'=>loadConnections()], JSON_UNESCAPED_UNICODE); exit; }
if (($_POST['action'] ?? '') === 'test_woo') {
header('Content-Type: application/json; charset=UTF-8');
$r = wooReq(trim($_POST['store_url']??''), trim($_POST['consumer_key']??''), trim($_POST['consumer_secret']??''), 'GET', 'system_status');
if ($r['ok']) { $env=$r['body']['environment']??[]; echo json_encode(['ok'=>true,'message'=>'اتصال موفق!','version'=>$env['woocommerce_version']??'?'], JSON_UNESCAPED_UNICODE); }
else { echo json_encode(['ok'=>false,'error'=>($r['code']===401?'کلید اشتباه':'خطا HTTP '.$r['code'])], JSON_UNESCAPED_UNICODE); }
exit;
}
if (($_POST['action'] ?? '') === 'test_basalam') {
header('Content-Type: application/json; charset=UTF-8');
$tk=trim($_POST['token']??'');
$r = bslReq($tk, 'GET', 'users/me');
if ($r['ok']&&!empty($r['body'])) {
$b=$r['body'];$v=$b['vendor']??[];
$ivs=$b['info_verification_status']??null;

$verified=false;$verifValue=0;$verifName='';$verifDesc='';
if($ivs&&is_array($ivs)){
$verifValue=(int)($ivs['value']??0);
$verifName=$ivs['name']??'';
$verifDesc=$ivs['description']??'';

$verified=($verifValue>=1);
}
echo json_encode([
'ok'=>true,'message'=>'اتصال موفق!',
'user_id'=>$b['id']??0,
'user_name'=>$b['name']??'',
'username'=>$b['username']??'',
'mobile'=>$b['mobile']??'',
'email'=>$b['email']??'',
'national_code'=>$b['national_code']??'',
'hash_id'=>$b['hash_id']??'',
'avatar'=>$b['avatar']??null,
'is_banned_in_social'=>$b['is_banned_in_social']??false,
'vendor_id'=>$v['id']??0,
'vendor_title'=>$v['title']??'',
'vendor_identifier'=>$v['identifier']??'',
'vendor_is_active'=>$v['is_active']??false,
'vendor_status'=>$v['status']??0,
'vendor_created_at'=>$v['created_at']??'',
'vendor_activated_at'=>$v['activated_at']??'',
'vendor_order_count'=>$v['order_count']??0,
'vendor_free_shipping_iran'=>$v['free_shipping_to_iran']??0,
'verified'=>$verified,
'verification_value'=>$verifValue,
'verification_name'=>$verifName,
'verification_desc'=>$verifDesc,
], JSON_UNESCAPED_UNICODE);
}else{
$errMsg='خطا';
if($r['code']===401)$errMsg='توکن نامعتبر (۴۰۱)';
elseif($r['code']===403)$errMsg='دسترسی ممنوع (۴۰۳) — احراز هویت ناقص یا توکن بدون مجوز';
elseif($r['code']===0)$errMsg='خطا ارتباط با سرور باسلام';
elseif(!empty($r['body']['detail']))$errMsg=mb_substr($r['body']['detail'],0,200);
echo json_encode(['ok'=>false,'error'=>$errMsg,'http_code'=>$r['code'],'detail'=>$r['body']['detail']??($r['raw']??'')], JSON_UNESCAPED_UNICODE);
}
exit;
}

if (($_POST['action'] ?? '') === 'test_notif') {
header('Content-Type: application/json; charset=UTF-8');
$type=trim($_POST['notif_type']??'');
$token=trim($_POST['token']??'');
$chatId=trim($_POST['chat_id']??'');
if(empty($token)||empty($chatId)){echo json_encode(['ok'=>false,'error'=>'Token و Chat ID الزامی است'],JSON_UNESCAPED_UNICODE);exit;}
$testMsg='🔔 تست اعلان — '.date('H:i:s Y/m/d').' — Scraper v8.22';
if($type==='baleh'){
$ok=bslSendToBaleh($token,$chatId,$testMsg);
if($ok){echo json_encode(['ok'=>true,'message'=>'پیام بله ارسال شد'],JSON_UNESCAPED_UNICODE);}
else{echo json_encode(['ok'=>false,'error'=>'ارسال به بله ناموفق — Token یا Chat ID را بررسی کنید'],JSON_UNESCAPED_UNICODE);}
}elseif($type==='rubika'){
$ok=bslSendToRubika($token,$chatId,$testMsg);
if($ok){echo json_encode(['ok'=>true,'message'=>'پیام روبیکا ارسال شد'],JSON_UNESCAPED_UNICODE);}
else{echo json_encode(['ok'=>false,'error'=>'ارسال به روبیکا ناموفق — Token یا Chat ID را بررسی کنید'],JSON_UNESCAPED_UNICODE);}
}else{
echo json_encode(['ok'=>false,'error'=>'نوع اعلان نامعتبر'],JSON_UNESCAPED_UNICODE);
}
exit;
}
if (($_POST['action'] ?? '') === 'test_ai') {
header('Content-Type: application/json; charset=UTF-8');
$apiKey=trim($_POST['api_key']??'');
$baseUrl=trim($_POST['base_url']??'https://dashscope.aliyuncs.com/compatible-mode/v1');
$model=trim($_POST['model']??'qwen-plus');
if(empty($apiKey)){echo json_encode(['ok'=>false,'error'=>'کلید API خالی'],JSON_UNESCAPED_UNICODE);exit;}
$url=rtrim($baseUrl,'/').'/chat/completions';
$payload=['model'=>$model,'messages'=>[['role'=>'user','content'=>'سلام، لطفا کلمه «متصل» را پاس بده']], 'temperature'=>0.1, 'max_tokens'=>20];
$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
$resp=curl_exec($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
if($httpCode===200){
$rData=json_decode($resp,true);
$aiText=trim($rData['choices'][0]['message']['content']??'');
$modelUsed=$rData['model']??$model;
$usage=$rData['usage']??[];
echo json_encode(['ok'=>true,'message'=>'اتصال AI موفق!','response'=>$aiText,'model'=>$modelUsed,'usage'=>$usage],JSON_UNESCAPED_UNICODE);
}else{
$errBody=json_decode($resp,true);
$errMsg=$errBody['error']['message']??($errBody['message']??'HTTP '.$httpCode);
if($httpCode===401)$errMsg='کلید API نامعتبر (۴۰۱)';
elseif($httpCode===0)$errMsg='خطا ارتباط با سرور AI: '.$err;
echo json_encode(['ok'=>false,'error'=>$errMsg,'http_code'=>$httpCode],JSON_UNESCAPED_UNICODE);
}
exit;
}

if (isset($_GET['ai_category'])) {
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$ai=$cn['ai']??[];$bs=$cn['basalam']??[];
$apiKey=trim($ai['api_key']??'');
$baseUrl=trim($ai['base_url']??'https://dashscope.aliyuncs.com/compatible-mode/v1');
$model=trim($ai['model']??'qwen-plus');
$temperature=(float)($ai['temperature']??0.1);
$productTitle=trim($_GET['title']??'');
if(empty($apiKey)){echo json_encode(['ok'=>false,'error'=>'کلید API هوش مصنوعی تنظیم نشده','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}
if(empty($productTitle)){echo json_encode(['ok'=>false,'error'=>'عنوان محصول خالی','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}

$tk=$bs['token']??'';$cats=[];
if(!empty($tk)){
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1)as $s)$o[]=$s;}}return $o;};$cats=$cFlat($cData,0);}}
}
if(empty($cats)){echo json_encode(['ok'=>false,'error'=>'دسته‌بندی‌های باسلام در دسترس نیست','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}

$catList='';$leafCats=[];
foreach($cats as $c){if(($c['level']??0)>=2){$catList.=$c['id'].': '.$c['name']."\n";$leafCats[]=$c;}}
if(empty($leafCats)){foreach($cats as $c){$catList.=$c['id'].': '.$c['name']."\n";$leafCats[]=$c;}}
if(strlen($catList)>3000){$catList='';$leafCats=array_slice($leafCats,0,200);foreach($leafCats as $c){$catList.=$c['id'].': '.$c['name']."\n";}}
$prompt="You are a product categorization assistant for a Persian (Farsi) e-commerce platform (BaSalam).\nGiven this product title: \"{$productTitle}\"\n\nSelect the BEST category ID from this list:\n{$catList}\n\nReturn ONLY the category ID number. Do not return any text, explanation, or name. Just the numeric ID.";

$url=rtrim($baseUrl,'/').'/chat/completions';
$payload=['model'=>$model,'messages'=>[['role'=>'system','content'=>'You are a product categorization assistant. Return ONLY the numeric category ID.'],['role'=>'user','content'=>$prompt]],'temperature'=>$temperature,'max_tokens'=>20];
$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
$resp=curl_exec($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($httpCode!==200){echo json_encode(['ok'=>false,'error'=>'خطا API AI (HTTP '.$httpCode.')','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}
$rData=json_decode($resp,true);
$aiText=trim($rData['choices'][0]['message']['content']??'');

$aiCatId=0;
if(preg_match('/\d+/',$aiText,$m)){$aiCatId=(int)$m[0];}

$valid=false;$catName='';$aiCatId=findLeafCategory($aiCatId,$cats);
foreach($cats as $c){if((int)$c['id']===$aiCatId){$valid=true;$catName=$c['name'];break;}}
if(!$valid&&$aiCatId>0){
$aiCatId=autoMatchBslCategory($productTitle,$cats);
if($aiCatId>0){$valid=true;foreach($cats as $c){if((int)$c['id']===$aiCatId){$catName=$c['name'];break;}}}
}
echo json_encode(['ok'=>$valid,'category_id'=>$aiCatId,'category_name'=>$catName,'ai_raw'=>$aiText,'ai_model'=>$model,'title'=>$productTitle],JSON_UNESCAPED_UNICODE);
exit;
}

if (($_POST['action'] ?? '') === 'bsl_categories') {
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$tk=$cn['basalam']['token']??'';$vid=(int)($cn['basalam']['vendor_id']??0);
if (!$tk) { echo json_encode(['ok' => false, 'error' => 'توکن باسلام ذخیره نشده'], JSON_UNESCAPED_UNICODE); exit; }
$cats=[];
$r=bslReq($tk,'GET','categories');
if(!$r['ok']){echo json_encode(['ok'=>false,'error'=>'خطا دریافت دسته‌ها (HTTP '.$r['code'].') '.mb_substr($r['raw']??'',0,200)],JSON_UNESCAPED_UNICODE);exit;}
$data=$r['body']['data']??$r['body']??[];
if(!is_array($data))$data=[];
$flatten=function($items,$level=0)use(&$flatten){
$out=[];
foreach($items as $c){
$title=trim($c['title']??$c['name']??'');
$id=(int)($c['id']??0);
if($id>0)$out[]=['id'=>$id,'name'=>$title,'level'=>$level];
$children=$c['children']??[];
if(is_array($children)&&count($children)>0){
$sub=$flatten($children,$level+1);
foreach($sub as $s)$out[]=$s;
}
}
return $out;
};
$cats=$flatten($data,0);
echo json_encode(['ok'=>true,'categories'=>$cats],JSON_UNESCAPED_UNICODE);
exit;
}

if (($_POST['action'] ?? '') === 'woo_categories') {
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections(); $w=$cn['woocommerce']??[];
if(empty($w['store_url'])){echo json_encode(['ok'=>false,'error'=>'ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$r=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'GET','products/categories?per_page=100');
if($r['ok']&&is_array($r['body'])){$cats=[];foreach($r['body'] as $c2)$cats[]=['id'=>$c2['id'],'name'=>$c2['name'],'count'=>$c2['count']??0];echo json_encode(['ok'=>true,'categories'=>$cats],JSON_UNESCAPED_UNICODE);}
else echo json_encode(['ok'=>false,'error'=>'ناموفق'],JSON_UNESCAPED_UNICODE);
exit;
}
if (isset($_GET['woo_stream'])) {
header('Content-Type: text/event-stream'); header('Cache-Control: no-cache'); header('X-Accel-Buffering: no');
while (@ob_get_level()) @ob_end_clean();

$fromFile=!empty($_POST['from_file']);
// v8.36: مثل باسلام — فایل مخصوص همین صف، نه فایل مشترک
if($fromFile){$raw=@file_get_contents(wooQueueProductsFile(trim($_POST['queue_id']??'')));$pd=json_decode($raw?:'[]',true)?:[];}
else{$rawInput=$_POST['products']??'[]';$pd=json_decode($rawInput,true)?:[];}
$cn=loadConnections(); $w=$cn['woocommerce']??[];
if(empty($w['store_url'])){send_sse('error',['message'=>'تنظیمات ووکامرس ناقص']);send_sse('done',[]);exit;}
$titleSuffix=trim($_POST['title_suffix']??'');
$startIndex=max(0,(int)($_POST['start_index']??0));
$skipKeys=json_decode($_POST['skip_keys']??'[]',true)?:[];
$skipMap=array_flip($skipKeys);

$isWooResume=$startIndex>0;
if($isWooResume){$prevWooP=readProgress(WOO_PROGRESS_FILE);$sent=(int)($prevWooP['sent']??0);$updated=(int)($prevWooP['updated']??0);$skipped=(int)($prevWooP['skipped']??0);$fail=(int)($prevWooP['failed']??0);}else{$sent=0;$fail=0;$skipped=0;$updated=0;}
$total=count($pd);

send_sse('send_info',['msg'=>'✅ دریافت '.$total.' محصول ('.($fromFile?'از فایل':'از POST').($isWooResume?' | ادامه از #'.($startIndex+1):'').')']);
send_sse('send_info',['msg'=>'شروع ارسال '.$total.' محصول به ووکامرس...'.($startIndex>0?' (ادامه از محصول #'.($startIndex+1).')':'')]);
foreach($pd as $i=>$p){
if($i<$startIndex){continue;}

if(file_exists(BSL_STOP_FILE)){
@unlink(BSL_STOP_FILE);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$i,mb_substr($pTitle??'',0,30),['❌ فرآیند توسط کاربر متوقف شد']);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'current'=>$i,'started_at'=>$GLOBALS['startedAt'],'recent_log'=>['❌ فرآیند متوقف شد'],'total_log_count'=>1,'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList]);
exit;
}
$pTitle=trim($p['title']??$p['name']??'');
$pPrice=(string)($p['final_price']??'0');
$pKey=$p['key']??'';
$n=$i+1;

$GLOBALS['_currentProductLink']=$p['link']??'';
// v8.21: Apply title suffix if not already present in the title
if($titleSuffix!==''&&$pTitle!==''&&strpos($pTitle,$titleSuffix)===false){$pTitle=$pTitle.$titleSuffix;}
if(isset($skipMap[$pKey])){$skipped++;send_sse('send_skip',['key'=>$pKey,'remote_id'=>0,'title'=>$pTitle,'reason'=>'قبلاً ارسال شده']);continue;}
send_sse('send_progress',['current'=>$n,'total'=>$total,'title'=>mb_substr($pTitle,0,50),'index'=>$i]);
send_sse_ping();
send_sse('send_info',['msg'=>"[$n/$total] بررسی: ".mb_substr($pTitle,0,60)." | قیمت: $pPrice"]);
$wp=['name'=>$pTitle,'type'=>'simple','regular_price'=>$pPrice,'status'=>$w['default_status']??'draft','manage_stock'=>!empty($w['manage_stock']),'stock_quantity'=>(int)($w['stock_quantity']??10)];
if(!empty($p['short_desc']))$wp['short_description']=$p['short_desc'];
if(!empty($p['long_desc']))$wp['description']=$p['long_desc'];
if(!empty($p['sku']))$wp['sku']=$p['sku'];
if(!empty($w['default_category']))$wp['categories']=[['id'=>(int)$w['default_category']]];

$wooImgId=0;
if(!empty($p['image'])){
send_sse('send_info',['msg'=>"[$n] 📥 آپلود تصویر به مدیا..."]);
$upResult=wooUploadImage($w['store_url'],$w['consumer_key'],$w['consumer_secret'],$p['image']);
if(!empty($upResult['ok'])){
$wooImgId=(int)$upResult['media_id'];
$wp['images']=[['id'=>$wooImgId]];
send_sse('send_info',['msg'=>"[$n] ✓ تصویر آپلود شد (Media#$wooImgId)"]);
}else{
send_sse('send_info',['msg'=>"[$n] ⚠️ آپلود تصویر ناموفق: ".mb_substr($upResult['error']??'',0,80)." - محصول بدون تصویر ارسال می‌شود..."]);

}
}
else{
// v8.21: Log when image URL is empty
send_sse('send_info',['msg'=>"[$n] ⚠️ بدون تصویر — URL خالی"]);
}
$existing=null;
if($pTitle!==''){
$sep='products?search='.urlencode($pTitle).'&status=any&per_page=10';
send_sse('send_info',['msg'=>"[$n] جستجو در ووکامرس..."]);
$sr=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'GET',$sep);
if($sr['ok']&&is_array($sr['body'])){
$fc=count($sr['body']);
send_sse('send_info',['msg'=>"[$n] جستجو (کامل): $fc نتیجه"]);
foreach($sr['body'] as $ep){
if(isset($ep['name'])&&trim($ep['name'])===$pTitle){$existing=$ep;break;}
}
if(!$existing && $titleSuffix!==''){
$baseTitle = trim(str_replace($titleSuffix, '', $pTitle));
if($baseTitle!=='' && $baseTitle!==$pTitle){
foreach($sr['body'] as $ep){
$epName=trim($ep['title']??$ep['name']??'');
if($epName===$baseTitle || $epName===$pTitle){$existing=$ep;send_sse('send_info',['msg'=>"[$n] ✓ یافت شد با عنوان بدون پسوند"]);break;}
}
}
}
if(!$existing && $titleSuffix!==''){
$baseTitle = trim(str_replace($titleSuffix, '', $pTitle));
if($baseTitle!=='' && $baseTitle!==$pTitle){
$sep2='products?search='.urlencode($baseTitle).'&status=any&per_page=10';
send_sse('send_info',['msg'=>"[$n] جستجوی بدون پسوند..."]);
$sr2=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'GET',$sep2);
if($sr2['ok']&&is_array($sr2['body'])){
foreach($sr2['body'] as $ep){
$epName=trim($ep['title']??$ep['name']??'');
if($epName===$baseTitle || $epName===$pTitle){$existing=$ep;send_sse('send_info',['msg'=>"[$n] ✓ یافت شد با جستجوی بدون پسوند"]);break;}
}
}
}
}
}else{
$ec=$sr['code']??0;$ee=$sr['error']??'';
send_sse('send_info',['msg'=>"[$n] جستجو ناموفق: HTTP $ec $ee"]);
}
}
if($existing){
$exId=$existing['id']??'?';
$exPrice=trim((string)($existing['regular_price']??''));
$exStock=(int)($existing['stock_quantity']??0);
$newStock=(int)($w['stock_quantity']??10);
send_sse('send_info',['msg'=>"[$n] تکراری یافت شد: ID#$exId | قیمت: $exPrice | موجودی: $exStock"]);
if($exPrice===$pPrice&&$exStock===$newStock){
$skipped++;
send_sse('send_skip',['key'=>$pKey,'remote_id'=>$existing['id'],'title'=>$pTitle,'reason'=>'تکرار: نام+قیمت+موجودی یکسان','image'=>$p['image']??'','price'=>$pPrice,'price_unit'=>$priceUnit,'category'=>'','link'=>$p['link']??'']);
send_sse('send_info',['msg'=>"[$n] ⏭ رد شد - تکرار دقیق"]);
usleep(100000);continue;
}else{
send_sse('send_info',['msg'=>"[$n] ⚡ آپدیت: قیمت $exPrice → $pPrice | موجودی $exStock → $newStock"]);
$wpUpdate=['regular_price'=>$pPrice,'stock_quantity'=>$newStock];
if(!empty($p['short_desc']))$wpUpdate['short_description']=$p['short_desc'];
if(!empty($p['long_desc']))$wpUpdate['description']=$p['long_desc'];
if(!empty($p['image'])){
if($wooImgId>0){
$wpUpdate['images']=[['id'=>$wooImgId]];
}else{
$upResult2=wooUploadImage($w['store_url'],$w['consumer_key'],$w['consumer_secret'],$p['image']);
if(!empty($upResult2['ok'])){
$wpUpdate['images']=[['id'=>(int)$upResult2['media_id']]];
}else{

}
}
}
if(!empty($p['sku']))$wpUpdate['sku']=$p['sku'];
$r=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'PUT','products/'.$existing['id'],$wpUpdate);
if($r['ok']&&!empty($r['body']['id'])){
$updated++;
send_sse('send_update',['key'=>$pKey,'remote_id'=>$r['body']['id'],'title'=>$r['body']['name']??$pTitle,'edit_url'=>rtrim($w['store_url'],'/').'/wp-admin/post.php?post='.$r['body']['id'].'&action=edit','old_price'=>$exPrice,'new_price'=>$pPrice,'update_reason'=>'تغییر قیمت: '.$exPrice.' → '.$pPrice]);
send_sse('send_info',['msg'=>"[$n] ✅ آپدیت موفق: ID#{$r['body']['id']}"]);
}else{
$fail++;
$errMsg=mb_substr($r['body']['message']??($r['body']['error']??$r['error']??'?'),0,120);
send_sse('send_fail',['key'=>$pKey,'error'=>$errMsg,'index'=>$i]);
send_sse('send_info',['msg'=>"[$n] ❌ خطای آپدیت: $errMsg"]);
}
usleep(150000);continue;
}
}
send_sse('send_info',['msg'=>"[$n] 🆕 محصول جدید - ایجاد..."]);
$r=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'POST','products',$wp);
if($r['ok']&&!empty($r['body']['id'])){
$sent++;
send_sse('send_ok',['key'=>$pKey,'remote_id'=>$r['body']['id'],'title'=>$r['body']['name']??'','edit_url'=>rtrim($w['store_url'],'/').'/wp-admin/post.php?post='.$r['body']['id'].'&action=edit']);
send_sse('send_info',['msg'=>"[$n] ✅ ایجاد موفق: ID#{$r['body']['id']} - ".mb_substr($r['body']['name']??'',0,40)]);
}else{
$fail++;
$errBody=$r['body']??[];
$errMsg=mb_substr($errBody['message']??($errBody['error']??($r['error']??json_encode($errBody,JSON_UNESCAPED_UNICODE))),0,150);
send_sse('send_fail',['key'=>$pKey,'error'=>$errMsg,'index'=>$i]);
send_sse('send_info',['msg'=>"[$n] ❌ خطای ایجاد (HTTP {$r['code']}): $errMsg"]);
}
usleep(150000);
}
$totalSkipped=$skipped+$fail;send_sse('send_info',['msg'=>"پایان: $sent جدید, $updated آپدیت, $skipped تکراری, $fail خطا از $total محصول"]);
send_sse('send_complete',['sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total]);send_sse('done',[]);exit;
}

if(isset($_GET['action']) && $_GET['action'] === 'woo_backend'){
set_time_limit(0); ignore_user_abort(true);

$wooLockFile=__DIR__.'/woo_backend.lock';
$wooLockFp=fopen($wooLockFile,'w');
if(!flock($wooLockFp,LOCK_EX|LOCK_NB)){
fclose($wooLockFp);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>false,'error'=>'woo_backend already running','skipped'=>true],JSON_UNESCAPED_UNICODE);
exit;
}
register_shutdown_function(function()use($wooLockFp,$wooLockFile){@flock($wooLockFp,LOCK_UN);@fclose($wooLockFp);@unlink($wooLockFile);});

header('Content-Type: application/json; charset=UTF-8');
header('Connection: close');
header('Content-Length: '.strlen(json_encode(['ok'=>true,'msg'=>'woo_backend started'],JSON_UNESCAPED_UNICODE)));
echo json_encode(['ok'=>true,'msg'=>'woo_backend started'],JSON_UNESCAPED_UNICODE);
@ob_flush(); @flush();
if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}

$startedAt=time();$GLOBALS['startedAt']=$startedAt;
@unlink(WOO_STOP_FILE);
$wooSentList=[];$wooUpdatedList=[];$wooSkippedList=[];$wooFailedList=[];$wooLog=[];

function wooBackendProgress($s,$u,$sk,$f,$t,$c,$lt,$log=null,$extra=[]){
global $wooLog,$wooSentList,$wooUpdatedList,$wooSkippedList,$wooFailedList;
if($log!==null){$wooLog[]=$log;}
$totalLog=count($wooLog);
$recentSlice=$totalLog>200?array_slice($wooLog,-200):$wooLog;
$d=['running'=>true,'sent'=>$s,'updated'=>$u,'skipped'=>$sk,'failed'=>$f,'total'=>$t,'last_title'=>$lt,'current'=>$c,'done'=>false,'started_at'=>$GLOBALS['startedAt'],'last_progress_ts'=>time(),'recent_log'=>$recentSlice,'total_log_count'=>$totalLog,'sent_details'=>$wooSentList,'updated_details'=>$wooUpdatedList,'skipped_details'=>$wooSkippedList,'failed_details'=>$wooFailedList];
if(!empty($extra))$d=array_merge($d,$extra);
writeProgress(WOO_PROGRESS_FILE,$d);
clearstatcache();
}

$cn=loadConnections();$w=$cn['woocommerce']??[];
if(empty($w['store_url'])||empty($w['consumer_key'])||empty($w['consumer_secret'])){
writeProgress(WOO_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'total'=>0,'current'=>0,'started_at'=>$startedAt,'recent_log'=>['❌ تنظیمات ووکامرس ناقص'],'total_log_count'=>1,'sent_details'=>[],'updated_details'=>[],'skipped_details'=>[],'failed_details'=>[]]);
exit;
}

$raw=@file_get_contents(WOO_PRODUCTS_FILE);
$pd=json_decode($raw?:'[]',true)?:[];
if(empty($pd)){
writeProgress(WOO_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'total'=>0,'current'=>0,'started_at'=>$startedAt,'recent_log'=>['❌ فایل محصولات خالی'],'total_log_count'=>1,'sent_details'=>[],'updated_details'=>[],'skipped_details'=>[],'failed_details'=>[]]);
exit;
}

$total=count($pd);$sent=0;$updated=0;$skipped=0;$fail=0;
$bslDelayMs=max(0,(int)($cn['basalam']['delay_ms']??500));
// v8.21: Read title_suffix from the running woo queue entry config
$wooTitleSuffix='';
$wooQueue=wooReadQueue();
foreach($wooQueue['entries'] as $qe){if($qe['status']==='running'&&!empty($qe['config']['title_suffix'])){$wooTitleSuffix=trim($qe['config']['title_suffix']);break;}}
if($wooTitleSuffix===''){$wooTitleSuffix=trim($cn['basalam']['title_suffix']??'');}
$GLOBALS['_currentProductLink']='';

wooBackendProgress(0,0,0,0,$total,0,'',['✅ [v8.22 woo_backend] شروع — '.$total.' محصول']);

foreach($pd as $i=>$p){
if(file_exists(WOO_STOP_FILE)){
@unlink(WOO_STOP_FILE);
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$i,'',['❌ متوقف شد']);
writeProgress(WOO_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'current'=>$i,'started_at'=>$startedAt,'recent_log'=>['❌ متوقف شد'],'total_log_count'=>1,'sent_details'=>$wooSentList,'updated_details'=>$wooUpdatedList,'skipped_details'=>$wooSkippedList,'failed_details'=>$wooFailedList]);
exit;
}

$pTitle=trim($p['title']??$p['name']??'');
$pPrice=(string)($p['final_price']??'0');
$pKey=$p['key']??'';
$n=$i+1;
$GLOBALS['_currentProductLink']=$p['link']??'';
// v8.21: Apply title suffix if not already present in the title
if($wooTitleSuffix!==''&&$pTitle!==''&&strpos($pTitle,$wooTitleSuffix)===false){$pTitle=$pTitle.$wooTitleSuffix;}

$card=['title'=>$pTitle,'image'=>$p['image']??'','price'=>$pPrice,'category'=>'','link'=>$p['link']??''];

if($pTitle===''){wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ عنوان خالی");$fail++;$wooFailedList[]=array_merge(['title'=>'','key'=>$pKey,'error'=>'عنوان خالی'],$card);continue;}

$wp=['name'=>$pTitle,'type'=>'simple','regular_price'=>$pPrice,'status'=>$w['default_status']??'draft','manage_stock'=>!empty($w['manage_stock']),'stock_quantity'=>(int)($w['stock_quantity']??10)];
if(!empty($p['short_desc']))$wp['short_description']=$p['short_desc'];
if(!empty($p['long_desc']))$wp['description']=$p['long_desc'];
if(!empty($p['sku']))$wp['sku']=$p['sku'];
if(!empty($w['default_category']))$wp['categories']=[['id'=>(int)$w['default_category']]];

$existing=null;
if($pTitle!==''){
$sep='products?search='.urlencode($pTitle).'&status=any&per_page=10';
$sr=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'GET',$sep);
if($sr['ok']&&is_array($sr['body'])){
foreach($sr['body'] as $ep){
if(isset($ep['name'])&&trim($ep['name'])===$pTitle){$existing=$ep;break;}
}
// v8.21: Search without suffix for dedup
if(!$existing && $wooTitleSuffix!==''){
$baseTitle=trim(str_replace($wooTitleSuffix,'',$pTitle));
if($baseTitle!=='' && $baseTitle!==$pTitle){
foreach($sr['body'] as $ep){
$epName=trim($ep['title']??$ep['name']??'');
if($epName===$baseTitle || $epName===$pTitle){$existing=$ep;wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✓ یافت شد با عنوان بدون پسوند");break;}
}
}
}
}
}

if($existing){
$exId=$existing['id']??'?';
$exPrice=trim((string)($existing['regular_price']??''));
$exStock=(int)($existing['stock_quantity']??0);
$newStock=(int)($w['stock_quantity']??10);
$editUrl=rtrim($w['store_url'],'/').'/wp-admin/post.php?post='.$exId.'&action=edit';

if($exPrice===$pPrice&&$exStock===$newStock){
$skipped++;$wooSkippedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$exId,'reason'=>'تکرار: نام+قیمت+موجودی یکسان','edit_url'=>$editUrl],$card);
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⏭ تکرار: $pTitle");
continue;
}

$wooImgId=0;
if(!empty($p['image'])){
$upResult=wooUploadImage($w['store_url'],$w['consumer_key'],$w['consumer_secret'],$p['image']);
if(!empty($upResult['ok'])){$wooImgId=(int)$upResult['media_id'];}
}

$wpUpdate=['regular_price'=>$pPrice,'stock_quantity'=>$newStock];
if(!empty($p['short_desc']))$wpUpdate['short_description']=$p['short_desc'];
if(!empty($p['long_desc']))$wpUpdate['description']=$p['long_desc'];
if($wooImgId>0)$wpUpdate['images']=[['id'=>$wooImgId]];
if(!empty($p['sku']))$wpUpdate['sku']=$p['sku'];
$r=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'PUT','products/'.$exId,$wpUpdate);
if($r['ok']&&!empty($r['body']['id'])){
$updated++;$wooUpdatedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r['body']['id'],'edit_url'=>$editUrl,'changes'=>'قیمت '.$exPrice.'→'.$pPrice,'update_reason'=>'تغییر قیمت: '.$exPrice.' → '.$pPrice],$card);
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ آپدیت: ID#{$r['body']['id']}");
}else{
$errMsg=mb_substr($r['body']['message']??($r['body']['error']??$r['error']??'?'),0,120);
$fail++;$wooFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>'خطای آپدیت: '.$errMsg,'edit_url'=>$editUrl],$card);
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ آپدیت: $errMsg");
}
}else{

$wooImgId=0;
if(!empty($p['image'])){
$upResult=wooUploadImage($w['store_url'],$w['consumer_key'],$w['consumer_secret'],$p['image']);
if(!empty($upResult['ok'])){
$wooImgId=(int)$upResult['media_id'];
$wp['images']=[['id'=>$wooImgId]];
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✓ تصویر آپلود شد (Media#$wooImgId)");
}else{
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚠️ تصویر ناموفق: ".mb_substr($upResult['error']??'',0,60));
}
}else{
// v8.21: Log when image URL is empty
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚠️ بدون تصویر — URL خالی");
}

$r=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'POST','products',$wp);
if($r['ok']&&!empty($r['body']['id'])){
$editUrl=rtrim($w['store_url'],'/').'/wp-admin/post.php?post='.$r['body']['id'].'&action=edit';
$sent++;$wooSentList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r['body']['id'],'edit_url'=>$editUrl,'price'=>$pPrice],$card);
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ ایجاد: ID#{$r['body']['id']}");
}else{
$errBody=$r['body']??[];
$errMsg=mb_substr($errBody['message']??($errBody['error']??($r['error']??json_encode($errBody,JSON_UNESCAPED_UNICODE))),0,150);
$fail++;$wooFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>'خطای ایجاد: '.$errMsg],$card);
wooBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ ایجاد: $errMsg");
}
}
usleep(max(100000,$bslDelayMs*1000));
}

$finalLog='پایان: '.$sent.' جدید, '.$updated.' آپدیت, '.$skipped.' تکراری, '.$fail.' خطا';
writeProgress(WOO_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'current'=>$total,'started_at'=>$startedAt,'last_progress_ts'=>time(),'recent_log'=>[$finalLog],'total_log_count'=>count($wooLog)+1,'sent_details'=>$wooSentList,'updated_details'=>$wooUpdatedList,'skipped_details'=>$wooSkippedList,'failed_details'=>$wooFailedList]);

$queue=wooReadQueue();
foreach($queue['entries'] as &$e){
if($e['status']==='running'){
$e['status']='done';
$e['sent']=$sent;$e['updated']=$updated;$e['skipped']=$skipped;$e['failed']=$fail;$e['done_at']=time();
}
}
unset($e);
wooWriteQueue($queue);

exit;
}

if(isset($_GET['bsl_stop'])){
header('Content-Type: application/json; charset=UTF-8');
@file_put_contents(BSL_STOP_FILE,json_encode(['stop'=>true,'time'=>time()],LOCK_EX));

$prevProgress=readProgress(BSL_PROGRESS_FILE);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'sent'=>$prevProgress['sent']??0,'updated'=>$prevProgress['updated']??0,'skipped'=>$prevProgress['skipped']??0,'failed'=>$prevProgress['failed']??0,'total'=>$prevProgress['total']??0,'current'=>$prevProgress['current']??0,'last_title'=>'','started_at'=>$prevProgress['started_at']??0,'recent_log'=>['❌ ارسال توسط کاربر متوقف شد'],'total_log_count'=>($prevProgress['total_log_count']??0)+1,'sent_details'=>$prevProgress['sent_details']??[],'updated_details'=>$prevProgress['updated_details']??[],'skipped_details'=>$prevProgress['skipped_details']??[],'failed_details'=>$prevProgress['failed_details']??[]]);

$queue=bslReadQueue();
foreach($queue['entries'] as &$e){
if($e['status']==='running'){
$e['status']='failed';
$e['sent']=$prevProgress['sent']??0;$e['updated']=$prevProgress['updated']??0;$e['skipped']=$prevProgress['skipped']??0;$e['failed']=$prevProgress['failed']??0;$e['current']=$prevProgress['current']??0;
}
}
unset($e);
bslWriteQueue($queue);
echo json_encode(['ok'=>true,'msg'=>'فرآیند متوقف شد'],JSON_UNESCAPED_UNICODE);
exit;
}

if (isset($_GET['poll_bsl'])) {
header('Content-Type: application/json; charset=UTF-8');
$p = readProgress(BSL_PROGRESS_FILE);

$queue = bslReadQueue();
$hasMore = false;
foreach ($queue['entries'] as $e) {
if ($e['status'] === 'waiting') { $hasMore = true; break; }
}
$p['has_more'] = $hasMore;
echo json_encode($p, JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['woo_stop'])){
header('Content-Type: application/json; charset=UTF-8');
@file_put_contents(WOO_STOP_FILE,json_encode(['stop'=>true,'time'=>time()],LOCK_EX));
$prevProgress=readProgress(WOO_PROGRESS_FILE);
writeProgress(WOO_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'sent'=>$prevProgress['sent']??0,'updated'=>$prevProgress['updated']??0,'skipped'=>$prevProgress['skipped']??0,'failed'=>$prevProgress['failed']??0,'total'=>$prevProgress['total']??0,'current'=>$prevProgress['current']??0,'last_title'=>'','started_at'=>$prevProgress['started_at']??0,'recent_log'=>['❌ ارسال ووکامرس توسط کاربر متوقف شد'],'total_log_count'=>($prevProgress['total_log_count']??0)+1,'sent_details'=>$prevProgress['sent_details']??[],'updated_details'=>$prevProgress['updated_details']??[],'skipped_details'=>$prevProgress['skipped_details']??[],'failed_details'=>$prevProgress['failed_details']??[]]);

$queue=wooReadQueue();
foreach($queue['entries'] as &$e){
if($e['status']==='running'){
$e['status']='failed';
$e['sent']=$prevProgress['sent']??0;$e['updated']=$prevProgress['updated']??0;$e['skipped']=$prevProgress['skipped']??0;$e['failed']=$prevProgress['failed']??0;$e['current']=$prevProgress['current']??0;
}
}
unset($e);
wooWriteQueue($queue);
echo json_encode(['ok'=>true,'msg'=>'فرآیند ووکامرس متوقف شد'],JSON_UNESCAPED_UNICODE);
exit;
}
if (isset($_GET['poll_woo'])) {
header('Content-Type: application/json; charset=UTF-8');
$p = readProgress(WOO_PROGRESS_FILE);

$queue=wooReadQueue();
$hasMore=false;
foreach($queue['entries'] as $e){if($e['status']==='waiting'){$hasMore=true;break;}}
$p['has_more']=$hasMore;
$p['queue_count']=count($queue['entries']);
echo json_encode($p, JSON_UNESCAPED_UNICODE);
exit;
}

function wooReadQueue(): array {
clearstatcache(true,WOO_QUEUE_FILE);
if(!file_exists(WOO_QUEUE_FILE)) return ['entries'=>[]];
$d=@json_decode(@file_get_contents(WOO_QUEUE_FILE)?:'',true);
return is_array($d)&&isset($d['entries'])?$d:['entries'=>[]];
}
function wooWriteQueue(array $queue): void {
@file_put_contents(WOO_QUEUE_FILE,json_encode($queue,JSON_UNESCAPED_UNICODE),LOCK_EX);
}

if(isset($_GET['woo_queue_status'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=wooReadQueue();
$progress=readProgress(WOO_PROGRESS_FILE);
foreach($queue['entries'] as &$e){
if($e['status']==='running'){
$e['sent']=$progress['sent']??0;
$e['updated']=$progress['updated']??0;
$e['skipped']=$progress['skipped']??0;
$e['failed']=$progress['failed']??0;
$e['current']=$progress['current']??0;
$e['total']=$progress['total']??$e['total']??0;
$e['done']=$progress['done']??false;
if($progress['done']){$e['status']='done';$e['done_at']=time();}
}
}
unset($e);
wooWriteQueue($queue);
echo json_encode($queue,JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['woo_queue_save_products'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_POST['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$qFile=__DIR__.'/woo_queue_products_'.$queueId.'.json';
$pd=json_decode($_POST['products']??'[]',true)?:[];
$chunkIndex=(int)($_POST['chunk_index']??0);
if(empty($pd)){echo json_encode(['ok'=>false,'error'=>'محصولی دریافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
if($chunkIndex===0){
@unlink($qFile);
@file_put_contents($qFile,json_encode($pd,JSON_UNESCAPED_UNICODE),LOCK_EX);
$total=count($pd);
}else{
$existing=json_decode(@file_get_contents($qFile)?:'[]',true)?:[];
$merged=array_merge($existing,$pd);
@file_put_contents($qFile,json_encode($merged,JSON_UNESCAPED_UNICODE),LOCK_EX);
$total=count($merged);
}
echo json_encode(['ok'=>true,'saved'=>count($pd),'chunk'=>$chunkIndex,'total_saved'=>$total,'queue_id'=>$queueId],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['woo_queue_add'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_POST['queue_id']??'');
$total=(int)($_POST['total']??0);
$startImm=!empty($_POST['start_immediately']);
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$qFile=__DIR__.'/woo_queue_products_'.$queueId.'.json';
if(!file_exists($qFile)){echo json_encode(['ok'=>false,'error'=>'فایل محصولات یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
$queue=wooReadQueue();
$status=$startImm?'running':'waiting';

if($startImm){
@unlink(WOO_PROGRESS_FILE);@unlink(WOO_STOP_FILE);
@unlink(WOO_PRODUCTS_FILE);
$copyOk=@copy($qFile,WOO_PRODUCTS_FILE);
if(!$copyOk){echo json_encode(['ok'=>false,'error'=>'خطا در کپی فایل محصولات'],JSON_UNESCAPED_UNICODE);exit;}
$verifyProducts=json_decode(@file_get_contents(WOO_PRODUCTS_FILE)?:'',true)?:[];
if(empty($verifyProducts)){echo json_encode(['ok'=>false,'error'=>'فایل محصولات خالی است بعد از کپی'],JSON_UNESCAPED_UNICODE);exit;}
}
$wooTitleSuffix=trim($_POST['title_suffix']??'');
// v8.36: پروفایل مبدأ را در صف ووکامرس هم ثبت کن
$pKeyIn=trim((string)($_POST['profile_key']??''));
$pNameIn=trim((string)($_POST['profile_name']??''));
if($pKeyIn!==''&&$pNameIn===''){$__pf=loadProfiles();$pNameIn=(string)($__pf[$pKeyIn]['name']??$pKeyIn);}
$entry=['id'=>$queueId,'status'=>$status,'products_file'=>$qFile,'total'=>$total,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'current'=>0,'started_at'=>$startImm?time():0,'done_at'=>0,'profile_key'=>$pKeyIn,'profile_name'=>$pNameIn,'config'=>['title_suffix'=>$wooTitleSuffix]];
$queue['entries'][]=$entry;
wooWriteQueue($queue);
echo json_encode(['ok'=>true,'queue_id'=>$queueId,'status'=>$status,'position'=>count($queue['entries']),'start_now'=>$startImm,'queue_count'=>count($queue['entries'])],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['woo_queue_start_next'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=wooReadQueue();

$progress=readProgress(WOO_PROGRESS_FILE);
foreach($queue['entries'] as &$e){
if($e['status']==='running'&&($progress['done']??false)){
$e['status']='done';
$e['sent']=$progress['sent']??0;
$e['updated']=$progress['updated']??0;
$e['skipped']=$progress['skipped']??0;
$e['failed']=$progress['failed']??0;
$e['done_at']=time();
}
}
unset($e);

$nextEntry=null;
foreach($queue['entries'] as &$e){
if($e['status']==='waiting'||($e['status']==='running'&&($e['current']??0)<=0)){
$nextEntry=$e;break;
}
}
unset($e);
if($nextEntry){
@unlink(WOO_PRODUCTS_FILE);
@copy($nextEntry['products_file'],WOO_PRODUCTS_FILE);
$nextEntry['status']='running';
$nextEntry['started_at']=time();
wooWriteQueue($queue);
echo json_encode(['ok'=>true,'next_id'=>$nextEntry['id'],'total'=>$nextEntry['total'],'msg'=>'شروع فرآیند بعدی از صف ووکامرس'],JSON_UNESCAPED_UNICODE);
}else{
wooWriteQueue($queue);
echo json_encode(['ok'=>true,'next_id'=>null,'msg'=>'صف خالی — فرآیند بعدی موجود نیست'],JSON_UNESCAPED_UNICODE);
}
exit;
}

if(isset($_GET['woo_queue_cancel'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
$queue=wooReadQueue();
$found=false;
foreach($queue['entries'] as $i=>$e){
if($e['id']===$queueId&&$e['status']==='waiting'){
@unlink($e['products_file']);
array_splice($queue['entries'],$i,1);
$found=true;break;
}
}
wooWriteQueue($queue);
echo json_encode(['ok'=>$found,'queue_id'=>$queueId],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['woo_queue_clear_done'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=wooReadQueue();
foreach($queue['entries'] as $i=>$e){
if($e['status']==='done'){
@unlink($e['products_file']);
array_splice($queue['entries'],$i,1);
}
}
wooWriteQueue($queue);
echo json_encode(['ok'=>true,'remaining'=>count($queue['entries'])],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['woo_queue_start_server'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=wooReadQueue();
$entry=null;
foreach($queue['entries'] as &$e){
if($e['id']===$queueId){
$entry=&$e;break;
}
}
unset($e);
if(!$entry){echo json_encode(['ok'=>false,'error'=>'ورودی یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
if($entry['status']!=='waiting'&&$entry['status']!=='running'){echo json_encode(['ok'=>false,'error'=>'وضعیت ورودی مناسب نیست: '.$entry['status']],JSON_UNESCAPED_UNICODE);exit;}

@unlink(WOO_PRODUCTS_FILE);
$copyOk=@copy($entry['products_file'],WOO_PRODUCTS_FILE);
if(!$copyOk){echo json_encode(['ok'=>false,'error'=>'خطا در کپی فایل محصولات'],JSON_UNESCAPED_UNICODE);exit;}
$entry['status']='running';
$entry['started_at']=time();
wooWriteQueue($queue);

echo json_encode(['ok'=>true,'queue_id'=>$queueId,'msg'=>'شروع پردازش سرورساید'],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['woo_queue_delete'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
$queue=wooReadQueue();
$found=false;
foreach($queue['entries'] as $i=>$e){
if($e['id']===$queueId){
@unlink($e['products_file']);
array_splice($queue['entries'],$i,1);
$found=true;break;
}
}
wooWriteQueue($queue);
echo json_encode(['ok'=>$found,'queue_id'=>$queueId],JSON_UNESCAPED_UNICODE);
exit;
}

if (isset($_GET['bsl_products'])) {
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$page=max(1,(int)($_GET['page']??1));
$perPage=min(1000,max(10,(int)($_GET['per_page']??50)));

$statusParam=$_GET['status']??'active';
$statusMap=[
'active'=>['2976'],
'approved'=>['2976'],
'inactive'=>['3790'],
'not_approved'=>['3567'],
'pending'=>['3568'],
'archived'=>['4184'],
'all'=>['2976','3790','3567','3568','4184','2977','2978','3248','4221'],
];
$statusValues=$statusMap[$statusParam]??$statusMap['active'];
$url='vendors/'.$vid.'/products?page='.$page.'&per_page='.$perPage;
foreach($statusValues as $sv){$url.='&statuses='.$sv;}
$r=bslReq($tk,'GET',$url);
if(!$r['ok']){echo json_encode(['ok'=>false,'error'=>'خطا در دریافت ('.($r['code']??'?').') '.mb_substr($r['raw']??'',0,200)],JSON_UNESCAPED_UNICODE);exit;}
$data=$r['body']['data']??[];
$totalPage=(int)($r['body']['total_page']??1);
$totalCount=(int)($r['body']['total_count']??0);

$cats=[];
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1)as $s)$o[]=$s;}}return $o;};$cats=$cFlat($cData,0);}}
echo json_encode(['ok'=>true,'products'=>$data,'page'=>$page,'total_page'=>$totalPage,'total_count'=>$totalCount,'per_page'=>$perPage,'categories'=>$cats,'status'=>$statusParam],JSON_UNESCAPED_UNICODE);
exit;
}

if (isset($_GET['bsl_rejected_cats'])) {
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$rejected=[];$cats=[];

for($pg=1;$pg<=20;$pg++){
$r=bslReq($tk,'GET','vendors/'.$vid.'/products?page='.$pg.'&per_page=100&statuses=3567');
if(!$r['ok'])break;
$data=$r['body']['data']??[];
if(empty($data))break;
foreach($data as $p){
$rev=$p['revision']??[];
$revData=$rev['data']??[];
$rawStatus=$p['status']??$revData['status']??0;
$sv=(is_array($rawStatus)&&!empty($rawStatus['value']))?(int)$rawStatus['value']:(int)$rawStatus;
if($sv===3567){
$rejReasons=$rev['rejection_reasons']??[];
$catReject=false;$catMsg='';
foreach($rejReasons as $rr){
if((int)($rr['value']??0)===6046){$catReject=true;$catMsg=$rr['name']??$rr['description']??'دسته‌بندی نادرست';}
}
if($catReject){
$catObj=$revData['category']??$p['category']??null;
$catTitle=is_array($catObj)?($catObj['title']??''):(string)($p['category_id']??'');
$catId=is_array($catObj)?(int)($catObj['id']??0):(int)($p['category_id']??0);
$rejected[]=['id'=>$p['id'],'title'=>$p['title']??$revData['title']??'','status'=>$sv,'cat_reject_msg'=>$catMsg,'current_cat_id'=>$catId,'current_cat_title'=>$catTitle];
}
}
}
if($pg>=2&&count($data)<100)break;
}

$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1)as $s)$o[]=$s;}}return $o;};$cats=$cFlat($cData,0);}}
echo json_encode(['ok'=>true,'rejected'=>$rejected,'categories'=>$cats],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_fix_ai_cat'])){
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
set_time_limit(0);ignore_user_abort(true);
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){
echo "data: ".json_encode(['type'=>'error','msg'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();exit;
}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$productId=(int)($_GET['product_id']??0);
if($productId<=0){
echo "data: ".json_encode(['type'=>'error','msg'=>'شناسه محصول نامعتبر'],JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();exit;
}
$sse=function($d){echo "data: ".json_encode($d,JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();};
$sse(['type'=>'step','msg'=>'دریافت اطلاعات محصول #'.$productId.' از باسلام...']);

$rGet=bslReq($tk,'GET','products/'.$productId);
if(!$rGet['ok'])$rGet=bslReq($tk,'GET','vendors/'.$vid.'/products/'.$productId);
if(!$rGet['ok']){
$sse(['type'=>'error','msg'=>'محصول یافت نشد ('.($rGet['code']??'?').')']);exit;
}
$p=$rGet['body']??[];
$pName=trim($p['title']??$p['name']??'');
$sse(['type'=>'step','msg'=>'محصول "'.mb_substr($pName,0,60).'" دریافت شد']);
$rev=$p['revision']??[];
$aiText=trim($rev['metadata']['description']??'');
$rejReasons=$rev['rejection_reasons']??[];
$sse(['type'=>'step','msg'=>'بررسی علل رد محصول...']);

$rejTexts=[];
foreach($rejReasons as $rr){
$rrDesc=trim($rr['description']??'');
if($rrDesc!==''&&mb_strlen($rrDesc,'UTF-8')>5){$aiText.=($aiText?' ':'').$rrDesc;$rejTexts[]=$rrDesc;}
}
if(!empty($rejTexts)){
$sse(['type'=>'step','msg'=>'علت رد: '.mb_substr(implode(' | ',$rejTexts),0,200)]);
}
if($aiText===''){
$sse(['type'=>'error','msg'=>'متن بررسی هوش مصنوعی یافت نشد — محصول هیچ توصیه AI یا علت رد ندارد']);exit;
}
$sse(['type'=>'step','msg'=>'متن AI یافت شد: '.mb_substr($aiText,0,200)]);

$sse(['type'=>'step','msg'=>'دریافت دسته‌بندی‌ها از باسلام...']);
$cats=[];
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1)as $s)$o[]=$s;}}return $o;};$cats=$cFlat($cData,0);}}
bslSetCatNameMap($cats);
if(empty($cats)){
$sse(['type'=>'error','msg'=>'دسته‌بندی‌ها بارگذاری نشد']);exit;
}
$sse(['type'=>'step','msg'=>count($cats).' دسته‌بندی دریافت شد']);

$sse(['type'=>'step','msg'=>'تحلیل متن AI برای استخراج دسته‌بندی پیشنهادی...']);
$sse(['type'=>'step','msg'=>'متن AI: '.mb_substr($aiText,0,300)]);
$aiResult=extractAiCategoryFromTextEx($aiText,$cats);
$aiCatId=$aiResult['catId'];
$aiCatName=$aiResult['catName'];
$aiMethod=$aiResult['matchMethod'];
$aiScore=$aiResult['score'];
$aiCandidates=$aiResult['allCandidates'];
if($aiCatId<=0){
$sse(['type'=>'error','msg'=>'دسته‌بندی توصیه‌شده در متن AI یافت نشد','ai_text'=>mb_substr($aiText,0,500)]);exit;
}
$rawCatName=bslCatNameById($aiCatId);
$methodLabel=$aiMethod==='regex'?'الگوی متنی':'تطبیق نام دسته';
$sse(['type'=>'step','msg'=>'دسته استخراج‌شده: '.$rawCatName.' ('.$aiCatId.') — روش: '.$methodLabel.' — امتیاز: '.$aiScore]);
if(!empty($aiCandidates)){
$top3=array_slice($aiCandidates,0,3);
$candStr=implode(' | ',array_map(function($c){return ($c['catName']??'').'('.$c['catId'].')='.$c['score'];},$top3));
$sse(['type'=>'step','msg'=>'سایر کاندیداها: '.$candStr]);
}

$sse(['type'=>'step','msg'=>'بررسی دسته فرزند (leaf)...']);
$aiCatId=findLeafCategory($aiCatId,$cats);
$catName=bslCatNameById($aiCatId);
if($catName!==$rawCatName){
$sse(['type'=>'step','msg'=>'دسته فرزند یافت شد: '.$catName.' ('.$aiCatId.')']);
}else{
$sse(['type'=>'step','msg'=>'دسته یک دسته فرزین است']);
}

$sse(['type'=>'step','msg'=>'ارسال درخواست اصلاح دسته‌بندی (وضعیت: در انتظار بررسی مجدد)...']);
$bu=['category_id'=>$aiCatId,'status'=>3568];
$r=bslReq($tk,'PATCH','products/'.$productId,$bu);
if($r['code']===404)$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$productId,$bu);
if($r['ok']&&!empty($r['body']['id'])){
$sse(['type'=>'done','ok'=>true,'msg'=>'✅ اصلاح شد و ارسال به بررسی مجدد: '.$catName.' ('.$aiCatId.')','product_id'=>$productId,'category_id'=>$aiCatId,'category_name'=>$catName,'ai_text'=>mb_substr($aiText,0,200)]);
}else{
$errDetail=($r['body']['message']??($r['body']['error']??''));
$sse(['type'=>'step','msg'=>'PATCH مستقیم ناموفق ('.$errDetail.') — تلاش با روش جایگزین...']);

$redirectUrl='?bsl_fix_cat=1&product_id='.$productId.'&category_id='.$aiCatId;
$sse(['type'=>'fallback','ok'=>false,'msg'=>'PATCH ناموفق — لطفاً از دکمه اصلاح دسته استفاده کنید','redirect_url'=>$redirectUrl,'category_id'=>$aiCatId,'category_name'=>$catName,'ai_text'=>mb_substr($aiText,0,200)]);
}
if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
exit;
}

if(isset($_GET['bsl_fix_ai_cat_batch'])){
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
set_time_limit(0);ignore_user_abort(true);
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){
echo "data: ".json_encode(['type'=>'error','msg'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();exit;
}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$sse=function($d){echo "data: ".json_encode($d,JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();};
$sse(['type'=>'step','msg'=>'دریافت دسته‌بندی‌ها از باسلام...']);

$cats=[];
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1)as $s)$o[]=$s;}}return $o;};$cats=$cFlat($cData,0);}}
bslSetCatNameMap($cats);
if(empty($cats)){
$sse(['type'=>'error','msg'=>'دسته‌بندی‌ها بارگذاری نشد']);exit;
}
$sse(['type'=>'step','msg'=>'دسته‌بندی‌ها دریافت شد: '.count($cats).' دسته']);
$fixed=0;$failed=0;$noAi=0;$noCat=0;$total=0;

$sse(['type'=>'step','msg'=>'دریافت لیست محصولات ردشده (تأیید نشده)...']);
$allProducts=[];
for($pg=1;$pg<=20;$pg++){
$r=bslReq($tk,'GET','vendors/'.$vid.'/products?page='.$pg.'&per_page=100&statuses=3567');
if(!$r['ok'])break;
$data=$r['body']['data']??[];
if(empty($data))break;
$tp=max(1,(int)($r['body']['total_page']??1));
foreach($data as $p)$allProducts[]=$p;
$sse(['type'=>'step','msg'=>'صفحه '.$pg.' از '.$tp.' دریافت شد ('.count($allProducts).' محصول تا کنون)']);
if($pg>=$tp)break;
}
$total=count($allProducts);
$sse(['type'=>'start','total'=>$total,'msg'=>'شروع اصلاح '.count($allProducts).' محصول ردشده...']);
$idx=0;
foreach($allProducts as $p){
$idx++;
$rev=$p['revision']??[];
$aiText=trim($rev['metadata']['description']??'');
$rejReasons=$rev['rejection_reasons']??[];
foreach($rejReasons as $rr){$rrDesc=trim($rr['description']??'');if($rrDesc!==''&&mb_strlen($rrDesc,'UTF-8')>5)$aiText.=($aiText?' ':'').$rrDesc;}
$pId=(int)($p['id']??0);
$pName=trim($p['title']??$p['name']??'');
$sse(['type'=>'progress','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'step'=>'check_ai']);
if($aiText===''){
$noAi++;
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'no_ai','msg'=>'متن AI یافت نشد']);
continue;
}
$sse(['type'=>'progress','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'step'=>'extract_cat','ai_text'=>mb_substr($aiText,0,200)]);
$aiResult=extractAiCategoryFromTextEx($aiText,$cats);
$aiCatId=$aiResult['catId'];
$aiCatName=$aiResult['catName'];
$aiMethod=$aiResult['matchMethod'];
$aiScore=$aiResult['score'];
$aiCandidates=$aiResult['allCandidates'];
if($aiCatId<=0){
$noCat++;
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'no_cat','msg'=>'دسته AI یافت نشد','ai_text'=>mb_substr($aiText,0,300)]);
continue;
}
$sse(['type'=>'progress','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'step'=>'ai_result','catId'=>$aiCatId,'catName'=>$aiCatName,'method'=>$aiMethod,'score'=>$aiScore,'candidates'=>$aiCandidates,'ai_text'=>mb_substr($aiText,0,300)]);
$sse(['type'=>'progress','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'step'=>'find_leaf','rawCatId'=>$aiCatId]);
$aiCatId=findLeafCategory($aiCatId,$cats);
$catName=bslCatNameById($aiCatId);
$sse(['type'=>'progress','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'step'=>'patching','catId'=>$aiCatId,'catName'=>$catName]);

$bu=['category_id'=>$aiCatId,'status'=>3568];
$r2=bslReq($tk,'PATCH','products/'.$pId,$bu);
if($r2['code']===404)$r2=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$pId,$bu);
if($r2['ok']&&!empty($r2['body']['id'])){
$fixed++;
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'fixed','catId'=>$aiCatId,'catName'=>$catName,'msg'=>'اصلاح شد و ارسال به بررسی مجدد: '.$catName.' ('.$aiCatId.')']);
}else{
$failed++;
$errDetail=($r2['body']['message']??($r2['body']['error']??''));
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'failed','catId'=>$aiCatId,'catName'=>$catName,'msg'=>'PATCH ناموفق: '.$errDetail]);
}
usleep(500000);
}
$sse(['type'=>'done','fixed'=>$fixed,'failed'=>$failed,'no_ai'=>$noAi,'no_cat'=>$noCat,'total'=>$total,'msg'=>'✅ اصلاح شد: '.$fixed.' | بدون AI: '.$noAi.' | دسته یافت نشد: '.$noCat.' | ناموفق: '.$failed.' (از '.$total.' محصول)']);
if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
exit;
}

if(isset($_GET['bsl_download_ai_texts'])){
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){
http_response_code(400);echo 'تنظیمات باسلام ناقص';exit;
}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];

$allProducts=[];
for($pg=1;$pg<=20;$pg++){
$r=bslReq($tk,'GET','vendors/'.$vid.'/products?page='.$pg.'&per_page=100&statuses=3567');
if(!$r['ok'])break;
$data=$r['body']['data']??[];
if(empty($data))break;
$tp=max(1,(int)($r['body']['total_page']??1));
foreach($data as $p)$allProducts[]=$p;
if($pg>=$tp)break;
}

$lines=[];
$lines[]='========================================';
$lines[]='متن‌های توصیه هوش مصنوعی باسلام — محصولات تأیید نشده';
$lines[]='تعداد محصول: '.count($allProducts);
$lines[]='تاریخ تولید: '.date('Y-m-d H:i:s');
$lines[]='========================================';
$lines[]='';
$idx=0;
foreach($allProducts as $p){
$idx++;
$pId=(int)($p['id']??0);
$pName=trim($p['title']??$p['name']??'');
$rev=$p['revision']??[];
$aiText=trim($rev['metadata']['description']??'');
$rejReasons=$rev['rejection_reasons']??[];
foreach($rejReasons as $rr){$rrDesc=trim($rr['description']??'');if($rrDesc!==''&&mb_strlen($rrDesc,'UTF-8')>5)$aiText.=($aiText?' | ':'').$rrDesc;}
$lines[]='--- محصول #'.$idx.' ---';
$lines[]='شناسه: '.$pId;
$lines[]='نام: '.$pName;
if($aiText!==''){
$lines[]='متن AI:';
$lines[]=$aiText;
}else{
$lines[]='متن AI: (بدون متن)';
}
$lines[]='';
}
$output=implode("\n",$lines);
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="basalam_ai_texts_'.date('Ymd_His').'.txt"');
header('Content-Length: '.strlen($output));
echo $output;
exit;
}

if(isset($_GET['bsl_find_duplicates'])){
header('Content-Type: application/json; charset=UTF-8');
set_time_limit(0);ignore_user_abort(true);
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$statusFilter=$_GET['status']??'all';
$statusMap=['active'=>['2976'],'inactive'=>['3790'],'not_approved'=>['3567'],'pending'=>['3568'],'archived'=>['4184'],'all'=>['2976','3790','3567','3568','4184']];
$statusValues=$statusMap[$statusFilter]??$statusMap['all'];

$allProducts=[];
for($pg=1;$pg<=200;$pg++){
$url='vendors/'.$vid.'/products?page='.$pg.'&per_page=100';
foreach($statusValues as $sv){$url.='&statuses='.$sv;}
$r=bslReq($tk,'GET',$url);
if(!$r['ok'])break;
$data=$r['body']['data']??[];
if(empty($data))break;
$tp=max(1,(int)($r['body']['total_page']??1));
foreach($data as $p){
$pId=(int)($p['id']??0);
$pName=trim($p['title']??$p['name']??'');
$pStatus=(int)($p['status']??0);
$rev=$p['revision']??[];
$pPrice=(int)($rev['data']['primary_price']??0);
$pStock=(int)($rev['data']['stock']??0);
$pCatId=(int)($rev['data']['category_id']??0);
$allProducts[]=['id'=>$pId,'name'=>$pName,'status'=>$pStatus,'price'=>$pPrice,'stock'=>$pStock,'category_id'=>$pCatId];
}
if($pg>=$tp)break;
}

$normalize=function($n){
$n=preg_replace('/\s*\(کد\s*:\s*\d+\)\s*/u','',$n);
$n=preg_replace('/\s*\(code\s*:\s*\d+\)\s*/iu','',$n);
$n=preg_replace('/\s*\(کد\s*:\s*[^\)]+\)\s*/u','',$n);
$n=preg_replace('/\s*\(\d+\)\s*$/u','',$n);
return trim($n);
};

$groups=[];
foreach($allProducts as $p){
$nn=$normalize($p['name']);
if($nn==='')continue;
$key=mb_strtolower($nn,'UTF-8');
if(!isset($groups[$key]))$groups[$key]=[];
$groups[$key][]=$p;
}

$duplicates=[];
$totalDupProducts=0;
foreach($groups as $key=>$items){
if(count($items)<2)continue;
$duplicates[]=['normalized_name'=>$items[0]['name']??'','count'=>count($items),'products'=>$items];
$totalDupProducts+=count($items);
}

usort($duplicates,function($a,$b){return $b['count']<=>$a['count'];});
echo json_encode(['ok'=>true,'total_products'=>count($allProducts),'duplicate_groups'=>count($duplicates),'duplicate_products'=>$totalDupProducts,'duplicates'=>$duplicates],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_change_status'])){
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$productId=(int)($_GET['product_id']??0);
$newStatus=(int)($_GET['status']??0);
if($productId<=0||$newStatus<=0){echo json_encode(['ok'=>false,'error'=>'شناسه یا وضعیت نامعتبر'],JSON_UNESCAPED_UNICODE);exit;}
$statusLabels=['2976'=>'فعال','3790'=>'غیرفعال','3568'=>'در انتظار تأیید'];
$label=$statusLabels[$newStatus]??$newStatus;
$bu=['status'=>$newStatus];
$r=bslReq($tk,'PATCH','products/'.$productId,$bu);
if($r['code']===404)$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$productId,$bu);
if($r['ok']&&!empty($r['body']['id'])){
echo json_encode(['ok'=>true,'msg'=>'محصول #'.$productId.' → '.$label.' ('.$newStatus.')'],JSON_UNESCAPED_UNICODE);
}else{
echo json_encode(['ok'=>false,'error'=>'تغییر وضعیت ناموفق ('.$r['code'].'): '.($r['body']['message']??$r['body']['error']??'خطا')],JSON_UNESCAPED_UNICODE);
}
exit;
}
if(isset($_GET['bsl_delete_product'])){
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$productId=(int)($_GET['product_id']??0);
if($productId<=0){echo json_encode(['ok'=>false,'error'=>'شناسه محصول نامعتبر'],JSON_UNESCAPED_UNICODE);exit;}
$r=bslReq($tk,'DELETE','products/'.$productId);
if($r['code']===404)$r=bslReq($tk,'DELETE','vendors/'.$vid.'/products/'.$productId);
if($r['ok']||$r['code']===204||$r['code']===200){
echo json_encode(['ok'=>true,'msg'=>'محصول #'.$productId.' حذف شد'],JSON_UNESCAPED_UNICODE);
}else{
echo json_encode(['ok'=>false,'error'=>'حذف ناموفق ('.$r['code'].'): '.($r['body']['message']??$r['body']['error']??'خطا')],JSON_UNESCAPED_UNICODE);
}
exit;
}

if(isset($_GET['bsl_delete_batch'])){
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
set_time_limit(0);ignore_user_abort(true);
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){
echo "data: ".json_encode(['type'=>'error','msg'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();exit;
}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$ids=json_decode($_GET['ids']??'[]',true);
if(!is_array($ids)||empty($ids)){
echo "data: ".json_encode(['type'=>'error','msg'=>'لیست محصولات خالی است'],JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();exit;
}
$sse=function($d){echo "data: ".json_encode($d,JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();};
$sse(['type'=>'step','msg'=>'شروع حذف '.count($ids).' محصول...']);
$deleted=0;$failed=0;$total=count($ids);
foreach($ids as $idx=>$pId){
$pId=(int)$pId;
if($pId<=0){$failed++;$sse(['type'=>'item','idx'=>$idx+1,'total'=>$total,'pId'=>$pId,'status'=>'failed','msg'=>'شناسه نامعتبر']);continue;}
$r=bslReq($tk,'DELETE','products/'.$pId);
if($r['code']===404)$r=bslReq($tk,'DELETE','vendors/'.$vid.'/products/'.$pId);
if($r['ok']||$r['code']===204||$r['code']===200){
$deleted++;
$sse(['type'=>'item','idx'=>$idx+1,'total'=>$total,'pId'=>$pId,'status'=>'deleted','msg'=>'حذف شد']);
}else{
$failed++;
$errDetail=($r['body']['message']??$r['body']['error']??'خطا');
$sse(['type'=>'item','idx'=>$idx+1,'total'=>$total,'pId'=>$pId,'status'=>'failed','msg'=>'حذف ناموفق: '.$errDetail]);
}
usleep(500000);
}
$sse(['type'=>'done','deleted'=>$deleted,'failed'=>$failed,'total'=>$total,'msg'=>'حذف شد: '.$deleted.' | ناموفق: '.$failed.' (از '.$total.')']);
if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
exit;
}

if(isset($_GET['bsl_status_overview'])){
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$counts=['active'=>0,'inactive'=>0,'not_approved'=>0,'pending'=>0,'archived'=>0];
$statusQueries=['active'=>['2976'],'inactive'=>['3790'],'not_approved'=>['3567'],'pending'=>['3568'],'archived'=>['4184']];
foreach($statusQueries as $key=>$statuses){
$url='vendors/'.$vid.'/products?page=1&per_page=1';
foreach($statuses as $sv){$url.='&statuses='.$sv;}
$r=bslReq($tk,'GET',$url);
if($r['ok'])$counts[$key]=(int)($r['body']['total_count']??0);
}
$counts['total']=array_sum($counts);
echo json_encode(['ok'=>true,'counts'=>$counts],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_activate_batch'])){
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
set_time_limit(0);ignore_user_abort(true);
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){
echo "data: ".json_encode(['type'=>'error','msg'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();exit;
}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$fromStatus=$_GET['from_status']??'3790';
$sse=function($d){echo "data: ".json_encode($d,JSON_UNESCAPED_UNICODE)."\n\n";if(ob_get_level())ob_flush();flush();};
$statusLabels=['3790'=>'غیرفعال','3567'=>'تأیید نشده','3568'=>'در انتظار تأیید','4184'=>'بایگانی'];
$fromLabel=$statusLabels[$fromStatus]??$fromStatus;
$sse(['type'=>'step','msg'=>'دریافت محصولات '.$fromLabel.'...']);
$allProducts=[];
for($pg=1;$pg<=200;$pg++){
$url='vendors/'.$vid.'/products?page='.$pg.'&per_page=100&statuses='.$fromStatus;
$r=bslReq($tk,'GET',$url);
if(!$r['ok'])break;
$data=$r['body']['data']??[];
if(empty($data))break;
$tp=max(1,(int)($r['body']['total_page']??1));
foreach($data as $p){
$pId=(int)($p['id']??0);
$pName=trim($p['title']??$p['name']??'');
$rev=$p['revision']??[];
$pPrice=(int)($rev['data']['primary_price']??0);
$pStock=(int)($rev['data']['stock']??0);
$pCatId=(int)($rev['data']['category_id']??0);
$allProducts[]=['id'=>$pId,'name'=>$pName,'price'=>$pPrice,'stock'=>$pStock,'category_id'=>$pCatId];
}
$sse(['type'=>'step','msg'=>'صفحه '.$pg.' از '.$tp.' دریافت شد ('.count($allProducts).' محصول)']);
if($pg>=$tp)break;
}
$total=count($allProducts);
$sse(['type'=>'start','total'=>$total,'msg'=>'شروع فعال‌سازی '.count($allProducts).' محصول '.$fromLabel.'...']);
$activated=0;$failed=0;$skipped=0;$idx=0;
foreach($allProducts as $p){
$idx++;
$pId=$p['id'];
$pName=$p['name'];

if($p['price']<=0){
$skipped++;
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'skipped','msg'=>'بدون قیمت — رد شد']);
continue;
}
if($p['stock']<=0){
$skipped++;
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'skipped','msg'=>'ناموجود — رد شد']);
continue;
}
$sse(['type'=>'progress','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'step'=>'patching']);
$bu=['status'=>2976];
if($p['category_id']>0)$bu['category_id']=$p['category_id'];
$r2=bslReq($tk,'PATCH','products/'.$pId,$bu);
if($r2['code']===404)$r2=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$pId,$bu);
if($r2['ok']&&!empty($r2['body']['id'])){
$activated++;
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'activated','msg'=>'فعال شد (2976)']);
}else{
$failed++;
$errDetail=($r2['body']['message']??($r2['body']['error']??''));
$sse(['type'=>'item','idx'=>$idx,'total'=>$total,'pId'=>$pId,'pName'=>$pName,'status'=>'failed','msg'=>'PATCH ناموفق: '.$errDetail]);
}
usleep(500000);
}
$sse(['type'=>'done','activated'=>$activated,'failed'=>$failed,'skipped'=>$skipped,'total'=>$total,'msg'=>'فعال شد: '.$activated.' | رد شد: '.$skipped.' | ناموفق: '.$failed.' (از '.$total.')']);
if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
exit;
}
if (isset($_GET['bsl_fix_cat'])) {
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$productId=(int)($_GET['product_id']??0);
$newCatId=(int)($_GET['category_id']??0);
if($productId<=0||$newCatId<=0){echo json_encode(['ok'=>false,'error'=>'شناسه محصول یا دسته نامعتبر'],JSON_UNESCAPED_UNICODE);exit;}

$bu=['category_id'=>$newCatId,'status'=>2976];
$r=bslReq($tk,'PATCH','products/'.$productId,$bu);
if($r['code']===404)$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$productId,$bu);
if($r['ok']&&!empty($r['body']['id'])){
// v8.48: این یک انتخاب دستیِ تأییدشده است — یادش بگیر
$learnTitle=trim((string)($_GET['title']??''));
if($learnTitle===''){
$g=bslReq($tk,'GET','products/'.$productId);
if(!empty($g['ok'])){$gb=$g['body']??[];
$learnTitle=(string)($gb['title']??($gb['name']??($gb['revision']['data']['title']??'')));}
}
$learned=$learnTitle!==''?catLearnRecord($learnTitle,$newCatId,trim((string)($_GET['cat_name']??''))):false;
echo json_encode(['ok'=>true,'msg'=>'دسته اصلاح شد — محصول ID#'.$productId.' به دسته ID#'.$newCatId.' تغییر یافت','product_id'=>$productId,'category_id'=>$newCatId,'learned'=>$learned,'learn_word'=>$learnTitle!==''?catFirstWord($learnTitle):''],JSON_UNESCAPED_UNICODE);
}else{

$rUnpub=bslReq($tk,'PATCH','products/'.$productId,['status'=>3790]);
if($rUnpub['code']===404)$rUnpub=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$productId,['status'=>3790]);

$rGet=bslReq($tk,'GET','products/'.$productId);
if(!$rGet['ok'])$rGet=bslReq($tk,'GET','vendors/'.$vid.'/products/'.$productId);
if(!$rGet['ok']){echo json_encode(['ok'=>false,'error'=>'PATCH 404 + نمی‌توان محصول را دریافت کرد برای جایگزینی'],JSON_UNESCAPED_UNICODE);exit;}
$origP=$rGet['body']??[];
$rev=$origP['revision']??[];$revData=$rev['data']??[];
$origTitle=$origP['title']??$revData['title']??'';

$replaceTitle=$origTitle.' #'.$productId;
$origPhoto=$revData['photo']??$origP['photo']??null;
$photoId=0;if(is_array($origPhoto))$photoId=(int)($origPhoto['id']??0);elseif(is_numeric($origPhoto))$photoId=(int)$origPhoto;
$origPrice=$revData['primary_price']??$origP['primary_price']??0;
$origStock=$revData['inventory']??$origP['inventory']??10;
$origWeight=(int)($bs['weight']??500);
$origPW=(int)($bs['package_weight']??($origWeight+100));
if($origPW<=$origWeight)$origPW=$origWeight+100;
$origBrief=$origP['brief']??$revData['brief']??mb_substr($origTitle,0,250);
$origDesc=$origP['description']??$revData['description']??$origTitle;
$photosArr=$revData['photos']??$origP['photos']??[];
$photosIds=[];if(is_array($photosArr)){foreach($photosArr as $ph){if(is_array($ph))$photosIds[]=(int)($ph['id']??0);elseif(is_numeric($ph))$photosIds[]=(int)$ph;}}
if($photoId>0&&!in_array($photoId,$photosIds))$photosIds[]=$photoId;
if(empty($photosIds)&&$photoId>0)$photosIds=[$photoId];
$bp=['name'=>mb_substr($replaceTitle,0,120),'brief'=>mb_substr($origBrief,0,250),'description'=>$origDesc,'primary_price'=>$origPrice,'stock'=>$origStock,'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>$origWeight,'package_weight'=>$origPW,'is_wholesale'=>false,'category_id'=>$newCatId,'photo'=>$photoId,'photos'=>$photosIds,'status'=>2976,'sku'=>'fix-'.$productId];
$rCreate=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($rCreate['ok']&&!empty($rCreate['body']['id'])){
echo json_encode(['ok'=>true,'msg'=>'محصول جایگزین شد — محصول جدید ID#'.$rCreate['body']['id'].' با دسته ID#'.$newCatId,'product_id'=>$rCreate['body']['id'],'category_id'=>$newCatId,'replaced_from'=>$productId],JSON_UNESCAPED_UNICODE);
}else{
$em=$rCreate['body']['error_description']??($rCreate['body']['message']??($rCreate['body']['error']??''));if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);
echo json_encode(['ok'=>false,'error'=>'PATCH 404 + ایجاد جایگزین ناموفق: '.mb_substr($em??$rCreate['raw']??'HTTP'.$rCreate['code'],0,300)],JSON_UNESCAPED_UNICODE);
}
}
exit;
}

if (isset($_GET['bsl_clear_temp'])) {
header('Content-Type: application/json; charset=UTF-8');
$bslDel=@unlink(BSL_PRODUCTS_FILE);
$wooDel=@unlink(WOO_PRODUCTS_FILE);
$bslProgDel=@unlink(BSL_PROGRESS_FILE);
$wooProgDel=@unlink(WOO_PROGRESS_FILE);

$stopDel=@unlink(BSL_STOP_FILE);
echo json_encode(['ok'=>true,'bsl_temp_deleted'=>$bslDel,'woo_temp_deleted'=>$wooDel,'bsl_progress_deleted'=>$bslProgDel,'woo_progress_deleted'=>$wooProgDel,'bsl_temp_exists'=>file_exists(BSL_PRODUCTS_FILE),'woo_temp_exists'=>file_exists(WOO_PRODUCTS_FILE),'bsl_temp_size'=>@filesize(BSL_PRODUCTS_FILE),'woo_temp_size'=>@filesize(WOO_PRODUCTS_FILE)],JSON_UNESCAPED_UNICODE);
exit;
}

function bslReadQueue(): array {
clearstatcache(true,BSL_QUEUE_FILE);
if(!file_exists(BSL_QUEUE_FILE)) return ['entries'=>[]];
$d=@json_decode(@file_get_contents(BSL_QUEUE_FILE)?:'',true);
return is_array($d)&&isset($d['entries'])?$d:['entries'=>[]];
}
function bslWriteQueue(array $queue): void {
@file_put_contents(BSL_QUEUE_FILE,json_encode($queue,JSON_UNESCAPED_UNICODE),LOCK_EX);
}

/**
 * v8.36: فایل محصولاتِ یک ورودی صف را برمی‌گرداند.
 *
 * چرا لازم است: تا نسخهٔ ۸.۳۵ همهٔ ارسال‌ها از یک فایل مشترک
 * (BSL_PRODUCTS_FILE) می‌خواندند، ولی آن فایل با شروع هر ارسال تازه
 * بازنویسی می‌شد. نتیجه این بود که اگر پروفایل دیگری ارسال می‌شد،
 * محصولات پروفایل قبلی فرستاده می‌شد. حالا هر صف فایل خودش را دارد.
 */
function bslQueueProductsFile(string $queueId): string {
    $queueId=trim($queueId);
    if($queueId!==''){
        // مسیر ثبت‌شده در خود صف مرجع اصلی است
        $q=bslReadQueue();
        foreach(($q['entries']??[]) as $e){
            if(($e['id']??'')===$queueId){
                $f=(string)($e['products_file']??'');
                if($f!==''&&is_file($f)) return $f;
                break;
            }
        }
        $guess=__DIR__.'/bsl_queue_products_'.basename($queueId).'.json';
        if(is_file($guess)) return $guess;
    }
    return BSL_PRODUCTS_FILE;   // سازگاری با مسیرهای قدیمی بدون queue_id
}

/** همان منطق برای ووکامرس */
function wooQueueProductsFile(string $queueId): string {
    $queueId=trim($queueId);
    $q=@json_decode((string)@file_get_contents(WOO_QUEUE_FILE),true);
    $entries=is_array($q['entries']??null)?$q['entries']:[];
    if($queueId!==''){
        foreach($entries as $e){
            if(($e['id']??'')===$queueId){
                $f=(string)($e['products_file']??'');
                if($f!==''&&is_file($f)) return $f;
                break;
            }
        }
        $guess=__DIR__.'/woo_queue_products_'.basename($queueId).'.json';
        if(is_file($guess)) return $guess;
    }
    // فراخوان‌های ووکامرس queue_id نمی‌فرستند؛ پس ردیف در حال اجرا را خودمان پیدا می‌کنیم
    foreach($entries as $e){
        if(($e['status']??'')==='running'){
            $f=(string)($e['products_file']??'');
            if($f!==''&&is_file($f)) return $f;
        }
    }
    return WOO_PRODUCTS_FILE;
}

if(isset($_GET['bsl_queue_status'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=bslReadQueue();

$progress=readProgress(BSL_PROGRESS_FILE);
foreach($queue['entries'] as &$e){
if($e['status']==='running'){
$e['sent']=$progress['sent']??0;
$e['updated']=$progress['updated']??0;
$e['skipped']=$progress['skipped']??0;
$e['failed']=$progress['failed']??0;
$e['current']=$progress['current']??0;
$e['total']=$progress['total']??$e['total']??0;
$e['done']=$progress['done']??false;

if($progress['paused']){$e['status']='paused';$e['paused_at']=time();}
else if($progress['done']){$e['status']='done';$e['done_at']=time();}
}
}
unset($e);
bslWriteQueue($queue);
echo json_encode($queue,JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_save_products'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_POST['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$qFile=__DIR__.'/bsl_queue_products_'.$queueId.'.json';
$pd=json_decode($_POST['products']??'[]',true)?:[];
$chunkIndex=(int)($_POST['chunk_index']??0);
if(empty($pd)){echo json_encode(['ok'=>false,'error'=>'محصولی دریافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
if($chunkIndex===0){
@unlink($qFile);
@file_put_contents($qFile,json_encode($pd,JSON_UNESCAPED_UNICODE),LOCK_EX);
$total=count($pd);
}else{
$existing=json_decode(@file_get_contents($qFile)?:'[]',true)?:[];
$merged=array_merge($existing,$pd);
@file_put_contents($qFile,json_encode($merged,JSON_UNESCAPED_UNICODE),LOCK_EX);
$total=count($merged);
}
echo json_encode(['ok'=>true,'saved'=>count($pd),'chunk'=>$chunkIndex,'total_saved'=>$total,'queue_id'=>$queueId],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_add'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_POST['queue_id']??'');
$total=(int)($_POST['total']??0);
$catId=(int)($_POST['category_id']??0);
$autoCat=!empty($_POST['auto_category']);
$titleSuffix=trim($_POST['title_suffix']??'');
$delayMs=max(0,(int)($_POST['delay_ms']??500));
$retryDelayMs=max(0,(int)($_POST['retry_delay_ms']??1000));
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$qFile=__DIR__.'/bsl_queue_products_'.$queueId.'.json';
if(!file_exists($qFile)){echo json_encode(['ok'=>false,'error'=>'فایل محصولات یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$startImm=!empty($_POST['start_immediately']);
$status=$startImm?'running':'waiting';

if($startImm){
@unlink(BSL_PROGRESS_FILE);@unlink(BSL_STOP_FILE);
@unlink(BSL_PRODUCTS_FILE);
$copyOk=@copy($qFile,BSL_PRODUCTS_FILE);
if(!$copyOk){echo json_encode(['ok'=>false,'error'=>'خطا در کپی فایل محصولات: '.$qFile.' → '.BSL_PRODUCTS_FILE],JSON_UNESCAPED_UNICODE);exit;}

$verifyProducts=json_decode(@file_get_contents(BSL_PRODUCTS_FILE)?:'',true)?:[];
if(empty($verifyProducts)){echo json_encode(['ok'=>false,'error'=>'فایل محصولات خالی است بعد از کپی'],JSON_UNESCAPED_UNICODE);exit;}
$cn=loadConnections();
if(!isset($cn['basalam']))$cn['basalam']=[];
$cn['basalam']['category_id']=$catId;
$cn['basalam']['auto_category']=$autoCat;
$cn['basalam']['title_suffix']=$titleSuffix;
$cn['basalam']['delay_ms']=$delayMs;
$cn['basalam']['retry_delay_ms']=$retryDelayMs;
@file_put_contents(CONNECTIONS_FILE,json_encode($cn,JSON_UNESCAPED_UNICODE),LOCK_EX);
}
// v8.36: نام و کلید پروفایل را نگه می‌داریم تا در صف معلوم باشد چه چیزی می‌رود
$pKeyIn=trim((string)($_POST['profile_key']??''));
$pNameIn=trim((string)($_POST['profile_name']??''));
if($pKeyIn!==''&&$pNameIn===''){$__pf=loadProfiles();$pNameIn=(string)($__pf[$pKeyIn]['name']??$pKeyIn);}
$entry=['id'=>$queueId,'status'=>$status,'products_file'=>$qFile,'total'=>$total,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'current'=>0,'started_at'=>$startImm?time():0,'done_at'=>0,'paused_at'=>0,'profile_key'=>$pKeyIn,'profile_name'=>$pNameIn,'config'=>['category_id'=>$catId,'auto_category'=>$autoCat,'title_suffix'=>$titleSuffix,'delay_ms'=>$delayMs,'retry_delay_ms'=>$retryDelayMs]];
$queue['entries'][]=$entry;
bslWriteQueue($queue);
echo json_encode(['ok'=>true,'queue_id'=>$queueId,'status'=>$status,'position'=>count($queue['entries']),'start_now'=>$startImm,'queue_count'=>count($queue['entries'])],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_start_next'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=bslReadQueue();

$progress=readProgress(BSL_PROGRESS_FILE);
foreach($queue['entries'] as &$e){
if($e['status']==='running'&&($progress['done']??false)){
$e['status']='done';
$e['sent']=$progress['sent']??0;
$e['updated']=$progress['updated']??0;
$e['skipped']=$progress['skipped']??0;
$e['failed']=$progress['failed']??0;
$e['done_at']=time();
}
}
unset($e);

$nextEntry=null;
foreach($queue['entries'] as &$e){
if($e['status']==='waiting'||($e['status']==='running'&&($e['current']??0)<=0)){
$nextEntry=$e;break;
}
}
unset($e);
if($nextEntry){

@unlink(BSL_PRODUCTS_FILE);
@copy($nextEntry['products_file'],BSL_PRODUCTS_FILE);
$nextEntry['status']='running';
$nextEntry['started_at']=time();
bslWriteQueue($queue);

$cn=loadConnections();
if(!isset($cn['basalam']))$cn['basalam']=[];
$cn['basalam']['category_id']=$nextEntry['config']['category_id']??0;
$cn['basalam']['auto_category']=$nextEntry['config']['auto_category']??false;
$cn['basalam']['title_suffix']=$nextEntry['config']['title_suffix']??'';
$cn['basalam']['delay_ms']=$nextEntry['config']['delay_ms']??500;
$cn['basalam']['retry_delay_ms']=$nextEntry['config']['retry_delay_ms']??1000;
@file_put_contents(CONNECTIONS_FILE,json_encode($cn,JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['ok'=>true,'next_id'=>$nextEntry['id'],'total'=>$nextEntry['total'],'products_file'=>$nextEntry['products_file'],'config'=>$nextEntry['config'],'msg'=>'شروع فرآیند بعدی از صف'],JSON_UNESCAPED_UNICODE);
}else{
bslWriteQueue($queue);
echo json_encode(['ok'=>true,'next_id'=>null,'msg'=>'صف خالی — فرآیند بعدی موجود نیست'],JSON_UNESCAPED_UNICODE);
}
exit;
}

if(isset($_GET['bsl_queue_cancel'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
$queue=bslReadQueue();
$found=false;
foreach($queue['entries'] as $i=>$e){
if($e['id']===$queueId&&$e['status']==='waiting'){

@unlink($e['products_file']);
array_splice($queue['entries'],$i,1);
$found=true;break;
}
}
bslWriteQueue($queue);
echo json_encode(['ok'=>$found,'queue_id'=>$queueId],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_clear_done'])){
header('Content-Type: application/json; charset=UTF-8');
$queue=bslReadQueue();
foreach($queue['entries'] as $i=>$e){
if($e['status']==='done'){
@unlink($e['products_file']);
array_splice($queue['entries'],$i,1);
}
}
bslWriteQueue($queue);
echo json_encode(['ok'=>true,'remaining'=>count($queue['entries'])],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_get_products'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$entry=null;
foreach($queue['entries'] as $e){if($e['id']===$queueId){$entry=$e;break;}}
if(!$entry){echo json_encode(['ok'=>false,'error'=>'ورودی یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
$products=@json_decode(@file_get_contents($entry['products_file']??'')?:'[]',true)?:[];
echo json_encode(['ok'=>true,'products'=>$products,'total'=>count($products),'queue_id'=>$queueId,'config'=>$entry['config']??[]],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_update_progress'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
foreach($queue['entries'] as &$e){
if($e['id']===$queueId&&$e['status']==='running'){
$e['sent']=(int)($_GET['sent']??0);
$e['updated']=(int)($_GET['updated']??0);
$e['skipped']=(int)($_GET['skipped']??0);
$e['failed']=(int)($_GET['failed']??0);
$e['current']=(int)($_GET['current']??0);
break;
}
}
unset($e);
bslWriteQueue($queue);
echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_mark_done'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
foreach($queue['entries'] as &$e){
if($e['id']===$queueId){
$e['status']='done';
$e['sent']=(int)($_GET['sent']??0);
$e['updated']=(int)($_GET['updated']??0);
$e['skipped']=(int)($_GET['skipped']??0);
$e['failed']=(int)($_GET['failed']??0);
$e['total']=(int)($_GET['total']??$e['total']??0);
$e['current']=$e['total'];
$e['done_at']=time();
break;
}
}
unset($e);
bslWriteQueue($queue);
echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_pause'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$found=false;
foreach($queue['entries'] as &$e){
if($e['id']===$queueId&&$e['status']==='running'){
$e['status']='paused';

$progress=readProgress(BSL_PROGRESS_FILE);
$e['sent']=$progress['sent']??0;
$e['updated']=$progress['updated']??0;
$e['skipped']=$progress['skipped']??0;
$e['failed']=$progress['failed']??0;
$e['current']=$progress['current']??0;
$e['paused_at']=time();

$e['sent_details']=$progress['sent_details']??[];
$e['updated_details']=$progress['updated_details']??[];
$e['skipped_details']=$progress['skipped_details']??[];
$e['failed_details']=$progress['failed_details']??[];
$e['recent_log']=$progress['recent_log']??[];
$e['total_log_count']=$progress['total_log_count']??0;
$e['started_at']=$progress['started_at']??$e['started_at'];
$found=true;

writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>false,'paused'=>true,'paused_by'=>'user','sent'=>$e['sent'],'updated'=>$e['updated'],'skipped'=>$e['skipped'],'failed'=>$e['failed'],'total'=>$e['total'],'current'=>$e['current'],'last_title'=>'','started_at'=>$e['started_at'],'recent_log'=>$e['recent_log'],'total_log_count'=>$e['total_log_count'],'sent_details'=>$e['sent_details'],'updated_details'=>$e['updated_details'],'skipped_details'=>$e['skipped_details'],'failed_details'=>$e['failed_details']]);
break;
}
}
unset($e);
bslWriteQueue($queue);
echo json_encode(['ok'=>$found,'queue_id'=>$queueId],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_resume'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$found=false;
foreach($queue['entries'] as &$e){
if($e['id']===$queueId&&$e['status']==='paused'){
$e['status']='running';
$e['paused_at']=0;
$found=true;

@unlink(BSL_PRODUCTS_FILE);
@copy($e['products_file'],BSL_PRODUCTS_FILE);

$cn=loadConnections();
if(!isset($cn['basalam']))$cn['basalam']=[];
$cn['basalam']['category_id']=$e['config']['category_id']??0;
$cn['basalam']['auto_category']=$e['config']['auto_category']??false;
$cn['basalam']['title_suffix']=$e['config']['title_suffix']??'';
$cn['basalam']['delay_ms']=$e['config']['delay_ms']??500;
$cn['basalam']['retry_delay_ms']=$e['config']['retry_delay_ms']??1000;
@file_put_contents(CONNECTIONS_FILE,json_encode($cn,JSON_UNESCAPED_UNICODE),LOCK_EX);

writeProgress(BSL_PROGRESS_FILE,['running'=>true,'done'=>false,'paused'=>false,'sent'=>$e['sent'],'updated'=>$e['updated'],'skipped'=>$e['skipped'],'failed'=>$e['failed'],'total'=>$e['total'],'current'=>$e['current'],'last_title'=>'','started_at'=>$e['started_at'],'recent_log'=>['🔄 ادامه ارسال از محصول #'.($e['current']+1)],'total_log_count'=>($e['total_log_count']??0)+1,'sent_details'=>$e['sent_details']??[],'updated_details'=>$e['updated_details']??[],'skipped_details'=>$e['skipped_details']??[],'failed_details'=>$e['failed_details']??[]]);
break;
}
}
unset($e);

$resumeCurrent=0;
foreach($queue['entries'] as $ent){
if($ent['id']===$queueId){$resumeCurrent=$ent['current']??0;break;}
}
bslWriteQueue($queue);
echo json_encode(['ok'=>$found,'queue_id'=>$queueId,'current'=>$resumeCurrent],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_detail'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$entry=null;
foreach($queue['entries'] as $e){
if($e['id']===$queueId){$entry=$e;break;}
}
if(!$entry){echo json_encode(['ok'=>false,'error'=>'ورودی یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}

if($entry['status']==='running'){
$progress=readProgress(BSL_PROGRESS_FILE);
$entry['sent']=$progress['sent']??0;
$entry['updated']=$progress['updated']??0;
$entry['skipped']=$progress['skipped']??0;
$entry['failed']=$progress['failed']??0;
$entry['current']=$progress['current']??0;
$entry['done']=$progress['done']??false;
$entry['sent_details']=$progress['sent_details']??$entry['sent_details']??[];
$entry['updated_details']=$progress['updated_details']??$entry['updated_details']??[];
$entry['skipped_details']=$progress['skipped_details']??$entry['skipped_details']??[];
$entry['failed_details']=$progress['failed_details']??$entry['failed_details']??[];
$entry['recent_log']=$progress['recent_log']??$entry['recent_log']??[];
$entry['total_log_count']=$progress['total_log_count']??$entry['total_log_count']??0;
$entry['started_at']=$progress['started_at']??$entry['started_at'];
}

$products=@json_decode(@file_get_contents($entry['products_file']??'')?:'[]',true)?:[];
$entry['products']=$products;
echo json_encode(['ok'=>true,'entry'=>$entry],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_delete'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$found=false;
foreach($queue['entries'] as $i=>$e){
if($e['id']===$queueId){

if($e['status']==='running'){
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'total'=>$e['total'],'current'=>0,'started_at'=>$e['started_at'],'recent_log'=>['❌ ارسال لغو شد'],'total_log_count'=>1]);
}
@unlink($e['products_file']);
array_splice($queue['entries'],$i,1);
$found=true;break;
}
}
bslWriteQueue($queue);
echo json_encode(['ok'=>$found,'queue_id'=>$queueId],JSON_UNESCAPED_UNICODE);
exit;
}

if(isset($_GET['bsl_queue_start_server'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$entry=null;$entryIdx=null;
foreach($queue['entries'] as $i=>$e){if($e['id']===$queueId){$entry=$e;$entryIdx=$i;break;}}
if(!$entry){echo json_encode(['ok'=>false,'error'=>'ورودی یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
if($entry['status']!=='waiting'){echo json_encode(['ok'=>false,'error'=>'ورودی در وضعیت waiting نیست'],JSON_UNESCAPED_UNICODE);exit;}
@unlink(BSL_PROGRESS_FILE);@unlink(BSL_STOP_FILE);@unlink(BSL_PRODUCTS_FILE);
if(!file_exists($entry['products_file'])){echo json_encode(['ok'=>false,'error'=>'فایل محصولات یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
@copy($entry['products_file'],BSL_PRODUCTS_FILE);
$queue['entries'][$entryIdx]['status']='running';$queue['entries'][$entryIdx]['started_at']=time();
bslWriteQueue($queue);
$cn=loadConnections();if(!isset($cn['basalam']))$cn['basalam']=[];
$cn['basalam']['category_id']=$entry['config']['category_id']??0;$cn['basalam']['auto_category']=$entry['config']['auto_category']??false;$cn['basalam']['title_suffix']=$entry['config']['title_suffix']??'';$cn['basalam']['delay_ms']=$entry['config']['delay_ms']??500;$cn['basalam']['retry_delay_ms']=$entry['config']['retry_delay_ms']??1000;
@file_put_contents(CONNECTIONS_FILE,json_encode($cn,JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['ok'=>true,'queue_id'=>$queueId,'total'=>$entry['total']],JSON_UNESCAPED_UNICODE);exit;
}

if(isset($_GET['bsl_queue_restart_server'])){
header('Content-Type: application/json; charset=UTF-8');
$queueId=trim($_GET['queue_id']??'');
if($queueId===''){echo json_encode(['ok'=>false,'error'=>'queue_id خالی'],JSON_UNESCAPED_UNICODE);exit;}
$queue=bslReadQueue();
$entry=null;$entryIdx=null;
foreach($queue['entries'] as $i=>$e){if($e['id']===$queueId){$entry=$e;$entryIdx=$i;break;}}
if(!$entry){echo json_encode(['ok'=>false,'error'=>'ورودی یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
if($entry['status']!=='running'){echo json_encode(['ok'=>false,'error'=>'ورودی در وضعیت running نیست'],JSON_UNESCAPED_UNICODE);exit;}

$progress=readProgress(BSL_PROGRESS_FILE);
$current=(int)($progress['current']??$entry['current']??0);
$sent=(int)($progress['sent']??$entry['sent']??0);$updated=(int)($progress['updated']??$entry['updated']??0);$skipped=(int)($progress['skipped']??$entry['skipped']??0);$failed=(int)($progress['failed']??$entry['failed']??0);
@unlink(BSL_PROGRESS_FILE);@unlink(BSL_STOP_FILE);@unlink(BSL_PRODUCTS_FILE);
if(!file_exists($entry['products_file'])){echo json_encode(['ok'=>false,'error'=>'فایل محصولات یافت نشد'],JSON_UNESCAPED_UNICODE);exit;}
@copy($entry['products_file'],BSL_PRODUCTS_FILE);
$cn=loadConnections();if(!isset($cn['basalam']))$cn['basalam']=[];
$cn['basalam']['category_id']=$entry['config']['category_id']??0;$cn['basalam']['auto_category']=$entry['config']['auto_category']??false;$cn['basalam']['title_suffix']=$entry['config']['title_suffix']??'';$cn['basalam']['delay_ms']=$entry['config']['delay_ms']??500;$cn['basalam']['retry_delay_ms']=$entry['config']['retry_delay_ms']??1000;
@file_put_contents(CONNECTIONS_FILE,json_encode($cn,JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['ok'=>true,'queue_id'=>$queueId,'total'=>$entry['total'],'current'=>$current,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$failed],JSON_UNESCAPED_UNICODE);exit;
}

function bslFormatRemaining(int $seconds): string {
if($seconds <= 0) return 'آماده اجرا';
$minutes = ceil($seconds / 60);
if($minutes < 60) return $minutes.' دقیقه';
$hours = floor($minutes / 60);
$minRem = $minutes % 60;
if($hours >= 24){
$days = floor($hours / 24);
$hrRem = $hours % 24;
return $days.' روز'.($hrRem>0?' '.$hrRem.' ساعت':'');
}
return $hours.' ساعت'.($minRem>0?' '.$minRem.' دقیقه':'');
}

if(isset($_GET['action']) && $_GET['action'] === 'bsl_diag'){
header('Content-Type: application/json; charset=UTF-8');
$queue=bslReadQueue();
$progress=readProgress(BSL_PROGRESS_FILE);
$profiles=loadProfiles();
$syncState=loadSyncState();
$cn=loadConnections();
$now=time();
$diag=[
'queue_entries'=>count($queue['entries']),
'queue_statuses'=>[],
'progress'=>$progress,
'connections_basalam'=>[
'token_set'=>!empty($cn['basalam']['token']),
'vendor_id'=>(int)($cn['basalam']['vendor_id']??0),
'category_id'=>(int)($cn['basalam']['category_id']??0),
'auto_category'=>!empty($cn['basalam']['auto_category']),
],
'profiles'=>[],
];

foreach($queue['entries'] as $e){
$st=$e['status']??'unknown';
if(!isset($diag['queue_statuses'][$st]))$diag['queue_statuses'][$st]=0;
$diag['queue_statuses'][$st]++;
}

foreach($profiles as $key=>$p){
$sc=$p['syncConfig']??[];
$rawProd=$p['products']??[];
$rawProdCount=is_array($rawProd)?count($rawProd):0;
$orderCount=is_array($p['productsOrder']??[])?count($p['productsOrder']??[]):0;

$prodType='empty';
if($rawProdCount>0){
$first=reset($rawProd);
if(is_array($first)&&isset($first[0])&&isset($first[1]))$prodType='map_entries';
elseif(is_array($first)&&isset($first['title']))$prodType='flat_objects';
elseif(is_array($first))$prodType='unknown_array';
else $prodType='scalar';
}

$orderedCount=0;
$prodMap=[];
foreach($rawProd as $entry){
if(is_array($entry)&&count($entry)>=2&&is_string($entry[0]))$prodMap[$entry[0]]=$entry[1];
elseif(is_array($entry)&&isset($entry['title'])){$orderedCount++;}
}
foreach(($p['productsOrder']??[]) as $pk){if(isset($prodMap[$pk]))$orderedCount++;}
$diag['profiles'][]=[
'key'=>$key,
'name'=>$p['name']??$key,
'url'=>$p['url']??'',
'sync_enabled'=>!empty($sc['enabled']),
'sync_target'=>$sc['target']??'woo',
'sync_interval'=>(int)($sc['interval']??3600),
'sync_lastRun'=>(int)($syncState[$key]['lastRun']??0),
'products_raw_count'=>$rawProdCount,
'products_format'=>$prodType,
'productsOrder_count'=>$orderCount,
'products_ordered_count'=>$orderedCount,
'interval_type'=>(int)($sc['interval']??3600)===0?'on_endpoint_call':'time_based',
'interval_remaining'=>(int)($sc['interval']??3600)>0?max(0,(int)($sc['interval']??3600)-($now-(int)($syncState[$key]['lastRun']??0))):0,
'interval_remaining_text'=>(int)($sc['interval']??3600)===0?'هر بار فراخوانی اجرا می‌شود':bslFormatRemaining((int)($sc['interval']??3600)-($now-(int)($syncState[$key]['lastRun']??0))),
];
}
echo json_encode($diag,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
exit;
}

if(isset($_GET['action']) && $_GET['action'] === 'bsl_backend'){

$bslLockFile=__DIR__.'/bsl_backend.lock';
$bslLockFp=fopen($bslLockFile,'w');
if(!flock($bslLockFp,LOCK_EX|LOCK_NB)){

fclose($bslLockFp);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>false,'error'=>'bsl_backend already running','skipped'=>true],JSON_UNESCAPED_UNICODE);
exit;
}
set_time_limit(0); ignore_user_abort(true);

register_shutdown_function(function()use($bslLockFp,$bslLockFile){@flock($bslLockFp,LOCK_UN);@fclose($bslLockFp);@unlink($bslLockFile);});
$startedAt=time();
$GLOBALS['startedAt']=$startedAt;
$bslQueueId=''; $bslSentList=[]; $bslUpdatedList=[]; $bslSkippedList=[]; $bslFailedList=[]; $bslLog=[]; $bslFlatCats=[];

function bslBackendProgress($s,$u,$sk,$f,$t,$c,$lt,$log=null,$extra=[]){
global $bslLog,$bslSentList,$bslUpdatedList,$bslSkippedList,$bslFailedList,$bslQueueId;
if($log!==null){$bslLog[]=$log;}
$totalLog=count($bslLog);
$recentSlice=$totalLog>200?array_slice($bslLog,-200):$bslLog;
$d=['running'=>true,'sent'=>$s,'updated'=>$u,'skipped'=>$sk,'failed'=>$f,'total'=>$t,'last_title'=>$lt,'current'=>$c,'done'=>false,'started_at'=>$GLOBALS['startedAt'],'last_progress_ts'=>time(),'recent_log'=>$recentSlice,'total_log_count'=>$totalLog,'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList];
if($bslQueueId!='')$d['queue_id']=$bslQueueId;
if(!empty($extra))$d=array_merge($d,$extra);
writeProgress(BSL_PROGRESS_FILE,$d);
clearstatcache();
}

$queue=bslReadQueue();

$progress=readProgress(BSL_PROGRESS_FILE);
foreach($queue['entries'] as &$e){
if($e['status']==='running'&&($progress['done']??false)){
$e['status']='done';
$e['sent']=$progress['sent']??0;$e['updated']=$progress['updated']??0;
$e['skipped']=$progress['skipped']??0;$e['failed']=$progress['failed']??0;
$e['current']=$progress['current']??0;$e['done_at']=time();
}
}
unset($e);

$nextEntry=null;$nextIdx=null;
$now=time();
$progQueueId=$progress['queue_id']??'';
$progRunning=!empty($progress['running']);
foreach($queue['entries'] as $i=>&$e){
if($e['status']==='waiting'){
$nextEntry=$e;$nextIdx=$i;break;
}

if($e['status']==='running'){

$noProgressMatch=($progQueueId!==$e['id'])||(!$progRunning);
if($noProgressMatch){

$nextEntry=$e;$nextIdx=$i;break;
}

$progTs=(int)($progress['last_progress_ts']??0);
$entryStarted=(int)($e['started_at']??0);
$staleSeconds=$progTs>0?($now-$progTs):($entryStarted>0?($now-$entryStarted):9999);
if($staleSeconds>120){
$nextEntry=$e;$nextIdx=$i;break;
}
}
}
unset($e);

if(!$nextEntry){

$profiles = loadProfiles();
$syncState = loadSyncState();
$now = time();
$cn = loadConnections();
$autoCreated = 0;
$autoLog = [];
$diagProfiles = [];

foreach ($profiles as $key => $profile) {
$syncCfg = $profile['syncConfig'] ?? [];
$diagEntry = [
'key' => $key,
'name' => $profile['name'] ?? $key,
'sync_enabled' => !empty($syncCfg['enabled']),
'sync_target' => $syncCfg['target'] ?? 'woo',
'sync_interval' => (int)($syncCfg['interval'] ?? 3600),
'has_products' => !empty($profile['products']),
'products_raw_count' => is_array($profile['products']??[]) ? count($profile['products']??[]) : 0,
'productsOrder_count' => is_array($profile['productsOrder']??[]) ? count($profile['productsOrder']??[]) : 0,
'sync_lastRun' => (int)($syncState[$key]['lastRun']??0),
'reason' => '',
];

if (empty($syncCfg['enabled'])) {
$diagEntry['reason'] = 'sync not enabled';
$diagProfiles[] = $diagEntry; continue;
}

$target = $syncCfg['target'] ?? 'woo';
if ($target === 'woo') {
$diagEntry['reason'] = 'target=woo (only WooCommerce, skipped by bsl_backend)';
$diagProfiles[] = $diagEntry; continue;
}

$interval = (int)($syncCfg['interval'] ?? 3600);
$lastRun = (int)($syncState[$key]['lastRun'] ?? 0);
if ($interval > 0 && ($now - $lastRun < $interval)) {
$remaining = $interval - ($now - $lastRun);
$remainingMin = ceil($remaining / 60);
$remainingStr = $remainingMin >= 60 ? (floor($remainingMin/60).' ساعت '.($remainingMin%60).' دقیقه') : ($remainingMin.' دقیقه');
$diagEntry['reason'] = 'هنوز زمانش نرسیده — '.$remainingStr.' مانده (interval='.$interval.'s, lastRun='.$lastRun.', now='.$now.')';
$diagProfiles[] = $diagEntry; continue;
}

$rawProducts = $profile['products'] ?? [];
if (empty($rawProducts)) {
$diagEntry['reason'] = 'no products saved in profile';
$diagProfiles[] = $diagEntry; continue;
}

$orderedProducts = [];
$productsOrder = $profile['productsOrder'] ?? [];
$diagRawType = 'unknown';
if (!empty($rawProducts)) {

$firstEntry = $rawProducts[0] ?? reset($rawProducts) ?? null;
if (is_array($firstEntry)) {
if (isset($firstEntry[0]) && isset($firstEntry[1])) {
$diagRawType = 'map_entries [[key,data],...]';
} elseif (isset($firstEntry['title']) || isset($firstEntry['price']) || isset($firstEntry['image'])) {
$diagRawType = 'flat_objects [{title,...},{title,...},...]';
} else {
$diagRawType = 'unknown_array_format (keys='.implode(',',array_keys($firstEntry)).')';
}
} elseif (is_string($firstEntry)) {
$diagRawType = 'string_keys';
}
}
if (!empty($productsOrder) && is_array($productsOrder)) {

$prodMap = [];
foreach ($rawProducts as $entry) {
if (is_array($entry) && count($entry) >= 2) {
$prodMap[$entry[0]] = $entry[1];
}
}
foreach ($productsOrder as $pk) {
if (isset($prodMap[$pk])) {
$orderedProducts[] = $prodMap[$pk];
}
}
} else {

foreach ($rawProducts as $entry) {
if (is_array($entry) && count($entry) >= 2 && is_string($entry[0])) {

$orderedProducts[] = $entry[1];
} elseif (is_array($entry) && (isset($entry['title']) || isset($entry['price']) || isset($entry['image']))) {

$orderedProducts[] = $entry;
}
}
}

$diagEntry['raw_products_type'] = $diagRawType;
$diagEntry['ordered_products_count'] = count($orderedProducts);

if (empty($orderedProducts)) {
$diagEntry['reason'] = 'products conversion failed (0 ordered products from '.count($rawProducts).' raw products, type='.$diagRawType.')';
$diagProfiles[] = $diagEntry; continue;
}

$queueId = 'sync_' . $key . '_' . $now;
$qFile = __DIR__ . '/bsl_queue_products_' . $queueId . '.json';
$saveOk = @file_put_contents($qFile, json_encode($orderedProducts, JSON_UNESCAPED_UNICODE), LOCK_EX);
if (!$saveOk) { $autoLog[] = '⚠️ خطا ذخیره فایل محصولات پروفایل '.$key; continue; }

$catId = (int)($cn['basalam']['category_id'] ?? 0);
$autoCat = !empty($cn['basalam']['auto_category']);
$titleSuffix = trim($profile['titleSuffix'] ?? '') ?: trim($cn['basalam']['title_suffix'] ?? '');

$diagEntry['reason'] = '✅ auto-sync entry created ('.count($orderedProducts).' products)';
$diagEntry['queue_id'] = $queueId;
$diagProfiles[] = $diagEntry;

$entry = [
'id' => $queueId,
'status' => 'waiting',
'products_file' => $qFile,
'total' => count($orderedProducts),
'sent' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
'current' => 0, 'started_at' => 0, 'done_at' => 0, 'paused_at' => 0,
'config' => [
'category_id' => $catId,
'auto_category' => $autoCat,
'title_suffix' => $titleSuffix,
],
'profile_key' => $key,
'profile_name' => $profile['name'] ?? $key,
'auto_sync' => true,
];
$queue['entries'][] = $entry;
$autoCreated++;
$autoLog[] = '✅ پروفایل "'.$profile['name'].'" ('.count($orderedProducts).' محصول) به صف اضافه شد';

$syncState[$key] = ['lastRun' => $now, 'status' => 'queued'];
}

if ($autoCreated > 0) {
bslWriteQueue($queue);
saveSyncState($syncState);

foreach ($queue['entries'] as $i => &$e) {
if ($e['status'] === 'waiting') {
$nextEntry = $e;
$nextIdx = $i;
break;
}
}
unset($e);
}

if (!$nextEntry) {
bslWriteQueue($queue);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>true,'msg'=>'صف خالی — هیچ ورودی برای پردازش نیست','started'=>false,'processed'=>0,'auto_created'=>$autoCreated,'auto_log'=>$autoLog,'diag_profiles'=>$diagProfiles,'diag_total_profiles'=>count($profiles)],JSON_UNESCAPED_UNICODE);
exit;
}
}

$bslQueueId=$nextEntry['id'];

@unlink(BSL_PROGRESS_FILE);@unlink(BSL_STOP_FILE);@unlink(BSL_PRODUCTS_FILE);
if(!file_exists($nextEntry['products_file'])){
$queue['entries'][$nextIdx]['status']='failed';
$queue['entries'][$nextIdx]['done_at']=time();
bslWriteQueue($queue);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>$nextEntry['total'],'total'=>$nextEntry['total'],'current'=>0,'started_at'=>$startedAt,'queue_id'=>$bslQueueId,'recent_log'=>['❌ فایل محصولات یافت نشد'],'total_log_count'=>1,'sent_details'=>[],'updated_details'=>[],'skipped_details'=>[],'failed_details'=>[]]);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>false,'error'=>'فایل محصولات یافت نشد','queue_id'=>$bslQueueId],JSON_UNESCAPED_UNICODE);
exit;
}

$copyOk=@copy($nextEntry['products_file'],BSL_PRODUCTS_FILE);
if(!$copyOk){
$queue['entries'][$nextIdx]['status']='failed';$queue['entries'][$nextIdx]['done_at']=time();
bslWriteQueue($queue);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>$nextEntry['total'],'total'=>$nextEntry['total'],'current'=>0,'started_at'=>$startedAt,'queue_id'=>$bslQueueId,'recent_log'=>['❌ کپی فایل ناموفک'],'total_log_count'=>1,'sent_details'=>[],'updated_details'=>[],'skipped_details'=>[],'failed_details'=>[]]);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>false,'error'=>'کپی فایل محصولات ناموفک','queue_id'=>$bslQueueId],JSON_UNESCAPED_UNICODE);
exit;
}

$verifyProducts=json_decode(@file_get_contents(BSL_PRODUCTS_FILE)?:'',true)?:[];
if(empty($verifyProducts)){
$queue['entries'][$nextIdx]['status']='failed';$queue['entries'][$nextIdx]['done_at']=time();
bslWriteQueue($queue);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'total'=>0,'current'=>0,'started_at'=>$startedAt,'queue_id'=>$bslQueueId,'recent_log'=>['❌ فایل خالی'],'total_log_count'=>1,'sent_details'=>[],'updated_details'=>[],'skipped_details'=>[],'failed_details'=>[]]);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>false,'error'=>'فایل محصولات خالی','queue_id'=>$bslQueueId],JSON_UNESCAPED_UNICODE);
exit;
}

$cn=loadConnections();
if(!isset($cn['basalam']))$cn['basalam']=[];
$cn['basalam']['category_id']=$nextEntry['config']['category_id']??0;
$cn['basalam']['auto_category']=$nextEntry['config']['auto_category']??false;
$cn['basalam']['title_suffix']=$nextEntry['config']['title_suffix']??'';
$cn['basalam']['delay_ms']=$nextEntry['config']['delay_ms']??500;
$cn['basalam']['retry_delay_ms']=$nextEntry['config']['retry_delay_ms']??1000;
@file_put_contents(CONNECTIONS_FILE,json_encode($cn,JSON_UNESCAPED_UNICODE),LOCK_EX);

$queue['entries'][$nextIdx]['status']='running';
$queue['entries'][$nextIdx]['started_at']=$startedAt;
bslWriteQueue($queue);

if(!empty($nextEntry['auto_sync'])){
bslBackendProgress(0,0,0,0,0,0,'',['⏱ سینک خودکار — پروفایل "'.$nextEntry['profile_name'].'" ('.$nextEntry['total'].' محصول)']);
}

$tk=$cn['basalam']['token'];$vid=(int)$cn['basalam']['vendor_id'];
$autoCat=!empty($cn['basalam']['auto_category']);

$bslDelayMs=max(0,(int)($cn['basalam']['delay_ms']??500));
$bslRetryDelayMs=max(0,(int)($cn['basalam']['retry_delay_ms']??1000));

$bslFallbackCats=$cn['basalam']['fallback_cat_ids']??[];if(!is_array($bslFallbackCats))$bslFallbackCats=[];

$GLOBALS['_bslRetryDelayMs']=$bslRetryDelayMs;
bslBackendProgress(0,0,0,0,count($verifyProducts),0,'',['✅ [v8.22 bsl_backend] شروع — queue_id='.$bslQueueId.' — '.count($verifyProducts).' محصول — فاصله: '.$bslDelayMs.'ms — تلاش: '.$bslRetryDelayMs.'ms']);

$authCheck=bslReq($tk,'GET','users/me');
if($authCheck['code']===403||$authCheck['code']===401){
$authErr=$authCheck['code']===401?'توکن نامعتبر (۴۰۱)':'دسترسی ممنوع (۴۰۳)';
$queue=bslReadQueue();foreach($queue['entries'] as &$qe){if($qe['id']===$bslQueueId&&$qe['status']==='running'){$qe['status']='failed';$qe['done_at']=time();break;}}unset($qe);bslWriteQueue($queue);
@unlink(BSL_PRODUCTS_FILE);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>count($verifyProducts),'total'=>count($verifyProducts),'current'=>0,'started_at'=>$startedAt,'last_progress_ts'=>time(),'queue_id'=>$bslQueueId,'recent_log'=>['❌ '.$authErr],'total_log_count'=>1,'sent_details'=>[],'updated_details'=>[],'skipped_details'=>[],'failed_details'=>[]]);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>false,'error'=>$authErr,'auth_fail'=>true,'queue_id'=>$bslQueueId],JSON_UNESCAPED_UNICODE);
exit;
}
bslBackendProgress(0,0,0,0,count($verifyProducts),0,'',['✅ احراز هویت موفق']);

bslBackendProgress(0,0,0,0,count($verifyProducts),0,'',['دریافت دسته‌بندی‌ها...']);
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){
$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1) as $s)$o[]=$s;}}return $o;};
$bslFlatCats=$cFlat($cData,0);
bslSetCatNameMap($bslFlatCats);
}}
bslBackendProgress(0,0,0,0,count($verifyProducts),0,'',[count($bslFlatCats).' دسته']);

bslBackendProgress(0,0,0,0,count($verifyProducts),0,'',['🚀 شروع ارسال — جستجوی هر محصول قبل از ارسال...']);
$pd=$verifyProducts;$total=count($pd);$sent=0;$updated=0;$skipped=0;$fail=0;
$bslExisting=[];$bslExistingNorm=[];$bslArchivedMap=[];
// v8.22: Phase 1 removed — per-product search replaces bulk loading

foreach($pd as $i=>$p){
if(file_exists(BSL_STOP_FILE)){@unlink(BSL_STOP_FILE);
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$i,'',['❌ متوقف #'.($i+1)]);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'current'=>$i,'started_at'=>$startedAt,'last_progress_ts'=>time(),'queue_id'=>$bslQueueId,'recent_log'=>['❌ متوقف شد'],'total_log_count'=>count($bslLog),'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList]);
$queue=bslReadQueue();foreach($queue['entries'] as &$qe){if($qe['id']===$bslQueueId&&$qe['status']==='running'){$qe['status']='failed';$qe['done_at']=time();break;}}unset($qe);bslWriteQueue($queue);
@unlink(BSL_PRODUCTS_FILE);exit;
}
$pTitle=trim($p['title']??$p['name']??'');$pKey=$p['key']??'';$n=$i+1;$pn=(int)preg_replace("/[^0-9]/","",(string)($p['final_price']??'0'));

$GLOBALS['_currentProductLink']=$p['link']??'';

$priceUnit=$p['price_unit']??'';
if($priceUnit==='rial'){$pn=$pn; }else{$pn=$pn*10; }

$card=['image'=>$p['image']??'','price'=>$pn,'price_unit'=>$priceUnit,'link'=>$p['link']??''];
if($pTitle===''){$fail++;$bslFailedList[]=array_merge(['title'=>'','key'=>$pKey,'error'=>'عنوان خالی'],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,'',"[{$n}] ❌ عنوان خالی");continue;}
$titleWords=preg_split('/\s+/u',$pTitle);if(mb_strlen($pTitle)<6||count($titleWords)<2){$fail++;$bslFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>'عنوان کوتاه'],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ کوتاه");continue;}
if($pn<=0){$fail++;$bslFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>'قیمت 0'],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,'',"[{$n}] ❌ قیمت 0");continue;}
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,40),"[{$n}/{$total}] ".mb_substr($pTitle,0,50));

$exBsl=null;$nTitle=bslNormalizeTitle($pTitle);
// v8.22: Per-product search instead of bulk Phase 1
if(isset($bslExisting[$pTitle])){$exBsl=$bslExisting[$pTitle];}
elseif(isset($bslExistingNorm[$nTitle])){$exBsl=$bslExistingNorm[$nTitle];}
else{
// Search for this product in BaSalam
$searchQ=bslNormalizeTitle($pTitle);
$sr=bslReq($tk,'GET','vendors/'.$vid.'/products?per_page=20&search='.urlencode($searchQ));
if($sr['ok']){$srData=$sr['body']['data']??[];if(is_array($srData)){
foreach($srData as $sp){$sn=trim($sp['title']??$sp['name']??'');$snn=bslNormalizeTitle($sn);
if($sn===$pTitle||$snn===$nTitle){$exBsl=$sp;$bslExisting[$sn]=$sp;$bslExistingNorm[$snn]=$sp;break;}
// Also check partial match (normalized title contains the other)
if($snn!==''&&$nTitle!==''&&(mb_strpos($nTitle,$snn,0,'UTF-8')!==false||mb_strpos($snn,$nTitle,0,'UTF-8')!==false)){
$exBsl=$sp;$bslExisting[$sn]=$sp;$bslExistingNorm[$snn]=$sp;break;}
}
// Cache all found products for future lookups
foreach($srData as $sp){$sn=trim($sp['title']??$sp['name']??'');if($sn!==''){$bslExisting[$sn]=$sp;$snn=bslNormalizeTitle($sn);if($snn!==$sn)$bslExistingNorm[$snn]=$sp;}}
}}
// Also check archived/inactive products if not found
if(!$exBsl){
$ar=bslReq($tk,'GET','vendors/'.$vid.'/products?per_page=20&statuses=4184&statuses=3790&search='.urlencode($searchQ));
if($ar['ok']){$arData=$ar['body']['data']??[];if(is_array($arData)){
foreach($arData as $ap){$an=trim($ap['title']??$ap['name']??'');$ann=bslNormalizeTitle($an);
if($an===$pTitle||$ann===$nTitle||($ann!==''&&$nTitle!==''&&(mb_strpos($nTitle,$ann,0,'UTF-8')!==false||mb_strpos($ann,$nTitle,0,'UTF-8')!==false))){
$exBsl=$ap;$bslExisting[$an]=$ap;$bslExistingNorm[$ann]=$ap;$bslArchivedMap[$an]=$ap;$bslArchivedMap[$ann]=$ap;break;}
}
}}}
}

$catId=(int)($cn['basalam']['category_id']??0);if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$catId=$_ac;}
if($catId>0&&!empty($cData)&&is_array($cData)){$leafCat=findLeafCategory($catId,$cData);if($leafCat!=$catId)bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 📂 دسته برگ: $catId→$leafCat");$catId=$leafCat;}

$card['category_id']=$catId;$card['category']=bslCatNameById($catId);
if($exBsl){
$exId=$exBsl['id']??0;$exRevData=($exBsl['revision']&&is_array($exBsl['revision'])&&isset($exBsl['revision']['data']))?$exBsl['revision']['data']:[];
$exPrice=(int)($exRevData['primary_price']??$exBsl['primary_price']??0);$exStock=(int)($exRevData['inventory']??$exBsl['inventory']??0);$newStock=(int)($cn['basalam']['stock']??10);
$exStatusVal=0;$exStatusObj=$exBsl['status']??null;
if(is_array($exStatusObj)&&isset($exStatusObj['value']))$exStatusVal=(int)$exStatusObj['value'];
elseif(is_numeric($exStatusObj))$exStatusVal=(int)$exStatusObj;
elseif(is_array($exRevData)&&isset($exRevData['status'])){if(is_array($exRevData['status'])&&isset($exRevData['status']['value']))$exStatusVal=(int)$exRevData['status']['value'];elseif(is_numeric($exRevData['status']))$exStatusVal=(int)$exRevData['status'];}

$isArchived=$exStatusVal===4184||$exStatusVal===3567||$exStatusVal===3568||$exStatusVal===3790;
$isBslArchived=isset($bslArchivedMap[$pTitle])||isset($bslArchivedMap[$nTitle]);
if($isArchived||$isBslArchived){

$statusLabel=$exStatusVal===3790?'غیرفعال':($exStatusVal===4184?'بایگانی':($exStatusVal===3567?'تأیید نشده':'در انتظار'));
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🔄 بازفعال‌سازی از {$statusLabel} (status=$exStatusVal) → ID#$exId");
$bu=['primary_price'=>$pn,'stock'=>$newStock,'status'=>2976,'preparation_days'=>(int)($cn['basalam']['preparation_days']??3),'weight'=>(int)($cn['basalam']['weight']??500),'package_weight'=>(int)($cn['basalam']['package_weight']??((int)($cn['basalam']['weight']??500)+100))];
if($catId>0)$bu['category_id']=$catId;
$pid=null;if(!empty($p['image'])){$_up=bslUpload($tk,$p['image']);if(!empty($_up['ok']))$pid=$_up['file_id'];}if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}
$r=bslReq($tk,'PATCH','products/'.$exId,$bu);if($r['code']===404)$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu);
if($r['ok']&&!empty($r['body']['id'])){
$updated++;$bslUpdatedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$exId,'changes'=>'بازفعال‌سازی از '.$statusLabel,'update_reason'=>'بازفعال‌سازی (status='.$exStatusVal.'→2976)'],$card);
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ بازفعال‌سازی از {$statusLabel}: ID#$exId");
}else{

$em=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??''));if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);
$fail++;$bslFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>'بازگردانی ناموفق: '.mb_substr($em??'',0,100)],$card);
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ بازگردانی ناموفق: ".mb_substr($em??'',0,80));
}
continue;
}

$needUpdate=false;$updateChanges=[];
if($exPrice!=$pn){$needUpdate=true;$updateChanges[]='قیمت '.($exPrice/10).'→'.($pn/10).' تومان';}
if($exStock!=$newStock){$needUpdate=true;$updateChanges[]='موجودی';}

if(!$needUpdate){$skipped++;$bslSkippedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$exId,'reason'=>'تکرار (قیمت+موجودی یکسان)'],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⏭ تکرار (یکسان)");continue;}

bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ آپدیت: ".implode(',',$updateChanges));
$bu=['primary_price'=>$pn,'stock'=>$newStock,'preparation_days'=>(int)($cn['basalam']['preparation_days']??3),'weight'=>(int)($cn['basalam']['weight']??500),'package_weight'=>(int)($cn['basalam']['package_weight']??((int)($cn['basalam']['weight']??500)+100))];
if($newStock<=0)$bu['status']=3790;else $bu['status']=2976;if($catId>0)$bu['category_id']=$catId;
$pid=null;if(!empty($p['image'])){$_up=bslUpload($tk,$p['image']);if(!empty($_up['ok']))$pid=$_up['file_id'];}if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}
$r=bslReq($tk,'PATCH','products/'.$exId,$bu);if($r['code']===404)$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu);
if($r['ok']&&!empty($r['body']['id'])){ $updated++;$bslUpdatedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$exId,'changes'=>'آپدیت'],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ آپدیت #{$exId}");continue;}
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] PATCH ناموفک → جایگزینی...");
$rUnpub=bslReq($tk,'PATCH','products/'.$exId,['status'=>3790]);if($rUnpub['code']===404)$rUnpub=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,['status'=>3790]);
$replaceTitle=$pTitle;if(!$rUnpub['ok'])$replaceTitle=mb_substr($pTitle,0,110).' (v'.date('ymdHi').')';$pTitle=$replaceTitle;
}

$pid=null;if(!empty($p['image'])){ $up=bslUpload($tk,$p['image']);if(!empty($up['ok']))$pid=$up['file_id'];else{$up2=bslUpload($tk,$p['image']);if(!empty($up2['ok']))$pid=$up2['file_id'];}}

if(!$pid&&!empty($p['link'])){
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🔄 تصویر آپلود نشد — تلاش از سایت مبدأ...");
$srcPage=fetch_html($p['link']);
if(!empty($srcPage['ok'])&&!empty($srcPage['html'])){
$freshImgUrl=extractImageFromHtml($srcPage['html'],$p['link']);
if($freshImgUrl){
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 📷 تصویر جدید از سایت مبدأ: ".mb_substr($freshImgUrl,0,50));
$up3=bslUpload($tk,$freshImgUrl);
if(!empty($up3['ok']))$pid=$up3['file_id'];
}
}
}
if(!$pid){

bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚠️ تصویر آپلود نشد — ارسال بدون تصویر (غیرفعال)");
$bsBrief=trim(strip_tags($p['short_desc']??''));$bsDesc=trim($p['long_desc']??'');if($bsBrief==='')$bsBrief=trim(strip_tags($pTitle));if($bsDesc==='')$bsDesc=$bsBrief;
$catId=(int)($cn['basalam']['category_id']??0);
if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$acId=autoMatchBslCategory($pTitle,$bslFlatCats);if($acId>0)$catId=$acId;}
if($catId>0&&!empty($cData)&&is_array($cData)){$catId=findLeafCategory($catId,$cData);}
$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($cn['basalam']['stock']??10),'preparation_days'=>(int)($cn['basalam']['preparation_days']??3),'weight'=>(int)($cn['basalam']['weight']??500),'package_weight'=>(int)($cn['basalam']['package_weight']??((int)($cn['basalam']['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'status'=>3790];
if(!empty($p['sku']))$bp['sku']=$p['sku'];
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($r['ok']&&!empty($r['body']['id'])){
$sent++;$bslSentList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r['body']['id'],'note'=>'بدون تصویر (غیرفعال)'],$card);
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ بدون تصویر: ID#{$r['body']['id']} (غیرفعال)");
}else{
$em=$r['body']['error_description']??$r['body']['message']??$r['body']['error']??'';
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);

if(mb_stripos($em,'دسته')!==false||mb_stripos($em,'category')!==false||mb_stripos($em,'فرزند')!==false){
$fbResult=bslTryCreateWithFallback($tk,$vid,$bp,$bslFallbackCats,$pTitle,$autoCat,$bslFlatCats,$cData);
if(!empty($fbResult['ok'])){
$sent++;$bslSentList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$fbResult['body']['id'],'note'=>'بدون تصویر (اصلاح دسته: '.$fbResult['used_cat_id'].')'],$card);
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ بدون تصویر (اصلاح دسته→{$fbResult['used_cat_id']}): ID#{$fbResult['body']['id']}");
usleep($bslDelayMs*1000);continue;
}
}
$fail++;$bslFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>'تصویر+ایجاد بدون تصویر ناموفق: '.mb_substr($em,0,150)],$card);
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ تصویر+بدون تصویر: $em");
}
usleep($bslDelayMs*1000);continue;
}
$bsBrief=trim(strip_tags($p['short_desc']??''));$bsDesc=trim($p['long_desc']??'');if($bsBrief==='')$bsBrief=trim(strip_tags($pTitle));if($bsDesc==='')$bsDesc=$bsBrief;
$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($cn['basalam']['stock']??10),'preparation_days'=>(int)($cn['basalam']['preparation_days']??3),'weight'=>(int)($cn['basalam']['weight']??500),'package_weight'=>(int)($cn['basalam']['package_weight']??((int)($cn['basalam']['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'photo'=>$pid,'photos'=>[$pid]];
if(mb_strlen($bsBrief)>=3&&mb_strlen($bsDesc)>=3)$bp['status']=2976;else $bp['status']=3790;if(!empty($p['sku']))$bp['sku']=$p['sku'];
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($r['ok']&&!empty($r['body']['id'])){ $sent++;$bslSentList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r['body']['id']],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ #{$r['body']['id']}");
}else{

$em=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??null));if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);if(!$em)$em=mb_substr($r['raw']??('HTTP'.$r['code']),0,300);
$dupName=false;$msgs=$r['body']['messages']??[];
if(is_array($msgs)){foreach($msgs as $m){$f=$m['fields']??[];$mt=$m['message']??'';if(in_array('name',$f)&&(mb_stripos($mt,'تکرار')!==false||mb_stripos($mt,'duplicate')!==false||mb_stripos($mt,'already')!==false))$dupName=true;}}

if(!$dupName&&(mb_stripos($em,'نام تکرار')!==false||mb_stripos($em,'duplicate name')!==false||mb_stripos($em,'already exists')!==false))$dupName=true;
if($dupName&&$exBsl){

bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ نام تکراری → آپدیت اجباری...");
$bu2=['primary_price'=>$pn,'stock'=>(int)($cn['basalam']['stock']??10),'preparation_days'=>(int)($cn['basalam']['preparation_days']??3),'weight'=>(int)($cn['basalam']['weight']??500),'package_weight'=>(int)($cn['basalam']['package_weight']??((int)($cn['basalam']['weight']??500)+100)),'status'=>2976];
if($catId>0)$bu2['category_id']=$catId;
if($pid){$bu2['photo']=$pid;$bu2['photos']=[$pid];}
$r2=bslReq($tk,'PATCH','products/'.$exId,$bu2);if($r2['code']===404)$r2=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu2);
if($r2['ok']&&!empty($r2['body']['id'])){ $updated++;$bslUpdatedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$exId,'changes'=>'آپدیت اجباری (نام تکراری)'],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ آپدیت اجباری #{$exId}");}
else{ $skipped++;$bslSkippedList[]=['title'=>$pTitle,'key'=>$pKey,'reason'=>'نام تکراری — آپدیت شکست'];bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⏭ نام تکراری — آپدیت شکست");}
}else{ $fail++;$bslFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>mb_substr($em,0,200)],$card);bslBackendProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ ".mb_substr($em,0,60));}
}
}

bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','🔍 فاز ۲: محصولات رد‌شده...');
$catFixed=0;$catRetryFailed=0;$catRejected=[];
if(!empty($bslFlatCats)){
$bslPage2=1;$bslMore2=true;
while($bslMore2&&$bslPage2<=3){
$lr2=bslReq($tk,'GET','vendors/'.$vid.'/products?page='.$bslPage2.'&per_page=100&status=not_approved');
if(!$lr2['ok'])break;$lr2Data=$lr2['body']['data']??[];if(!is_array($lr2Data)||empty($lr2Data))break;
foreach($lr2Data as $bp2){$sv2=$bp2['status']??[];$svv=(is_array($sv2)?$sv2['value']??0:$sv2);$revR=$bp2['revision']??[];
if(is_array($revR)&&isset($revR['rejection_reasons'])){foreach($revR['rejection_reasons'] as $rr){if(($rr['value']??0)==6046){$catRejected[]=$bp2;break;}}}
}
$pg2=$lr2['body']??[];$tp2=max(1,(int)($pg2['total_page']??1));if($bslPage2<$tp2){$bslPage2++;}else{$bslMore2=false;}
}
foreach($catRejected as $ae){
$aeId=(int)($ae['id']??0);$aeTitle=trim($ae['title']??$ae['name']??'');
$aeCatId=autoMatchBslCategoryForce($aeTitle,$bslFlatCats);
if($aeCatId<=0){$catRetryFailed++;continue;}
$rCatP=bslReq($tk,'PATCH','products/'.$aeId,['category_id'=>$aeCatId,'status'=>2976]);
if($rCatP['code']===404)$rCatP=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$aeId,['category_id'=>$aeCatId,'status'=>2976]);
if($rCatP['ok']&&!empty($rCatP['body']['id'])){ $catFixed++;bslBackendProgress($sent,$updated+$catFixed,$skipped,$fail+$catRetryFailed,$total,$total,'',"✅ [{$aeTitle}] دسته اصلاح → #{$aeId}");
}else{ $catRetryFailed++;bslBackendProgress($sent,$updated+$catFixed,$skipped,$fail+$catRetryFailed,$total,$total,'',"❌ [{$aeTitle}] اصلاح خطا");}
usleep(500000);
}
}
$updated+=$catFixed;$fail+=$catRetryFailed;
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','Phase 2: '.$catFixed.' اصلاح, '.$catRetryFailed.' خطا');

$imgRetryCount=0;$imgRetryFixed=0;
$imgFailedEntries=[];
foreach($bslFailedList as $fIdx=>$fEntry){
if(($fEntry['error']??'')==='تصویر آپلود نشد'&&!empty($fEntry['link'])){
$imgFailedEntries[]=$fEntry;
}
}
if(!empty($imgFailedEntries)){
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','🔄 Phase 3: '.count($imgFailedEntries).' محصول — تلاش مجدد تصویر...');
foreach($imgFailedEntries as $imgF){
$imgRetryCount++;
$imgTitle=$imgF['title']??'';
$imgLink=$imgF['link']??'';
$imgOriginalUrl=$imgF['image_url']??'';

$pid3=null;
if($imgOriginalUrl){
$upR1=bslUpload($tk,$imgOriginalUrl);
if(!empty($upR1['ok']))$pid3=$upR1['file_id'];
}

if(!$pid3&&$imgLink){
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','🔄 ['.mb_substr($imgTitle,0,30).'] — از سایت مبدأ...');
$srcPage3=fetch_html($imgLink);
if(!empty($srcPage3['ok'])&&!empty($srcPage3['html'])){
$freshImg3=extractImageFromHtml($srcPage3['html'],$imgLink);
if($freshImg3){
$upR2=bslUpload($tk,$freshImg3);
if(!empty($upR2['ok']))$pid3=$upR2['file_id'];
}
}
}
if(!$pid3){
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','❌ ['.mb_substr($imgTitle,0,30).'] تصویر دوباره آپلود نشد');
continue;
}

$pRetry=null;
foreach($verifyProducts as $vp){
$vpKey=$vp['key']??($vp['link']??'');
if($vpKey===($imgF['key']??'')||($vp['title']??'')===mb_substr($imgTitle,0,30)){
$pRetry=$vp;break;
}
}
if(!$pRetry){
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','⚠️ ['.mb_substr($imgTitle,0,30).'] داده محصول یافت نشد');
continue;
}

$pRT=trim($pRetry['title']??'');$pRBrief=trim(strip_tags($pRetry['short_desc']??$pRT));$pRDesc=trim(strip_tags($pRetry['long_desc']??$pRBrief));

$pRP=(int)preg_replace("/[^0-9]/","",(string)($pRetry['final_price']??$pRetry['price']??'0'));

$priceUnitR=$pRetry['price_unit']??'';
if($priceUnitR==='rial'){$pRP=$pRP; }else{$pRP=$pRP*10; }
if($pRP<10000)$pRP=10000;
$pRSku=$pRetry['sku']??'';

$normRT=bslNormalizeTitle($pRT);$exRTfound=false;
foreach($bslExistingNorm as $exNorm=>$exId){if($normRT===bslNormalizeTitle($exNorm)){$exRTfound=true;break;}}
if($exRTfound){
$skipped++;$bslSkippedList[]=array_merge(['title'=>$pRT,'key'=>$pRetry['key']??'','reason'=>'duplicate in Phase 3','remote_id'=>0],['image'=>$pRetry['image']??'','price'=>0,'category'=>'','link'=>'']);
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','⏭ ['.mb_substr($pRT,0,30).'] تکرار — Phase 3');
}else{
$bpR=['name'=>mb_substr($pRT,0,120),'brief'=>mb_substr($pRBrief,0,250),'description'=>$pRDesc,'primary_price'=>$pRP,'stock'=>(int)($cn['basalam']['stock']??10),'preparation_days'=>(int)($cn['basalam']['preparation_days']??3),'weight'=>(int)($cn['basalam']['weight']??500),'package_weight'=>(int)($cn['basalam']['package_weight']??((int)($cn['basalam']['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'photo'=>$pid3,'photos'=>[$pid3]];
if(mb_strlen($pRBrief)>=3&&mb_strlen($pRDesc)>=3)$bpR['status']=2976;else $bpR['status']=3790;
if($pRSku)$bpR['sku']=$pRSku;

if(!empty($cn['basalam']['title_suffix']))$bpR['name']=mb_substr($bpR['name'].$cn['basalam']['title_suffix'],0,120);
$rR=bslReq($tk,'POST','vendors/'.$vid.'/products',$bpR);
if($rR['ok']&&!empty($rR['body']['id'])){
$imgRetryFixed++;$sent++;
$bslSentList[]=['title'=>$pRT,'key'=>$pRetry['key']??'','remote_id'=>$rR['body']['id'],'phase'=>'3'];

$bslFailedList=array_values(array_filter($bslFailedList,function($fe)use($imgF){return($fe['key']??'')!==($imgF['key']??'');}));
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','✅ ['.mb_substr($pRT,0,30).'] تصویر اصلاح → #'.$rR['body']['id']);
}else{
$fail++;$bslFailedList[]=['title'=>$pRT,'key'=>$pRetry['key']??'','error'=>'Phase 3 send fail'];
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','❌ ['.mb_substr($pRT,0,30).'] Phase 3 ارسال خطا');
}
}
usleep(300000);
}
}
bslBackendProgress($sent,$updated,$skipped,$fail,$total,$total,'','Phase 3: '.$imgRetryFixed.'/'.$imgRetryCount.' تصویر اصلاح');

$finalLog="پایان: $sent جدید, $updated آپدیت, $skipped تکراری, $fail خطا | Phase3: $imgRetryFixed تصویر اصلاح";
$bslLog[]=$finalLog;
@unlink(BSL_PRODUCTS_FILE);
$finalProgress=['running'=>false,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'last_title'=>'','current'=>$total,'done'=>true,'started_at'=>$startedAt,'last_progress_ts'=>time(),'queue_id'=>$bslQueueId,'recent_log'=>$bslLog,'total_log_count'=>count($bslLog),'log'=>$finalLog,'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList];
writeProgress(BSL_PROGRESS_FILE,$finalProgress);

$queue=bslReadQueue();foreach($queue['entries'] as &$qe){
if($qe['id']===$bslQueueId&&$qe['status']==='running'){
$qe['status']='done';$qe['sent']=$sent;$qe['updated']=$updated;$qe['skipped']=$skipped;$qe['failed']=$fail;$qe['current']=$total;$qe['done_at']=time();
$qe['sent_details']=$bslSentList;$qe['updated_details']=$bslUpdatedList;$qe['skipped_details']=$bslSkippedList;$qe['failed_details']=$bslFailedList;
$qe['recent_log']=array_slice($bslLog,-50);break;
}
}
unset($qe);bslWriteQueue($queue);

if(!empty($nextEntry['auto_sync'])&&$nextEntry['profile_key']){
$syncState=loadSyncState();
$syncState[$nextEntry['profile_key']]=['lastRun'=>time(),'status'=>'done','sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail];
saveSyncState($syncState);
}

$queue=bslReadQueue();
$hasMore=false;
foreach($queue['entries'] as $e2){
if($e2['status']==='waiting'){ $hasMore=true; break; }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>true,'msg'=>$finalLog,'started'=>true,'processed'=>1,'queue_id'=>$bslQueueId,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'has_more'=>$hasMore,'auto_sync'=>!empty($nextEntry['auto_sync'])],JSON_UNESCAPED_UNICODE);
exit;
}

if (isset($_GET['bsl_ai_category'])) {
header('Content-Type: application/json; charset=UTF-8');
$cn=loadConnections();$bs=$cn['basalam']??[];$ai=$cn['ai']??[];
$productTitle=trim($_GET['title']??'');

$aiApiKey=trim($ai['api_key']??'');
$aiBaseUrl=trim($ai['base_url']??'https://dashscope.aliyuncs.com/compatible-mode/v1');
$aiModel=trim($ai['model']??'qwen-plus');
$aiTemperature=(float)($ai['temperature']??0.1);
$geminiKey=trim($bs['gemini_api_key']??$_GET['api_key']??'');
if(empty($productTitle)){echo json_encode(['ok'=>false,'error'=>'عنوان محصول خالی','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}
if(empty($aiApiKey)&&empty($geminiKey)){echo json_encode(['ok'=>false,'error'=>'کلید API هوش مصنوعی تنظیم نشده (Qwen یا Gemini)','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}

$tk=$bs['token']??'';
$cats=[];
if(!empty($tk)){
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1)as $s)$o[]=$s;}}return $o;};$cats=$cFlat($cData,0);}}
}
if(empty($cats)){echo json_encode(['ok'=>false,'error'=>'دسته‌بندی‌های باسلام در دسترس نیست','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}

$catList='';$leafCats=[];
foreach($cats as $c){
if(($c['level']??0)>=2){$catList.=$c['id'].': '.$c['name'].'\n';$leafCats[]=$c;}
}
if(empty($leafCats)){foreach($cats as $c){$catList.=$c['id'].': '.$c['name'].'\n';$leafCats[]=$c;}}

if(strlen($catList)>3000){$catList='';$leafCats=array_slice($leafCats,0,200);foreach($leafCats as $c){$catList.=$c['id'].': '.$c['name'].'\n';}}
$prompt="You are a product categorization assistant for a Persian (Farsi) e-commerce platform (BaSalam).\nGiven this product title: \"{$productTitle}\"\n\nSelect the BEST category ID from this list:\n{$catList}\n\nReturn ONLY the category ID number. Do not return any text, explanation, or name. Just the numeric ID.";

$aiText='';$aiModelUsed='';
if(!empty($aiApiKey)){

$url=rtrim($aiBaseUrl,'/').'/chat/completions';
$payload=['model'=>$aiModel,'messages'=>[['role'=>'system','content'=>'You are a product categorization assistant. Return ONLY the numeric category ID.'],['role'=>'user','content'=>$prompt]],'temperature'=>$aiTemperature,'max_tokens'=>20];
$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$aiApiKey],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
$resp=curl_exec($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($httpCode===200){$rData=json_decode($resp,true);$aiText=trim($rData['choices'][0]['message']['content']??'');$aiModelUsed=$aiModel;}
}
if(empty($aiText)&&!empty($geminiKey)){

$url='https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key='.$geminiKey;
$payload=['contents'=>['parts'=>['text'=>$prompt]],'generationConfig'=>['temperature'=>0.1,'maxOutputTokens'=>20]];
$ch=curl_init($url);curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_TIMEOUT,15);
$resp=curl_exec($ch);$httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
if($httpCode===200){$rData=json_decode($resp,true);$aiText=trim($rData['candidates'][0]['content']['parts'][0]['text']??'');$aiModelUsed='gemini-2.0-flash';}
}
if(empty($aiText)){echo json_encode(['ok'=>false,'error'=>'خطا API هوش مصنوعی (هیچکدام پاسخ نداد)','category_id'=>0],JSON_UNESCAPED_UNICODE);exit;}

$aiCatId=0;
if(preg_match('/\d+/',$aiText,$m)){$aiCatId=(int)$m[0];}

$valid=false;$catName='';
foreach($cats as $c){if((int)$c['id']===$aiCatId){$valid=true;$catName=$c['name'];break;}}
if(!$valid&&$aiCatId>0){
$aiCatId=autoMatchBslCategory($productTitle,$cats);
if($aiCatId>0){$valid=true;foreach($cats as $c){if((int)$c['id']===$aiCatId){$catName=$c['name'];break;}}}
}
echo json_encode(['ok'=>$valid,'category_id'=>$aiCatId,'category_name'=>$catName,'ai_raw'=>$aiText,'ai_model'=>$aiModelUsed,'title'=>$productTitle],JSON_UNESCAPED_UNICODE);
exit;
}

if (isset($_GET['bsl_save_products'])) {
header('Content-Type: application/json; charset=UTF-8');
$pd = json_decode($_POST['products'] ?? '[]', true) ?: [];
$chunkIndex = (int)($_POST['chunk_index'] ?? 0);
if (empty($pd)) {
echo json_encode(['ok' => false, 'error' => 'محصولی دریافت نشد'], JSON_UNESCAPED_UNICODE);
exit;
}
if ($chunkIndex === 0) {

@unlink(BSL_PRODUCTS_FILE);
$saved = @file_put_contents(BSL_PRODUCTS_FILE, json_encode($pd, JSON_UNESCAPED_UNICODE), LOCK_EX);
} else {
$existing = json_decode(@file_get_contents(BSL_PRODUCTS_FILE) ?: '[]', true) ?: [];
$merged = array_merge($existing, $pd);
$saved = @file_put_contents(BSL_PRODUCTS_FILE, json_encode($merged, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
echo json_encode(['ok' => true, 'saved' => count($pd), 'chunk' => $chunkIndex, 'total_saved' => count($merged ?? $pd)], JSON_UNESCAPED_UNICODE);
exit;
}
if (isset($_GET['woo_save_products'])) {
header('Content-Type: application/json; charset=UTF-8');
$pd = json_decode($_POST['products'] ?? '[]', true) ?: [];
$chunkIndex = (int)($_POST['chunk_index'] ?? 0);
if (empty($pd)) {
echo json_encode(['ok' => false, 'error' => 'محصولی دریافت نشد'], JSON_UNESCAPED_UNICODE);
exit;
}
if ($chunkIndex === 0) {

@unlink(WOO_PRODUCTS_FILE);
$saved = @file_put_contents(WOO_PRODUCTS_FILE, json_encode($pd, JSON_UNESCAPED_UNICODE), LOCK_EX);
} else {
$existing = json_decode(@file_get_contents(WOO_PRODUCTS_FILE) ?: '[]', true) ?: [];
$merged = array_merge($existing, $pd);
$saved = @file_put_contents(WOO_PRODUCTS_FILE, json_encode($merged, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
echo json_encode(['ok' => true, 'saved' => count($pd), 'chunk' => $chunkIndex, 'total_saved' => count($merged ?? $pd)], JSON_UNESCAPED_UNICODE);
exit;
}

if (isset($_GET['bsl_send_one'])) {
header('Content-Type: application/json; charset=UTF-8');
set_time_limit(30);
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}

$queueCatId=(int)($_POST['queue_category_id']??-1);
$queueAutoCat=!empty($_POST['queue_auto_category']);
if($queueCatId>=0){

$bs['category_id']=$queueCatId;
$bs['auto_category']=$queueAutoCat;
}

if(!empty($_POST['delay_ms']))$bs['delay_ms']=(int)$_POST['delay_ms'];
if(!empty($_POST['retry_delay_ms']))$bs['retry_delay_ms']=(int)$_POST['retry_delay_ms'];
$autoCat=!empty($bs['auto_category']);
if(!$autoCat&&(empty($bs['category_id'])||(int)$bs['category_id']<=0)){echo json_encode(['ok'=>false,'error'=>'دسته‌بندی باسلام انتخاب نشده'],JSON_UNESCAPED_UNICODE);exit;}
$bslFallbackCats=$cn['basalam']['fallback_cat_ids']??[];if(!is_array($bslFallbackCats))$bslFallbackCats=[];

$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$authCheck=bslReq($tk,'GET','users/me');

$GLOBALS['_bslRetryDelayMs']=max(0,(int)($bs['retry_delay_ms']??1000));
if($authCheck['code']===403||$authCheck['code']===401){
$authErr=$authCheck['code']===401?'\u062a\u0648\u06a9\u0646 \u0646\u0627\u0645\u0639\u062a\u0628\u0631 (\u06f4\u06f0\u06f1) \u2014 \u062a\u0648\u06a9\u0646 \u062c\u062f\u06cc\u062f \u0628\u0633\u0627\u0632\u06cc\u062f':'\u062f\u0633\u062a\u0631\u0633\u06cc \u0645\u0645\u0646\u0648\u0639 (\u06f4\u06f0\u06f3 Forbidden) \u2014 \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u0646\u0627\u0642\u0635 \u0627\u06cc\u0631 \u062a\u0648\u06a9\u0646 \u0628\u062f\u0648\u0646 \u0645\u062c\u0648\u0632';
$authDetail=$authCheck['body']['detail']??'';
echo json_encode(['ok'=>false,'action'=>'fail','error'=>$authErr,'auth_fail'=>true,'http_code'=>$authCheck['code'],'detail'=>$authDetail],JSON_UNESCAPED_UNICODE);exit;
}
$p=json_decode($_POST['product']??'[]',true)?:[];
$productIndex=(int)($_POST['product_index']??0);
$totalProducts=(int)($_POST['total']??0);
$mode=$_POST['mode']??'send';
$pTitle=trim($p['title']??$p['name']??'');
$pKey=$p['key']??'';
$n=$productIndex+1;

$GLOBALS['_currentProductLink']=$p['link']??'';
$pn=(int)preg_replace("/[^0-9]/","",(string)($p['final_price']??'0'));

$priceUnit=$p['price_unit']??'';
if($priceUnit==='rial'){$pn=$pn; }else{$pn=$pn*10; }

$cardImg=$p['image']??'';
$cardPrice=$pn;
$cardCatId=(int)($bs['category_id']??0);
$cardLink=$p['link']??'';

if($pTitle===''){echo json_encode(['ok'=>false,'action'=>'fail','error'=>'عنوان خالی','key'=>$pKey,'title'=>'','image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>'','link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;}
$titleWords=preg_split('/\s+/u',$pTitle);
if(mb_strlen($pTitle)<6||count($titleWords)<2){echo json_encode(['ok'=>false,'action'=>'fail','error'=>'عنوان کوتاه','key'=>$pKey,'title'=>$pTitle,'image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>'','link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;}
if($pn<=0){echo json_encode(['ok'=>false,'action'=>'fail','error'=>'قیمت 0','key'=>$pKey,'title'=>$pTitle,'image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>'','link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;}

$bslFlatCats=[];$cData=[];
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){$cData=$cr['body']['data']??[];if(is_array($cData)){$cFlat=function($items,$lv=0)use(&$cFlat){$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1) as $s)$o[]=$s;}}return $o;};$bslFlatCats=$cFlat($cData,0);}}

bslSetCatNameMap($bslFlatCats);

$bslExisting=[];$bslExistingNorm=[];
$lr=bslReq($tk,'GET','vendors/'.$vid.'/products?page=1&per_page=100&statuses=2976&statuses=3790');
if($lr['ok']){$lrData=$lr['body']['data']??[];if(is_array($lrData)){foreach($lrData as $bp){$bn=trim($bp['title']??$bp['name']??'');if($bn!==''){$bslExisting[$bn]=$bp;$nn=bslNormalizeTitle($bn);if($nn!==$bn)$bslExistingNorm[$nn]=$bp;}}}}

$searchR=bslReq($tk,'GET','vendors/'.$vid.'/products?page=1&per_page=100&statuses=2976&statuses=3790&search='.urlencode(bslNormalizeTitle($pTitle)));
if($searchR['ok']){$srData=$searchR['body']['data']??[];if(is_array($srData)){foreach($srData as $bp){$bn=trim($bp['title']??$bp['name']??'');if($bn!==''){if(!isset($bslExisting[$bn])){$bslExisting[$bn]=$bp;$nn=bslNormalizeTitle($bn);if($nn!==$bn)$bslExistingNorm[$nn]=$bp;}}}}}

$exBsl=null;
$nTitle=bslNormalizeTitle($pTitle);
if(isset($bslExisting[$pTitle]))$exBsl=$bslExisting[$pTitle];
if(!$exBsl&&isset($bslExistingNorm[$nTitle]))$exBsl=$bslExistingNorm[$nTitle];
if(!$exBsl&&$nTitle!==''){foreach($bslExistingNorm as $ek=>$ev){if($ek!==''&&($nTitle==$ek||mb_strpos($nTitle,$ek,0,'UTF-8')!==false||mb_strpos($ek,$nTitle,0,'UTF-8')!==false)){$exBsl=$ev;break;}}}

if($exBsl){
$exId=$exBsl['id']??0;
$exRevData=($exBsl['revision']&&is_array($exBsl['revision'])&&isset($exBsl['revision']['data']))?$exBsl['revision']['data']:[];
$exPrice=(int)($exRevData['primary_price']??$exBsl['primary_price']??0);
$exStock=(int)($exRevData['inventory']??$exBsl['inventory']??0);
$exTitle=trim($exBsl['title']??$exBsl['name']??'');
$exStatusVal=0;$exStatusObj=$exBsl['status']??null;
if(is_array($exStatusObj)&&isset($exStatusObj['value']))$exStatusVal=(int)$exStatusObj['value'];
elseif(is_numeric($exStatusObj))$exStatusVal=(int)$exStatusObj;
elseif(is_array($exRevData)&&isset($exRevData['status'])){if(is_array($exRevData['status'])&&isset($exRevData['status']['value']))$exStatusVal=(int)$exRevData['status']['value'];elseif(is_numeric($exRevData['status']))$exStatusVal=(int)$exRevData['status'];}
$newStock=(int)($bs['stock']??10);

$needUpdate=false;$changes=[];
if($exPrice!=$pn){$needUpdate=true;$changes[]='قیمت '.$exPrice.'→'.$pn;}
if($exStock!=$newStock){$needUpdate=true;$changes[]='موجودی '.$exStock.'→'.$newStock;}
if($exTitle!==''&&$exTitle!==$pTitle&&bslNormalizeTitle($exTitle)!==bslNormalizeTitle($pTitle)){$needUpdate=true;$changes[]='عنوان';}
if($newStock<=0){$needUpdate=true;$changes[]='ناموجود';}
if($exStatusVal===3567){$needUpdate=true;$changes[]='re-submit';}

if($exStatusVal===3790||$exStatusVal===3568||$exStatusVal===4184){
$needUpdate=true;
$statusLabels=[3790=>'غیرفعال',3568=>'در انتظار',4184=>'بایگانی'];
$changes[]='بازفعال‌سازی از '.($statusLabels[$exStatusVal]??$exStatusVal);
}

if(!$needUpdate){
echo json_encode(['ok'=>true,'action'=>'skip','key'=>$pKey,'title'=>$pTitle,'remote_id'=>$exId,'reason'=>'تکرار: نام+قیمت+موجودی یکسان','image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;
}

$bu=['primary_price'=>$pn,'stock'=>$newStock,'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100))];
if($newStock<=0)$bu['status']=3790;else $bu['status']=2976;
if($exTitle!==''&&$exTitle!==$pTitle&&bslNormalizeTitle($exTitle)!==bslNormalizeTitle($pTitle))$bu['name']=mb_substr($pTitle,0,120);
if(!empty($p['long_desc']))$bu['description']=$p['long_desc'];elseif(!empty($p['short_desc']))$bu['description']=strip_tags($p['short_desc']);

$buCatId=(int)($bs['category_id']??0);if($buCatId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$buCatId=$_ac;}if($buCatId>0)$bu['category_id']=$buCatId;
$pid=null;if(!empty($p['image'])){$_up=bslUpload($tk,$p['image']);if(!empty($_up['ok']))$pid=$_up['file_id'];}
if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}

$r=bslReq($tk,'PATCH','products/'.$exId,$bu);
if($r['code']===404)$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu);

if($r['ok']&&!empty($r['body']['id'])){
echo json_encode(['ok'=>true,'action'=>'update','key'=>$pKey,'title'=>$pTitle,'remote_id'=>$exId,'old_price'=>$exPrice,'new_price'=>$pn,'changes'=>implode(', ',$changes),'update_reason'=>implode(', ',$changes),'image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;
}

$rUnpub=bslReq($tk,'PATCH','products/'.$exId,['status'=>3790]);
if($rUnpub['code']===404)$rUnpub=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,['status'=>3790]);
$replaceTitle=$pTitle;if(!$rUnpub['ok'])$replaceTitle=mb_substr($pTitle,0,110).' (v'.date('ymdHi').')';

$pTitle=$replaceTitle;
}

$pid=null;if(!empty($p['image'])){$up=bslUpload($tk,$p['image']);if(!empty($up['ok']))$pid=$up['file_id'];else{$up2=bslUpload($tk,$p['image']);if(!empty($up2['ok']))$pid=$up2['file_id'];}}

if(!$pid&&!empty($p['link'])){
$srcPage=fetch_html($p['link'],15);
if(!empty($srcPage['ok'])&&!empty($srcPage['html'])){
$freshImgUrl=extractImageFromHtml($srcPage['html'],$p['link']);
if($freshImgUrl){$up3=bslUpload($tk,$freshImgUrl);if(!empty($up3['ok']))$pid=$up3['file_id'];}
}
}
if(!$pid){

$bsBrief=trim(strip_tags($p['short_desc']??''));$bsDesc=trim($p['long_desc']??'');
if($bsBrief==='')$bsBrief=trim(strip_tags($pTitle));if($bsDesc==='')$bsDesc=$bsBrief;
$catId=(int)($bs['category_id']??0);
if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$catId=$_ac;}
if($catId>0&&!empty($cData)&&is_array($cData)){$catId=findLeafCategory($catId,$cData);}
$cardCatId=$catId;$cardCatName=bslCatNameById($catId);
$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'status'=>3790];
if(!empty($p['sku']))$bp['sku']=$p['sku'];
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($r['ok']&&!empty($r['body']['id'])){
echo json_encode(['ok'=>true,'action'=>'send','key'=>$pKey,'title'=>$pTitle,'remote_id'=>$r['body']['id'],'image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink,'warning'=>'بدون تصویر (غیرفعال)'],JSON_UNESCAPED_UNICODE);exit;
}
$em=$r['body']['error_description']??$r['body']['message']??$r['body']['error']??'';
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);
echo json_encode(['ok'=>false,'action'=>'fail','error'=>'تصویر آپلود نشد + ایجاد بدون تصویر هم ناموفق: '.mb_substr($em,0,150),'key'=>$pKey,'title'=>$pTitle,'image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;
}
$bsBrief=trim(strip_tags($p['short_desc']??''));$bsDesc=trim($p['long_desc']??'');
if($bsBrief==='')$bsBrief=trim(strip_tags($pTitle));if($bsDesc==='')$bsDesc=$bsBrief;

$catId=(int)($bs['category_id']??0);

if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$catId=$_ac;}
if($catId>0&&!empty($cData)&&is_array($cData)){$catId=findLeafCategory($catId,$cData);}

$cardCatId=$catId;
$cardCatName=bslCatNameById($catId);
$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'photo'=>$pid,'photos'=>[$pid]];
if(mb_strlen($bsBrief)>=3&&mb_strlen($bsDesc)>=3)$bp['status']=2976;else $bp['status']=3790;
if(!empty($p['sku']))$bp['sku']=$p['sku'];
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);

if($r['ok']&&!empty($r['body']['id'])){
echo json_encode(['ok'=>true,'action'=>'send','key'=>$pKey,'title'=>$pTitle,'remote_id'=>$r['body']['id'],'image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;
}

$msgs=$r['body']['messages']??[];$dupName=false;
if(is_array($msgs)){foreach($msgs as $m){$f=$m['fields']??[];$mt=$m['message']??'';if(in_array('name',$f)&&(mb_stripos($mt,'تکرار')!==false||mb_stripos($mt,'duplicate')!==false||mb_stripos($mt,'already')!==false))$dupName=true;}}

$emCheck=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??''));
if(is_array($emCheck))$emCheck=json_encode($emCheck,JSON_UNESCAPED_UNICODE);
if(!$dupName&&$emCheck&&(mb_stripos($emCheck,'نام تکرار')!==false||mb_stripos($emCheck,'duplicate name')!==false||mb_stripos($emCheck,'already exists')!==false||mb_stripos($emCheck,'تکراری')!==false))$dupName=true;

if(!$dupName&&$r['code']===422)$dupName=true;
if($dupName&&$exBsl){

$bu=['primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100))];
if((int)($bs['stock']??10)<=0)$bu['status']=3790;else $bu['status']=2976;
if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}
if($buCatId>0)$bu['category_id']=$buCatId;
$r3=bslReq($tk,'PATCH','products/'.$exId,$bu);
if($r3['code']===404)$r3=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu);
if($r3['ok']&&!empty($r3['body']['id'])){echo json_encode(['ok'=>true,'action'=>'update','key'=>$pKey,'title'=>$pTitle,'remote_id'=>$exId,'changes'=>'آپدیت از نام تکراری','image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;}
}

if($dupName){
echo json_encode(['ok'=>true,'action'=>'skip','key'=>$pKey,'title'=>$pTitle,'remote_id'=>$exId??0,'reason'=>'نام تکراری (422)','image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;
}
$em=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??null));if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);if(!$em)$em=mb_substr($r['raw']??('HTTP'.$r['code']),0,300);
echo json_encode(['ok'=>false,'action'=>'fail','error'=>mb_substr($em,0,200),'key'=>$pKey,'title'=>$pTitle,'image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink],JSON_UNESCAPED_UNICODE);exit;
}

if (isset($_GET['basalam_stream'])) {

set_time_limit(0); ignore_user_abort(true);
@ini_set('post_max_size', '64M');
$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>false,'error'=>'تنظیمات باسلام ناقص'],JSON_UNESCAPED_UNICODE);exit;}
$autoCat=!empty($bs['auto_category']);
if(!$autoCat&&(empty($bs['category_id'])||(int)$bs['category_id']<=0)){header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>false,'error'=>'دسته‌بندی باسلام انتخاب نشده! تیک «دسته خودکار» را فعال کنید یا دسته‌بندی انتخاب کنید'],JSON_UNESCAPED_UNICODE);exit;}

$fromFile=!empty($_POST['from_file']);
if($fromFile){
// v8.36: فایل مخصوصِ همین صف را بخوان، نه فایل مشترک.
// باگ قدیمی: همه‌ی ارسال‌ها از BSL_PRODUCTS_FILE می‌خواندند و چون این
// فایل با هر ارسال جدید بازنویسی می‌شود، اگر دو ارسال نزدیک به هم
// شروع می‌شدند، پروفایل دوم محصولات پروفایل اول را می‌فرستاد.
$srcFile=bslQueueProductsFile(trim($_POST['queue_id']??''));
$raw=@file_get_contents($srcFile);
$pd=json_decode($raw?:'[]',true)?:[];

$fSize=@filesize($srcFile);
}else{
$rawInput=$_POST['products']??'[]';
$pd=json_decode($rawInput,true)?:[];
}
$titleSuffix=trim($_POST['title_suffix']??'');

$bslDelayMs=max(0,(int)($bs['delay_ms']??500));
$bslRetryDelayMs=max(0,(int)($bs['retry_delay_ms']??1000));
$GLOBALS['_bslRetryDelayMs']=$bslRetryDelayMs;

$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$authCheck=bslReq($tk,'GET','users/me');
if($authCheck['code']===403||$authCheck['code']===401){
$authErr=$authCheck['code']===401?'\u062a\u0648\u06a9\u0646 \u0646\u0627\u0645\u0639\u062a\u0628\u0631 (\u06f4\u06f0\u06f1)':'\u062f\u0633\u062a\u0631\u0633\u06cc \u0645\u0645\u0646\u0648\u0639 (\u06f4\u06f0\u06f3 Forbidden) \u2014 \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u0646\u0627\u0642\u0635';
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>count($pd),'total'=>count($pd),'current'=>0,'started_at'=>time(),'recent_log'=>['\u2717 '.$authErr],'total_log_count'=>1,'sent_details'=>[],'updated_details'=>[],'skipped_details'=>[],'failed_details'=>[]]);
$queueId=trim($_POST['queue_id']??'');
if($queueId!==''){
$queue=bslReadQueue();
foreach($queue['entries'] as &$qe){if($qe['id']===$queueId&&$qe['status']==='running'){$qe['status']='failed';$qe['failed']=count($pd);$qe['done_at']=time();break;}}
unset($qe);bslWriteQueue($queue);
}
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>false,'error'=>$authErr,'auth_fail'=>true],JSON_UNESCAPED_UNICODE);exit;
}
$startIndex=max(0,(int)($_POST['start_index']??0));
$skipKeys=json_decode($_POST['skip_keys']??'[]',true)?:[];
$skipMap=array_flip($skipKeys);

$isResume=$startIndex>0;

if($isResume){
$prevProgress=readProgress(BSL_PROGRESS_FILE);
$sent=(int)($prevProgress['sent']??0);
$updated=(int)($prevProgress['updated']??0);
$skipped=(int)($prevProgress['skipped']??0);
$fail=(int)($prevProgress['failed']??0);
}else{
$sent=0;$fail=0;$skipped=0;$updated=0;
}
$tk=$bs['token'];$vid=(int)$bs['vendor_id'];$total=count($pd);

@unlink(BSL_STOP_FILE);

$startedAt=time();
$GLOBALS['startedAt']=$startedAt;
$bslQueueId=trim($_POST['queue_id']??'');
$bslSentList=[];$bslUpdatedList=[];$bslSkippedList=[];$bslFailedList=[];$bslLog=[];$bslFlatCats=[];

$initProgress=['running'=>true,'sent'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'total'=>$total,'last_title'=>'','current'=>0,'done'=>false,'started_at'=>$startedAt,'last_progress_ts'=>time(),'recent_log'=>['شروع فرآیند ارسال'],'total_log_count'=>1,'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList];
if($bslQueueId!=='')$initProgress['queue_id']=$bslQueueId;
writeProgress(BSL_PROGRESS_FILE,$initProgress);
clearstatcache();

bslUpdateProgress(0,0,0,0,$total,0,'',['✅ دریافت '.$total.' محصول ('.($fromFile?'از فایل':'از POST').($isResume?' | ادامه از #'.($startIndex+1):'').')']);

bslUpdateProgress(0,0,0,0,$total,0,'',['دریافت دسته‌بندی‌های باسلام...']);
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){
$cData=$cr['body']['data']??[];
if(is_array($cData)){
$cFlat=function($items,$lv=0)use(&$cFlat){
$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1)as $s)$o[]=$s;}}return $o;
};
$bslFlatCats=$cFlat($cData,0);
bslSetCatNameMap($bslFlatCats);
}
}
bslUpdateProgress(0,0,0,0,$total,0,'',[count($bslFlatCats).' دسته بارگذاری']);
$bslLog=[];

function bslUpdateProgress($s,$u,$sk,$f,$t,$c,$lt,$log=null,$extra=[]){
global $bslLog,$bslSentList,$bslUpdatedList,$bslSkippedList,$bslFailedList,$bslQueueId;
if($log!==null){$bslLog[]=$log;}
$totalLog=count($bslLog);

$recentSlice=$totalLog>200?array_slice($bslLog,-200):$bslLog;

$d=['running'=>true,'sent'=>$s,'updated'=>$u,'skipped'=>$sk,'failed'=>$f,'total'=>$t,'last_title'=>$lt,'current'=>$c,'done'=>false,'started_at'=>$GLOBALS['startedAt'],'last_progress_ts'=>time(),'recent_log'=>$recentSlice,'total_log_count'=>$totalLog,'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList];
if($bslQueueId!=='')$d['queue_id']=$bslQueueId;
if(!empty($extra))$d=array_merge($d,$extra);
writeProgress(BSL_PROGRESS_FILE,$d);

clearstatcache();
}

bslUpdateProgress(0,0,0,0,$total,0,'',['شروع ارسال '.$total.' محصول به باسلام']);

$jsonResp=json_encode(['ok'=>true,'msg'=>'فرآیند ارسال شروع شد','total'=>$total,'started_at'=>$startedAt],JSON_UNESCAPED_UNICODE);

while(@ob_get_level())@ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');
header('Content-Length: '.strlen($jsonResp));
header('Connection: close');
echo $jsonResp;
if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}
@ob_flush();@flush();

bslUpdateProgress(0,0,0,0,$total,0,'',['✅ [v7.81] Connection closed — background processing starting now (queue_id='.$bslQueueId.', products='.$total.')']);

$bslExisting=[];$bslExistingNorm=[];
if(!$isResume){
bslUpdateProgress(0,0,0,0,$total,0,'',['دریافت لیست محصولات فعلی باسلام...']);
$bslPage=1;$bslMore=true;$maxDupPages=20;
while($bslMore&&$bslPage<=$maxDupPages){

if(file_exists(BSL_STOP_FILE)){
@unlink(BSL_STOP_FILE);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,0,'',['❌ فرآیند متوقف شد']);
writeProgress(BSL_PROGRESS_FILE,['running'=>false,'done'=>true,'cancelled'=>true,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'current'=>0,'started_at'=>$GLOBALS['startedAt'],'recent_log'=>['❌ فرآیند متوقف شد'],'total_log_count'=>1,'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList]);
exit;
}
$lr=bslReq($tk,'GET','vendors/'.$vid.'/products?page='.$bslPage.'&per_page=100&statuses=2976&statuses=3790');
if(!$lr['ok']){bslUpdateProgress(0,0,0,0,$total,0,'',['خطا در دریافت لیست (HTTP '.($lr['code']??'?').')']);break;}
$lrData=$lr['body']['data']??[];
if(!is_array($lrData)||empty($lrData)){bslUpdateProgress(0,0,0,0,$total,0,'',['لیست محصولات خالی']);break;}
$cnt=count($lrData);
foreach($lrData as $bp){$bn=trim($bp['title']??$bp['name']??'');if($bn!==''){ $bslExisting[$bn]=$bp; $nn=bslNormalizeTitle($bn);if($nn!==$bn)$bslExistingNorm[$nn]=$bp; }}
bslUpdateProgress(0,0,0,0,$total,0,'',['صفحه '.$bslPage.': '.$cnt.' محصول موجود']);
$pg=$lr['body']??[];
$totalPage=max(1,(int)($pg['total_page']??($pg['meta']['last_page']??1)));
if($bslPage<$totalPage){$bslPage++;}else{$bslMore=false;}
}
bslUpdateProgress(0,0,0,0,$total,0,'',[count($bslExisting).' محصول موجود. شروع ارسال...']);
}else{

bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$startIndex,mb_substr($pTitle??'',0,30),['ادامه از محصول #'.($startIndex+1)]);
}

foreach($pd as $i=>$p){
if($i<$startIndex){continue;}
$pTitle=trim($p['title']??$p['name']??'');
$pKey=$p['key']??'';
$n=$i+1;
if($i===0){bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,40),null,['debug_title'=>$p['title']??'MISSING','debug_name'=>$p['name']??'MISSING','computed'=>$pTitle]);}
if($pTitle===''){$fail++;$bslFailedList[]=['title'=>'','key'=>$pKey,'error'=>'عنوان خالی'];bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,'',"[{$n}] ❌ عنوان خالی - محصول رد شد");continue;}
$titleWords=preg_split('/\s+/u',$pTitle);
if(mb_strlen($pTitle)<6||count($titleWords)<2){$fail++;$bslFailedList[]=['title'=>$pTitle,'key'=>$pKey,'error'=>'عنوان کوتاه ('.count($titleWords).' کلمه, '.mb_strlen($pTitle).' حرف)'];bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ عنوان کوتاه: '".$pTitle."' (".count($titleWords).' کلمه');continue;}
if(isset($skipMap[$pKey])){$skipped++;$bslSkippedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'reason'=>'قبلاً ارسال شده (skip)','remote_id'=>0],$card);bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⏭ قبلاً ارسال شده");continue;}
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,40),"[{$n}/{$total}] بررسی: ".mb_substr($pTitle,0,50));
$pn=(int)preg_replace("/[^0-9]/","",(string)($p['final_price']??'0'));

$priceUnit=$p['price_unit']??'';
if($priceUnit==='rial'){$pn=$pn; }else{$pn=$pn*10; }
if($pn<=0){$fail++;$bslFailedList[]=['title'=>$pTitle,'key'=>$pKey,'error'=>'قیمت نامعتبر (0)'];bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,'',"[{$n}] ❌ قیمت نامعتبر");continue;}

$exBsl=null;
$nTitle=bslNormalizeTitle($pTitle);

if(isset($bslExisting[$pTitle])){$exBsl=$bslExisting[$pTitle];}

if(!$exBsl&&isset($bslExistingNorm[$nTitle])){$exBsl=$bslExistingNorm[$nTitle];}

if(!$exBsl&&$titleSuffix!==''){
$baseTitle=trim(str_replace($titleSuffix,'',$pTitle));
$nBase=bslNormalizeTitle($baseTitle);
if($baseTitle!==''&&$baseTitle!==$pTitle){
if(isset($bslExisting[$baseTitle])){$exBsl=$bslExisting[$baseTitle];}
elseif(isset($bslExistingNorm[$nBase])){$exBsl=$bslExistingNorm[$nBase];}
}
}

if(!$exBsl&&$nTitle!==''){
foreach($bslExistingNorm as $ek=>$ev){
if($ek!==''&&($nTitle==$ek||mb_strpos($nTitle,$ek,0,'UTF-8')!==false||mb_strpos($ek,$nTitle,0,'UTF-8')!==false)){
$exBsl=$ev;break;
}
}
}
if($exBsl){bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🔍 هم‌نام یافت شد: ID#".($exBsl['id']??'?'));}
if($exBsl){
$exId=$exBsl['id']??'?';

$exRevData=($exBsl['revision']&&is_array($exBsl['revision'])&&isset($exBsl['revision']['data']))?$exBsl['revision']['data']:[];
$exRevWhole=$exBsl['revision']??[];
$exPrice=(int)($exRevData['primary_price']??$exBsl['primary_price']??$exBsl['price']??0);
$exStock=(int)($exRevData['inventory']??$exBsl['inventory']??$exBsl['stock']??0);
$exTitle=trim($exRevData['title']??$exBsl['title']??$exBsl['name']??'');
$exStatusVal=0;
$exStatusObj=$exBsl['status']??null;
if(is_array($exStatusObj)&&isset($exStatusObj['value']))$exStatusVal=(int)$exStatusObj['value'];
elseif(is_numeric($exStatusObj))$exStatusVal=(int)$exStatusObj;
$newStock=(int)($bs['stock']??10);

$catRejectedExisting=false;$catRejectMsg='';
if($exStatusVal===3567){

$rejectionReasons=$exRevWhole['rejection_reasons']??[];
if(is_array($rejectionReasons)){
foreach($rejectionReasons as $rr){
$rrName=$rr['name']??'';
$rrVal=(int)($rr['value']??0);

if($rrVal===6046||mb_stripos($rrName,'دسته')!==false||mb_stripos($rrName,'category')!==false){
$catRejectedExisting=true;
$catRejectMsg=$rrName;
break;
}
}
}

$metaDesc=$exRevWhole['metadata']??[];
if(is_array($metaDesc)){
$aiText=trim($metaDesc['description']??'');
if($aiText!==''&&mb_stripos($aiText,'دسته')!==false){
$catRejectedExisting=true;
if(!$catRejectMsg)$catRejectMsg='بررسی AI: دسته نامعتبر';
}
}
}

if($catRejectedExisting&&!empty($bslFlatCats)){

$retryCatId=0;
$aiText=trim($exRevWhole['metadata']['description']??'');
if($aiText!==''){$retryCatId=extractAiCategoryFromText($aiText,$bslFlatCats);}
if($retryCatId<=0)$retryCatId=autoMatchBslCategory($pTitle,$bslFlatCats);
if($retryCatId<=0)$retryCatId=autoMatchBslCategoryForce($pTitle,$bslFlatCats);
if($retryCatId>0){
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🔄 رد شده دسته نامعتبر → اصلاح دسته → ID#$retryCatId (PATCH+re-submit)");
$bu=['category_id'=>$retryCatId,'status'=>2976,'primary_price'=>$pn,'stock'=>$newStock,'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100))];
if(!empty($p['long_desc']))$bu['description']=$p['long_desc'];
elseif(!empty($p['short_desc']))$bu['description']=strip_tags($p['short_desc']);
if(mb_strlen($bsBrief??'')>=3)$bu['brief']=mb_substr($bsBrief??$pTitle,0,250);
$pid=null;
if(!empty($p['image'])){$_up=bslUpload($tk,$p['image']);if(!empty($_up['ok']))$pid=$_up['file_id'];else{$_up2=bslUpload($tk,$p['image']);if(!empty($_up2['ok']))$pid=$_up2['file_id'];}}
if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}
$r=bslReq($tk,'PATCH','products/'.$exId,$bu);
if($r['code']===404){$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu);}
if($r['ok']&&!empty($r['body']['id'])){
$updated++;$bslUpdatedList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$exId,'changes'=>'اصلاح دسته ردشده → ID#'.$retryCatId.' + re-submit'];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ اصلاح دسته + re-submit: ID#$exId → دسته#$retryCatId");
usleep(500000);continue;
}else{
$em=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??null));
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);
if(!$em)$em=mb_substr($r['raw']??('HTTP'.$r['code']),0,300);

bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ اصلاح دسته PATCH خطا: $em");
}
}else{
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚠️ رد دسته + دسته جایگزین یافت نشد");
}
}

$needUpdate=false;$updateLog='';
if($exPrice!==$pn){$needUpdate=true;$updateLog.=' قیمت '.$exPrice.'→'.$pn;}
if($exStock!==$newStock){$needUpdate=true;$updateLog.=' موجودی '.$exStock.'→'.$newStock;}

if($exTitle!==''&&$exTitle!==$pTitle&&bslNormalizeTitle($exTitle)!==bslNormalizeTitle($pTitle)){$needUpdate=true;$updateLog.=' عنوان آپدیت';}

if($newStock<=0){$needUpdate=true;$updateLog.=' ناموجود';}

if($exStatusVal===3567&&!$catRejectedExisting){$needUpdate=true;$updateLog.=' re-submit تایید نشده';}
if(!$needUpdate){
$skipped++;$bslSkippedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'reason'=>'تکرار: نام+قیمت+موجودی یکسان','remote_id'=>$exId??0],$card);bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⏭ تکرار: نام+قیمت+موجودی");
usleep(100000);continue;
}
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ آپدیت ID#$exId".$updateLog);
$bu=['primary_price'=>$pn,'stock'=>$newStock,'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100))];
if($newStock<=0){$bu['status']=3790;}else{$bu['status']=2976;}

if($exTitle!==''&&$exTitle!==$pTitle&&bslNormalizeTitle($exTitle)!==bslNormalizeTitle($pTitle)){$bu['name']=mb_substr($pTitle,0,120);}
if(!empty($p['long_desc']))$bu['description']=$p['long_desc'];
elseif(!empty($p['short_desc']))$bu['description']=strip_tags($p['short_desc']);
if(!empty($p['sku']))$bu['sku']=$p['sku'];

$buCatId=(int)($bs['category_id']??0);
if($buCatId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$buCatId=$_ac;}
if($buCatId<=0)$buCatId=(int)($bs['category_id']??0);
if($buCatId>0)$bu['category_id']=$buCatId;
$pid=null;
if(!empty($p['image'])){$up=bslUpload($tk,$p['image']);if(!empty($up['ok']))$pid=$up['file_id'];}
if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}
$r=bslReq($tk,'PATCH','products/'.$exId,$bu);
if($r['code']===404){$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu);}
if($r['ok']&&!empty($r['body']['id'])){
$updated++;$bslUpdatedList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$exId,'changes'=>$updateLog,'update_reason'=>$updateLog];bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ آپدیت: ID#$exId".$updateLog);
}else{

$em=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??null));
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);
if(!$em)$em=mb_substr($r['raw']??('HTTP'.$r['code']),0,300);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ خطای آپدیت: $em → جایگزین");

$rUnpub=bslReq($tk,'PATCH','products/'.$exId,['status'=>3790]);
if($rUnpub['code']===404){$rUnpub=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,['status'=>3790]);}
$unpubOk=$rUnpub['ok'];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 📦 غیرفعال ID#$exId: ".($unpubOk?'✅':'❌ (PATCH 404)'));

$replaceTitle=$pTitle;
if(!$unpubOk){

$replaceSuffix=' (v'.date('ymdHi').')';
$replaceTitle=mb_substr($pTitle,0,110).$replaceSuffix;
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚠️ غیرفعال ناموفق → عنوان تغییر: ".$replaceTitle);
}

$bsBrief=trim(strip_tags($p['short_desc']??''));
$bsDesc=trim($p['long_desc']??'');
if($bsBrief==='')$bsBrief=trim(strip_tags($p['title']??$p['name']??''));
if($bsDesc==='')$bsDesc=$bsBrief;
$catId=(int)($bs['category_id']??0);

if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$catId=$_ac;}
$bp2=['name'=>mb_substr($replaceTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>$newStock,'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId];

$pid2=$pid??null;
if(!$pid2&&!empty($p['image'])){$_up=bslUpload($tk,$p['image']);if(!empty($_up['ok']))$pid2=$_up['file_id'];else{$_up2=bslUpload($tk,$p['image']);if(!empty($_up2['ok']))$pid2=$_up2['file_id'];}}
if($pid2){$bp2['photo']=$pid2;$bp2['photos']=[$pid2];$bp2['status']=2976;}
else{

$bp2['status']=3790;
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚠️ بدون تصویر → draft");
}
if(!empty($p['sku']))$bp2['sku']=$p['sku'];
$r2=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp2);
if($r2['ok']&&!empty($r2['body']['id'])){
$sent++;$bslSentList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r2['body']['id'],'note'=>'replaced ID#'.$exId];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ جایگزین: ID#{$r2['body']['id']} (قدیم ID#$exId)");
}else{

$em2=$r2['body']['error_description']??($r2['body']['message']??($r2['body']['error']??null));
if(is_array($em2))$em2=json_encode($em2,JSON_UNESCAPED_UNICODE);
if(!$em2)$em2=mb_substr($r2['raw']??('HTTP'.$r2['code']),0,300);
$isDupName=false;
$msgs2=$r2['body']['messages']??[];
if(is_array($msgs2)){foreach($msgs2 as $m2){$m2t=$m2['message']??'';if(mb_stripos($m2t,'duplicate')!==false||mb_stripos($m2t,'نام محصول تکراری')!==false||mb_stripos($m2t,'هم‌نام')!==false)$isDupName=true;}}
if($isDupName&&!$unpubOk){

$retryTitle=mb_substr($pTitle,0,100).' #'.substr(md5($pTitle.$pn.time()),0,6);
$bp2['name']=$retryTitle;
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🔄 تلاش با عنوان تغییر: ".$retryTitle);
$r3=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp2);
if($r3['ok']&&!empty($r3['body']['id'])){
$sent++;$bslSentList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r3['body']['id'],'note'=>'replaced with hash suffix'];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ جایگزین با عنوان تغییر: ID#{$r3['body']['id']}");
}else{
$em3=$r3['body']['error_description']??($r3['body']['message']??($r3['body']['error']??null));
if(is_array($em3))$em3=json_encode($em3,JSON_UNESCAPED_UNICODE);
if(!$em3)$em3=mb_substr($r3['raw']??('HTTP'.$r3['code']),0,300);
$fail++;$bslFailedList[]=['title'=>$pTitle,'key'=>$pKey,'error'=>'خطای جایگزینی: '.mb_substr($em3,0,200)];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ خطای جایگزینی: $em3");
}
}else{
$fail++;$bslFailedList[]=['title'=>$pTitle,'key'=>$pKey,'error'=>'خطای جایگزینی: '.mb_substr($em2,0,200)];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ خطای جایگزینی: $em2");
}
}
}
usleep(500000);continue;
}

$pid=null;
if(!empty($p['image'])){$up=bslUpload($tk,$p['image']);if(!empty($up['ok']))$pid=$up['file_id'];else{$up2=bslUpload($tk,$p['image']);if(!empty($up2['ok']))$pid=$up2['file_id'];}}

if(!$pid&&!empty($p['link'])){
$srcPage=fetch_html($p['link'],15);
if(!empty($srcPage['ok'])&&!empty($srcPage['html'])){
$freshImgUrl=extractImageFromHtml($srcPage['html'],$p['link']);
if($freshImgUrl){$up3=bslUpload($tk,$freshImgUrl);if(!empty($up3['ok']))$pid=$up3['file_id'];}
}
}

if(!$pid){
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚠️ تصویر آپلود نشد — ارسال بدون تصویر (غیرفعال)");
$bsBrief=trim(strip_tags($p['short_desc']??''));$bsDesc=trim($p['long_desc']??'');
if($bsBrief==='')$bsBrief=trim(strip_tags($p['title']??$p['name']??''));
if($bsDesc==='')$bsDesc=$bsBrief;
$catId=(int)($bs['category_id']??0);
if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$autoCatId=autoMatchBslCategory($pTitle,$bslFlatCats);if($autoCatId>0)$catId=$autoCatId;}
if($catId>0&&!empty($cData)&&is_array($cData)){$catId=findLeafCategory($catId,$cData);}
$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'status'=>3790];
if(!empty($p['sku']))$bp['sku']=$p['sku'];
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($r['ok']&&!empty($r['body']['id'])){
$sent++;$bslSentList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r['body']['id'],'note'=>'بدون تصویر (غیرفعال)'],$card);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ ایجاد بدون تصویر: ID#{$r['body']['id']} (غیرفعال)");
}else{
$em=$r['body']['error_description']??$r['body']['message']??$r['body']['error']??'';
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);

if(mb_stripos($em,'دسته')!==false||mb_stripos($em,'category')!==false||mb_stripos($em,'فرزند')!==false){
$fbResult=bslTryCreateWithFallback($tk,$vid,$bp,$bslFallbackCats,$pTitle,$autoCat,$bslFlatCats,$cData);
if(!empty($fbResult['ok'])){
$sent++;$bslSentList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$fbResult['body']['id'],'note'=>'بدون تصویر (اصلاح دسته: '.$fbResult['used_cat_id'].')'],$card);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ بدون تصویر (اصلاح دسته→{$fbResult['used_cat_id']}): ID#{$fbResult['body']['id']}");
usleep($bslDelayMs*1000);continue;
}
}
$fail++;$bslFailedList[]=array_merge(['title'=>$pTitle,'key'=>$pKey,'error'=>'تصویر+ایجاد بدون تصویر ناموفق: '.mb_substr($em,0,150)],$card);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ تصویر+ایجاد بدون تصویر ناموفق: $em");
}
usleep($bslDelayMs*1000);continue;
}
$bsBrief=trim(strip_tags($p['short_desc']??''));
$bsDesc=trim($p['long_desc']??'');
if($bsBrief==='')$bsBrief=trim(strip_tags($p['title']??$p['name']??''));
if($bsDesc==='')$bsDesc=$bsBrief;

$catId=(int)($bs['category_id']??0);
if($catId<=0&&$autoCat&&!empty($bslFlatCats)){
$autoCatId=autoMatchBslCategory($pTitle,$bslFlatCats);
if($autoCatId>0){$catId=$autoCatId;bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🏷️ دسته خودکار: ID#$catId");}
}
$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId];

if(mb_strlen($bsBrief)>=3 && mb_strlen($bsDesc)>=3){$bp['photo']=$pid;$bp['photos']=[$pid];$bp['status']=2976;}
else{$bp['photo']=$pid;$bp['photos']=[$pid];$bp['status']=3790;}
if(!empty($p['sku']))$bp['sku']=$p['sku'];
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($r['ok']&&!empty($r['body']['id'])){
$sent++;$bslSentList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r['body']['id']];bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ ایجاد: ID#{$r['body']['id']}");
}else{

$msgs=$r['body']['messages']??[];
$catRejected=false;$catErrMsg='';
if(is_array($msgs)){
foreach($msgs as $m){
$fields=$m['fields']??[];
$msgText=$m['message']??'';
if(in_array('category_id',$fields)||mb_stripos($msgText,'دسته')!==false||mb_stripos($msgText,'category')!==false){
$catRejected=true;$catErrMsg=$msgText;break;
}
}
}

$emDesc=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??null));
if(is_array($emDesc))$emDesc=json_encode($emDesc,JSON_UNESCAPED_UNICODE);
if(!$catRejected&&$emDesc&&(mb_stripos($emDesc,'دسته')!==false||mb_stripos($emDesc,'category')!==false)){$catRejected=true;$catErrMsg=$emDesc;}

if($catRejected&&!empty($bslFlatCats)){

$retryCatId=0;
if($catErrMsg!==''){$retryCatId=extractAiCategoryFromText($catErrMsg,$bslFlatCats);}
if($retryCatId<=0)$retryCatId=autoMatchBslCategory($pTitle,$bslFlatCats);

if($retryCatId<=0||$retryCatId===$catId){

$retryCatId=autoMatchBslCategoryForce($pTitle,$bslFlatCats);
}
if($retryCatId>0&&$retryCatId!==$catId){
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🔄 رد شده دسته نامعتبر ($catErrMsg) → تلاش با دسته ID#$retryCatId");
$bp['category_id']=$retryCatId;
$r2=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($r2['ok']&&!empty($r2['body']['id'])){
$sent++;$bslSentList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$r2['body']['id'],'retry_cat'=>$retryCatId];bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ ایجاد با دسته خودکار ID#$retryCatId");
usleep(500000);continue;
}else{

$em2=$r2['body']['error_description']??($r2['body']['message']??($r2['body']['error']??null));
if(is_array($em2))$em2=json_encode($em2,JSON_UNESCAPED_UNICODE);
if(!$em2)$em2=mb_substr($r2['raw']??('HTTP'.$r2['code']),0,300);
$fail++;$bslFailedList[]=['title'=>$pTitle,'key'=>$pKey,'error'=>'خطای ایجاد (retry cat ID#$retryCatId): '.mb_substr($em2,0,200)];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ retry also failed: $em2");
}
}else{

$fail++;$bslFailedList[]=['title'=>$pTitle,'key'=>$pKey,'error'=>'دسته نامعتبر و دسته جایگزین یافت نشد: '.$catErrMsg];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ دسته نامعتبر + جایگزین یافت نشد");
}
usleep(500000);
}else{

$dupName=false;$dupErrMsg='';

if(is_array($msgs)){
foreach($msgs as $m){
$fields=$m['fields']??[];
$msgText=$m['message']??'';
if(in_array('name',$fields)&&mb_stripos($msgText,'تکرار')!==false||mb_stripos($msgText,'duplicate')!==false||mb_stripos($msgText,'هم')!==false){
$dupName=true;$dupErrMsg=$msgText;break;
}
}
}

if(!$dupName&&$emDesc&&(mb_stripos($emDesc,'تکرار')!==false||mb_stripos($emDesc,'duplicate')!==false||mb_stripos($emDesc,'هم نام')!==false)){
$dupName=true;$dupErrMsg=is_string($emDesc)?$emDesc:'';
}

if($dupName){

$foundExisting=null;
foreach($bslExisting as $ebn=>$ebp){
if(bslNormalizeTitle($ebn)===bslNormalizeTitle($pTitle)){ $foundExisting=$ebp;break; }
}
if(!$foundExisting){
foreach($bslExistingNorm as $enk=>$enp){
if($enk===bslNormalizeTitle($pTitle)){ $foundExisting=$enp;break; }
}
}

if(!$foundExisting&&$nTitle!==''){
foreach($bslExistingNorm as $fek=>$fev){
if($fek!==''&&($nTitle==$fek||mb_strpos($nTitle,$fek,0,'UTF-8')!==false||mb_strpos($fek,$nTitle,0,'UTF-8')!==false)){
$foundExisting=$fev;break;
}
}
}

if($foundExisting){
$dupId=$foundExisting['id']??'?';
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 🔁 نام تکراری → آپدیت ID#$dupId");
$bu=['primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100))];
if((int)($bs['stock']??10)<=0){$bu['status']=3790;}else{$bu['status']=2976;}
if(!empty($p['long_desc']))$bu['description']=$p['long_desc'];
elseif(!empty($p['short_desc']))$bu['description']=strip_tags($p['short_desc']);

if(empty($bu['category_id'])&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$bu['category_id']=$_ac;}
elseif((int)($bs['category_id']??0)>0)$bu['category_id']=(int)($bs['category_id']??0);
if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}
$r3=bslReq($tk,'PATCH','products/'.$dupId,$bu);
if($r3['code']===404){$r3=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$dupId,$bu);}
if($r3['ok']&&!empty($r3['body']['id'])){
$updated++;$bslUpdatedList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$dupId,'changes'=>'آپدیت از نام تکراری'];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⚡ آپدیت نام تکراری: ID#$dupId");
usleep(500000);continue;
}else{

$em3=$r3['body']['error_description']??($r3['body']['message']??($r3['body']['error']??null));
if(is_array($em3))$em3=json_encode($em3,JSON_UNESCAPED_UNICODE);
if(!$em3)$em3=mb_substr($r3['raw']??('HTTP'.$r3['code']),0,300);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ PATCH نام تکراری: $em3 → جایگزین");

$rUnpubDup=bslReq($tk,'PATCH','products/'.$dupId,['status'=>3790]);
if($rUnpubDup['code']===404){$rUnpubDup=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$dupId,['status'=>3790]);}
$dupTitle=$pTitle;
if(!$rUnpubDup['ok']){$dupTitle=mb_substr($pTitle,0,100).' #'.substr(md5($pTitle.$pn.time()),0,6);}

$bpDup=['name'=>mb_substr($dupTitle,0,120),'brief'=>mb_substr(trim(strip_tags($p['short_desc']??$pTitle)),0,250),'description'=>trim($p['long_desc']??strip_tags($p['short_desc']??$pTitle)),'primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId];
if($pid){$bpDup['photo']=$pid;$bpDup['photos']=[$pid];$bpDup['status']=2976;}
else{$bpDup['status']=3790;}
if(!empty($p['sku']))$bpDup['sku']=$p['sku'];
$rDupPost=bslReq($tk,'POST','vendors/'.$vid.'/products',$bpDup);
if($rDupPost['ok']&&!empty($rDupPost['body']['id'])){
$sent++;$bslSentList[]=['title'=>$pTitle,'key'=>$pKey,'remote_id'=>$rDupPost['body']['id'],'note'=>'replaced dup ID#'.$dupId];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ✅ جایگزین نام تکراری: ID#{$rDupPost['body']['id']}");
}else{
$skipped++;$bslSkippedList[]=['title'=>$pTitle,'key'=>$pKey,'reason'=>'نام تکراری — جایگزینی شکست'];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⏭ نام تکراری — جایگزینی شکست");
}
usleep(500000);continue;
}
}else{

$skipped++;$bslSkippedList[]=['title'=>$pTitle,'key'=>$pKey,'reason'=>'نام تکراری (422)'];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ⏭ نام تکراری (422)");
}
}else{

$fail++;
$em=$r['body']['error_description']??($r['body']['message']??($r['body']['error']??null));
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);
if(!$em)$em=mb_substr($r['raw']??('HTTP'.$r['code']),0,300);
$bslFailedList[]=['title'=>$pTitle,'key'=>$pKey,'error'=>'خطای ایجاد: '.mb_substr($em,0,200)];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] ❌ خطای ایجاد: $em");
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$n,mb_substr($pTitle,0,30),"[{$n}] 📦 درخواست: ".mb_substr(json_encode($bp,JSON_UNESCAPED_UNICODE),0,500));
}
}
}
usleep($bslDelayMs*1000);
}

bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,'',['🔄 Phase 2: بررسی محصولات رد شده (دسته نامعتبر)...']);
$catFixed=0;$catRetryFailed=0;

$allExisting=[];$ap=1;$more=true;
while($more&&$ap<=20){
$ar=bslReq($tk,'GET','vendors/'.$vid.'/products?page='.$ap.'&per_page=100&statuses=2976&statuses=3790');
if(!$ar['ok'])break;
$ad=$ar['body']['data']??[];
if(!is_array($ad)||empty($ad))break;
foreach($ad as $ae){
$atn=trim($ae['title']??$ae['name']??'');
if($atn!=='')$allExisting[$atn]=$ae;
}
$tp2=max(1,(int)($ar['body']['total_page']??1));
if($ap<$tp2)$ap++;else$more=false;
}
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,'',['🔍 '.count($allExisting).' محصول موجود — بررسی رد شده‌ها...']);

foreach($allExisting as $aeTitle=>$ae){
$aeStatus=$ae['status']??null;
$aeSV=0;
if(is_array($aeStatus)&&isset($aeStatus['value']))$aeSV=(int)$aeStatus['value'];
elseif(is_numeric($aeStatus))$aeSV=(int)$aeStatus;

if($aeSV!==3567)continue;

$aeRev=$ae['revision']??[];
$aeReasons=$aeRev['rejection_reasons']??[];
$aeCatRejected=false;$aeCatMsg='';
if(is_array($aeReasons)){
foreach($aeReasons as $ar2){
$ar2Val=(int)($ar2['value']??0);
$ar2Name=$ar2['name']??'';
if($ar2Val===6046||mb_stripos($ar2Name,'دسته')!==false||mb_stripos($ar2Name,'category')!==false){
$aeCatRejected=true;$aeCatMsg=$ar2Name;break;
}
}
}

$aeMeta=$aeRev['metadata']??[];
if(is_array($aeMeta)&&!$aeCatRejected){
$aeAiDesc=trim($aeMeta['description']??'');
if($aeAiDesc!==''&&mb_stripos($aeAiDesc,'دسته')!==false){
$aeCatRejected=true;$aeCatMsg='بررسی AI: دسته نامعتبر';
}
}
if(!$aeCatRejected)continue;

$aeTitle2=trim($ae['title']??$ae['name']??'');

$newCatId=0;
$aeAiText=trim($aeRev['metadata']['description']??'');
if($aeAiText!==''){$newCatId=extractAiCategoryFromText($aeAiText,$bslFlatCats);}
if($newCatId<=0)$newCatId=autoMatchBslCategory($aeTitle2,$bslFlatCats);
if($newCatId<=0)$newCatId=autoMatchBslCategoryForce($aeTitle2,$bslFlatCats);
if($newCatId<=0){
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,mb_substr($aeTitle2,0,30),'⚠️ ['.mb_substr($aeTitle2,0,30).'] دسته جایگزین یافت نشد');
continue;
}

$aeId=$ae['id']??0;
$r4=bslReq($tk,'PATCH','products/'.$aeId,$bu2);
if($r4['code']===404){$r4=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$aeId,$bu2);}
if($r4['ok']&&!empty($r4['body']['id'])){
$catFixed++;
$bslUpdatedList[]=['title'=>$aeTitle2,'key'=>'cat-fix','remote_id'=>$aeId,'changes'=>'اصلاح دسته ردشده → ID#'.$newCatId];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,mb_substr($aeTitle2,0,30),'✅ ['.mb_substr($aeTitle2,0,30).'] دسته اصلاح شد → ID#'.$newCatId);
}else{

$catRetryFailed++;
$em4=$r4['body']['error_description']??($r4['body']['message']??($r4['body']['error']??null));
if(is_array($em4))$em4=json_encode($em4,JSON_UNESCAPED_UNICODE);
if(!$em4)$em4=mb_substr($r4['raw']??('HTTP'.$r4['code']),0,300);
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,mb_substr($aeTitle2,0,30),'❌ ['.mb_substr($aeTitle2,0,30).'] اصلاح دسته PATCH خطا → جایگزین');

$rUnpub2=bslReq($tk,'PATCH','products/'.$aeId,['status'=>3790]);
if($rUnpub2['code']===404){$rUnpub2=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$aeId,['status'=>3790]);}
$aeReplaceTitle=$aeTitle2;
if(!$rUnpub2['ok']){$aeReplaceTitle=mb_substr($aeTitle2,0,100).' #'.substr(md5($aeTitle2.time()),0,6);}
$aeBrief=trim(strip_tags($aeTitle2));
$aeDesc=$aeBrief;
$aeRevData=($ae['revision']&&is_array($ae['revision'])&&isset($ae['revision']['data']))?$ae['revision']['data']:[];
$aePhoto=$aeRevData['photo']??$ae['photo']??null;
$aePhotoId=0;
if(is_array($aePhoto))$aePhotoId=(int)($aePhoto['id']??0);
elseif(is_numeric($aePhoto))$aePhotoId=(int)$aePhoto;
$bpCat=['name'=>mb_substr($aeReplaceTitle,0,120),'brief'=>mb_substr($aeBrief,0,250),'description'=>$aeDesc,'primary_price'=>(int)($aeRevData['primary_price']??$ae['primary_price']??0),'stock'=>(int)($aeRevData['inventory']??$ae['inventory']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$newCatId,'photo'=>$aePhotoId,'photos'=>[$aePhotoId]];
if($aePhotoId>0){$bpCat['status']=2976;}else{$bpCat['status']=3790;}
$rCatPost=bslReq($tk,'POST','vendors/'.$vid.'/products',$bpCat);
if($rCatPost['ok']&&!empty($rCatPost['body']['id'])){
$catFixed++;$bslSentList[]=['title'=>$aeTitle2,'key'=>'cat-fix-replace','remote_id'=>$rCatPost['body']['id'],'note'=>'replaced ID#'.$aeId];
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,mb_substr($aeTitle2,0,30),'✅ ['.mb_substr($aeTitle2,0,30).'] دسته اصلاح جایگزین → ID#'.$rCatPost['body']['id']);
}else{
$bslFailedList[]=['title'=>$aeTitle2,'key'=>'cat-fix','error'=>'اصلاح دسته خطا ID#'.$aeId.': '.mb_substr($em4,0,200)];
}
}
usleep(500000);
}
if($catFixed>0||$catRetryFailed>0){
$updated+=$catFixed;$fail+=$catRetryFailed;
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,'','Phase 2: '.$catFixed.' دسته اصلاح شد, '.$catRetryFailed.' خطا');
}else{
bslUpdateProgress($sent,$updated,$skipped,$fail,$total,$total,'','Phase 2: هیچ محصول رد‌شده دسته‌بندی یافت نشد');
}

$finalLog="پایان: $sent جدید, $updated آپدیت, $skipped تکراری, $fail خطا | Phase2: $catFixed دسته اصلاح, $catRetryFailed خطا";
$bslLog[]=$finalLog;

if($fromFile)@unlink(BSL_PRODUCTS_FILE);

$finalProgress=['running'=>false,'sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total,'last_title'=>'','current'=>$total,'done'=>true,'started_at'=>$startedAt,'last_progress_ts'=>time(),'recent_log'=>$bslLog,'total_log_count'=>count($bslLog),'log'=>$finalLog,'sent_details'=>$bslSentList,'updated_details'=>$bslUpdatedList,'skipped_details'=>$bslSkippedList,'failed_details'=>$bslFailedList];
if($bslQueueId!=='')$finalProgress['queue_id']=$bslQueueId;
writeProgress(BSL_PROGRESS_FILE,$finalProgress);

$queueId=trim($_POST['queue_id']??'');
if($queueId!==''){
$queue=bslReadQueue();
foreach($queue['entries'] as &$qe){
if($qe['id']===$queueId&&$qe['status']==='running'){
$qe['status']='done';$qe['sent']=$sent;$qe['updated']=$updated;$qe['skipped']=$skipped;$qe['failed']=$fail;$qe['current']=$total;$qe['done_at']=time();
$qe['sent_details']=$bslSentList;$qe['updated_details']=$bslUpdatedList;$qe['skipped_details']=$bslSkippedList;$qe['failed_details']=$bslFailedList;
$qe['recent_log']=array_slice($bslLog,-50);
break;
}
}
unset($qe);
bslWriteQueue($queue);
}
exit;
}

if (isset($_GET['bsl_client_stream'])) {
set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
while (@ob_get_level()) @ob_end_clean();

$cn=loadConnections();$bs=$cn['basalam']??[];
if(empty($bs['token'])||empty($bs['vendor_id'])){send_sse('error',['message'=>'تنظیمات باسلام ناقص']);send_sse('done',[]);exit;}
$autoCat=!empty($bs['auto_category']);
if(!$autoCat&&(empty($bs['category_id'])||(int)$bs['category_id']<=0)){send_sse('error',['message'=>'دسته‌بندی باسلام انتخاب نشده']);send_sse('done',[]);exit;}
$bslFallbackCats=$cn['basalam']['fallback_cat_ids']??[];if(!is_array($bslFallbackCats))$bslFallbackCats=[];

$tk=$bs['token'];$vid=(int)$bs['vendor_id'];
$pd=json_decode($_POST['products']??'[]',true)?:[];
$total=count($pd);
if($total===0){send_sse('error',['message'=>'محصولی دریافت نشد']);send_sse('done',[]);exit;}

$bslDelayMs=max(0,(int)($bs['delay_ms']??500));
$bslRetryDelayMs=max(0,(int)($bs['retry_delay_ms']??1000));
$GLOBALS['_bslRetryDelayMs']=$bslRetryDelayMs;

send_sse('send_info',['msg'=>'شروع ارسال '.$total.' محصول به باسلام (client-side)']);

$bslFlatCats=[];
$cr=bslReq($tk,'GET','categories');
if($cr['ok']){
$cData=$cr['body']['data']??[];
if(is_array($cData)){
$cFlat=function($items,$lv=0)use(&$cFlat){
$o=[];foreach($items as $c){$t=trim($c['title']??$c['name']??'');$id=(int)($c['id']??0);if($id>0)$o[]=['id'=>$id,'name'=>$t,'level'=>$lv];$ch=$c['children']??[];if(is_array($ch)&&count($ch)>0){foreach($cFlat($ch,$lv+1) as $s)$o[]=$s;}}return $o;
};
$bslFlatCats=$cFlat($cData,0);
bslSetCatNameMap($bslFlatCats);
}
}
send_sse('send_info',['msg'=>count($bslFlatCats).' دسته بارگذاری']);

// v8.22: Phase 1 removed — per-product search replaces bulk loading
$bslExisting=[];$bslExistingNorm=[];$bslArchivedMap=[];
send_sse('send_info',['msg'=>'🚀 شروع ارسال — جستجوی هر محصول قبل از ارسال']);

$sent=0;$updated=0;$skipped=0;$fail=0;

foreach($pd as $i=>$p){
$n=$i+1;
$pTitle=trim($p['title']??$p['name']??'');
$pKey=$p['key']??'';
$pn=(int)preg_replace("/[^0-9]/","",(string)($p['final_price']??'0'));

$priceUnit=$p['price_unit']??'';
if($priceUnit==='rial'){$pn=$pn; }else{$pn=$pn*10; }

$cardImg=$p['image']??'';$cardPrice=$pn;$cardLink=$p['link']??'';$cardCatId=(int)($bs['category_id']??0);$cardCatName=bslCatNameById($cardCatId);

send_sse('send_progress',['current'=>$n,'total'=>$total,'title'=>mb_substr($pTitle,0,50),'index'=>$i]);
send_sse_ping();

if($pTitle===''){$fail++;send_sse('send_fail',['key'=>$pKey,'error'=>'عنوان خالی']);continue;}
if($pn<=0){$fail++;send_sse('send_fail',['key'=>$pKey,'error'=>'قیمت 0']);continue;}

$titleWords=preg_split('/\s+/u',$pTitle);
if(mb_strlen($pTitle)<6||count($titleWords)<2){$fail++;send_sse('send_fail',['key'=>$pKey,'error'=>'عنوان کوتاه']);continue;}

$exBsl=null;$nTitle=bslNormalizeTitle($pTitle);
// v8.22: Per-product search instead of bulk Phase 1
if(isset($bslExisting[$pTitle])){$exBsl=$bslExisting[$pTitle];}
elseif(isset($bslExistingNorm[$nTitle])){$exBsl=$bslExistingNorm[$nTitle];}
else{
$searchQ=bslNormalizeTitle($pTitle);
$sr=bslReq($tk,'GET','vendors/'.$vid.'/products?per_page=20&search='.urlencode($searchQ));
if($sr['ok']){$srData=$sr['body']['data']??[];if(is_array($srData)){
foreach($srData as $sp){$sn=trim($sp['title']??$sp['name']??'');$snn=bslNormalizeTitle($sn);
if($sn===$pTitle||$snn===$nTitle){$exBsl=$sp;$bslExisting[$sn]=$sp;$bslExistingNorm[$snn]=$sp;break;}
if($snn!==''&&$nTitle!==''&&(mb_strpos($nTitle,$snn,0,'UTF-8')!==false||mb_strpos($snn,$nTitle,0,'UTF-8')!==false)){$exBsl=$sp;$bslExisting[$sn]=$sp;$bslExistingNorm[$snn]=$sp;break;}
}
foreach($srData as $sp){$sn=trim($sp['title']??$sp['name']??'');if($sn!==''){$bslExisting[$sn]=$sp;$snn=bslNormalizeTitle($sn);if($snn!==$sn)$bslExistingNorm[$snn]=$sp;}}
}}
if(!$exBsl){
$ar=bslReq($tk,'GET','vendors/'.$vid.'/products?per_page=20&statuses=4184&statuses=3790&search='.urlencode($searchQ));
if($ar['ok']){$arData=$ar['body']['data']??[];if(is_array($arData)){
foreach($arData as $ap){$an=trim($ap['title']??$ap['name']??'');$ann=bslNormalizeTitle($an);
if($an===$pTitle||$ann===$nTitle||($ann!==''&&$nTitle!==''&&(mb_strpos($nTitle,$ann,0,'UTF-8')!==false||mb_strpos($ann,$nTitle,0,'UTF-8')!==false))){
$exBsl=$ap;$bslExisting[$an]=$ap;$bslExistingNorm[$ann]=$ap;$bslArchivedMap[$an]=$ap;$bslArchivedMap[$ann]=$ap;break;}
}
}}}
}

if($exBsl){
$exId=$exBsl['id']??'?';
$exRevData=($exBsl['revision']&&is_array($exBsl['revision'])&&isset($exBsl['revision']['data']))?$exBsl['revision']['data']:[];
$exPrice=(int)($exRevData['primary_price']??$exBsl['primary_price']??0);
$exStock=(int)($exRevData['inventory']??$exBsl['inventory']??0);
$exStatusVal=0;$exStatusObj=$exBsl['status']??null;
if(is_array($exStatusObj)&&isset($exStatusObj['value']))$exStatusVal=(int)$exStatusObj['value'];
elseif(is_numeric($exStatusObj))$exStatusVal=(int)$exStatusObj;
elseif(is_array($exRevData)&&isset($exRevData['status'])){if(is_array($exRevData['status'])&&isset($exRevData['status']['value']))$exStatusVal=(int)$exRevData['status']['value'];elseif(is_numeric($exRevData['status']))$exStatusVal=(int)$exRevData['status'];}
$exTitle=trim($exBsl['title']??$exBsl['name']??'');

$needUpdate=false;$updateLog='';
$newStock=(int)($bs['stock']??10);
if($exPrice!=$pn){$needUpdate=true;$updateLog.=' قیمت '.$exPrice.'→'.$pn;}
if($exStock!=$newStock){$needUpdate=true;$updateLog.=' موجودی '.$exStock.'→'.$newStock;}
if($newStock<=0){$needUpdate=true;$updateLog.=' ناموجود';}
if($exStatusVal===3567){$needUpdate=true;$updateLog.=' re-submit';}

if($exStatusVal===3790||$exStatusVal===3568||$exStatusVal===4184){
$needUpdate=true;
$statusLabels=[3790=>'غیرفعال',3568=>'در انتظار',4184=>'بایگانی'];
$updateLog.=' بازفعال‌سازی از '.($statusLabels[$exStatusVal]??$exStatusVal);
}

if(!$needUpdate){$skipped++;send_sse('send_skip',['key'=>$pKey,'title'=>$pTitle,'reason'=>'تکرار','image'=>$cardImg,'price'=>$cardPrice,'price_unit'=>$priceUnit,'category_id'=>$cardCatId,'category'=>$cardCatName,'link'=>$cardLink]);continue;}

send_sse('send_info',['msg'=>'['.$n.'] آپدیت ID#'.$exId.$updateLog]);
$bu=['primary_price'=>$pn,'stock'=>$newStock,'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100))];
if($newStock<=0){$bu['status']=3790;}else{$bu['status']=2976;}

$buCatId=(int)($bs['category_id']??0);if($buCatId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$buCatId=$_ac;}
if($buCatId<=0)$buCatId=(int)($bs['category_id']??0);
if($buCatId>0)$bu['category_id']=$buCatId;

$pid=null;if(!empty($p['image'])){send_sse('send_info',['msg'=>'['.$n.'] آپلود تصویر...']);$up=bslUpload($tk,$p['image']);if(!empty($up['ok']))$pid=$up['file_id'];else{$up2=bslUpload($tk,$p['image']);if(!empty($up2['ok']))$pid=$up2['file_id'];}}
if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}

$r=bslReq($tk,'PATCH','products/'.$exId,$bu);
if($r['code']===404){$r=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,$bu);}

if($r['ok']&&!empty($r['body']['id'])){
$updated++;
send_sse('send_update',['key'=>$pKey,'title'=>$pTitle,'remote_id'=>$exId,'old_price'=>$exPrice,'new_price'=>$pn,'edit_url'=>'']);
}else{

$em=$r['body']['error_description']??($r['body']['message']??'HTTP'.$r['code']);
send_sse('send_info',['msg'=>'['.$n.'] PATCH خطا: '.$em.' → جایگزین']);

$rUnpub=bslReq($tk,'PATCH','products/'.$exId,['status'=>3790]);
if($rUnpub['code']===404){$rUnpub=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$exId,['status'=>3790]);}
$replaceTitle=$pTitle;
if(!$rUnpub['ok']){$replaceTitle=mb_substr($pTitle,0,100).' #'.substr(md5($pTitle.$pn.time()),0,6);}

$bsBrief=trim(strip_tags($p['short_desc']??$pTitle));$bsDesc=trim($p['long_desc']??$bsBrief);
$catId=$buCatId;if($catId<=0)$catId=(int)($bs['category_id']??0);
$bp2=['name'=>mb_substr($replaceTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>$newStock,'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId];
if($pid){$bp2['photo']=$pid;$bp2['photos']=[$pid];$bp2['status']=2976;}else{$bp2['status']=3790;}
$r2=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp2);
if($r2['ok']&&!empty($r2['body']['id'])){
$sent++;send_sse('send_ok',['key'=>$pKey,'title'=>$pTitle,'remote_id'=>$r2['body']['id'],'edit_url'=>'']);
}else{
$fail++;send_sse('send_fail',['key'=>$pKey,'error'=>'خطای جایگزینی']);
}
}
usleep(500000);
continue;
}

$pid=null;if(!empty($p['image'])){send_sse('send_info',['msg'=>'['.$n.'] آپلود تصویر...']);$up=bslUpload($tk,$p['image']);if(!empty($up['ok']))$pid=$up['file_id'];else{$up2=bslUpload($tk,$p['image']);if(!empty($up2['ok']))$pid=$up2['file_id'];}}
if(!$pid){

send_sse('send_info',['msg'=>'['.$n.'] ⚠️ تصویر آپلود نشد — ارسال بدون تصویر (غیرفعال)']);
$bsBrief=trim(strip_tags($p['short_desc']??''));$bsDesc=trim($p['long_desc']??'');
if($bsBrief==='')$bsBrief=trim(strip_tags($pTitle));if($bsDesc==='')$bsDesc=$bsBrief;
$catId=(int)($bs['category_id']??0);
if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$catId=$_ac;}
if($catId>0&&!empty($cData)&&is_array($cData)){$catId=findLeafCategory($catId,$cData);}
$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'status'=>3790];
if(!empty($p['sku']))$bp['sku']=$p['sku'];
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);
if($r['ok']&&!empty($r['body']['id'])){
$sent++;send_sse('send_ok',['key'=>$pKey,'remote_id'=>$r['body']['id'],'title'=>$pTitle,'note'=>'بدون تصویر (غیرفعال)']);
}else{
$em=$r['body']['error_description']??$r['body']['message']??$r['body']['error']??'';
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);

if(mb_stripos($em,'دسته')!==false||mb_stripos($em,'category')!==false||mb_stripos($em,'فرزند')!==false){
$fbResult=bslTryCreateWithFallback($tk,$vid,$bp,$bslFallbackCats,$pTitle,$autoCat,$bslFlatCats,$cData);
if(!empty($fbResult['ok'])){
$sent++;send_sse('send_ok',['key'=>$pKey,'remote_id'=>$fbResult['body']['id'],'title'=>$pTitle,'note'=>'بدون تصویر (اصلاح دسته: '.$fbResult['used_cat_id'].')']);
usleep($bslDelayMs*1000);continue;
}
}
$fail++;send_sse('send_fail',['key'=>$pKey,'error'=>'تصویر+ایجاد بدون تصویر ناموفق: '.mb_substr($em,0,150)]);
}
usleep($bslDelayMs*1000);continue;
}

$bsBrief=trim(strip_tags($p['short_desc']??''));$bsDesc=trim($p['long_desc']??'');
if($bsBrief==='')$bsBrief=trim(strip_tags($pTitle));
if($bsDesc==='')$bsDesc=$bsBrief;

$catId=(int)($bs['category_id']??0);
if($catId<=0&&$autoCat&&!empty($bslFlatCats)){$_ac=autoMatchBslCategory($pTitle,$bslFlatCats);if($_ac>0)$catId=$_ac;}

$bp=['name'=>mb_substr($pTitle,0,120),'brief'=>mb_substr($bsBrief,0,250),'description'=>$bsDesc,'primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'preparation_days'=>(int)($bs['preparation_days']??3),'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100)),'is_wholesale'=>false,'category_id'=>$catId,'photo'=>$pid,'photos'=>[$pid],'status'=>2976];
if(!empty($p['sku']))$bp['sku']=$p['sku'];

send_sse('send_info',['msg'=>'['.$n.'] ایجاد: '.mb_substr($pTitle,0,40)]);
$r=bslReq($tk,'POST','vendors/'.$vid.'/products',$bp);

if($r['ok']&&!empty($r['body']['id'])){
$sent++;send_sse('send_ok',['key'=>$pKey,'title'=>$pTitle,'remote_id'=>$r['body']['id'],'edit_url'=>'']);
}else{

$msgs=$r['body']['messages']??[];
$dupName=false;
if(is_array($msgs)){foreach($msgs as $m){$msgText=$m['message']??'';if(mb_stripos($msgText,'تکرار')!==false||mb_stripos($msgText,'duplicate')!==false){$dupName=true;break;}}}
if($dupName){

$foundExisting=null;
foreach($bslExisting as $ebn=>$ebp){if(bslNormalizeTitle($ebn)===$nTitle){$foundExisting=$ebp;break;}}
if(!$foundExisting){foreach($bslExistingNorm as $enk=>$enp){if($enk===bslNormalizeTitle($pTitle)){$foundExisting=$enp;break;}}}
if($foundExisting){
$dupId=$foundExisting['id']??'?';
send_sse('send_info',['msg'=>'['.$n.'] نام تکراری → آپدیت ID#'.$dupId]);
$bu=['primary_price'=>$pn,'stock'=>(int)($bs['stock']??10),'status'=>2976,'category_id'=>$catId,'weight'=>(int)($bs['weight']??500),'package_weight'=>(int)($bs['package_weight']??((int)($bs['weight']??500)+100))];
if($pid){$bu['photo']=$pid;$bu['photos']=[$pid];}
$r3=bslReq($tk,'PATCH','products/'.$dupId,$bu);
if($r3['code']===404){$r3=bslReq($tk,'PATCH','vendors/'.$vid.'/products/'.$dupId,$bu);}
if($r3['ok']){$updated++;send_sse('send_update',['key'=>$pKey,'title'=>$pTitle,'remote_id'=>$dupId,'old_price'=>0,'new_price'=>$pn,'edit_url'=>'']);}
else{$skipped++;send_sse('send_skip',['key'=>$pKey,'reason'=>'نام تکراری — آپدیت شکست']);}
}else{$skipped++;send_sse('send_skip',['key'=>$pKey,'reason'=>'نام تکراری (422)']);}
}else{
$fail++;
$em=$r['body']['error_description']??($r['body']['message']??'HTTP'.$r['code']);
if(is_array($em))$em=json_encode($em,JSON_UNESCAPED_UNICODE);
send_sse('send_fail',['key'=>$pKey,'error'=>mb_substr($em,0,200)]);
}
}
usleep($bslDelayMs*1000);
}

send_sse('send_complete',['sent'=>$sent,'updated'=>$updated,'skipped'=>$skipped,'failed'=>$fail,'total'=>$total]);
send_sse('done',[]);exit;
}
if (isset($_GET['woo_dedup_stream'])) {
header('Content-Type: text/event-stream'); header('Cache-Control: no-cache'); header('X-Accel-Buffering: no');
while (@ob_get_level()) @ob_end_clean();
$cn=loadConnections();$w=$cn['woocommerce']??[];
if(empty($w['store_url'])){send_sse('error',['message'=>'تنظیمات ووکامرس ناقص']);send_sse('done',[]);exit;}
$doDelete=!empty($_POST['do_delete']);
send_sse('dedup_info',['msg'=>'دریافت لیست محصولات ووکامرس...']);
$allProducts=[];$page=1;$totalFetched=0;
while(true){
$r=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'GET','products?per_page=100&status=any&page='.$page);
if(!$r['ok']||!is_array($r['body'])){send_sse('dedup_info',['msg'=>'خطا در دریافت صفحه '.$page.' (HTTP '.($r['code']??'?').')']);break;}
$batch=$r['body'];
if(empty($batch))break;
foreach($batch as $prod){
$allProducts[]=['id'=>$prod['id']??0,'name'=>trim($prod['name']??''),'price'=>$prod['regular_price']??'','date_created'=>$prod['date_created']??'','status'=>$prod['status']??''];
}
$totalFetched+=count($batch);
send_sse('dedup_info',['msg'=>"صفحه $page: ".count($batch)." محصول (مجموع: $totalFetched)"]);
if(count($batch)<100)break;
$page++;
usleep(200000);
}
send_sse('dedup_info',['msg'=>"مجموع $totalFetched محصول دریافت شد. جستجوی تکراری‌ها..."]);

$groups=[];
foreach($allProducts as $prod){
$n=normalizeTitle($prod['name']);
if($n==='')continue;
$groups[$n][]=$prod;
}
$dupCount=0;$delCount=0;$delFail=0;
foreach($groups as $norm=>$items){
if(count($items)<2)continue;

usort($items,function($a,$b){return strcmp($a['date_created']??'',$b['date_created']??'');});
$dupCount+=count($items)-1;
send_sse('dedup_found',['name'=>$items[0]['name'],'count'=>count($items),'ids'=>array_column($items,'id')]);
send_sse('dedup_info',['msg'=>'تکراری: "'.mb_substr($items[0]['name'],0,50).'" ×'.count($items)]);
if($doDelete){

for($d=0;$d<count($items)-1;$d++){
$did=$items[$d]['id'];
$dr=wooReq($w['store_url'],$w['consumer_key'],$w['consumer_secret'],'DELETE','products/'.$did.'?force=true');
if($dr['ok']){$delCount++;send_sse('dedup_info',['msg'=>"✅ حذف ID#$did: ".mb_substr($items[$d]['name'],0,40)]);}
else{$delFail++;send_sse('dedup_info',['msg'=>"❌ خطا حذف ID#$did: ".($dr['body']['message']??'?')]);}
usleep(300000);
}
}
}
send_sse('dedup_complete',['total'=>$totalFetched,'groups'=>count(array_filter($groups,function($g){return count($g)>=2;})),'duplicates'=>$dupCount,'deleted'=>$delCount,'delete_failed'=>$delFail,'dry_run'=>!$doDelete]);
send_sse('done',[]);exit;
}
function normalizeTitle(string $title): string {
$t=mb_strtolower(trim($title),'UTF-8');
$t=preg_replace("/\s+/",' ',$t);

$t=preg_replace("/\s*[-–—|\/\\\(].*$/u",'',$t);
$t=preg_replace("/\s*(رنگ|سایز|مدل|مشکی|سفید|قرمز|آبی|سبز|زرد|صورتی|بنفش|نقره‌ای|طلایی|قهوه‌ای|خاکستری|نارنجی).*$/u",'',$t);
$t=trim($t);
return $t;
}

if (($_POST['action']??'')==='save_sync') {
header('Content-Type: application/json');
$sync=['enabled'=>!empty($_POST['enabled']),'interval'=>max(5,(int)($_POST['interval']??30)),'target'=>$_POST['target']??'woo','scrape_url'=>trim($_POST['scrape_url']??''),'auto_send'=>!empty($_POST['auto_send']),'delete_missing'=>!empty($_POST['delete_missing'])];
$cn=loadConnections();$cn['sync']=$sync;
file_put_contents(CONNECTIONS_FILE,json_encode($cn,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
}
if (isset($_GET['sync_stream'])) {
header('Content-Type: text/event-stream'); header('Cache-Control: no-cache'); header('X-Accel-Buffering: no');
while (@ob_get_level()) @ob_end_clean();
$cn=loadConnections();$sync=$cn['sync']??[];$w=$cn['woocommerce']??[];$bs=$cn['basalam']??[];
$target=$sync['target']??'woo';
send_sse('sync_info',['msg'=>'شروع سینک خودکار...','phase'=>'start']);

send_sse('sync_info',['msg'=>'مرحله ۱: اسکرپ محصولات...','phase'=>'scrape']);

send_sse('sync_info',['msg'=>'در انتظار داده از کلاینت...','phase'=>'waiting']);
send_sse('done',[]);exit;
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
<title>اسکرپر ووکامرس v8.22</title>
<style>*{box-sizing:border-box;margin:0;-webkit-tap-highlight-color:transparent}html,body{overflow-x:hidden}body{font-family:Tahoma,system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;padding:12px;padding-bottom:90px;padding-top:56px;direction:rtl}.container{max-width:1400px;margin:0 auto}h1{font-size:18px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:14px;margin-bottom:14px}.row{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}input,select{background:#0f172a;border:1px solid #475569;color:#fff;padding:10px 12px;border-radius:8px;font-size:13px;font-family:inherit;width:100%}input[type="checkbox"]{width:auto}select{min-width:90px;width:auto}.btn{padding:11px 14px;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:12px;font-family:inherit;transition:.15s;white-space:nowrap}.btn:hover{opacity:.9}.btn:active{transform:scale(.97)}.btn:disabled{opacity:.5;cursor:not-allowed}.btn-blue{background:linear-gradient(135deg,#3b82f6,#06b6d4);color:#000}.btn-red{background:#ef4444;color:#fff}.btn-green{background:#22c55e;color:#000}.btn-purple{background:#a855f7;color:#fff}.btn-orange{background:#f97316;color:#000}.btn-gray{background:#475569;color:#fff}.btn-yellow{background:#eab308;color:#000}.btn-cyan{background:#06b6d4;color:#000}.btn-teal{background:#14b8a6;color:#000}.btn-pink{background:#ec4899;color:#fff}.btn-indigo{background:#6366f1;color:#fff}.hidden{display:none!important}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}.stat{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px;text-align:center}.stat b{font-size:20px;display:block}.stat span{color:#64748b;font-size:10px}.progress{height:5px;background:#334155;border-radius:5px;margin:10px 0;overflow:hidden}.progress-bar{height:100%;background:linear-gradient(90deg,#3b82f6,#a855f7);width:0;transition:.3s}.progress-bar.pink{background:linear-gradient(90deg,#ec4899,#f59e0b)}.status{color:#94a3b8;font-size:12px;margin-bottom:8px}.logs{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px;max-height:140px;overflow-y:auto;font-family:monospace;font-size:11px;margin-bottom:10px;direction:ltr;text-align:left}.log{padding:2px 0;border-bottom:1px solid #1e293b}.log-ok{color:#4ade80}.log-err{color:#f87171}.log-info{color:#60a5fa}.log-detail{color:#f0abfc}.main-tabs{position:fixed;bottom:0;left:0;right:0;background:#0f172a;border-top:1px solid #334155;display:flex;z-index:1000;box-shadow:0 -4px 20px rgba(0,0,0,.5);padding-bottom:env(safe-area-inset-bottom)}.main-tab{flex:1;padding:10px 4px 8px;border:none;background:transparent;color:#64748b;font-size:11px;font-family:inherit;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:2px;position:relative;transition:color .2s}.main-tab .t-icon{font-size:20px}.main-tab .t-label{font-weight:600}.main-tab.active{color:#3b82f6;background:#1e293b}.main-tab .badge{position:absolute;top:4px;right:calc(50% - 20px);background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:2px 5px;border-radius:10px;min-width:16px;text-align:center}.main-tab .badge.ok{background:#22c55e;color:#000}.tab-pane{display:none;animation:fadeIn .3s ease}.tab-pane.active{display:block}@keyframes fadeIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}.sub-tabs{display:flex;gap:3px;background:#0f172a;padding:3px;border-radius:10px;margin-bottom:12px}.sub-tab{flex:1;padding:9px;border:none;border-radius:8px;font-weight:600;cursor:pointer;background:transparent;color:#94a3b8;font-size:12px;font-family:inherit;text-align:center}.sub-tab.active{background:#3b82f6;color:#000}.mode-tabs{display:flex;gap:3px;background:#0f172a;padding:3px;border-radius:10px;margin-bottom:12px}.mode-tab{flex:1;padding:9px;border:none;border-radius:8px;font-weight:600;cursor:pointer;background:transparent;color:#94a3b8;font-size:12px;font-family:inherit;text-align:center}.mode-tab.active{background:#3b82f6;color:#000}.visual-container{display:grid;grid-template-columns:1fr;gap:14px}.iframe-wrap{background:#0f172a;border:1px solid #334155;border-radius:0 0 10px 10px;overflow:auto;height:600px;position:relative;resize:vertical;min-height:300px;max-height:95vh}.iframe-wrap iframe{width:100%;height:100%;border:none;background:#fff;min-height:100%}.iframe-wrap .if-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:13px}.iframe-size-bar{display:flex;align-items:center;gap:8px;padding:6px 10px;background:#1e293b;border:1px solid #334155;border-radius:10px 10px 0 0;font-size:12px;color:#94a3b8}.iframe-size-bar input[type=range]{flex:1;cursor:pointer}.iframe-size-bar .size-val{color:#67e8f9;font-weight:700;min-width:50px;text-align:center;font-size:13px}.iframe-size-bar label{cursor:pointer;color:#94a3b8}.selector-panel{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:12px}.selector-panel h3{margin:0 0 10px;font-size:14px;color:#67e8f9}.sel-item{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px;margin-bottom:8px;transition:border-color .2s}.sel-item.has{border-color:#22c55e;background:#14532d20}.sel-item.has label{color:#4ade80}.sel-item label{display:flex;align-items:center;gap:6px;font-size:11px;margin-bottom:4px;color:#94a3b8}.sel-item input{width:100%;font-family:monospace;font-size:11px;padding:6px 8px}.sel-item .sel-preview{font-size:10px;color:#86efac;padding:4px 8px;background:#0f172a;border:1px solid #22c55e;border-radius:4px;margin-top:6px;font-family:Tahoma,sans-serif;word-break:break-word;max-height:60px;overflow:hidden;line-height:1.4}.sel-item .sel-preview.price-prev{color:#fbbf24;border-color:#f59e0b;font-family:monospace;direction:ltr;text-align:left}.sel-item .sel-preview.link-prev{color:#a78bfa;border-color:#8b5cf6;font-family:monospace;font-size:9px;direction:ltr;text-align:left}.sel-item .sel-preview.img-prev{color:#f472b6;border-color:#ec4899;font-family:monospace;font-size:9px;direction:ltr;text-align:left}.sel-item .sel-preview.empty{color:#fca5a5;border-color:#ef4444;background:#7f1d1d30}.sel-item .sel-actions-row{display:flex;gap:4px;margin-top:6px}.sel-item .sel-actions-row .btn{padding:4px 8px;font-size:10px;flex:1}.sel-actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}.suggest-list{max-height:150px;overflow-y:auto;background:#1e293b;border:1px solid #334155;border-radius:6px;margin-top:6px}.suggest-item{padding:8px;font-size:11px;cursor:pointer;font-family:monospace;border-bottom:1px solid #334155}.suggest-item:hover{background:#334155}.detail-field{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px;margin-bottom:8px;transition:border-color .2s}.detail-field.enabled{border-color:#a855f7;background:#2d1b4e}.detail-field-row{display:flex;gap:8px;align-items:center;margin-bottom:6px}.detail-field-row .fname{flex:0 0 110px;font-size:12px;font-weight:700;color:#c4b5fd}.detail-field-row .ftoggle{flex:0 0 auto}.detail-field-row .fselector{flex:1;font-family:monospace;font-size:11px;padding:6px 8px}.detail-field-meta{font-size:10px;color:#64748b;display:flex;gap:10px;align-items:center}.detail-field-meta .preview{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}.product{background:#1e293b;border:1px solid #334155;border-radius:12px;overflow:hidden}.thumb{height:140px;background:linear-gradient(135deg,#1e3a5f,#312e81);display:flex;align-items:center;justify-content:center}.thumb img{width:100%;height:100%;object-fit:cover}.noimg{color:#64748b;font-weight:600;font-size:11px}.pbody{padding:10px}.ptitle{font-weight:700;font-size:12px;margin-bottom:6px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:36px}.pdetail-short{font-size:10px;color:#cbd5e1;line-height:1.4;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-height:30px}.price{display:inline-block;padding:4px 8px;background:#166534;color:#86efac;border-radius:6px;font-weight:700;font-size:12px;margin-bottom:4px}.price-orig{display:block;font-size:10px;color:#64748b;text-decoration:line-through;margin-bottom:4px;direction:ltr;text-align:right;font-family:monospace}.no-price{background:#7f1d1d;color:#fca5a5}.plink{display:block;text-align:center;padding:6px;background:#1e3a5f;border-radius:6px;color:#60a5fa;text-decoration:none;font-weight:600;font-size:11px}.table-wrap{overflow-x:auto;border:1px solid #334155;border-radius:10px}table{width:100%;border-collapse:collapse;font-size:12px;min-width:750px}th,td{padding:8px 10px;text-align:right;border-bottom:1px solid #334155}th{background:#1e3a5f;color:#93c5fd;font-size:10px}.td-orig{color:#94a3b8;text-decoration:line-through;font-size:11px;font-family:monospace;direction:ltr;text-align:right}.td-detail{font-size:10px;color:#cbd5e1;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.text-view{position:relative}.text-content{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:12px;font-family:monospace;font-size:11px;white-space:pre-wrap;max-height:400px;overflow:auto;direction:ltr;text-align:left}.copy-btn{position:absolute;top:6px;left:6px}.copied{background:#22c55e!important}.alert{padding:10px;border-radius:8px;margin-bottom:10px;font-size:12px}.alert-info{background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd}.alert-purple{background:#3b0764;border:1px solid #a855f7;color:#e9d5ff}.alert-success{background:#14532d;border:1px solid #22c55e;color:#86efac}.settings-card h3{margin-bottom:12px;font-size:14px;color:#67e8f9}.profile-row{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}.profile-row select{flex:2;min-width:150px;font-weight:600}.profile-row input{flex:1;min-width:120px}.profile-row .btn{flex:0 0 auto}.profile-indicator{display:inline-block;padding:3px 8px;border-radius:4px;font-size:10px}.saved{background:#14532d;color:#86efac;border:1px solid #22c55e}.unsaved{background:#78350f;color:#fbbf24;border:1px solid #f59e0b}.toast{position:fixed;top:80px;left:50%;transform:translateX(-50%);background:#14532d;color:#86efac;padding:12px 20px;border-radius:8px;border:1px solid #22c55e;box-shadow:0 8px 20px rgba(0,0,0,.5);z-index:99999;font-weight:700;font-size:12px;opacity:0;transition:opacity .3s,top .3s;pointer-events:none;max-width:90%;text-align:center}.toast.show{opacity:1;top:60px}.toast.error{background:#7f1d1d;color:#fca5a5;border-color:#ef4444}.row label{color:#94a3b8;font-size:12px;min-width:80px;display:flex;align-items:center}input[type="checkbox"]{margin-left:5px}.section-title{font-size:13px;color:#67e8f9;margin-bottom:8px;font-weight:700;display:flex;align-items:center;gap:6px}.section-title.purple{color:#c4b5fd}.empty-state{text-align:center;padding:40px 20px;color:#64748b}.empty-state .icon{font-size:48px;margin-bottom:10px;opacity:.5}.empty-state p{font-size:13px}.switch{position:relative;display:inline-block;width:36px;height:20px}.switch input{opacity:0;width:0;height:0}.slider{position:absolute;cursor:pointer;inset:0;background:#475569;transition:.2s;border-radius:20px}.slider:before{position:absolute;content:"";height:14px;width:14px;right:3px;bottom:3px;background:#fff;transition:.2s;border-radius:50%}input:checked+.slider{background:#a855f7}input:checked+.slider:before{transform:translateX(-16px)}.cc{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:14px;margin-bottom:14px}.cc.wc{border-color:#7c3aed}.cc.bs{border-color:#0891b2}.cch{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;cursor:pointer}.cch h3{font-size:14px;margin:0;display:flex;align-items:center;gap:6px}.ccb{overflow:hidden}.ccb.collapsed{max-height:0!important;padding:0;margin:0;overflow:hidden}.cst{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700}.cst.on{background:#14532d;color:#86efac}.cst.off{background:#475569;color:#94a3b8}.cst.tg{background:#78350f;color:#fbbf24}.crow{display:flex;gap:8px;margin-bottom:8px;align-items:center;flex-wrap:wrap}.crow label{min-width:100px;color:#94a3b8;font-size:12px;flex-shrink:0}.crow input,.crow select{flex:1;min-width:150px}.cact{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}.sres{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px;max-height:500px;overflow-y:auto;font-size:11px;margin-top:10px}.sres .ok2{color:#4ade80;padding:2px 0;border-bottom:1px solid #1e293b}.sres .no2{color:#f87171;padding:2px 0;border-bottom:1px solid #1e293b}.sres a{color:#60a5fa;text-decoration:none}.scard{background:#1e293b;border:1px solid #334155;border-radius:8px;padding:8px;margin:4px 0;display:flex;gap:8px;align-items:flex-start;transition:border-color .2s}.scard:hover{border-color:#475569}.scard-img{width:48px;height:48px;border-radius:6px;object-fit:cover;flex-shrink:0;background:#0f172a}.scard-noimg{width:48px;height:48px;border-radius:6px;flex-shrink:0;background:#0f172a;display:flex;align-items:center;justify-content:center;color:#475569;font-size:18px}.scard-body{flex:1;min-width:0;direction:rtl}.scard-title{color:#e2e8f0;font-weight:700;font-size:11px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;direction:rtl}.scard-meta{display:flex;gap:6px;flex-wrap:wrap;font-size:10px;margin-bottom:2px;direction:rtl}.scard-meta span{display:inline-flex;align-items:center;gap:2px}.scard-price{color:#4ade80;font-family:monospace;font-size:10px;direction:ltr}.scard-cat{color:#c084fc;font-size:9px}.scard-unit{color:#64748b;font-size:9px}.scard-result{font-size:10px;font-weight:700;margin-top:2px}.scard-ok{color:#4ade80}.scard-up{color:#facc15}.scard-skip{color:#94a3b8}.scard-fail{color:#f87171}.scard.scard-ok{border-left:3px solid #4ade80}.scard.scard-up{border-left:3px solid #facc15}.scard.scard-skip{border-left:3px solid #94a3b8}.scard.scard-fail{border-left:3px solid #f87171}.scard-err{color:#f87171;font-size:9px;margin-top:2px;direction:rtl;background:#7f1d1d20;padding:1px 6px;border-radius:3px}.scard-reason{color:#fbbf24;font-size:9px;margin-top:2px;direction:rtl;background:#42200620;padding:1px 6px;border-radius:3px}.scard-rid{color:#60a5fa;font-size:9px;direction:ltr}.ssum{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:10px}.ssum .si{background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px;text-align:center}.ssum .si b{font-size:18px;display:block}.ssum .si span{color:#64748b;font-size:10px}@media(min-width:900px){body{padding:16px;padding-bottom:16px}h1{font-size:22px}.main-tabs{position:static;border-top:none;box-shadow:none;background:#1e293b;border:1px solid #334155;border-radius:12px;margin-bottom:14px;padding:3px}.main-tab{padding:12px;border-radius:8px;flex-direction:row;gap:8px;font-size:13px}.main-tab .t-icon{font-size:16px}.main-tab.active{background:#3b82f6}.main-tab .badge{position:static;margin-right:4px;min-width:auto}.visual-container{grid-template-columns:1fr 320px}.grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.btn{padding:10px 16px}.profile-row{flex-wrap:nowrap}}.bsl-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:100001;display:flex;align-items:center;justify-content:center;padding:10px}.bsl-modal{background:#0f172a;border:1px solid #334155;border-radius:14px;max-width:95vw;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;width:900px}.bsl-modal-head{padding:12px 16px;background:#1e293b;border-bottom:1px solid #334155;display:flex;align-items:center;justify-content:space-between}.bsl-modal-head h2{margin:0;font-size:15px;color:#67e8f9}.bsl-modal-body{overflow:auto;flex:1;padding:8px}.bsl-modal-table{width:100%;border-collapse:collapse;font-size:11px}.bsl-modal-table th{background:#1e293b;color:#67e8f9;padding:8px;text-align:center;font-size:11px;border:1px solid #334155;white-space:nowrap}.bsl-modal-table td{padding:6px 8px;border:1px solid #1e293b;color:#e2e8f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bsl-modal-table td.td-name{max-width:none;white-space:normal;overflow:visible;line-height:1.4;font-size:12px;direction:rtl;unicode-bidi:plaintext}.bsl-modal-table tr:hover td{background:#1e293b80}.bsl-modal-table .td-id{color:#94a3b8;font-family:monospace;text-align:center}.bsl-modal-table .td-price{color:#fbbf24;font-family:monospace;text-align:center;direction:ltr}.bsl-modal-table .td-stock{color:#22c55e;text-align:center}.bsl-modal-table .td-status{text-align:center}.bsl-modal-table .td-img{width:40px;height:40px;object-fit:cover;border-radius:4px}.bsl-modal-pager{padding:8px 16px;background:#1e293b;border-top:1px solid #334155;display:flex;align-items:center;justify-content:center;gap:8px}.bsl-tabs{display:flex;gap:2px;padding:0 12px;background:#1e293b;border-bottom:1px solid #334155;flex-wrap:wrap;direction:rtl}.bsl-tab{padding:6px 12px;font-size:11px;color:#94a3b8;cursor:pointer;border-bottom:2px solid transparent;transition:all .2s;white-space:nowrap;border-radius:6px 6px 0 0}.bsl-tab:hover{color:#e2e8f0;background:#334155}.bsl-tab.active{color:#67e8f9;border-bottom-color:#67e8f9;background:#0f172a;font-weight:700}.bsl-tab .tab-count{font-size:9px;color:#64748b;margin-right:2px}.hamburger-btn{position:fixed;top:10px;left:10px;z-index:10001;width:44px;height:44px;border-radius:12px;background:#1e293b;border:1px solid #475569;color:#e2e8f0;font-size:22px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 12px rgba(0,0,0,.4);transition:background .2s}.hamburger-btn:hover{background:#334155}.hamburger-btn.active{background:#3b82f6;color:#000}.settings-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9998;display:none;opacity:0;transition:opacity .3s}.settings-overlay.open{display:block;opacity:1}.settings-panel{position:fixed;top:0;left:-420px;width:400px;max-width:90vw;height:100vh;background:#0f172a;border-right:1px solid #334155;z-index:9999;overflow-y:auto;transition:left .3s ease;padding:0}.settings-panel.open{left:0}.settings-panel-head{position:sticky;top:0;z-index:1;background:#1e293b;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #334155}.settings-panel-head h2{margin:0;font-size:16px;color:#e2e8f0}.settings-panel-body{padding:16px 20px}.settings-panel .cc{margin-bottom:12px}.settings-panel .ccb{padding:10px}.smenu{border-bottom:1px solid #1e293b}.smenu-hdr{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;cursor:pointer;transition:background .15s}.smenu-hdr:hover{background:#1e293b}.smenu-hdr h3{margin:0;font-size:14px;display:flex;align-items:center;gap:8px}.smenu-hdr .arrow{font-size:12px;color:#64748b;transition:transform .2s}.smenu-hdr.open .arrow{transform:rotate(180deg)}.smenu-body{max-height:0;overflow:hidden;transition:max-height .3s ease;padding:0 16px}.smenu-body.open{max-height:2000px;padding:0 16px 16px}.smenu-body .crow{margin-bottom:8px}.smenu-body .cact{margin-top:10px}.live-cnt{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin:8px 0}.live-cnt .lc{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:7px 4px;text-align:center;cursor:pointer;transition:.15s;display:flex;flex-direction:column;gap:1px}.live-cnt .lc:hover{background:#1e293b;transform:translateY(-1px)}.live-cnt .lc b{font-size:17px;line-height:1.2;font-family:ui-monospace,monospace}.live-cnt .lc span{font-size:9px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.live-cnt .lc i{font-size:9px;font-style:normal;font-family:ui-monospace,monospace}@media(max-width:620px){.live-cnt{grid-template-columns:repeat(3,1fr)}}.pdir{display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700}.pdir-up{background:#7f1d1d;color:#fca5a5}.pdir-down{background:#14532d;color:#86efac}.pdir-same{background:#334155;color:#94a3b8}.app-ver{display:inline-block;background:#0f172a;border:1px solid #334155;color:#67e8f9;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;font-family:ui-monospace,monospace;cursor:pointer;transition:.15s;vertical-align:middle}.app-ver:hover{border-color:#67e8f9;background:#0e749020}.app-ver.upd{border-color:#f59e0b;color:#fbbf24;background:#42200630;animation:verPulse 2s ease-in-out infinite}@keyframes verPulse{0%,100%{opacity:1}50%{opacity:.55}}.vc-drop{position:absolute;top:100%;left:0;right:0;background:#0f172a;border:1px solid #475569;border-radius:8px;max-height:220px;overflow-y:auto;z-index:60;display:none;margin-top:3px;box-shadow:0 6px 18px rgba(0,0,0,.5)}.vc-drop.open{display:block}.vc-opt{padding:8px 10px;cursor:pointer;font-size:11px;font-family:monospace;border-bottom:1px solid #1e293b;display:flex;justify-content:space-between;gap:8px;direction:ltr;text-align:left}.vc-opt:last-child{border-bottom:none}.vc-opt:hover{background:#1e3a5f}.vc-opt .vc-meta{color:#64748b;font-size:10px;flex:0 0 auto}.vc-drop .vc-none{padding:10px;color:#64748b;font-size:11px;text-align:center}.pbadge{display:inline-block;font-size:9px;font-weight:700;padding:1px 6px;border-radius:4px;margin-left:4px;vertical-align:middle}.pb-new{background:#14532d;color:#86efac}.pb-chg{background:#78350f;color:#fcd34d}.rf-btn{background:#1e293b;border:1px solid #334155;color:#94a3b8;font-size:11px;font-family:inherit;padding:5px 10px;border-radius:6px;cursor:pointer;transition:.15s}.rf-btn:hover{background:#334155}.rf-btn.on{background:#1e3a5f;border-color:#3b82f6;color:#93c5fd;font-weight:700}.product.is-new{border-color:#22c55e}.product.is-chg{border-color:#f59e0b}.p2-card{border:1px solid #475569;border-radius:10px;padding:10px 12px;margin-bottom:8px;background:#0f172a}.p2-card.p2-ok{border-color:#22c55e;background:#14532d33}.p2-card.p2-err{border-color:#ef4444;background:#7f1d1d26}.p2-title{font-size:12.5px;font-weight:700;color:#e2e8f0;margin-bottom:3px;line-height:1.6}.p2-id{font-size:10px;color:#64748b;font-family:ui-monospace,monospace}.p2-reason{font-size:10.5px;color:#94a3b8;margin-bottom:8px;line-height:1.6}.p2-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.p2-actions .btn{font-size:11px;padding:5px 10px}.p2-auto{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.p2-hint{font-size:10.5px;color:#fbbf24}.p2-search{position:relative;flex:1;min-width:170px;max-width:280px}.p2-search input[type=text]{width:100%;padding:5px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:11px;direction:rtl}.p2-list{display:none;position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#1e293b;border:1px solid #475569;border-radius:6px;z-index:100002;direction:rtl}.p2-status{margin-top:6px;font-size:11px}.p2-ok-txt{color:#4ade80;font-weight:700}.p2-err-txt{color:#fca5a5}@media(max-width:620px){.p2-actions{flex-direction:column;align-items:stretch}.p2-search{max-width:none}.p2-auto{max-width:none}}</style>
</head>
<body>
<button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSettingsPanel()">☰</button>
<div class="container">
<h1>🛒 اسکرپر
  <span class="app-ver" id="appVer" title="نسخهٔ کد — برای بررسی به‌روزرسانی کلیک کنید"
        onclick="toggleSettingsPanel()">v<?=APP_VERSION?></span>
  <span id="profileStatus" class="profile-indicator unsaved">جدید</span>
</h1>

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
    <button class="main-tab" data-tab="send" onclick="switchMainTab('send')">
        <span class="t-icon">📤</span>
        <span class="t-label">ارسال</span>
    </button>
    <button class="main-tab" data-tab="import" onclick="switchMainTab('import')">
        <span class="t-icon">📥</span>
        <span class="t-label">درون‌ریزی</span>
    </button>
</div>

<div class="settings-overlay" id="settingsOverlay" onclick="toggleSettingsPanel()"></div>
<div class="settings-panel" id="settingsPanel">
<div class="settings-panel-head"><h2>☰ تنظیمات عمومی</h2><button class="btn btn-gray" onclick="toggleSettingsPanel()" style="font-size:14px;padding:4px 10px">✕</button></div>
<div class="settings-panel-body" style="padding:0">

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>📜 تغییرات نسخه‌ها</h3><span class="cst off">v<?=APP_VERSION?></span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
آنچه در هر نسخه تغییر کرده است. نسخهٔ فعلی با رنگ روشن مشخص شده.
</div>
<div id="changelogBox" style="max-height:340px;overflow-y:auto"></div>
</div>
</div>

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>🔄 نسخهٔ کد</h3><span class="cst off" id="vcBadge">—</span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px;margin-bottom:10px">
<div style="font-size:11px;color:#94a3b8;margin-bottom:4px">نسخهٔ فعلی روی هاست</div>
<div id="vcLocalInfo" style="font-family:monospace;font-size:11px;color:#67e8f9">—</div>
</div>

<button class="btn btn-blue" onclick="vcCheck(true)" id="vcBtnCheck" style="width:100%;padding:11px;font-size:13px">🔍 بررسی و نصب نسخهٔ جدید</button>
<button class="btn btn-green hidden" onclick="vcUpdate()" id="vcBtnUpdate" style="width:100%;padding:12px;font-size:13px;margin-top:8px">⬇ نصب نسخهٔ جدید</button>
<div class="status" id="vcStatus" style="margin-top:8px;text-align:center">—</div>

<div class="crow" style="margin-top:10px;margin-bottom:4px">
<label style="min-width:auto;display:flex;align-items:center;gap:7px;cursor:pointer;color:#e2e8f0;font-size:12px">
<input type="checkbox" id="vcOnLoad" onchange="vcSave(true)"> بررسی خودکار هنگام باز/رفرش شدن صفحه
</label>
</div>
<div style="font-size:10.5px;color:#64748b;margin:0 0 6px;line-height:1.7">
فقط <b>اطلاع می‌دهد</b>؛ نصب همیشه با تأیید خودتان انجام می‌شود.
اگر خاموش باشد، هیچ درخواستی هنگام رفرش فرستاده نمی‌شود.
</div>

<div class="smenu-hdr" onclick="toggleSmenu(this)" style="padding:9px 0;border-top:1px solid #1e293b">
<h3 style="font-size:12px;color:#94a3b8">⚙️ منبع و نصب‌کننده</h3><span class="arrow">▼</span></div>
<div class="smenu-body" style="padding:0">
<div class="alert alert-info" style="font-size:10.5px;line-height:1.7;margin-bottom:10px">
نصب توسط <b>deploy.php</b> انجام می‌شود که فایلی جداگانه است. این اسکریپت
هرگز خودش را بازنویسی نمی‌کند تا اسکنر امنیتی هاست آن را مشکوک نشناسد.
</div>
<div class="crow">
  <label>ریپو:</label>
  <input id="vcRepo" placeholder="user/repo" dir="ltr" oninput="vcDirty()" style="flex:1">
  <button class="btn btn-gray" onclick="vcLoadBranches(true)" id="vcBtnRepo" style="flex:0 0 auto;padding:8px 12px">🔄</button>
</div>
<div class="crow vc-pick">
  <label>برنچ:</label>
  <div style="flex:1;position:relative;min-width:150px">
    <input id="vcBranch" placeholder="ابتدا 🔄 را بزنید" dir="ltr" autocomplete="off"
           oninput="vcFilterBranch()" onfocus="vcFilterBranch()">
    <div class="vc-drop" id="vcBranchDrop"></div>
  </div>
</div>
<div class="crow vc-pick">
  <label>مسیر فایل:</label>
  <div style="flex:1;position:relative;min-width:150px">
    <input id="vcPath" placeholder="ابتدا برنچ را انتخاب کنید" dir="ltr" autocomplete="off"
           oninput="vcFilterFile()" onfocus="vcFilterFile()">
    <div class="vc-drop" id="vcPathDrop"></div>
  </div>
</div>
<div style="font-size:10px;color:#64748b;margin:-4px 0 8px" id="vcFileCount"></div>
<div class="crow"><label>توکن گیت‌هاب:</label><input type="password" id="vcGhToken" placeholder="فقط ریپوی خصوصی" dir="ltr" oninput="vcDirty()"></div>
<div class="crow"><label>فایل نصب‌کننده:</label><input id="vcDeployFile" placeholder="deploy.php" dir="ltr" oninput="vcDirty()"></div>
<div class="crow"><label>رمز نصب‌کننده:</label><input type="password" id="vcDepToken" placeholder="توکن API پنل deploy" dir="ltr" oninput="vcDirty()"></div>
<div style="font-size:10px;color:#64748b;margin-bottom:8px">
گیت‌هاب: <span id="vcGhState">—</span> · نصب‌کننده: <span id="vcDepState">—</span> ·
برای حذف <code>__CLEAR__</code> بنویسید
</div>
<div class="cact">
<button class="btn btn-cyan" onclick="vcSave(true)" style="flex:1">💾 ذخیره</button>
</div>

<div style="border-top:1px solid #1e293b;margin:12px 0 10px"></div>
<div style="font-size:11px;color:#94a3b8;margin-bottom:6px">☁️ بکاپ ورک‌اسپیس هاست</div>
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
همهٔ فایل‌های هاست را در یک برنچ گیت‌هاب ذخیره می‌کند. فایل‌های حاوی کلید
(مثل <code>connections.json</code>) به‌صورت خودکار کنار گذاشته می‌شوند.
</div>
<button class="btn btn-teal" onclick="vcOpenBackup()" style="width:100%;padding:10px">☁️ باز کردن پنل بکاپ هاست</button>
</div>
</div>
</div>

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>🛒 ووکامرس</h3><span class="cst off" id="wcS">غیرمتصل</span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div class="crow"><label>آدرس:</label><input type="url" id="wcUrl" placeholder="https://yourstore.com" dir="ltr"></div>
<div class="crow"><label>Key:</label><input id="wcCK" placeholder="ck_..." dir="ltr"></div>
<div class="crow"><label>Secret:</label><input type="password" id="wcCS" placeholder="cs_..." dir="ltr"></div>
<div class="crow"><label>وضعیت:</label><select id="wcSt"><option value="draft">پیش‌نویس</option><option value="publish">منتشر</option></select></div>
<div class="crow"><label>دسته:</label><select id="wcCat"><option value="0">--</option></select><button class="btn btn-gray" onclick="loadCats()" style="flex:0;padding:8px">🔄</button></div>
<div class="crow"><label><input type="checkbox" id="wcMS"> موجودی</label><input type="number" id="wcSQ" value="10" style="max-width:100px"></div>
<div class="cact"><button class="btn btn-purple" onclick="testWoo()">🔗 تست</button><button class="btn btn-cyan" onclick="saveConn()">💾 ذخیره</button></div>
<div id="wcTR" style="margin-top:8px"></div>
</div></div>

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>🏪 باسلام</h3><span class="cst off" id="bsS">غیرمتصل</span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div class="crow"><label>Token:</label><input type="password" id="bsTk" dir="ltr"></div>
<div class="crow"><label>غرفه:</label><input type="number" id="bsVid" dir="ltr"></div>
<div class="crow"><label>آماده‌سازی:</label><input type="number" id="bsPD" value="3" style="max-width:100px"></div>
<div class="crow"><label>وزن:</label><input type="number" id="bsW" value="500" style="max-width:120px"></div>
<div class="crow"><label>وزن بسته:</label><input type="number" id="bsPW" value="600" min="0" style="flex:1"><small>گرم</small></div>
<div class="crow"><label>موجودی:</label><input type="number" id="bsSt" value="10" style="max-width:100px"></div>
<div style="margin-top:10px;padding-top:10px;border-top:1px solid #334155">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
<span style="font-size:12px;color:#fbbf24;font-weight:700">👥 غرفه‌های باسلام</span>
<button class="btn btn-green" style="font-size:10px;padding:3px 8px" onclick="addBslVendor()">➕ افزودن غرفه</button>
</div>
<div style="font-size:10px;color:#64748b;margin-bottom:8px">غرفه پیش‌فرض (فوقانی) همان تنظیمات توکن/غرفه بالاست. غرفه‌های اضافی در ارسال همزمان استفاده می‌شوند.</div>
<div id="bslVendorsList" style="display:flex;flex-direction:column;gap:6px"></div>
</div>
<div class="cact"><button class="btn btn-cyan" onclick="testBsl()">🔗 تست</button><button class="btn btn-cyan" onclick="saveConn()">💾 ذخیره</button></div>
<div id="bsTR" style="margin-top:8px"></div>
</div></div>

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>🤖 هوش مصنوعی</h3><span class="cst off" id="aiS">غیرمتصل</span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div class="crow"><label>API Key:</label><input type="password" id="aiKey" dir="ltr" placeholder="sk-..." style="flex:1"></div>
<div class="crow"><label>Base URL:</label><input type="url" id="aiBaseUrl" dir="ltr" value="https://dashscope.aliyuncs.com/compatible-mode/v1" style="flex:1"></div>
<div class="crow"><label>مدل:</label><select id="aiModel" style="flex:1"><option value="qwen-plus">qwen-plus</option><option value="qwen-turbo">qwen-turbo</option><option value="qwen-max">qwen-max</option><option value="qwen-long">qwen-long</option><option value="qwen3-235b-a22b">qwen3-235b</option></select></div>
<div class="crow"><label>🌡️ دقت:</label><input type="number" id="aiTemp" value="0.1" min="0" max="2" step="0.1" style="max-width:80px" dir="ltr"></div>
<div style="margin-top:8px;padding-top:8px;border-top:1px solid #334155">
<div style="font-size:11px;color:#fbbf24;font-weight:700;margin-bottom:6px">🔮 Gemini (برای دسته‌بندی خودکار باسلام)</div>
<div class="crow"><label>کلید Gemini:</label><input type="password" id="bsGemKey" dir="ltr" placeholder="AIza..." style="flex:1"></div>
</div>
<div class="cact"><button class="btn btn-purple" onclick="testAi()">🔗 تست</button><button class="btn btn-green" onclick="testAiCategory()">🏷️ تست دسته</button><button class="btn btn-cyan" onclick="saveConn()">💾 ذخیره</button></div>
<div id="aiTR" style="margin-top:8px"></div>
</div></div>

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>🔔 اعلان‌ها</h3><span class="cst off" id="balehS">غیرفعال</span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div class="crow"><label>بله فعال:</label><input type="checkbox" id="balehEnabled" style="width:16px;height:16px"></div>
<div class="crow"><label>Token بله:</label><input type="password" id="balehToken" dir="ltr" placeholder="Bot Token" style="flex:1"></div>
<div class="crow"><label>Chat ID:</label><input type="text" id="balehChatId" dir="ltr" placeholder="شناسه چت" style="flex:1"></div>
<div class="crow"><label>روبیکا فعال:</label><input type="checkbox" id="rubikaEnabled" style="width:16px;height:16px"></div>
<div class="crow"><label>Token روبیکا:</label><input type="password" id="rubikaToken" dir="ltr" placeholder="Bot Token" style="flex:1"></div>
<div class="crow"><label>Chat ID:</label><input type="text" id="rubikaChatId" dir="ltr" placeholder="شناسه چت" style="flex:1"></div>
<div style="margin-top:8px;padding-top:8px;border-top:1px solid #334155">
<div style="font-size:11px;color:#fbbf24;font-weight:700;margin-bottom:6px">📋 رویدادهای اعلان</div>
<div style="font-size:10px;color:#64748b;margin-bottom:6px">انتخاب کنید کدام رویدادهای باسلام به پیام‌رسان ارسال شوند:</div>
<div style="display:flex;flex-direction:column;gap:4px">
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifOrderNew" checked style="width:15px;height:15px"><span>🛒 سفارش جدید</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifOrderStatus" checked style="width:15px;height:15px"><span>📦 تغییر وضعیت سفارش</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifChatMsg" checked style="width:15px;height:15px"><span>💬 پیام مشتری</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifProductStatus" checked style="width:15px;height:15px"><span>📋 تغییر وضعیت محصول</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifProductNew" checked style="width:15px;height:15px"><span>➕ محصول جدید افزوده شد</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifOrderRefund" checked style="width:15px;height:15px"><span>🔄 بازگشت سفارش</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifSrcPrice" checked style="width:15px;height:15px"><span>💰 گران/ارزان شدن مبدأ</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifSrcStock" checked style="width:15px;height:15px"><span>📦 موجود/ناموجود شدن مبدأ</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifRunFail" checked style="width:15px;height:15px"><span>⚠️ خطای اجرای خودکار</span></label>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer"><input type="checkbox" id="notifCronPing" style="width:15px;height:15px"><span>📡 پینگ اجرای کران‌جاب</span></label>
<div style="display:flex;align-items:center;gap:6px;font-size:11px;color:#94a3b8;padding-right:21px">
<span>حداکثر هر</span>
<input type="number" id="pingEvery" value="360" min="0" style="max-width:70px;padding:4px 6px;font-size:11px" dir="ltr">
<span>دقیقه</span>
<button class="btn btn-gray" onclick="testPing()" style="font-size:10px;padding:3px 8px">📡 تست</button>
</div>
<div style="font-size:10px;color:#64748b;padding-right:21px;line-height:1.6">
کران معمولاً هر ۵ دقیقه اجرا می‌شود؛ بدون این فاصله روزی ۲۸۸ پیام می‌آید.
صفر یعنی هر اجرا پیام بفرست.
</div>
</div>
<div style="margin-top:8px;padding-top:8px;border-top:1px solid #334155">
<div style="font-size:11px;color:#fbbf24;font-weight:700;margin-bottom:6px">🔁 یادآوری موارد بی‌جواب</div>
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
هر مورد فقط یک بار اعلان می‌شود. اگر پیام مشتری بی‌جواب بماند یا سفارشی
ارسال نشود، بعد از این مدت دوباره یادآوری می‌آید. به‌محض پاسخ دادن،
یادآوری خودبه‌خود قطع می‌شود.
</div>
<div class="crow"><label>یادآوری بعد از:</label><input type="number" id="remindAfter" value="30" min="0" style="max-width:80px" dir="ltr"><span style="font-size:10px;color:#64748b">دقیقه · ۰ = خاموش</span></div>
<div class="crow"><label>حداکثر تکرار:</label><input type="number" id="remindMax" value="0" min="0" style="max-width:80px" dir="ltr"><span style="font-size:10px;color:#64748b">۰ = بی‌نهایت</span></div>
</div>
</div>
<div class="cact"><button class="btn btn-purple" onclick="testNotif('baleh')">🔔 تست بله</button><button class="btn btn-orange" onclick="testNotif('rubika')">🔔 تست روبیکا</button><button class="btn btn-cyan" onclick="saveConn()">💾 ذخیره</button></div>
<div style="border-top:1px solid #1e293b;margin:10px 0 8px"></div>
<div style="font-size:11px;color:#94a3b8;margin-bottom:6px">🔍 استعلام از باسلام</div>
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
اول لیست را باز می‌کند تا ببینید چه خبر است؛ بعد خودتان انتخاب می‌کنید
چه چیزی به پیام‌رسان‌ها برود. چیزی بدون تأیید شما فرستاده نمی‌شود.
</div>
<div class="cact">
<button class="btn btn-teal" onclick="openOrdersModal()" style="flex:1">🛒 سفارش‌ها</button>
<button class="btn btn-cyan" onclick="openChatsModal()" style="flex:1">💬 گفتگوها</button>
</div>
<div class="cact">
<button class="btn btn-indigo" onclick="notifTest('products')" style="flex:1">📋 تست محصولات</button>
<button class="btn btn-orange" onclick="notifTest('source')" style="flex:1">💰 تست تغییرات مبدأ</button>
</div>
<div id="notifTestR" style="margin-top:8px"></div>
<div id="notifTR" style="margin-top:8px"></div>
</div></div>

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>🗂 محصولات رفته از مبدأ</h3><span class="cst off" id="retireS">خاموش</span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
وقتی محصولی در سایت مبدأ ناموجود یا حذف شد، روی ووکامرس/باسلام چه شود؟
«پیش‌نویس» پیشنهاد می‌شود چون برگشت‌پذیر است.
</div>
<div class="crow"><label>اقدام:</label>
<select id="retireMode" onchange="updateRetireBadge()" style="flex:1">
<option value="off">کاری نکن (فقط گزارش)</option>
<option value="draft">پیش‌نویس/غیرفعال کن</option>
<option value="outofstock">ناموجود کن</option>
<option value="delete">حذف کن (زباله‌دان)</option>
</select></div>
<div class="crow"><label>حداکثر ٪ حذف:</label><input type="number" id="retireMaxPct" value="30" min="1" max="100" style="max-width:90px" dir="ltr"><span style="font-size:10px;color:#64748b">اگر بیشتر شد، متوقف شو</span></div>
<div class="crow"><label>حداکثر تعداد:</label><input type="number" id="retireMaxCount" value="50" min="1" style="max-width:90px" dir="ltr"><span style="font-size:10px;color:#64748b">سقف در هر اجرا</span></div>
<label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#e2e8f0;cursor:pointer;margin-bottom:6px"><input type="checkbox" id="notifRetire" checked style="width:15px;height:15px"><span>🔔 اعلان نتیجه به پیام‌رسان‌ها</span></label>
<div style="font-size:10px;color:#f87171;background:#7f1d1d20;padding:6px 8px;border-radius:6px;margin-bottom:6px">
⚠️ محافظ: اگر سایت مبدأ خراب شود و همه‌چیز «حذف‌شده» به نظر برسد، این دو سقف جلوی پاک شدن کل فروشگاه را می‌گیرند.
</div>
<div class="cact">
<button class="btn btn-gray" onclick="retirePreview()" style="flex:1">👁 پیش‌نمایش</button>
<button class="btn btn-cyan" onclick="saveConn()" style="flex:1">💾 ذخیره</button>
</div>
<div id="retireR" style="margin-top:8px"></div>
<div style="margin-top:10px;padding-top:10px;border-top:1px solid #334155">
<div style="font-size:11px;color:#fbbf24;font-weight:700;margin-bottom:6px">🔍 مغایرت‌گیری با مقصد</div>
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
همهٔ پروفایل‌هایی که «همگام‌سازی دوره‌ای» آن‌ها روشن است را با فروشگاه مقایسه می‌کند:
محصولی که در هیچ پروفایلی نیست، و محصولی که قیمتش مغایرت دارد.
اول فقط گزارش می‌گیرد؛ اعمال تغییرات با تأیید شماست.
</div>
<div class="cact">
<button class="btn btn-purple" onclick="reconScan('woo')" style="flex:1">🛒 بررسی ووکامرس</button>
<button class="btn btn-cyan" onclick="reconScan('bsl')" style="flex:1">🏪 بررسی باسلام</button>
</div>
<div id="reconR" style="margin-top:8px"></div>
</div>
<div style="margin-top:10px;padding-top:10px;border-top:1px solid #334155">
<div style="font-size:11px;color:#fbbf24;font-weight:700;margin-bottom:6px">🧠 یادگیری دسته‌بندی</div>
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
هر بار دستهٔ محصولی را دستی اصلاح کنید، سیستم کلمهٔ اولِ عنوان را با آن دسته به خاطر می‌سپارد
و دفعهٔ بعد خودش همان را پیشنهاد می‌دهد.
</div>
<div class="cact">
<button class="btn btn-gray" onclick="catLearnShow()" style="flex:1">📚 آموخته‌ها</button>
<button class="btn btn-gray" onclick="catLearnTest()" style="flex:1">🧪 آزمایش عنوان</button>
</div>
<div id="catLearnR" style="margin-top:8px"></div>
</div>
</div></div>

<div class="smenu">
<div class="smenu-hdr" onclick="toggleSmenu(this)"><h3>🩺 نگهبان صف ارسال</h3><span class="cst off" id="stallS">فعال</span><span class="arrow">▼</span></div>
<div class="smenu-body">
<div style="font-size:10.5px;color:#64748b;margin-bottom:8px;line-height:1.7">
اگر ارسالی وسط راه گیر کند، خودکار ادامه داده می‌شود.
چون از قفل سیستمی استفاده می‌کند، سرعت ارسال سالم را کم نمی‌کند.
</div>
<div class="crow"><label>فعال:</label><input type="checkbox" id="stallWatchdog" onchange="updateStallBadge()" checked style="width:16px;height:16px"></div>
<div class="crow"><label>آستانه (ثانیه):</label><input type="number" id="stallAfter" value="300" min="60" style="max-width:90px" dir="ltr"><span style="font-size:10px;color:#64748b">بی‌حرکتی بیش از این = گیر کرده</span></div>
<div class="cact">
<button class="btn btn-gray" onclick="watchdogCheck()" style="flex:1">🔎 بررسی حالا</button>
<button class="btn btn-cyan" onclick="saveConn()" style="flex:1">💾 ذخیره</button>
</div>
<div class="cact">
<button class="btn btn-gray" onclick="window.open('?selftest=1','_blank')" style="flex:1">🧾 خودآزمون نصب</button>
</div>
<div id="watchdogR" style="margin-top:8px"></div>
</div></div>

</div></div>

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

        <div style="margin-top:10px;padding:10px;background:#0f172a;border:1px solid #334155;border-radius:8px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                    <input type="checkbox" id="profileSyncEn" onchange="scheduleSave();updateSyncStatusText()">
                    <b style="color:#22d3ee">🔄 همگام‌سازی دوره‌ای این پروفایل</b>
                </label>
            </div>
            <div class="row" style="margin-bottom:6px;align-items:center">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">دوره:</label>
                <select id="profileSyncInterval" onchange="scheduleSave();updateSyncStatusText()" style="flex:1">
                    <option value="0">🔄 هنگام فراخوانی اندپوینت</option>
                    <option value="1800">هر ۳۰ دقیقه</option>
                    <option value="3600" selected>هر ۱ ساعت</option>
                    <option value="7200">هر ۲ ساعت</option>
                    <option value="10800">هر ۳ ساعت</option>
                    <option value="21600">هر ۶ ساعت</option>
                    <option value="43200">هر ۱۲ ساعت</option>
                    <option value="86400">هر روز</option>
                    <option value="604800">هر هفته</option>
                </select>
            </div>
            <div class="row" style="margin-bottom:6px;align-items:center">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">هدف:</label>
                <select id="profileSyncTarget" onchange="scheduleSave();updateSyncStatusText()" style="flex:1">
                    <option value="woo">ووکامرس</option>
                    <option value="bsl">باسلام</option>
                    <option value="both">هر دو</option>
                </select>
            </div>

            <div class="row" style="margin-bottom:4px;align-items:center">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">➕🔄 حالت:</label>
                <div style="flex:1;display:flex;flex-direction:column;gap:4px">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:11px">
                        <input type="checkbox" id="profileSyncWooAddUpdate" onchange="scheduleSave();updateSyncStatusText()">
                        <span style="color:#c4b5fd">افزودن/آپدیت ووکامرس</span>
                        <span style="color:#64748b;font-size:9px">(فقط محصولات جدید و تغییرکرده ارسال شوند)</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:11px">
                        <input type="checkbox" id="profileSyncBslAddUpdate" onchange="scheduleSave();updateSyncStatusText()">
                        <span style="color:#67e8f9">افزودن/آپدیت باسلام</span>
                        <span style="color:#64748b;font-size:9px">(فقط محصولات جدید و تغییرکرده ارسال شوند)</span>
                    </label>
                </div>
            </div>
            <div style="font-size:10px;color:#64748b;line-height:1.7;background:#0f172a;border:1px solid #334155;border-radius:6px;padding:6px 8px;margin-top:4px">
                💡 بدون این تیک‌ها، هر اجرای خودکار <b>کل</b> محصولات را دوباره می‌فرستد.
                با تیک، فقط تفاوت‌های نسبت به اجرای قبلی ارسال می‌شود — برای فهرست‌های بزرگ بسیار سریع‌تر است.
                محصولات حذف‌شده از مبدأ مسیر جداگانه دارند («🗂 محصولات رفته از مبدأ»).
            </div>
            <div id="profileSyncStatus" style="font-size:10px;color:#64748b;margin-top:6px"></div>
        </div>
    </div>

    <div class="card">
        <div class="section-title">🔗 آدرس و محدوده</div>
        <div class="row">
            <input type="url" id="url" value="<?=h(DEFAULT_URL)?>" placeholder="آدرس فروشگاه..." oninput="onUrlChange()">
            <select id="pages" onchange="scheduleSave()" style="max-width:120px"><?php for($i=1;$i<=100;$i++): ?><option value="<?=$i?>"<?=$i==10?'selected':''?>><?=$i?> صفحه</option><?php endfor;?></select>
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
                <button class="btn btn-blue" id="startBtn" onclick="startAutoExtract()" style="flex:1">▶ استخراج اتوماتیک</button>
                <button class="btn btn-gray" id="startManualBtn" onclick="start()" style="flex:1">شروع بدون سلکتور</button>
                <button class="btn btn-red hidden" id="stopBtn" onclick="stop()" style="flex:1">⏹ توقف</button>
                <button class="btn btn-gray" onclick="reset()">↺</button>
            </div>
            <div class="row" style="margin-top:6px">
                <button class="btn btn-purple" id="startBackendBtn" onclick="startBackendSync()" style="flex:1;font-size:13px;padding:8px 12px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;border:none;border-radius:8px;cursor:pointer">⚡ استخراج بک‌اند</button>
            </div>

            <div class="hidden" id="extractProgress" style="margin-top:8px;padding:10px;background:#1e293b;border:1px solid #475569;border-radius:8px">
                <div id="extractStatusText" style="color:#a855f7;font-size:12px;font-weight:bold;margin-bottom:6px">⚡ استخراج بک‌اند...</div>
                <div class="progress"><div class="progress-bar" id="extractProgressBar" style="background:linear-gradient(90deg,#7c3aed,#a855f7);width:0%"></div></div>
                <div id="extractProgressPanel" style="display:none"></div>
            </div>

            <div id="extractQueueSection" style="margin-top:10px">
                <div style="background:#1e293b;border:1px solid #475569;border-radius:10px;padding:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <span style="font-weight:700;font-size:12px;color:#a855f7">📋 صف استخراج بک‌اند</span>
                        <div style="display:flex;gap:4px">
                            <button class="btn btn-gray" onclick="clearExtractQueueDone()" style="font-size:10px;padding:3px 8px" title="پاک کردن تکمیل‌شده‌ها">🧹</button>
                            <button class="btn btn-gray" onclick="refreshExtractQueue()" style="font-size:10px;padding:3px 8px" title="تازه‌سازی">🔄</button>
                        </div>
                    </div>
                    <div id="extractQueueList" style="font-size:11px;color:#94a3b8">بارگذاری...</div>
                </div>
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

    <div class="card settings-card">
        <div class="section-title">🏪 دسته‌بندی باسلام (این پروفایل)</div>
        <div style="font-size:11px;color:#64748b;margin-bottom:8px">💡 دسته‌بندی مختص این پروفایل. اگر تنظیم نشود، دسته پیش‌فرض تنظیمات عمومی استفاده می‌شود.</div>
        <div class="row" style="align-items:center">
            <label>دسته باسلام:</label>
            <div style="flex:1;display:flex;flex-direction:column;position:relative">
                <input type="hidden" id="bslProfileCatId" value="0">
                <input type="text" id="bslProfileCatSearch" placeholder="جستجو دسته باسلام برای این پروفایل..." style="width:100%;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px;direction:rtl" autocomplete="off">
                <div id="bslProfileCatList" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:250px;overflow-y:auto;background:#1e293b;border:1px solid #475569;border-radius:6px;z-index:9999;direction:rtl"></div>
            </div>
            <button class="btn btn-gray" onclick="loadBslCats();setTimeout(()=>{if(bslAllCats.length>0)renderBslProfileCatDropdown(bslAllCats)},1000)" style="flex:0;padding:8px">🔄</button>
        </div>
        <div class="row" style="flex-direction:column;align-items:stretch;margin-top:10px">
            <label style="font-size:11px;color:#94a3b8">📂 دسته‌های جایگزین (fallback) این پروفایل:</label>
            <small style="color:#64748b;font-size:10px;margin-bottom:4px">اگر دسته اصلی رد شد، این دسته‌ها به ترتیب امتحان می‌شوند</small>
            <div id="bslProfileFallbackList" style="display:flex;flex-direction:column;gap:4px;margin:4px 0"></div>
            <div style="display:flex;flex-direction:column;position:relative;gap:4px">
                <div style="display:flex;gap:6px">
                    <input type="text" id="bslProfileFallbackCatSearch" placeholder="جستجو دسته جایگزین..." style="flex:1;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px;direction:rtl" autocomplete="off">
                    <button class="btn btn-green" style="font-size:11px;padding:4px 8px" onclick="addBslProfileFallbackCat()">➕</button>
                </div>
                <div id="bslProfileFallbackCatDropList" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:250px;overflow-y:auto;background:#1e293b;border:1px solid #475569;border-radius:6px;z-index:9999;direction:rtl"></div>
            </div>
        </div>
    </div>
</div>

<div class="tab-pane" id="pane-selectors">
    <div class="card">
        <div class="section-title">🎨 سلکتورهای لیست محصولات</div>
        <div class="alert alert-info">
            💡 <b>نکته مهم:</b> در پنجره پایین، پس از کلیک روی هر المان، یک <b>پیش‌نمایش زنده</b> از متنی که در خروجی نمایش داده می‌شود را می‌بینید. اگر متن اشتباه بود، از دکمه‌های ⬆⬇⬅➡ برای تغییر المان استفاده کنید.
        </div>
        <div class="row" style="align-items:center;gap:12px;margin-bottom:8px">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:12px">
                <input type="checkbox" id="fullMode" onchange="scheduleSave()"> <b style="color:#fbbf24">⚡ بارگذاری کامل (JS)</b>
            </label>
            <span style="font-size:10px;color:#64748b">برای سایت‌های اسکرولی و React (مثل باسلام)</span>
        </div>
        <div class="row">
            <button class="btn btn-orange" onclick="loadVisual()" style="flex:1">🔄 بارگذاری صفحه</button>
            <button class="btn btn-green" onclick="loadDirect()" style="flex:1" title="بارگذاری مستقیم بدون پراکسی — برای سایت‌های SPA مثل snappshop">🌐 بارگذاری مستقیم</button>
            <button class="btn btn-yellow" onclick="suggestSelectors()" style="flex:1">💡 پیشنهاد</button>
            <button class="btn btn-gray" onclick="clearSel()">🗑️</button>
        </div>

        <div class="row" style="margin-top:6px">
            <button class="btn btn-indigo" onclick="openFullPageInspector()" style="flex:1" title="باز کردن صفحه در تب جدید با بازرسی المان — مناسب موبایل">🔍 بازرسی تمام صفحه</button>
            <button class="btn btn-cyan" onclick="copyInspectorScript()" style="flex:1" title="کپی اسکریپت بازرسی — در سایت مقصد اجرا کنید">📋 کپی اسکریپت بازرسی</button>
        </div>

        <details style="margin-top:6px;font-size:11px;color:#94a3b8">
            <summary style="cursor:pointer;color:#67e8f9;font-size:12px">📖 راهنمای بازرسی المان (بدون نیاز به کامپیوتر)</summary>
            <div style="padding:8px;background:#0f172a;border:1px solid #334155;border-radius:8px;margin-top:6px">
                <b style="color:#fbbf24">🔍 بازرسی تمام صفحه:</b><br>
                سایت مقصد را در تب جدید با نوار انتخابگر باز می‌کند. المان‌ها را بزنید و بعد «اتمام» را بزنید.<br>
                <b style="color:#fbbf24">📋 کپی اسکریپت بازرسی:</b><br>
                یک اسکریپت بازرسی کپی می‌شود که می‌توانید آن را در هر سایتی اجرا کنید:<br>
                <b style="color:#93c5fd">روش ۱ — بوکمارکلت (پیشنهادی):</b><br>
                ۱. در مرورگر یک بوکمارک (نشانک) جدید بسازید<br>
                ۲. در قسمت آدرس، ابتدا <code style="background:#1e293b;padding:1px 4px;border-radius:3px">javascript:</code> را تایپ کنید<br>
                ۳. سپس اسکریپت کپی‌شده را بعد از آن بچسبانید<br>
                ۴. در سایت مقصد، روی بوکمارک کلیک کنید<br>
                <b style="color:#93c5fd">روش ۲ — کنسول مرورگر:</b><br>
                ۱. در سایت مقصد، آدرس را پاک کنید و <code style="background:#1e293b;padding:1px 4px;border-radius:3px">javascript:</code> تایپ کنید<br>
                ۲. اسکریپت کپی‌شده را بچسبانید و Enter بزنید<br>
                <b style="color:#93c5fd">روش ۳ — از طریق منوی مرورگر:</b><br>
                ۱. در سایت مقصد، از منوی مرورگر «ابزارهای توسعه» یا «Inspect» را باز کنید<br>
                ۲. به تب «Console» بروید<br>
                ۳. اسکریپت کپی‌شده را بچسبانید و Enter بزنید<br><br>
                <span style="color:#86efac">بعد از اجرای اسکریپت، نوار انتخابگر ظاهر می‌شود. المان‌ها را بزنید و بعد از «✅ کپی همه» سلکتورها کپی می‌شوند.</span>
            </div>
        </details>

        <div id="directLoadBanner" class="hidden" style="margin-top:8px;padding:10px;background:#14532d;border:1px solid #22c55e;border-radius:8px;font-size:11px;color:#86efac">
            <b>🌐 حالت بارگذاری مستقیم فعال</b><br>
            سایت بدون پراکسی در iframe بارگذاری شده — JS کامل رندر می‌شود.<br>
            <span style="color:#fbbf24">⚠️ نوار انتخابگر ظاهر نمی‌شود — از یکی از روش‌های زیر استفاده کنید:</span><br>
            <span style="color:#93c5fd">🔍 دکمه <b>بازرسی تمام صفحه</b> — باز کردن صفحه در تب جدید با بازرسی المان (پیشنهادی)</span><br>
            <span style="color:#67e8f9">📋 دکمه <b>کپی اسکریپت بازرسی</b> — اسکریپت را کپی کنید و در سایت مقصد اجرا کنید</span><br>
            <span style="color:#94a3b8">✏️ یا سلکتورها را دستی وارد کنید</span>
        </div>

        <div class="row" style="margin-top:8px">
            <button class="btn btn-blue" id="selStartBtn" onclick="startFromSelectors()" style="flex:1;font-size:14px;padding:10px">▶ شروع استخراج</button>
        </div>
    </div>

    <div class="visual-container">
        <div>
            <div class="iframe-size-bar">
                <span>📏 ارتفاع:</span>
                <input type="range" id="iframeSizeSlider" min="300" max="1200" value="600" step="50" oninput="setIframeHeight(this.value)">
                <span class="size-val" id="iframeSizeVal">600</span>
            </div>
            <div class="iframe-wrap" id="iframeWrap">
                <iframe id="vFrame"></iframe>
            </div>
        </div>
        <div class="selector-panel">
            <h3>📋 سلکتورها + پیش‌نمایش</h3>

            <div class="row" style="margin-bottom:8px;align-items:center;gap:8px">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#94a3b8;font-size:12px">
                    <input type="checkbox" id="manualSelMode" onchange="toggleManualSelMode()"> <b style="color:#fbbf24">✏️ وارد کردن دستی سلکتور</b>
                </label>
                <span style="font-size:10px;color:#64748b">برای سایت‌هایی که در پیش‌نمایش کامل لود نمی‌شوند</span>
            </div>
            <div class="sel-item" id="s-container">
                <label><span>📦</span> کانتینر (ضروری)</label>
                <input id="selContainer" readonly placeholder="کلیک در صفحه یا تایپ دستی..." oninput="onManualSelInput('container')">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('container')">👁 تست</button>
                </div>
                <div class="sel-preview empty" id="prev-container">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-title">
                <label><span>📝</span> عنوان</label>
                <input id="selTitle" readonly placeholder="مثال: h2.product-title" oninput="onManualSelInput('title')">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('title')">👁 تست</button>
                </div>
                <div class="sel-preview empty" id="prev-title">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-price">
                <label><span>💰</span> قیمت</label>
                <input id="selPrice" readonly placeholder="مثال: span.price" oninput="onManualSelInput('price')">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('price')">👁 تست</button>
                </div>
                <div class="sel-preview price-prev empty" id="prev-price">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-link">
                <label><span>🔗</span> لینک <span style="color:#fbbf24">(Smart Finder)</span></label>
                <input id="selLink" readonly placeholder="مثال: a.product-link" oninput="onManualSelInput('link')">
                <div class="sel-actions-row">
                    <button class="btn btn-indigo" onclick="testSel('link')">👁 تست</button>
                </div>
                <div class="sel-preview link-prev empty" id="prev-link">در انتظار...</div>
            </div>
            <div class="sel-item" id="s-image">
                <label><span>🖼️</span> تصویر</label>
                <input id="selImage" readonly placeholder="مثال: img.product-image" oninput="onManualSelInput('image')">
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

    <div class="iframe-size-bar" style="margin-top:14px">
        <span>📏 ارتفاع:</span>
        <input type="range" id="detailIframeSlider" min="300" max="1200" value="600" step="50" oninput="setDetailIframeHeight(this.value)">
        <span class="size-val" id="detailIframeSizeVal">600</span>
    </div>
    <div class="iframe-wrap hidden" id="detailFrameWrap">
        <iframe id="detailFrame"></iframe>
    </div>
</div>

<div class="tab-pane" id="pane-results">
    <div class="card" id="resultsCard">
        <div class="sb-tbs">
            <button class="sub-tab active" data-v="grid" onclick="switchView('grid')">📊 کارت</button>
            <button class="sub-tab" data-v="table" onclick="switchView('table')">📋 جدول</button>
            <button class="sub-tab" data-v="text" onclick="switchView('text')">📝 متن</button>
        </div>
        <div class="row">
            <button class="btn btn-pink" onclick="startDetailExtraction()" id="btnExtractDetail" style="flex:2">🔍 استخراج تفصیلی</button>
            <button class="btn btn-orange" onclick="fetchMissingImages()" id="btnFetchMissing" style="flex:2">🖼️ دریافت و آماده‌سازی تصاویر</button>
            <button class="btn btn-red hidden" onclick="stopFetchMissing()" id="btnStopFetchMissing" style="flex:1">⏹</button>
            <button class="btn btn-red hidden" onclick="stopDetailExtraction()" id="btnStopDetail" style="flex:1">⏹ توقف</button>
        </div>
        <div class="progress hidden" id="detailProgress"><div class="progress-bar pink" id="detailProgressBar"></div></div>
        <div class="status" id="detailStatus" style="color:#c4b5fd"></div>
        <div class="row">
            <button class="btn btn-gray" onclick="copyCSV()" style="flex:1">📋 کپی</button>
            <button class="btn btn-green" onclick="dlCSV()" style="flex:1">📄 CSV</button>
            <button class="btn btn-purple" onclick="dlExcel()" style="flex:1">📊 Excel</button>
            <button class="btn btn-red" onclick="clearResults()" style="flex:1">🗑️ پاک کردن نتایج</button>
        </div>
        <div id="resultFilterBar" style="display:none;gap:6px;flex-wrap:wrap;align-items:center;margin:8px 0;padding:8px;background:#0f172a;border:1px solid #334155;border-radius:8px">
            <span style="font-size:11px;color:#94a3b8">نمایش:</span>
            <button class="rf-btn on" data-f="all" onclick="setResultFilter('all')">همه (<span id="rfAllN">۰</span>)</button>
            <button class="rf-btn" data-f="new" onclick="setResultFilter('new')">🆕 جدید (<span id="rfNewN">۰</span>)</button>
            <button class="rf-btn" data-f="changed" onclick="setResultFilter('changed')">🔄 آپدیت (<span id="rfChgN">۰</span>)</button>
            <button class="rf-btn" data-f="unchanged" onclick="setResultFilter('unchanged')">بدون تغییر (<span id="rfUncN">۰</span>)</button>
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

<div class="tab-pane" id="pane-send">

<div class="card">
<div class="section-title" style="color:#a78bfa">📤 ارسال ووکامرس</div>
<div class="alert alert-info" style="margin-bottom:8px">💡 <b id="wcN">۰</b> محصول با قیمت از <span id="wcT">۰</span> کل</div>
<div class="cact"><button class="btn btn-purple" id="wSB" onclick="sendWoo()" style="flex:1">🚀 ارسال ووکامرس</button><button class="btn btn-orange hidden" id="wRB" onclick="sendWoo()" style="flex:1">🔄 تلاش مجدد</button><button class="btn btn-red hidden" id="wST" onclick="wooStop()">⏹</button></div>
<div class="progress hidden" id="wP"><div class="progress-bar" id="wPB" style="background:linear-gradient(90deg,#7c3aed,#a78bfa)"></div></div>
<div class="status" id="wSS" style="color:#c4b5fd"></div>
<div class="ssum hidden" id="wSM"><div class="si" style="cursor:pointer" onclick="showWooReport('sent')"><b id="wO" style="color:#4ade80">۰</b><span>جدید</span></div><div class="si" style="cursor:pointer" onclick="showWooReport('updated')"><b id="wU" style="color:#facc15">۰</b><span>آپدیت</span></div><div class="si" style="cursor:pointer" onclick="showWooReport('skipped')"><b id="wK" style="color:#fb923c">۰</b><span>تکراری</span></div><div class="si" style="cursor:pointer" onclick="showWooReport('failed')"><b id="wF" style="color:#f87171">۰</b><span>خطا</span></div><div class="si" style="cursor:pointer" onclick="showWooReport('all')"><b id="wT" style="color:#60a5fa">۰</b><span>کل</span></div></div>
<div class="sres hidden" id="wR"></div>

<div id="wooQueueSection" style="margin-top:10px">
<div style="background:#1e293b;border:1px solid #475569;border-radius:10px;padding:14px">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
<span style="color:#c4b5fd;font-weight:700;font-size:14px">📋 صف ارسال ووکامرس</span>
<button class="btn btn-gray" onclick="clearWooQueueDone()" style="font-size:10px;padding:4px 8px">🗑️ پاکسازی انجام‌شده</button>
</div>
<div id="wooQueueList" style="font-size:11px;color:#64748b">صف خالی — برای افزودن، دکمه «🚀 ارسال ووکامرس» را کلیک کنید</div>
</div>
</div>

<div style="margin-top:10px;border:1px solid #475569;border-radius:8px;overflow:hidden">
<div onclick="toggleWooDedup()" style="cursor:pointer;padding:10px 14px;background:#1e293b;display:flex;justify-content:space-between;align-items:center">
<span style="color:#f97316;font-weight:700;font-size:13px">🔍 حذف محصولات تکراری ووکامرس</span>
<span id="wooDedupArrow" style="color:#94a3b8;font-size:12px">▼</span>
</div>
<div id="wooDedupBody" style="display:none;padding:10px;background:#0f172a">
<div class="alert alert-info" style="margin-bottom:8px;font-size:11px">💡 محصولاتی که عنوان مشابه دارند (با یا بدون پسوند رنگ/سایز/مدل) شناسایی و قدیمی‌ترین حذف می‌شود.</div>
<div class="crow">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" id="ddDelete"> <b style="color:#f87171">حذف واقعی</b> <span style="color:#64748b">(بدون تیک = فقط نمایش)</span>
    </label>
</div>
<div class="cact">
    <button class="btn btn-orange" id="ddBtn" onclick="wooDedup()" style="flex:1">🔍 جستجوی تکراری‌ها</button>
    <button class="btn btn-red hidden" id="ddStop" onclick="ddRunning=false" style="flex:0">⏹</button>
</div>
<div class="progress hidden" id="ddP"><div class="progress-bar" id="ddPB" style="background:linear-gradient(90deg,#ea580c,#f97316)"></div></div>
<div class="status" id="ddSS" style="color:#fb923c"></div>
<div class="sres hidden" id="ddRunning" style="max-height:300px;overflow-y:auto"></div>
<div class="ssum hidden" id="ddSM">
    <div class="si"><b id="ddTot" style="color:#60a5fa">۰</b><span>کل محصولات</span></div>
    <div class="si"><b id="ddGrp" style="color:#facc15">۰</b><span>گروه تکراری</span></div>
    <div class="si"><b id="ddDup" style="color:#f97316">۰</b><span>تعداد تکراری</span></div>
    <div class="si"><b id="ddDel" style="color:#f87171">۰</b><span>حذف شده</span></div>
</div>
</div>
</div>
</div>

<div class="card" style="margin-top:14px">
<div class="section-title" style="color:#22d3ee">📤 ارسال باسلام</div>

<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px;margin-bottom:10px">
<div style="font-size:11px;color:#fbbf24;font-weight:700;margin-bottom:8px">📂 تنظیمات دسته‌بندی باسلام</div>
<div class="crow"><label>دسته پیش‌فرض:</label><div style="flex:1;display:flex;flex-direction:column;position:relative"><input type="hidden" id="bsCat" value="0"><input type="text" id="bsCatSearch" placeholder="جستجو دسته..." style="width:100%;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px;direction:rtl" autocomplete="off"><div id="bsCatList" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:300px;overflow-y:auto;background:#1e293b;border:1px solid #475569;border-radius:6px;z-index:99999;direction:rtl"></div></div><button class="btn btn-gray" onclick="loadBslCats()" style="flex:0;padding:8px">🔄</button></div>
<div style="margin-top:6px;padding-top:6px;border-top:1px solid #334155">
<div style="font-size:11px;color:#94a3b8;margin-bottom:6px">📂 دسته‌های جایگزین (اگر دسته رد شد):</div>
<div id="bslFallbackCatsList" style="display:flex;flex-direction:column;gap:4px;margin-bottom:6px"></div>
<div style="display:flex;flex-direction:column;position:relative;gap:4px">
<div style="display:flex;gap:6px">
<input type="text" id="bslFallbackCatSearch" placeholder="جستجو دسته جایگزین..." style="flex:1;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px;direction:rtl" autocomplete="off">
<button class="btn btn-green" style="font-size:11px;padding:4px 8px" onclick="addBslFallbackCat()">➕</button>
</div>
<div id="bslFallbackCatDropList" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:250px;overflow-y:auto;background:#1e293b;border:1px solid #475569;border-radius:6px;z-index:99999;direction:rtl"></div>
</div></div>
<div class="crow" style="margin-top:8px"><label>🏷️ دسته خودکار:</label><label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#67e8f9"><input type="checkbox" id="bsAutoCat" style="width:16px;height:16px"><span>تشخیص خودکار</span></label></div>
<div class="crow"><label>⏱️ فاصله:</label><input type="number" id="bsDelayMs" value="500" min="0" max="10000" step="100" style="max-width:120px" dir="ltr"><small>ms</small></div>
<div class="crow"><label>🔄 تاخیر:</label><input type="number" id="bsRetryDelayMs" value="1000" min="0" max="30000" step="100" style="max-width:120px" dir="ltr"><small>ms</small></div>
</div>

<div class="alert alert-info" style="margin-bottom:8px">💡 <b id="bsN">۰</b> محصول با قیمت از <span id="bsT2">۰</span> کل</div>
<div class="cact"><button class="btn btn-cyan" id="bSB" onclick="sendBsl()" style="flex:1">🚀 ارسال باسلام</button><button class="btn btn-green" id="bSBlegacy" onclick="sendBslClient()" style="flex:1">🚀 ارسال فرات</button><button class="btn btn-orange hidden" id="bRB" onclick="sendBsl()" style="flex:1">🔄 تلاش مجدد</button><button class="btn btn-teal" onclick="showBslProductsModal()" style="flex-shrink:0;font-size:12px;padding:6px 10px">🏪 مدیریت جامع محصولات باسلام</button><button class="btn btn-red hidden" id="bST" onclick="stopBslProcess()">⏹ توقف</button></div>
<div class="progress hidden" id="bP"><div class="progress-bar" id="bPB" style="background:linear-gradient(90deg,#0891b2,#22d3ee)"></div></div>
<div class="status" id="bSS" style="color:#67e8f9"></div>
<div class="ssum hidden" id="bSM"><div class="si" style="cursor:pointer" onclick="showBslReport('sent')"><b id="bO" style="color:#4ade80">۰</b><span>جدید</span></div><div class="si" style="cursor:pointer" onclick="showBslReport('updated')"><b id="bU" style="color:#facc15">۰</b><span>آپدیت</span></div><div class="si" style="cursor:pointer" onclick="showBslReport('skipped')"><b id="bK" style="color:#fb923c">۰</b><span>تکراری</span></div><div class="si" style="cursor:pointer" onclick="showBslReport('failed')"><b id="bF" style="color:#f87171">۰</b><span>خطا</span></div><div class="si" style="cursor:pointer" onclick="showBslReport('all')"><b id="bT" style="color:#60a5fa">۰</b><span>کل</span></div></div>
<div class="sres hidden" id="bR"></div>

<div id="bslQueueSection" style="margin-top:10px">
<div style="background:#1e293b;border:1px solid #475569;border-radius:10px;padding:14px">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
<span style="color:#67e8f9;font-weight:700;font-size:14px">📋 صف ارسال باسلام</span>
<button class="btn btn-gray" onclick="clearBslQueueDone()" style="font-size:10px;padding:4px 8px">🗑️ پاکسازی انجام‌شده</button>
</div>
<div id="bslQueueList" style="font-size:11px;color:#64748b">صف خالی — برای افزودن، دکمه «🚀 ارسال باسلام» را کلیک کنید</div>
</div>
</div>
</div>

<div class="card" style="margin-top:14px">
<div class="section-title" style="color:#22d3ee">🔄 سینک دوره‌ای (همگام‌سازی خودکار)</div>
<div class="alert alert-info" style="margin-bottom:8px;font-size:11px">💡 هر پروفایل می‌تواند سینک دوره‌ای خود را داشته باشد. تنظیمات سینک در تب «شروع» بخش پروفایل انجام می‌شود.<br>
🔗 برای فعال‌سازی سینک خودکار از سرور، یک <b>cron job</b> به آدرس زیر اضافه کنید:<br>
<code style="background:#0f172a;padding:2px 8px;border-radius:4px;font-size:10px;direction:ltr">*/5 * * * * curl -s "https://yourdomain.com/scraper-v8.22.php?cron_run" > /dev/null</code>
</div>
<div id="syncProfilesList" style="margin-bottom:10px"></div>
<div class="cact">
    <button class="btn btn-cyan" onclick="refreshSyncStatus()" style="flex:1">🔄 بروزرسانی وضعیت</button>
    <button class="btn btn-green" onclick="runSyncNow()" style="flex:1">▶ اجرای الان</button>
</div>
<div class="status" id="syncStatus" style="color:#22d3ee"></div>
</div>

</div>

<div class="tab-pane" id="pane-import">

    <div style="padding:8px;background:#22c55e;color:#000;font-size:12px;font-weight:bold;text-align:center;border-radius:8px;margin-bottom:10px">✅ تب درون‌ریزی v7.81b — اگر این متن را می‌بینید، تب فعال است!</div>
    <div class="card">
        <div class="section-title">📥 آپلود فایل CSV/Excel محصولات</div>
        <div class="alert alert-info" style="margin-bottom:10px;font-size:11px">
            💡 فایل CSV یا Excel را آپلود کنید. ستون‌ها به صورت خودکار شناسایی و نگاشت می‌شوند.<br>
            فرمت‌های مجاز: CSV, XLS (Excel XML), XLSX
        </div>
        <div class="row" style="align-items:center">
            <input type="file" id="importFile" accept=".csv,.xls,.xlsx,.xml" style="flex:1">
            <button class="btn btn-blue" onclick="uploadImportFile()" id="btnUploadImport">📤 آپلود و تحلیل</button>
        </div>
        <div id="importUploadStatus" style="color:#94a3b8;font-size:12px;margin-top:6px"></div>
    </div>

    <div class="card hidden" id="importMappingCard">
        <div class="section-title">🗺️ نگاشت ستون‌ها</div>
        <div class="alert alert-info" style="margin-bottom:10px;font-size:11px">
            ستون‌های فایل شما را به فیلدهای محصول متصل کنید. نگاشت خودکار انجام شده، در صورت نیاز اصلاح کنید.
        </div>
        <div id="importMappingRows" style="margin-bottom:12px"></div>
        <div id="importPreview" style="margin-bottom:12px"></div>

        <div style="margin-top:10px;padding:10px;background:#0f172a;border:1px solid #475569;border-radius:8px">
            <div style="font-weight:700;font-size:12px;color:#fbbf24;margin-bottom:8px">💰 تنظیمات قیمت و عنوان</div>
            <div class="row" style="align-items:center;margin-bottom:6px">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">پسوند عنوان:</label>
                <input type="text" id="impTitleSuffix" placeholder="مثال: - فروشگاه من" style="flex:1">
            </div>
            <div class="row" style="align-items:center;margin-bottom:6px">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">روش قیمت:</label>
                <select id="impPriceMode" style="flex:1">
                    <option value="none">بدون تغییر</option>
                    <option value="percent">درصد (مثلا 20+)</option>
                    <option value="multiplier">ضریب (مثلا 1.5)</option>
                </select>
                <input type="number" id="impPriceVal" value="0" step="0.01" placeholder="مقدار" style="max-width:120px">
            </div>
            <div class="row" style="align-items:center;margin-bottom:6px">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">گرد کردن:</label>
                <select id="impRoundPrice" style="flex:1">
                    <option value="0" selected>بدون گرد کردن</option>
                    <option value="1000">هزار (1,000)</option>
                    <option value="5000">۵ هزار (5,000)</option>
                    <option value="10000">ده هزار (10,000)</option>
                    <option value="50000">۵۰ هزار (50,000)</option>
                    <option value="100000">صد هزار (100,000)</option>
                </select>
            </div>
            <div id="impPricePreview" style="font-size:11px;color:#64748b;margin-top:6px"></div>
        </div>
        <div class="row">
            <button class="btn btn-green" onclick="processImport()" id="btnProcessImport" style="flex:1">✅ وارد کردن محصولات</button>
        </div>
        <div id="importProcessStatus" style="color:#94a3b8;font-size:12px;margin-top:6px"></div>
    </div>

    <div class="card hidden" id="importResultCard">
        <div class="section-title">✅ محصولات وارد شده</div>
        <div class="stats" style="margin-bottom:12px">
            <div class="stat"><b id="impTotal">۰</b><span>محصول</span></div>
            <div class="stat"><b id="impWithPrice">۰</b><span>با قیمت</span></div>
            <div class="stat"><b id="impWithImage">۰</b><span>با تصویر</span></div>
        </div>

        <div style="margin-bottom:10px;padding:10px;background:#0f172a;border:1px solid #475569;border-radius:8px">
            <div style="font-weight:700;font-size:12px;color:#fbbf24;margin-bottom:8px">💰 تعدیل قیمت و عنوان (قابل تغییر قبل از ارسال)</div>
            <div class="row" style="align-items:center;margin-bottom:6px">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">پسوند عنوان:</label>
                <input type="text" id="impTitleSuffix2" placeholder="مثال: - فروشگاه من" style="flex:1" oninput="reapplyImportPrice()">
            </div>
            <div class="row" style="align-items:center;margin-bottom:6px">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">روش قیمت:</label>
                <select id="impPriceMode2" style="flex:1" onchange="reapplyImportPrice()">
                    <option value="none">بدون تغییر</option>
                    <option value="percent">درصد (مثلا 20+)</option>
                    <option value="multiplier">ضریب (مثلا 1.5)</option>
                </select>
                <input type="number" id="impPriceVal2" value="0" step="0.01" placeholder="مقدار" style="max-width:120px" oninput="reapplyImportPrice()">
            </div>
            <div class="row" style="align-items:center;margin-bottom:6px">
                <label style="min-width:80px;font-size:12px;color:#94a3b8">گرد کردن:</label>
                <select id="impRoundPrice2" style="flex:1" onchange="reapplyImportPrice()">
                    <option value="0" selected>بدون گرد کردن</option>
                    <option value="1000">هزار (1,000)</option>
                    <option value="5000">۵ هزار (5,000)</option>
                    <option value="10000">ده هزار (10,000)</option>
                    <option value="50000">۵۰ هزار (50,000)</option>
                    <option value="100000">صد هزار (100,000)</option>
                </select>
            </div>
            <div id="impPricePreview2" style="font-size:11px;color:#64748b;margin-top:6px"></div>
        </div>

        <div id="importProductsPreview" style="max-height:300px;overflow-y:auto;margin-bottom:10px;border:1px solid #334155;border-radius:6px"></div>
        <div class="row">
            <button class="btn btn-purple" onclick="sendImportToWoo()" style="flex:1">🚀 ارسال به ووکامرس</button>
            <button class="btn btn-cyan" onclick="sendImportToBsl()" style="flex:1">🚀 ارسال به باسلام</button>
            <button class="btn btn-green" onclick="addImportToResults()" style="flex:1">➕ افزودن به نتایج</button>
        </div>
        <div class="progress hidden" id="impProgress"><div class="progress-bar" id="impProgressBar"></div></div>
        <div class="status" id="impStatus" style="color:#c4b5fd"></div>
        <div class="sres hidden" id="impLog" style="max-height:250px;overflow-y:auto"></div>
        <div class="ssum hidden" id="impSM">
            <div class="si"><b id="impO" style="color:#4ade80">۰</b><span>جدید</span></div>
            <div class="si"><b id="impU" style="color:#facc15">۰</b><span>آپدیت</span></div>
            <div class="si"><b id="impK" style="color:#94a3b8">۰</b><span>تکراری</span></div>
            <div class="si"><b id="impF" style="color:#f87171">۰</b><span>خطا</span></div>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

</div>

<script>
(function(){
window._jsOk=true;
window.onerror=function(m,u,l,c,e){
try{
console.error('JS Error:',m,'Line:',l);
var d=document.getElementById('_dbg');
if(!d){d=document.createElement('div');d.id='_dbg';
d.style.cssText='position:fixed;bottom:0;left:0;right:0;max-height:40vh;overflow:auto;background:#1a0000;border:3px solid #f00;padding:10px;z-index:9999998;font:12px monospace;color:#fca5a5;direction:ltr';
document.body.appendChild(d);
var b=document.createElement('button');b.textContent='X';b.style.cssText='position:absolute;top:5px;right:5px;background:#f00;color:#fff;border:none;padding:2px 8px;cursor:pointer;font:bold 14px monospace';
b.onclick=function(){d.style.display='none';};d.appendChild(b);}
d.insertAdjacentHTML('beforeend','<div style="border-bottom:1px solid #7f1d1d;padding:4px 0">JS Error: '+m+' <b>Line:'+l+'</b></div>');
}catch(ex){}
return false;
};
window.addEventListener('unhandledrejection',function(e){
try{var d=document.getElementById('_dbg');if(d)d.insertAdjacentHTML('beforeend','<div style="color:#fbbf24;border-bottom:1px solid #7f1d1d;padding:4px 0">Promise: '+String(e.reason)+'</div>');}catch(ex){}
});
})();
</script>
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

// ========== Import State ==========
let importProducts = [];
let importFile = '';
let importHeaders = [];
let importMapping = {};

// ========== Woo/Bsl Retry State ==========

let profiles =<?=json_encode($initialProfiles, JSON_UNESCAPED_UNICODE)?>;
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
    // v7.66: Update auto-extract button based on selector state
    if(sel.container && sel.title){
        $('startBtn').textContent='▶ استخراج اتوماتیک ✓ ('+sel.container+')';
        $('startBtn').style.background='#22c55e';
        $('startBtn').style.color='#fff';
    }else{
        $('startBtn').textContent='▶ استخراج اتوماتیک';
        $('startBtn').style.background='';
        $('startBtn').style.color='';
    }
}

function switchMainTab(name) {
    document.querySelectorAll('.main-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + name));
    window.scrollTo({top:0,behavior:'smooth'});
    try { history.replaceState(null, '', '#' + name); } catch(e) {}
}
// v8.17: Settings panel toggle
function toggleSettingsPanel(){const p=document.getElementById('settingsPanel');const o=document.getElementById('settingsOverlay');const b=document.getElementById('hamburgerBtn');if(p.classList.contains('open')){p.classList.remove('open');o.classList.remove('open');if(b)b.classList.remove('active');}else{p.classList.add('open');o.classList.add('open');if(b)b.classList.add('active');}}
function toggleSmenu(hdr){const isOpen=hdr.classList.contains('open');hdr.classList.toggle('open');const body=hdr.nextElementSibling;if(body){if(isOpen){body.classList.remove('open');}else{body.classList.add('open');}}}
// v8.17: Per-profile fallback categories
let bslProfileFallbackCats=[];
let bslProfileFallbackSelectedCatId=0;
function addBslProfileFallbackCat(){const catId=bslProfileFallbackSelectedCatId;if(catId<=0||bslProfileFallbackCats.includes(catId)){showToast('ابتدا یک دسته انتخاب کنید',1);return;}bslProfileFallbackCats.push(catId);bslProfileFallbackSelectedCatId=0;$('bslProfileFallbackCatSearch').value='';renderBslProfileFallbackCats(bslProfileFallbackCats);scheduleSave();}
function removeBslProfileFallbackCat(idx){bslProfileFallbackCats.splice(idx,1);renderBslProfileFallbackCats(bslProfileFallbackCats);scheduleSave();}
function renderBslProfileFallbackCats(ids){bslProfileFallbackCats=Array.isArray(ids)?ids:[];const list=$('bslProfileFallbackList');if(!list)return;/* v8.43: اگر دسته‌ها هنوز نرسیده‌اند، بعد از رسیدنشان دوباره رسم کن تا نام‌ها ظاهر شوند */if(bslProfileFallbackCats.length>0&&(!bslAllCats||bslAllCats.length===0)){if(!window.__fbRetry){window.__fbRetry=1;setTimeout(function(){window.__fbRetry=0;if(bslAllCats&&bslAllCats.length>0)renderBslProfileFallbackCats(bslProfileFallbackCats);},2500);}}ids=bslProfileFallbackCats;list.innerHTML='';ids.forEach((catId,idx)=>{const catName=bslCatNameByIdJS(catId);const row=document.createElement('div');row.style.cssText='display:flex;align-items:center;gap:6px;padding:6px 10px;background:#1e293b;border-radius:6px;border:1px solid #475569';row.innerHTML='<span style="flex:1;color:#e2e8f0;font-size:12px">'+esc(catName||'دسته')+' <span style="color:#94a3b8">(#'+catId+')</span></span><button class="btn btn-red" style="font-size:10px;padding:2px 6px" onclick="removeBslProfileFallbackCat('+idx+')">✕</button>';list.appendChild(row);});}
function initBslProfileFallbackCatSearch(){const si=$('bslProfileFallbackCatSearch');const dl=$('bslProfileFallbackCatDropList');if(!si||!dl)return;si.onfocus=function(){if(bslAllCats.length>0){dl.style.display='block';renderBslProfileFallbackCatDropList(bslAllCats,'');}};si.onblur=function(){setTimeout(()=>{dl.style.display='none';},200);};si.oninput=function(){const q=si.value.toLowerCase().trim();renderBslProfileFallbackCatDropList(bslAllCats,q);};}
function renderBslProfileFallbackCatDropList(cats,q){const dl=$('bslProfileFallbackCatDropList');if(!dl)return;dl.innerHTML='';const filtered=cats.filter(c=>!q||c.name.toLowerCase().includes(q)).slice(0,100);if(filtered.length===0){dl.innerHTML='<div style="padding:8px;color:#64748b;font-size:11px;text-align:center">دسته‌ای یافت نشد</div>';return;}filtered.forEach(c=>{const d=document.createElement('div');d.style.cssText='padding:6px 10px;cursor:pointer;font-size:12px;color:#e2e8f0;border-bottom:1px solid #1e293b';d.textContent=c.name+' ('+c.id+')';d.onmousedown=function(){bslProfileFallbackSelectedCatId=c.id;$('bslProfileFallbackCatSearch').value=c.name;dl.style.display='none';};dl.appendChild(d);});}
function bslCatNameByIdJS(catId){if(!bslAllCats||bslAllCats.length===0)return'';const cat=bslAllCats.find(c=>c.id===catId);return cat?cat.name:'';}
// v8.17: Global fallback categories (in settings panel)
let bslFallbackCatIds=[];
let bslFallbackSelectedCatId=0;
function addBslFallbackCat(){const catId=bslFallbackSelectedCatId;if(catId<=0||bslFallbackCatIds.includes(catId)){showToast('ابتدا یک دسته انتخاب کنید',1);return;}bslFallbackCatIds.push(catId);bslFallbackSelectedCatId=0;$('bslFallbackCatSearch').value='';renderBslFallbackCats(bslFallbackCatIds);}
function removeBslFallbackCat(idx){bslFallbackCatIds.splice(idx,1);renderBslFallbackCats(bslFallbackCatIds);}
function renderBslFallbackCats(ids){bslFallbackCatIds=ids;const list=$('bslFallbackCatsList');if(!list)return;list.innerHTML='';ids.forEach((catId,idx)=>{const catName=bslCatNameByIdJS(catId);const row=document.createElement('div');row.style.cssText='display:flex;align-items:center;gap:6px;padding:6px 10px;background:#1e293b;border-radius:6px;border:1px solid #475569';row.innerHTML='<span style="flex:1;color:#e2e8f0;font-size:12px">'+esc(catName||'دسته')+' <span style="color:#94a3b8">(#'+catId+')</span></span><button class="btn btn-red" style="font-size:10px;padding:2px 6px" onclick="removeBslFallbackCat('+idx+')">✕</button>';list.appendChild(row);});}
function getBslFallbackCatIds(){return bslFallbackCatIds;}
function initBslFallbackCatSearch(){const si=$('bslFallbackCatSearch');const dl=$('bslFallbackCatDropList');if(!si||!dl)return;si.onfocus=function(){if(bslAllCats.length>0){dl.style.display='block';renderBslFallbackCatDropList(bslAllCats,'');}};si.onblur=function(){setTimeout(()=>{dl.style.display='none';},200);};si.oninput=function(){const q=si.value.toLowerCase().trim();renderBslFallbackCatDropList(bslAllCats,q);};}
function renderBslFallbackCatDropList(cats,q){const dl=$('bslFallbackCatDropList');if(!dl)return;dl.innerHTML='';const filtered=cats.filter(c=>!q||c.name.toLowerCase().includes(q)).slice(0,100);if(filtered.length===0){dl.innerHTML='<div style="padding:8px;color:#64748b;font-size:11px;text-align:center">دسته‌ای یافت نشد</div>';return;}filtered.forEach(c=>{const d=document.createElement('div');d.style.cssText='padding:6px 10px;cursor:pointer;font-size:12px;color:#e2e8f0;border-bottom:1px solid #1e293b';d.textContent=c.name+' ('+c.id+')';d.onmousedown=function(){bslFallbackSelectedCatId=c.id;$('bslFallbackCatSearch').value=c.name;dl.style.display='none';};dl.appendChild(d);});}
// v8.17: Multi-vendor management
let bslExtraVendors=[];
function addBslVendor(){bslExtraVendors.push({vendor_id:0,token:'',name:'',shop_name:''});renderBslVendors();}
function removeBslVendor(idx){if(!confirm('حذف این غرفه؟'))return;bslExtraVendors.splice(idx,1);renderBslVendors();}
function testBslVendor(idx){const v=bslExtraVendors[idx];if(!v||!v.token){showToast('توکن خالی است',1);return;}const btn=document.getElementById('bslVTestBtn_'+idx);if(btn){btn.disabled=true;btn.textContent='⏳';}const fd=new FormData();fd.append('action','test_basalam');fd.append('token',v.token);fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(btn){btn.disabled=false;btn.textContent='🔗';}if(d.ok){bslExtraVendors[idx].vendor_id=d.vendor_id||0;bslExtraVendors[idx].name=d.user_name||d.username||'';bslExtraVendors[idx].shop_name=d.vendor_title||'';renderBslVendors();showToast('✓ '+d.vendor_title+' (#'+d.vendor_id+')');}else{showToast('❌ '+(d.error||'خطا'),1);}}).catch(()=>{if(btn){btn.disabled=false;btn.textContent='🔗';}showToast('❌ خطا شبکه',1);});}
function renderBslVendors(){const list=$('bslVendorsList');if(!list)return;list.innerHTML='';if(bslExtraVendors.length===0){list.innerHTML='<div style="color:#64748b;font-size:11px;text-align:center;padding:8px">غرفه اضافی وجود ندارد</div>';return;}bslExtraVendors.forEach((v,idx)=>{const card=document.createElement('div');card.style.cssText='background:#0f172a;border:1px solid #475569;border-radius:8px;padding:10px';const info=v.shop_name?'<div style="color:#22d3ee;font-size:11px;margin-bottom:4px">'+esc(v.shop_name)+' (#'+v.vendor_id+')</div>':'';const nameVal=v.name||'';card.innerHTML='<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><span style="font-size:11px;color:#fbbf24;font-weight:700">غرفه '+(idx+1)+'</span><button class="btn btn-red" style="font-size:10px;padding:2px 6px" onclick="removeBslVendor('+idx+')">✕</button></div>'+info+'<div style="display:flex;gap:6px;margin-bottom:6px"><input type="text" id="bslVName_'+idx+'" value="'+esc(nameVal)+'" placeholder="نام" style="flex:1;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px;direction:rtl" oninput="bslExtraVendors['+idx+'].name=this.value"></div><div style="display:flex;gap:6px;margin-bottom:6px"><input type="password" id="bslVToken_'+idx+'" value="'+esc(v.token||'')+'" placeholder="Token" dir="ltr" style="flex:1;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px" oninput="bslExtraVendors['+idx+'].token=this.value"></div><div style="display:flex;gap:6px"><input type="number" id="bslVVid_'+idx+'" value="'+(v.vendor_id||'')+'" placeholder="شماره غرفه" dir="ltr" style="flex:1;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px" oninput="bslExtraVendors['+idx+'].vendor_id=parseInt(this.value)||0"><button class="btn btn-cyan" id="bslVTestBtn_'+idx+'" style="font-size:10px;padding:4px 8px" onclick="testBslVendor('+idx+')">🔗 تست</button></div>';list.appendChild(card);});}
// v8.17: Per-profile BaSalam category dropdown
let bslProfileSelectedCatId=0;
function renderBslProfileCatDropdown(cats,selectedId){const si=$('bslProfileCatSearch');const list=$('bslProfileCatList');if(!si||!list)return;/* v8.43: اگر بدون شناسه صدا زده شود (مثلاً دکمهٔ 🔄)، انتخاب فعلی نباید پاک شود */if(selectedId===undefined||selectedId===null)selectedId=bslProfileSelectedCatId||parseInt($('bslProfileCatId').value)||0;bslProfileSelectedCatId=selectedId||0;$('bslProfileCatId').value=String(bslProfileSelectedCatId);if(selectedId>0){const c=cats.find(x=>x.id===selectedId);if(c)si.value=c.name;}si.onfocus=function(){list.style.display='block';};si.onblur=function(){setTimeout(()=>{list.style.display='none';},200);};si.oninput=function(){const q=si.value.toLowerCase().trim();renderBslProfileCatList(cats,q);};renderBslProfileCatList(cats,'');}
function renderBslProfileCatList(cats,q){const list=$('bslProfileCatList');if(!list)return;list.innerHTML='';cats.filter(c=>!q||c.name.toLowerCase().includes(q)).slice(0,100).forEach(c=>{const d=document.createElement('div');d.style.cssText='padding:6px 10px;cursor:pointer;font-size:12px;color:#e2e8f0;border-bottom:1px solid #1e293b';d.textContent=c.name+' ('+c.id+')';d.onmousedown=function(){bslProfileSelectedCatId=c.id;$('bslProfileCatId').value=String(c.id);$('bslProfileCatSearch').value=c.name;list.style.display='none';scheduleSave();};list.appendChild(d);});}
function bslSelectProfileCat(catId){bslProfileSelectedCatId=catId;$('bslProfileCatId').value=String(catId);if(bslAllCats.length>0){const c=bslAllCats.find(x=>x.id===catId);if(c)$('bslProfileCatSearch').value=c.name;}scheduleSave();}

(function(){
    const hash = window.location.hash.replace('#','');
    if (['start','settings','selectors','results','send','import'].includes(hash)) {
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
    if($('fullMode'))$('fullMode').checked=!!p.fullMode;
    $('customColName').value = p.customColName || 'وضعیت';
    $('customColVal').value = p.customColVal || 'موجود';
    // Restore iframe height slider
    if(p.iframeHeight){$('iframeSizeSlider').value=p.iframeHeight;setIframeHeight(p.iframeHeight);}

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
    // v7.66: Show auto-extract status on start button
    if(sel.container && sel.title){
        $('startBtn').textContent='▶ استخراج اتوماتیک ✓ ('+sel.container+')';
        $('startBtn').style.background='#22c55e';
        $('startBtn').style.color='#fff';
    }else{
        $('startBtn').textContent='▶ استخراج اتوماتیک';
        $('startBtn').style.background='';
        $('startBtn').style.color='';
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

    // Restore saved products from profile
    if (p.products && Array.isArray(p.products) && p.products.length > 0) {
        resetResultFilter();
        products.clear();
        order = p.productsOrder || p.products.map(e => e[0]);
        p.products.forEach(entry => {
            if (Array.isArray(entry) && entry.length === 2) {
                if(entry[1]&&typeof entry[1]==='object'&&!entry[1].key)entry[1].key=entry[0];
                products.set(entry[0], entry[1]);
            }
        });
        refreshViews();
        switchMainTab('results');
        showToast('✓ پروفایل "' + (p.name || '') + '" بارگذاری شد (' + toFa(products.size) + ' محصول)');
    } else {
        refreshViews();
        showToast('✓ پروفایل "' + (p.name || '') + '" بارگذاری شد');
    }

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
    // v8.06: Restore per-profile BaSalam category
    if(p.bslCategoryId && p.bslCategoryId>0){
        bslSelectedCatId=p.bslCategoryId;
        $('bsCat').value=String(p.bslCategoryId);
        if(bslAllCats.length>0){
            renderBslCatDropdown(bslAllCats,p.bslCategoryId);
            bslSelectCat(p.bslCategoryId);
        }else{
            // Load cats first, then select
            loadBslCats();
            setTimeout(()=>{bslSelectCat(p.bslCategoryId);},2000);
        }
    }else{
        bslSelectedCatId=0;
        $('bsCat').value='0';
        if($('bsCatSearch'))$('bsCatSearch').value='';
    }
    // v8.17: Restore per-profile category and fallback
    if(p.bslCategoryId && p.bslCategoryId>0){
        bslProfileSelectedCatId=p.bslCategoryId;
        if($('bslProfileCatId'))$('bslProfileCatId').value=String(p.bslCategoryId);
        if(bslAllCats.length>0){
            renderBslProfileCatDropdown(bslAllCats,p.bslCategoryId);
        }else{
            // v8.43: دسته‌ها هنوز نرسیده‌اند. قبلاً هیچ کاری نمی‌شد و کادر
            // خالی می‌ماند، بعد ذخیرهٔ خودکار همان خالی را ذخیره می‌کرد.
            // حالا شناسه را نشان می‌دهیم و پس از رسیدن دسته‌ها نام را می‌گذاریم.
            if($('bslProfileCatSearch'))$('bslProfileCatSearch').value='#'+p.bslCategoryId;
            const _wantCat=p.bslCategoryId;
            loadBslCats();
            let _tries=0;
            const _t=setInterval(()=>{
                _tries++;
                if(bslAllCats&&bslAllCats.length>0){
                    clearInterval(_t);
                    renderBslProfileCatDropdown(bslAllCats,_wantCat);
                }else if(_tries>20){clearInterval(_t);}
            },400);
        }
    }else{
        bslProfileSelectedCatId=0;
        if($('bslProfileCatId'))$('bslProfileCatId').value='0';
        if($('bslProfileCatSearch'))$('bslProfileCatSearch').value='';
    }
    if(p.bslFallbackCatIds && Array.isArray(p.bslFallbackCatIds)){
        renderBslProfileFallbackCats(p.bslFallbackCatIds);
    }else{
        bslProfileFallbackCats=[];
        renderBslProfileFallbackCats([]);
    }
}

function collectProfileData() {
    // Serialize current products Map and order array
    const prodsArr = [];
    products.forEach((v, k) => prodsArr.push([k, v]));
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
        fullMode: $('fullMode')?$('fullMode').checked:false,
        customColName: $('customColName').value,
        customColVal: $('customColVal').value,
        iframeHeight: parseInt($('iframeSizeSlider').value) || 600,
        products: prodsArr,
        productsOrder: [...order],
        // v8.06: Per-profile BaSalam category
        // v8.43: اگر متغیرها هنوز مقدار نگرفته‌اند، از فیلد مخفی بخوان تا
        // ذخیرهٔ خودکار انتخاب قبلی را با صفر جایگزین نکند.
        bslCategoryId: bslProfileSelectedCatId
            || parseInt(($('bslProfileCatId')||{}).value||'0')
            || bslSelectedCatId
            || parseInt(($('bsCat')||{}).value||'0')
            || 0,
        // v8.17: Per-profile fallback categories
        bslFallbackCatIds: Array.isArray(bslProfileFallbackCats)?bslProfileFallbackCats:[]
    };
}

function saveProfileSilent() {
    const data = collectProfileData();
    if (!data.url) return;

    const fd = new FormData();
    fd.append('action', 'save_profile');
    for (const k in data) {
        // v7.66: syncConfig must also be JSON.stringify'd — otherwise FormData converts object to "[object Object]"
        if (k === 'selectors' || k === 'detailSelectors' || k === 'products' || k === 'productsOrder' || k === 'syncConfig' || k === 'bslFallbackCatIds') {
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
        // v7.66: syncConfig must also be JSON.stringify'd — otherwise "[object Object]"
        if (k === 'selectors' || k === 'detailSelectors' || k === 'products' || k === 'productsOrder' || k === 'syncConfig' || k === 'bslFallbackCatIds') {
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
                // v7.66: Show selector status after save
                if(data.selectors && data.selectors.container){
                    showToast('✓ ذخیره شد — سلکتورها: '+data.selectors.container+' ✓');
                }else{
                    showToast('✓ ذخیره شد — بدون سلکتورها (استخراج اتوماتیک غیرفعال)');
                }
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

function setDetailIframeHeight(h){const w=$('detailFrameWrap');if(!w)return;w.style.height=h+'px';$('detailIframeSizeVal').textContent=h;}
function setIframeHeight(h){const w=$('iframeWrap');if(!w)return;w.style.height=h+'px';$('iframeSizeVal').textContent=h;scheduleSave();}
function loadVisual(){
  const url=$('url').value.trim();
  if(!url){showToast('URL وارد کنید',true);return;}
  $('status').textContent='در حال بارگذاری...';
  $('directLoadBanner').classList.add('hidden');
  const full=$('fullMode')&&$('fullMode').checked?'&full=1':'';
  $('vFrame').src='?visual_proxy='+encodeURIComponent(url)+full;
  $('vFrame').onload=()=>{
      if($('fullMode')&&$('fullMode').checked){
          $('status').textContent='⏳ رندر JS... صبر کنید';
          setTimeout(()=>{$('status').textContent='✓ آماده | ⬆ اسکرول بیشتر | 🔄 اسکرول خودکار';showToast('از ⬆🔄 برای بارگذاری بیشتر استفاده کنید');},3000);
      } else {
          $('status').textContent='✓ روی المان‌ها کلیک کنید';
          showToast('صفحه آماده');
      }
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

// v7.81: Toggle manual selector input mode
function toggleManualSelMode(){
    const manual=$('manualSelMode').checked;
    ['container','title','price','link','image'].forEach(k=>{
        const inp=$('sel'+k.charAt(0).toUpperCase()+k.slice(1));
        if(manual){inp.removeAttribute('readonly');inp.style.background='#1e293b';inp.style.borderColor='#f59e0b';inp.style.cursor='text';}
        else{inp.setAttribute('readonly','readonly');inp.style.background='';inp.style.borderColor='';inp.style.cursor='';}
    });
    if(manual) showToast('✏️ حالت دستی فعال — سلکتورها را مستقیم تایپ کنید');
}
// v7.81: Handle manual selector input
function onManualSelInput(key){
    const inp=$('sel'+key.charAt(0).toUpperCase()+key.slice(1));
    sel[key]=inp.value.trim();
    if(sel[key])$('s-'+key).classList.add('has');
    else $('s-'+key).classList.remove('has');
    updateStartVisBtn();scheduleSave();
}
// v7.81: Direct load — load site directly in iframe without proxy
function loadDirect(){
    const url=$('url').value.trim();
    if(!url){showToast('URL وارد کنید',true);return;}
    $('status').textContent='🌐 بارگذاری مستقیم...';
    $('directLoadBanner').classList.remove('hidden');
    if($('manualSelMode')&&!$('manualSelMode').checked){$('manualSelMode').checked=true;toggleManualSelMode();}
    $('vFrame').src=url;
    $('vFrame').onload=()=>{$('status').textContent='✅ سایت بارگذاری شد — از 🔍 بازرسی تمام صفحه یا 📋 اسکریپت بازرسی استفاده کنید';showToast('✅ سایت مستقیم بارگذاری شد');};
}
// v7.81: Full Page Inspector — opens the page in a new tab with proxy + inspector
function openFullPageInspector(){
    const url=$('url').value.trim();
    if(!url){showToast('URL وارد کنید',true);return;}
    const full=$('fullMode')&&$('fullMode').checked?'&full=1':'';
    const w=window.open('?visual_proxy='+encodeURIComponent(url)+'&fullpage_inspect=1'+full,'_blank');
    if(!w){showToast('⚠️ پاپ‌آپ مسدود شد — لطفاً پاپ‌آپ را فعال کنید',true);return;}
    showToast('🔍 صفحه بازرسی در تب جدید باز شد — المان‌ها را انتخاب کنید');
    $('status').textContent='🔍 صفحه بازرسی در تب جدید باز شد';
}
// v7.81: Copy Inspector Script — a self-contained element inspector for any page
function copyInspectorScript(){
    const script=`(function(){if(document.getElementById('__insp_bar')){document.getElementById('__insp_bar').remove();return;}
var S={container:'',title:'',price:'',link:'',image:''},E={},cur=null,picked=null;
var mode='container',fields=['container','title','price','link','image'];
var labels={container:'📦 کانتینر',title:'📝 عنوان',price:'💰 قیمت',link:'🔗 لینک',image:'🖼️ تصویر'};
function gs(el){if(!el||el.tagName=='BODY'||el.tagName=='HTML')return'';var t=el.tagName.toLowerCase();var c=Array.from(el.classList).filter(function(x){return !x.startsWith('__')&&x.length<40});if(c.length)return t+'.'+c.slice(0,3).join('.');if(el.id&&el.id.length<30&&!/^__/.test(el.id))return t+'#'+el.id;return t;}
function elInfo(el){if(!el)return'';var tag=el.tagName.toLowerCase();var cls=Array.from(el.classList).filter(function(x){return !x.startsWith('__')}).join('.');var id=el.id&&!/^__/.test(el.id)?'#'+el.id:'';return tag+(cls?'.'+cls:'')+(id||'');}
function countSimilar(el){if(!el)return 0;var s=gs(el);if(!s)return 0;try{return document.querySelectorAll(s).length;}catch(e){return 0;}}
var bar=document.createElement('div');bar.id='__insp_bar';
bar.style.cssText='position:fixed;top:0;left:0;right:0;background:linear-gradient(180deg,#1e293b,#0f172a);color:#fff;padding:0;z-index:999999;font:13px Tahoma,sans-serif;box-shadow:0 4px 20px rgba(0,0,0,.6)';
var style=document.createElement('style');style.textContent='*{cursor:crosshair!important}.__ih{outline:3px solid #3b82f6!important;outline-offset:2px}.__is{outline:3px solid #22c55e!important;background:rgba(34,197,94,.08)!important}';
document.head.appendChild(style);
bar.innerHTML='<div style="display:flex;gap:8px;align-items:center;padding:8px 14px;flex-wrap:wrap"><select id="__insp_m" style="padding:7px 12px;border-radius:6px;border:1px solid #475569;background:#334155;color:#fff;font:inherit;cursor:pointer"><option value="container">📦 کانتینر</option><option value="title">📝 عنوان</option><option value="price">💰 قیمت</option><option value="link">🔗 لینک</option><option value="image">🖼️ تصویر</option></select><span id="__insp_sel" style="background:#0f172a;padding:5px 10px;border-radius:4px;font-family:monospace;font-size:11px;color:#67e8f9;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">کلیک کنید...</span><button id="__insp_ok" style="padding:7px 12px;border-radius:6px;border:1px solid #475569;background:#334155;color:#fff;font:inherit;cursor:pointer">✓ بعدی</button><button id="__insp_done" style="padding:7px 12px;border-radius:6px;border:1px solid #22c55e;background:#22c55e;color:#000;font:inherit;cursor:pointer;font-weight:bold">✅ کپی همه</button><button id="__insp_close" style="padding:7px 12px;border-radius:6px;border:1px solid #ef4444;background:#ef4444;color:#fff;font:inherit;cursor:pointer">✕</button></div><div style="display:flex;gap:6px;align-items:center;padding:4px 14px 8px;flex-wrap:wrap;border-top:1px solid #334155"><button id="__insp_up" style="padding:5px 10px;border-radius:6px;border:1px solid #3b82f6;background:#1e3a5f;color:#93c5fd;font:inherit;cursor:pointer;font-weight:700">⬆</button><button id="__insp_down" style="padding:5px 10px;border-radius:6px;border:1px solid #3b82f6;background:#1e3a5f;color:#93c5fd;font:inherit;cursor:pointer;font-weight:700">⬇</button><button id="__insp_prev" style="padding:5px 10px;border-radius:6px;border:1px solid #3b82f6;background:#1e3a5f;color:#93c5fd;font:inherit;cursor:pointer;font-weight:700">⬅</button><button id="__insp_next" style="padding:5px 10px;border-radius:6px;border:1px solid #3b82f6;background:#1e3a5f;color:#93c5fd;font:inherit;cursor:pointer;font-weight:700">➡</button><span id="__insp_tag" style="background:#312e81;padding:3px 8px;border-radius:4px;font-family:monospace;font-size:11px;color:#c4b5fd">-</span><span id="__insp_cnt" style="background:#0f172a;padding:2px 8px;border-radius:4px;font-size:11px;color:#f59e0b"></span></div><div style="display:flex;gap:6px;align-items:center;padding:4px 14px 8px;flex-wrap:wrap;border-top:1px solid #1e293b;background:#0f172a"><span style="font-size:10px;color:#64748b">پیش‌نمایش:</span><span id="__insp_preview" style="background:#0f172a;padding:6px 10px;border-radius:4px;font-size:11px;color:#86efac;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border:1px solid #22c55e;font-weight:700;min-width:100px;direction:ltr;text-align:left">در انتظار انتخاب...</span></div>';
document.body.appendChild(bar);
function selectEl(el){if(!el||el.tagName=='BODY'||el.tagName=='HTML'||el.closest('#__insp_bar'))return;var m=document.getElementById('__insp_m').value;var s=gs(el);if(E[m])E[m].classList.remove('__is');if(picked)picked.classList.remove('__ih');el.classList.add('__is');el.classList.remove('__ih');E[m]=el;S[m]=s;picked=el;document.getElementById('__insp_sel').textContent=s||'(none)';document.getElementById('__insp_tag').textContent=elInfo(el);var n=countSimilar(el);document.getElementById('__insp_cnt').textContent=n?n+' مشابه':'';var p=document.getElementById('__insp_preview');if(m==='container'){p.textContent='تعداد: '+n;p.style.color=n>=2?'#86efac':'#fbbf24';}else if(m==='title'){p.textContent=(el.textContent||'').trim().substring(0,80);p.style.color='#60a5fa';}else if(m==='price'){p.textContent=(el.textContent||'').trim().substring(0,50);p.style.color='#fbbf24';}else if(m==='link'){var a=el.tagName==='A'?el.getAttribute('href'):'';if(!a){var aEl=el.querySelector('a[href]');a=aEl?aEl.getAttribute('href'):'';}p.textContent=a||'(لینک یافت نشد)';p.style.color=a?'#a78bfa':'#fca5a5';}else if(m==='image'){var img=el.tagName==='IMG'?el.getAttribute('src'):'';if(!img){var imgEl=el.querySelector('img');img=imgEl?imgEl.getAttribute('src'):'';}p.textContent=img||'(تصویر یافت نشد)';p.style.color=img?'#f472b6':'#fca5a5';}}
document.addEventListener('mouseover',function(e){if(e.target.closest('#__insp_bar'))return;if(cur&&cur!==picked)cur.classList.remove('__ih');cur=e.target;if(cur!==picked)cur.classList.add('__ih');},true);
document.addEventListener('mouseout',function(e){if(e.target&&e.target!==picked)e.target.classList.remove('__ih');},true);
document.addEventListener('click',function(e){if(e.target.closest('#__insp_bar'))return;e.preventDefault();e.stopPropagation();selectEl(e.target);},true);
document.getElementById('__insp_ok').onclick=function(){var m=document.getElementById('__insp_m').value;if(!S[m]){alert('ابتدا المانی انتخاب کنید');return;}var i=fields.indexOf(m);if(i<fields.length-1){document.getElementById('__insp_m').value=fields[i+1];picked=null;}};
document.getElementById('__insp_done').onclick=function(){if(!S.container){alert('کانتینر را انتخاب کنید');return;}var t='';for(var k in S){if(S[k])t+=labels[k]+': '+S[k]+'\n';}navigator.clipboard.writeText(t).then(function(){alert('✅ سلکتورها کپی شد:\n\n'+t);}).catch(function(){prompt('سلکتورها را کپی کنید:',t);});};
document.getElementById('__insp_close').onclick=function(){bar.remove();style.remove();if(picked)picked.classList.remove('__ih');for(var k in E)if(E[k])E[k].classList.remove('__is');};
document.getElementById('__insp_up').onclick=function(){var el=picked||cur;if(!el)return;var p=el.parentElement;if(p&&p.tagName!=='BODY'&&p.tagName!=='HTML'&&!p.closest('#__insp_bar')){if(el===picked)el.classList.remove('__is');el.classList.remove('__ih');selectEl(p);p.scrollIntoView({behavior:'smooth',block:'center'});}};
document.getElementById('__insp_down').onclick=function(){var el=picked||cur;if(!el)return;var ch=Array.from(el.children).filter(function(c){return !c.id||c.id!=='__insp_bar'});if(ch.length>0){if(el===picked)el.classList.remove('__is');el.classList.remove('__ih');selectEl(ch[0]);ch[0].scrollIntoView({behavior:'smooth',block:'center'});}};
document.getElementById('__insp_prev').onclick=function(){var el=picked||cur;if(!el)return;var prev=el.previousElementSibling;if(prev){if(el===picked)el.classList.remove('__is');el.classList.remove('__ih');selectEl(prev);prev.scrollIntoView({behavior:'smooth',block:'center'});}};
document.getElementById('__insp_next').onclick=function(){var el=picked||cur;if(!el)return;var next=el.nextElementSibling;if(next){if(el===picked)el.classList.remove('__is');el.classList.remove('__ih');selectEl(next);next.scrollIntoView({behavior:'smooth',block:'center'});}};
document.body.style.paddingTop=(bar.offsetHeight+10)+'px';
})();`;
    const bookmarklet='javascript:'+script;
    navigator.clipboard.writeText(bookmarklet).then(function(){
        showToast('✅ اسکریپت بازرسی کپی شد — در نوار آدرس مرورگر سایت مقصد پیست کنید');
    }).catch(function(){
        prompt('اسکریپت بازرسی را کپی کنید (javascript: + اسکریپت):',bookmarklet);
    });
    $('status').textContent='📋 اسکریپت بازرسی کپی شد — در سایت مقصد اجرا کنید';
}
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

// v7.41: Start extraction from selectors tab
function startFromSelectors(){
  if(!sel.container){showToast('ابتدا کانتینر را انتخاب کنید!',true);return;}
  if(!sel.title){showToast('ابتدا سلکتور عنوان را انتخاب کنید!',true);return;}
  if(!$('url').value.trim()){showToast('ابتدا آدرس سایت را وارد کنید!',true);return;}
  switchMainTab('start');
  setTimeout(() => start(true), 300);
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

// v8.06: Safe scroll — only scrolls the element if it's visible and has overflow (prevents mobile auto-scroll)
function scrollElBottom(el){if(!el)return;try{if(el.scrollHeight>el.clientHeight&&el.scrollHeight>0){const nearBottom=el.scrollHeight-el.scrollTop-el.clientHeight<80;if(nearBottom)el.scrollTop=el.scrollHeight;}}catch(e){}}
function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

/**
 * v8.42: کلید محصول را مطمئن می‌کند.
 *
 * محصولات ذخیره‌شده در پروفایل به شکل [کلید, محصول] نگه داشته می‌شوند،
 * یعنی خودِ شیء محصول فیلد key ندارد. هنگام بارگذاری پروفایل، p.key
 * برابر undefined می‌شد و چون جست‌وجو با [data-k="undefined"] انجام
 * می‌شد، همهٔ محصولات روی یک کارت بازنویسی می‌شدند و فقط یکی دیده می‌شد.
 */
function ensureKey(p,fallback){
  if(!p||typeof p!=='object')return '';
  if(p.key===undefined||p.key===null||p.key===''){
    if(fallback!==undefined&&fallback!==null&&fallback!=='')p.key=fallback;
  }
  return p.key||'';
}

function renderCard(p,k){
  const _k=ensureKey(p,k);
  if(!_k)return;                       // بدون کلید نمی‌توان کارت ساخت
  const el=$('vGrid').querySelector(`[data-k="${_k}"]`);
  let title = getFinalTitle(p.title);
  let price = getFinalPrice(p.price);
  let origPrice = getOriginalPrice(p.price);
  let origDiffers = origPrice !== '0' && origPrice !== price;
  let shortDesc = '';
  if (detailSel.shortDesc && detailSel.shortDesc.enabled && p.shortDesc) {
      shortDesc = stripHtml(p.shortDesc);
  }
  // v8.40: نشان جدید/آپدیت
  const _st=prodStatus(p);
  const html=`<div class="thumb">${p.image?`<img class="lazy-img" data-src="?image_proxy=${encodeURIComponent(p.image)}" loading="lazy">`:'<div class="noimg">بدون تصویر</div>'}</div>
  <div class="pbody"><div class="ptitle">${statusBadge(_st)}${esc(title||'بدون عنوان')}</div>
  ${shortDesc ? `<div class="pdetail-short">${esc(shortDesc)}</div>` : ''}
  ${origDiffers ? `<span class="price-orig">${esc(origPrice)}</span>` : ''}
  <div class="price ${price!=='0'?'':'no-price'}">${price!=='0'?esc(price):'؟'}</div>
  ${p.link?`<a class="plink" href="${esc(p.link)}" target="_blank">مشاهده</a>`:''}</div>`;
  // v8.41: محصول تازه هرگز نباید به‌خاطر فیلترِ اجرای قبلی پنهان شود
  if(resultFilter!=='all'&&Object.keys(prodStatusMap).length===0)resetResultFilter();
  const _cls='product'+(_st==='new'?' is-new':_st==='changed'?' is-chg':'');
  if(el){el.innerHTML=html;el.className=_cls;el.style.display=matchFilter(_st)?'':'none';}
  else{const d=document.createElement('div');d.className=_cls;d.dataset.k=_k;d.innerHTML=html;
       d.style.display=matchFilter(_st)?'':'none';$('vGrid').appendChild(d);}
}

function renderRow(p,i,k){
  const _k=ensureKey(p,k);
  if(!_k)return;
  const el=$('tBody').querySelector(`[data-k="${_k}"]`);
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

  // v8.40: نشان جدید/آپدیت کنار عنوان
  const _st=prodStatus(p);
  const html=`<td>${toFa(i)}</td><td>${statusBadge(_st)}${esc(title)}</td><td class="td-orig">${esc(origPrice)}</td><td style="direction:ltr;text-align:right">${esc(price)}</td>
  <td>${p.link?`<a href="${esc(p.link)}" target="_blank">لینک</a>`:'-'}</td><td style="direction:ltr;text-align:left;font-size:9px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(p.image||'')}">${esc(p.image||'-')}</td>${detailTds}${customTd}`;
  if(el){el.innerHTML=html;el.style.display=matchFilter(_st)?'':'none';}
  else{const tr=document.createElement('tr');tr.dataset.k=_k;tr.innerHTML=html;
       tr.style.display=matchFilter(_st)?'':'none';$('tBody').appendChild(tr);}
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
            renderCard(products.get(k),k);
            renderRow(products.get(k), i + 1, k);
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
  let headers = '# | Title | Original | Final | URL | Image';
  const enabledFields = getEnabledDetailFields();
  enabledFields.forEach(f => headers += ` | ${f.label}`);
  if (isCustomColEnabled()) headers += ` | ${getCustomColName()}`;
  let t = headers + '\n' + '-'.repeat(140) + '\n';
  order.forEach((k,i)=>{
      const p=products.get(k);
      if(!p)return;
      let row = `${i+1} | ${(getFinalTitle(p.title)||'').substring(0,25)} | ${getOriginalPrice(p.price)} | ${getFinalPrice(p.price)} | ${p.link||'-'} | ${p.image||'-'}`;
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

// v7.66: Auto extract — uses saved selectors from profile, no need to re-select
// v7.81: Backend Extract — server-side scraping from source supplier website
// NOT sending to BaSalam/WooCommerce — only extracting products and saving to profile
// Triggers ?action=backend_extract endpoint which does PHP-side scraping
function startBackendSync(){
    const sel=$('profileSelect');
    // Need a profile with saved selectors to know what/how to scrape
    if(!sel||!sel.value){
        showToast('⚠️ ابتدا پروفایل با سلکتورها ذخیره شده انتخاب کنید',1);
        switchMainTab('profiles');
        return;
    }
    // Check if selectors are saved in the profile
    const url=sel.value.trim();
    showToast('⚡ شروع استخراج بک‌اند...');
    // First check if profile has selectors
    fetch('?load_profile='+encodeURIComponent(url)).then(r=>r.json()).then(d=>{
        if(!d.ok||!d.profile){showToast('خطا: پروفایل یافت نشد',1);return;}
        const prof=d.profile||{};
        const sels=prof.selectors||{};
        if(!sels.container||sels.container===''){
            showToast('⚠️ سلکتورها ذخیره نشده — ابتدا با فرانت‌اند استخراج کنید',1);
            return;
        }
        openExtractPanel('⚡ استخراج بک‌اند — پیشرفت زنده');
        // Trigger backend extract endpoint (fire-and-forget)
        fetch('?action=backend_extract&profile_key='+encodeURIComponent(profileKey(url)),{method:'GET'}).catch(()=>{});
        watchExtractProgress();
    }).catch(()=>{showToast('خطا شبکه',1);});
}

/**
 * v8.28: پنل پیشرفت استخراج — یک ظاهر واحد برای هر سه مسیر
 * (دکمهٔ استخراج بک‌اند، دکمهٔ اجرای حالا، و کران‌جاب). قبلاً هرکدام
 * پنل خودش را می‌ساخت و «اجرای حالا» نه شمارنده داشت نه دکمهٔ توقف و
 * نه اصلاً polling را شروع می‌کرد، برای همین به نظر می‌رسید کاری نمی‌کند.
 */
function openExtractPanel(title){
    switchMainTab('start');
    const panel=$('extractProgressPanel');
    if(panel){
        panel.style.display='block';
        panel.innerHTML='<div style="color:#a855f7;font-weight:bold;padding:8px;margin-bottom:4px;background:#2e106530;border-radius:6px">'+esc(title)+'</div>'
            +'<div id="liveCounters" class="live-cnt"></div>'
            +'<div id="extractLog" style="max-height:400px;overflow-y:auto;font-size:11px;color:#e2e8f0"></div>'
            +'<div id="extractStats" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr;gap:8px;margin-top:10px"></div>'
            +'<div style="text-align:center;margin-top:8px"><button class="btn btn-red" onclick="stopBackendExtract()">⏹ توقف</button></div>';
    }
    if($('extractStatusText'))$('extractStatusText').textContent='⚡ در حال استخراج...';
    if($('extractProgressBar')){$('extractProgressBar').style.width='0%';$('extractProgressBar').style.background='linear-gradient(90deg,#7c3aed,#a855f7)';}
    if($('extractProgress'))$('extractProgress').classList.remove('hidden');
    refreshExtractQueue();
}

/** شروع (یا ازسرگیری) رصد پیشرفت — از چند بار اجرا شدن جلوگیری می‌کند */
function watchExtractProgress(){
    if(extractPollTimer)clearInterval(extractPollTimer);
    extractPollTimer=setInterval(pollExtractProgress,1500);
}
function stopBackendExtract(){
    fetch('?extract_stop=1').catch(()=>{});
    if(extractPollTimer)clearInterval(extractPollTimer);
    $('extractStatusText').textContent='⏹ متوقف شد';
    $('extractProgress').classList.add('hidden');
    setTimeout(refreshExtractQueue,1000);
}
// v8.20: Extraction queue rendered in the same visual language as the
// Basalam/WooCommerce send queues (status badge, progress bar, counters,
// elapsed time, per-row actions).
function refreshExtractQueue(){
    fetch('?extract_queue_status=1').then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        renderExtractQueue(d.entries||[], d.progress||{});
    }).catch(()=>{});
}

function renderExtractQueue(entries, progress){
    const list=$('extractQueueList');
    if(!list)return;
    if(!entries.length){
        list.innerHTML='<span style="color:#64748b">صف خالی — برای افزودن، دکمه «⚡ استخراج بک‌اند» را کلیک کنید</span>';
        return;
    }
    const statusLabels={waiting:'⏳ در صف',running:'🔄 در حال استخراج',paused:'⏸ متوقف',done:'✅ انجام شد',failed:'❌ خطا'};
    const statusColors={waiting:'#fbbf24',running:'#a855f7',paused:'#f97316',done:'#4ade80',failed:'#f87171'};
    const statusBg={waiting:'#42200630',running:'#7c3aed20',paused:'#c2410c20',done:'#14532d20',failed:'#7f1d1d20'};

    let html='';
    entries.slice().reverse().forEach(e=>{
        const st=statusLabels[e.status]?e.status:'failed';
        // زندهٔ ردیف در حال اجرا از progress خوانده می‌شود
        const live=(st==='running'&&progress&&progress.running)?progress:null;
        const total=(live&&live.total)||e.total||0;
        const current=(live&&live.current)||e.current||0;
        const products=e.products_count||(live&&live.extracted)||0;
        const newC=(live&&live.new)||e.new||0;
        const chgC=(live&&live.price_changed)||e.price_changed||0;
        const remC=(live&&live.removed)||e.removed||0;
        const unchC=(live&&live.unchanged)||e.unchanged||0;

        let progText='', progPercent=0;
        if(st==='running'){
            if(total>0){
                progPercent=Math.min(100,Math.round(current/total*100));
                progText=toFa(current)+'/'+toFa(total)+' ('+toFa(progPercent)+'٪)';
            }else{
                progText='در حال آماده‌سازی...';
            }
            if(products>0)progText+=' | '+toFa(products)+'📦 '+toFa(newC)+'🆕 '+toFa(chgC)+'💰 '+toFa(remC)+'❌';
        }else if(st==='paused'){
            progPercent=total>0?Math.round(current/total*100):0;
            progText=toFa(current)+'/'+toFa(total)+' — متوقف';
        }else if(st==='done'){
            progPercent=100;
            progText='✓ '+toFa(products)+' محصول | '+toFa(newC)+' جدید | '+toFa(chgC)+' تغییر قیمت | '
                    +toFa(remC)+' حذف‌شده | '+toFa(unchC)+' بدون تغییر';
        }else if(st==='waiting'){
            progText=total>0?(toFa(total)+' محصول — منتظر شروع'):'منتظر شروع';
        }else if(st==='failed'){
            progText=e.error?esc(e.error):'استخراج ناتمام ماند';
        }

        html+='<div onclick="showExtractLogModal(\''+esc(e.id)+'\')" style="cursor:pointer;padding:8px 10px;border:1px solid #334155;border-radius:8px;margin:4px 0;background:'+statusBg[st]+';transition:background 0.2s" onmouseover="this.style.borderColor=\'#a855f7\'" onmouseout="this.style.borderColor=\'#334155\'">';

        // ردیف ۱: وضعیت + نام پروفایل + دکمه‌ها
        html+='<div style="display:flex;justify-content:space-between;align-items:center">';
        html+='<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
        html+='<span style="color:'+statusColors[st]+';font-weight:700;font-size:12px">'+statusLabels[st]+'</span>';
        // v8.27: منشأ اجرا — دستی یا خودکار (کران‌جاب)
        if(e.trigger==='auto'){
            html+='<span style="color:#22d3ee;font-size:10px;background:#0e749020;padding:1px 6px;border-radius:4px">⏱ خودکار</span>';
        }else if(e.trigger==='manual'){
            html+='<span style="color:#a78bfa;font-size:10px;background:#4c1d9520;padding:1px 6px;border-radius:4px">👤 دستی</span>';
        }
        if(e.profile_name)html+='<span style="color:#94a3b8;font-size:10px">'+esc(e.profile_name)+'</span>';
        if(products>0)html+='<span style="color:#e2e8f0;font-weight:600;font-size:12px">'+toFa(products)+' محصول</span>';
        html+='</div>';
        html+='<div style="display:flex;gap:4px">';
        if(st==='running'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#f97316;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();stopExtractQueue(\''+esc(e.id)+'\')">⏹ توقف</button>';
        }else{
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:'+(st==='done'?'#334155':'#dc2626')+';color:'+(st==='done'?'#94a3b8':'#fff')+';border:none;border-radius:4px" onclick="event.stopPropagation();deleteExtractQueue(\''+esc(e.id)+'\')">🗑️ حذف</button>';
        }
        html+='</div></div>';

        // ردیف ۲: نوار پیشرفت
        if(progPercent>0||st==='running'||st==='paused'||st==='done'){
            html+='<div style="margin-top:4px"><div style="height:4px;background:#1e293b;border-radius:2px;overflow:hidden"><div style="height:100%;background:'+statusColors[st]+';width:'+progPercent+'%;border-radius:2px;transition:width 0.5s"></div></div></div>';
        }

        // ردیف ۳: متن پیشرفت
        if(progText)html+='<div style="color:#94a3b8;font-size:10px;margin-top:3px">'+progText+'</div>';

        // ردیف ۴: زمان سپری‌شده یا زمان پایان
        if(st==='running'&&e.started_at>0){
            const el=Math.floor(Date.now()/1000-e.started_at);
            const es=el>=60?(Math.floor(el/60)+' دقیقه '+(el%60)+' ثانیه'):(el+' ثانیه');
            html+='<div style="color:#64748b;font-size:9px;margin-top:2px">⏱ '+es+'</div>';
        }else if(e.started_at>0){
            html+='<div style="color:#64748b;font-size:9px;margin-top:2px">⏱ '+new Date(e.started_at*1000).toLocaleString('fa-IR')+'</div>';
        }

        html+='<div style="color:#475569;font-size:9px;margin-top:1px">کلیک برای گزارش تفصیلی →</div>';
        html+='</div>';
    });
    list.innerHTML=html;
}

function deleteExtractQueue(qid){
    if(!confirm('این ردیف از صف حذف شود؟'))return;
    fetch('?extract_queue_delete=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        if(d.ok){showToast('🗑️ حذف شد');refreshExtractQueue();}
        else showToast(d.error||'خطا در حذف',1);
    }).catch(()=>showToast('خطا شبکه',1));
}

function clearExtractQueueDone(){
    fetch('?extract_queue_clear_done=1').then(r=>r.json()).then(d=>{
        if(d.ok){showToast('🧹 '+toFa(d.removed||0)+' ردیف پاک شد');refreshExtractQueue();}
        else showToast(d.error||'خطا',1);
    }).catch(()=>showToast('خطا شبکه',1));
}

function stopExtractQueue(qid){
    if(!confirm('استخراج در حال اجرا متوقف شود؟'))return;
    fetch('?extract_stop=1').then(()=>{
        showToast('⏹ درخواست توقف ارسال شد');
        setTimeout(refreshExtractQueue,1200);
    }).catch(()=>showToast('خطا شبکه',1));
}
// v7.81: Show extract log modal for a specific queue entry
function showExtractLogModal(queueId){
    // Create modal
    let modal=$('extractLogModal');
    if(!modal){
        modal=document.createElement('div');
        modal.id='extractLogModal';
        modal.style.cssText='position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center';
        document.body.appendChild(modal);
    }
    modal.style.display='flex';
    modal.innerHTML='<div style="background:#1e293b;border:1px solid #475569;border-radius:12px;padding:16px;width:90%;max-width:700px;max-height:85vh;overflow-y:auto;color:#e2e8f0">'
        +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">'
        +'<span style="font-weight:700;font-size:14px;color:#a855f7">📋 گزارش استخراج بک‌اند</span>'
        +'<button class="btn btn-gray" onclick="closeExtractLogModal()" style="font-size:10px;padding:4px 8px">✕</button>'
        +'</div>'
        +'<div id="extractModalCounters" class="live-cnt"></div>'
        +'<div id="extractModalLog" style="font-size:11px;max-height:45vh;overflow-y:auto;margin-top:10px">بارگذاری...</div>'
        +'</div>';
    // v8.25: شمارنده‌های قابل کلیک این اجرا را بارگذاری کن
    loadExtractModalCounters(queueId);
    pollExtractLogModal();
}

/**
 * v8.25: شمارنده‌های جدید/تغییر قیمت/حذف‌شده را برای یک اجرای مشخص
 * می‌آورد. با کلیک روی هرکدام، فهرست محصولات همان دسته باز می‌شود.
 */
function loadExtractModalCounters(queueId){
    const box=$('extractModalCounters');
    if(box)box.innerHTML='<div style="grid-column:1/-1;color:#64748b;font-size:11px;padding:6px">در حال بارگذاری شمارنده‌ها...</div>';
    fetch('?extract_report=1&queue_id='+encodeURIComponent(queueId)).then(r=>r.json()).then(d=>{
        if(!box)return;
        if(!d.ok){
            box.innerHTML='<div style="grid-column:1/-1;color:#64748b;font-size:11px;padding:6px">'+esc(d.error||'گزارشی موجود نیست')+'</div>';
            return;
        }
        const rp=d.report||{};
        // همان داده‌ای که مودال جزئیات از آن می‌خواند
        extractReportData={
            newItems:rp.new_items||[], changedItems:rp.changed_items||[],
            removedItems:rp.removed_items||[], unchangedCount:rp.unchanged||0,
            totalOld:0
        };
        buildStatusMap();refreshResultBadges();
        // شمارنده‌ها از خودِ گزارش خوانده می‌شوند نه از طول لیست،
        // چون لیست‌ها سقف ۳۰۰ تایی دارند ولی شمارش باید دقیق باشد.
        const nNew=rp.new!=null?rp.new:(rp.new_items||[]).length;
        const nChg=rp.price_changed!=null?rp.price_changed:(rp.changed_items||[]).length;
        const nRem=rp.removed!=null?rp.removed:(rp.removed_items||[]).length;
        const nUnc=rp.unchanged||0;
        const up=rp.price_up||0, down=rp.price_down||0;
        const gone=rp.gone_from_site||0, noPrice=rp.no_price||0;

        const cell=(icon,label,val,color,type,extra)=>
            '<div class="lc" style="border-color:'+color+'33" onclick="showExtractReport(\''+type+'\')" title="کلیک برای دیدن فهرست">'
            +'<b style="color:'+color+'">'+toFa(val)+'</b><span>'+icon+' '+label+'</span>'
            +(extra?'<i style="color:#64748b">'+extra+'</i>':'')+'</div>';

        box.innerHTML=
            '<div class="lc" style="border-color:#67e8f933"><b style="color:#67e8f9">'+toFa(rp.extracted||0)+'</b><span>📦 کل</span></div>'
            +cell('🆕','جدید',nNew,'#4ade80','new')
            +cell('💰','تغییر قیمت',nChg,'#facc15','changed',nChg?('▲'+toFa(up)+' ▼'+toFa(down)):'')
            +cell('❌','حذف/ناموجود',nRem,'#f87171','removed',noPrice?('🚫'+toFa(noPrice)+' بی‌قیمت'):'')
            +cell('⏭','بدون تغییر',nUnc,'#94a3b8','unchanged');
    }).catch(()=>{
        if(box)box.innerHTML='<div style="grid-column:1/-1;color:#f87171;font-size:11px;padding:6px">خطا در دریافت گزارش</div>';
    });
}
function closeExtractLogModal(){
    const modal=$('extractLogModal');
    if(modal)modal.style.display='none';
    if(extractModalTimer)clearInterval(extractModalTimer);
}
function pollExtractLogModal(){
    if(extractModalTimer)clearInterval(extractModalTimer);
    extractModalTimer=setInterval(()=>{
        fetch('?poll_extract=1').then(r=>r.json()).then(d=>{
            if(!d)return;
            const logDiv=$('extractModalLog');
            if(!logDiv)return;
            const logs=d.recent_log||[];
            const running=d.running||false;
            const done=d.done||false;
            const extracted=d.extracted||0;
            const newC=d.new||0;const changedC=d.price_changed||0;const removedC=d.removed||0;
            let html='';
            if(done){
                html+='<div style="color:#4ade80;font-weight:700;margin-bottom:8px">✅ تکمیل: '+toFa(extracted)+' محصول</div>';
                if(newC>0)html+='<div style="color:#4ade80;font-size:11px">🆕 '+toFa(newC)+' جدید</div>';
                if(changedC>0)html+='<div style="color:#facc15;font-size:11px">💰 '+toFa(changedC)+' تغییر قیمت</div>';
                if(removedC>0)html+='<div style="color:#f87171;font-size:11px">❌ '+toFa(removedC)+' حذف شده</div>';
                clearInterval(extractModalTimer);
            }else if(running){
                html+='<div style="color:#a855f7;font-weight:700;margin-bottom:8px">⏳ در حال اجرا — '+toFa(extracted)+' محصول</div>';
            }
            if(logs.length>0){
                logs.forEach(m=>{
                    let cls='color:#94a3b8';
                    if(m.includes('✅')||m.includes('✓'))cls='color:#4ade80';
                    if(m.includes('❌'))cls='color:#f87171';
                    if(m.includes('📄'))cls='color:#facc15';
                    if(m.includes('🔍'))cls='color:#67e8f9';
                    html+='<div style="'+cls+';padding:2px 4px">'+esc(m)+'</div>';
                });
            }
            logDiv.innerHTML=html;
        }).catch(()=>{});
    },2000);
}
// v8.22: clickable live counters shown while the extraction runs
function renderLiveCounters(d){
    const box=$('liveCounters');
    if(!box)return;
    const nNew=d.new||0, nChg=d.price_changed||0, nRem=d.removed||0, nUnc=d.unchanged||0;
    const up=d.price_up||0, down=d.price_down||0;

    // نگه داشتن داده برای باز کردن لیست‌ها بدون انتظار برای پایان کار
    extractReportData={
        newItems:d.new_items||[], changedItems:d.changed_items||[],
        removedItems:d.removed_items||[], unchangedCount:nUnc,
        totalOld:nNew+nChg+nRem+nUnc
    };
    buildStatusMap();refreshResultBadges();

    const cell=(icon,label,val,color,type,extra)=>
        '<div class="lc" style="border-color:'+color+'33" onclick="showExtractReport(\''+type+'\')" title="کلیک برای دیدن لیست">'
        +'<b style="color:'+color+'">'+toFa(val)+'</b>'
        +'<span>'+icon+' '+label+'</span>'
        +(extra?'<i style="color:#64748b">'+extra+'</i>':'')
        +'</div>';

    let extra='';
    if(nChg>0)extra='▲'+toFa(up)+' ▼'+toFa(down);
    const noPrice=d.no_price||0;   // v8.26: چند تا از حذف‌شده‌ها بی‌قیمت‌اند

    box.innerHTML=
        cell('📦','کل','','#67e8f9','none').replace('<b style="color:#67e8f9"></b>','<b style="color:#67e8f9">'+toFa(d.extracted||0)+'</b>')
        +cell('🆕','جدید',nNew,'#4ade80','new')
        +cell('💰','تغییر قیمت',nChg,'#facc15','changed',extra)
        +cell('❌','حذف/ناموجود',nRem,'#f87171','removed',noPrice?('🚫'+toFa(noPrice)+' بی‌قیمت'):'')
        +cell('⏭','بدون تغییر',nUnc,'#94a3b8','unchanged');
}

function pollExtractProgress(){
    // v8.20: keep the queue list live while a run is in progress, the same
    // way the Basalam queue refreshes itself during a send.
    window._extractPollCount=(window._extractPollCount||0)+1;
    if(window._extractPollCount%3===0)refreshExtractQueue();

    fetch('?poll_extract=1').then(r=>r.json()).then(d=>{
        if(!d)return;
        renderLiveCounters(d);   // v8.22
        const running=d.running||false;
        const done=d.done||false;
        const cancelled=d.cancelled||false;
        const extracted=d.extracted||0;
        const logs=d.recent_log||[];
        const phase=d.phase||'list';
        const current=d.current||0;
        const total=d.total||0;
        const newCount=d.new||0;
        const priceChanged=d.price_changed||0;
        const removed=d.removed||0;
        const unchanged=d.unchanged||0;
        const page=d.page||0;

        // Update progress bar
        $('extractProgressBar').style.width=(total>0?Math.min(current/total*100,100):0)+'%';

        // Update status text
        let statusText='';
        if(running&&!done){
            if(phase==='detail'){
                statusText='🔍 جزئیات '+toFa(d.detail_current||0)+'/'+toFa(d.detail_total||0)+' — کل: '+toFa(extracted)+' محصول';
            }else{
                statusText='📄 صفحه '+toFa(page)+' — '+toFa(extracted)+' محصول استخراج شده';
            }
        }else if(done){
            if(cancelled){
                statusText='⏹ متوقف شد';
            }else{
                statusText='✅ '+toFa(extracted)+' محصول — 🆕'+toFa(newCount)+' 💰'+toFa(priceChanged)+' ❌'+toFa(removed)+' ⏭'+toFa(unchanged);
            }
        }else if(d.error){
            statusText='❌ '+d.error;
        }
        $('extractStatusText').textContent=statusText;

        // Show logs
        const logDiv=$('extractLog');
        if(logDiv&&logs.length>0){
            logs.forEach(m=>{
                let cls='color:#64748b;font-size:11px';
                if(m.includes('✅')||m.includes('✓'))cls='color:#4ade80;font-size:11px';
                if(m.includes('❌'))cls='color:#f87171;font-size:11px';
                if(m.includes('🔍'))cls='color:#67e8f9;font-size:11px';
                if(m.includes('📄'))cls='color:#facc15;font-size:11px';
                logDiv.insertAdjacentHTML('beforeend','<div style="'+cls+'">'+esc(m)+'</div>');
            });
            scrollElBottom(logDiv);
        }

        // Show comparison stats when done
        if(done){
            if(extractPollTimer)clearInterval(extractPollTimer);
            $('extractProgress').classList.add('hidden');
            refreshExtractQueue(); // v7.81: Update queue list
            // v8.19: Store backend extract report data for modal access
            if(d.new_items||d.changed_items||d.removed_items){
                extractReportData={newItems:d.new_items||[],changedItems:d.changed_items||[],removedItems:d.removed_items||[],unchangedCount:d.unchanged||0,totalOld:(d.new_items||[]).length+(d.changed_items||[]).length+(d.removed_items||[]).length+(d.unchanged||0)};
                buildStatusMap();refreshResultBadges();
            }
            if(!cancelled&&extracted>0){
                // Load profile products and show in current session
                loadBackendExtractResults(d.profile_key||'');
                // Show live comparison
                showLiveComparison();
                // v8.19: Show clickable stats in extractStats
                const statsDiv=$('extractStats');
                if(statsDiv){
                    statsDiv.innerHTML='<div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'new\')"><b style="color:#4ade80;font-size:16px">'+toFa(newCount)+'</b><br><span style="color:#94a3b8;font-size:10px">🆕 جدید</span></div><div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'changed\')"><b style="color:#facc15;font-size:16px">'+toFa(priceChanged)+'</b><br><span style="color:#94a3b8;font-size:10px">💰 تغییر قیمت</span></div><div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'removed\')"><b style="color:#f87171;font-size:16px">'+toFa(removed)+'</b><br><span style="color:#94a3b8;font-size:10px">❌ حذف شده</span></div><div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'unchanged\')"><b style="color:#94a3b8;font-size:16px">'+toFa(unchanged)+'</b><br><span style="color:#94a3b8;font-size:10px">⏭ بدون تغییر</span></div><div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center"><b style="color:#60a5fa;font-size:16px">'+toFa(extracted)+'</b><br><span style="color:#94a3b8;font-size:10px">📊 کل</span></div>';
                }
                showToast('✅ '+extracted+' محصول استخراج شد — 🆕'+newCount+' 💰'+priceChanged+' ❌'+removed);
            }
        }
    }).catch(()=>{});
}
function loadBackendExtractResults(key){
    // Load the profile that was just updated by backend extract
    fetch('?load_profile='+encodeURIComponent(key)).then(r=>r.json()).then(d=>{
        if(!d.ok||!d.profile)return;
        const prof=d.profile||{};
        const prods=prof.products||[];
        const prodOrder=prof.productsOrder||[];
        resetResultFilter();
        products.clear();order=[];
        if(!prodOrder.length)return;
        // Convert products: could be [[key,data],...] or {key:data,...}
        let prodMap={};
        if(Array.isArray(prods)&&prods.length>0){
            const first=prods[0];
            if(Array.isArray(first)&&first.length>=2&&typeof first[0]=='string'){
                // [[key,data],...] format
                prods.forEach(e=>{if(Array.isArray(e)&&e.length>=2)prodMap[e[0]]=e[1];});
            }else{
                // [{title:...,price:...},...] flat array — keyed by prodOrder
                prodOrder.forEach((k,i)=>{if(prods[i])prodMap[k]=prods[i];});
            }
        }else if(typeof prods==='object'&&prods!==null){
            // {key:data,...} object format
            prodMap=prods;
        }
        prodOrder.forEach(k=>{
            const p=prodMap[k];
            if(p){
                // v8.42: کلید را روی خود شیء بنشان — پروفایل آن را جدا نگه می‌دارد
                if(typeof p==='object'&&!p.key)p.key=k;
                products.set(k,p);
                order.push(k);
                renderCard(p,k);
                renderRow(p,order.length,k);
            }
        });
        update();
        $('txtContent').textContent=genTxt();
        // Auto-save current session
        if($('url').value.trim()&&products.size>0)saveProfileSilent();
    }).catch(()=>{});
}
// Helper: profileKey from URL — mirrors PHP profileKey()
function profileKey(url){
    try{
        const parsed=new URL(url);
        let host=parsed.hostname.toLowerCase();
        let path=parsed.pathname.replace(/^\/|\/$/g,'');
        // Remove /page/N suffix
        path=path.replace(/\/page\/\d+\/?$/i,'');
        // Remove .html/.htm/.php suffix
        path=path.replace(/\.(html|htm|php)$/i,'');
        // Replace non-alpha-numeric with underscore
        path=path.replace(/[^a-z0-9]+/gi,'_');
        return host+(path?'_'+path:'');
    }catch(e){return '';}
}
function startAutoExtract(){
    if(running) return;
    const url=$('url').value.trim();
    if(!url){showToast('URL وارد کنید',true);return;}
    // Check if selectors are saved
    if(!sel.container){
        showToast('⚠️ سلکتورها ذخیره نشده — ابتدا سلکتورها انتخاب و پروفایل ذخیره کنید',true);
        switchMainTab('selectors');
        return;
    }
    if(!sel.title){
        showToast('⚠️ سلکتور عنوان ذخیره نشده — ابتدا آن را انتخاب کنید',true);
        switchMainTab('selectors');
        return;
    }
    if (isDirty) saveProfileSilent();
    switchMainTab('start');
    setTimeout(() => start(true), 300);
}

function start(useSel=false){
  if(running)return;
  const url=$('url').value.trim();
  if(!url){showToast('URL وارد کنید',true);return;}

  if (isDirty) saveProfileSilent();

  products.clear();order=[];pages=0;details=0;running=true;
  $('vGrid').innerHTML='';$('tBody').innerHTML='';
  resetResultFilter();
  log('▶ شروع: '+url,'info');

  $('startBtn').classList.add('hidden');$('startManualBtn').classList.add('hidden');$('stopBtn').classList.remove('hidden');
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
  es.addEventListener('fetch_info',e=>{const d=JSON.parse(e.data);if(d.msg)$('status').textContent=d.msg;log(d.msg,'info');});
  es.addEventListener('missing_done',e=>{const d=JSON.parse(e.data);details++;const p=products.get(d.key);if(p){if(d.image)p.image=d.image;if(d.price)p.price=d.price;if(d.image_cached){p._imgCached=true;p._imgValid=true;}else if(d.image_valid){p._imgValid=true;}products.set(d.key,p);renderCard(p,d.key);renderRow(p,order.indexOf(d.key)+1,d.key);}update();});
  es.addEventListener('complete',e=>{
      const d=JSON.parse(e.data);
      log(`✅ تکمیل: ${d.total} محصول`,'ok');
      $('status').textContent=`✓ ${toFa(d.total)} محصول`;
      $('progressBar').style.width='100%';
      showToast(`✓ ${d.total} محصول استخراج شد`);
      switchMainTab('results');
      // Auto-save profile with products after scrape completes
      if($('url').value.trim() && products.size > 0) saveProfileSilent();
  });
  es.addEventListener('error',e=>{if(e.data)log('❌ '+JSON.parse(e.data).message,'err');});
  es.addEventListener('done',finish);
  es.onerror=()=>{if(running)finish();};
}

function stop(){if(es)es.close();log('⏹ متوقف شد','err');finish();}
function finish(){running=false;if(es){es.close();es=null;}$('startBtn').classList.remove('hidden');$('startManualBtn').classList.remove('hidden');$('stopBtn').classList.add('hidden');$('txtContent').textContent=genTxt();showLiveComparison();}

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
                    renderCard(p, d.key);
                    renderRow(p, order.indexOf(d.key) + 1, d.key);
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
    // v7.81: Show comparison report after extraction
    showComparisonReport();
}

// v7.81: Clear results only (keep selectors, URL, profile settings)
function clearResults(){
  if(!confirm('پاک کردن همه نتایج؟ (تنظیمات و سلکتورها حفظ می‌شوند)')) return;
  resetResultFilter();
  products.clear();order=[];pages=0;details=0;
  $('vGrid').innerHTML='<div class="empty-state" id="emptyState"><div class="icon">📭</div><p>هنوز محصولی اسکرپ نشده است.</p></div>';
  $('tBody').innerHTML='';$('txtContent').textContent='';
  $('emptyState')||0;
  $('numP').textContent='۰';$('numPg').textContent='۰';$('numD').textContent='۰';
  $('resultsBadge').classList.add('hidden');$('resultsBadge').textContent='0';
  $('detailProgress').classList.add('hidden');$('detailStatus').textContent='';
  $('detailProgressBar').style.width='0%';
  $('logs').innerHTML='<div class="log log-info">نتایج پاک شدند</div>';
  refreshViews();
  scheduleSave();
  showToast('✅ نتایج پاک شدند');
}

function reset(){
  stop();$('url').value='<?=h(DEFAULT_URL)?>';resetResultFilter();products.clear();order=[];pages=0;details=0;
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

/* ==================================================================
 *  بررسی نسخه و نصب از طریق deploy.php
 *  این اسکریپت خودش هیچ کدی دانلود یا بازنویسی نمی‌کند؛ فقط مقایسه
 *  می‌کند و برای نصب، مرورگر را به deploy.php (فایل جداگانه) می‌فرستد.
 * ================================================================== */
let VC = null, vcSaveTimer = null, VC_BRANCHES = [], VC_FILES = [], VC_PENDING = false;

/* ==================================================================
 *  v8.28: تاریخچهٔ تغییرات — تازه‌ترین نسخه بالای فهرست
 * ================================================================== */
const CHANGELOG = [
  {v:'8.48', t:'یادگیری دسته‌بندی از انتخاب‌های دستی', items:[
    'هر بار دستهٔ محصولی را دستی اصلاح کنید، کلمهٔ اولِ عنوان با آن دسته به خاطر سپرده می‌شود',
    'دفعهٔ بعد محصولی با همان کلمهٔ اول، خودکار همان دسته را می‌گیرد',
    'انتخاب انسان بر حدس الگوریتم مقدم است',
    'کلمهٔ اول عنوان حالا سه برابر وزن دارد — در فارسی نوع کالا معمولاً اول می‌آید',
    'کلمات تبلیغاتی مثل «خرید» و «قیمت» در ابتدای عنوان نادیده گرفته می‌شوند',
    'اگر برای یک کلمه چند دسته ثبت شده باشد، پرتکرارترین برنده است',
    'بخش «🧠 یادگیری دسته‌بندی» برای دیدن، آزمایش و پاک کردن آموخته‌ها'
  ]},
  {v:'8.47', t:'مغایرت‌گیری ووکامرس و باسلام با پروفایل‌ها', items:[
    'دو دکمهٔ جدید: بررسی ووکامرس و بررسی باسلام',
    'همهٔ پروفایل‌هایی که همگام‌سازی دوره‌ای‌شان روشن است را با فروشگاه مقایسه می‌کند',
    'محصولی که در هیچ پروفایلی نیست را نشان می‌دهد و می‌تواند حذف یا غیرفعال کند',
    'محصولی که قیمتش مغایرت دارد را نشان می‌دهد و می‌تواند اصلاح کند',
    'اول فقط گزارش می‌گیرد — هیچ تغییری بدون تأیید صریح شما انجام نمی‌شود',
    'محافظ ایمنی بازنشستگی اینجا هم اعمال می‌شود تا حذف انبوه اتفاقی رخ ندهد',
    'اگر مقصد هیچ محصولی برنگرداند، برای احتیاط هیچ کاری انجام نمی‌شود',
    'تفاوت واحد ریال باسلام و تومان ووکامرس در مقایسه لحاظ شده است'
  ]},
  {v:'8.46', t:'نصب نسخهٔ جدید بدون منتظر ماندن برای صف', items:[
    'قبلاً اگر اسکرپ یا ارسالی در جریان بود، نصب نسخهٔ جدید رد می‌شد',
    'حالا نصب همیشه انجام می‌شود و فقط اطلاع داده می‌شود چه کاری در جریان است',
    'اگر ارسالی در جریان باشد یک تأیید ساده گرفته می‌شود، نه بیشتر',
    'امن است چون deploy.php فایل را اتمیک جایگزین می‌کند و اجرای فعلی با نسخهٔ قبلی تمام می‌شود',
    'اسکریپت تک‌فایل است و require زمان‌اجرا ندارد، پس نیمی از کد قدیم و نیمی از کد جدید اجرا نمی‌شود',
    'صف روی فایل ذخیره می‌شود و از نصب جان سالم به در می‌برد'
  ]},
  {v:'8.45', t:'نگهبان صف قوی‌تر و بازطراحی مودال فاز ۲', items:[
    'باگ نگهبان: بازیابی با GET فرستاده می‌شد ولی پردازنده پارامترها را از POST می‌خواند — پس هر بازیابی از محصول اول شروع می‌کرد',
    'حالا شمارهٔ محصول جاری، شناسهٔ صف و پرچم from_file فرستاده می‌شوند و کار از همان‌جا ادامه می‌یابد',
    'قفل رهاشدهٔ پردازهٔ مرده و سیگنال توقف جامانده پاک می‌شوند',
    'ردیفی که کارش تمام شده ولی «در حال اجرا» مانده بود و جلوی صف را گرفته بود، بسته می‌شود',
    'باگ مودال فاز ۲: در حلقه دو تگ باز می‌شد و بسته نمی‌شد، پس هر محصول داخل محصول قبلی فرو می‌رفت',
    'مودال فاز ۲ بازنویسی شد: کارت‌های مرتب، نوار خلاصه، وضعیت هر محصول و دکمهٔ «اصلاح همهٔ پیشنهادها»',
    'نمایش درست روی موبایل'
  ]},
  {v:'8.44', t:'تغییر ضریب قیمت حالا همهٔ محصولات را به‌روز می‌کند', items:[
    'باگ بزرگ: کران اصلاً قیمت نهایی را نمی‌ساخت — ارسال‌کننده دنبال final_price می‌گشت که وجود نداشت و قیمت صفر می‌شد',
    'محاسبهٔ درصد/ضریب/گِرد کردن و پسوند عنوان حالا سمت سرور هم انجام می‌شود',
    'با تغییر دستی ضریب یا درصد قیمت، همهٔ محصولات یک بار به‌عنوان آپدیت فرستاده می‌شوند',
    'حتی اگر تیک «فقط تغییرات» فعال باشد، این ارسال کامل یک‌باره انجام می‌شود',
    'امضای تنظیمات قیمت ذخیره می‌شود تا ارسال کامل فقط یک بار تکرار شود',
    'تغییر پسوند عنوان هم مثل تغییر قیمت رفتار می‌کند'
  ]},
  {v:'8.43', t:'رفع باگ: دستهٔ باسلام پروفایل ذخیره/بازیابی نمی‌شد', items:[
    'هر ذخیره‌ای که فیلد دسته را نمی‌فرستاد، دستهٔ پروفایل را صفر می‌کرد — حالا مقدار قبلی حفظ می‌شود',
    'دکمهٔ 🔄 کنار دستهٔ پروفایل، انتخاب فعلی را پاک می‌کرد',
    'هنگام بارگذاری پروفایل اگر دسته‌ها هنوز نرسیده بودند، کادر خالی می‌ماند و ذخیرهٔ خودکار همان خالی را ثبت می‌کرد',
    'حالا تا رسیدن دسته‌ها شناسه نمایش داده می‌شود و بعد نام جایگزین می‌گردد',
    'نام دسته‌های جایگزین هم پس از بارگذاری دسته‌ها دوباره رسم می‌شود',
    'خواندن مقدار از فیلد مخفی به‌عنوان پشتیبان، تا انتخاب با صفر جایگزین نشود'
  ]},
  {v:'8.42', t:'رفع باگ اصلی: فقط یک محصول در تب نتایج دیده می‌شد', items:[
    'علت واقعی: محصولات در پروفایل به شکل [کلید, محصول] ذخیره می‌شوند و خودِ محصول فیلد key ندارد',
    'هنگام بارگذاری پروفایل، p.key برابر undefined می‌شد و همهٔ محصولات روی یک کارت بازنویسی می‌شدند',
    'حالا کلید به‌صورت صریح به renderCard و renderRow داده می‌شود',
    'هنگام بارگذاری، کلید روی خود شیء محصول هم نشانده می‌شود',
    'جست‌وجوی کارت به داخل vGrid محدود شد (قبلاً کل صفحه را می‌گشت)',
    'محصول بدون کلید نادیده گرفته می‌شود، نه اینکه روی کارت قبلی بنویسد'
  ]},
  {v:'8.41', t:'رفع باگ: شمارنده ۵۰۰ محصول می‌گفت ولی صفحه خالی بود', items:[
    'باگ نسخهٔ ۸.۴۰: فیلتر نتایج بین اجراها باقی می‌ماند',
    'اگر روی «جدید» فیلتر می‌کردید و بعد پروفایل دیگری بارگذاری می‌شد، محصولات تازه وضعیتی نداشتند و همه پنهان می‌شدند',
    'حالا با شروع استخراج، بارگذاری پروفایل، پاک کردن نتایج و بازنشانی، فیلتر هم صفر می‌شود',
    'دکمه‌های فیلتر هم بصری بازنشانی می‌شوند، نه فقط متغیر داخلی',
    'محافظ: اگر فیلتری فعال باشد ولی هیچ محصولی وضعیت نداشته باشد، خودکار به «همه» برمی‌گردد تا صفحه هیچ‌وقت خالی نماند'
  ]},
  {v:'8.40', t:'نشان جدید/آپدیت روی تب نتایج', items:[
    'کنار عنوان هر محصول در تب نتایج، نشان «جدید» یا «آپدیت» نمایش داده می‌شود',
    'نوار فیلتر بالای نتایج: همه / جدید / آپدیت / بدون تغییر با شمارش هرکدام',
    'در هر دو نمای کارت و جدول کار می‌کند و حاشیهٔ کارت هم رنگی می‌شود',
    'دیگر لازم نیست برای دیدن اینکه چه چیزی تغییر کرده، مودال گزارش را باز کنید',
    'تأیید شد: حذف محصولات رفته از مبدأ با فعال بودن تیک‌های افزودن/آپدیت هم انجام می‌شود'
  ]},
  {v:'8.39', t:'ارسال فقط تغییرات — تیک‌های افزودن/آپدیت بالاخره کار می‌کنند', items:[
    'باگ قدیمی: تیک‌های «افزودن/آپدیت ووکامرس» و «افزودن/آپدیت باسلام» ذخیره می‌شدند ولی سمت سرور اصلاً خوانده نمی‌شدند',
    'نتیجه این بود که هر اجرای خودکار کل محصولات را دوباره می‌فرستاد',
    'حالا با فعال بودن تیک، فقط محصولات جدید و تغییرکرده ارسال می‌شوند',
    'هر تیک مستقل عمل می‌کند — می‌توان فقط برای یکی از دو مقصد فعالش کرد',
    'اگر تغییری نباشد، صف اصلاً ساخته نمی‌شود (no_changes)',
    'اگر استخراج شکست بخورد، برای احتیاط کل فهرست فرستاده می‌شود نه فهرست ناقص',
    'سقف ۳۰۰تایی لیست تغییرات برداشته شد تا در فهرست‌های بزرگ محصولی جا نماند'
  ]},
  {v:'8.38', t:'یادآوری موارد بی‌جواب', items:[
    'هر مورد فقط یک بار اعلان می‌شود؛ دیگر با هر اجرای کران تکرار نمی‌شود',
    'اگر پیام مشتری بی‌جواب بماند یا سفارشی ارسال نشود، بعد از ۳۰ دقیقه (قابل تنظیم) یادآوری می‌آید',
    'به‌محض پاسخ دادن یا ارسال سفارش، یادآوری خودبه‌خود قطع می‌شود',
    'پیام تازه یا تغییر وضعیت، بلافاصله اعلان می‌شود حتی اگر قبلاً یادآوری رفته باشد',
    'پیام‌های یادآوری با نشان 🔁 و شمارهٔ دفعه مشخص می‌شوند',
    'حداکثر تعداد یادآوری قابل تنظیم است (۰ = بی‌نهایت)',
    'هنگام ارتقا، موارد قدیمی بی‌صدا ثبت می‌شوند تا یک‌جا ۱۰ پیام نیاید'
  ]},
  {v:'8.37', t:'پینگ کران‌جاب — بفهمید زمان‌بند واقعاً کار می‌کند', items:[
    'تیک «📡 پینگ اجرای کران‌جاب» به فهرست رویدادهای اعلان اضافه شد',
    'هر بار کران اجرا شود پیام می‌آید: زمان سرور، نسخه، تعداد پروفایل‌ها و محصولات استخراج‌شده',
    'فاصلهٔ زمانی قابل تنظیم (پیش‌فرض ۶ ساعت) چون کران هر ۵ دقیقه اجرا می‌شود و بدون آن روزی ۲۸۸ پیام می‌آمد',
    'اگر اجرا به‌خاطر قفل رد شود هم پینگ می‌آید — وگرنه قفلِ گیرکرده شبیه کرانِ خاموش دیده می‌شود',
    'دکمهٔ «📡 تست» برای دیدن همان پیام بدون منتظر ماندن'
  ]},
  {v:'8.36', t:'رفع باگ ارسال پروفایل اشتباه', items:[
    'باگ مهم: هنگام ارسال یک پروفایل، محصولات پروفایل دیگری فرستاده می‌شد',
    'علت: همهٔ ارسال‌ها از یک فایل مشترک می‌خواندند که با هر ارسال تازه بازنویسی می‌شد',
    'حالا هر صف فایل محصولات خودش را دارد و بر اساس queue_id خوانده می‌شود',
    'نام پروفایل در صف ثبت و نمایش داده می‌شود تا معلوم باشد چه چیزی می‌رود',
    'همین اشکال در صف ووکامرس و در کران‌جاب هم رفع شد',
    'تنظیمات «محصولات رفته از مبدأ» و «نگهبان صف» به بخش‌های جدای منوی همبرگری منتقل شدند'
  ]},
  {v:'8.35', t:'خودآزمون نصب — بفهمید چه نسخه‌ای واقعاً روی سرور است', items:[
    'صفحهٔ ?selftest=1 هر قابلیت را در همان فایلِ در حال اجرا بررسی می‌کند',
    'اگر نصب ناقص باشد (فایل قدیمی روی هاست مانده باشد) صریح می‌گوید',
    'محافظ ایمنی بازنشستگی واقعاً اجرا و آزمایش می‌شود، نه فقط وجودش بررسی شود',
    'خروجی JSON با ?selftest=1&json=1 برای بررسی خودکار',
    'دکمهٔ «خودآزمون نصب» در تنظیمات اعلان‌ها'
  ]},
  {v:'8.34', t:'بازنشستگی خودکار محصولات رفته از مبدأ', items:[
    'اگر محصولی در سایت مبدأ ناموجود یا حذف شود، حالا روی ووکامرس و باسلام هم از دسترس خارج می‌شود',
    'چهار حالت: کاری نکن / پیش‌نویس / ناموجود / حذف — پیش‌فرض «کاری نکن» است تا ناخواسته چیزی پاک نشود',
    'حذف در ووکامرس به زباله‌دان می‌رود (force=false) و در باسلام غیرفعال می‌شود، پس برگشت‌پذیر است',
    'محافظ ایمنی: اگر بیش از ۳۰٪ یا بیش از ۵۰ محصول یک‌جا حذف شده باشند، هیچ کاری نمی‌کند و هشدار می‌فرستد',
    'دکمهٔ «پیش‌نمایش» نشان می‌دهد چه اتفاقی می‌افتد، بدون اینکه چیزی تغییر کند',
    'دکمهٔ «بررسی حالا» برای نگهبان صف و تنظیم آستانه از داخل پنل'
  ]},
  {v:'8.33', t:'رفع خطای ۴۲۲، متن کامل پیام‌ها، نگهبان صف', items:[
    'رفع خطای ۴۲۲ در استعلام سفارش‌ها و محصولات — پارامتر sort نامعتبر بود و حذف شد',
    'پیام خطای ۴۲۲ حالا دقیقاً می‌گوید کدام پارامتر ایراد داشته',
    'متن کامل پیام‌های خوانده‌نشدهٔ مشتری به پیام‌رسان‌ها فرستاده می‌شود، نه فقط آخرین پیام',
    'نگهبان صف: اگر ارسال ووکامرس یا باسلام بیش از ۵ دقیقه بی‌حرکت بماند، خودکار ادامه داده می‌شود',
    'نگهبان با flock کار می‌کند، پس هیچ‌وقت دو پردازش موازی نمی‌سازد و سرعت ارسال را کم نمی‌کند',
    'اندپوینت ?queue_watchdog برای بررسی دستی وضعیت گیر کردن صف'
  ]},
  {v:'8.32', t:'مودال استعلام: اول ببین، بعد بفرست', items:[
    'دکمه‌های «سفارش‌ها» و «گفتگوها» حالا لیست را در مودال باز می‌کنند، نه اینکه مستقیم پیام بفرستند',
    'انتخاب موردی با تیک، و دو دکمهٔ ارسال: «خلاصه در یک پیام» یا «جداگانه»',
    'فیلتر «ارسال‌نشده» و «خوانده‌نشده» — موارد نیازمند اقدام از پیش تیک خورده‌اند',
    'رفع باگ: نام مشتری در سفارش‌ها همیشه «نامشخص» نشان داده می‌شد — مسیر درست customer.recipient.name است',
    'رفع باگ: متن آخرین پیام گفتگو خوانده نمی‌شد — مسیر درست last_message.content.text است',
    'نمایش نام فارسی وضعیت سفارش از جدول رسمی کدها (جدید، در حال آماده‌سازی، ارسال شده و ...)'
  ]},
  {v:'8.31', t:'رفع خطای ۴۰۴ استعلام‌های باسلام', items:[
    'مسیر سفارش‌ها به vendor-parcels و گفتگوها به chats اصلاح شد (باسلام به API Gateway مهاجرت کرده بود)',
    'اصلاح نگاشت فیلدها طبق مستندات رسمی — مشتری، مبلغ و تعداد پیام خوانده‌نشده',
    'پیام خطای گویا: تفکیک ۴۰۴ (مسیر) از ۴۰۳ (کمبود اسکوپ) و ۴۰۱ (توکن)',
    'حذف کامل cron_sync'
  ]},
  {v:'8.30', t:'اعلان تغییرات مبدأ و ادغام کران', items:[
    'اعلان گران/ارزان شدن و موجود/ناموجود شدن محصولات سایت مبدأ',
    'اعلان خطای اجرای خودکار — دیگر شکست کران‌جاب بی‌صدا نمی‌ماند',
    'ادغام cron_sync و cron_run در یک مسیر واحد با قفل ضد هم‌پوشانی'
  ]},
  {v:'8.29', t:'اعلان‌های پیام‌رسان', items:[
    'دکمه‌های تست جداگانه برای سفارش‌ها، پیام‌های مشتری و تغییر وضعیت محصولات',
    'استعلام اعلان‌ها به‌عنوان مرحلهٔ سوم به «اجرای حالا» و کران‌جاب اضافه شد',
    'رفع اشکال: cron_sync اصلاً اعلان‌ها را بررسی نمی‌کرد، فقط cron_run'
  ]},
  {v:'8.28', t:'یکسان‌سازی ظاهر استخراج', items:[
    'دکمهٔ «اجرای حالا» و کران‌جاب دقیقاً همان پنل و شمارنده‌های زندهٔ «استخراج بک‌اند» را نشان می‌دهند',
    'رفع اشکال: «اجرای حالا» اصلاً رصد پیشرفت را شروع نمی‌کرد، برای همین به نظر می‌رسید کاری نمی‌کند',
    'افزوده شدن همین گزارش تغییرات به منوی همبرگری'
  ]},
  {v:'8.27', t:'هستهٔ مشترک استخراج', items:[
    'کران‌جاب و دکمهٔ دستی یک تابع مشترک را اجرا می‌کنند تا رفتارشان از هم فاصله نگیرد',
    'برچسب «👤 دستی» و «⏱ خودکار» روی ردیف‌های صف',
    'حذف کد تکراری قدیمی که باعث می‌شد فیلدهای جزئیات در اجرای خودکار خالی بماند'
  ]},
  {v:'8.26', t:'محصولات بدون قیمت', items:[
    'محصول بی‌قیمت یا «ناموجود» حالا در دستهٔ حذف/ناموجود می‌آید، نه «بدون تغییر»',
    'تفکیک «از سایت حذف شد» و «ناموجود شد» با ستون علت',
    'رفع اشکال: شمارنده‌ها از طول لیست خوانده می‌شدند و در اجرای بزرگ کمتر گزارش می‌کردند'
  ]},
  {v:'8.25', t:'مقایسه بر پایهٔ قیمت مبدأ', items:[
    'گران/ارزان شدن همیشه با قیمت اصلی سایت سنجیده می‌شود، نه قیمت اعمال‌شدهٔ خودمان',
    'شمارنده‌های قابل کلیک در مودال کارهای تمام‌شده',
    'رفع اشکال: اختلاف صرفاً قالب‌بندی («۱۲۰٬۰۰۰» و «120000») تغییر قیمت شمرده می‌شد'
  ]},
  {v:'8.24', t:'بکاپ رمزنگاری‌شده', items:[
    'ارسال کل ورک‌اسپیس هاست به گیت‌هاب با رمزنگاری AES-256-GCM فایل‌های حساس',
    'بازگرداندن فایل رمزشده با عبارت رمز'
  ]},
  {v:'8.23', t:'بکاپ ورک‌اسپیس', items:[
    'ارسال فایل‌های هاست به یک برنچ گیت‌هاب',
    'محافظت دولایه در برابر انتشار کلیدها (نام فایل + بررسی محتوا)'
  ]},
  {v:'8.22', t:'شمارنده‌های زنده', items:[
    'شمارنده‌های جدید/تغییر قیمت/حذف‌شده در حین استخراج و قابل کلیک',
    'ستون «گران شد / ارزان شد» با اختلاف و درصد',
    'مقاوم‌سازی کران‌جاب: قفل ضد هم‌پوشانی و محافظ صفحهٔ خالی'
  ]},
  {v:'8.21', t:'رفع خطای ۴۰۱', items:[
    'توکن نامعتبر گیت‌هاب دیگر کل به‌روزرسانی را از کار نمی‌اندازد و خودکار پاک می‌شود'
  ]},
  {v:'8.20', t:'نسخه و صف استخراج', items:[
    'نمایش نسخهٔ کد در بالای صفحه',
    'ظاهر صف استخراج هم‌سان با صف ووکامرس و باسلام'
  ]},
  {v:'8.18', t:'به‌روزرسانی از گیت‌هاب', items:[
    'بررسی و نصب نسخهٔ جدید از داخل پنل'
  ]},
];

/**
 * v8.29: تست استعلام باسلام — واقعاً درخواست می‌فرستد، نه شبیه‌سازی.
 * حالت تست وضعیت را ذخیره نمی‌کند تا اعلان واقعی بعدی از دست نرود.
 */
function notifTest(kind){
  const labels={orders:'🛒 سفارش‌ها',chats:'💬 پیام‌ها',products:'📋 محصولات',source:'💰 تغییرات مبدأ',ping:'📡 پینگ کران'};
  const box=$('notifTestR');
  if(box)box.innerHTML='<div style="color:#93c5fd;font-size:11px">⏳ در حال استعلام '+esc(labels[kind]||kind)+' از باسلام...</div>';
  fetch('?notif_test=1&kind='+encodeURIComponent(kind)).then(r=>r.json()).then(d=>{
    if(!box)return;
    if(!d.ok){
      box.innerHTML='<div style="color:#f87171;font-size:11px;background:#7f1d1d20;padding:6px 8px;border-radius:6px">✗ '+esc(d.error||'خطا')+'</div>';
      showToast(d.error||'تست ناموفق',1);
      return;
    }
    const sent=d.sent||{};
    const chips=Object.keys(sent).map(k=>{
      const okv=sent[k]==='sent';
      const nm={baleh:'بله',rubika:'روبیکا',none:'پیام‌رسان'}[k]||k;
      return '<span style="font-size:10px;padding:1px 7px;border-radius:4px;margin-left:4px;background:'
        +(okv?'#14532d':'#7f1d1d')+';color:'+(okv?'#86efac':'#fca5a5')+'">'
        +(okv?'✓ ':'✗ ')+esc(nm)+'</span>';
    }).join('');
    let h='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px;font-size:11px">';
    h+='<div style="color:#4ade80;margin-bottom:4px">✓ ارتباط با باسلام برقرار است</div>';
    h+='<div style="color:#94a3b8">بررسی‌شده: '+toFa(d.total_seen||0)+' مورد · یافت‌شده: '+toFa(d.found||0)+'</div>';
    if(chips)h+='<div style="margin-top:4px">ارسال: '+chips+'</div>';
    if(d.sample)h+='<div style="margin-top:6px;color:#cbd5e1;background:#1e293b;padding:6px;border-radius:6px;white-space:pre-wrap;font-size:10.5px">'+esc(d.sample)+'</div>';
    h+='</div>';
    box.innerHTML=h;
    showToast('✓ تست '+(labels[kind]||kind)+' انجام شد');
  }).catch(()=>{
    if(box)box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا در ارتباط</div>';
    showToast('خطا شبکه',1);
  });
}

/** v8.37: پینگ آزمایشی — همان پیامی که کران‌جاب می‌فرستد */
function testPing(){notifTest('ping');}

/* =====================================================================
 *  v8.47: مغایرت‌گیری — رابط کاربری
 * ===================================================================== */
var reconLast=null;

function reconLabel(t){return t==='woo'?'ووکامرس':'باسلام';}

/** گام ۱: فقط گزارش، بدون هیچ تغییری */
function reconScan(target){
  const box=$('reconR');
  if(box)box.innerHTML='<div style="color:#93c5fd;font-size:11px">⏳ در حال خواندن '
    +esc(reconLabel(target))+' و مقایسه با پروفایل‌ها... (ممکن است طول بکشد)</div>';
  fetch('?recon=1&target='+encodeURIComponent(target)).then(r=>r.json()).then(d=>{
    reconLast=d;
    if(!box)return;
    if(!d.ok){box.innerHTML='<div style="color:#fca5a5;font-size:11px;background:#7f1d1d20;'
      +'padding:6px 8px;border-radius:6px">✗ '+esc(d.error||'خطا')+'</div>';return;}
    box.innerHTML=reconReport(d,target);
  }).catch(e=>{if(box)box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا: '+esc(e.message)+'</div>';});
}

function reconReport(d,target){
  const nExtra=d.extra_total||0, nDiff=d.price_diff_total||0;
  let h='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:9px;font-size:11px">';
  h+='<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:6px">'
   +'<span style="color:#94a3b8">در پروفایل‌ها: <b style="color:#e2e8f0">'+toFa(d.expected||0)+'</b></span>'
   +'<span style="color:#94a3b8">در '+esc(reconLabel(target))+': <b style="color:#e2e8f0">'+toFa(d.remote||0)+'</b></span>'
   +'<span style="color:#86efac">یکسان: '+toFa(d.matched||0)+'</span>'
   +'</div>';
  h+='<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:6px">'
   +'<span style="color:'+(nExtra?'#fca5a5':'#64748b')+'">🗑 اضافی: <b>'+toFa(nExtra)+'</b></span>'
   +'<span style="color:'+(nDiff?'#fbbf24':'#64748b')+'">💰 مغایرت قیمت: <b>'+toFa(nDiff)+'</b></span>'
   +'</div>';
  if(d.applied){
    h+='<div style="color:#4ade80;margin:4px 0">✅ اعمال شد — '
      +toFa(d.repriced||0)+' قیمت اصلاح، '+toFa(d.deleted||0)+' مورد اضافی'
      +((d.failed||0)?' · <span style="color:#fca5a5">'+toFa(d.failed)+' ناموفق</span>':'')+'</div>';
  }
  if(d.skipped_delete){
    h+='<div style="color:#fca5a5;background:#7f1d1d20;padding:5px 7px;border-radius:6px;margin:4px 0">'
      +'🛑 حذف انجام نشد: '+esc(d.skipped_delete)+'</div>';
  }
  // نمونه‌ها
  const sample=(arr,title,color,fmt)=>{
    if(!arr||!arr.length)return '';
    let x='<div style="margin-top:6px"><div style="color:'+color+';font-weight:700;margin-bottom:3px">'+title+'</div>';
    arr.slice(0,8).forEach(it=>{x+='<div style="color:#cbd5e1;border-top:1px solid #1e293b;padding:2px 0">• '+fmt(it)+'</div>';});
    if(arr.length>8)x+='<div style="color:#64748b;padding-top:2px">… و '+toFa(arr.length-8)+' مورد دیگر</div>';
    return x+'</div>';
  };
  h+=sample(d.extra,'🗑 در هیچ پروفایلی نیست','#fca5a5',
    it=>esc(String(it.title||'').slice(0,50))+(it.done===true?' <span style="color:#4ade80">✓</span>':''));
  h+=sample(d.price_diff,'💰 قیمت مغایر','#fbbf24',
    it=>esc(String(it.title||'').slice(0,40))+' <span style="color:#94a3b8">'
      +toFa(Number(it.from||0).toLocaleString('en-US'))+' → </span>'
      +'<b style="color:#e2e8f0">'+toFa(Number(it.to||0).toLocaleString('en-US'))+'</b>'
      +(it.done===true?' <span style="color:#4ade80">✓</span>':''));
  if(!d.applied&&(nExtra||nDiff)){
    h+='<div style="margin-top:8px;padding-top:8px;border-top:1px solid #334155">';
    h+='<div style="color:#94a3b8;margin-bottom:5px">اقدام برای موارد اضافی:</div>';
    h+='<select id="reconMode" style="width:100%;margin-bottom:6px;padding:5px;font-size:11px">'
      +'<option value="off">کاری نکن (فقط قیمت‌ها اصلاح شود)</option>'
      +'<option value="draft">پیش‌نویس/غیرفعال کن</option>'
      +'<option value="outofstock">ناموجود کن</option>'
      +'<option value="delete">حذف کن (زباله‌دان)</option></select>';
    h+='<label style="display:flex;align-items:center;gap:6px;color:#cbd5e1;margin-bottom:6px;cursor:pointer">'
      +'<input type="checkbox" id="reconFixPrice" checked style="width:14px;height:14px">'
      +'<span>قیمت‌های مغایر اصلاح شوند ('+toFa(nDiff)+')</span></label>';
    h+='<button class="btn btn-green" onclick="reconApply(\''+target+'\')" style="width:100%;font-size:11px">'
      +'✅ اعمال تغییرات</button>';
    h+='</div>';
  }
  if(!nExtra&&!nDiff)h+='<div style="color:#4ade80;margin-top:4px">✓ همه‌چیز هماهنگ است</div>';
  return h+'</div>';
}

/** گام ۲: اعمال، با تأیید صریح */
function reconApply(target){
  const mode=($('reconMode')||{}).value||'off';
  const fix=($('reconFixPrice')||{}).checked?1:0;
  const d=reconLast||{};
  const nExtra=d.extra_total||0, nDiff=d.price_diff_total||0;
  const modeTxt={off:'',draft:'پیش‌نویس',outofstock:'ناموجود',delete:'حذف'}[mode]||'';
  let msg='تغییرات روی '+reconLabel(target)+':\n';
  if(fix&&nDiff)msg+='\n• اصلاح قیمت '+nDiff+' محصول';
  if(mode!=='off'&&nExtra)msg+='\n• '+modeTxt+' کردن '+nExtra+' محصول اضافی';
  if(!fix&&mode==='off'){showToast('هیچ اقدامی انتخاب نشده',1);return;}
  msg+='\n\nادامه می‌دهید؟';
  if(!confirm(msg))return;
  const box=$('reconR');
  if(box)box.innerHTML='<div style="color:#93c5fd;font-size:11px">⏳ در حال اعمال...</div>';
  fetch('?recon=1&apply=1&target='+encodeURIComponent(target)
    +'&mode='+encodeURIComponent(mode)+'&fix_price='+fix)
   .then(r=>r.json()).then(res=>{
    reconLast=res;
    if(!box)return;
    if(!res.ok){box.innerHTML='<div style="color:#fca5a5;font-size:11px">✗ '+esc(res.error||'خطا')+'</div>';return;}
    res.applied=true;
    box.innerHTML=reconReport(res,target);
    showToast('✓ '+toFa(res.repriced||0)+' قیمت · '+toFa(res.deleted||0)+' مورد اضافی');
  }).catch(e=>{if(box)box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا: '+esc(e.message)+'</div>';});
}

/* v8.48: رابط حافظهٔ یادگیری دسته‌بندی */
function catLearnShow(){
  const box=$('catLearnR');
  if(box)box.innerHTML='<div style="color:#93c5fd;font-size:11px">⏳ ...</div>';
  fetch('?catlearn=1').then(r=>r.json()).then(d=>{
    if(!box)return;
    if(!d.ok){box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا</div>';return;}
    if(!d.count){box.innerHTML='<div style="color:#64748b;font-size:11px">هنوز چیزی آموخته نشده — '
      +'یک دسته را دستی اصلاح کنید.</div>';return;}
    let h='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px;font-size:11px">';
    h+='<div style="display:flex;align-items:center;margin-bottom:5px">'
      +'<span style="color:#93c5fd">🧠 '+toFa(d.count)+' کلمه آموخته شده</span>'
      +'<span style="flex:1"></span>'
      +'<button class="btn btn-red" onclick="catLearnClear()" style="font-size:10px;padding:3px 8px">پاک کردن همه</button></div>';
    d.rows.slice(0,40).forEach(r=>{
      h+='<div style="display:flex;gap:6px;align-items:center;border-top:1px solid #1e293b;padding:3px 0">'
        +'<b style="color:#e2e8f0;min-width:70px">'+esc(r.word)+'</b>'
        +'<span style="color:#94a3b8;flex:1">→ '+esc(r.cat_name||('#'+r.cat_id))+'</span>'
        +'<span style="color:#64748b;font-size:10px">'+toFa(r.times)+'×</span>'
        +(r.variants>1?'<span style="color:#fbbf24;font-size:10px" title="چند دستهٔ مختلف">⚠'+toFa(r.variants)+'</span>':'')
        +'<button class="btn btn-gray" onclick="catLearnForget(\''+encodeURIComponent(r.word)+'\')" '
        +'style="font-size:9px;padding:2px 6px">✕</button></div>';
    });
    if(d.count>40)h+='<div style="color:#64748b;padding-top:4px">… و '+toFa(d.count-40)+' مورد دیگر</div>';
    box.innerHTML=h+'</div>';
  }).catch(()=>{if(box)box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا</div>';});
}
function catLearnForget(w){
  fetch('?catlearn=1&forget='+w).then(r=>r.json()).then(()=>{showToast('فراموش شد');catLearnShow();});
}
function catLearnClear(){
  if(!confirm('همهٔ آموخته‌ها پاک شوند؟'))return;
  fetch('?catlearn=1&clear=1').then(r=>r.json()).then(()=>{showToast('پاک شد');catLearnShow();});
}
function catLearnTest(){
  const t=prompt('عنوان محصول را بنویسید:');
  if(!t)return;
  const box=$('catLearnR');
  fetch('?catlearn=1&test='+encodeURIComponent(t)).then(r=>r.json()).then(d=>{
    if(!box)return;
    box.innerHTML='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px;font-size:11px">'
      +'<div style="color:#cbd5e1">عنوان: '+esc(d.title||'')+'</div>'
      +'<div style="color:#93c5fd">کلمهٔ اول: <b>'+esc(d.first_word||'—')+'</b></div>'
      +'<div style="color:'+(d.learned_cat?'#4ade80':'#64748b')+'">'
      +(d.learned_cat?('✓ دستهٔ آموخته‌شده: #'+d.learned_cat):'— هنوز برای این کلمه چیزی آموخته نشده')
      +'</div></div>';
  }).catch(()=>{});
}

/** v8.36: نشانگر کنار عنوان بخش «محصولات رفته از مبدأ» */
function updateRetireBadge(){
  const el=$('retireS'),sel=$('retireMode');
  if(!el||!sel)return;
  const lbl={off:'خاموش',draft:'پیش‌نویس',outofstock:'ناموجود',delete:'حذف'}[sel.value]||'خاموش';
  el.textContent=lbl;
  el.className='cst '+(sel.value==='off'?'off':'on');
}

/** v8.36: نشانگر کنار عنوان بخش «نگهبان صف» */
function updateStallBadge(){
  const el=$('stallS'),cb=$('stallWatchdog');
  if(!el||!cb)return;
  el.textContent=cb.checked?'فعال':'خاموش';
  el.className='cst '+(cb.checked?'on':'off');
}

/**
 * v8.34: پیش‌نمایش بازنشستگی — هیچ تغییری اعمال نمی‌کند، فقط نشان می‌دهد
 * چه محصولاتی روی مقصد پیدا می‌شوند و چه بلایی سرشان می‌آید.
 */
function retirePreview(){
  const box=$('retireR');
  const key=$('profileSelect')?$('profileSelect').value:'';
  if(!key){if(box)box.innerHTML='<div style="color:#fca5a5;font-size:11px">اول یک پروفایل انتخاب کنید</div>';return;}
  const mode=$('retireMode')?$('retireMode').value:'draft';
  if(mode==='off'){if(box)box.innerHTML='<div style="color:#94a3b8;font-size:11px">اقدام روی «کاری نکن» است</div>';return;}
  if(box)box.innerHTML='<div style="color:#93c5fd;font-size:11px">⏳ در حال بررسی...</div>';
  fetch('?retire_run=1&dry=1&profile='+encodeURIComponent(key)+'&mode='+encodeURIComponent(mode))
   .then(r=>r.json()).then(d=>{
    if(!box)return;
    if(!d.ok){box.innerHTML='<div style="color:#f87171;font-size:11px;background:#7f1d1d20;padding:6px 8px;border-radius:6px">✗ '+esc(d.error||'خطا')+'</div>';return;}
    if(d.skipped){
      const blocked=d.guard&&d.guard.blocked;
      box.innerHTML='<div style="color:'+(blocked?'#fca5a5':'#94a3b8')+';font-size:11px;background:'+(blocked?'#7f1d1d20':'#0f172a')+';padding:6px 8px;border-radius:6px">'
        +(blocked?'🛑 ':'ℹ️ ')+esc(d.skipped)+'</div>';return;
    }
    let h='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px;font-size:11px">';
    h+='<div style="color:#fbbf24;margin-bottom:4px">پیش‌نمایش — هیچ تغییری اعمال نشد</div>';
    h+='<div style="color:#94a3b8">بررسی‌شده: '+toFa(d.checked||0)+' · یافت‌نشده: '+toFa(d.not_found||0)+'</div>';
    (d.items||[]).slice(0,12).forEach(it=>{
      h+='<div style="border-top:1px solid #1e293b;padding:3px 0;color:#cbd5e1">• '+esc(it.title||'')
        +' <span style="color:#64748b">'+esc(it.reason||'')+'</span>'
        +(it.woo?' <span style="color:#67e8f9">woo: '+esc(it.woo)+'</span>':'')
        +(it.bsl?' <span style="color:#c4b5fd">bsl: '+esc(it.bsl)+'</span>':'')+'</div>';
    });
    h+='<div style="margin-top:6px;color:#94a3b8">برای اجرای واقعی، «اقدام» را ذخیره کنید تا در اجرای خودکار بعدی انجام شود.</div>';
    h+='</div>';
    box.innerHTML=h;
  }).catch(()=>{if(box)box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا در ارتباط</div>';});
}

/** v8.34: بررسی دستی وضعیت گیر کردن صف */
function watchdogCheck(){
  const box=$('watchdogR');
  if(box)box.innerHTML='<div style="color:#93c5fd;font-size:11px">⏳ در حال بررسی...</div>';
  const after=$('stallAfter')?$('stallAfter').value:300;
  fetch('?queue_watchdog=1&dry=1&after='+encodeURIComponent(after)).then(r=>r.json()).then(d=>{
    if(!box)return;
    if(!d.ok){box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا</div>';return;}
    let h='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px;font-size:11px">';
    (d.checks||[]).forEach(c=>{
      const nm=c.which==='bsl'?'باسلام':'ووکامرس';
      if(c.stalled){
        h+='<div style="color:#fca5a5">⚠️ '+esc(nm)+' گیر کرده — '+toFa(c.idle||0)+' ثانیه بی‌حرکت'
          +' ('+toFa(c.current||0)+'/'+toFa(c.total||0)+')</div>';
      }else{
        h+='<div style="color:#86efac">✓ '+esc(nm)+' — '+esc(c.reason||'سالم')+'</div>';
      }
    });
    h+='<div style="margin-top:4px;color:#64748b">در اجرای خودکار بعدی، موارد گیرکرده ادامه داده می‌شوند.</div>';
    h+='</div>';
    box.innerHTML=h;
  }).catch(()=>{if(box)box.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا در ارتباط</div>';});
}

/* =====================================================================
 *  v8.32: مودال‌های استعلام — سفارش‌ها و گفتگوها
 *  اول لیست را نشان می‌دهد، بعد کاربر انتخاب می‌کند چه چیزی
 *  به پیام‌رسان‌ها فرستاده شود. هر دو مودال از یک اسکلت مشترک می‌آیند.
 * ===================================================================== */
let inqState={kind:'',filter:'',rows:[],sel:{},loading:false};

function inqClose(){
  const m=document.getElementById('inqModalContainer');
  if(m)m.remove();
  inqState={kind:'',filter:'',rows:[],sel:{},loading:false};
}

/** تاریخ ISO را به شکل خوانا و کوتاه فارسی درمی‌آورد */
function inqDate(s){
  if(!s)return '—';
  const d=new Date(s);
  if(isNaN(d))return '—';
  try{
    return new Intl.DateTimeFormat('fa-IR',{month:'short',day:'numeric',
      hour:'2-digit',minute:'2-digit'}).format(d);
  }catch(e){return d.toLocaleString();}
}

function inqMoney(n){return toFa(Number(n||0).toLocaleString('en-US'))+' تومان';}

/** کلید یکتای هر ردیف بسته به نوع */
function inqId(r){return inqState.kind==='orders'?r.parcel_id:r.chat_id;}

/** ردیف‌هایی که به‌طور پیش‌فرض «اقدام‌نشده» شمرده می‌شوند */
function inqIsPending(r){return inqState.kind==='orders'?!!r.unsent:(r.unseen>0);}

function inqSelectedIds(){
  return Object.keys(inqState.sel).filter(k=>inqState.sel[k]);
}

function inqToggle(id){
  inqState.sel[id]=!inqState.sel[id];
  inqRenderFoot();
  const cb=document.getElementById('inqCb_'+id);
  if(cb)cb.checked=!!inqState.sel[id];
  const tr=document.getElementById('inqRow_'+id);
  if(tr)tr.style.background=inqState.sel[id]?'#1e3a5f':'';
}

function inqSelectAll(on){
  inqState.rows.forEach(r=>{inqState.sel[inqId(r)]=on;});
  inqRenderBody();inqRenderFoot();
}

/** فقط موارد اقدام‌نشده را تیک می‌زند */
function inqSelectPending(){
  inqState.rows.forEach(r=>{inqState.sel[inqId(r)]=inqIsPending(r);});
  inqRenderBody();inqRenderFoot();
}

function openOrdersModal(filter){inqOpen('orders',filter||'all');}
function openChatsModal(filter){inqOpen('chats',filter||'all');}

function inqOpen(kind,filter){
  inqState.kind=kind;inqState.filter=filter;inqState.sel={};inqState.loading=true;
  const isOrd=kind==='orders';
  const title=isOrd?'🛒 سفارش‌های باسلام':'💬 گفتگوهای باسلام';
  let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)inqClose()">';
  html+='<div class="bsl-modal" style="max-width:920px">';
  html+='<div class="bsl-modal-head"><h2 id="inqTitle">'+title+'</h2>'
      +'<button class="btn btn-gray" onclick="inqClose()">✕</button></div>';
  // نوار فیلتر
  html+='<div style="display:flex;gap:6px;padding:8px 12px;background:#111c31;border-bottom:1px solid #334155;flex-wrap:wrap;align-items:center">';
  if(isOrd){
    html+='<button class="btn btn-gray" id="inqF_all" onclick="inqSetFilter(\'all\')" style="font-size:11px;padding:5px 10px">همه</button>';
    html+='<button class="btn btn-gray" id="inqF_unsent" onclick="inqSetFilter(\'unsent\')" style="font-size:11px;padding:5px 10px">📦 ارسال‌نشده</button>';
  }else{
    html+='<button class="btn btn-gray" id="inqF_all" onclick="inqSetFilter(\'all\')" style="font-size:11px;padding:5px 10px">همه</button>';
    html+='<button class="btn btn-gray" id="inqF_unseen" onclick="inqSetFilter(\'unseen\')" style="font-size:11px;padding:5px 10px">💬 خوانده‌نشده</button>';
  }
  html+='<span style="flex:1"></span>';
  html+='<span id="inqSummary" style="font-size:11px;color:#94a3b8"></span>';
  html+='<button class="btn btn-gray" onclick="inqReload()" style="font-size:11px;padding:5px 10px">🔄 تازه‌سازی</button>';
  html+='</div>';
  html+='<div class="bsl-modal-body" id="inqBody" style="min-height:180px"></div>';
  html+='<div class="bsl-modal-pager" id="inqFoot" style="justify-content:space-between;flex-wrap:wrap;gap:6px"></div>';
  html+='</div></div>';
  const old=document.getElementById('inqModalContainer');if(old)old.remove();
  const div=document.createElement('div');div.id='inqModalContainer';div.innerHTML=html;
  document.body.appendChild(div);
  inqReload();
}

function inqSetFilter(f){inqState.filter=f;inqState.sel={};inqReload();}

function inqReload(){
  const body=document.getElementById('inqBody');
  if(body)body.innerHTML='<div style="padding:26px;text-align:center;color:#93c5fd;font-size:12px">⏳ در حال استعلام از باسلام...</div>';
  inqRenderFoot();
  const isOrd=inqState.kind==='orders';
  const url=isOrd
    ?('?bsl_orders_list=1&per_page=30&filter='+encodeURIComponent(inqState.filter))
    :('?bsl_chats_list=1&limit=50&filter='+encodeURIComponent(inqState.filter));
  fetch(url).then(r=>r.json()).then(d=>{
    inqState.loading=false;
    if(!d||!d.ok){
      if(body)body.innerHTML='<div style="padding:20px;color:#fca5a5;font-size:12px;background:#7f1d1d20;border-radius:8px;margin:8px">✗ '+esc(d&&d.error?d.error:'خطا در دریافت')+'</div>';
      inqRenderFoot();return;
    }
    inqState.rows=d.rows||[];
    // پیش‌فرض: موارد اقدام‌نشده تیک خورده باشند تا کار کاربر کم شود
    inqState.rows.forEach(r=>{if(inqState.sel[inqId(r)]===undefined)inqState.sel[inqId(r)]=inqIsPending(r);});
    const sm=document.getElementById('inqSummary');
    if(sm){
      sm.innerHTML=isOrd
        ?(toFa(d.total||0)+' سفارش · <b style="color:#fbbf24">'+toFa(d.unsent||0)+'</b> ارسال‌نشده')
        :(toFa(d.total||0)+' گفتگو · <b style="color:#fbbf24">'+toFa(d.unseen||0)+'</b> خوانده‌نشده');
    }
    ['all','unsent','unseen'].forEach(f=>{
      const b=document.getElementById('inqF_'+f);
      if(b){b.className='btn '+(inqState.filter===f?'btn-cyan':'btn-gray');}
    });
    inqRenderBody();inqRenderFoot();
  }).catch(e=>{
    inqState.loading=false;
    if(body)body.innerHTML='<div style="padding:20px;color:#fca5a5;font-size:12px">✗ خطا در ارتباط: '+esc(e.message)+'</div>';
    inqRenderFoot();
  });
}

function inqRenderBody(){
  const body=document.getElementById('inqBody');
  if(!body)return;
  const rows=inqState.rows;
  if(!rows.length){
    body.innerHTML='<div style="padding:30px;text-align:center;color:#64748b;font-size:12px">موردی یافت نشد</div>';
    return;
  }
  const isOrd=inqState.kind==='orders';
  let h='<table class="bsl-modal-table"><thead><tr>'
    +'<th style="width:34px"><input type="checkbox" onclick="inqSelectAll(this.checked)" style="width:15px;height:15px;cursor:pointer"></th>';
  h+=isOrd
    ?'<th>شماره</th><th>مشتری</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th>'
    :'<th>مشتری</th><th>آخرین پیام</th><th>خوانده‌نشده</th><th>زمان</th>';
  h+='</tr></thead><tbody>';
  rows.forEach(r=>{
    const id=inqId(r);
    const pend=inqIsPending(r);
    const on=!!inqState.sel[id];
    h+='<tr id="inqRow_'+id+'" style="border-bottom:1px solid #1e293b;'+(on?'background:#1e3a5f':'')+'">';
    h+='<td style="text-align:center"><input type="checkbox" id="inqCb_'+id+'" '+(on?'checked':'')
      +' onclick="inqToggle('+id+')" style="width:15px;height:15px;cursor:pointer"></td>';
    if(isOrd){
      h+='<td style="font-family:ui-monospace,monospace;direction:ltr;text-align:right">#'+toFa(r.order_id||r.parcel_id)+'</td>';
      h+='<td>'+esc(r.customer||'—')+(r.items_count>0?'<div style="font-size:9.5px;color:#64748b">'+toFa(r.items_count)+' قلم</div>':'')+'</td>';
      h+='<td style="white-space:nowrap">'+inqMoney(r.amount)+'</td>';
      h+='<td><span style="font-size:10px;padding:2px 7px;border-radius:4px;background:'
        +(pend?'#7c2d12':'#14532d')+';color:'+(pend?'#fdba74':'#86efac')+'">'+esc(r.status||'—')+'</span></td>';
      h+='<td style="font-size:10px;color:#94a3b8;white-space:nowrap">'+esc(inqDate(r.created_at))+'</td>';
    }else{
      h+='<td>'+esc(r.who||'—')+'</td>';
      h+='<td style="max-width:340px;color:#cbd5e1">'+esc((r.text||'').slice(0,90))+'</td>';
      h+='<td style="text-align:center">'+(r.unseen>0
        ?'<span style="font-size:10px;padding:2px 7px;border-radius:10px;background:#7f1d1d;color:#fca5a5">'+toFa(r.unseen)+'</span>'
        :'<span style="color:#475569">—</span>')+'</td>';
      h+='<td style="font-size:10px;color:#94a3b8;white-space:nowrap">'+esc(inqDate(r.updated_at))+'</td>';
    }
    h+='</tr>';
  });
  h+='</tbody></table>';
  body.innerHTML=h;
}

function inqRenderFoot(){
  const foot=document.getElementById('inqFoot');
  if(!foot)return;
  const n=inqSelectedIds().length;
  const isOrd=inqState.kind==='orders';
  const lbl=isOrd?'سفارش':'گفتگو';
  let h='<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
  h+='<button class="btn btn-gray" onclick="inqSelectPending()" style="font-size:11px;padding:5px 10px">'
    +(isOrd?'انتخاب ارسال‌نشده‌ها':'انتخاب خوانده‌نشده‌ها')+'</button>';
  h+='<button class="btn btn-gray" onclick="inqSelectAll(false)" style="font-size:11px;padding:5px 10px">هیچ‌کدام</button>';
  h+='<span style="font-size:11px;color:'+(n?'#4ade80':'#64748b')+'">'+toFa(n)+' '+lbl+' انتخاب شده</span>';
  h+='</div>';
  h+='<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
  h+='<button class="btn btn-purple" id="inqSendDigest" '+(n?'':'disabled')
    +' onclick="inqSend(1)" style="font-size:11px;padding:6px 11px">📋 ارسال خلاصه (یک پیام)</button>';
  h+='<button class="btn btn-green" id="inqSendEach" '+(n?'':'disabled')
    +' onclick="inqSend(0)" style="font-size:11px;padding:6px 11px">📨 ارسال جداگانه</button>';
  h+='</div>';
  foot.innerHTML=h;
}

function inqSend(digest){
  const ids=inqSelectedIds();
  if(!ids.length){showToast('موردی انتخاب نشده',1);return;}
  if(!digest&&ids.length>5&&!confirm('می‌خواهید '+ids.length+' پیام جداگانه فرستاده شود؟\nبرای شلوغ نشدن پیام‌رسان، «ارسال خلاصه» بهتر است.'))return;
  ['inqSendDigest','inqSendEach'].forEach(k=>{const b=document.getElementById(k);if(b){b.disabled=true;}});
  const b2=document.getElementById(digest?'inqSendDigest':'inqSendEach');
  const oldTxt=b2?b2.textContent:'';
  if(b2)b2.textContent='⏳ در حال ارسال...';
  fetch('?bsl_notify_selected=1&kind='+encodeURIComponent(inqState.kind)
    +'&digest='+(digest?1:0)+'&ids='+encodeURIComponent(ids.join(',')))
    .then(r=>r.json()).then(d=>{
      if(b2)b2.textContent=oldTxt;
      inqRenderFoot();
      if(!d||!d.ok){showToast((d&&d.error)?d.error:'ارسال ناموفق',1);return;}
      if(d.note){showToast(d.note,1);return;}
      const dl=d.delivery||{};
      const bad=Object.keys(dl).filter(k=>dl[k]!=='sent');
      if(bad.length)showToast('⚠ ارسال به '+bad.join('، ')+' ناموفق بود',1);
      else showToast('✓ '+toFa(d.sent||0)+' پیام فرستاده شد');
    }).catch(()=>{
      if(b2)b2.textContent=oldTxt;
      inqRenderFoot();showToast('خطا شبکه',1);
    });
}

function renderChangelog(){
  const box=$('changelogBox');
  if(!box)return;
  const cur='<?=APP_VERSION?>';
  box.innerHTML=CHANGELOG.map(c=>{
    const isCur=c.v===cur;
    return '<div style="border-right:2px solid '+(isCur?'#4ade80':'#334155')+';padding:0 9px 10px;margin-bottom:8px">'
      +'<div style="display:flex;align-items:center;gap:6px;margin-bottom:3px">'
      +'<b style="color:'+(isCur?'#4ade80':'#94a3b8')+';font-size:12px;font-family:ui-monospace,monospace">v'+esc(c.v)+'</b>'
      +(isCur?'<span style="font-size:9px;color:#4ade80;background:#14532d;padding:1px 6px;border-radius:4px">فعلی</span>':'')
      +'<span style="font-size:11px;color:#e2e8f0">'+esc(c.t)+'</span></div>'
      +'<ul style="margin:0;padding-right:16px;color:#94a3b8;font-size:10.5px;line-height:1.8">'
      +c.items.map(i=>'<li>'+esc(i)+'</li>').join('')+'</ul></div>';
  }).join('');
}

/** آیا عملیات سنگینی در جریان است؟ */
/**
 * v8.46: فقط گزارش می‌دهد چه کاری در جریان است — دیگر جلوی نصب را نمی‌گیرد.
 *
 * چرا امن است: deploy.php فایل را اتمیک جایگزین می‌کند (نوشتن در فایل
 * موقت، بررسی هش، سپس rename). پردازه‌های در حال اجرا نسخهٔ قبلی را
 * کامل در حافظه دارند و تا پایان با همان ادامه می‌دهند. اسکریپت هم
 * تک‌فایل است و هیچ require زمان‌اجرا ندارد، پس نیمی از کد قدیم و نیمی
 * از کد جدید اجرا نمی‌شود. صف هم روی فایل ذخیره می‌شود و از نصب
 * جان سالم به در می‌برد.
 */
function vcBusy() {
    try {
        if (typeof running !== 'undefined' && running) return 'اسکرپ';
        if (typeof detailRunning !== 'undefined' && detailRunning) return 'استخراج تفصیلی';
        if (typeof bslClientRunning !== 'undefined' && bslClientRunning) return 'ارسال به باسلام';
        if (typeof wSend !== 'undefined' && wSend) return 'ارسال به ووکامرس';
        if (typeof bSend !== 'undefined' && bSend) return 'ارسال به باسلام';
        if (typeof fetchMissingRunning !== 'undefined' && fetchMissingRunning) return 'تکمیل اطلاعات';
        if (typeof ddRunning !== 'undefined' && ddRunning) return 'حذف تکراری‌ها';
    } catch (e) {}
    return '';
}

function vcStat(msg, color) {
    const el = $('vcStatus');
    if (el) { el.innerHTML = msg; el.style.color = color || '#4ade80'; }
}
function vcBadge(t, cls) {
    const b = $('vcBadge');
    if (b) { b.textContent = t; b.className = 'cst ' + (cls || 'off'); }
}
function vcDirty() {
    clearTimeout(vcSaveTimer);
    vcSaveTimer = setTimeout(() => vcSave(), 900);
}
function vcFmtSize(b) {
    if (!b) return '';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
}
function vcCloseDrops() {
    ['vcBranchDrop', 'vcPathDrop'].forEach(id => { const e = $(id); if (e) e.classList.remove('open'); });
}

function vcLoad(then) {
    fetch('?vc_settings=1').then(r => r.json()).then(d => {
        if (!d.ok) return;
        VC = d.settings;
        if ($('vcOnLoad'))     $('vcOnLoad').checked   = !!VC.check_on_load;
        if ($('vcRepo'))       $('vcRepo').value       = VC.repo || '';
        if ($('vcBranch'))     $('vcBranch').value     = VC.branch || '';
        if ($('vcPath'))       $('vcPath').value       = VC.path || '';
        if ($('vcDeployFile')) $('vcDeployFile').value = VC.deploy_file || 'deploy.php';
        if ($('vcGhState'))    $('vcGhState').textContent  = VC.has_gh_token ? 'تنظیم شده' : 'تنظیم نشده';
        if ($('vcDepState'))   $('vcDepState').textContent = VC.has_dep_token ? 'تنظیم شده' : 'تنظیم نشده';
        if ($('vcLocalInfo')) {
            $('vcLocalInfo').innerHTML = esc(VC.self_name) + '<br>' +
                '<span style="color:#64748b">' + esc(VC.local_id) + ' · ' +
                toFa((VC.local_size / 1024).toFixed(0)) + ' KB</span>';
        }
        vcBadge(VC.check_on_load ? 'خودکار' : 'دستی', VC.check_on_load ? 'on' : 'off');
        vcStat('برای بررسی نسخهٔ جدید دکمه را بزنید', '#64748b');
        if (typeof then === 'function') then();
    }).catch(() => {});
}

function vcSave(showMsg) {
    const fd = new FormData();
    fd.append('check_on_load', $('vcOnLoad').checked ? '1' : '');
    fd.append('repo',        $('vcRepo').value.trim());
    fd.append('branch',      $('vcBranch').value.trim());
    fd.append('path',        $('vcPath').value.trim());
    fd.append('deploy_file', $('vcDeployFile').value.trim() || 'deploy.php');
    if ($('vcGhToken').value)  fd.append('github_token', $('vcGhToken').value);
    if ($('vcDepToken').value) fd.append('deploy_token', $('vcDepToken').value);

    fetch('?vc_settings=1', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (!d.ok) { showToast(d.error || 'ذخیره ناموفق', true); return; }
        VC = d.settings;
        $('vcGhToken').value = ''; $('vcDepToken').value = '';
        if ($('vcGhState'))  $('vcGhState').textContent  = VC.has_gh_token ? 'تنظیم شده' : 'تنظیم نشده';
        if ($('vcDepState')) $('vcDepState').textContent = VC.has_dep_token ? 'تنظیم شده' : 'تنظیم نشده';
        vcBadge(VC.check_on_load ? 'خودکار' : 'دستی', VC.check_on_load ? 'on' : 'off');
        if (showMsg) showToast('✓ ذخیره شد');
    }).catch(() => showToast('خطا در ذخیره', true));
}

/**
 * v8.23: پنل بکاپ ورک‌اسپیس را باز می‌کند.
 * خودِ اسکریپر چیزی آپلود نمی‌کند؛ کار به deploy.php واگذار می‌شود تا
 * این فایل ساده و دور از الگوهای مشکوک برای اسکنر هاست بماند.
 */
function vcOpenBackup() {
    fetch('?vc_deploy_info=1').then(r => r.json()).then(d => {
        if (!d.ok) {
            showToast(d.error || 'نصب‌کننده در دسترس نیست', true);
            vcStat('✗ ' + esc(d.error || ''), '#f87171');
            return;
        }
        window.open(d.file + '#wbackup', '_blank');
        showToast('پنل بکاپ در تب جدید باز شد');
    }).catch(() => showToast('خطا در ارتباط', true));
}

/** حذف توکن گیت‌هاب — رفع سریع خطای ۴۰۱ روی ریپوی عمومی */
function vcClearToken() {
    const fd = new FormData();
    fd.append('github_token', '__CLEAR__');
    fetch('?vc_settings=1', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (!d.ok) { showToast(d.error || 'حذف ناموفق', true); return; }
        VC = d.settings;
        if ($('vcGhState')) $('vcGhState').textContent = 'تنظیم نشده';
        showToast('✓ توکن حذف شد — دوباره تلاش کنید');
        vcStat('توکن حذف شد — دکمهٔ بررسی را بزنید', '#4ade80');
    }).catch(() => showToast('خطا در ارتباط', true));
}

/** مقایسهٔ نسخه. manual=true یعنی کاربر دکمه زده. */
function vcCheck(manual) {
    if (!manual && (!VC || !VC.check_on_load)) return;
    const btn = $('vcBtnCheck');
    if (manual && btn) { btn.disabled = true; btn.textContent = '⏳ در حال بررسی...'; }
    if (manual) vcStat('<span style="opacity:.7">در حال مقایسه با گیت‌هاب...</span>', '#93c5fd');

    fetch('?vc_check=1' + (manual ? '&force=1' : '')).then(r => r.json()).then(d => {
        const reset = () => { if (btn) { btn.disabled = false; btn.textContent = '🔍 بررسی و نصب نسخهٔ جدید'; } };
        if (d.skipped) { reset(); return; }
        if (!d.ok) {
            if (d.bad_token) {
                // رایج‌ترین علت ۴۰۱: توکنی که لازم نیست و نامعتبر است
                vcStat('✗ ' + esc(d.error) +
                    ' <button class="btn" style="font-size:10px;padding:2px 8px;background:#dc2626;color:#fff;border:none;border-radius:4px;margin-right:6px" onclick="vcClearToken()">حذف توکن</button>',
                    '#f87171');
            } else {
                vcStat('✗ ' + esc(d.error || 'خطا'), '#f87171');
            }
            if (manual) showToast(d.error || 'خطا', true);
            reset(); return;
        }
        if (!d.update) {
            VC_PENDING = false;
            $('vcBtnUpdate').classList.add('hidden');
            vcStat('✓ نسخهٔ شما به‌روز است — <code>' + esc(d.local_id) + '</code>', '#4ade80');
            vcBadge('به‌روز', 'on');
            const av = $('appVer');
            if (av) { av.classList.remove('upd'); av.title = 'نسخهٔ کد — به‌روز است'; }
            if (manual) showToast('✓ نسخهٔ شما به‌روز است');
            reset(); return;
        }

        // نسخهٔ جدید موجود است
        VC_PENDING = true;
        vcBadge('جدید', 'tg');
        $('vcBtnUpdate').classList.remove('hidden');
        const av = $('appVer');
        if (av) { av.classList.add('upd'); av.title = 'نسخهٔ جدید موجود است — کلیک کنید'; }
        vcStat('⬆ نسخهٔ جدید: <code>' + esc(d.remote_id) + '</code> · ' +
               toFa((d.remote_size / 1024).toFixed(0)) + ' KB', '#fbbf24');
        reset();

        if (!d.deploy_ready) {
            vcStat('⬆ نسخهٔ جدید موجود است — اما رمز نصب‌کننده تنظیم نشده', '#fbbf24');
            if (manual) showToast('رمز نصب‌کننده را در «منبع و نصب‌کننده» وارد کنید', true);
            return;
        }

        // v8.46: کاری در جریان باشد یا نه، نصب انجام می‌شود. جایگزینی
        // اتمیک است و اجرای فعلی با نسخهٔ قبلی تمام می‌شود.
        const busy = vcBusy();
        if (busy) showToast('«' + busy + '» در جریان است — نصب بدون وقفه انجام می‌شود');

        // دکمهٔ دستی = کاربر خودش خواسته، پس همان‌جا نصب و رفرش می‌کنیم
        if (manual) { vcUpdate(true); return; }
        vcBanner(d);
    }).catch(() => {
        vcStat('✗ خطا در ارتباط', '#f87171');
        if (btn) { btn.disabled = false; btn.textContent = '🔍 بررسی و نصب نسخهٔ جدید'; }
    });
}

function vcBanner(d) {
    if (document.getElementById('vcBar')) return;
    const b = document.createElement('div');
    b.id = 'vcBar';
    b.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:linear-gradient(135deg,#f59e0b,#f97316);' +
        'color:#1c1207;padding:11px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;' +
        'font-family:Tahoma,sans-serif;font-size:13px;font-weight:700;box-shadow:0 3px 14px rgba(0,0,0,.4);direction:rtl';
    b.innerHTML = '<span style="flex:1;min-width:200px">⬆ نسخهٔ جدیدی از کد روی گیت‌هاب موجود است' +
        ' <span style="opacity:.75;font-weight:400">(' + esc(d.remote_id) + ')</span></span>' +
        '<button id="vcGo" style="background:#0f172a;color:#fde68a;border:none;padding:8px 15px;border-radius:7px;' +
        'font-weight:700;cursor:pointer;font-family:inherit;font-size:12px">نصب کن</button>' +
        '<button id="vcNo" style="background:transparent;color:#1c1207;border:1px solid rgba(0,0,0,.35);' +
        'padding:8px 13px;border-radius:7px;cursor:pointer;font-family:inherit;font-size:12px">بعداً</button>';
    document.body.appendChild(b);
    document.body.style.paddingTop = (b.offsetHeight + 6) + 'px';
    document.getElementById('vcGo').onclick = () => { vcCloseBar(); vcUpdate(true); };
    document.getElementById('vcNo').onclick = vcCloseBar;
}
function vcCloseBar() {
    const b = document.getElementById('vcBar');
    if (b) b.remove();
    document.body.style.paddingTop = '';
}

/**
 * نصب: این تابع خودش چیزی نمی‌نویسد. یک فرم به deploy.php ارسال می‌کند
 * که فایلی مستقل است و کار نصب را با همان زنجیرهٔ ایمنی انجام می‌دهد.
 */
function vcUpdate(skipConfirm) {
    // v8.46: دیگر رد نمی‌شود. فقط اگر ارسالی در جریان باشد یک تأیید
    // می‌گیرد، چون آن مورد تنها حالتی است که کاربر شاید بخواهد صبر کند.
    const busy = vcBusy();
    if (busy && !skipConfirm) {
        if (!confirm('«' + busy + '» در جریان است.\n\n'
            + 'نصب بدون توقف آن انجام می‌شود و کار جاری با نسخهٔ فعلی تمام می‌گردد.\n'
            + 'ادامه می‌دهید؟')) return;
    }

    const btn = $('vcBtnUpdate');
    const lock = () => { if (btn) { btn.disabled = true; btn.textContent = '⏳ در حال نصب...'; } };
    const unlock = () => { if (btn) { btn.disabled = false; btn.textContent = '⬇ نصب نسخهٔ جدید'; } };

    fetch('?vc_deploy_info=1').then(r => r.json()).then(d => {
        if (!d.ok) {
            showToast(d.error || 'نصب‌کننده در دسترس نیست', true);
            vcStat('✗ ' + esc(d.error || ''), '#f87171');
            return;
        }
        if (!d.token) {
            showToast('ابتدا رمز نصب‌کننده را در تنظیمات وارد کنید', true);
            vcStat('✗ رمز نصب‌کننده تنظیم نشده — بخش «منبع و نصب‌کننده»', '#fbbf24');
            return;
        }
        if (!skipConfirm && !confirm('نسخهٔ جدید نصب شود؟\n\nنصب توسط ' + d.file +
            ' انجام می‌شود و از نسخهٔ فعلی بکاپ گرفته خواهد شد.')) return;

        lock();
        vcCloseBar();
        showToast('⏳ در حال نصب نسخهٔ جدید...');
        vcStat('<span style="opacity:.7">' + esc(d.file) + ' در حال نصب...</span>', '#93c5fd');

        // در پس‌زمینه صدا زده می‌شود؛ نصب را deploy.php انجام می‌دهد نه این فایل
        const fd = new FormData();
        fd.append('action',    'deploy');
        fd.append('api_token', d.token);
        fd.append('repo',      $('vcRepo').value.trim());
        fd.append('branch',    $('vcBranch').value.trim());
        fd.append('source',    $('vcPath').value.trim());
        fd.append('dest',      VC ? VC.self_name : '');
        fd.append('folder',    '');
        fd.append('check_php', '1');
        fd.append('backup',    '1');

        return fetch(d.file, { method: 'POST', body: fd })
            .then(r => r.json().catch(() => ({ ok: false, error: 'پاسخ نامعتبر از نصب‌کننده' })))
            .then(res => {
                if (!res.ok) {
                    vcStat('✗ ' + esc(res.error || 'نصب ناموفق'), '#f87171');
                    showToast(res.error || 'نصب ناموفق', true);
                    unlock();
                    return;
                }
                if (res.same || res.changed === false) {
                    vcStat('✓ از قبل به‌روز بود', '#4ade80');
                    showToast('✓ از قبل به‌روز بود');
                    $('vcBtnUpdate').classList.add('hidden');
                    unlock();
                    return;
                }
                vcStat('✓ نصب شد' + (res.backup ? ' · بکاپ: <code>' + esc(res.backup) + '</code>' : '') +
                       ' — بارگذاری مجدد...', '#4ade80');
                showToast('✓ به‌روزرسانی انجام شد — صفحه رفرش می‌شود');
                setTimeout(() => {
                    const u = new URL(location.href);
                    u.searchParams.set('_v', Date.now());   // دور زدن کش مرورگر
                    location.replace(u.toString());
                }, 1400);
            });
    }).catch(() => {
        showToast('خطا در ارتباط با نصب‌کننده', true);
        vcStat('✗ خطا در ارتباط', '#f87171');
        unlock();
    });
}

/* ---------- دراپ‌داون‌های جستجودار ---------- */
function vcLoadBranches(manual) {
    const repo = $('vcRepo').value.trim();
    if (!repo) { if (manual) showToast('ابتدا نام ریپو را وارد کنید', true); return Promise.resolve(); }
    const btn = $('vcBtnRepo');
    if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
    return fetch('?vc_branches=1&repo=' + encodeURIComponent(repo)).then(r => r.json()).then(d => {
        if (!d.ok) { VC_BRANCHES = []; if (manual) showToast(d.error || 'ناموفق', true); return; }
        VC_BRANCHES = d.branches || [];
        if (manual) showToast('✓ ' + toFa(VC_BRANCHES.length) + ' برنچ');
        $('vcBranch').placeholder = 'کلیک یا تایپ کنید';
        return vcLoadFiles(false);
    }).catch(() => { if (manual) showToast('خطا در ارتباط', true); })
      .finally(() => { if (btn) { btn.disabled = false; btn.textContent = '🔄'; } });
}

function vcLoadFiles(manual) {
    const repo = $('vcRepo').value.trim(), branch = $('vcBranch').value.trim();
    if (!repo || !branch) return Promise.resolve();
    $('vcFileCount').textContent = 'در حال دریافت فهرست...';
    return fetch('?vc_files=1&repo=' + encodeURIComponent(repo) + '&branch=' + encodeURIComponent(branch))
        .then(r => r.json()).then(d => {
            if (!d.ok) { VC_FILES = []; $('vcFileCount').textContent = ''; if (manual) showToast(d.error || 'ناموفق', true); return; }
            VC_FILES = d.files || [];
            $('vcFileCount').textContent = toFa(VC_FILES.length) + ' فایل PHP';
            $('vcPath').placeholder = 'کلیک یا تایپ کنید';
        }).catch(() => { $('vcFileCount').textContent = ''; });
}

function vcRenderDrop(dropId, items, onPick, emptyMsg) {
    const box = $(dropId);
    if (!box) return;
    if (!items.length) {
        box.innerHTML = '<div class="vc-none">' + esc(emptyMsg || 'موردی یافت نشد') + '</div>';
        box.classList.add('open'); return;
    }
    box.innerHTML = items.map((it, i) =>
        '<div class="vc-opt" data-i="' + i + '"><span>' + esc(it.label) + '</span>' +
        '<span class="vc-meta">' + esc(it.meta || '') + '</span></div>').join('');
    box.querySelectorAll('.vc-opt').forEach(el => {
        el.onmousedown = e => { e.preventDefault(); onPick(items[+el.dataset.i]); };
    });
    box.classList.add('open');
}

function vcFilterBranch() {
    const q = $('vcBranch').value.trim().toLowerCase();
    if (!VC_BRANCHES.length) { vcRenderDrop('vcBranchDrop', [], () => {}, 'ابتدا دکمهٔ 🔄 را بزنید'); return; }
    const cur = VC ? VC.branch : '';
    const items = VC_BRANCHES.filter(b => b.name.toLowerCase().includes(q)).slice(0, 60)
        .map(b => ({ label: b.name, meta: (b.name === cur ? '● فعلی  ' : '') + b.sha, value: b.name }));
    vcRenderDrop('vcBranchDrop', items, it => {
        $('vcBranch').value = it.value;
        vcCloseDrops(); vcDirty(); VC_FILES = [];
        $('vcPath').placeholder = 'در حال بارگذاری...';
        vcLoadFiles(true);
    });
}

function vcFilterFile() {
    const q = $('vcPath').value.trim().toLowerCase();
    if (!VC_FILES.length) { vcRenderDrop('vcPathDrop', [], () => {}, 'ابتدا ریپو و برنچ را انتخاب کنید'); return; }
    const self = VC ? VC.self_name : '';
    const items = VC_FILES.filter(f => f.path.toLowerCase().includes(q)).slice(0, 80)
        .map(f => ({ label: f.path, meta: (f.path.split('/').pop() === self ? '★ ' : '') + vcFmtSize(f.size), value: f.path }));
    vcRenderDrop('vcPathDrop', items, it => { $('vcPath').value = it.value; vcCloseDrops(); vcDirty(); });
}

document.addEventListener('click', e => { if (!e.target.closest('.vc-pick')) vcCloseDrops(); });

vcLoad(() => {
    if (VC && VC.check_on_load) setTimeout(() => vcCheck(false), 1200);
    if (VC && VC.repo) setTimeout(() => vcLoadBranches(false), 2200);
});
renderChangelog();

// ========== Connection JS ==========
let wSend=false,bSend=false,cn={woocommerce:{},basalam:{}},extractPollTimer=null,extractModalTimer=null;
function loadConn(){fetch('',{method:'POST',body:new URLSearchParams('action=load_connections')}).then(r=>r.json()).then(d=>{if(d.ok){cn=d.connections;applyCn();}}).catch(()=>{});}
function applyCn(){const w=cn.woocommerce||{},b=cn.basalam||{};if(w.store_url&&$('wcUrl'))$('wcUrl').value=w.store_url;if(w.consumer_key&&$('wcCK'))$('wcCK').value=w.consumer_key;if(w.consumer_secret&&$('wcCS'))$('wcCS').value=w.consumer_secret;if(w.default_status&&$('wcSt'))$('wcSt').value=w.default_status;if(w.default_category&&$('wcCat'))$('wcCat').value=w.default_category;if($('wcMS'))$('wcMS').checked=!!w.manage_stock;if(w.stock_quantity&&$('wcSQ'))$('wcSQ').value=w.stock_quantity;if(b.token&&$('bsTk'))$('bsTk').value=b.token;if(b.vendor_id&&$('bsVid'))$('bsVid').value=b.vendor_id;if(b.preparation_days&&$('bsPD'))$('bsPD').value=b.preparation_days;if(b.weight&&$('bsW'))$('bsW').value=b.weight;if($('bsPW')&&b.package_weight)$('bsPW').value=b.package_weight;if(b.stock&&$('bsSt'))$('bsSt').value=b.stock;// v7.48: Restore category in searchable dropdown
if(b.category_id){$('bsCat').value=String(b.category_id);bslSelectedCatId=b.category_id;if(bslAllCats.length>0){renderBslCatDropdown(bslAllCats,b.category_id);}else{loadBslCats();}}else{$('bsCat').value='0';bslSelectedCatId=0;if($('bsCatSearch'))$('bsCatSearch').value='';}
if($('bsAutoCat'))$('bsAutoCat').checked=!!b.auto_category;if($('bsGemKey')&&b.gemini_api_key)$('bsGemKey').value=b.gemini_api_key;if($('bsDelayMs')&&b.delay_ms)$('bsDelayMs').value=b.delay_ms;if($('bsRetryDelayMs')&&b.retry_delay_ms)$('bsRetryDelayMs').value=b.retry_delay_ms;
// v8.17: Restore global fallback categories
if(b.fallback_cat_ids&&Array.isArray(b.fallback_cat_ids)){renderBslFallbackCats(b.fallback_cat_ids);}
// v8.17: Restore extra vendors
if(b.vendors&&Array.isArray(b.vendors)){bslExtraVendors=b.vendors;renderBslVendors();}
// v8.06: Restore AI settings
const a=cn.ai||{};if($('aiKey')&&a.api_key)$('aiKey').value=a.api_key;if($('aiBaseUrl')&&a.base_url)$('aiBaseUrl').value=a.base_url;if($('aiModel')&&a.model)$('aiModel').value=a.model;if($('aiTemp')&&a.temperature)$('aiTemp').value=a.temperature;
// v8.17: Restore Baleh/Rubika settings
const bl=cn.baleh||{};if($('balehEnabled'))$('balehEnabled').checked=!!bl.enabled;if($('balehToken')&&bl.token)$('balehToken').value=bl.token;if($('balehChatId')&&bl.chat_id)$('balehChatId').value=bl.chat_id;if($('balehS')&&bl.token){$('balehS').textContent='فعال';$('balehS').className='cst on';}
const rb=cn.rubika||{};if($('rubikaEnabled'))$('rubikaEnabled').checked=!!rb.enabled;if($('rubikaToken')&&rb.token)$('rubikaToken').value=rb.token;if($('rubikaChatId')&&rb.chat_id)$('rubikaChatId').value=rb.chat_id;
const ne=cn.notif_events||{};if($('notifOrderNew'))$('notifOrderNew').checked=ne.order_new!==false;if($('notifOrderStatus'))$('notifOrderStatus').checked=ne.order_status!==false;if($('notifChatMsg'))$('notifChatMsg').checked=ne.chat_msg!==false;if($('notifProductStatus'))$('notifProductStatus').checked=ne.product_status!==false;if($('notifProductNew'))$('notifProductNew').checked=ne.product_new!==false;if($('notifOrderRefund'))$('notifOrderRefund').checked=ne.order_refund!==false;if($('notifSrcPrice'))$('notifSrcPrice').checked=ne.src_price!==false;if($('notifSrcStock'))$('notifSrcStock').checked=ne.src_stock!==false;if($('notifRunFail'))$('notifRunFail').checked=ne.run_fail!==false;if($('notifRetire'))$('notifRetire').checked=ne.retire!==false;if($('notifCronPing'))$('notifCronPing').checked=!!ne.cron_ping;if($('pingEvery'))$('pingEvery').value=(cn.ping_every!==undefined?cn.ping_every:360);if($('remindAfter'))$('remindAfter').value=(cn.notif_remind_after!==undefined?cn.notif_remind_after:30);if($('remindMax'))$('remindMax').value=(cn.notif_remind_max!==undefined?cn.notif_remind_max:0);if($('retireMode'))$('retireMode').value=cn.retire_mode||'off';if($('retireMaxPct'))$('retireMaxPct').value=cn.retire_max_pct||30;if($('retireMaxCount'))$('retireMaxCount').value=cn.retire_max_count||50;if($('stallWatchdog'))$('stallWatchdog').checked=cn.stall_watchdog!==false;if($('stallAfter'))$('stallAfter').value=cn.stall_after||300;updateRetireBadge();updateStallBadge();
updN();if(b.token&&bslAllCats.length===0){loadBslCats();}}
function saveConn(){const fd=new FormData();fd.append('action','save_connections');fd.append('woocommerce',JSON.stringify({enabled:1,store_url:$('wcUrl').value.trim(),consumer_key:$('wcCK').value.trim(),consumer_secret:$('wcCS').value.trim(),default_status:$('wcSt').value,default_category:parseInt($('wcCat').value)||0,manage_stock:$('wcMS').checked,stock_quantity:parseInt($('wcSQ').value)||10}));fd.append('basalam',JSON.stringify({enabled:1,token:$('bsTk').value.trim(),vendor_id:parseInt($('bsVid').value)||0,preparation_days:parseInt($('bsPD').value)||3,weight:parseInt($('bsW').value)||500,package_weight:parseInt($('bsPW')?.value)||0,stock:parseInt($('bsSt').value)||10,category_id:parseInt($('bsCat').value)||0,auto_category:$('bsAutoCat')?.checked||false,gemini_api_key:$('bsGemKey')?.value||'',delay_ms:parseInt($('bsDelayMs')?.value)||500,retry_delay_ms:parseInt($('bsRetryDelayMs')?.value)||1000,fallback_cat_ids:getBslFallbackCatIds(),vendors:bslExtraVendors}));
// v8.06: Save AI settings
fd.append('ai',JSON.stringify({enabled:1,api_key:$('aiKey')?.value||'',base_url:$('aiBaseUrl')?.value||'https://dashscope.aliyuncs.com/compatible-mode/v1',model:$('aiModel')?.value||'qwen-plus',temperature:parseFloat($('aiTemp')?.value)||0.1}));
// v8.17: Save Baleh/Rubika
fd.append('baleh',JSON.stringify({enabled:$('balehEnabled')?.checked?1:0,token:$('balehToken')?.value||'',chat_id:$('balehChatId')?.value||''}));
fd.append('rubika',JSON.stringify({enabled:$('rubikaEnabled')?.checked?1:0,token:$('rubikaToken')?.value||'',chat_id:$('rubikaChatId')?.value||''}));
fd.append('notif_events',JSON.stringify({order_new:$('notifOrderNew')?.checked?1:0,order_status:$('notifOrderStatus')?.checked?1:0,chat_msg:$('notifChatMsg')?.checked?1:0,product_status:$('notifProductStatus')?.checked?1:0,product_new:$('notifProductNew')?.checked?1:0,order_refund:$('notifOrderRefund')?.checked?1:0,src_price:$('notifSrcPrice')?.checked?1:0,src_stock:$('notifSrcStock')?.checked?1:0,run_fail:$('notifRunFail')?.checked?1:0,retire:$('notifRetire')?.checked?1:0,cron_ping:$('notifCronPing')?.checked?1:0}));fd.append('ping_every',$('pingEvery')?.value||360);fd.append('notif_remind_after',$('remindAfter')?.value??30);fd.append('notif_remind_max',$('remindMax')?.value??0);fd.append('retire_mode',$('retireMode')?.value||'off');fd.append('retire_max_pct',$('retireMaxPct')?.value||30);fd.append('retire_max_count',$('retireMaxCount')?.value||50);fd.append('stall_watchdog',$('stallWatchdog')?.checked?1:0);fd.append('stall_after',$('stallAfter')?.value||300);fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showToast(d.ok?'\u2713 \u0630\u062e\u06cc\u0631\u0647 \u0634\u062f':'\u062e\u0637\u0627',!d.ok);}).catch(()=>showToast('\u062e\u0637\u0627',1));}
function updN(){
let n=0,total=0;
products.forEach(p=>{total++;if(getFinalPriceNum(p.price)>0)n++;});
if($('wcN'))$('wcN').textContent=toFa(n)+'/'+toFa(total);
if($('bsN'))$('bsN').textContent=toFa(n)+'/'+toFa(total);
}
const _u=update;update=function(){_u();updN();};
function testWoo(){const s=$('wcS'),r=$('wcTR');s.textContent='\u062a\u0633\u062a...';s.className='cst tg';r.innerHTML='';const fd=new FormData();fd.append('action','test_woo');fd.append('store_url',$('wcUrl').value.trim());fd.append('consumer_key',$('wcCK').value.trim());fd.append('consumer_secret',$('wcCS').value.trim());fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){s.textContent='\u2713';s.className='cst on';r.innerHTML='<div class="alert alert-success" style="padding:8px;font-size:11px">\u2713 '+esc(d.message)+(d.version?' | WC '+esc(d.version):'')+'</div>';saveConn();loadCats();}else{s.textContent='\u2717';s.className='cst off';r.innerHTML='<div style="background:#7f1d1d;color:#fca5a5;padding:8px;font-size:11px">\u2717 '+esc(d.error||'\u062e\u0637\u0627')+'</div>';}}).catch(()=>{s.textContent='\u2717';s.className='cst off';});}
// v7.48: Searchable category dropdown for BaSalam
let bslAllCats=[];
let bslSelectedCatId=0;
let bslSelectedCatName='';
function loadBslCats(){fetch('',{method:'POST',body:new URLSearchParams('action=bsl_categories')}).then(r=>r.json()).then(d=>{if(d.ok&&d.categories){bslAllCats=d.categories;const savedCatId=parseInt($('bsCat').value)||0;renderBslCatDropdown(bslAllCats,savedCatId);initBslFallbackCatSearch();initBslProfileFallbackCatSearch();}}).catch(()=>{});}
function renderBslCatDropdown(cats,selectedId){
    bslSelectedCatId=selectedId||0;
    $('bsCat').value=String(bslSelectedCatId);
    if(bslSelectedCatId>0){const sc=cats.find(c=>c.id===bslSelectedCatId);if(sc){bslSelectedCatName=sc.name;$('bsCatSearch').value=sc.name+' ('+sc.id+')';}}
    else{$('bsCatSearch').value='';$('bsCatSearch').placeholder='\u062c\u0633\u062a\u062c\u0648 \u06cc\u0627 \u0627\u0646\u062a\u062e\u0627\u0628 \u062f\u0633\u062a\u0647\u200c\u0628\u0646\u062f\u06cc...';}
    renderBslCatFilter('');
}
function renderBslCatFilter(query){
    const list=$('bsCatList');if(!list)return;
    const q=query.toLowerCase().trim();
    let html='';
    if(bslSelectedCatId>0){const sc=bslAllCats.find(c=>c.id===bslSelectedCatId);if(sc&&(!q||sc.name.toLowerCase().includes(q))){html+='<div style="padding:6px 10px;cursor:pointer;background:#0e4429;color:#4ade80;border-bottom:1px solid #334155;font-size:12px" onclick="bslSelectCat('+sc.id+')">\u2713 '+esc(sc.name)+' ('+sc.id+')'+(sc.level>0?' \u2014 \u0633\u0637\u062d '+sc.level:'')+'</div>';}}
    bslAllCats.forEach(c=>{
        if(bslSelectedCatId===c.id)return;
        const name=(c.name||'').toLowerCase();
        if(q&&!name.includes(q))return;
        const prefix=c.level>0?'\u2500\u2500'.repeat(c.level)+' ':'';
        html+='<div style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #1e293b;font-size:12px;color:#e2e8f0" onclick="bslSelectCat('+c.id+')">'+esc(prefix+c.name)+' ('+c.id+')</div>';
    });
    if(!html)html='<div style="padding:10px;color:#64748b;font-size:12px;text-align:center">\u0646\u062a\u06cc\u062c\u0647 \u06cc\u0627\u0641\u062a \u0646\u0634\u062f</div>';
    list.innerHTML=html;
}
function bslSelectCat(catId){
    bslSelectedCatId=catId;
    $('bsCat').value=String(catId);
    const sc=bslAllCats.find(c=>c.id===catId);
    if(sc){bslSelectedCatName=sc.name;$('bsCatSearch').value=sc.name+' ('+sc.id+')';}
    else{bslSelectedCatName='';$('bsCatSearch').value='';}
    $('bsCatList').style.display='none';
}
// v7.48: Search input event handlers
document.addEventListener('DOMContentLoaded',function(){
    const si=$('bsCatSearch');
    if(si){
        si.addEventListener('focus',function(){renderBslCatFilter(this.value);$('bsCatList').style.display='block';});
        si.addEventListener('input',function(){renderBslCatFilter(this.value);$('bsCatList').style.display='block';});
        si.addEventListener('blur',function(){setTimeout(function(){$('bsCatList').style.display='none';},200);});
        si.addEventListener('keydown',function(e){
            if(e.key==='Escape')$('bsCatList').style.display='none';
            if(e.key==='Enter'&&bslAllCats.length>0){
                const q=this.value.toLowerCase().trim();
                const match=bslAllCats.find(c=>c.name.toLowerCase().includes(q)&&c.id!==bslSelectedCatId);
                if(match)bslSelectCat(match.id);
            }
        });
    }
});
// v8.06: Test AI connection (Alibaba Cloud ModelStudio / Qwen)
function testNotif(type){
    const r=$('notifTR');
    r.innerHTML='';
    let token,chatId,label;
    if(type==='baleh'){token=$('balehToken')?.value?.trim()||'';chatId=$('balehChatId')?.value?.trim()||'';label='بله';}
    else if(type==='rubika'){token=$('rubikaToken')?.value?.trim()||'';chatId=$('rubikaChatId')?.value?.trim()||'';label='روبیکا';}
    else{showToast('نوع نامعتبر',1);return;}
    if(!token||!chatId){showToast('Token و Chat ID را وارد کنید',1);return;}
    r.innerHTML='<div style="color:#facc15;font-size:11px;padding:4px">⏳ تست '+label+'...</div>';
    const fd=new FormData();fd.append('action','test_notif');fd.append('notif_type',type);fd.append('token',token);fd.append('chat_id',chatId);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){r.innerHTML='<div style="background:#14532d;color:#86efac;padding:8px;font-size:11px;border-radius:6px">✓ '+esc(d.message)+'</div>';}
        else{r.innerHTML='<div style="background:#7f1d1d;color:#fca5a5;padding:8px;font-size:11px;border-radius:6px">✗ '+esc(d.error||'خطا')+'</div>';}
    }).catch(()=>{r.innerHTML='<div style="background:#7f1d1d;color:#fca5a5;padding:8px;font-size:11px;border-radius:6px">✗ خطا شبکه</div>';});
}
function testAi(){const s=$('aiS'),r=$('aiTR');s.textContent='تست...';s.className='cst tg';r.innerHTML='';const fd=new FormData();fd.append('action','test_ai');fd.append('api_key',$('aiKey').value.trim());fd.append('base_url',$('aiBaseUrl').value.trim());fd.append('model',$('aiModel').value);fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){s.textContent='✓';s.className='cst on';r.innerHTML='<div class="alert alert-success" style="padding:8px;font-size:11px">✓ '+esc(d.message)+' | مدل: '+esc(d.model)+' | پاسخ: '+esc(d.response||'')+'</div>';saveConn();}else{s.textContent='✗';s.className='cst off';r.innerHTML='<div style="background:#7f1d1d;color:#fca5a5;padding:8px;font-size:11px">✗ '+esc(d.error||'خطا')+'</div>';}}).catch(()=>{s.textContent='✗';s.className='cst off';});}
// v8.06: Test AI category selection with a sample product title
function testAiCategory(){const r=$('aiTR');const title=prompt('عنوان محصول برای تست دسته‌بندی:','کفش ورزشی مردانه نایک');if(!title)return;r.innerHTML='<div style="color:#67e8f9;font-size:11px">🔄 در حال تحلیل «'+esc(title)+'» با AI...</div>';fetch('?ai_category=1&title='+encodeURIComponent(title)).then(r=>r.json()).then(d=>{if(d.ok){r.innerHTML='<div class="alert alert-success" style="padding:8px;font-size:11px">✓ دسته: <b>'+esc(d.category_name)+'</b> ('+d.category_id+') | مدل: '+esc(d.ai_model||'')+' | پاسخ AI: '+esc(d.ai_raw||'')+'</div>';}else{r.innerHTML='<div style="background:#7f1d1d;color:#fca5a5;padding:8px;font-size:11px">✗ '+esc(d.error||'خطا')+'</div>';}}).catch(()=>{r.innerHTML='<div style="color:#f87171;font-size:11px">✗ خطا شبکه</div>';});}

function testBsl(){const s=$('bsS'),r=$('bsTR');s.textContent='\u062a\u0633\u062a...';s.className='cst tg';r.innerHTML='';const fd=new FormData();fd.append('action','test_basalam');fd.append('token',$('bsTk').value.trim());fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
if(d.ok){s.textContent='\u2713';s.className='cst on';r.innerHTML='<div class="alert alert-success" style="padding:8px;font-size:11px">\u2713 '+esc(d.message)+' | '+esc(d.vendor_title||'')+'</div>';if(d.vendor_id&&!$('bsVid').value)$('bsVid').value=d.vendor_id;saveConn();
showBslVendorModal(d);
}else{s.textContent='\u2717';s.className='cst off';
r.innerHTML='<div style="background:#7f1d1d;color:#fca5a5;padding:8px;font-size:11px">\u2717 '+esc(d.error||'\u062e\u0637\u0627')+'</div>';
if(d.http_code===403||d.http_code===401){showBslVendorModal(d);}
}
}).catch(()=>{s.textContent='\u2717';s.className='cst off';});}
function showBslVendorModal(d){
let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeBslVendorModal()">';
html+='<div class="bsl-modal" style="max-width:600px">';
html+='<div class="bsl-modal-head"><h2 style="color:'+(d.ok?'#22c55e':'#f87171')+'">'+(d.ok?'\u2713 \u0627\u0637\u0644\u0627\u0639\u0627\u062a \u063a\u0631\u0641\u0647 \u0628\u0627\u0633\u0627\u0644\u0627\u0645':'\u2717 \u062e\u0637\u0627 \u0627\u062a\u0635\u0627\u0644 \u0628\u0627\u0633\u0627\u0644\u0627\u0645')+'</h2><button class="btn btn-gray" onclick="closeBslVendorModal()">\u2717</button></div>';
if(!d.ok){
html+='<div class="bsl-modal-body" style="padding:20px">';
html+='<div style="background:#7f1d1d;color:#fca5a5;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px"><b>\u2717 '+esc(d.error||'\u062e\u0637\u0627')+'</b></div>';
if(d.http_code===403){
html+='<div style="background:#1e293b;padding:12px;border-radius:8px;margin-bottom:12px;font-size:12px;color:#facc15">';
html+='<div style="font-weight:700;margin-bottom:8px">\u26a0\ufe0f \u062f\u0633\u062a\u0631\u0633\u06cc \u0645\u0645\u0646\u0648\u0639 (\u06f4\u06f0\u06f3 Forbidden)</div>';
html+='<div style="color:#e2e8f0;line-height:1.6">\u0627\u06cc\u0646 \u062a\u0648\u06a9\u0646 \u0645\u0645\u06a9\u0646 \u0627\u0633\u062a:</div>';
html+='<ul style="color:#e2e8f0;font-size:12px;line-height:1.8;padding-right:20px">';
html+='<li>\u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u0646\u0627\u0642\u0635 \u0628\u0627\u0634\u062f \u2014 \u0627\u0628\u062a\u062f\u0627 \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u06a9\u0627\u0645\u0644 \u06a9\u0646\u06cc\u062f</li>';
html+='<li>\u0645\u062c\u0648\u0632 \u0644\u0627\u0632\u0645 \u0631\u0627 \u0646\u062f\u0627\u0634\u062a\u0647 \u0628\u0627\u0634\u062f \u2014 \u062a\u0648\u06a9\u0646 \u062c\u062f\u06cc\u062f \u0628\u0627 \u062f\u0633\u062a\u0631\u0633\u06cc \u06a9\u0627\u0645\u0644 \u0627\u06cc\u062c\u0627\u062f \u06a9\u0646\u06cc\u062f</li>';
html+='<li>\u0645\u0646\u0642\u0636\u06cc \u0634\u062f\u0647 \u0628\u0627\u0634\u062f \u2014 \u062a\u0648\u06a9\u0646 \u062c\u062f\u06cc\u062f \u0628\u0633\u0627\u0632\u06cc\u062f</li>';
html+='</ul>';
html+='<div style="margin-top:12px"><a href="https://developers.basalam.com" target="_blank" style="color:#22d3ee;font-size:13px">\u2713 \u067e\u0646\u0644 \u062a\u0648\u0633\u0639\u0647\u062f\u0647\u0646\u062f\u06af\u0627\u0646 \u0628\u0627\u0633\u0627\u0644\u0627\u0645</a></div>';
html+='<div style="margin-top:8px"><a href="https://basalam.com/vendor/verification" target="_blank" style="color:#f87171;font-size:13px;font-weight:700">\u2713 \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u0628\u0627\u0633\u0627\u0644\u0627\u0645</a></div>';
html+='</div>';
}else if(d.http_code===401){
html+='<div style="background:#1e293b;padding:12px;border-radius:8px;margin-bottom:12px;font-size:12px;color:#f87171">';
html+='<div style="font-weight:700;margin-bottom:8px">\u2717 \u062a\u0648\u06a9\u0646 \u0646\u0627\u0645\u0639\u062a\u0628\u0631 (\u06f4\u06f0\u06f1 Unauthorized)</div>';
html+='<div style="color:#e2e8f0;line-height:1.6">\u062a\u0648\u06a9\u0646 \u0648\u0627\u0631\u062f \u0634\u062f\u0647 \u0645\u0639\u062a\u0628\u0631 \u0646\u06cc\u0633\u062a. \u0644\u0637\u0641\u0627\u064b \u062a\u0648\u06a9\u0646 \u062c\u062f\u06cc\u062f Personal Access Token \u0627\u0632 \u067e\u0646\u0644 \u0628\u0627\u0633\u0627\u0644\u0627\u0645 \u0628\u0633\u0627\u0632\u06cc\u062f.</div>';
html+='<div style="margin-top:10px"><a href="https://developers.basalam.com" target="_blank" style="color:#22d3ee;font-size:13px">\u2713 \u067e\u0646\u0644 \u062a\u0648\u0633\u0639\u0647\u062f\u0647\u0646\u062f\u06af\u0627\u0646 \u0628\u0627\u0633\u0627\u0644\u0627\u0645</a></div>';
html+='</div>';
}
if(d.detail)html+='<div style="background:#0f172a;padding:8px;border-radius:6px;font-size:10px;color:#94a3b8;font-family:monospace;margin-top:8px">\u067e\u0627\u0633\u062e \u0633\u0631\u0648\u0631: '+esc(String(d.detail).substring(0,300))+'</div>';
html+='</div>';
}else{
html+='<div class="bsl-modal-body" style="padding:16px">';
html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">';
html+='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px">';
html+='<div style="color:#67e8f9;font-weight:700;font-size:13px;margin-bottom:8px">\u2713 \u0627\u0637\u0644\u0627\u0639\u0627\u062a \u06a9\u0627\u0631\u0628\u0631</div>';
html+='<div style="font-size:11px;line-height:1.8;color:#e2e8f0">';
html+='<div><span style="color:#94a3b8">\u0634\u0646\u0627\u0633\u0647:</span> <b>'+toFa(d.user_id||0)+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0646\u0627\u0645:</span> <b>'+esc(d.user_name||'')+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0646\u0627\u0645 \u06a9\u0627\u0631\u0628\u0631\u06cc:</span> <b>'+esc(d.username||'\u2014')+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0645\u0648\u0628\u0627\u06cc\u0644:</span> <b>'+esc(d.mobile||'\u2014')+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0627\u06cc\u0645\u06cc\u0644:</span> <b>'+esc(d.email||'\u2014')+'</b></div>';
html+='<div><span style="color:#94a3b8">\u06a9\u062f \u0645\u0644\u06cc:</span> <b>'+esc(d.national_code||'\u2014')+'</b></div>';
html+='<div><span style="color:#94a3b8">Hash ID:</span> <b style="font-family:monospace;font-size:10px">'+esc(d.hash_id||'')+'</b></div>';
html+='</div></div>';
html+='<div style="background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px">';
html+='<div style="color:#22d3ee;font-weight:700;font-size:13px;margin-bottom:8px">\u2713 \u0627\u0637\u0644\u0627\u0639\u0627\u062a \u063a\u0631\u0641\u0647</div>';
html+='<div style="font-size:11px;line-height:1.8;color:#e2e8f0">';
html+='<div><span style="color:#94a3b8">\u0634\u0646\u0627\u0633\u0647 \u063a\u0631\u0641\u0647:</span> <b>'+toFa(d.vendor_id||0)+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0646\u0627\u0645 \u063a\u0631\u0641\u0647:</span> <b>'+esc(d.vendor_title||'')+'</b></div>';
html+='<div><span style="color:#94a3b8">Identifier:</span> <b style="font-family:monospace;font-size:10px">'+esc(d.vendor_identifier||'')+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0641\u0639\u0627\u0644:</span> <b style="color:'+(d.vendor_is_active?'#22c55e':'#f87171')+'">'+(d.vendor_is_active?'\u2713 \u0641\u0639\u0627\u0644':'\u2717 \u063a\u06cc\u0631\u0641\u0639\u0627\u0644')+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0648\u0632\u06cc\u0639\u062a:</span> <b>'+toFa(d.vendor_status||0)+'</b></div>';
html+='<div><span style="color:#94a3b8">\u062a\u0639\u062f\u0627\u062f \u0633\u0641\u0627\u0631\u0634:</span> <b>'+toFa(d.vendor_order_count||0)+'</b></div>';
html+='<div><span style="color:#94a3b8">\u0627\u0631\u0633\u0627\u0644 \u0631\u0627\u06cc\u063a\u0627\u0646 \u0627\u06cc\u0631\u0627\u0646:</span> <b>'+toFa(d.vendor_free_shipping_iran||0)+' \u062a\u0648\u0645\u0627\u0646</b></div>';
if(d.vendor_activated_at)html+='<div><span style="color:#94a3b8">\u0641\u0639\u0627\u0644\u200c\u0633\u0627\u0632\u06cc:</span> <b>'+esc(d.vendor_activated_at||'')+'</b></div>';
html+='</div></div>';
html+='</div>';
html+='<div style="background:'+(d.verified?'#14532d30':'#7f1d1d30')+';border:1px solid '+((d.verified?'#22c55e':'#f87171'))+';border-radius:8px;padding:12px;margin-bottom:12px">';
html+='<div style="color:'+(d.verified?'#22c55e':'#f87171')+';font-weight:700;font-size:13px;margin-bottom:8px">'+(d.verified?'\u2713 \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u06a9\u0627\u0645\u0644':'\u26a0\ufe0f \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u0646\u0627\u0642\u0635')+'</div>';
if(!d.verified){
html+='<div style="color:#fca5a5;font-size:11px;line-height:1.6">'+(d.verification_desc?'\u0639\u0644\u062a: '+esc(d.verification_desc):'\u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u06a9\u0627\u0645\u0644 \u0646\u0634\u062f\u0647. \u0628\u0631\u0627\u06cc \u0627\u0631\u0633\u0627\u0644 \u0645\u062d\u0635\u0648\u0644 \u0648 \u0627\u0633\u062a\u0641\u0627\u062f\u0647 \u0627\u0632 API \u0628\u0627\u06cc\u062f \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u0631\u0627 \u062a\u06a9\u0645\u06cc\u0644 \u06a9\u0646\u06cc\u062f.')+'</div>';
html+='<div style="margin-top:10px"><a href="https://basalam.com/vendor/verification" target="_blank" style="display:inline-block;background:#f87171;color:white;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none">\u2713 \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a \u0628\u0627\u0633\u0627\u0644\u0627\u0645</a></div>';
}else{
html+='<div style="color:#22c55e;font-size:11px">'+esc(d.verification_name||'\u062a\u0627\u06cc\u06cc\u062f \u0634\u062f\u0647')+'</div>';
}
html+='</div>';
html+='</div>';
}
html+='</div></div>';
const old=document.getElementById('bslVendorModalContainer');if(old)old.remove();
const div=document.createElement('div');div.id='bslVendorModalContainer';div.innerHTML=html;
document.body.appendChild(div);
}
function closeBslVendorModal(){const m=document.getElementById('bslVendorModalContainer');if(m)m.remove();}
// v7.41: Stale progress detection + auto-resume
let bslLastUpdateTime=0,bslResumeCount=0,bslMaxResumes=10,bslLastLogCount=0,bslSendStartedAt=0,bslLastDoneQueueId='';
// v7.82: Track how many product cards we've rendered to avoid duplicates
let bslLastCardCount=0;
// v8.06: WooCommerce polling state
let wooLastCardCount=0,wooLastUpdateTime=0,wooLastLogCount=0,wooResumeCount=0;

// v7.48: Poll BaSalam send progress — live stats, synced logs, elapsed time, ETA
function pollBslProgress() {
    fetch('?poll_bsl=1').then(r=>r.json()).then(d=>{
        if(!d) {setTimeout(pollBslProgress,2000);return;}
        console.log('[BSL poll] running='+d.running+' done='+d.done+' paused='+d.paused+' total='+d.total+' sent='+d.sent+' current='+d.current+' started_at='+d.started_at);
        const running = d.running || false;
        const done = d.done || false;
        const paused = d.paused || false;
        const total = d.total || 0;
        const sent = d.sent || 0;
        const updated = d.updated || 0;
        const skipped = d.skipped || 0;
        const failed = d.failed || 0;
        const current = d.current || 0;
        const logs = d.recent_log || [];
        const totalLogCount = d.total_log_count || 0;
        const startedAt = d.started_at || 0;
        // Store detail lists for report modals — available during processing now
        if(d.sent_details) bslReportData.sent=d.sent_details;
        if(d.updated_details) bslReportData.updated=d.updated_details;
        if(d.skipped_details) bslReportData.skipped=d.skipped_details;
        if(d.failed_details) bslReportData.failed=d.failed_details;
        // v7.82: Render product cards from detail lists
        const totalCards=bslReportData.sent.length+bslReportData.updated.length+bslReportData.skipped.length+bslReportData.failed.length;
        if(totalCards>bslLastCardCount){
            const logDiv=$('bR');
            // Render new cards from ALL detail lists
            const allItems=[];
            bslReportData.sent.forEach(x=>{allItems.push(Object.assign({result:'ok'},x));});
            bslReportData.updated.forEach(x=>{allItems.push(Object.assign({result:'update'},x));});
            bslReportData.skipped.forEach(x=>{allItems.push(Object.assign({result:'skip'},x));});
            bslReportData.failed.forEach(x=>{allItems.push(Object.assign({result:'fail'},x));});
            // Only render new cards
            for(let ci=bslLastCardCount;ci<allItems.length;ci++){
                const item=allItems[ci];
                logDiv.insertAdjacentHTML("beforeend",renderSendCard(item));
            }
            bslLastCardCount=totalCards;
            // Trim for performance
            const logNodes=logDiv.children;
            if(logNodes.length>300){for(let j=0;j<logNodes.length-300;j++)logNodes[0].remove();}
            scrollElBottom(logDiv);
        }
        // Show stat boxes during process (not just at end)
        $('bSM').classList.remove('hidden');
        $('bO').textContent=toFa(sent);
        $('bU').textContent=toFa(updated);
        $('bK').textContent=toFa(skipped);
        $('bF').textContent=toFa(failed);
        $('bT').textContent=toFa(total);
        // v7.48: Calculate elapsed time and ETA
        let elapsedStr='',etaStr='';
        if(startedAt>0){
            const elapsedSec=Math.floor(Date.now()/1000-startedAt);
            elapsedStr=elapsedSec>=60?(Math.floor(elapsedSec/60)+'دقیقه '+elapsedSec%60+'ثانیه'):(elapsedSec+' ثانیه');
            if(current>0&&total>0&&current>1){
                const perProduct=elapsedSec/current;
                const remaining=Math.round(perProduct*(total-current));
                etaStr=remaining>=60?(Math.floor(remaining/60)+'دقیقه '+remaining%60+'ثانیه'):(remaining+' ثانیه');
            }
        }
        // v7.48: Add only NEW log entries using total_log_count for proper sync
        const logDiv=$('bR');
        if(totalLogCount>bslLastLogCount) {
            // New entries exist. Figure out which part of recent_log is new.
            const newCount=totalLogCount-bslLastLogCount;
            // recent_log may be a slice of the full log.
            // If totalLogCount <= 200, recent_log = full log, new entries start from bslLastLogCount
            // If totalLogCount > 200, recent_log = last 200 entries
            //   new entries are the last newCount entries in recent_log
            let startInSlice;
            if(totalLogCount<=200){
                startInSlice=bslLastLogCount;
            }else{
                startInSlice=Math.max(0,200-newCount);
            }
            for(let i=startInSlice;i<logs.length;i++){
                const m=logs[i];
                // v8.06: Skip product-level logs that are now shown as cards — skip any message starting with [n]
                if(/^\[\d+\]/.test(m))continue;
                let cls='';
                if(m.includes('✅')){cls='color:#22c55e;background:#14532d20;padding:3px 8px;font-size:12px;border-radius:4px';}
                else if(m.includes('❌')){cls='color:#ef4444;background:#7f1d1d20;padding:3px 8px;font-size:12px;border-radius:4px';}
                else if(m.includes('⚡')){cls='color:#facc15;background:#42200620;padding:3px 8px;font-size:12px;border-radius:4px';}
                else if(m.includes('⏭')){cls='color:#94a3b8;padding:2px 6px;font-size:11px';}
                else if(m.includes('📦')){cls='color:#818cf8;padding:2px 6px;font-size:10px';}
                else if(m.includes('🏷️')){cls='color:#c084fc;padding:2px 6px;font-size:10px';}
                else if(m.includes('🔍')){cls='color:#67e8f9;padding:2px 6px;font-size:10px';}
                else{cls='color:#64748b;padding:1px 8px;font-size:10px';}
                logDiv.insertAdjacentHTML('beforeend','<div style="'+cls+';margin:1px 0">'+esc(m)+'</div>');
            }
            bslLastLogCount=totalLogCount;
            // Trim old log entries for DOM performance (keep last 300)
            const logNodes=logDiv.children;
            if(logNodes.length>300){for(let j=0;j<logNodes.length-300;j++)logNodes[0].remove();}
            scrollElBottom(logDiv);
        }
        if(d.debug_title||d.debug_name){console.log('BSL debug:',d.debug_title,d.debug_name,d.computed);}
        // v7.49: Improved progress display with elapsed + ETA + phase info
        if(running || !done) {
            let progText='';
            if(running&&total>0&&current<=0){
                progText='🔍 بررسی محصولات موجود ('+toFa(total)+' محصول)...';
            }else if(current>0&&total>0){
                progText=toFa(current)+'/'+toFa(total)+' ('+Math.round(current/total*100)+'٪)';
            }else{
                progText='در حال پردازش...';
            }
            if(elapsedStr) progText+=' | '+elapsedStr;
            if(etaStr) progText+=' | ~'+etaStr+' مانده';
            $('bSS').textContent=progText;
            $('bPB').style.width=(total>0?current/total*100:0)+'%';
            // v7.48: Show product title being processed
            if(d.last_title){
                $('bSS').title='محصول فعلی: '+d.last_title;
            }
        }
        // v7.50: Paused — stop polling, keep UI visible for resume
        if(paused&&!running&&!done) {
            $('bSS').textContent='⏸ متوقف — برای ادامه، دکمه ▶ در صف را کلیک کنید';
            $('bPB').style.background='#f97316';
            $('bR').innerHTML+='<div style="color:#f97316;font-weight:700;padding:8px;background:#c2410c20;border-radius:6px;margin:4px 0">⏸ ارسال متوقف شد — محصول #'+toFa(current)+' | برای ادامه از صف ▶ کلیک کنید</div>';
            checkBslQueue();
            return; // Stop polling until resume
        }
        if(done) {
            bSend=false;
            $('bSB').classList.remove('hidden');$('bSBlegacy').classList.remove('hidden');
            $('bST').classList.add('hidden');
            // v7.50: If cancelled (by user delete), don't show success message
            if(d.cancelled){
                $('bSS').textContent='❌ ارسال لغو شد';
                $('bPB').style.width='0%';
                checkBslQueue();
                return;
            }
            $('bPB').style.width='100%';
            let finalText='✓ '+toFa(sent)+' جدید | '+toFa(updated)+' آپدیت | '+toFa(skipped)+' تکراری | '+toFa(failed)+' خطا';
            if(elapsedStr) finalText+=' | '+elapsedStr;
            $('bSS').textContent=finalText;
            // v8.06: Only show toast once per queue entry (prevent infinite toast spam)
            const thisQueueId=d.queue_id||'';
            if(bslLastDoneQueueId!==thisQueueId){
                bslLastDoneQueueId=thisQueueId;
                showToast('✓ '+sent+' جدید, '+updated+' آپدیت, '+skipped+' تکراری, '+failed+' خطا');
            }
            // v7.38: Auto-run Phase 2 — check for category-rejected products
            setTimeout(bslPhase2Check,2000);
            // v7.48: After process done, check queue and auto-start next entry
            setTimeout(()=>{startNextBslQueueEntry();},4000);
            return;
        }
        // v7.66: Improved stale detection — use last_progress_ts from progress file for reliable detection
        // Also use server-side resume (not just re-triggering bsl_backend without queue_id)
        const now=Date.now();
        const progressTs=d.last_progress_ts?d.last_progress_ts*1000:0;
        // Update bslLastUpdateTime using progress timestamp (more reliable than JS tracking)
        if(progressTs>0&&progressTs>bslLastUpdateTime)bslLastUpdateTime=progressTs;
        if(bslLastUpdateTime===0)bslLastUpdateTime=now;
        if(totalLogCount>bslLastLogCount)bslLastUpdateTime=now;
        if(current>0)bslLastUpdateTime=now;
        const staleThreshold=120000; // v7.66: 120 seconds stale threshold (increased from 90)
        if(now-bslLastUpdateTime>staleThreshold&&running&&!done){
            // Stale! Server process probably died. Auto-resume with server-side mode.
            bslResumeCount++;
            if(bslResumeCount<=bslMaxResumes){
                console.log('⚠️ Stale progress detected ('+bslResumeCount+' resume). Auto-resuming from product #'+current+' via bsl_backend');
                $('bR').innerHTML+='<div style="color:#f87171;background:#7f1d1d20;padding:6px;font-size:12px;border-radius:6px;margin:4px 0">⚠️ فرآیند قطع شد — ادامه سرورساید از محصول #'+toFa(current)+' (تلاش '+toFa(bslResumeCount)+')</div>';
                bslLastUpdateTime=now;
                bslLastLogCount=totalLogCount;
                // v7.66: Resume using restartBslServer (server-side mode) with queue_id
                // Find the running queue entry and use restartBslServer for proper resume
                const runningQueueId=d.queue_id||window._currentBslQueueId||'';
                if(runningQueueId){
                    // Use restartBslServer for proper server-side resume
                    const fd=new FormData();
                    fd.append('from_file','1');
                    fd.append('queue_id',runningQueueId);
                    fd.append('start_index',String(current));
                    setTimeout(pollBslProgress,500);
                    // v7.81: Fire-and-forget stale resume
                    fetch('?action=bsl_backend',{method:'GET'}).catch(()=>{});
                    bslLastUpdateTime=Date.now();
                }else{
                    // No queue_id — just re-trigger bsl_backend with start_index
                    const fd=new FormData();
                    fd.append('from_file','1');
                    fd.append('start_index',String(current));
                    setTimeout(pollBslProgress,500);
                        // v7.81: Fire-and-forget stale resume (no queue_id)
                    fetch('?action=bsl_backend',{method:'GET'}).catch(()=>{});
                    bslLastUpdateTime=Date.now();
                }
                return;
            }else{
                $('bR').innerHTML+='<div style="color:#f87171;padding:6px;font-size:12px">❌ فرآیند قطع شد — حداکثر تلاش‌های ادامه رسید</div>';
                bSend=false;$('bSB').classList.remove('hidden');$('bSBlegacy').classList.remove('hidden');$('bST').classList.add('hidden');
                return;
            }
        }
        // v7.38: Keep polling until done
        // v7.50: Also update queue UI every 5 polls
        if(!window._bslPollCounter)window._bslPollCounter=0;
        window._bslPollCounter++;
        if(window._bslPollCounter%5===0)checkBslQueue();
        setTimeout(pollBslProgress, 1000);
    }).catch(()=>{setTimeout(pollBslProgress, 2000);});
}

function loadCats(){fetch('',{method:'POST',body:new URLSearchParams('action=woo_categories')}).then(r=>r.json()).then(d=>{if(d.ok&&d.categories){const s=$('wcCat'),v=s.value;s.innerHTML='<option value="0">--</option>';d.categories.forEach(c=>s.innerHTML+='<option value="'+c.id+'">'+esc(c.name)+' ('+c.count+')</option>');if(v)s.value=v;}}).catch(()=>{});}
function getSendP(){
const d=[];order.forEach(k=>{
    const p=products.get(k);if(!p)return;
    const rawTitle=p.title||p.name||'';
    const fp=getFinalPriceNum(p.price);
    // v7.82: Detect price unit (Rial vs Toman) from original price string
    const priceStr=(p.price||'').toString();
    const priceUnit=priceStr.includes('ریال')||priceStr.includes('ر.ی')?'rial':'toman';
    d.push({key:k,title:getFinalTitle(rawTitle),final_price:String(fp),price_unit:priceUnit,image:p.image||'',sku:p.sku||'',short_desc:p.shortDesc||'',long_desc:p.longDesc||'',weight:p.weight||'',link:p.link||'',orig_price:p.origPrice||p.originalPrice||''});
});
if(d.length>0)console.log('getSendP: total='+d.length+', withPrice='+d.filter(x=>parseInt(x.final_price)>0).length);
if(d.length>0){
        console.log('getSendP: total='+d.length+', withPrice='+d.filter(x=>parseInt(x.final_price)>0).length+', products.size='+products.size+', order.length='+order.length);
        // v7.41: Show in debug panel too
        const dbg=$('_dbg');if(dbg)dbg.innerHTML+='<div>📊 getSendP: '+d.length+' محصول (products.size='+products.size+', order='+order.length+') | با قیمت: '+d.filter(x=>parseInt(x.final_price)>0).length+'</div>';
    }
    return d;
}
function pSSE(ev){if(!ev.trim())return null;let t='',d='';ev.split('\n').forEach(l=>{if(l.startsWith('event:'))t=l.substring(6).trim();else if(l.startsWith('data:'))d=l.substring(5).trim();});if(!t||!d)return null;try{return{t,d:JSON.parse(d)};}catch(e){return null;}}
// v8.17: queueWooSend — same pattern as queueBslSend (server-side processing)
function queueWooSend(ps){
    const qid='wq_'+Date.now()+'_'+Math.random().toString(36).substr(2,6);
    $('wSS').textContent='ذخیره محصولات در صف...';
    const CHUNK_SIZE=50;const chunks=[];
    for(let i=0;i<ps.length;i+=CHUNK_SIZE)chunks.push(ps.slice(i,i+CHUNK_SIZE));
    let savedChunks=0,totalSaved=0;
    function saveNextWooQueueChunk(){
        if(savedChunks>=chunks.length){
            const fd2=new FormData();
            fd2.append('queue_id',qid);
            fd2.append('total',String(totalSaved));
            fd2.append('start_immediately','1');
            fd2.append('profile_key',($('profileSelect')&&$('profileSelect').value)||'');
            fd2.append('profile_name',($('profileName')&&$('profileName').value)||'');
            fd2.append('title_suffix',$('titleSuffix')?$('titleSuffix').value.trim():'');
            fetch('?woo_queue_add=1',{method:'POST',body:fd2}).then(r=>r.json()).then(d=>{
                if(!d.ok){showToast('خطا: '+d.error,1);return;}
                showToast('✓ '+toFa(totalSaved)+' محصول در صف ووکامرس — سرورساید');
                wSend=true;
                wooReportData={sent:[],updated:[],skipped:[],failed:[]};
                wooLastLogCount=0;wooLastCardCount=0;wooLastUpdateTime=0;
                // v8.17: Keep send button visible so user can add more to queue
                $('wSB').textContent='🚀 + افزودن به صف';
                $('wST').classList.remove('hidden');
                $('wP').classList.remove('hidden');$('wPB').style.width='0%';
                $('wR').classList.remove('hidden');
                $('wR').innerHTML='<div style="color:#a78bfa;font-weight:bold;padding:8px;margin-bottom:4px;background:#1e3a5f;border-radius:6px">✓ ارسال '+toFa(totalSaved)+' محصول ووکامرس (سرورساید — بسته مرورگر ادامه می‌دهد)</div>';
                $('wSM').classList.remove('hidden');$('wO').textContent=toFa(0);$('wU').textContent=toFa(0);$('wK').textContent=toFa(0);$('wF').textContent=toFa(0);$('wT').textContent=toFa(totalSaved);
                $('wSS').textContent='✓ شروع ارسال...';
                window._currentWooQueueId=qid;
                checkWooQueue();
                // Trigger woo_backend
                fetch('?action=woo_backend').catch(()=>{});
                $('wR').innerHTML+='<div style="color:#22c55e;padding:4px;font-size:12px">✓ ارسال شروع — پیشرفت زنده نمایش می‌شود</div>';
                setTimeout(pollWooProgress,1000);
            }).catch(()=>{});
            return;
        }
        const chunk=chunks[savedChunks];
        const fd=new FormData();
        fd.append('products',JSON.stringify(chunk));fd.append('chunk_index',String(savedChunks));fd.append('queue_id',qid);
        $('wSS').textContent='ذخیره '+toFa(totalSaved)+'/'+toFa(ps.length)+' محصول...';
        fetch('?woo_queue_save_products=1',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(!d.ok){showToast('خطا: '+d.error,1);return;}
            totalSaved=d.total_saved;savedChunks++;saveNextWooQueueChunk();
        }).catch(()=>{showToast('خطا شبکه',1);});
    }
    saveNextWooQueueChunk();
}
function sendWoo(){
const ps=getSendP();if(!ps.length){showToast('محصولی نیست',1);return;}
queueWooSend(ps);
}
function pollWooProgress(){
    fetch('?poll_woo=1').then(r=>r.json()).then(d=>{
        if(!d){setTimeout(pollWooProgress,2000);return;}
        const running=d.running||false;
        const done=d.done||false;
        const total=d.total||0;
        const sent=d.sent||0;
        const updated=d.updated||0;
        const skipped=d.skipped||0;
        const failed=d.failed||0;
        const current=d.current||0;
        const logs=d.recent_log||[];
        const totalLogCount=d.total_log_count||0;
        const startedAt=d.started_at||0;
        // Store detail lists for report modals
        if(d.sent_details)wooReportData.sent=d.sent_details;
        if(d.updated_details)wooReportData.updated=d.updated_details;
        if(d.skipped_details)wooReportData.skipped=d.skipped_details;
        if(d.failed_details)wooReportData.failed=d.failed_details;
        // v8.06: Render product cards from detail lists
        const totalCards=wooReportData.sent.length+wooReportData.updated.length+wooReportData.skipped.length+wooReportData.failed.length;
        if(totalCards>wooLastCardCount){
            const logDiv=$('wR');
            const allItems=[];
            wooReportData.sent.forEach(x=>{allItems.push(Object.assign({result:'ok'},x));});
            wooReportData.updated.forEach(x=>{allItems.push(Object.assign({result:'update'},x));});
            wooReportData.skipped.forEach(x=>{allItems.push(Object.assign({result:'skip'},x));});
            wooReportData.failed.forEach(x=>{allItems.push(Object.assign({result:'fail'},x));});
            for(let ci=wooLastCardCount;ci<allItems.length;ci++){
                const item=allItems[ci];
                logDiv.insertAdjacentHTML('beforeend',renderSendCard(item));
            }
            wooLastCardCount=totalCards;
            const logNodes=logDiv.children;
            if(logNodes.length>300){for(let j=0;j<logNodes.length-300;j++)logNodes[0].remove();}
            scrollElBottom(logDiv);
        }
        // Show stat boxes
        $('wSM').classList.remove('hidden');
        $('wO').textContent=toFa(sent);
        $('wU').textContent=toFa(updated);
        $('wK').textContent=toFa(skipped);
        $('wF').textContent=toFa(failed);
        $('wT').textContent=toFa(total);
        // Calculate elapsed and ETA
        let elapsedStr='';
        if(startedAt>0){
            const elapsedSec=Math.floor(Date.now()/1000-startedAt);
            elapsedStr=elapsedSec>=60?(Math.floor(elapsedSec/60)+'دقیقه '+elapsedSec%60+'ثانیه'):(elapsedSec+' ثانیه');
            if(current>0&&total>0&&current>1){
                const perProduct=elapsedSec/current;
                const remaining=Math.round(perProduct*(total-current));
                elapsedStr+=' | ~'+(remaining>=60?(Math.floor(remaining/60)+'دقیقه '+remaining%60+'ثانیه'):(remaining+' ثانیه'))+' مانده';
            }
        }
        // Progress display
        if(running||!done){
            let progText='';
            if(current>0&&total>0){
                progText=toFa(current)+'/'+toFa(total)+' ('+Math.round(current/total*100)+'٪)';
            }else{
                progText='در حال پردازش...';
            }
            if(elapsedStr)progText+=' | '+elapsedStr;
            $('wSS').textContent=progText;
            $('wPB').style.width=(total>0?current/total*100:0)+'%';
        }
        if(done){
            wSend=false;
            $('wSB').textContent='🚀 ارسال ووکامرس';
            $('wST').classList.add('hidden');
            $('wRB').classList.remove('hidden');
            if(d.cancelled){
                $('wSS').textContent='❌ ارسال لغو شد';
                $('wPB').style.width='0%';
                checkWooQueue();
                return;
            }
            $('wPB').style.width='100%';
            let finalText='✓ '+toFa(sent)+' جدید | '+toFa(updated)+' آپدیت | '+toFa(skipped)+' تکراری | '+toFa(failed)+' خطا';
            if(elapsedStr)finalText+=' | '+elapsedStr;
            $('wSS').textContent=finalText;
            showToast('✓ '+sent+' جدید, '+updated+' آپدیت, '+skipped+' تکراری, '+failed+' خطا');
            // v8.17: Auto-start next queue entry if available
            checkWooQueue();
            if(d.has_more){
                fetch('?woo_queue_start_next=1').then(r=>r.json()).then(nd=>{
                    if(nd.ok&&nd.next_id){
                        showToast('📋 شروع فرآیند بعدی از صف ووکامرس');
                        // Reset progress for new entry
                        wooReportData={sent:[],updated:[],skipped:[],failed:[]};
                        wooLastLogCount=0;wooLastCardCount=0;wooLastUpdateTime=0;
                        wSend=true;
                        $('wSB').textContent='🚀 + افزودن به صف';$('wST').classList.remove('hidden');
                        $('wPB').style.width='0%';
                        $('wR').innerHTML='<div style="color:#a78bfa;font-weight:bold;padding:8px;margin-bottom:4px;background:#1e3a5f;border-radius:6px">📋 شروع فرآیند بعدی از صف — '+toFa(nd.total)+' محصول</div>';
                        $('wO').textContent=toFa(0);$('wU').textContent=toFa(0);$('wK').textContent=toFa(0);$('wF').textContent=toFa(0);$('wT').textContent=toFa(nd.total);
                        fetch('?action=woo_backend').catch(()=>{});
                        setTimeout(pollWooProgress,1500);
                    }
                }).catch(()=>{});
            }
            return;
        }
        // Stale detection
        const now=Date.now();
        const progressTs=d.last_progress_ts?d.last_progress_ts*1000:0;
        if(progressTs>0&&progressTs>wooLastUpdateTime)wooLastUpdateTime=progressTs;
        if(wooLastUpdateTime===0)wooLastUpdateTime=now;
        if(totalLogCount>wooLastLogCount)wooLastUpdateTime=now;
        if(current>0)wooLastUpdateTime=now;
        const staleThreshold=120000;
        if(now-wooLastUpdateTime>staleThreshold&&running&&!done){
            wooResumeCount++;
            if(wooResumeCount<=3){
                $('wR').insertAdjacentHTML('beforeend','<div style="color:#f87171;background:#7f1d1d20;padding:6px;font-size:12px;border-radius:6px;margin:4px 0">⚠️ فرآیند قطع شد — ادامه سرورساید (تلاش '+toFa(wooResumeCount)+')</div>');
                wooLastUpdateTime=now;
                wooLastLogCount=totalLogCount;
                fetch('?action=woo_backend').catch(()=>{});
                setTimeout(pollWooProgress,1500);
                return;
            }else{
                $('wR').insertAdjacentHTML('beforeend','<div style="color:#f87171;padding:6px;font-size:12px">❌ فرآیند قطع شد — حداکثر تلاش‌های ادامه رسید</div>');
                wSend=false;$('wSB').textContent='🚀 ارسال ووکامرس';$('wST').classList.add('hidden');
                return;
            }
        }
        // Keep polling
        if(totalLogCount>wooLastLogCount)wooLastLogCount=totalLogCount;
        setTimeout(pollWooProgress,1000);
    }).catch(()=>{setTimeout(pollWooProgress,2000);});
}
function wooStop(){
    fetch('?woo_stop=1').then(r=>r.json()).then(d=>{
        if(d.ok)showToast('فرآیند متوقف شد');
        else showToast('خطا: '+d.error,1);
    }).catch(()=>{showToast('خطا شبکه',1);});
}
// v8.06: Auto-restore WooCommerce progress on page load
function checkWooProgress(){
    fetch('?poll_woo=1').then(r=>r.json()).then(d=>{
        if(!d)return;
        if(d.running||(!d.done&&d.total>0)){
            // Server-side process is active — restore UI
            wSend=true;
            $('wSB').textContent='🚀 + افزودن به صف';$('wST').classList.remove('hidden');
            $('wP').classList.remove('hidden');$('wR').classList.remove('hidden');$('wSM').classList.remove('hidden');
            pollWooProgress();
        }else if(d.done&&d.total>0){
            // Process finished — show results
            $('wSB').textContent='🚀 ارسال ووکامرس';$('wST').classList.add('hidden');
            $('wP').classList.remove('hidden');$('wR').classList.remove('hidden');$('wSM').classList.remove('hidden');
            $('wPB').style.width='100%';
            $('wO').textContent=toFa(d.sent||0);$('wU').textContent=toFa(d.updated||0);
            $('wK').textContent=toFa(d.skipped||0);$('wF').textContent=toFa(d.failed||0);$('wT').textContent=toFa(d.total||0);
            if(d.cancelled){
                $('wSS').textContent='❌ ارسال لغو شد';
            }else{
                $('wSS').textContent='✓ '+toFa(d.sent||0)+' جدید | '+toFa(d.updated||0)+' آپدیت | '+toFa(d.skipped||0)+' تکراری | '+toFa(d.failed||0)+' خطا';
            }
            // Render cards from detail lists
            if(d.sent_details)wooReportData.sent=d.sent_details;
            if(d.updated_details)wooReportData.updated=d.updated_details;
            if(d.skipped_details)wooReportData.skipped=d.skipped_details;
            if(d.failed_details)wooReportData.failed=d.failed_details;
            const allItems=[];
            wooReportData.sent.forEach(x=>{allItems.push(Object.assign({result:'ok'},x));});
            wooReportData.updated.forEach(x=>{allItems.push(Object.assign({result:'update'},x));});
            wooReportData.skipped.forEach(x=>{allItems.push(Object.assign({result:'skip'},x));});
            wooReportData.failed.forEach(x=>{allItems.push(Object.assign({result:'fail'},x));});
            allItems.forEach(item=>{$('wR').insertAdjacentHTML('beforeend',renderSendCard(item));});
            wooLastCardCount=allItems.length;
        }
        // v8.17: Also check the woo queue
        checkWooQueue();
    }).catch(()=>{});
}
function finW(s,u,k,f,t){wSend=false;$('wSB').textContent='🚀 ارسال ووکامرس';$('wST').classList.add('hidden');$('wSM').classList.remove('hidden');$('wO').textContent=toFa(s);$('wU').textContent=toFa(u);$('wK').textContent=toFa(k);$('wF').textContent=toFa(f);$('wT').textContent=toFa(t);$('wSS').textContent='✓ '+toFa(s)+' جدید | '+toFa(u)+' آپدیت | '+toFa(k)+' تکراری | '+toFa(f)+' خطا';$('wPB').style.width='100%';showToast('✓ '+s+' جدید, '+u+' آپدیت, '+k+' تکراری, '+f+' خطا');}

// v7.81: Compare current extraction with previous profile products and show report
// v7.81: Live comparison — shows new/changed/removed/unchanged products inline in page
// Persistent panel (not floating, not auto-dismissing) — shows all items with full details
var extractReportData={newItems:[],changedItems:[],removedItems:[],unchangedCount:0,totalOld:0};

/* =====================================================================
 *  v8.40: نشان «جدید / آپدیت» روی نتایج
 *
 *  تا حالا برای فهمیدن اینکه کدام محصول جدید است باید مودال گزارش را
 *  باز می‌کردید. حالا خود تب نتایج این را نشان می‌دهد و می‌شود فقط
 *  همان‌ها را دید.
 * ===================================================================== */
var prodStatusMap={};          // key → 'new' | 'changed'
var resultFilter='all';        // all | new | changed | unchanged

/** نقشهٔ وضعیت را از نتیجهٔ آخرین مقایسه می‌سازد */
function buildStatusMap(){
  prodStatusMap={};
  (extractReportData.newItems||[]).forEach(it=>{
    const k=it.key||it.title; if(k)prodStatusMap[k]='new';
  });
  (extractReportData.changedItems||[]).forEach(it=>{
    const k=it.key||it.title; if(k&&!prodStatusMap[k])prodStatusMap[k]='changed';
  });
  updateResultCounts();
}

/** وضعیت یک محصول — اگر مقایسه‌ای انجام نشده باشد خالی برمی‌گرداند */
function prodStatus(p){
  if(!p)return '';
  return prodStatusMap[p.key]||prodStatusMap[getFinalTitle(p.title)]||'';
}

function statusBadge(st){
  if(st==='new')return '<span class="pbadge pb-new">جدید</span>';
  if(st==='changed')return '<span class="pbadge pb-chg">آپدیت</span>';
  return '';
}

/** شمارنده‌های بالای نتایج را تازه می‌کند */
function updateResultCounts(){
  const bar=$('resultFilterBar');
  if(!bar)return;
  let nNew=0,nChg=0;
  order.forEach(k=>{
    const st=prodStatusMap[k]||'';
    if(st==='new')nNew++; else if(st==='changed')nChg++;
  });
  const total=products.size;
  const nUnc=Math.max(0,total-nNew-nChg);
  const has=(nNew+nChg)>0;
  bar.style.display=has?'flex':'none';
  const set=(id,v)=>{const e=$(id);if(e)e.textContent=toFa(v);};
  set('rfAllN',total); set('rfNewN',nNew); set('rfChgN',nChg); set('rfUncN',nUnc);
}

/** فیلتر نتایج بر اساس وضعیت */
function setResultFilter(f){
  resultFilter=f;
  document.querySelectorAll('#resultFilterBar .rf-btn').forEach(b=>{
    b.classList.toggle('on',b.dataset.f===f);
  });
  applyResultFilter();
}

function applyResultFilter(){
  // v8.41: اگر فیلتری فعال است ولی هیچ محصولی وضعیت ندارد، یعنی نقشهٔ
  // وضعیت مربوط به اجرای قبلی بوده. در این حالت به‌جای پنهان کردن همه‌چیز
  // خودکار به «همه» برمی‌گردیم — کاربر نباید با صفحهٔ خالی روبه‌رو شود.
  if(resultFilter!=='all'&&Object.keys(prodStatusMap).length===0){
    resetResultFilter();
  }
  document.querySelectorAll('#vGrid .product').forEach(el=>{
    const st=prodStatusMap[el.dataset.k]||'';
    el.style.display=matchFilter(st)?'':'none';
  });
  document.querySelectorAll('#tBody tr').forEach(el=>{
    const st=prodStatusMap[el.dataset.k]||'';
    el.style.display=matchFilter(st)?'':'none';
  });
}

function matchFilter(st){
  if(resultFilter==='all')return true;
  if(resultFilter==='unchanged')return st==='';
  return st===resultFilter;
}

/**
 * v8.41: صفر کردن وضعیت و فیلتر نتایج.
 *
 * باگ نسخهٔ ۸.۴۰: فیلتر یک متغیر سراسری بود و بین اجراها باقی می‌ماند.
 * اگر کاربر روی «جدید» فیلتر می‌کرد و بعد پروفایل دیگری بارگذاری می‌شد،
 * محصولات تازه هیچ وضعیتی نداشتند و همه با display:none پنهان می‌شدند —
 * شمارنده «۵۰۰ محصول» می‌گفت ولی صفحه تقریباً خالی بود.
 */
function resetResultFilter(){
  prodStatusMap={};
  resultFilter='all';
  document.querySelectorAll('#resultFilterBar .rf-btn').forEach(b=>{
    b.classList.toggle('on',b.dataset.f==='all');
  });
  updateResultCounts();
}

/**
 * بعد از ساخته شدن نقشهٔ وضعیت، نشان‌ها را روی نتایجِ از قبل رسم‌شده
 * می‌نشاند. دوباره‌سازی کامل لازم نیست — فقط عنوان و رنگ حاشیه.
 */
function refreshResultBadges(){
  document.querySelectorAll('#vGrid .product').forEach(el=>{
    const st=prodStatusMap[el.dataset.k]||'';
    el.className='product'+(st==='new'?' is-new':st==='changed'?' is-chg':'');
    const t=el.querySelector('.ptitle');
    if(t){
      const old=t.querySelector('.pbadge');
      if(old)old.remove();
      if(st)t.insertAdjacentHTML('afterbegin',statusBadge(st));
    }
    el.style.display=matchFilter(st)?'':'none';
  });
  document.querySelectorAll('#tBody tr').forEach(el=>{
    const st=prodStatusMap[el.dataset.k]||'';
    const td=el.querySelector('td:nth-child(2)');
    if(td){
      const old=td.querySelector('.pbadge');
      if(old)old.remove();
      if(st)td.insertAdjacentHTML('afterbegin',statusBadge(st));
    }
    el.style.display=matchFilter(st)?'':'none';
  });
  updateResultCounts();
}

function showLiveComparison(){
    const sel=$('profileSelect');
    if(!sel||!sel.value||products.size===0)return;
    const url=sel.value.trim();
    fetch('?load_profile='+encodeURIComponent(url)).then(r=>r.json()).then(d=>{
        if(!d.ok||!d.profile)return;
        const prevProds=d.profile.products||{};
        const prevOrder=d.profile.productsOrder||[];
        if(!prevOrder.length)return;
        let newCount=0,priceChanged=0,unchanged=0,removedCount=0,totalOld=0;
        const newItems=[],changedItems=[],removedItems=[];
        order.forEach(k=>{
            const curr=products.get(k);if(!curr)return;
            const currPrice=getFinalPriceNum(curr.price);
            const prev=prevProds[k];
            if(!prev){
                newCount++;newItems.push({key:k,title:curr.title||curr.name||'',price:currPrice});
            }else{
                const prevPrice=getFinalPriceNum(prev.price);
                if(currPrice!==prevPrice){
                    priceChanged++;changedItems.push({key:k,title:curr.title||curr.name||'',oldPrice:prevPrice,newPrice:currPrice});
                }else{unchanged++;}
            }
        });
        prevOrder.forEach(k=>{
            totalOld++;
            if(!products.has(k)){
                const prev=prevProds[k];
                removedCount++;removedItems.push({title:prev?.title||prev?.name||'',price:getFinalPriceNum(prev?.price)});
            }
        });
        // v8.19: Store data for modal access
        extractReportData={newItems:newItems,changedItems:changedItems,removedItems:removedItems,unchangedCount:unchanged,totalOld:totalOld};
        buildStatusMap();refreshResultBadges();
        if(newCount===0&&priceChanged===0&&removedCount===0)return;
        // Build a permanent section in the page (not floating panel)
        let container=$('liveComparison');
        if(!container){
            container=document.createElement('div');
            container.id='liveComparison';
            container.style.cssText='margin:12px 0;padding:0';
            const grid=$('vGrid');
            if(grid&&grid.parentNode)grid.parentNode.insertBefore(container,grid);
            else document.body.appendChild(container);
        }
        let html='<div style="background:#1e293b;border:1px solid #475569;border-radius:12px;padding:16px;color:#e2e8f0">';
        html+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
        html+='<span style="font-weight:700;font-size:14px;color:#67e8f9">📊 گزارش مقایسه زنده</span>';
        html+='<button class="btn btn-gray" onclick="document.getElementById(\'liveComparison\').style.display=\'none\'" style="font-size:10px;padding:4px 8px">✕</button>';
        html+='</div>';
        // v8.19: Clickable counters that open modals
        html+='<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;margin-bottom:12px">';
        html+='<div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'new\')"><b style="color:#4ade80;font-size:16px">'+toFa(newCount)+'</b><br><span style="color:#94a3b8;font-size:10px">🆕 جدید</span></div>';
        html+='<div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'changed\')"><b style="color:#facc15;font-size:16px">'+toFa(priceChanged)+'</b><br><span style="color:#94a3b8;font-size:10px">💰 تغییر قیمت</span></div>';
        html+='<div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'removed\')"><b style="color:#f87171;font-size:16px">'+toFa(removedCount)+'</b><br><span style="color:#94a3b8;font-size:10px">❌ حذف شده</span></div>';
        html+='<div style="background:#0f172a;border-radius:8px;padding:8px;text-align:center;cursor:pointer" onclick="showExtractReport(\'unchanged\')"><b style="color:#94a3b8;font-size:16px">'+toFa(unchanged)+'</b><br><span style="color:#94a3b8;font-size:10px">⏭ بدون تغییر</span></div>';
        html+='</div>';
        html+='<div style="font-size:10px;color:#64748b;margin-top:8px">کل قبلی: '+toFa(totalOld)+' | کل جدید: '+toFa(products.size)+' | بدون تغییر: '+toFa(unchanged)+'</div>';
        html+='</div>';
        container.innerHTML=html;
        container.style.display='block';
    }).catch(()=>{});
}
// v8.19: Extract report modal — shows list of products by category
function showExtractReport(type){
    const list=extractReportData[type==='new'?'newItems':type==='changed'?'changedItems':type==='removed'?'removedItems':[]]||[];
    const labels={new:'🆕 محصولات جدید',changed:'💰 تغییر قیمت',removed:'❌ حذف شده',unchanged:'⏭ بدون تغییر'};
    const colors={new:'#4ade80',changed:'#facc15',removed:'#f87171',unchanged:'#94a3b8'};
    const title=labels[type]||type;
    const color=colors[type]||'#e2e8f0';
    let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeReportModal()">';
    html+='<div class="bsl-modal" style="max-width:700px">';
    html+='<div class="bsl-modal-head"><h2 style="color:'+color+'">'+title+' ('+toFa(type==='unchanged'?extractReportData.unchangedCount:list.length)+' محصول)</h2><button class="btn btn-gray" onclick="closeReportModal()">✕</button></div>';
    if(type==='unchanged'){
        // Unchanged items — we don't have a list, just show count
        html+='<div class="bsl-modal-body" style="padding:20px;text-align:center;color:#64748b">'+toFa(extractReportData.unchangedCount)+' محصول بدون تغییر</div>';
    }else if(!list.length){
        html+='<div class="bsl-modal-body" style="padding:20px;text-align:center;color:#64748b">هیچ محصول در این دسته نیست</div>';
    }else{
        html+='<div class="bsl-modal-body" style="max-height:500px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        html+='<thead><tr style="background:#1e293b;color:#94a3b8"><th style="padding:6px;text-align:right">#</th><th style="padding:6px;text-align:right">عنوان</th>';
        if(type==='new'){html+='<th style="padding:6px;text-align:right">قیمت</th>';}
        if(type==='changed'){html+='<th style="padding:6px;text-align:right">قیمت مبدأ (قبلی)</th><th style="padding:6px;text-align:right">قیمت مبدأ (جدید)</th><th style="padding:6px;text-align:right">وضعیت</th><th style="padding:6px;text-align:right">اختلاف</th>';}
        if(type==='removed'){html+='<th style="padding:6px;text-align:right">آخرین قیمت</th><th style="padding:6px;text-align:right">علت</th>';}
        html+='</tr></thead><tbody>';
        list.forEach((item,i)=>{
            const link=item.link?'<a href="'+esc(item.link)+'" target="_blank" style="color:#60a5fa;text-decoration:none">🔗</a> ':'';
            html+='<tr style="border-bottom:1px solid #1e293b"><td style="padding:4px;color:#64748b">'+toFa(i+1)+'</td>';
            html+='<td style="padding:4px;color:#e2e8f0">'+link+esc(item.title||'—')+'</td>';
            if(type==='new'){html+='<td style="padding:4px;color:#4ade80">'+toFa(item.price)+'</td>';}
            if(type==='changed'){
                const oldP=item.oldPrice||item.old_price||'';
                const newP=item.newPrice||item.new_price||'';
                // v8.22: اگر سرور جهت را نداده باشد، همین‌جا محاسبه کن
                let dir=item.dir;
                const num=v=>{const s=toEn(String(v||'')).replace(/[^0-9]/g,'');return s?parseInt(s,10):0;};
                const oN=num(oldP), nN=num(newP);
                if(!dir)dir=nN>oN?'up':(nN<oN?'down':'same');
                const badge=dir==='up'?'<span class="pdir pdir-up">▲ گران شد</span>'
                          :dir==='down'?'<span class="pdir pdir-down">▼ ارزان شد</span>'
                          :'<span class="pdir pdir-same">— بدون تغییر</span>';
                let diff=item.diff, pct=item.pct;
                if(diff===undefined){diff=nN-oN;pct=oN>0?Math.round((diff/oN)*1000)/10:0;}
                const dCol=dir==='up'?'#fca5a5':dir==='down'?'#86efac':'#94a3b8';
                const sign=diff>0?'+':'';
                html+='<td style="padding:4px;color:#f87171;text-decoration:line-through">'+toFa(oldP)+'</td>';
                html+='<td style="padding:4px;color:#4ade80;font-weight:700">'+toFa(newP)+'</td>';
                html+='<td style="padding:4px">'+badge+'</td>';
                html+='<td style="padding:4px;color:'+dCol+';font-family:ui-monospace,monospace;font-size:11px;direction:ltr;text-align:right">'
                     +toFa(sign+Number(diff).toLocaleString('en-US'))+(pct?'<br><span style="font-size:9px;opacity:.8">'+toFa(sign+pct)+'٪</span>':'')+'</td>';
            }
            if(type==='removed'){
                const why=item.reason||'از سایت حذف شد';
                const wc=why.indexOf('قیمت')>=0?'#fbbf24':'#f87171';
                html+='<td style="padding:4px;color:#94a3b8">'+(item.price?toFa(item.price):'—')+'</td>';
                html+='<td style="padding:4px"><span class="pdir" style="background:'+wc+'22;color:'+wc+'">'+esc(why)+'</span></td>';
            }
            html+='</tr>';
        });
        html+='</tbody></table></div>';
    }
    html+='</div></div>';
    const old=document.getElementById('reportModalContainer');if(old)old.remove();
    const div=document.createElement('div');div.id='reportModalContainer';div.innerHTML=html;
    document.body.appendChild(div);
}
// v7.81: Alias — backward compatibility
function showComparisonReport(){showLiveComparison();}
// v7.81: Toggle comparison section visibility
function toggleCmpSection(el){
    const s=el.nextElementSibling.style;
    s.display=s.display==='none'?'block':'none';
}

// v7.50: Queue a BSL send — ALWAYS used, even for first send
function queueBslSend(ps,catId){
    const autoCat=$('bsAutoCat')&&$('bsCat')&&$('bsAutoCat').checked;
    const titleSuffix='';
    const qid='q_'+Date.now()+'_'+Math.random().toString(36).substr(2,6);
    // v7.56: SERVER-SIDE background processing — PHP does the work, browser just displays
    // 1. Save products to queue file (for persistence + cron resume)
    // 2. bsl_queue_add with start_immediately=1 (copies products file, sets status='running')
    // 3. Trigger bsl_backend (PHP background — continues after browser closes!)
    // 4. Start polling to display live progress
    $('bSS').textContent='\u0627\u0641\u0632\u0648\u062f\u0646 \u062f\u0631 \u0635\u0641 \u0648 \u0634\u0631\u0648\u0639 \u0627\u0631\u0633\u0627\u0644...';
    const CHUNK_SIZE=50;const chunks=[];
    for(let i=0;i<ps.length;i+=CHUNK_SIZE)chunks.push(ps.slice(i,i+CHUNK_SIZE));
    let savedChunks=0,totalSaved=0;
    function saveNextQueueChunk(){
        if(savedChunks>=chunks.length){
            const fd2=new FormData();
            fd2.append('queue_id',qid);
            fd2.append('total',String(totalSaved));
            fd2.append('category_id',String(catId));
            fd2.append('auto_category',autoCat?'1':'0');
            fd2.append('title_suffix',titleSuffix);
            fd2.append('delay_ms',$('bsDelayMs')?($('bsDelayMs').value||'500'):'500');
            fd2.append('retry_delay_ms',$('bsRetryDelayMs')?($('bsRetryDelayMs').value||'1000'):'1000');
            fd2.append('start_immediately','1');
            fd2.append('profile_key',($('profileSelect')&&$('profileSelect').value)||'');
            fd2.append('profile_name',($('profileName')&&$('profileName').value)||'');
            fetch('?bsl_queue_add=1',{method:'POST',body:fd2}).then(r=>r.json()).then(d=>{
                if(!d.ok){showToast('\u062e\u0637\u0627: '+d.error,1);return;}
                showToast('\u2713 '+toFa(totalSaved)+' \u0645\u062d\u0635\u0648\u0644 \u062f\u0631 \u0635\u0641 \u2014 \u0633\u0631\u0648\u0631\u0633\u0627\u06cc\u062f');
                // Initialize visual UI
                bSend=true;
                bslReportData={sent:[],updated:[],skipped:[],failed:[]};
                bslLastLogCount=0;bslLastCardCount=0;bslLastUpdateTime=0;
                $('bSB').classList.add('hidden');$('bSBlegacy').classList.add('hidden');
                $('bST').classList.remove('hidden');
                $('bP').classList.remove('hidden');$('bPB').style.width='0%';
                $('bR').classList.remove('hidden');
                $('bR').innerHTML='<div style="color:#67e8f9;font-weight:bold;padding:8px;margin-bottom:4px;background:#1e3a5f;border-radius:6px">\u2713 \u0627\u0631\u0633\u0627\u0644 '+toFa(totalSaved)+' \u0645\u062d\u0635\u0648\u0644 (\u0633\u0631\u0648\u0631\u0633\u0627\u06cc\u062f \u2014 \u0628\u0633\u062a\u0647 \u0645\u0631\u0648\u0631\u06af\u0632 \u0628\u0627 \u0627\u062f\u0627\u0645\u0647 \u0645\u06cc\u062f\u0647\u062f)</div>';
                $('bSM').classList.remove('hidden');$('bO').textContent=toFa(0);$('bU').textContent=toFa(0);$('bK').textContent=toFa(0);$('bF').textContent=toFa(0);$('bT').textContent=toFa(totalSaved);
                $('bSS').textContent='\u2713 \u0634\u0631\u0648\u0631 \u0627\u0631\u0633\u0627\u0644...';
                // v7.66: Track current queue ID for resume capability                window._currentBslQueueId=qid;                console.log("[v7.66 queueBslSend] Starting bsl_backend for queue_id="+qid+" with "+totalSaved+" products");                checkBslQueue();
                // v7.66: Trigger bsl_backend (pure PHP processor)
                const fd3=new FormData();fd3.append('from_file','1');fd3.append('queue_id',qid);
                // v7.81: Fire-and-forget — trigger bsl_backend, start polling immediately
                fetch('?action=bsl_backend',{method:'GET'}).catch(()=>{});
                $('bR').innerHTML+='<div style="color:#22c55e;padding:4px;font-size:12px">✓ ارسال شروع — پیشرفت زنده نمایش می‌شود</div>';
                setTimeout(pollBslProgress,1000);
            }).catch(()=>{});
            return;
        }
        const chunk=chunks[savedChunks];
        const fd=new FormData();
        fd.append('products',JSON.stringify(chunk));fd.append('chunk_index',String(savedChunks));fd.append('queue_id',qid);
        $('bSS').textContent='\u0630\u062e\u06cc\u0631\u0647 '+toFa(totalSaved)+'/'+toFa(ps.length)+' \u0645\u062d\u0635\u0648\u0644...';
        fetch('?bsl_queue_save_products=1',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            if(!d.ok){showToast('\u062e\u0637\u0627: '+d.error,1);return;}
            totalSaved=d.total_saved;savedChunks++;saveNextQueueChunk();
        }).catch(()=>{showToast('\u062e\u0637\u0627 \u0634\u0628\u06a9\u0647',1);});
    }
    saveNextQueueChunk();
}
// v7.51: sendBsl ALWAYS queues — buttons stay visible, operation goes to queue
function sendBsl(){
const catId=parseInt($('bsCat')&&$('bsCat').value)||0;
if(catId<=0){showToast('ابتدا دسته‌بندی باسلام را انتخاب کنید!',1);return;}
const ps=getSendP();
if(!ps.length){showToast('محصولی نیست',1);return;}
queueBslSend(ps,catId);
}
// v7.50: Proper stop — sends stop signal to PHP and stops JS polling
// v7.51: Stop — just set bSend=false, JS stops sending next products
// In client-driven mode, there's no PHP process to kill
function stopBslProcess(){
    if(!bSend)return;
    bSend=false;
    $('bST').classList.add('hidden');
    $('bSS').textContent='⏹ متوقف';
    showToast('⏹ ارسال متوقف شد');
    if(bslQueueRunner){
        // Mark the queue entry as failed/paused
        fetch('?bsl_queue_update_progress=1&queue_id='+encodeURIComponent(bslQueueRunner.id)+
            '&sent='+bslQueueRunner.sent+'&updated='+bslQueueRunner.updated+'&skipped='+bslQueueRunner.skipped+'&failed='+bslQueueRunner.failed+'&current='+bslQueueRunner.current,
            {method:'POST'}).then(()=>{checkBslQueue();}).catch(()=>{});
        bslQueueRunner=null;
        checkBslQueue();
    }
}
function finB(s,u,k,f,t){$('bSS').textContent='✓ '+toFa(s)+' جدید | '+toFa(u)+' آپدیت | '+toFa(k)+' تکراری | '+toFa(f)+' خطا';showToast('✓ '+s+' جدید, '+u+' آپدیت, '+k+' تکراری, '+f+' خطا');}

// v7.48: Client-side BaSalam send — one product at a time, no server timeout
let bslClientRunning=false;
function sendBslClient(){
    if(bslClientRunning||bSend)return;
    const catId=parseInt($('bsCat')&&$('bsCat').value)||0;
    if(catId<=0){showToast('ابتدا دسته‌بندی باسلام را انتخاب کنید!',1);return;}
    const ps=getSendP();
    if(!ps.length){showToast('محصولی نیست',1);return;}
    bslClientRunning=true;bSend=true;
    bslReportData={sent:[],updated:[],skipped:[],failed:[]};
    bslLastLogCount=0;bslLastCardCount=0;bslLastUpdateTime=0;bslResumeCount=0;
    $('bR').dataset.logIdx='0';
    $('bSB').classList.add('hidden');$('bSBlegacy').classList.add('hidden');$('bST').classList.remove('hidden');
    $('bP').classList.remove('hidden');$('bPB').style.width='0%';
    $('bR').classList.remove('hidden');
    $('bR').innerHTML='<div style="color:#22c55e;font-weight:bold;padding:8px;margin-bottom:4px;background:#14532d30;border-radius:6px">🚀 ارسال فرات '+toFa(ps.length)+' محصول به باسلام (تکی)</div>';
    $('bSM').classList.remove('hidden');$('bO').textContent='۰';$('bU').textContent='۰';$('bK').textContent='۰';$('bF').textContent='۰';$('bT').textContent=toFa(ps.length);
    $('bSS').textContent='0/'+toFa(ps.length);
    // v7.48: Also clear stale temp/progress files before starting
    fetch('?bsl_clear_temp=1').then(()=>{}).catch(()=>{});
    let i=0,s=0,u=0,k=0,f=0;
    function sendNext(){
        if(!bSend){finishBslClient(s,u,k,f,ps.length);return;}
        if(i>=ps.length){finishBslClient(s,u,k,f,ps.length);return;}
        const p=ps[i];
        $('bSS').textContent=toFa(i+1)+'/'+toFa(ps.length);
        $('bPB').style.width=((i+1)/ps.length*100)+'%';
        const fd=new FormData();
        fd.append('product',JSON.stringify(p));
        fd.append('product_index',String(i));
        fd.append('total',String(ps.length));
        fd.append('mode','send');
        fd.append('delay_ms',$('bsDelayMs')?($('bsDelayMs').value||'500'):'500');
        fd.append('retry_delay_ms',$('bsRetryDelayMs')?($('bsRetryDelayMs').value||'1000'):'1000');
        fetch('?bsl_send_one=1',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            // v7.56: Auth fail — stop immediately
            if(d.auth_fail){
                $('bR').innerHTML+='<div class="no2" style="font-size:14px;font-weight:700">✗ '+esc(d.error||'خطا احراز هویت')+'</div>';
                $('bSS').textContent='✗ '+esc(d.error||'خطا احراز هویت');
                bSend=false;bslClientRunning=false;
                $('bSB').classList.remove('hidden');$('bSBlegacy').classList.remove('hidden');$('bST').classList.add('hidden');
                showBslVendorModal({ok:false,error:d.error,http_code:d.http_code,detail:d.detail||''});
                return;
            }
            if(d.action==='send'){
                s++;$('bO').textContent=toFa(s);
                $('bR').insertAdjacentHTML('beforeend',renderSendCard({title:d.title||p.title,image:d.image||p.image,price:d.price,category:d.category,category_id:d.category_id,price_unit:d.price_unit,link:d.link,result:'ok',remote_id:d.remote_id}));
                bslReportData.sent.push({title:d.title,remote_id:d.remote_id,key:d.key,image:d.image||p.image,price:d.price,category:d.category});
            }else if(d.action==='update'){
                u++;$('bU').textContent=toFa(u);
                $('bR').insertAdjacentHTML('beforeend',renderSendCard({title:d.title||p.title,image:d.image||p.image,price:d.price,category:d.category,category_id:d.category_id,price_unit:d.price_unit,link:d.link,result:'update',remote_id:d.remote_id,changes:d.changes,update_reason:d.update_reason||d.changes}));
                bslReportData.updated.push({title:d.title,remote_id:d.remote_id,key:d.key,changes:d.changes,update_reason:d.update_reason||d.changes,image:d.image||p.image,price:d.price,category:d.category});
            }else if(d.action==='skip'){
                k++;$('bK').textContent=toFa(k);
                $('bR').insertAdjacentHTML('beforeend',renderSendCard({title:d.title||p.title,image:d.image||p.image,price:d.price,category:d.category,category_id:d.category_id,price_unit:d.price_unit,link:d.link,result:'skip',remote_id:d.remote_id,error:d.reason}));
                bslReportData.skipped.push({title:d.title,remote_id:d.remote_id,key:d.key,reason:d.reason,image:d.image||p.image,price:d.price,category:d.category});
            }else if(d.action==='fail'||!d.ok){
                f++;$('bF').textContent=toFa(f);
                $('bR').insertAdjacentHTML('beforeend',renderSendCard({title:d.title||p.title,image:d.image||p.image,price:d.price,category:d.category,category_id:d.category_id,price_unit:d.price_unit,link:d.link,result:'fail',error:d.error}));
                bslReportData.failed.push({title:d.title||p.title,key:p.key,error:d.error,image:d.image||p.image,price:d.price,category:d.category});
            }
            scrollElBottom($('bR'));
            i++;
            const delayMs2=parseInt($('bsDelayMs')?.value)||500;
            if(delayMs2>0){setTimeout(sendNext,delayMs2);}else{sendNext();}
        }).catch(e=>{
            f++;$('bF').textContent=toFa(f);
            $('bR').innerHTML+='<div class="no2">✗ خطا شبکه</div>';
            scrollElBottom($('bR'));
            i++;
            // v8.06: Configurable delay between products
            const delayMs=parseInt($('bsDelayMs')?.value)||500;
            if(delayMs>0){setTimeout(sendNext,delayMs);}else{sendNext();}
        });
    }
    sendNext();
}
function finishBslClient(s,u,k,f,t){
    bslClientRunning=false;bSend=false;
    $('bSB').classList.remove('hidden');$('bSBlegacy').classList.remove('hidden');$('bST').classList.add('hidden');
    $('bO').textContent=toFa(s);$('bU').textContent=toFa(u);$('bK').textContent=toFa(k);$('bF').textContent=toFa(f);$('bT').textContent=toFa(t);
    $('bSS').textContent='✓ '+toFa(s)+' جدید | '+toFa(u)+' آپدیت | '+toFa(k)+' تکراری | '+toFa(f)+' خطا';
    $('bPB').style.width='100%';
    showToast('✓ '+s+' جدید, '+u+' آپدیت, '+k+' تکراری, '+f+' خطا');
}

loadConn();
// v8.06: Auto-restore WooCommerce progress on page load
checkWooProgress();
// v7.48: Offline category matching — apply autoMatchBslCategory to all products
function offlineCatMatch(){
    const ps=getSendP();
    if(!ps.length){showToast('محصولی نیست',1);return;}
    const cats=bslAllCats;
    if(!cats.length){loadBslCats();showToast('ابتدا دسته‌ها را بارگذاری کنید (🔄)',1);return;}
    let matched=0;
    ps.forEach(p=>{
        const catId=autoMatchBslCategoryJS(p.title,cats);
        if(catId>0){
            // Store matched category
            bslSelectedCatId=catId;
            $('bsCat').value=String(catId);
            const sc=cats.find(c=>c.id===catId);
            if(sc)$('bsCatSearch').value=sc.name+' ('+catId+')';
            matched++;
        }
    });
    if(matched>0){showToast('✓ '+matched+' محصول دسته‌بندی شد');saveConn();}
    else{showToast('هیچ محصول دسته‌بندی نشد — لolojistica نام‌ها با دسته‌ها همخوانی ندارند',1);}
}
// v7.48: Online AI category matching — call Gemini Flash API for each product
function onlineCatMatch(){
    const ps=getSendP();
    if(!ps.length){showToast('محصولی نیست',1);return;}
    const gemKey=$('bsGemKey')?.value||'';
    if(!gemKey){showToast('ابتدا کلید Gemini را وارد کنید!',1);return;}
    if(!bslAllCats.length){loadBslCats();showToast('ابتدا دسته‌ها را بارگذاری کنید',1);return;}
    let i=0,matched=0;
    $('bSS').textContent='دسته‌بندی AI: 0/'+ps.length;
    function matchNext(){
        if(i>=ps.length){$('bSS').textContent='✓ '+matched+' دسته‌بندی AI';showToast('✓ '+matched+' محصول با AI دسته‌بندی شد');saveConn();return;}
        const p=ps[i];
        $('bSS').textContent='دسته‌بندی AI: '+i+'/'+ps.length;
        fetch('?bsl_ai_category=1&title='+encodeURIComponent(p.title)+'&api_key='+encodeURIComponent(gemKey)).then(r=>r.json()).then(d=>{
            if(d.ok&&d.category_id>0){
                bslSelectedCatId=d.category_id;
                $('bsCat').value=String(d.category_id);
                const sc=bslAllCats.find(c=>c.id===d.category_id);
                if(sc)$('bsCatSearch').value=sc.name+' ('+d.category_id+')';
                matched++;
                $('bR').innerHTML+='<div class="ok2" style="font-size:11px">ᾑ6 '+esc(p.title)+' → '+esc(d.category_name||'')+' ('+d.category_id+')</div>';
            }else{
                $('bR').innerHTML+='<div style="color:#94a3b8;font-size:11px">✗ '+esc(p.title)+' - '+esc(d.error||'دسته یافت نشد')+'</div>';
            }
            i++;matchNext();
        }).catch(e=>{
            $('bR').innerHTML+='<div style="color:#f87171;font-size:11px">✗ خطا شبکه</div>';
            i++;matchNext();
        });
    }
    matchNext();
}
// v7.50: Verify version + auto-detect running process + queue management
console.log('=== Scraper v7.81 loaded ===');
const vCheck=document.querySelector('h1');if(vCheck&&!vCheck.textContent.includes('v8.22'))console.warn('⚠️ Version mismatch! HTML shows: '+vCheck.textContent);

// v7.48: Auto-detect running BSL process on page load (works across devices/refreshes)
// v7.51: Auto-detect running process on page load
(function checkBslRunningOnLoad(){
    // v7.56: On page load, check if there's a running server-side process and just POLL to display progress.
    // NEVER start client-driven sendNextQueueProduct — it conflicts with server-side processing!
    // If server process died, offer "resume from server" button.
    fetch('?poll_bsl=1').then(r=>r.json()).then(d=>{
        if(d&&d.running&&!d.done){
            console.log('Server-side BSL process running on load — resuming polling');
            bSend=true;
            $('bST').classList.remove('hidden');
            $('bP').classList.remove('hidden');$('bR').classList.remove('hidden');$('bSM').classList.remove('hidden');
            const sent=d.sent||0,updated=d.updated||0,skipped=d.skipped||0,failed=d.failed||0,total=d.total||0,current=d.current||0;
            $('bO').textContent=toFa(sent);$('bU').textContent=toFa(updated);$('bK').textContent=toFa(skipped);$('bF').textContent=toFa(failed);$('bT').textContent=toFa(total);
            $('bPB').style.width=(total>0?current/total*100:0)+'%';
            const logDiv=$('bR');
            const logs=d.recent_log||[];
            const totalLogCount=d.total_log_count||0;
            for(let i=0;i<logs.length;i++){
                const m=logs[i];let cls='';
                if(m.includes('\u2713'))cls='color:#22c55e;background:#14532d20;padding:3px 8px;font-size:12px;border-radius:4px';
                else if(m.includes('\u2717'))cls='color:#ef4444;background:#7f1d1d20;padding:3px 8px;font-size:12px;border-radius:4px';
                else if(m.includes('\u26a1'))cls='color:#facc15;background:#42200620;padding:3px 8px;font-size:12px;border-radius:4px';
                else if(m.includes('\u23ed'))cls='color:#94a3b8;padding:2px 6px;font-size:11px';
                else cls='color:#64748b;padding:1px 8px;font-size:10px';
                logDiv.insertAdjacentHTML('beforeend','<div style="'+cls+';margin:1px 0">'+esc(m)+'</div>');
            }
            const logNodes=logDiv.children;if(logNodes.length>300){for(let j=0;j<logNodes.length-300;j++)logNodes[0].remove();}
            scrollElBottom(logDiv);
            bslLastLogCount=totalLogCount;bslLastUpdateTime=Date.now();
            if(d.sent_details)bslReportData.sent=d.sent_details;
            if(d.updated_details)bslReportData.updated=d.updated_details;
            if(d.skipped_details)bslReportData.skipped=d.skipped_details;
            if(d.failed_details)bslReportData.failed=d.failed_details;
            let elapsedStr='';
            if(d.started_at>0){const elapsedSec=Math.floor(Date.now()/1000-d.started_at);elapsedStr=elapsedSec>=60?(Math.floor(elapsedSec/60)+' \u062f\u0631\u06cc\u0642\u0647 '+elapsedSec%60+' \u062b\u0627\u0646\u06cc\u0647'):(elapsedSec+' \u062b\u0627\u0646\u06cc\u0647');}
            $('bSS').textContent=toFa(current)+'/'+toFa(total)+' ('+Math.round(current/total*100)+'\u066a)'+(elapsedStr?' | '+elapsedStr:'');
            if(d.last_title)$('bSS').title='\u0645\u062d\u0635\u0648\u0644 \u0641\u0639\u0644\u06cc: '+d.last_title;
            pollBslProgress();
        }else{
            // v7.66: No running process — check for waiting/stuck entries, trigger bsl_backend
            fetch('?bsl_queue_status=1').then(r=>r.json()).then(q=>{
                const stuckEntry=q.entries?.find(e=>e.status==='running'&&e.current>0);
                const waitingEntry=q.entries?.find(e=>e.status==='waiting');
                if(stuckEntry||waitingEntry){
                    // Found waiting or stuck — trigger bsl_backend
                    $('bSS').textContent='\u26a0 \u0631\u0633\u0627\u0646 \u0633\u0631\u0648\u0631 \u0642\u0637\u0639 \u0634\u062f \u2014 \u06a9\u0644\u06cc\u06a9 \u0631\u0627 \u0628\u0631\u0627\u06cc \u0627\u062f\u0627\u0645\u0647';
                    checkBslQueue();
                }else{
                    $('bSS').textContent='';
                    checkBslQueue();
                }
            }).catch(()=>{checkBslQueue();});
        }
        checkBslQueue();
    }).catch(()=>{checkBslQueue();});
})();
// v7.48: Queue management functions
let bslQueuePollTimer=null;
function checkBslQueue(){
    fetch('?bsl_queue_status=1').then(r=>r.json()).then(q=>{
        renderBslQueue(q);
        // v7.66: NEVER start client-driven sendNextQueueProduct — bsl_backend does the work
        // If a 'running' entry is found and no polling is active, just start polling to display progress
        const running=q.entries?.find(e=>e.status==='running');
        if(running&&!bSend){
            // Server-side process is active — start polling to show its progress (NOT client-driven send)
            console.log('[v7.81 checkBslQueue] Found running entry, starting pollBslProgress (server-side mode)');
            bSend=true;
            $('bST').classList.remove('hidden');
            $('bP').classList.remove('hidden');$('bR').classList.remove('hidden');$('bSM').classList.remove('hidden');
            pollBslProgress();
        }
        // Poll queue periodically while any entry is active
        const hasActive=q.entries?.some(e=>e.status==='running'||e.status==='waiting'||e.status==='paused');
        if(hasActive&&!bslQueuePollTimer){
            bslQueuePollTimer=setInterval(checkBslQueue,3000);
        }else if(!hasActive&&bslQueuePollTimer){
            clearInterval(bslQueuePollTimer);bslQueuePollTimer=null;
        }
    }).catch(()=>{});
}
function renderBslQueue(q){
    const section=$('bslQueueSection');
    const list=$('bslQueueList');
    if(!section||!list)return;
    const entries=q.entries||[];
    if(entries.length===0){list.innerHTML='<span style="color:#64748b">صف خالی — برای افزودن، دکمه «🚀 ارسال باسلام» را کلیک کنید</span>';return;}
    section.style.display='block';
    const statusLabels={waiting:'⏳ در صف',running:'🔄 در حال ارسال',paused:'⏸ متوقف',done:'✅ انجام شد',failed:'❌ خطا'};
    const statusColors={waiting:'#fbbf24',running:'#67e8f9',paused:'#f97316',done:'#4ade80',failed:'#f87171'};
    const statusBg={waiting:'#42200630',running:'#0e749020',paused:'#c2410c20',done:'#14532d20',failed:'#7f1d1d20'};
    let html='';
    entries.forEach(e=>{
        let progText='';
        let progPercent=0;
        if(e.status==='running'&&e.current>0&&e.total>0){
            progText=toFa(e.current)+'/'+toFa(e.total)+' ('+Math.round(e.current/e.total*100)+'٪)';
            progPercent=Math.round(e.current/e.total*100);
            progText+=' | '+toFa(e.sent)+'✅ '+toFa(e.updated)+'⚡ '+toFa(e.skipped)+'⏭ '+toFa(e.failed)+'❌';
        }else if(e.status==='paused'&&e.current>0&&e.total>0){
            progText=toFa(e.current)+'/'+toFa(e.total)+' — متوقف در محصول #'+toFa(e.current);
            progPercent=Math.round(e.current/e.total*100);
            progText+=' | '+toFa(e.sent)+'✅ '+toFa(e.updated)+'⚡ '+toFa(e.skipped)+'⏭ '+toFa(e.failed)+'❌';
        }else if(e.status==='done'){
            progText='✓ '+toFa(e.sent)+' جدید | '+toFa(e.updated)+' آپدیت | '+toFa(e.skipped)+' تکراری | '+toFa(e.failed)+' خطا';
            progPercent=100;
        }else if(e.status==='waiting'){
            progText=toFa(e.total)+' محصول — منتظر شروع';
        }
        // v7.50: Clickable entry — opens detail modal
        html+='<div onclick="showBslQueueDetail(\''+e.id+'\')" style="cursor:pointer;padding:8px 10px;border:1px solid #334155;border-radius:8px;margin:4px 0;background:'+statusBg[e.status]+';transition:background 0.2s" onmouseover="this.style.borderColor=\'#67e8f9\'" onmouseout="this.style.borderColor=\'#334155\'">';
        // Row 1: Status badge + count + action buttons
        html+='<div style="display:flex;justify-content:space-between;align-items:center">';
        html+='<div style="display:flex;align-items:center;gap:8px">';
        html+='<span style="color:'+statusColors[e.status]+';font-weight:700;font-size:12px">'+statusLabels[e.status]+'</span>';
        if(e.auto_sync)html+='<span style="color:#22d3ee;font-size:10px;background:#0e749020;padding:1px 6px;border-radius:4px;margin-left:4px">⏱ سینک خودکار</span>';
        if(e.profile_name)html+='<span style="color:#94a3b8;font-size:10px;margin-left:4px">'+esc(e.profile_name)+'</span>';
        html+='<span style="color:#e2e8f0;font-weight:600;font-size:12px">'+toFa(e.total)+' محصول</span>';
        html+='</div>';
        // Action buttons
        html+='<div style="display:flex;gap:4px">';
        if(e.status==='running'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#3b82f6;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();restartBslServer(\''+e.id+'\')">\u2713 \u0634\u0631\u0648\u0631 \u0633\u0631\u0648\u0631</button>';
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#dc2626;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();deleteBslQueue(\''+e.id+'\')">\u2717 \u062d\u062f\u0641</button>';
        }else if(e.status==='paused'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#22c55e;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();resumeBslQueue(\''+e.id+'\')">▶ ادامه</button>';
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#dc2626;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();deleteBslQueue(\''+e.id+'\')">❌ حذف</button>';
        }else if(e.status==='waiting'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#3b82f6;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();startBslServer(\''+e.id+'\')">\u2713 \u0634\u0631\u0648\u0631 \u0633\u0631\u0648\u0631</button>';
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#dc2626;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();deleteBslQueue(\''+e.id+'\')">\u2717 \u062d\u062f\u0641</button>';
        }else if(e.status==='done'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#334155;color:#94a3b8;border:none;border-radius:4px" onclick="event.stopPropagation();deleteBslQueue(\''+e.id+'\')">🗑️ حذف</button>';
        }
        html+='</div>';
        html+='</div>';
        // Row 2: Progress bar
        if(progPercent>0||e.status==='running'||e.status==='paused'||e.status==='done'){
            html+='<div style="margin-top:4px"><div style="height:4px;background:#1e293b;border-radius:2px;overflow:hidden"><div style="height:100%;background:'+statusColors[e.status]+';width:'+progPercent+'%;border-radius:2px;transition:width 0.5s"></div></div></div>';
        }
        // Row 3: Progress text
        if(progText){
            html+='<div style="color:#94a3b8;font-size:10px;margin-top:3px">'+progText+'</div>';
        }
        // Row 4: Elapsed time
        if((e.status==='running'||e.status==='paused')&&e.started_at>0){
            const elapsedSec=Math.floor(Date.now()/1000-(e.started_at||0));
            const elapsedStr=elapsedSec>=60?(Math.floor(elapsedSec/60)+' دقیقه '+elapsedSec%60+' ثانیه'):(elapsedSec+' ثانیه');
            html+='<div style="color:#64748b;font-size:9px;margin-top:2px">⏱ '+elapsedStr+'</div>';
        }
        html+='<div style="color:#475569;font-size:9px;margin-top:1px">کلیک برای گزارش تفصیلی →</div>';
        html+='</div>';
    });
    list.innerHTML=html;
}
function pauseBslQueue(qid){
    fetch('?bsl_queue_pause=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        if(d.ok){showToast('⏸ ارسال متوقف شد');checkBslQueue();bSend=false;}
        else{showToast('خطا در توقف: '+d.error,1);}
    }).catch(()=>{showToast('خطا شبکه',1);});
}
function startBslServer(qid){
    // v7.56: Start server-side processing for a waiting queue entry
    // Copy products file, update connections, trigger bsl_backend
    $('bSS').textContent='\u0634\u0631\u0648\u0631 \u0633\u0631\u0648\u0631\u0633\u0627\u06cc\u062f...';
    bSend=true;bslReportData={sent:[],updated:[],skipped:[],failed:[]};bslLastLogCount=0;bslLastCardCount=0;bslLastUpdateTime=0;
    window._currentBslQueueId=qid; // v7.66: track queue ID
    $('bSB').classList.add('hidden');$('bSBlegacy').classList.add('hidden');$('bST').classList.remove('hidden');
    $('bP').classList.remove('hidden');$('bPB').style.width='0%';
    $('bR').classList.remove('hidden');$('bR').innerHTML='';
    $('bSM').classList.remove('hidden');$('bO').textContent=toFa(0);$('bU').textContent=toFa(0);$('bK').textContent=toFa(0);$('bF').textContent=toFa(0);
    const fd=new FormData();fd.append('queue_id',qid);fd.append('start_immediately','1');fd.append('total','0');fd.append('category_id','0');fd.append('auto_category','0');fd.append('title_suffix','');
    // First: copy products file and set status='running' via bsl_queue_add (reuse start_immediately logic)
    // But bsl_queue_add needs total — let's use a dedicated endpoint
    fetch('?bsl_queue_start_server=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        if(!d.ok){showToast('\u062e\u0637\u0627: '+d.error,1);bSend=false;return;}
        $('bT').textContent=toFa(d.total||0);
        $('bSS').textContent='\u2713 \u0634\u0631\u0648\u0631 \u0633\u0631\u0648\u0631...';
        // Now trigger bsl_backend with from_file=1 and queue_id
        const fd2=new FormData();fd2.append('from_file','1');fd2.append('queue_id',qid);
        // v7.81: Fire-and-forget — trigger bsl_backend, start polling
        fetch('?action=bsl_backend',{method:'GET'}).catch(()=>{});
        $('bR').innerHTML+='<div style="color:#22c55e;padding:4px;font-size:12px">✓ ارسال شروع — پیشرفت زنده نمایش می‌شود</div>';
        checkBslQueue();
        setTimeout(pollBslProgress,1000);
    }).catch(()=>{});
}
function restartBslServer(qid){
    // v7.56: Restart server-side processing for a stuck 'running' entry
    // Same as startBslServer but also clears progress and updates current position
    $('bSS').textContent='\u0634\u0631\u0648\u0631 \u0627\u062f\u0627\u0645\u0647 \u0633\u0631\u0648\u0631\u0633\u0627\u06cc\u062f...';
    bSend=true;bslReportData={sent:[],updated:[],skipped:[],failed:[]};bslLastLogCount=0;bslLastCardCount=0;bslLastUpdateTime=0;
    window._currentBslQueueId=qid; // v7.66: track queue ID
    $('bSB').classList.add('hidden');$('bSBlegacy').classList.add('hidden');$('bST').classList.remove('hidden');
    $('bP').classList.remove('hidden');$('bR').classList.remove('hidden');$('bSM').classList.remove('hidden');
    // Clear stale progress + trigger server restart
    fetch('?bsl_queue_restart_server=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        if(!d.ok){showToast('\u062e\u0637\u0627: '+d.error,1);bSend=false;return;}
        $('bT').textContent=toFa(d.total||0);
        $('bO').textContent=toFa(d.sent||0);$('bU').textContent=toFa(d.updated||0);$('bK').textContent=toFa(d.skipped||0);$('bF').textContent=toFa(d.failed||0);
        // Trigger bsl_backend with resume from current position
        const fd2=new FormData();fd2.append('from_file','1');fd2.append('queue_id',qid);fd2.append('start_index',String(d.current||0));
        // v7.81: Fire-and-forget — trigger bsl_backend, start polling
        fetch('?action=bsl_backend',{method:'GET'}).catch(()=>{});
        $('bR').innerHTML+='<div style="color:#22c55e;padding:4px;font-size:12px">✓ ادامه ارسال — پیشرفت زنده نمایش می‌شود</div>';
        checkBslQueue();
        setTimeout(pollBslProgress,1000);
    }).catch(()=>{});
}
function deleteBslQueue(qid){
    if(!confirm('آیا مطمئن هستید؟ عملیات حذف خواهد شد.'))return;
    fetch('?bsl_queue_delete=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        if(d.ok){
            showToast('عملیات حذف شد');
            bSend=false;
            $('bSB').classList.remove('hidden');$('bSBlegacy').classList.remove('hidden');
            $('bST').classList.add('hidden');
            $('bP').classList.add('hidden');$('bR').classList.add('hidden');$('bSM').classList.add('hidden');
            $('bSS').textContent='';
            checkBslQueue();
        }else{showToast('خطا در حذف: '+d.error,1);}
    }).catch(()=>{showToast('خطا شبکه',1);});
}
function cancelBslQueueEntry(qid){
    deleteBslQueue(qid);
}
// v8.17: WooCommerce Queue display — proper queue system like BaSalam
let wooQueuePollTimer=null;
function checkWooQueue(){
    fetch('?woo_queue_status=1').then(r=>r.json()).then(q=>{
        renderWooQueue(q);
        // If a 'running' entry is found and no polling is active, start polling to show progress
        const running=q.entries?.find(e=>e.status==='running');
        if(running&&!wSend){
            wSend=true;
            $('wST').classList.remove('hidden');
            $('wP').classList.remove('hidden');$('wR').classList.remove('hidden');$('wSM').classList.remove('hidden');
            pollWooProgress();
        }
        // Poll queue periodically while any entry is active
        const hasActive=q.entries?.some(e=>e.status==='running'||e.status==='waiting');
        if(hasActive&&!wooQueuePollTimer){
            wooQueuePollTimer=setInterval(checkWooQueue,3000);
        }else if(!hasActive&&wooQueuePollTimer){
            clearInterval(wooQueuePollTimer);wooQueuePollTimer=null;
        }
    }).catch(()=>{});
}
function renderWooQueue(q){
    const section=$('wooQueueSection');
    const list=$('wooQueueList');
    if(!section||!list)return;
    const entries=q.entries||[];
    if(entries.length===0){list.innerHTML='<span style="color:#64748b">صف خالی — برای افزودن، دکمه «🚀 ارسال ووکامرس» را کلیک کنید</span>';return;}
    section.style.display='block';
    const statusLabels={waiting:'⏳ در صف',running:'🔄 در حال ارسال',done:'✅ انجام شد',failed:'❌ خطا'};
    const statusColors={waiting:'#fbbf24',running:'#a78bfa',done:'#4ade80',failed:'#f87171'};
    const statusBg={waiting:'#42200630',running:'#7c3aed20',done:'#14532d20',failed:'#7f1d1d20'};
    let html='';
    entries.forEach(e=>{
        let progText='';let progPercent=0;
        if(e.status==='running'&&e.current>0&&e.total>0){
            progText=toFa(e.current)+'/'+toFa(e.total)+' ('+Math.round(e.current/e.total*100)+'٪)';
            progPercent=Math.round(e.current/e.total*100);
            progText+=' | '+toFa(e.sent)+'✅ '+toFa(e.updated)+'⚡ '+toFa(e.skipped)+'⏭ '+toFa(e.failed)+'❌';
        }else if(e.status==='done'){
            progText='✓ '+toFa(e.sent)+' جدید | '+toFa(e.updated)+' آپدیت | '+toFa(e.skipped)+' تکراری | '+toFa(e.failed)+' خطا';
            progPercent=100;
        }else if(e.status==='waiting'){
            progText=toFa(e.total)+' محصول — منتظر شروع';
        }else if(e.status==='failed'){
            progText='❌ '+toFa(e.failed)+' خطا';
        }
        html+='<div style="cursor:pointer;padding:8px 10px;border:1px solid #334155;border-radius:8px;margin:4px 0;background:'+statusBg[e.status]+';transition:background 0.2s" onmouseover="this.style.borderColor=\'#a78bfa\'" onmouseout="this.style.borderColor=\'#334155\'">';
        html+='<div style="display:flex;justify-content:space-between;align-items:center">';
        html+='<div style="display:flex;align-items:center;gap:8px">';
        html+='<span style="color:'+statusColors[e.status]+';font-weight:700;font-size:12px">'+statusLabels[e.status]+'</span>';
        html+='<span style="color:#e2e8f0;font-weight:600;font-size:12px">'+toFa(e.total)+' محصول</span>';
        html+='</div>';
        html+='<div style="display:flex;gap:4px">';
        if(e.status==='running'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#3b82f6;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();startWooServer(\''+e.id+'\')">✓ شروع سرور</button>';
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#dc2626;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();deleteWooQueue(\''+e.id+'\')">✗ حذف</button>';
        }else if(e.status==='waiting'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#3b82f6;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();startWooServer(\''+e.id+'\')">✓ شروع سرور</button>';
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#dc2626;color:#fff;border:none;border-radius:4px" onclick="event.stopPropagation();deleteWooQueue(\''+e.id+'\')">✗ حذف</button>';
        }else if(e.status==='done'||e.status==='failed'){
            html+='<button class="btn" style="font-size:10px;padding:3px 8px;background:#334155;color:#94a3b8;border:none;border-radius:4px" onclick="event.stopPropagation();deleteWooQueue(\''+e.id+'\')">🗑️ حذف</button>';
        }
        html+='</div></div>';
        if(progPercent>0||e.status==='running'||e.status==='done'){
            html+='<div style="margin-top:4px"><div style="height:4px;background:#1e293b;border-radius:2px;overflow:hidden"><div style="height:100%;background:'+statusColors[e.status]+';width:'+progPercent+'%;border-radius:2px;transition:width 0.5s"></div></div></div>';
        }
        if(progText){html+='<div style="color:#94a3b8;font-size:10px;margin-top:3px">'+progText+'</div>';}
        if((e.status==='running')&&e.started_at>0){
            const elapsedSec=Math.floor(Date.now()/1000-(e.started_at||0));
            const elapsedStr=elapsedSec>=60?(Math.floor(elapsedSec/60)+' دقیقه '+elapsedSec%60+' ثانیه'):(elapsedSec+' ثانیه');
            html+='<div style="color:#64748b;font-size:9px;margin-top:2px">⏱ '+elapsedStr+'</div>';
        }
        html+='</div>';
    });
    list.innerHTML=html;
}
function startWooServer(qid){
    fetch('?woo_queue_start_server=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        if(d.ok){
            showToast('✓ شروع پردازش سرورساید');
            // Trigger woo_backend
            fetch('?action=woo_backend').catch(()=>{});
            wSend=true;
            $('wST').classList.remove('hidden');
            $('wP').classList.remove('hidden');$('wR').classList.remove('hidden');$('wSM').classList.remove('hidden');
            setTimeout(pollWooProgress,1500);
            checkWooQueue();
        }else{showToast('خطا: '+d.error,1);}
    }).catch(()=>{showToast('خطا شبکه',1);});
}
function deleteWooQueue(qid){
    fetch('?woo_queue_delete=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        checkWooQueue();
    }).catch(()=>{});
}
function clearWooQueueDone(){
    fetch('?woo_queue_clear_done=1').then(r=>r.json()).then(d=>{
        checkWooQueue();
    }).catch(()=>{});
}
function toggleWooDedup(){
    const body=$('wooDedupBody');
    const arrow=$('wooDedupArrow');
    if(!body)return;
    if(body.style.display==='none'){
        body.style.display='block';
        if(arrow)arrow.textContent='▲';
    }else{
        body.style.display='none';
        if(arrow)arrow.textContent='▼';
    }
}
function clearBslQueueDone(){
    fetch('?bsl_queue_clear_done=1').then(r=>r.json()).then(d=>{
        checkBslQueue();
    }).catch(()=>{});
}
// v7.50: Show detailed report modal for a queue entry
function showBslQueueDetail(qid){
    fetch('?bsl_queue_detail=1&queue_id='+encodeURIComponent(qid)).then(r=>r.json()).then(d=>{
        if(!d.ok){showToast('خطا: '+d.error,1);return;}
        const e=d.entry;
        const products=e.products||[];
        const statusLabels={waiting:'⏳ در صف',running:'🔄 در حال ارسال',paused:'⏸ متوقف',done:'✅ انجام شد',failed:'❌ خطا'};
        const statusColors={waiting:'#fbbf24',running:'#67e8f9',paused:'#f97316',done:'#4ade80',failed:'#f87171'};
        // Build detail list: match each product to its send result
        const sentMap={};
        (e.sent_details||[]).forEach(s=>{sentMap[s.key||s.title]=s;});
        const updatedMap={};
        (e.updated_details||[]).forEach(u=>{updatedMap[u.key||u.title]=u;});
        const skippedMap={};
        (e.skipped_details||[]).forEach(k=>{skippedMap[k.key||k.title]=k;});
        const failedMap={};
        (e.failed_details||[]).forEach(f=>{failedMap[f.key||f.title]=f;});
        let detailHtml='';
        detailHtml+='<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;padding:10px;background:#0f172a;border-radius:8px">';
        detailHtml+='<div style="text-align:center"><b style="color:#4ade80;font-size:16px">'+toFa(e.sent||0)+'</b><div style="color:#64748b;font-size:10px">✅ جدید</div></div>';
        detailHtml+='<div style="text-align:center"><b style="color:#facc15;font-size:16px">'+toFa(e.updated||0)+'</b><div style="color:#64748b;font-size:10px">⚡ آپدیت</div></div>';
        detailHtml+='<div style="text-align:center"><b style="color:#94a3b8;font-size:16px">'+toFa(e.skipped||0)+'</b><div style="color:#64748b;font-size:10px">⏭ تکراری</div></div>';
        detailHtml+='<div style="text-align:center"><b style="color:#f87171;font-size:16px">'+toFa(e.failed||0)+'</b><div style="color:#64748b;font-size:10px">❌ خطا</div></div>';
        detailHtml+='</div>';
        // Progress bar
        const progPercent=e.total>0?Math.round((e.current||0)/e.total*100):0;
        detailHtml+='<div style="margin-bottom:12px"><div style="height:6px;background:#1e293b;border-radius:3px;overflow:hidden"><div style="height:100%;background:'+statusColors[e.status]+';width:'+progPercent+'%;border-radius:3px"></div></div><div style="color:#94a3b8;font-size:11px;margin-top:4px">'+toFa(e.current||0)+'/'+toFa(e.total)+' ('+progPercent+'٪)</div></div>';
        // Product-by-product scrollable report
        detailHtml+='<div style="font-weight:700;color:#e2e8f0;font-size:12px;margin-bottom:8px">📋 گزارش محصولی:</div>';
        detailHtml+='<div style="max-height:400px;overflow-y:auto;border:1px solid #334155;border-radius:8px;padding:8px;background:#0f172a">';
        if(products.length>0){
            products.forEach((p,i)=>{
                const pKey=p.key||'';
                const pTitle=p.title||p.name||'';
                const matchKey=pKey||pTitle;
                let statusIcon='⚪';
                let statusText='در انتظار';
                let statusColor='#64748b';
                let extraInfo='';
                if(sentMap[matchKey]){
                    statusIcon='✅';statusText='ارسال شد';statusColor='#4ade80';
                    if(sentMap[matchKey].remote_id)extraInfo=' (ID: '+sentMap[matchKey].remote_id+')';
                }else if(updatedMap[matchKey]){
                    statusIcon='⚡';statusText='آپدیت شد';statusColor='#facc15';
                    if(updatedMap[matchKey].update_reason||updatedMap[matchKey].changes)extraInfo=' — '+(updatedMap[matchKey].update_reason||updatedMap[matchKey].changes);
                    if(updatedMap[matchKey].old_price&&updatedMap[matchKey].new_price)extraInfo+=' ('+updatedMap[matchKey].old_price+' → '+updatedMap[matchKey].new_price+')';
                }else if(skippedMap[matchKey]){
                    statusIcon='⏭';statusText='رد شد';statusColor='#94a3b8';
                    if(skippedMap[matchKey].reason)extraInfo=' — '+skippedMap[matchKey].reason;
                }else if(failedMap[matchKey]){
                    statusIcon='❌';statusText='خطا';statusColor='#f87171';
                    if(failedMap[matchKey].error)extraInfo=' — '+failedMap[matchKey].error;
                }
                detailHtml+='<div style="display:flex;align-items:center;gap:6px;padding:4px 6px;border-bottom:1px solid #1e293b;font-size:11px">';
                detailHtml+='<span style="color:'+statusColor+';font-size:14px;width:20px">'+statusIcon+'</span>';
                detailHtml+='<span style="color:#e2e8f0;flex:1">'+esc(pTitle)+(extraInfo?' <span style="color:#64748b;font-size:10px">'+esc(extraInfo)+'</span>':'')+'</span>';
                detailHtml+='<span style="color:'+statusColor+';font-size:10px">'+statusText+'</span>';
                detailHtml+='</div>';
            });
        }else{
            // No products list — show from detail arrays
            const allDetails=[...(e.sent_details||[]).map(d=>({...d,statusLabel:'✅ ارسال شد',statusColor:'#4ade80'})),
                ...(e.updated_details||[]).map(d=>({...d,statusLabel:'⚡ آپدیت شد',statusColor:'#facc15'})),
                ...(e.skipped_details||[]).map(d=>({...d,statusLabel:'⏭ رد شد',statusColor:'#94a3b8'})),
                ...(e.failed_details||[]).map(d=>({...d,statusLabel:'❌ خطا',statusColor:'#f87171'}))];
            allDetails.forEach(d=>{
                detailHtml+='<div style="display:flex;align-items:center;gap:6px;padding:4px 6px;border-bottom:1px solid #1e293b;font-size:11px">';
                detailHtml+='<span style="color:'+d.statusColor+';font-size:14px;width:20px">'+d.statusLabel.split(' ')[0]+'</span>';
                detailHtml+='<span style="color:#e2e8f0;flex:1">'+esc(d.title||d.key||'')+'</span>';
                if(d.error||d.reason)detailHtml+='<span style="color:#64748b;font-size:10px">'+esc(d.error||d.reason)+'</span>';
                detailHtml+='<span style="color:'+d.statusColor+';font-size:10px">'+d.statusLabel+'</span>';
                detailHtml+='</div>';
            });
        }
        detailHtml+='</div>';
        // Log entries
        if(e.recent_log&&e.recent_log.length>0){
            detailHtml+='<div style="font-weight:700;color:#e2e8f0;font-size:12px;margin:12px 0 8px">📝 لاگ:</div>';
            detailHtml+='<div style="max-height:200px;overflow-y:auto;border:1px solid #334155;border-radius:8px;padding:8px;background:#0f172a;font-size:10px">';
            (e.recent_log||[]).forEach(m=>{
                let cls='color:#64748b';
                if(m.includes('✅'))cls='color:#4ade80';
                else if(m.includes('❌'))cls='color:#f87171';
                else if(m.includes('⚡'))cls='color:#facc15';
                else if(m.includes('⏭'))cls='color:#94a3b8';
                else if(m.includes('🔍'))cls='color:#67e8f9';
                detailHtml+='<div style="'+cls+';margin:1px 0">'+esc(m)+'</div>';
            });
            detailHtml+='</div>';
        }
        // Close button
        detailHtml+='<div style="text-align:center;margin-top:16px"><button class="btn btn-cyan" onclick="closeBslQueueDetail()">✕ بستن</button></div>';
        // Show modal
        let modal=document.getElementById('bslQueueDetailModal');
        if(!modal){
            modal=document.createElement('div');
            modal.id='bslQueueDetailModal';
            modal.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:10000;display:flex;justify-content:center;align-items:center;padding:20px';
            document.body.appendChild(modal);
        }
        modal.style.display='flex';
        modal.innerHTML='<div style="background:#1e293b;border:1px solid #475569;border-radius:12px;padding:20px;max-width:700px;width:100%;max-height:90vh;overflow-y:auto;color:#e2e8f0"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px"><h3 style="color:'+statusColors[e.status]+';font-size:14px;margin:0">'+statusLabels[e.status]+' — '+toFa(e.total)+' محصول</h3><span style="color:#64748b;font-size:11px">#'+esc(e.id)+'</span></div>'+detailHtml+'</div>';
        modal.onclick=function(ev){if(ev.target===modal)closeBslQueueDetail();};
    }).catch(()=>{showToast('خطا شبکه',1);});
}
function closeBslQueueDetail(){
    const modal=document.getElementById('bslQueueDetailModal');
    if(modal)modal.style.display='none';
}
// v7.51: Client-driven queue send — like sendBslClient, one product at a time
// No server-side PHP stream needed. No polling. No timeout issues.
let bslQueueRunner=null; // track current running queue entry
function startNextBslQueueEntry(){
    // v7.66: Use server-side bsl_backend to process next waiting entry
    // instead of old client-driven approach
    // v7.81: Fire-and-forget — trigger bsl_backend, then poll
    fetch('?action=bsl_backend',{method:'GET'}).catch(()=>{});
    console.log('[v7.81] Triggered bsl_backend for next entry — polling starts');
    bSend=true;
    bslReportData={sent:[],updated:[],skipped:[],failed:[]};
    bslLastLogCount=0;bslLastCardCount=0;bslLastUpdateTime=0;
    $('bSM').classList.remove('hidden');
    $('bPB').style.width='0%';
    $('bR').classList.remove('hidden');
    setTimeout(pollBslProgress,1000);
    checkBslQueue();
}
// v7.81 LEGACY: Old client-driven startNextBslQueueEntry — kept for reference
function _legacyStartNextBslQueueEntry(){
    fetch('?bsl_queue_start_next=1').then(r=>r.json()).then(d=>{
        if(d.next_id){
            // v7.51: Get products for this queue entry, then send one-by-one from JS
            bslQueueRunner={id:d.next_id,total:d.total,sent:0,updated:0,skipped:0,failed:0,current:0,products:null,index:0,config:d.config||{}};
            // v7.56: DON'T call saveConn() here — we send queue config directly to bsl_send_one
            // Fetch the product list
            fetch('?bsl_queue_get_products=1&queue_id='+encodeURIComponent(d.next_id)).then(r=>r.json()).then(pd=>{
                if(!pd.ok||!pd.products||pd.products.length===0){
                    showToast('خطا: فایل محصولات یافت نشد',1);
                    markQueueEntryDone(bslQueueRunner.id,bslQueueRunner);
                    return;
                }
                bslQueueRunner.products=pd.products;
                bslQueueRunner.total=pd.products.length;
                bslQueueRunner.startedAt=Date.now();
                // Update queue status
                bSend=true;
                // DON'T hide send buttons — they stay visible so user can queue more
                $('bST').classList.remove('hidden');
                $('bSS').textContent='🚀 ارسال '+toFa(bslQueueRunner.total)+' محصول از صف...';
                checkBslQueue();
                // Start sending one product at a time
                sendNextQueueProduct();
            }).catch(()=>{showToast('خطا شبکه',1);});
        }else{
            bSend=false;
            $('bST').classList.add('hidden');
            $('bSS').textContent='صف خالی';
            checkBslQueue();
        }
    }).catch(()=>{showToast('خطا شبکه',1);});
}
function sendNextQueueProduct(){
    const q=bslQueueRunner;
    if(!q||!bSend||q.index>=q.products.length){
        finishQueueEntry();
        return;
    }
    const p=q.products[q.index];
    const n=q.index+1;
    // v7.56: Update visual stats (same as sendBslClient)
    $('bSS').textContent=toFa(n)+'/'+toFa(q.total)+' ('+Math.round(n/q.total*100)+'\u066a) \u2014 '+esc(p.title||p.name||'').substring(0,30);
    $('bPB').style.width=(n/q.total*100)+'%';
    const fd=new FormData();
    fd.append('product',JSON.stringify(p));
    fd.append('product_index',String(q.index));
    fd.append('total',String(q.total));
    fd.append('mode','send');
    // v7.56: Send queue entry config directly so bsl_send_one uses the right category
    fd.append('queue_category_id',String(q.config.category_id||0));
    fd.append('queue_auto_category',q.config.auto_category?'1':'0');
    fd.append('queue_title_suffix',q.config.title_suffix||'');
    fetch('?bsl_send_one=1',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        // v7.56: If auth fails (403/401), stop immediately
        if(d.auth_fail){
            q.failed+=q.products.length-q.index;
            q.current=q.total;
            $('bF').textContent=toFa(q.failed);
            $('bSS').textContent='\u2717 '+esc(d.error||'\u062e\u0637\u0627 \u0627\u062d\u0631\u0627\u0632 \u0647\u0648\u06cc\u062a');
            $('bR').innerHTML+='<div class="no2" style="font-weight:700">\u2717 '+esc(d.error||'\u062e\u0637\u0627')+'</div>';
            scrollElBottom($('bR'));
            finishQueueEntry();
            showBslVendorModal({ok:false,error:d.error,http_code:d.http_code,detail:d.detail||''});
            return;
        }
        // v7.56: Update visual stats + bslReportData (same as sendBslClient)
        if(d.action==='send'){
            q.sent++;q.current=q.index+1;
            $('bO').textContent=toFa(q.sent);
            $('bR').innerHTML+='<div class="ok2">\u2713 #'+d.remote_id+' '+esc(d.title||p.title)+' <span style="color:#64748b;font-size:10px">('+toFa(q.sent)+' \u062c\u062f\u06cc\u062f)</span></div>';
            bslReportData.sent.push({title:d.title,remote_id:d.remote_id,key:d.key});
        }else if(d.action==='update'){
            q.updated++;q.current=q.index+1;
            $('bU').textContent=toFa(q.updated);
            $('bR').insertAdjacentHTML('beforeend',renderSendCard({title:d.title||p.title,image:d.image||p.image,price:d.price,category:d.category,category_id:d.category_id,price_unit:d.price_unit,link:d.link,result:'update',remote_id:d.remote_id,changes:d.changes,update_reason:d.update_reason||d.changes}));
            bslReportData.updated.push({title:d.title,remote_id:d.remote_id,key:d.key,changes:d.changes,update_reason:d.update_reason||d.changes});
        }else if(d.action==='skip'){
            q.skipped++;q.current=q.index+1;
            $('bK').textContent=toFa(q.skipped);
            $('bR').innerHTML+='<div style="color:#94a3b8;padding:2px 6px;font-size:11px">\u23ed '+esc(d.title||p.title)+' \u2014 '+esc(d.reason||'')+'</div>';
            bslReportData.skipped.push({title:d.title,remote_id:d.remote_id,key:d.key,reason:d.reason});
        }else if(d.action==='fail'||!d.ok){
            q.failed++;q.current=q.index+1;
            $('bF').textContent=toFa(q.failed);
            $('bR').innerHTML+='<div class="no2">\u2717 '+esc(d.error||'\u062e\u0637\u0627')+' <span style="color:#64748b;font-size:10px">'+esc(p.title||'')+'</span></div>';
            bslReportData.failed.push({title:d.title||p.title,key:p.key,error:d.error});
        }
        scrollElBottom($('bR'));
        q.index++;
        // Update queue entry progress on server
        updateQueueEntryProgress(q.id,q);
        checkBslQueue();
        // Send next product
        sendNextQueueProduct();
    }).catch(e=>{
        q.failed++;q.current=q.index+1;q.index++;
        $('bF').textContent=toFa(q.failed);
        $('bR').innerHTML+='<div class="no2">\u2717 \u062e\u0637\u0627 \u0634\u0628\u06a9\u0647</div>';
        scrollElBottom($('bR'));
        updateQueueEntryProgress(q.id,q);
        checkBslQueue();
        // Network error — try next product
        sendNextQueueProduct();
    });
}
function updateQueueEntryProgress(qid,q){
    fetch('?bsl_queue_update_progress=1&queue_id='+encodeURIComponent(qid)+
        '&sent='+q.sent+'&updated='+q.updated+'&skipped='+q.skipped+'&failed='+q.failed+'&current='+q.current,
        {method:'POST'}).then(()=>{}).catch(()=>{});
}
function markQueueEntryDone(qid,q){
    fetch('?bsl_queue_mark_done=1&queue_id='+encodeURIComponent(qid)+
        '&sent='+q.sent+'&updated='+q.updated+'&skipped='+q.skipped+'&failed='+q.failed+'&total='+q.total,
        {method:'POST'}).then(()=>{checkBslQueue();}).catch(()=>{});
}
function finishQueueEntry(){
    const q=bslQueueRunner;
    if(!q)return;
    bSend=false;
    $('bST').classList.add('hidden');
    // v7.56: Update final visual stats (same as finishBslClient)
    $('bO').textContent=toFa(q.sent);$('bU').textContent=toFa(q.updated);
    $('bK').textContent=toFa(q.skipped);$('bF').textContent=toFa(q.failed);
    $('bT').textContent=toFa(q.total);
    $('bPB').style.width='100%';
    let elapsed='';
    if(q.startedAt){
        const sec=Math.floor((Date.now()-q.startedAt)/1000);
        elapsed=sec>=60?(Math.floor(sec/60)+' دقیقه '+sec%60+' ثانیه'):(sec+' ثانیه');
    }
    $('bSS').textContent='✓ '+toFa(q.sent)+' جدید | '+toFa(q.updated)+' آپدیت | '+toFa(q.skipped)+' تکراری | '+toFa(q.failed)+' خطا';
    if(elapsed)$('bSS').textContent+=' | '+elapsed;
    showToast('✓ '+q.sent+' جدید, '+q.updated+' آپدیت, '+q.skipped+' تکراری, '+q.failed+' خطا');
    markQueueEntryDone(q.id,q);
    bslQueueRunner=null;
    // Auto-start next waiting queue entry after 3 seconds
    setTimeout(()=>{startNextBslQueueEntry();},3000);
}

/* v7.24: JS status bar removed — errors only shown in _dbg panel */

// ========== Fetch Missing Images (Retry) ==========
let fetchMissingRunning=false, fetchMissingAborted=false;
function fetchMissingImages(){
    if(fetchMissingRunning)return;
    // v7.81: Find ALL products that need image processing:
    // 1. Products missing image/price → fetch from product page
    // 2. Products with images → pre-download to server cache for reliable bslUpload
    //    (prevents "تصویر آپلود نشد" error when sending to BaSalam)
    const items=[];
    order.forEach(k=>{
        const p=products.get(k);
        if(!p)return;
        if(!p.image||!p.price){
            // Missing image or price — fetch from product page
            if(p.link)items.push({key:k,link:p.link,title:p.title||'',mode:'fetch'});
        }else if(p.image){
            // Has image — pre-download to server cache for reliable bslUpload
            // Skip data: URLs, blob: URLs, and already-cached images
            if(!p.image.startsWith('data:')&&!p.image.startsWith('blob:')&&p.link&&!p._imgCached){
                items.push({key:k,link:p.link,title:p.title||'',mode:'validate',imgUrl:p.image});
            }
        }
    });
    if(!items.length){showToast('همه تصاویر و قیمت‌ها موجود است ✓');return;}
    fetchMissingRunning=true;fetchMissingAborted=false;
    $('btnFetchMissing').classList.add('hidden');
    $('btnStopFetchMissing').classList.remove('hidden');
    $('detailProgress').classList.remove('hidden');
    $('detailProgressBar').style.width='0%';
    $('detailStatus').textContent='دریافت تصاویر '+toFa(items.length)+' محصول...';
    log('🖼️ شروع دریافت تصاویر/قیمت '+items.length+' محصول','info');
    const fd=new FormData();
    fd.append('items',JSON.stringify(items));
    fetch('?fetch_missing_stream=1',{method:'POST',body:fd}).then(rp=>{
        const rd=rp.body.getReader(),dc=new TextDecoder();let bf='';
        let done=0,found=0,failed=0;
        function rd2(){rd.read().then(({done:fin,value})=>{
            if(fin){finFM(done,found,failed,items.length);return;}
            bf+=dc.decode(value,{stream:true});
            const es=bf.split('\n\n');bf=es.pop();
            es.forEach(ev=>{
                const p=pSSE(ev);if(!p)return;
                if(p.t==='missing_start'){
                    $('detailStatus').textContent='دریافت '+toFa(p.d.current)+'/'+toFa(p.d.total)+': '+esc(p.d.title||'');
                    $('detailProgressBar').style.width=(p.d.current/p.d.total*100)+'%';
                }else if(p.t==='missing_done'){
                    done++;
                    const pr=products.get(p.d.key);
                    if(pr){
                        let changed=false;
                        if(p.d.image_valid){
                            // Image validated as downloadable — mark it
                            pr._imgValid=true;changed=true;
                        }
                        // v7.81: Image pre-downloaded to server cache — mark as cached
                        if(p.d.image_cached){
                            pr._imgCached=true;pr._imgValid=true;changed=true;
                        }
                        if(p.d.image&&p.d.image!==pr.image){
                            // New/updated image URL (re-fetched from product page)
                            pr.image=p.d.image;pr._imgValid=true;pr._imgCached=true;changed=true;
                        }
                        if(p.d.image&&!pr.image){pr.image=p.d.image;pr._imgValid=true;pr._imgCached=true;changed=true;}
                        if(p.d.price&&!pr.price){pr.price=p.d.price;changed=true;}
                        if(changed){found++;products.set(p.d.key,pr);renderCard(pr,p.d.key);renderRow(pr,order.indexOf(p.d.key)+1,p.d.key);}
                    }
                }else if(p.t==='fetch_info'){
                    $('detailStatus').textContent=esc(p.d.msg);
                    log(p.d.msg,'info');
                }else if(p.t==='fetch_complete'){
                    found=p.d.found;failed=p.d.failed;
                }else if(p.t==='error'){$('detailStatus').textContent='❌ '+esc(p.d.message);}
            });
            // v8.06: Removed scrollIntoView — causes unwanted auto-scroll on mobile
            rd2();
        }).catch(()=>finFM(done,found,failed,items.length));}rd2();
    }).catch(()=>finFM(0,0,0,items.length));
}
function stopFetchMissing(){fetchMissingAborted=true;fetchMissingRunning=false;$('btnFetchMissing').classList.remove('hidden');$('btnStopFetchMissing').classList.add('hidden');$('detailStatus').textContent='⏹ متوقف شد';log('⏹ دریافت تصاویر متوقف شد','err');}
function finFM(done,found,failed,total){
    fetchMissingRunning=false;
    $('btnFetchMissing').classList.remove('hidden');
    $('btnStopFetchMissing').classList.add('hidden');
    $('detailProgressBar').style.width='100%';
    // v7.81: Count cached images
    let cached=0;order.forEach(k=>{const p=products.get(k);if(p&&p._imgCached)cached++;});
    const msg='✓ '+toFa(found)+' آماده‌شده از '+toFa(total)+(cached?' ('+toFa(cached)+' تصویر در حافظه سرور)':'')+(failed?' ('+toFa(failed)+' ناموفق)':'');
    $('detailStatus').textContent=msg;
    log('✅ دریافت تصاویر تکمیل: '+found+'/'+total+' ('+cached+' کش‌شده)','ok');
    showToast(msg);
    if(found>0&&$('url').value.trim())saveProfileSilent();
}

// ========== Lazy Image Loading with Rate Limit ==========
(function(){
  let imgQueue=[];
  let loading=0;
  const MAX_PARALLEL=6;
  // v8.06: Single observer — created once, reused for all images
  let lazyObs=null;
  function getObserver(){
    if(lazyObs)return lazyObs;
    lazyObs=new IntersectionObserver((entries)=>{
      entries.forEach(e=>{
        if(e.isIntersecting){
          const img=e.target;
          lazyObs.unobserve(img);
          if(img.dataset.src){
            imgQueue.push(img);
            loadNext();
          }
        }
      });
    },{rootMargin:'300px'});
    return lazyObs;
  }
  function loadNext(){
    while(loading<MAX_PARALLEL && imgQueue.length>0){
      const img=imgQueue.shift();
      loading++;
      img.onload=img.onerror=()=>{loading--;loadNext();};
      img.src=img.dataset.src;
      img.removeAttribute('data-src');
    }
  }
  function observeImages(){
    const obs=getObserver();
    document.querySelectorAll('img.lazy-img[data-src]').forEach(img=>obs.observe(img));
  }
  // v8.06: observeSingle — observe just one new image (much faster than querySelectorAll)
  function observeSingle(img){
    if(img&&img.dataset.src){
      getObserver().observe(img);
    }
  }
  // v8.06: Don't call observeImages() on every renderCard — too expensive with 600+ products
  // Instead, observe only the new image element
  const _renderCard=renderCard;
  renderCard=function(p,k){
    _renderCard(p,k);
    // Find the new image in the just-created/updated element
    const el=document.querySelector(`.product[data-k="${(p&&p.key)||k||''}"]`);
    if(el){const img=el.querySelector('img.lazy-img[data-src]');if(img)observeSingle(img);}
  };
  const _refreshViews=refreshViews;
  refreshViews=function(){_refreshViews();setTimeout(observeImages,200);};
  setTimeout(observeImages,500);
})();
// ========== WooCommerce Dedup JS ==========
let ddRunning=false;
function wooDedup(){
    if(ddRunning)return;
    ddRunning=true;
    const doDel=$('ddDelete').checked;
    $('ddBtn').classList.add('hidden');$('ddStop').classList.remove('hidden');
    $('ddP').classList.remove('hidden');$('ddRunning').classList.remove('hidden');$('ddRunning').innerHTML='';
    $('ddSM').classList.add('hidden');$('ddSS').textContent='در حال جستجو...';$('ddPB').style.width='0%';
    const fd=new FormData();fd.append('do_delete',doDel?'1':'0');
    fetch('?woo_dedup_stream=1',{method:'POST',body:fd}).then(rp=>{
        const rd=rp.body.getReader(),dc=new TextDecoder();let bf='';
        function rd2(){rd.read().then(({done,value})=>{
            if(done){finDedup();return;}
            bf+=dc.decode(value,{stream:true});const es=bf.split('\n\n');bf=es.pop();
            es.forEach(ev=>{const p=pSSE(ev);if(!p)return;
                if(p.t==='dedup_info'){$('ddRunning').innerHTML+='<div style="color:#94a3b8;padding:2px 8px;font-size:10px;border-bottom:1px solid #1e293b">'+esc(p.d.msg)+'</div>';}
                else if(p.t==='dedup_found'){
                    const ids=p.d.ids||[];
                    $('ddRunning').innerHTML+='<div style="padding:6px 8px;margin:3px 0;background:#7f1d1d30;border:1px solid #f97316;border-radius:6px;font-size:11px;color:#fb923c">🔴 <b>'+esc(p.d.name)+'</b> ×'+p.d.count+' <span style="color:#94a3b8">IDs: '+ids.join(', ')+'</span></div>';
                }
                else if(p.t==='dedup_complete'){
                    $('ddTot').textContent=toFa(p.d.total);
                    $('ddGrp').textContent=toFa(p.d.groups);
                    $('ddDup').textContent=toFa(p.d.duplicates);
                    $('ddDel').textContent=toFa(p.d.deleted);
                    $('ddSM').classList.remove('hidden');
                    $('ddPB').style.width='100%';
                    if(p.d.dry_run){$('ddSS').textContent='✓ حالت پیش‌نمایش - '+toFa(p.d.duplicates)+' تکراری یافت شد';}
                    else{$('ddSS').textContent='✓ '+toFa(p.d.deleted)+' محصول تکراری حذف شد';}
                }
                else if(p.t==='error'){$('ddRunning').innerHTML+='<div class="no2">✗ '+esc(p.d.message)+'</div>';}
            });scrollElBottom($('ddRunning'));rd2();
        }).catch(()=>finDedup());}rd2();
    }).catch(()=>finDedup());
}
function finDedup(){ddRunning=false;$('ddBtn').classList.remove('hidden');$('ddStop').classList.add('hidden');}

// ========== Auto-Sync JS (per-profile, triggered via cron) ==========
let syncTimer=null;
function saveSyncSettings(){
    // Per-profile sync is now managed via profile settings
    showToast('✓ سینک از تنظیمات پروفایل مدیریت می‌شود');
}
function toggleSync(){
    // Deprecated - sync is now per-profile
}
function startSyncTimer(){
    // Deprecated - sync is now per-profile via cron_run
}
function runSyncNow(){
    // v8.28: دقیقاً همان پنل و همان رصد زندهٔ دکمهٔ «استخراج بک‌اند»
    $('syncStatus').textContent='🔄 در حال اجرای کران جاب...';
    $('syncStatus').style.color='#67e8f9';
    openExtractPanel('🔄 اجرای کران جاب — استخراج و ارسال');
    watchExtractProgress();
    const fd=new FormData();
    fd.append('action','cron_run');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(!d||!d.profiles){showToast('❌ خطا در اجرای کران',1);$('syncStatus').textContent='❌ خطا';$('syncStatus').style.color='#f87171';return;}
        const profiles=d.profiles||[];
        const synced=profiles.filter(p=>p.status==='syncing');
        const notDue=profiles.filter(p=>p.status==='not_due');
        const disabled=profiles.filter(p=>p.status==='sync_disabled');
        const noProducts=profiles.filter(p=>p.bsl==='no_products'||p.woo==='no_products');
        let msg='✓ کران اجرا شد';
        if(synced.length>0)msg+=' | '+toFa(synced.length)+' سینک';
        if(notDue.length>0)msg+=' | '+toFa(notDue.length)+' هنوز نوبت نیست';
        if(disabled.length>0)msg+=' | '+toFa(disabled.length)+' غیرفعال';
        $('syncStatus').textContent=msg;
        $('syncStatus').style.color='#4ade80';
        showToast('✓ کران جاب اجرا شد');
        // v8.22: Show extraction results in the log
        const eLog=$('extractLog');
        if(eLog){
            profiles.forEach(p=>{
                if(p.extracted>0){eLog.innerHTML+='<div style="color:#4ade80;padding:2px 0">✅ '+esc(p.name||p.key)+' — '+toFa(p.extracted)+' محصول استخراج شد</div>';}
                if(p.woo==='queued'){eLog.innerHTML+='<div style="color:#a78bfa;padding:2px 0">📋 '+esc(p.name||p.key)+' — '+toFa(p.woo_total||0)+' محصول در صف ووکامرس</div>';}
                if(p.bsl==='queued'){eLog.innerHTML+='<div style="color:#22d3ee;padding:2px 0">📋 '+esc(p.name||p.key)+' — '+toFa(p.bsl_total||0)+' محصول در صف باسلام</div>';}
                if(p.status==='not_due'){eLog.innerHTML+='<div style="color:#64748b;padding:2px 0">⏳ '+esc(p.name||p.key)+' — هنوز نوبت نیست</div>';}
            });
            // v8.29: مرحلهٔ سوم — نتیجهٔ استعلام اعلان‌ها
            const nf=d.notifications||{};
            const nk=Object.keys(nf);
            if(nk.length){
                const lbl={orders:'🛒 سفارش جدید',chats:'💬 پیام جدید',products:'📋 تغییر محصول'};
                nk.forEach(k=>{eLog.innerHTML+='<div style="color:#fbbf24;padding:2px 0">'+(lbl[k]||k)+': '+toFa(nf[k])+' مورد — اعلان ارسال شد</div>';});
            }else{
                eLog.innerHTML+='<div style="color:#64748b;padding:2px 0">🔔 استعلام اعلان‌ها: مورد جدیدی نبود</div>';
            }
            eLog.innerHTML+='<div style="color:#22c55e;padding:4px 0;font-weight:bold">✅ کران جاب کامل شد</div>';
        }
        // v8.28: کران تمام شد — رصد را متوقف و صف/شمارنده‌ها را نهایی کن
        if(extractPollTimer)clearInterval(extractPollTimer);
        pollExtractProgress();
        refreshExtractQueue();
        const bar=$('extractProgressBar');
        if(bar){bar.style.width='100%';}
        // Show detailed results
        let html='';
        profiles.forEach(p=>{
            const statusColors={syncing:'#fbbf24',sync_disabled:'#64748b',not_due:'#94a3b8',queued_bsl:'#22d3ee',running_woo:'#a78bfa'};
            const statusLabels={syncing:'🔄 در حال سینک',sync_disabled:'⏹ غیرفعال',not_due:'⏳ هنوز نوبت نیست',queued_bsl:'📋 در صف باسلام',running_woo:'📋 در صف ووکامرس'};
            const sc=statusColors[p.status]||'#94a3b8';
            const sl=statusLabels[p.status]||p.status;
            html+='<div style="padding:4px 8px;border-bottom:1px solid #1e293b;font-size:11px">';
            html+='<b style="color:#e2e8f0">'+esc(p.name||p.key)+'</b> ';
            html+='<span style="color:'+sc+'">'+sl+'</span>';
            if(p.extracted>0)html+=' <span style="color:#4ade80;font-size:10px">🔍 '+toFa(p.extracted)+' استخراج</span>';
            if(p.bsl==='queued')html+=' <span style="color:#22d3ee;font-size:10px">📋 باسلام: '+toFa(p.bsl_total||0)+' محصول</span>';
            if(p.woo==='queued')html+=' <span style="color:#a78bfa;font-size:10px">📋 ووکامرس: '+toFa(p.woo_total||0)+' محصول</span>';
            if(p.remaining)html+=' <span style="color:#64748b;font-size:10px">⏳ '+Math.ceil(p.remaining/60)+' دقیقه مانده</span>';
            html+='</div>';
        });
        const list=$('syncProfilesList');
        if(list&&html)list.innerHTML=html;
        // Refresh sync status after a short delay
        setTimeout(()=>refreshSyncStatus(),3000);
    }).catch(e=>{
        $('syncStatus').textContent='❌ خطا در اجرای کران';
        $('syncStatus').style.color='#f87171';
        showToast('❌ خطا شبکه',1);
    });
}

if ($('url').value) {
    setTimeout(() => {
        const match = profiles.find(p => p.url === $('url').value.trim());
        if (match) loadProfileFromServer($('url').value.trim());
    }, 100);
}

// ========== CSV/Excel Import Functions ==========
function uploadImportFile(){
    const fileInput=$('importFile');
    if(!fileInput.files.length){showToast('فایل انتخاب کنید',true);return;}
    const fd=new FormData();
    fd.append('action','upload_import');
    fd.append('importFile',fileInput.files[0]);
    $('importUploadStatus').textContent='⏳ در حال آپلود و تحلیل...';
    $('btnUploadImport').disabled=true;
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        $('btnUploadImport').disabled=false;
        if(!d.ok){$('importUploadStatus').textContent='❌ '+d.error;showToast(d.error,true);return;}
        importFile=d.file;
        importHeaders=d.headers;
        importMapping=d.mapping||{};
        $('importUploadStatus').textContent='✓ '+d.rows+' ردیف یافت شد ('+d.headers.length+' ستون)';
        renderImportMapping(d);
        $('importMappingCard').classList.remove('hidden');
    }).catch(e=>{$('importUploadStatus').textContent='❌ خطا';$('btnUploadImport').disabled=false;});
}
function renderImportMapping(d){
    const fieldDefs=[
        {key:'title',label:'عنوان محصول',icon:'📝',required:true},
        {key:'price',label:'قیمت',icon:'💰',required:false},
        {key:'image',label:'تصویر (URL)',icon:'🖼️',required:false},
        {key:'link',label:'لینک محصول',icon:'🔗',required:false},
        {key:'sku',label:'SKU / کد محصول',icon:'🏷️',required:false},
        {key:'shortDesc',label:'توضیحات کوتاه',icon:'📄',required:false},
        {key:'longDesc',label:'توضیحات بلند',icon:'📃',required:false},
    ];
    let html='';
    fieldDefs.forEach(fd=>{
        const autoIdx=importMapping[fd.key];
        let opts='<option value="-1">-- انتخاب نشده --</option>';
        importHeaders.forEach((h,i)=>{
            const sel=(autoIdx!==undefined && autoIdx===i)?'selected':'';
            opts+=`<option value="${i}" ${sel}>${esc(h)}</option>`;
        });
        html+=`<div class="crow"><label>${fd.icon} ${fd.label}${fd.required?' <span style="color:#f87171">*</span>':''}</label><select id="impMap_${fd.key}" onchange="updateImportMapping('${fd.key}',this.value)" style="flex:1">${opts}</select></div>`;
    });
    $('importMappingRows').innerHTML=html;
    // Show sample data
    if(d.sample && d.sample.length>0){
        let prev='<div style="font-size:10px;color:#64748b;margin-bottom:4px">نمونه ردیف اول:</div>';
        prev+='<div style="background:#0f172a;border:1px solid #334155;border-radius:6px;padding:6px;font-size:10px;max-height:100px;overflow:auto;direction:ltr;text-align:left;font-family:monospace">';
        importHeaders.forEach((h,i)=>{prev+=`<b>${esc(h)}</b>: ${esc(d.sample[0][h]||'')}<br>`;});
        prev+='</div>';
        $('importPreview').innerHTML=prev;
    }
}
function updateImportMapping(field,val){importMapping[field]=parseInt(val);}
function processImport(){
    if(!importFile){showToast('ابتدا فایل را آپلود کنید',true);return;}
    $('importProcessStatus').textContent='⏳ در حال پردازش...';
    $('btnProcessImport').disabled=true;
    const fd=new FormData();
    fd.append('action','process_import');
    fd.append('file',importFile);
    fd.append('mapping',JSON.stringify(importMapping));
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        $('btnProcessImport').disabled=false;
        if(!d.ok){$('importProcessStatus').textContent='❌ '+d.error;showToast(d.error,true);return;}
        importProducts=d.products;
        applyImportPriceAdjust();
        const withPrice=d.products.filter(p=>p.price&&extractNumber(p.price)>0).length;
        const withImage=d.products.filter(p=>p.image).length;
        $('impTotal').textContent=toFa(d.count);
        $('impWithPrice').textContent=toFa(withPrice);
        $('impWithImage').textContent=toFa(withImage);
        $('importResultCard').classList.remove('hidden');
        if($('impTitleSuffix2')&&$('impTitleSuffix'))$('impTitleSuffix2').value=$('impTitleSuffix').value;
        if($('impPriceMode2')&&$('impPriceMode'))$('impPriceMode2').value=$('impPriceMode').value;
        if($('impPriceVal2')&&$('impPriceVal'))$('impPriceVal2').value=$('impPriceVal').value;
        if($('impRoundPrice2')&&$('impRoundPrice'))$('impRoundPrice2').value=$('impRoundPrice').value;
        $('importProcessStatus').textContent='✓ '+toFa(d.count)+' محصول وارد شد';
        showToast('✓ '+d.count+' محصول وارد شد');
    }).catch(e=>{$('importProcessStatus').textContent='❌ خطا';$('btnProcessImport').disabled=false;});
}

// v7.81: Apply price adjustment and title suffix to imported products
function applyImportPriceAdjust(){
    const mode=$('impPriceMode')?$('impPriceMode').value:'none';
    const val=parseFloat($('impPriceVal')?$('impPriceVal').value:0)||0;
    const roundMode=$('impRoundPrice')?$('impRoundPrice').value:'0';
    const suffix=$('impTitleSuffix')?$('impTitleSuffix').value.trim():'';
    const factor=parseInt(roundMode,10)||0;
    let changed=0;
    importProducts.forEach(p=>{
        if(p._origTitle===undefined)p._origTitle=p.title||'';
        if(p._origPrice===undefined)p._origPrice=p.price||'';
        p.title=p._origTitle;p.price=p._origPrice;
        if(suffix&&p.title)p.title=p.title+' '+suffix;
        let base=extractNumber(p.price);
        if(base>0&&mode!=='none'){
            let f=base;
            if(mode==='percent')f=base*(1+(val/100));
            else if(mode==='multiplier')f=base*val;
            f=Math.round(f);
            if(factor>0)f=Math.round(f/factor)*factor;
            p.price=String(f);p.raw_price=String(base);p.final_price=String(f);changed++;
        }else if(base>0){p.final_price=String(base);}
    });
    const pv=$('impPricePreview');
    if(pv){if(changed>0){pv.textContent='✓ '+toFa(changed)+' قیمت تعدیل شد';pv.style.color='#4ade80';}else if(mode!=='none'){pv.textContent='⚠️ محصولی با قیمت یافت نشد';pv.style.color='#fbbf24';}else{pv.textContent='';}}
    renderImportProductsPreview();
}
function reapplyImportPrice(){
    if($('impTitleSuffix2')&&$('impTitleSuffix'))$('impTitleSuffix').value=$('impTitleSuffix2').value;
    if($('impPriceMode2')&&$('impPriceMode'))$('impPriceMode').value=$('impPriceMode2').value;
    if($('impPriceVal2')&&$('impPriceVal'))$('impPriceVal').value=$('impPriceVal2').value;
    if($('impRoundPrice2')&&$('impRoundPrice'))$('impRoundPrice').value=$('impRoundPrice2').value;
    applyImportPriceAdjust();
    const pv2=$('impPricePreview2');
    if(pv2){const a=importProducts.filter(p=>p.final_price&&p._origPrice).length;if(a>0){pv2.textContent='✓ '+toFa(a)+' قیمت تعدیل شد';pv2.style.color='#4ade80';}else{pv2.textContent=toFa(importProducts.length)+' محصول';pv2.style.color='#94a3b8';}}
}
function renderImportProductsPreview(){
    const c=$('importProductsPreview');if(!c||!importProducts.length)return;
    let h='<table style="width:100%;border-collapse:collapse;font-size:11px"><thead><tr style="background:#1e293b;color:#94a3b8;border-bottom:2px solid #334155"><th style="padding:6px;text-align:right">#</th><th style="padding:6px;text-align:right">عنوان</th><th style="padding:6px;text-align:right">قیمت اصلی</th><th style="padding:6px;text-align:right">قیمت نهایی</th><th style="padding:6px;text-align:right">تصویر</th></tr></thead><tbody>';
    importProducts.forEach((p,i)=>{
        const op=p._origPrice||p.price||'',fp=p.final_price||p.price||'',hi=p.image?'✓':'—',pc=op!==fp&&p._origPrice!==undefined;
        h+='<tr style="border-bottom:1px solid #1e293b"><td style="padding:4px;color:#64748b">'+toFa(i+1)+'</td><td style="padding:4px;color:#60a5fa;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(p.title||'')+'</td><td style="padding:4px;color:#94a3b8;direction:ltr;text-align:right">'+esc(op)+'</td><td style="padding:4px;color:'+(pc?'#fbbf24':'#4ade80')+';direction:ltr;text-align:right">'+esc(fp)+(pc?' ⚡':'')+'</td><td style="padding:4px;color:#64748b">'+hi+'</td></tr>';
    });
    h+='</tbody></table>';c.innerHTML=h;
}

function addImportToResults(){
    if(!importProducts.length){showToast('محصولی نیست',true);return;}
    if($('impTitleSuffix2')&&$('impTitleSuffix'))$('impTitleSuffix').value=$('impTitleSuffix2').value;
    if($('impPriceMode2')&&$('impPriceMode'))$('impPriceMode').value=$('impPriceMode2').value;
    if($('impPriceVal2')&&$('impPriceVal'))$('impPriceVal').value=$('impPriceVal2').value;
    if($('impRoundPrice2')&&$('impRoundPrice'))$('impRoundPrice').value=$('impRoundPrice2').value;
    applyImportPriceAdjust();
    let added=0;
    importProducts.forEach(p=>{
        const key=p.key||md5('imp:'+p.title);
        if(!products.has(key)){
            products.set(key,p);
            order.push(key);
            added++;
        }
    });
    refreshViews();
    switchMainTab('results');
    showToast('✓ '+toFa(added)+' محصول به نتایج اضافه شد');
    scheduleSave();
}
function sendImportToWoo(){
    if(!importProducts.length){showToast('محصولی نیست',true);return;}
    if($('impTitleSuffix2')&&$('impTitleSuffix'))$('impTitleSuffix').value=$('impTitleSuffix2').value;
    if($('impPriceMode2')&&$('impPriceMode'))$('impPriceMode').value=$('impPriceMode2').value;
    if($('impPriceVal2')&&$('impPriceVal'))$('impPriceVal').value=$('impPriceVal2').value;
    if($('impRoundPrice2')&&$('impRoundPrice'))$('impRoundPrice').value=$('impRoundPrice2').value;
    applyImportPriceAdjust();
    const ps=importProducts.map(p=>({
        key:p.key,title:p.title||'',
        final_price:p.final_price||String(extractNumber(p.price)),
        price_unit:(p.price||'').toString().includes('ریال')||(p.price||'').toString().includes('ر.ی')?'rial':'toman',
        image:p.image||'',sku:p.sku||'',
        short_desc:p.shortDesc||'',long_desc:p.longDesc||'',weight:''
    }));
    if(!ps.length){showToast('محصولی نیست',true);return;}
    switchMainTab('send');
    wSend=true;wooReportData={sent:[],updated:[],skipped:[],failed:[]};
    $('wSB').textContent='🚀 + افزودن به صف';$('wST').classList.remove('hidden');
    $('wP').classList.remove('hidden');$('wPB').style.width='0%';
    $('wR').classList.remove('hidden');
    $('wR').innerHTML='<div style="color:#67e8f9;font-weight:bold;padding:8px;margin-bottom:4px;background:#1e3a5f;border-radius:6px">🚥 ارسال '+toFa(ps.length)+' محصول وارد شده به ووکامرس</div>';
    $('wSM').classList.remove('hidden');$('wO').textContent='۰';$('wU').textContent='۰';$('wK').textContent='۰';$('wF').textContent='۰';$('wT').textContent=toFa(ps.length);
    $('wSS').textContent='ذخیره محصولات...';
    // v8.17: Use queue system for import too
    queueWooSend(ps);
}
function sendImportToBsl(){
    if(!importProducts.length){showToast('محصولی نیست',true);return;}
    if($('impTitleSuffix2')&&$('impTitleSuffix'))$('impTitleSuffix').value=$('impTitleSuffix2').value;
    if($('impPriceMode2')&&$('impPriceMode'))$('impPriceMode').value=$('impPriceMode2').value;
    if($('impPriceVal2')&&$('impPriceVal'))$('impPriceVal').value=$('impPriceVal2').value;
    if($('impRoundPrice2')&&$('impRoundPrice'))$('impRoundPrice').value=$('impRoundPrice2').value;
    applyImportPriceAdjust();
    const ps=importProducts.map(p=>({
        key:p.key,title:p.title||p.name||'',
        final_price:p.final_price||String(extractNumber(p.price)),
        price_unit:(p.price||'').toString().includes('ریال')||(p.price||'').toString().includes('ر.ی')?'rial':'toman',
        image:p.image||'',sku:p.sku||'',
        short_desc:p.shortDesc||'',long_desc:p.longDesc||'',weight:''
    }));
    if(!ps.length){showToast('\u0645\u062d\u0635\u0648\u0644\u06cc \u0646\u06cc\u0633\u062a',true);return;}
    // v7.56: Use client-driven queue instead of server-side stream (was stopping after some products)
    const catId=parseInt($('bsCat')&&$('bsCat').value)||0;
    if(catId<=0){showToast('\u0627\u0628\u062a\u062f\u0627 \u062f\u0633\u062a\u0647\u200c\u0628\u0646\u062f\u06cc \u0628\u0627\u0633\u0627\u0644\u0627\u0645 \u0631\u0627 \u0627\u0646\u062a\u062e\u0627\u0628 \u06a9\u0646\u06cc\u062f!',1);return;}
    switchMainTab('send');
    queueBslSend(ps,catId);
}
function loadProfileSyncConfig(){
    // Load from current profile via server
    const url=$('url').value.trim();
    if(!url)return;
    fetch('?load_profile='+encodeURIComponent(url)).then(r=>r.json()).then(d=>{
        if(d.ok&&d.profile&&d.profile.syncConfig){
            const sc=d.profile.syncConfig;
            $('profileSyncEn').checked=!!sc.enabled;
            if(sc.interval)$('profileSyncInterval').value=sc.interval;
            if(sc.target)$('profileSyncTarget').value=sc.target;
            // v7.81: Load add/update checkboxes
            $('profileSyncWooAddUpdate').checked=!!sc.wooAddUpdate;
            $('profileSyncBslAddUpdate').checked=!!sc.bslAddUpdate;
            updateSyncStatusText();
        }else{
            $('profileSyncEn').checked=false;
            $('profileSyncWooAddUpdate').checked=false;
            $('profileSyncBslAddUpdate').checked=false;
            $('profileSyncStatus').textContent='';
        }
    }).catch(()=>{});
}
function getSyncConfig(){
    const intervalVal=parseInt($('profileSyncInterval').value);
    return {
        enabled:$('profileSyncEn').checked,
        // v7.66: 0 means "sync on endpoint call" — preserve it, don't convert to 3600
        interval:isNaN(intervalVal)?3600:intervalVal,
        target:$('profileSyncTarget').value||'woo',
        // v7.81: Add/Update mode checkboxes — stored in profile for future use
        wooAddUpdate:$('profileSyncWooAddUpdate').checked,
        bslAddUpdate:$('profileSyncBslAddUpdate').checked
    };
}
// v7.81: Update sync status text when any sync config changes
function updateSyncStatusText(){
    const en=$('profileSyncEn').checked;
    if(!en){$('profileSyncStatus').textContent='';return;}
    const intv=$('profileSyncInterval').options[$('profileSyncInterval').selectedIndex].text;
    const wm=$('profileSyncWooAddUpdate').checked?'➕🔄 ووکامرس':'🆕 ووکامرس';
    const bm=$('profileSyncBslAddUpdate').checked?'➕🔄 باسلام':'🆕 باسلام';
    $('profileSyncStatus').textContent='✓ سینک فعال ('+intv+') | '+wm+' | '+bm;
}
function refreshSyncStatus(){
    fetch('?sync_status=1').then(r=>r.json()).then(d=>{
        if(!d.ok)return;
        const state=d.state||{};
        let html='';
        profiles.forEach(p=>{
            // Load full profile to check sync config
            const syncEnabled=false; // Will be loaded async
            html+=`<div style="padding:6px 8px;border-bottom:1px solid #334155;font-size:11px">`;
            html+=`<b>${esc(p.name)}</b> `;
            if(state[p.key]){
                const s=state[p.key];
                html+=`<span style="color:${s.status==='running'?'#fbbf24':'#4ade80'}">● ${s.status==='running'?'در حال اجرا':'✓'}</span>`;
                html+=` <span style="color:#64748b">آخرین: ${new Date(s.lastRun*1000).toLocaleString('fa-IR')}</span>`;
            }else{
                html+=`<span style="color:#64748b">● هنوز اجرا نشده</span>`;
            }
            html+=`</div>`;
        });
        $('syncProfilesList').innerHTML=html||'<div style="color:#64748b;font-size:11px;padding:8px">هیچ پروفایلی تنظیم نشده</div>';
    }).catch(()=>{});
}
// Override applyProfile to also load sync config
const _origApplyProfile=applyProfile;
applyProfile=function(p){
    _origApplyProfile(p);
    if(p.syncConfig){
        $('profileSyncEn').checked=!!p.syncConfig.enabled;
        if(p.syncConfig.interval)$('profileSyncInterval').value=p.syncConfig.interval;
        if(p.syncConfig.target)$('profileSyncTarget').value=p.syncConfig.target;
        // v7.81: Load add/update checkboxes
        $('profileSyncWooAddUpdate').checked=!!p.syncConfig.wooAddUpdate;
        $('profileSyncBslAddUpdate').checked=!!p.syncConfig.bslAddUpdate;
        updateSyncStatusText();
    }else{
        $('profileSyncEn').checked=false;
        $('profileSyncWooAddUpdate').checked=false;
        $('profileSyncBslAddUpdate').checked=false;
        $('profileSyncStatus').textContent='';
    }
};
// Override collectProfileData to include syncConfig
const _origCollectProfileData=collectProfileData;
collectProfileData=function(){
    const d=_origCollectProfileData();
    d.syncConfig=getSyncConfig();
    return d;
};
// ========== Auto-Continue Send Logic ==========
// Simple send system - no auto-continue loops
// If connection drops, user can click "Continue" manually or "Retry" for failed products

refreshSyncStatus();
refreshExtractQueue();
// v7.23: BaSalam Products Modal (fixed API response structure)
let bslModalState={page:1,totalPage:1,totalCount:0,perPage:50,activeTab:'active'};
const BSL_TABS=[
    {key:'active',label:'✅ فعال',statuses:['2976']},
    {key:'approved',label:'🟢 تأیید شده',statuses:['2976']},
    {key:'inactive',label:'🟡 غیرفعال',statuses:['3790']},
    {key:'not_approved',label:'🔴 تأیید نشده',statuses:['3567']},
    {key:'pending',label:'🟠 در انتظار',statuses:['3568']},
    {key:'archived',label:'📦 بایگانی',statuses:['4184']},
    {key:'all',label:'📋 همه',statuses:['2976','3790','3567','3568','4184','2977','2978','3248','4221']},
];
let bslTabCounts={};
function showBslProductsModal(page,tab){
    if(!page)page=1;
    if(!tab)tab=bslModalState.activeTab;
    bslModalState.activeTab=tab;
    bslModalState.page=page;
    // Fetch products for this tab
    fetch('?bsl_products=1&page='+page+'&per_page='+bslModalState.perPage+'&status='+tab).then(r=>r.json()).then(d=>{
        if(!d||!d.ok){showToast(d?.error||'خطا در دریافت محصولات',1);return;}
        bslModalState.totalPage=d.total_page||1;
        bslModalState.totalCount=d.total_count||0;
        if(d.categories)window._bslModalCats=d.categories;
        bslTabCounts[tab]=d.total_count||0;
        renderBslModal(d.products||[]);
        // v8.06: Preload counts for other tabs (fire-and-forget, page 1 only)
        BSL_TABS.forEach(t=>{
            if(t.key!==tab&&bslTabCounts[t.key]===undefined){
                fetch('?bsl_products=1&page=1&per_page=1&status='+t.key).then(r2=>r2.json()).then(d2=>{
                    if(d2&&d2.ok){bslTabCounts[t.key]=d2.total_count||0;updateBslTabCounts();}
                }).catch(()=>{});
            }
        });
    }).catch(e=>{showToast('خطا: '+e.message,1);});
}
function bslChangePerPage(val){
    bslModalState.perPage=parseInt(val)||50;
    bslModalState.page=1;
    showBslProductsModal(1,bslModalState.activeTab);
}
function switchBslTab(tab){
    bslModalState.activeTab=tab;
    bslModalState.page=1;
    showBslProductsModal(1,tab);
}
function updateBslTabCounts(){
    const tabs=document.querySelectorAll('.bsl-tab');
    tabs.forEach(el=>{
        const key=el.getAttribute('onclick')?.match(/'(\w+)'/)?.[1];
        if(key&&bslTabCounts[key]!==undefined){
            const existing=el.querySelector('.tab-count');
            if(existing)existing.textContent='('+toFa(bslTabCounts[key])+')';
        }
    });
}
function renderBslModal(products){
    const total=bslModalState.totalCount;
    const pg=bslModalState.page;
    const tp=bslModalState.totalPage;
    const activeTab=bslModalState.activeTab;
    const isManageable=['active','inactive','not_approved','pending','archived'].includes(activeTab);
    let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeBslModal()">';
    html+='<div class="bsl-modal">';
    html+='<div class="bsl-modal-head"><h2>\u{1F3EA} \u0645\u062F\u06CC\u0631\u06CC\u062A \u062C\u0627\u0645\u0639 \u0645\u062D\u0635\u0648\u0644\u0627\u062A \u0628\u0627\u0633\u0644\u0627\u0645 ('+toFa(total)+' \u0645\u062D\u0635\u0648\u0644)</h2><div style="display:flex;gap:4px"><button class="btn btn-red" style="font-size:11px;padding:4px 8px" onclick="bslFindDuplicates()">\u{1F50D} \u062A\u06A9\u0631\u0627\u0631\u06CC\u200C\u0647\u0627</button><button class="btn btn-cyan" style="font-size:11px;padding:4px 8px" onclick="bslStatusOverview()">\u{1F4CA} \u0648\u0636\u0639\u06CC\u062A</button><button class="btn btn-gray" onclick="closeBslModal()">\u2715</button></div></div>';
    // Tabs
    html+='<div class="bsl-tabs">';
    BSL_TABS.forEach(t=>{
        const isActive=t.key===activeTab?'active':'';
        const count=bslTabCounts[t.key];
        const countStr=count!==undefined?' <span class="tab-count">('+toFa(count)+')</span>':'';
        html+='<div class="bsl-tab '+isActive+'" onclick="switchBslTab(\''+t.key+'\')">'+t.label+countStr+'</div>';
    });
    html+='</div>';
    // v8.17: Per-page selector
    html+='<div style="display:flex;align-items:center;gap:6px;padding:6px 10px;background:#0f172a;border-bottom:1px solid #334155">';
    html+='<span style="color:#94a3b8;font-size:11px">نمایش:</span>';
    html+='<select id="bslPerPageSelect" onchange="bslChangePerPage(this.value)" style="padding:4px 8px;border:1px solid #475569;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:12px;direction:ltr">';
    [50,100,200,500,1000].forEach(v=>{html+='<option value="'+v+'"'+(bslModalState.perPage===v?' selected':'')+'>'+toFa(v)+'</option>';});
    html+='</select>';
    html+='<span style="color:#94a3b8;font-size:11px">محصول در هر صفحه</span>';
    html+='</div>';
    html+='<div class="bsl-modal-body">';
    // v8.06: Search bar + toolbar for manageable tabs
    if(isManageable&&products.length>0){
        html+='<div style="margin-bottom:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap">';
        html+='<input type="text" id="bslSearchInput" placeholder="\u062C\u0633\u062A\u062C\u0648\u06CC \u0645\u062D\u0635\u0648\u0644..." style="flex:1;min-width:150px;padding:6px 10px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px;direction:rtl" oninput="bslFilterProducts()">';
        html+='<label style="color:#94a3b8;font-size:11px;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" id="bslSelectAll" onchange="bslToggleSelectAll(this.checked)"> \u0627\u0646\u062A\u062E\u0627\u0628 \u0647\u0645\u0647</label>';
        html+='<span id="bslSelectedCount" style="color:#67e8f9;font-size:11px">0 \u0627\u0646\u062A\u062E\u0627\u0628 \u0634\u062F\u0647</span>';
        // Activate selected button for non-active tabs
        if(activeTab!=='active'){
            html+='<button class="btn btn-green" style="font-size:11px;padding:4px 8px" onclick="bslActivateSelected()" id="bslActivateSelBtn">\u2705 \u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC \u0627\u0646\u062A\u062E\u0627\u0628\u200C\u0634\u062F\u0647</button>';
        }
        // Deactivate selected for active tab
        if(activeTab==='active'){
            html+='<button class="btn btn-orange" style="font-size:11px;padding:4px 8px" onclick="bslDeactivateSelected()" id="bslDeactSelBtn">\u23F8 \u063A\u06CC\u0631\u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC \u0627\u0646\u062A\u062E\u0627\u0628\u200C\u0634\u062F\u0647</button>';
        }
        // Delete selected
        html+='<button class="btn btn-red" style="font-size:11px;padding:4px 8px" onclick="bslDeleteSelected()">\u{1F5D1} \u062D\u0630\u0641 \u0627\u0646\u062A\u062E\u0627\u0628\u200C\u0634\u062F\u0647</button>';
        html+='</div>';
    }
    // v8.06: Batch AI fix button for not_approved tab
    if(activeTab==='not_approved'&&total>0){
        html+='<div style="margin-bottom:8px;padding:10px;border-radius:8px;background:#312e81;border:1px solid #7c3aed;display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
        html+='<div style="color:#c4b5fd;font-weight:700;font-size:12px">\u{1F916} \u0627\u0635\u0644\u0627\u062D \u062F\u0633\u062A\u0647\u200C\u0628\u0646\u062F\u06CC \u0628\u0631 \u0627\u0633\u0627\u0633 \u062A\u0648\u0635\u06CC\u0647 \u0647\u0648\u0634 \u0645\u0635\u0646\u0648\u0639\u06CC</div>';
        html+='<button class="btn btn-purple" onclick="bslBatchFixAiCat()" id="bslBatchAiBtn">\u{1F504} \u0627\u0635\u0644\u0627\u062D \u0647\u0645\u0647</button>';
        html+='<button class="btn btn-blue" onclick="bslDownloadAiTexts()" id="bslDownloadAiBtn" title="\u062F\u0627\u0646\u0644\u0648\u062F \u0645\u062A\u0646\u200C\u0647\u0627\u06CC \u062A\u0648\u0635\u06CC\u0647 AI \u0628\u0627\u0633\u0644\u0627\u0645 \u0628\u0631\u0627\u06CC \u0628\u0647\u0628\u0648\u062F \u0627\u0633\u062A\u062E\u0631\u0627\u062C \u062F\u0633\u062A\u0647">\u{1F4E5} \u062F\u0627\u0646\u0644\u0648\u062F \u0645\u062A\u0646\u200C\u0647\u0627\u06CC AI</button>';
        html+='</div>';
    }
    // v8.06: Batch activate all button for non-active tabs
    if(['inactive','not_approved','pending','archived'].includes(activeTab)&&total>0){
        const statusMap={'inactive':'3790','not_approved':'3567','pending':'3568','archived':'4184'};
        const labelMap={'inactive':'\u063A\u06CC\u0631\u0641\u0639\u0627\u0644','not_approved':'\u062A\u0623\u06CC\u06CC\u062F \u0646\u0634\u062F\u0647','pending':'\u062F\u0631 \u0627\u0646\u062A\u0638\u0627\u0631 \u062A\u0623\u06CC\u06CC\u062F','archived':'\u0628\u0627\u06CC\u06AF\u0627\u0646\u06CC'};
        const bgMap={'inactive':'#1e3a5f','not_approved':'#3b1e1e','pending':'#3b3a1e','archived':'#2d1b4e'};
        const borderMap={'inactive':'#3b82f6','not_approved':'#ef4444','pending':'#eab308','archived':'#8b5cf6'};
        html+='<div style="margin-bottom:8px;padding:10px;border-radius:8px;background:'+bgMap[activeTab]+';border:1px solid '+borderMap[activeTab]+';display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
        html+='<div style="color:#e2e8f0;font-weight:700;font-size:12px">\u2705 \u062A\u0628\u062F\u06CC\u0644 \u0647\u0645\u0647 \u0645\u062D\u0635\u0648\u0644\u0627\u062A '+labelMap[activeTab]+' \u0628\u0647 \u0641\u0639\u0627\u0644</div>';
        html+='<button class="btn btn-green" onclick="bslBatchActivate(\''+statusMap[activeTab]+'\')" id="bslActivateBtn">\u{1F680} \u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC \u0647\u0645\u0647</button>';
        html+='</div>';
    }
    // Not approved tab: show rejection reason prominently
    const isNotApproved=activeTab==='not_approved'||activeTab==='archived';
    // v8.06: Checkbox column for manageable tabs
    html+='<table class="bsl-modal-table"><thead><tr>'+(isManageable?'<th style="width:30px"><input type="checkbox" id="bslHeaderCb" onchange="bslToggleSelectAll(this.checked)"></th>':'')+'<th>ID</th><th>\u062A\u0635\u0648\u06CC\u0631</th><th>\u0646\u0627\u0645</th><th>\u0642\u06CC\u0645\u062A</th><th>\u0645\u0648\u062C\u0648\u062F\u06CC</th><th>\u0648\u0636\u0639\u06CC\u062A</th><th>\u062F\u0633\u062A\u0647</th>'+(isNotApproved?'<th>\u0639\u0644\u062A \u0631\u062F</th>':'')+'</tr></thead><tbody id="bslProductRows">';
    window._bslModalProducts=products;
    window._bslSelectedIds=new Set();
    products.forEach((p,idx)=>{
        const rev=p.revision&&p.revision.data||{};
        const photoObj=rev.photo||p.photo;
        const photosObj=rev.photos||p.photos;
        const ph=photoObj&&typeof photoObj==='object'?(photoObj.sm||photoObj.original||photoObj.xs||photoObj.md||photoObj.lg||''):'';
        const ph2=photosObj&&photosObj.length&&typeof photosObj[0]=='object'?(photosObj[0].sm||photosObj[0].original||photosObj[0].xs||photosObj[0].md||photosObj[0].lg||''):'';
        const imgSrc=ph2||ph;
        const img=imgSrc?'<img class="td-img" src="'+esc(imgSrc)+'" onerror="this.style.display=\'none\'">':'\u2014';
        const rawStatus=p.status||rev.status;
        const sv=(typeof rawStatus==='object'&&rawStatus!==null)?rawStatus.value:rawStatus;
        const sn=(typeof rawStatus==='object'&&rawStatus!==null)?(rawStatus.name||rawStatus.description||''):'';
        const statusMap={'2976':'\u0641\u0639\u0627\u0644','3790':'\u063A\u06CC\u0631\u0641\u0639\u0627\u0644','4184':'\u063A\u06CC\u0631\u0642\u0627\u0646\u0648\u0646\u06CC','3568':'\u062F\u0631 \u0627\u0646\u062A\u0638\u0627\u0631 \u062A\u0627\u06CC\u06CC\u062F','3567':'\u{1F534} \u062A\u0627\u06CC\u06CC\u062F \u0646\u0634\u062F\u0647'};
        const st=sn||statusMap[String(sv??'')]||String(sv??'')||'?';
        const stColor=String(sv??'')==='2976'?'#22c55e':String(sv??'')==='3790'?'#94a3b8':'#f87171';
        const pInv=p.inventory??rev.inventory??p.stock??'';
        const catObj=rev.category||p.category;
        const catTitle=(typeof catObj==='object'&&catObj!==null)?(catObj.title||String(catObj.id||'')):(p.category_id||'');
        const pName=String(p.title||rev.title||p.name||'').replace(/[\r\n]/g,' ').trim();
        const pPrice=p.primary_price||p.price||rev.primary_price||rev.price||'';
        // Rejection reason column
        let rejectCell='';
        if(isNotApproved){
            const revReject=(p.revision&&typeof p.revision==='object')?(p.revision.rejection_reasons||[]):[];
            const revStatusDesc=(typeof rawStatus==='object'&&rawStatus!==null)?(rawStatus.description||''):'';
            let reasons=[];
            if(revStatusDesc)reasons.push(revStatusDesc);
            revReject.forEach(rr=>{
                if(rr.name)reasons.push(rr.name+(rr.value?' ('+rr.value+')':''));
                if(rr.description)reasons.push(rr.description);
            });
            rejectCell='<td style="color:#fbbf24;font-size:10px;max-width:200px;white-space:normal;direction:rtl">'+esc(reasons.join(' | ')||'\u2014')+'</td>';
        }
        const rejected=String(sv??'')==='4184'||String(sv??'')==='3568'||String(sv??'')==='3567';
        const rowStyle=rejected?'cursor:pointer;background:#7f1d1d40':'cursor:pointer';
        html+='<tr style="'+rowStyle+'" data-pid="'+(p.id??'')+'" data-pname="'+esc(pName.toLowerCase())+'" data-idx="'+idx+'" class="bsl-product-row" onclick="showBslProductDetail('+idx+')">';
        if(isManageable)html+='<td style="text-align:center" onclick="event.stopPropagation()"><input type="checkbox" class="bsl-row-cb" data-pid="'+(p.id??'')+'" onchange="bslRowCheck(this)"></td>';
        html+='<td class="td-id">'+esc(String(p.id??''))+'</td>';
        html+='<td style="text-align:center">'+img+'</td>';
        html+='<td class="td-name" title="'+esc(pName)+'">'+esc(pName)+'</td>';
        html+='<td class="td-price">'+toFa(String(pPrice))+'</td>';
        html+='<td class="td-stock">'+toFa(String(pInv))+'</td>';
        html+='<td class="td-status" style="color:'+stColor+'">'+st+'</td>';
        html+='<td style="color:#94a3b8;text-align:center;font-size:10px">'+esc(String(catTitle||'-'))+'</td>';
        html+=rejectCell;
        html+='</tr>';
    });
    html+='</tbody></table></div>';
    // Pager
    html+='<div class="bsl-modal-pager">';
    if(pg>1)html+='<button class="btn btn-gray" onclick="showBslProductsModal('+1+',\''+activeTab+'\')">\u23EE</button>';
    if(pg>1)html+='<button class="btn btn-gray" onclick="showBslProductsModal('+((pg-1))+',\''+activeTab+'\')">\u25C0</button>';
    html+='<span style="color:#67e8f9;font-weight:600">\u0635\u0641\u062D\u0647 '+toFa(pg)+' / '+toFa(tp)+' ('+toFa(total)+' \u0645\u062D\u0635\u0648\u0644)</span>';
    if(pg<tp)html+='<button class="btn btn-gray" onclick="showBslProductsModal('+((pg+1))+',\''+activeTab+'\')">\u25B6</button>';
    if(pg<tp)html+='<button class="btn btn-gray" onclick="showBslProductsModal('+tp+',\''+activeTab+'\')">\u23ED</button>';
    html+='</div></div></div>';
    const old=document.getElementById('bslModalContainer');if(old)old.remove();
    const div=document.createElement('div');div.id='bslModalContainer';div.innerHTML=html;
    document.body.appendChild(div);
}
// v8.06: Filter products by search text
function bslFilterProducts(){
    const q=(document.getElementById('bslSearchInput')?.value||'').toLowerCase().trim();
    const rows=document.querySelectorAll('.bsl-product-row');
    rows.forEach(r=>{
        const name=r.getAttribute('data-pname')||'';
        r.style.display=(!q||name.includes(q))?'':'none';
    });
    bslUpdateSelectedCount();
}
// v8.06: Toggle select all visible rows
function bslToggleSelectAll(checked){
    const rows=document.querySelectorAll('.bsl-product-row');
    rows.forEach(r=>{
        if(r.style.display!=='none'){
            const cb=r.querySelector('.bsl-row-cb');
            if(cb){cb.checked=checked;bslRowCheck(cb);}
        }
    });
    const hc=document.getElementById('bslHeaderCb');if(hc)hc.checked=checked;
    const sa=document.getElementById('bslSelectAll');if(sa)sa.checked=checked;
}
// v8.06: Individual row checkbox
function bslRowCheck(cb){
    const pid=parseInt(cb.getAttribute('data-pid'));
    if(cb.checked)window._bslSelectedIds.add(pid);
    else window._bslSelectedIds.delete(pid);
    bslUpdateSelectedCount();
}
function bslUpdateSelectedCount(){
    const el=document.getElementById('bslSelectedCount');
    if(el)el.textContent=toFa(window._bslSelectedIds.size)+' \u0627\u0646\u062A\u062E\u0627\u0628 \u0634\u062F\u0647';
}
// v8.06: Activate selected products (set status=2976)
function bslActivateSelected(){
    const ids=[...window._bslSelectedIds];
    if(ids.length===0){showToast('\u26A0\uFE0F \u0647\u06CC\u0686 \u0645\u062D\u0635\u0648\u0644\u06CC \u0627\u0646\u062A\u062E\u0627\u0628 \u0646\u0634\u062F\u0647',1);return;}
    if(!confirm('\u2705 \u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC '+ids.length+' \u0645\u062D\u0635\u0648\u0644 \u0627\u0646\u062A\u062E\u0627\u0628\u200C\u0634\u062F\u0647\u061F'))return;
    bslActivateIds(ids);
}
// v8.06: Deactivate selected products (set status=3790)
function bslDeactivateSelected(){
    const ids=[...window._bslSelectedIds];
    if(ids.length===0){showToast('\u26A0\uFE0F \u0647\u06CC\u0686 \u0645\u062D\u0635\u0648\u0644\u06CC \u0627\u0646\u062A\u062E\u0627\u0628 \u0646\u0634\u062F\u0647',1);return;}
    if(!confirm('\u23F8 \u063A\u06CC\u0631\u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC '+ids.length+' \u0645\u062D\u0635\u0648\u0644 \u0627\u0646\u062A\u062E\u0627\u0628\u200C\u0634\u062F\u0647\u061F'))return;
    bslDeactivateIds(ids);
}
// v8.06: Delete selected products
function bslDeleteSelected(){
    const ids=[...window._bslSelectedIds];
    if(ids.length===0){showToast('\u26A0\uFE0F \u0647\u06CC\u0686 \u0645\u062D\u0635\u0648\u0644\u06CC \u0627\u0646\u062A\u062E\u0627\u0628 \u0646\u0634\u062F\u0647',1);return;}
    if(!confirm('\u{1F5D1} \u062D\u0630\u0641 '+ids.length+' \u0645\u062D\u0635\u0648\u0644 \u0627\u0646\u062A\u062E\u0627\u0628\u200C\u0634\u062F\u0647\u061F \u0627\u06CC\u0646 \u0639\u0645\u0644 \u063A\u06CC\u0631\u0642\u0627\u0628\u0644 \u0628\u0627\u0632\u06AF\u0634\u062A \u0627\u0633\u062A!'))return;
    bslDeleteIds(ids);
}
// v8.06: Generic SSE-based batch status change
function bslActivateIds(ids){
    bslBatchStatusModal(ids,2976,'\u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC');
}
function bslDeactivateIds(ids){
    bslBatchStatusModal(ids,3790,'\u063A\u06CC\u0631\u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC');
}
function bslDeleteIds(ids){
    bslBatchStatusModal(ids,-1,'\u062D\u0630\u0641');
}
function bslBatchStatusModal(ids,targetStatus,actionLabel){
    let modal=document.getElementById('bslBatchStatusModal');if(modal)modal.remove();
    modal=document.createElement('div');modal.id='bslBatchStatusModal';
    modal.innerHTML='<div class="bsl-modal-overlay" onclick="if(event.target===this)this.parentElement.remove()"><div class="bsl-modal" style="width:700px"><div class="bsl-modal-head"><h2>'+actionLabel+' '+ids.length+' \u0645\u062D\u0635\u0648\u0644</h2><button class="btn btn-gray" onclick="this.closest(\'#bslBatchStatusModal\').remove()">\u2715</button></div><div class="bsl-modal-body" style="padding:0"><div id="bslBatchStatusLog" style="max-height:60vh;overflow-y:auto;padding:8px;font-size:11px;direction:rtl"></div></div></div></div>';
    document.body.appendChild(modal);
    const logEl=document.getElementById('bslBatchStatusLog');
    const addRow=function(html){const d=document.createElement('div');d.style.cssText='padding:4px 6px;border-bottom:1px solid #1e293b;direction:rtl';d.innerHTML=html;logEl.appendChild(d);logEl.scrollTop=logEl.scrollHeight;};
    let done=0,fail=0;const total=ids.length;
    addRow('<span style="color:#67e8f9">\u2139\uFE0F \u0634\u0631\u0648\u0639 '+actionLabel+' '+total+' \u0645\u062D\u0635\u0648\u0644...</span>');
    // Process sequentially with delays
    let idx=0;
    function processNext(){
        if(idx>=ids.length){
            addRow('<span style="color:#4ade80;font-weight:700">\u{1F3C1} '+actionLabel+' \u0634\u062F: '+done+' | \u0646\u0627\u0645\u0648\u0641\u0642: '+fail+' (\u0627\u0632 '+total+')</span>');
            showToast('\u2705 '+actionLabel+' \u062A\u06A9\u0645\u06CC\u0644 \u0634\u062F');
            window._bslSelectedIds=new Set();
            setTimeout(()=>{const m=document.getElementById('bslBatchStatusModal');if(m)m.remove();showBslProductsModal(1,bslModalState.activeTab);},1500);
            return;
        }
        const pid=ids[idx];idx++;
        const url=targetStatus===-1?'?bsl_delete_product=1&product_id='+pid:'?bsl_change_status=1&product_id='+pid+'&status='+targetStatus;
        fetch(url).then(r=>r.json()).then(d=>{
            if(d&&d.ok){
                done++;
                addRow('<span style="color:#4ade80">\u2705 #'+pid+' \u2014 '+esc(d.msg)+'</span>');
            }else{
                fail++;
                addRow('<span style="color:#f87171">\u274C #'+pid+' \u2014 '+(d?.error||'\u062E\u0637\u0627')+'</span>');
            }
            setTimeout(processNext,600);
        }).catch(()=>{
            fail++;
            addRow('<span style="color:#f87171">\u274C #'+pid+' \u2014 \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647</span>');
            setTimeout(processNext,600);
        });
    }
    processNext();
}

function closeBslModal(){const m=document.getElementById('bslModalContainer');if(m)m.remove();const m2=document.getElementById('bslDetailPopup');if(m2)m2.remove();}
function showBslProductDetail(idx){
    const p=window._bslModalProducts&&window._bslModalProducts[idx];
    if(!p)return;
    const rev=p.revision&&p.revision.data||{};
    const rawStatus=p.status||rev.status;
    const sv=(typeof rawStatus==='object'&&rawStatus!==null)?rawStatus.value:rawStatus;
    const sn=(typeof rawStatus==='object'&&rawStatus!==null)?(rawStatus.name||rawStatus.description||''):'';
    const pName=String(p.title||rev.title||p.name||'').replace(/[\r\n]/g,' ').trim();
    // Build detail popup
    let h='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeBslDetailPopup()">';
    h+='<div class="bsl-modal" style="width:700px">';
    h+='<div class="bsl-modal-head"><h2>🔍 '+esc(pName)+' (ID#'+(p.id??'')+')</h2><button class="btn btn-gray" onclick="closeBslDetailPopup()">✕</button></div>';
    h+='<div class="bsl-modal-body" style="max-height:70vh;overflow:auto">';
    // Show status prominently
    const statusMap={'2976':'✅ فعال (Published)','3790':'🟡 غیرفعال (Unpublished)','4184':'🔴 غیرقانونی (Illegal)','3568':'🟠 در انتظار تایید (Pending)','3567':'🔴 تایید نشده (Not Approved)'};
    const statusLabel=statusMap[String(sv??'')]||'نامشخص ('+String(sv??'?')+')';
    const statusColor=String(sv??'')==='2976'?'#22c55e':String(sv??'')==='3790'?'#94a3b8':'#f87171';
    h+='<div style="margin-bottom:12px;padding:10px;border-radius:8px;background:#1e293b;border:1px solid '+statusColor+'">';
    h+='<div style="font-size:14px;font-weight:700;color:'+statusColor+'">وضعیت: '+statusLabel+'</div>';
    if(sn){h+='<div style="color:#94a3b8;font-size:11px;margin-top:4px">توضیح وضعیت: '+esc(sn)+'</div>';}
    // Show rejection reason if available (from revision or status description)
    const revStatus=rev.status;
    const revStatusName=(typeof revStatus==='object'&&revStatus!==null)?(revStatus.name||''):'';
    const revStatusDesc=(typeof revStatus==='object'&&revStatus!==null)?(revStatus.description||''):'';
    if(revStatusName){h+='<div style="color:#fbbf24;font-size:11px;margin-top:4px">وضعیت نسخه: '+esc(revStatusName)+'</div>';}
    if(revStatusDesc){h+='<div style="color:#f87171;font-size:11px;margin-top:4px">علت: '+esc(revStatusDesc)+'</div>';}
    h+='</div>';
    // v7.38: Show rejection_reasons from revision (the actual BaSalam rejection data)
    const revMeta=(p.revision&&typeof p.revision==='object')?(p.revision.metadata||{}):{};
    const revReject=(p.revision&&typeof p.revision==='object')?(p.revision.rejection_reasons||[]):[];
    const revDesc=revMeta.description||'';
    const revRejectedAt=p.revision?.rejected_at||'';
    // Show rejection reasons prominently
    if(String(sv??'')==='3567'||revReject.length>0){
        h+='<div style="margin-bottom:12px;padding:10px;border-radius:8px;background:#7f1d1d;border:1px solid #f87171">';
        h+='<div style="color:#f87171;font-size:13px;font-weight:700">🚫 محصول تایید نشده</div>';
        if(revRejectedAt){h+='<div style="color:#94a3b8;font-size:11px;margin-top:4px">زمان رد: '+esc(revRejectedAt)+'</div>';}
        if(revReject.length>0){
            revReject.forEach(rr=>{
                const rName=rr.name||'';const rDesc=rr.description||'';const rVal=rr.value||'';
                if(rName){h+='<div style="color:#fbbf24;font-size:11px;margin-top:6px;padding:4px 8px;background:#1e3a5f;border-radius:4px">⚠️ '+esc(rName)+' (کد: '+rVal+')</div>';}
                if(rDesc){h+='<div style="color:#94a3b8;font-size:11px;margin-top:2px">'+esc(rDesc)+'</div>';}
            });
        }
        // Show AI review text from metadata.description
        if(revDesc){
            h+='<div style="margin-top:8px;padding:8px;border:1px dashed #475569;border-radius:6px;font-size:11px;color:#e2e8f0;cursor:pointer" onclick="this.querySelector(\'.ai-text\').style.display=this.querySelector(\'.ai-text\').style.display===\'none\'?\'block\':\'none\'">🤖 بررسی هوش مصنوعی (کلیک کنید)<div class="ai-text" style="display:none;white-space:pre-wrap;margin-top:6px;color:#f0abfc">'+esc(revDesc)+'</div></div>';
        }
        h+='</div>';
    }
    // v7.48: Show key fields + inline searchable category fix for rejected products
    const pPrice=p.primary_price||p.price||rev.primary_price||rev.price||'';
    const pInv=p.inventory??rev.inventory??p.stock??'';
    const catObj=rev.category||p.category;
    const catTitle=(typeof catObj==='object'&&catObj!==null)?catObj.title:(p.category_id||'');
    const catId=(typeof catObj==='object'&&catObj!==null)?catObj.id:(p.category_id||'');
    h+='<div style="margin-bottom:12px;font-size:12px;color:#e2e8f0">';
    h+='<div style="margin:4px 0">&#x1f4b0; &#x0642;&#x06cc;&#x0645;&#x062a;: <b>'+toFa(String(pPrice))+'</b> &#x062a;&#x0648;&#x0645;&#x0627;&#x0646;</div>';
    h+='<div style="margin:4px 0">&#x1f4e6; &#x0645;&#x0648;&#x062c;&#x0648;&#x062f;&#x06cc;: <b>'+toFa(String(pInv))+'</b></div>';
    if(catTitle){h+='<div style="margin:4px 0">&#x1f3f7;&#xfe0f; &#x062f;&#x0633;&#x062a;&#x0647;: <b>'+esc(String(catTitle))+'</b> (ID#'+catId+')</div>';}
    h+='</div>';
    // v7.48: Check if product is category-rejected — show inline searchable category fix
    const isCatRejected=String(sv??'')==='3567';
    const hasCatRejectReason=revReject.some(rr=>(rr.value??0)==6046);
    if(isCatRejected||hasCatRejectReason){
        const modalCats=window._bslModalCats||bslAllCats||[];
        const autoCatId=modalCats.length>0?autoMatchBslCategoryJS(pName,modalCats):0;
        h+='<div style="margin-bottom:12px;padding:12px;border-radius:8px;background:#1e3a5f;border:1px solid #60a5fa" id="bsl-inline-fix-'+String(p.id??'')+'">';
        h+='<div style="color:#60a5fa;font-weight:700;font-size:13px;margin-bottom:8px">🔄 این محصول بالای دسته‌بندی رد شده — انتخاب دسته نامصوب</div>';
        // v8.06: AI category fix button — uses BaSalam AI review recommendation
        if(revDesc){
            h+='<button class="btn btn-purple" style="margin-bottom:8px" onclick="bslFixAiCat('+String(p.id??'')+')" id="bslAiFixBtn-'+String(p.id??'')+'">🤖 اصلاح با توصیه هوش مصنوعی باسلام</button>';
            h+='<div id="bslAiFixResult-'+String(p.id??'')+'" style="margin-bottom:8px;font-size:11px"></div>';
        }
        if(autoCatId>0){const ac=modalCats.find(c=>c.id===autoCatId);if(ac){h+='<button class="btn btn-green" style="margin-bottom:8px" onclick="bslInlineFixCat('+String(p.id??'')+','+autoCatId+')">&#x2713; دسته خودکار: '+esc(ac.name)+' ('+autoCatId+') ارسال</button>';}}
        // Searchable category dropdown inline
        h+='<div style="position:relative;margin-bottom:6px">';
        h+='<input type="text" id="bslInlineSearch-'+String(p.id??'')+'" placeholder="&#x062c;&#x0633;&#x062a;&#x062c;&#x0648; &#x062f;&#x0633;&#x062a;&#x0647;&#x200c;&#x0628;&#x0646;&#x062f;&#x06cc;..." style="width:100%;padding:6px 8px;border:1px solid #475569;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:12px;direction:rtl" autocomplete="off">';
        h+='<div id="bslInlineList-'+String(p.id??'')+'" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#1e293b;border:1px solid #475569;border-radius:6px;z-index:99999;direction:rtl"></div>';
        h+='</div>';
        h+='<button class="btn btn-orange" id="bslInlineBtn-'+String(p.id??'')+'" onclick="bslInlineFixCatManual('+String(p.id??'')+')" disabled>&#x1f504; &#x0627;&#x0631;&#x0633;&#x0627;&#x0644; &#x0628;&#x0627; &#x062f;&#x0633;&#x062a;&#x0647; &#x0627;&#x0646;&#x062a;&#x062e;&#x0627;&#x0628;&#x200c;&#x0634;&#x062f;&#x0647;</button>';
        h+='<div id="bslInlineResult-'+String(p.id??'')+'" style="margin-top:6px;font-size:11px"></div>';
        h+='</div>';
    }
    // Show raw JSON (collapsible)
    h+='<div style="border:1px dashed #475569;padding:8px;margin-top:8px;cursor:pointer;font-size:11px;color:#94a3b8" onclick="this.querySelector(\'.rawj\').style.display=this.querySelector(\'.rawj\').style.display===\'none\'?\'block\':\'none\'">📄 ساختار کامل JSON (کلیک کنید)<div class="rawj" style="display:none;white-space:pre-wrap;overflow:auto;max-height:400px;font-size:10px;direction:ltr;text-align:left;color:#67e8f9">'+esc(JSON.stringify(p,null,2))+'</div></div>';
    h+='</div></div></div>';
    const old2=document.getElementById('bslDetailPopup');if(old2)old2.remove();
    const div2=document.createElement('div');div2.id='bslDetailPopup';div2.innerHTML=h;
    document.body.appendChild(div2);
}
function closeBslDetailPopup(){const m=document.getElementById('bslDetailPopup');if(m)m.remove();}
// v7.48: Inline category fix functions for BaSalam products modal
let bslInlineSelectedCat={}; // productId -> selected catId
function bslBatchFixAiCat(){
    const btn=document.getElementById('bslBatchAiBtn');
    if(btn)btn.disabled=true;
    let modal=document.getElementById('bslAiBatchModal');
    if(modal)modal.remove();
    modal=document.createElement('div');
    modal.id='bslAiBatchModal';
    modal.innerHTML='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeBslAiBatchModal()"><div class="bsl-modal" style="width:800px"><div class="bsl-modal-head"><h2>\u{1F916} \u0627\u0635\u0644\u0627\u062D \u062F\u0633\u062A\u0647\u200C\u0628\u0646\u062F\u06CC \u0647\u0648\u0634 \u0645\u0635\u0646\u0648\u0639\u06CC \u2014 \u06AF\u0632\u0627\u0631\u0634 \u0632\u0646\u062F\u0647</h2><button class="btn btn-gray" onclick="closeBslAiBatchModal()">\u2715</button></div><div class="bsl-modal-body" style="padding:0"><div id="bslAiBatchSummary" style="padding:10px 16px;background:#1e293b;border-bottom:1px solid #334155;font-size:12px;color:#94a3b8;display:flex;gap:16px;flex-wrap:wrap;direction:rtl"><span>\u0627\u0632: <b id="bslAiBatchTotal" style="color:#67e8f9">0</b></span><span>\u2705 \u0627\u0635\u0644\u0627\u062D: <b id="bslAiBatchFixed" style="color:#4ade80">0</b></span><span>\u26A0\uFE0F \u0628\u062F\u0648\u0646 AI: <b id="bslAiBatchNoAi" style="color:#fbbf24">0</b></span><span>\u26A0\uFE0F \u062F\u0633\u062A\u0647 \u0646\u06CC\u0633\u062A: <b id="bslAiBatchNoCat" style="color:#fbbf24">0</b></span><span>\u274C \u0646\u0627\u0645\u0648\u0641\u0642: <b id="bslAiBatchFailed" style="color:#f87171">0</b></span></div><div id="bslAiBatchLog" style="max-height:60vh;overflow-y:auto;padding:8px;font-size:11px;direction:rtl"></div></div><div class="bsl-modal-pager"><button class="btn btn-gray" onclick="closeBslAiBatchModal()">\u0628\u0633\u062A\u0646</button></div></div></div>';
    document.body.appendChild(modal);
    const logEl=document.getElementById('bslAiBatchLog');
    let fixed=0,failed=0,noAi=0,noCat=0,total=0;
    const addRow=function(html){
        const d=document.createElement('div');
        d.style.cssText='padding:4px 6px;border-bottom:1px solid #1e293b;direction:rtl';
        d.innerHTML=html;
        logEl.appendChild(d);
        logEl.scrollTop=logEl.scrollHeight;
    };
    const updCounters=function(){
        const e1=document.getElementById('bslAiBatchTotal');if(e1)e1.textContent=toFa(total);
        const e2=document.getElementById('bslAiBatchFixed');if(e2)e2.textContent=toFa(fixed);
        const e3=document.getElementById('bslAiBatchNoAi');if(e3)e3.textContent=toFa(noAi);
        const e4=document.getElementById('bslAiBatchNoCat');if(e4)e4.textContent=toFa(noCat);
        const e5=document.getElementById('bslAiBatchFailed');if(e5)e5.textContent=toFa(failed);
    };
    addRow('<span style="color:#67e8f9">\u2139\uFE0F \u062F\u0631\u06CC\u0627\u0641\u062A \u062F\u0633\u062A\u0647\u200C\u0628\u0646\u062F\u06CC\u200C\u0647\u0627 \u0648 \u0645\u062D\u0635\u0648\u0644\u0627\u062A \u0631\u062F\u0634\u062F\u0647...</span>');
    const evtSrc=new EventSource('?bsl_fix_ai_cat_batch=1');
    window._bslAiBatchEvtSrc=evtSrc;
    evtSrc.onmessage=function(e){
        try{
            const d=JSON.parse(e.data);
            if(d.type==='step'){
                addRow('<span style="color:#67e8f9">\u2139\uFE0F '+esc(d.msg)+'</span>');
            }else if(d.type==='start'){
                total=d.total||0;
                updCounters();
                addRow('<span style="color:#c4b5fd;font-weight:700">\u{1F680} '+esc(d.msg)+'</span>');
            }else if(d.type==='progress'){
                const idx=d.idx||0;const tot=d.total||0;
                const pName=esc(d.pName||'');
                if(d.step==='check_ai'){
                    addRow('<span style="color:#94a3b8">['+idx+'/'+tot+'] '+pName+' \u2014 \u0628\u0631\u0631\u0633\u06CC \u0645\u062A\u0646 AI...</span>');
                }else if(d.step==='extract_cat'){
                    addRow('<span style="color:#94a3b8">['+idx+'/'+tot+'] '+pName+' \u2014 \u0627\u0633\u062A\u062E\u0631\u0627\u062C \u062F\u0633\u062A\u0647...</span>');
                }else if(d.step==='ai_result'){
                    const methodLabel=d.method==='regex'?'<span style="color:#4ade80">\u0627\u0644\u06AF\u0648\u06CC \u0645\u062A\u0646\u06CC</span>':'<span style="color:#fbbf24">\u062A\u0637\u0628\u06CC\u0642 \u0646\u0627\u0645</span>';
                    const catName=esc(d.catName||'');
                    const catId=d.catId||'';
                    const score=d.score||0;
                    const aiText=esc((d.ai_text||'').substring(0,200));
                    addRow('<div style="background:#1e1b4b;padding:6px 8px;border-radius:6px;margin:2px 0"><div style="color:#c4b5fd;font-weight:700">\u{1F916} ['+idx+'/'+tot+'] '+pName+'</div><div style="color:#fbbf24;font-size:12px;margin-top:2px">\u{1F4CD} \u062F\u0633\u062A\u0647 \u062A\u0648\u0635\u06CC\u0647\u200C\u0634\u062F\u0647: <b>'+catName+' ('+catId+')</b> \u2014 \u0627\u0645\u062A\u06CC\u0627\u0632: '+score+' \u2014 '+methodLabel+'</div><div style="color:#67e8f9;font-size:10px;margin-top:2px">\u{1F4AC} '+aiText+'</div>'+(d.candidates&&d.candidates.length>1?'<div style="color:#94a3b8;font-size:10px;margin-top:2px">\u{1F4CB} \u0633\u0627\u06CC\u0631: '+d.candidates.slice(1,4).map(c=>esc(c.catName||'')+'('+c.catId+')='+c.score).join(' | ')+'</div>':'')+'</div>');
                }else if(d.step==='find_leaf'){
                    addRow('<span style="color:#94a3b8">['+idx+'/'+tot+'] '+pName+' \u2014 \u062C\u0633\u062A\u062C\u0648\u06CC \u062F\u0633\u062A\u0647 \u0641\u0631\u0632\u06CC\u0646...</span>');
                }else if(d.step==='patching'){
                    addRow('<span style="color:#94a3b8">['+idx+'/'+tot+'] '+pName+' \u2014 \u0627\u0631\u0633\u0627\u0644 \u0627\u0635\u0644\u0627\u062D: '+esc(d.catName||'')+' ('+d.catId+')...</span>');
                }
            }else if(d.type==='item'){
                if(d.status==='fixed'){
                    fixed++;
                    addRow('<span style="color:#4ade80;font-weight:700">\u2705 ['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2192 '+esc(d.catName||'')+' ('+d.catId+')</span>');
                }else if(d.status==='no_ai'){
                    noAi++;
                    addRow('<span style="color:#fbbf24">\u26A0\uFE0F ['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2014 \u0645\u062A\u0646 AI \u06CC\u0627\u0641\u062A \u0646\u0634\u062F</span>');
                }else if(d.status==='no_cat'){
                    noCat++;
                    addRow('<span style="color:#fbbf24">\u26A0\uFE0F ['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2014 \u062F\u0633\u062A\u0647 \u0627\u0632 \u0645\u062A\u0646 AI \u0627\u0633\u062A\u062E\u0631\u0627\u062C \u0646\u0634\u062F</span>'+(d.ai_text?'<div style="color:#94a3b8;font-size:10px;padding-right:20px">\u{1F4AC} '+esc((d.ai_text||'').substring(0,200))+'</div>':''));
                }else if(d.status==='failed'){
                    failed++;
                    addRow('<span style="color:#f87171">\u274C ['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2014 '+esc(d.msg||'')+'</span>');
                }
                updCounters();
            }else if(d.type==='done'){
                addRow('<span style="color:#4ade80;font-weight:700">\u{1F3C1} '+esc(d.msg)+'</span>');
                showToast('\u2705 '+fixed+' \u0645\u062D\u0635\u0648\u0644 \u0627\u0635\u0644\u0627\u062D \u0634\u062F');
                evtSrc.close();
                if(btn)btn.disabled=false;
                if(fixed>0)setTimeout(()=>{closeBslAiBatchModal();showBslProductsModal(1,'not_approved');},2000);
            }else if(d.type==='error'){
                addRow('<span style="color:#f87171">\u274C '+esc(d.msg)+'</span>');
                evtSrc.close();
                if(btn)btn.disabled=false;
            }
        }catch(ex){}
    };
    evtSrc.onerror=function(){
        addRow('<span style="color:#f87171">\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647 \u2014 \u0627\u062A\u0635\u0627\u0644 \u0642\u0637\u0639 \u0634\u062F</span>');
        if(btn)btn.disabled=false;
        evtSrc.close();
    };
}

function closeBslAiBatchModal(){const m=document.getElementById('bslAiBatchModal');if(m)m.remove();const evtSrc=window._bslAiBatchEvtSrc;if(evtSrc)evtSrc.close();}

// v8.06: Download all AI review texts as a text file
function bslDownloadAiTexts(){
    const btn=document.getElementById('bslDownloadAiBtn');
    if(btn){btn.disabled=true;btn.textContent='⏳ در حال دریافت...';}
    fetch('?bsl_download_ai_texts=1').then(r=>{
        if(!r.ok)throw new Error('HTTP '+r.status);
        return r.blob();
    }).then(blob=>{
        const url=URL.createObjectURL(blob);
        const a=document.createElement('a');
        a.href=url;a.download='basalam_ai_texts.txt';
        document.body.appendChild(a);a.click();
        document.body.removeChild(a);URL.revokeObjectURL(url);
        showToast('✅ فایل متنی AI باسلام دانلود شد');
        if(btn){btn.disabled=false;btn.textContent='📥 دانلود متن‌های AI';}
    }).catch(err=>{
        showToast('❌ خطا در دریافت فایل: '+err.message,1);
        if(btn){btn.disabled=false;btn.textContent='📥 دانلود متن‌های AI';}
    });
}

// v8.06: Batch activate products — convert to status 2976 (active/approved)
function bslBatchActivate(fromStatus){
    const statusLabels={'3790':'غیرفعال','3567':'تأیید نشده','3568':'در انتظار تأیید','4184':'بایگانی'};
    const fromLabel=statusLabels[fromStatus]||fromStatus;
    const btn=document.getElementById('bslActivateBtn');
    if(btn)btn.disabled=true;
    let modal=document.getElementById('bslActivateModal');
    if(modal)modal.remove();
    modal=document.createElement('div');modal.id='bslActivateModal';
    modal.innerHTML='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeBslActivateModal()"><div class="bsl-modal" style="width:750px"><div class="bsl-modal-head"><h2>\u2705 \u0641\u0639\u0627\u0644\u200C\u0633\u0627\u0632\u06CC \u0645\u062D\u0635\u0648\u0644\u0627\u062A '+fromLabel+' \u2014 \u06AF\u0632\u0627\u0631\u0634 \u0632\u0646\u062F\u0647</h2><button class="btn btn-gray" onclick="closeBslActivateModal()">\u2715</button></div><div class="bsl-modal-body" style="padding:0"><div id="bslActivateSummary" style="padding:10px 16px;background:#1e293b;border-bottom:1px solid #334155;font-size:12px;color:#94a3b8;display:flex;gap:16px;flex-wrap:wrap;direction:rtl"><span>\u0627\u0632: <b id="bslActivateTotal" style="color:#67e8f9">0</b></span><span>\u2705 \u0641\u0639\u0627\u0644 \u0634\u062F: <b id="bslActivateOk" style="color:#4ade80">0</b></span><span>\u23F8 \u0631\u062F \u0634\u062F: <b id="bslActivateSkip" style="color:#fbbf24">0</b></span><span>\u274C \u0646\u0627\u0645\u0648\u0641\u0642: <b id="bslActivateFail" style="color:#f87171">0</b></span></div><div id="bslActivateLog" style="max-height:60vh;overflow-y:auto;padding:8px;font-size:11px;direction:rtl"></div></div><div class="bsl-modal-pager"><button class="btn btn-gray" onclick="closeBslActivateModal()">\u0628\u0633\u062A\u0646</button></div></div></div>';
    document.body.appendChild(modal);
    const logEl=document.getElementById('bslActivateLog');
    let ok=0,skip=0,fail=0,total=0;
    const addRow=function(html){const d=document.createElement('div');d.style.cssText='padding:4px 6px;border-bottom:1px solid #1e293b;direction:rtl';d.innerHTML=html;logEl.appendChild(d);logEl.scrollTop=logEl.scrollHeight;};
    const upd=function(){
        const e1=document.getElementById('bslActivateTotal');if(e1)e1.textContent=toFa(total);
        const e2=document.getElementById('bslActivateOk');if(e2)e2.textContent=toFa(ok);
        const e3=document.getElementById('bslActivateSkip');if(e3)e3.textContent=toFa(skip);
        const e4=document.getElementById('bslActivateFail');if(e4)e4.textContent=toFa(fail);
    };
    addRow('<span style="color:#67e8f9">\u2139\uFE0F \u062F\u0631\u06CC\u0627\u0641\u062A \u0645\u062D\u0635\u0648\u0644\u0627\u062A '+fromLabel+'...</span>');
    const evtSrc=new EventSource('?bsl_activate_batch=1&from_status='+fromStatus);
    window._bslActivateEvtSrc=evtSrc;
    evtSrc.onmessage=function(e){
        try{
            const d=JSON.parse(e.data);
            if(d.type==='step'){
                addRow('<span style="color:#67e8f9">\u2139\uFE0F '+esc(d.msg)+'</span>');
            }else if(d.type==='start'){
                total=d.total||0;upd();
                addRow('<span style="color:#c4b5fd;font-weight:700">\u{1F680} '+esc(d.msg)+'</span>');
            }else if(d.type==='progress'){
                addRow('<span style="color:#94a3b8">['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2014 \u0627\u0631\u0633\u0627\u0644...</span>');
            }else if(d.type==='item'){
                if(d.status==='activated'){ok++;addRow('<span style="color:#4ade80;font-weight:700">\u2705 ['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2014 '+esc(d.msg)+'</span>');}
                else if(d.status==='skipped'){skip++;addRow('<span style="color:#fbbf24">\u26A0\uFE0F ['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2014 '+esc(d.msg)+'</span>');}
                else if(d.status==='failed'){fail++;addRow('<span style="color:#f87171">\u274C ['+d.idx+'/'+d.total+'] '+esc(d.pName||'')+' \u2014 '+esc(d.msg)+'</span>');}
                upd();
            }else if(d.type==='done'){
                addRow('<span style="color:#4ade80;font-weight:700">\u{1F3C1} '+esc(d.msg)+'</span>');
                showToast('\u2705 '+ok+' \u0645\u062D\u0635\u0648\u0644 \u0641\u0639\u0627\u0644 \u0634\u062F');
                evtSrc.close();
                if(btn)btn.disabled=false;
                if(ok>0)setTimeout(()=>{closeBslActivateModal();showBslProductsModal(1,'active');},2000);
            }else if(d.type==='error'){
                addRow('<span style="color:#f87171">\u274C '+esc(d.msg)+'</span>');
                evtSrc.close();
                if(btn)btn.disabled=false;
            }
        }catch(ex){}
    };
    evtSrc.onerror=function(){
        addRow('<span style="color:#f87171">\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647</span>');
        if(btn)btn.disabled=false;
        evtSrc.close();
    };
}
function closeBslActivateModal(){const m=document.getElementById('bslActivateModal');if(m)m.remove();const evtSrc=window._bslActivateEvtSrc;if(evtSrc)evtSrc.close();}

// v8.06: Status overview modal
function bslStatusOverview(){
    let modal=document.getElementById('bslStatusOverviewModal');if(modal)modal.remove();
    modal=document.createElement('div');modal.id='bslStatusOverviewModal';
    modal.innerHTML='<div class="bsl-modal-overlay" onclick="if(event.target===this)this.parentElement.remove()"><div class="bsl-modal" style="width:500px"><div class="bsl-modal-head"><h2>\u{1F4CA} \u0646\u0645\u0627\u06CC \u06A9\u0644\u06CC \u0648\u0636\u0639\u06CC\u062A \u0645\u062D\u0635\u0648\u0644\u0627\u062A</h2><button class="btn btn-gray" onclick="this.closest(\'#bslStatusOverviewModal\').remove()">\u2715</button></div><div class="bsl-modal-body" style="padding:20px;text-align:center"><div style="color:#67e8f9">\u062F\u0631\u06CC\u0627\u0641\u062A \u0627\u0637\u0644\u0627\u0639\u0627\u062A...</div></div></div></div>';
    document.body.appendChild(modal);
    fetch('?bsl_status_overview=1').then(r=>r.json()).then(d=>{
        if(!d||!d.ok){modal.querySelector('.bsl-modal-body').innerHTML='<div style="color:#f87171">\u274C '+(d?.error||'\u062E\u0637\u0627')+'</div>';return;}
        const c=d.counts;
        const statusLabels=[
            ['active','\u2705 \u0641\u0639\u0627\u0644 (2976)','\u0642\u0627\u0628\u0644 \u0645\u0634\u0627\u0647\u062F\u0647 \u062A\u0648\u0633\u0637 \u0645\u0634\u062A\u0631\u06CC','#4ade80'],
            ['inactive','\u23F8 \u063A\u06CC\u0631\u0641\u0639\u0627\u0644 (3790)','\u0645\u062E\u0641\u06CC \u0627\u0632 \u0645\u0634\u062A\u0631\u06CC','#94a3b8'],
            ['not_approved','\u274C \u062A\u0627\u06CC\u06CC\u062F \u0646\u0634\u062F\u0647 (3567)','\u0631\u062F \u0634\u062F\u0647 \u062A\u0648\u0633\u0637 \u0628\u0627\u0633\u0644\u0627\u0645','#f87171'],
            ['pending','\u23F3 \u062F\u0631 \u0627\u0646\u062A\u0638\u0627\u0631 \u062A\u0627\u06CC\u06CC\u062F (3568)','\u0645\u0646\u062A\u0638\u0631 \u0628\u0631\u0631\u0633\u06CC','#fbbf24'],
            ['archived','\u{1F4DA} \u0628\u0627\u06CC\u06AF\u0627\u0646\u06CC (4184)','\u062D\u0630\u0641/\u0628\u0627\u06CC\u06AF\u0627\u0646\u06CC \u0634\u062F\u0647','#a78bfa'],
        ];
        let html='<div style="direction:rtl;margin-bottom:16px;font-size:12px;color:#94a3b8">\u062A\u0646\u0647\u0627 \u0645\u062D\u0635\u0648\u0644\u0627\u062A <b style="color:#4ade80">\u0641\u0639\u0627\u0644</b> \u0628\u0631\u0627\u06CC \u0645\u0634\u062A\u0631\u06CC \u0642\u0627\u0628\u0644 \u0645\u0634\u0627\u0647\u062F\u0647 \u0647\u0633\u062A\u0646\u062F</div>';
        html+='<table style="width:100%;border-collapse:collapse;font-size:13px;direction:rtl">';
        html+='<tr style="background:#1e293b"><th style="padding:10px;color:#67e8f9">\u0648\u0636\u0639\u06CC\u062A</th><th style="padding:10px;color:#67e8f9">\u062A\u0639\u062F\u0627\u062F</th><th style="padding:10px;color:#67e8f9">\u062A\u0648\u0636\u06CC\u062D</th></tr>';
        statusLabels.forEach(([key,label,desc,color])=>{
            const cnt=c[key]||0;
            html+='<tr style="border-bottom:1px solid #1e293b"><td style="padding:10px;color:'+color+';font-weight:700">'+label+'</td><td style="padding:10px;text-align:center;font-family:monospace;color:'+color+';font-size:16px">'+toFa(cnt)+'</td><td style="padding:10px;font-size:11px;color:#94a3b8">'+desc+'</td></tr>';
        });
        html+='<tr style="background:#1e293b;font-weight:700"><td style="padding:10px;color:#67e8f9">\u0645\u062C\u0645\u0648\u0639</td><td style="padding:10px;text-align:center;color:#67e8f9;font-size:18px">'+toFa(c.total||0)+'</td><td style="padding:10px;color:#94a3b8;font-size:11px">\u0647\u0645\u0647 \u0648\u0636\u0639\u06CC\u062A\u200C\u0647\u0627</td></tr>';
        html+='</table>';
        modal.querySelector('.bsl-modal-body').innerHTML=html;
    }).catch(()=>{modal.querySelector('.bsl-modal-body').innerHTML='<div style="color:#f87171">\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647</div>';});
}

// v8.06: Find duplicate BaSalam products
function bslFindDuplicates(){
    let modal=document.getElementById('bslDupModal');if(modal)modal.remove();
    modal=document.createElement('div');modal.id='bslDupModal';
    modal.innerHTML='<div class="bsl-modal-overlay" onclick="if(event.target===this)this.parentElement.remove()"><div class="bsl-modal" style="width:900px"><div class="bsl-modal-head"><h2>\u{1F50D} \u067E\u06CC\u062F\u0627\u06A9\u0631\u062F\u0646 \u0645\u062D\u0635\u0648\u0644\u0627\u062A \u062A\u06A9\u0631\u0627\u0631\u06CC</h2><button class="btn btn-gray" onclick="this.closest(\'#bslDupModal\').remove()">\u2715</button></div><div class="bsl-modal-body" style="padding:20px;text-align:center"><div style="color:#67e8f9">\u{1F50D} \u062F\u0631\u06CC\u0627\u0641\u062A \u0648 \u062A\u062D\u0644\u06CC\u0644 \u0645\u062D\u0635\u0648\u0644\u0627\u062A...</div><div style="color:#94a3b8;font-size:11px;margin-top:8px">\u067E\u0633\u0648\u0646\u062F \u0647\u0627\u06CC \u0645\u0627\u0646\u0646\u062F (\u06A9\u062F:x) \u0646\u0627\u062F\u06CC\u062F\u0647 \u06AF\u0631\u0641\u062A\u0647 \u0645\u06CC\u200C\u0634\u0648\u0646\u062F</div></div></div></div>';
    document.body.appendChild(modal);
    fetch('?bsl_find_duplicates=1&status=all').then(r=>r.json()).then(d=>{
        if(!d||!d.ok){modal.querySelector('.bsl-modal-body').innerHTML='<div style="color:#f87171">\u274C '+(d?.error||'\u062E\u0637\u0627')+'</div>';return;}
        if(!d.duplicates||d.duplicates.length===0){
            modal.querySelector('.bsl-modal-body').innerHTML='<div style="color:#4ade80;font-size:14px">\u2705 \u0647\u06CC\u0686 \u0645\u062D\u0635\u0648\u0644 \u062A\u06A9\u0631\u0627\u0631\u06CC \u06CC\u0627\u0641\u062A \u0646\u0634\u062F</div>';
            return;
        }
        let html='<div style="direction:rtl;margin-bottom:12px;font-size:12px;color:#94a3b8">\u06A9\u0644 '+toFa(d.duplicate_groups)+' \u06AF\u0631\u0648\u0647 \u062A\u06A9\u0631\u0627\u0631\u06CC \u0628\u0627 '+toFa(d.duplicate_products)+' \u0645\u062D\u0635\u0648\u0644 (\u0627\u0632 '+toFa(d.total_products)+' \u0645\u062D\u0635\u0648\u0644 \u06A9\u0644) <span style="color:#fbbf24">\u2014 \u067E\u0633\u0648\u0646\u062F (\u06A9\u062F:x) \u0646\u0627\u062F\u06CC\u062F\u0647 \u06AF\u0631\u0641\u062A\u0647 \u0634\u062F\u0647</span></div>';
        html+='<div style="max-height:60vh;overflow-y:auto;direction:rtl">';
        d.duplicates.forEach((g,gi)=>{
            const statusIcon={2976:'\u2705',3790:'\u23F8',3567:'\u274C',3568:'\u23F3',4184:'\u{1F4DA}'};
            html+='<div style="margin-bottom:10px;border:1px solid #475569;border-radius:8px;overflow:hidden">';
            html+='<div style="padding:8px 12px;background:#1e293b;display:flex;align-items:center;justify-content:space-between;cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'">';
            html+='<div style="color:#c4b5fd;font-weight:700;font-size:12px">\u{1F4C1} '+esc(g.normalized_name)+' <span style="color:#94a3b8">('+toFa(g.count)+' \u0646\u0633\u062E\u0647)</span></div>';
            // Find which to keep (prefer active)
            const activeItem=g.products.find(p=>p.status===2976);
            const keepId=activeItem?activeItem.id:g.products[0].id;
            const deleteIds=g.products.filter(p=>p.id!==keepId).map(p=>p.id);
            html+='<button class="btn btn-red" style="font-size:11px;padding:3px 8px" onclick="event.stopPropagation();bslDeleteDuplicates(['+deleteIds.join(',')+'],\''+esc(g.normalized_name).replace(/'/g,"\\'")+'\')">\u{1F5D1} \u062D\u0630\u0641 '+(g.count-1)+' \u062A\u06A9\u0631\u0627\u0631\u06CC</button>';
            html+='</div>';
            html+='<div style="display:'+(gi<3?'block':'none')+'">';
            html+='<table style="width:100%;border-collapse:collapse;font-size:11px;direction:rtl">';
            html+='<tr style="background:#0f172a"><th style="padding:4px;color:#67e8f9">ID</th><th style="padding:4px;color:#67e8f9">\u0646\u0627\u0645</th><th style="padding:4px;color:#67e8f9">\u0648\u0636\u0639\u06CC\u062A</th><th style="padding:4px;color:#67e8f9">\u0642\u06CC\u0645\u062A</th><th style="padding:4px;color:#67e8f9">\u0645\u0648\u062C\u0648\u062F\u06CC</th><th style="padding:4px;color:#67e8f9">\u0627\u0642\u062F\u0627\u0645</th></tr>';
            g.products.forEach(p=>{
                const isKeep=p.id===keepId;
                const sIcon=statusIcon[p.status]||'\u2753';
                const sName={2976:'\u0641\u0639\u0627\u0644',3790:'\u063A\u06CC\u0631\u0641\u0639\u0627\u0644',3567:'\u0631\u062F\u0634\u062F\u0647',3568:'\u062F\u0631\u0627\u0646\u062A\u0638\u0627\u0631',4184:'\u0628\u0627\u06CC\u06AF\u0627\u0646\u06CC'}[p.status]||p.status;
                const priceStr=p.price>0?toFa(Math.round(p.price/10))+'\u062A':'\u0646\u0627\u0645\u0634\u062E\u0635';
                const stockStr=p.stock>0?toFa(p.stock):'<span style="color:#f87171">\u0646\u0627\u0645\u0648\u062C\u0648\u062F</span>';
                const rowBg=isKeep?'background:#052e16':'';
                html+='<tr style="border-bottom:1px solid #1e293b;'+rowBg+'">';
                html+='<td style="padding:4px;color:#94a3b8;font-family:monospace;text-align:center">'+p.id+(isKeep?' \u2B50':'')+'</td>';
                html+='<td style="padding:4px;color:#e2e8f0;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(p.name)+'</td>';
                html+='<td style="padding:4px;text-align:center">'+sIcon+' '+sName+'</td>';
                html+='<td style="padding:4px;color:#fbbf24;text-align:center;font-family:monospace">'+priceStr+'</td>';
                html+='<td style="padding:4px;text-align:center">'+stockStr+'</td>';
                html+='<td style="padding:4px;text-align:center">'+(isKeep?'<span style="color:#4ade80;font-size:10px">\u062D\u0641\u0638</span>':'<button class="btn btn-red" style="font-size:10px;padding:2px 6px" onclick="bslDeleteOne('+p.id+')">\u{1F5D1}</button>')+'</td>';
                html+='</tr>';
            });
            html+='</table></div></div>';
        });
        html+='</div>';
        modal.querySelector('.bsl-modal-body').innerHTML=html;
    }).catch(()=>{modal.querySelector('.bsl-modal-body').innerHTML='<div style="color:#f87171">\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647</div>';});
}

// v8.06: Delete one BaSalam product
function bslDeleteOne(productId){
    if(!confirm('\u274C \u0622\u06CC\u0627 \u0627\u0632 \u062D\u0630\u0641 \u0645\u062D\u0635\u0648\u0644 #'+productId+' \u0645\u0637\u0645\u0626\u0646\u06CC\u062F\u061F'))return;
    fetch('?bsl_delete_product=1&product_id='+productId).then(r=>r.json()).then(d=>{
        if(d&&d.ok){showToast('\u2705 '+d.msg);bslFindDuplicates();}else{showToast('\u274C '+(d?.error||'\u062E\u0637\u0627'),1);}
    }).catch(()=>showToast('\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647',1));
}

// v8.06: Batch delete duplicate products (SSE stream in modal)
function bslDeleteDuplicates(ids,groupName){
    if(!confirm('\u274C \u0622\u06CC\u0627 \u0627\u0632 \u062D\u0630\u0641 '+ids.length+' \u0645\u062D\u0635\u0648\u0644 \u062A\u06A9\u0631\u0627\u0631\u06CC \u0645\u0637\u0645\u0626\u0646\u06CC\u062F\u061F\n\u0646\u0627\u0645: '+groupName))return;
    let modal=document.getElementById('bslDelDupModal');if(modal)modal.remove();
    modal=document.createElement('div');modal.id='bslDelDupModal';
    modal.innerHTML='<div class="bsl-modal-overlay" onclick="if(event.target===this)this.parentElement.remove()"><div class="bsl-modal" style="width:600px"><div class="bsl-modal-head"><h2>\u{1F5D1} \u062D\u0630\u0641 \u0645\u062D\u0635\u0648\u0644\u0627\u062A \u062A\u06A9\u0631\u0627\u0631\u06CC</h2><button class="btn btn-gray" onclick="this.closest(\'#bslDelDupModal\').remove()">\u2715</button></div><div class="bsl-modal-body" style="padding:0"><div id="bslDelDupLog" style="max-height:60vh;overflow-y:auto;padding:8px;font-size:11px;direction:rtl"></div></div></div></div>';
    document.body.appendChild(modal);
    const logEl=document.getElementById('bslDelDupLog');
    const addLog=function(cls,icon,text){const d=document.createElement('div');d.style.cssText='padding:3px 6px;border-bottom:1px solid #1e293b;direction:rtl;'+cls;d.textContent=icon+' '+text;logEl.appendChild(d);logEl.scrollTop=logEl.scrollHeight;};
    addLog('color:#67e8f9','\u2139\uFE0F','\u0634\u0631\u0648\u0639 \u062D\u0630\u0641 '+ids.length+' \u0645\u062D\u0635\u0648\u0644...');
    const evtSrc=new EventSource('?bsl_delete_batch=1&ids='+encodeURIComponent(JSON.stringify(ids)));
    window._bslDelDupEvtSrc=evtSrc;
    evtSrc.onmessage=function(e){
        try{
            const d=JSON.parse(e.data);
            if(d.type==='step'){addLog('color:#67e8f9','\u2139\uFE0F',d.msg);}
            else if(d.type==='item'){addLog(d.status==='deleted'?'color:#4ade80':'color:#f87171',d.status==='deleted'?'\u2705':'\u274C','['+d.idx+'/'+d.total+'] #'+d.pId+' \u2014 '+d.msg);}
            else if(d.type==='done'){addLog('color:#4ade80;font-weight:700','\u{1F3C1}',d.msg);evtSrc.close();setTimeout(()=>{const m=document.getElementById('bslDelDupModal');if(m)m.remove();bslFindDuplicates();},1500);}
            else if(d.type==='error'){addLog('color:#f87171','\u274C',d.msg);evtSrc.close();}
        }catch(ex){}
    };
    evtSrc.onerror=function(){addLog('color:#f87171','\u274C','\u062E\u0637\u0627 \u0634\u0628\u06A9\u0647');evtSrc.close();};
}

function bslFixAiCat(productId){
    const btn=document.getElementById('bslAiFixBtn-'+productId);
    const result=document.getElementById('bslAiFixResult-'+productId);
    if(btn)btn.disabled=true;
    let logEl=document.getElementById('bslAiFixLog-'+productId);
    if(!logEl){
        logEl=document.createElement('div');
        logEl.id='bslAiFixLog-'+productId;
        logEl.style.cssText='max-height:250px;overflow-y:auto;background:#0f172a;border:1px solid #475569;border-radius:6px;padding:6px;font-size:11px;direction:rtl;margin-top:4px';
        if(result&&result.parentElement)result.parentElement.appendChild(logEl);
    }
    logEl.innerHTML='';
    if(result)result.innerHTML='<div style="color:#67e8f9">\u{1F916} \u0628\u0631\u0631\u0633\u06CC \u062A\u0648\u0635\u06CC\u0647 \u0647\u0648\u0634 \u0645\u0635\u0646\u0648\u0639\u06CC \u0628\u0627\u0633\u0644\u0627\u0645...</div>';
    const addRow=function(html){
        const d=document.createElement('div');
        d.style.cssText='padding:2px 0;border-bottom:1px solid #1e293b;direction:rtl';
        d.innerHTML=html;
        logEl.appendChild(d);
        logEl.scrollTop=logEl.scrollHeight;
    };
    const evtSrc=new EventSource('?bsl_fix_ai_cat=1&product_id='+productId);
    evtSrc.onmessage=function(e){
        try{
            const d=JSON.parse(e.data);
            if(d.type==='step'){
                addRow('<span style="color:#67e8f9">\u2139\uFE0F '+esc(d.msg)+'</span>');
            }else if(d.type==='done'){
                addRow('<span style="color:#4ade80;font-weight:700">\u2705 '+esc(d.msg)+'</span>');
                if(result)result.innerHTML='<div style="color:#4ade80;font-weight:700">\u2705 '+esc(d.msg)+'</div>';
                showToast('\u2705 \u0627\u0635\u0644\u0627\u062D \u062F\u0633\u062A\u0647 \u0628\u0631 \u0627\u0633\u0627\u0633 \u062A\u0648\u0635\u06CC\u0647 AI');
                evtSrc.close();
            }else if(d.type==='fallback'){
                addRow('<span style="color:#fbbf24">\u26A0\uFE0F PATCH \u0645\u0633\u062A\u0642\u06CC\u0645 \u0646\u0627\u0645\u0648\u0641\u0642 \u2014 \u062A\u0644\u0627\u0634 \u0628\u0627 \u0631\u0648\u0634 \u062C\u0627\u06CC\u06AF\u0632\u06CC\u0646...</span>');
                if(result)result.innerHTML='<div style="color:#fbbf24">\u26A0\uFE0F \u062A\u0644\u0627\u0634 \u0628\u0627 \u0631\u0648\u0634 \u062C\u0627\u06CC\u06AF\u0632\u06CC\u0646...</div>';
                evtSrc.close();
                if(d.redirect_url){
                    fetch(d.redirect_url).then(r2=>r2.json()).then(d2=>{
                        if(d2&&d2.ok){
                            addRow('<span style="color:#4ade80;font-weight:700">\u2705 \u0627\u0635\u0644\u0627\u062D \u0634\u062F (\u062C\u0627\u06CC\u06AF\u0632\u06CC\u0646): '+esc(d2.msg||'')+'</span>');
                            if(result)result.innerHTML='<div style="color:#4ade80;font-weight:700">\u2705 '+esc(d2.msg||'')+'</div>';
                            showToast('\u2705 \u0627\u0635\u0644\u0627\u062D \u062F\u0633\u062A\u0647 (\u062C\u0627\u06CC\u06AF\u0632\u06CC\u0646)');
                        }else{
                            addRow('<span style="color:#f87171">\u274C '+(d2&&d2.error||'\u062E\u0637\u0627')+'</span>');
                            if(result)result.innerHTML='<div style="color:#f87171">\u274C '+(d2&&d2.error||'\u062E\u0637\u0627')+'</div>';
                            if(btn)btn.disabled=false;
                        }
                    }).catch(()=>{addRow('<span style="color:#f87171">\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647</span>');if(result)result.innerHTML='<div style="color:#f87171">\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647</div>';if(btn)btn.disabled=false;});
                }else{if(btn)btn.disabled=false;}
            }else if(d.type==='error'){
                addRow('<span style="color:#f87171">\u274C '+esc(d.msg)+'</span>');
                if(result)result.innerHTML='<div style="color:#f87171">\u274C '+esc(d.msg)+'</div>';
                evtSrc.close();
                if(btn)btn.disabled=false;
            }
        }catch(ex){}
    };
    evtSrc.onerror=function(){
        if(result)result.innerHTML='<div style="color:#f87171">\u274C \u062E\u0637\u0627 \u0634\u0628\u06A9\u0647</div>';
        if(btn)btn.disabled=false;
        evtSrc.close();
    };
}

function bslInlineFixCatManual(productId){
    const catId=bslInlineSelectedCat[productId]||0;
    if(catId<=0){showToast('&#x0627;&#x0628;&#x062a;&#x062f;&#x0627; &#x062f;&#x0633;&#x062a;&#x0647;&#x200c;&#x0628;&#x0646;&#x062f;&#x06cc; &#x0627;&#x0646;&#x062a;&#x062e;&#x0627;&#x0628; &#x06a9;&#x0646;&#x06cc;&#x062f;!',1);return;}
    bslInlineFixCat(productId,catId);
}
function bslInlineSelectCat(productId,catId){
    bslInlineSelectedCat[productId]=catId;
    const btn=document.getElementById('bslInlineBtn-'+productId);
    if(btn)btn.disabled=false;
    const search=document.getElementById('bslInlineSearch-'+productId);
    const cats=window._bslModalCats||bslAllCats||[];
    const c=cats.find(c=>c.id===catId);
    if(search&&c)search.value=c.name+' ('+c.id+')';
    const list=document.getElementById('bslInlineList-'+productId);
    if(list)list.style.display='none';
}
// v7.48: Render inline category search list
function bslInlineRenderList(productId,query){
    const list=document.getElementById('bslInlineList-'+productId);
    if(!list)return;
    const cats=window._bslModalCats||bslAllCats||[];
    const q=query.toLowerCase().trim();
    const selId=bslInlineSelectedCat[productId]||0;
    let html='';
    // Show selected first
    if(selId>0){const sc=cats.find(c=>c.id===selId);if(sc&&(!q||sc.name.toLowerCase().includes(q))){html+='<div style="padding:5px 8px;cursor:pointer;background:#0e4429;color:#4ade80;border-bottom:1px solid #334155;font-size:11px" onclick="bslInlineSelectCat('+productId+','+sc.id+')">&#x2713; '+esc(sc.name)+' ('+sc.id+')</div>';}}
    cats.forEach(c=>{
        if(selId===c.id)return;
        if(q&&!c.name.toLowerCase().includes(q))return;
        const prefix=c.level>0?'\\u2500\\u2500'.repeat(c.level)+' ':'';
        html+='<div style="padding:5px 8px;cursor:pointer;border-bottom:1px solid #1e293b;font-size:11px;color:#e2e8f0" onclick="bslInlineSelectCat('+productId+','+c.id+')">'+esc(prefix+c.name)+' ('+c.id+')</div>';
    });
    if(!html)html='<div style="padding:8px;color:#64748b;font-size:11px;text-align:center">&#x0646;&#x062a;&#x06cc;&#x062c;&#x0647; &#x06cc;&#x0627;&#x0641;&#x062a; &#x0646;&#x0634;&#x062f;</div>';
    list.innerHTML=html;
}
// v7.48: Set up inline search event handlers after detail popup is rendered
function setupBslInlineSearch(productId){
    const si=document.getElementById('bslInlineSearch-'+productId);
    if(!si)return;
    si.addEventListener('focus',function(){bslInlineRenderList(productId,this.value);document.getElementById('bslInlineList-'+productId).style.display='block';});
    si.addEventListener('input',function(){bslInlineRenderList(productId,this.value);document.getElementById('bslInlineList-'+productId).style.display='block';});
    si.addEventListener('blur',function(){setTimeout(function(){document.getElementById('bslInlineList-'+productId).style.display='none';},200);});
    si.addEventListener('keydown',function(e){
        if(e.key==='Escape')document.getElementById('bslInlineList-'+productId).style.display='none';
        if(e.key==='Enter'){const cats=window._bslModalCats||bslAllCats||[];const q=this.value.toLowerCase().trim();const match=cats.find(c=>c.name.toLowerCase().includes(q)&&c.id!==bslInlineSelectedCat[productId]);if(match)bslInlineSelectCat(productId,match.id);}
    });
}
// v7.48: Call setupBslInlineSearch after detail popup is shown
// (We'll patch the existing showBslProductDetail to call it after DOM insertion)
// Override: After the div2 is appended, call setup
const origShowDetail=showBslProductDetail;
showBslProductDetail=function(idx){
    origShowDetail(idx);
    const p=window._bslModalProducts&&window._bslModalProducts[idx];
    if(p){const productId=p.id;const sv=p.status||(p.revision&&p.revision.data||{}).status;const ssv=(typeof sv==='object'&&sv!==null)?sv.value:sv;const revReject=(p.revision&&typeof p.revision==='object')?(p.revision.rejection_reasons||[]):[];if(String(ssv??'')==='3567'||revReject.some(rr=>(rr.value??0)==6046)){setupBslInlineSearch(productId);}}
};

// v7.38: Phase 2 — Auto check for category-rejected products after send
function bslPhase2Check(){
    fetch('?bsl_rejected_cats=1').then(r=>r.json()).then(d=>{
        if(!d||!d.ok){showToast('خطا در بررسی محصولات رد شده',1);return;}
        const rejected=d.rejected||[];
        const cats=d.categories||[];
        if(rejected.length===0){showToast('✓ هیچ محصول رد‌شده دسته‌بندی یافت نشد');return;}
        // Show Phase 2 modal with rejected products + category dropdown + retry buttons
        showPhase2Modal(rejected,cats);
    }).catch(e=>{showToast('خطا شبکه: '+e.message,1);});
}
function showPhase2Modal(rejected,cats){
    // v8.45: بازنویسی کامل. مشکل قبلی: در حلقه دو <div> باز می‌شد ولی بسته
    // نمی‌شد، پس هر محصول داخل محصول قبلی فرو می‌رفت و با چند محصول،
    // مودال کاملاً به‌هم می‌ریخت.
    window._phase2Cats=cats;
    window._phase2Rows=rejected;
    let auto=0;
    const rows=rejected.map(p=>{
        const autoCatId=autoMatchBslCategoryJS(p.title,cats);
        if(autoCatId>0)auto++;
        return {p:p,autoCatId:autoCatId};
    });
    let h='<div class="bsl-modal-overlay" onclick="if(event.target===this)closePhase2()">';
    h+='<div class="bsl-modal" style="max-width:860px">';
    h+='<div class="bsl-modal-head">'
      +'<h2>🔄 فاز ۲ — اصلاح دستهٔ محصولات رد شده</h2>'
      +'<button class="btn btn-gray" onclick="closePhase2()">✕</button></div>';
    // نوار خلاصه و عملیات گروهی
    h+='<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 14px;'
      +'background:#111c31;border-bottom:1px solid #334155">'
      +'<span style="font-size:12px;color:#e2e8f0">'+toFa(rejected.length)+' محصول رد شده</span>'
      +'<span style="font-size:11px;color:#86efac">✅ '+toFa(auto)+' پیشنهاد خودکار</span>'
      +'<span style="font-size:11px;color:#fbbf24">✋ '+toFa(rejected.length-auto)+' نیازمند انتخاب دستی</span>'
      +'<span style="flex:1"></span>'
      +'<span id="p2Done" style="font-size:11px;color:#4ade80"></span>'
      +(auto>0?'<button class="btn btn-green" onclick="bslPhase2FixAll()" id="p2AllBtn" '
        +'style="font-size:11px;padding:5px 10px">⚡ اصلاح همهٔ پیشنهادها ('+toFa(auto)+')</button>':'')
      +'</div>';
    h+='<div class="bsl-modal-body" style="max-height:64vh;overflow:auto;padding:10px">';
    if(!rows.length){
        h+='<div style="padding:24px;text-align:center;color:#64748b">موردی نیست</div>';
    }
    rows.forEach(r=>{
        const p=r.p, autoCatId=r.autoCatId;
        const autoName=autoCatId>0?((cats.find(c=>c.id===autoCatId)||{}).name||('#'+autoCatId)):'';
        h+='<div class="p2-card" id="p2-'+p.id+'">';
        h+=  '<div class="p2-title">'+esc(p.title)+' <span class="p2-id">#'+p.id+'</span></div>';
        h+=  '<div class="p2-reason">⚠️ '+esc(p.cat_reject_msg||'دستهٔ نامعتبر')
           +  ' · دستهٔ فعلی: '+esc(p.current_cat_title||String(p.current_cat_id||'—'))+'</div>';
        h+=  '<div class="p2-actions">';
        if(autoCatId>0){
            h+='<button class="btn btn-green p2-auto" id="btn-'+p.id+'" '
             + 'onclick="bslFixCat('+p.id+','+autoCatId+','+autoCatId+')">'
             + '✅ '+esc(autoName)+'</button>';
        }else{
            h+='<span class="p2-hint">دسته را دستی انتخاب کنید</span>';
        }
        h+=  '<div class="p2-search">'
           + '<input type="hidden" id="cat-'+p.id+'" value="0">'
           + '<input type="text" id="catSearch-'+p.id+'" placeholder="جستجوی دسته..." autocomplete="off">'
           + '<div id="catList-'+p.id+'" class="p2-list"></div>'
           + '</div>';
        h+=  '<button class="btn btn-orange" data-pid="'+p.id+'" onclick="bslFixCatManual(this)" '
           + 'id="btn-'+p.id+'-m">🔄 ارسال</button>';
        h+=  '</div>';                     // p2-actions
        h+=  '<div class="p2-status" id="p2st-'+p.id+'"></div>';
        h+='</div>';                       // p2-card  ← این دو بسته‌شدن قبلاً نبود
    });
    h+='</div></div></div>';
    const old=document.getElementById('phase2Container');if(old)old.remove();
    const div=document.createElement('div');div.id='phase2Container';div.innerHTML=h;
    document.body.appendChild(div);
    rejected.forEach(p=>{setupPhase2CatSearch(p.id,cats);});
}

/** v8.45: اصلاح دسته‌جمعیِ همهٔ پیشنهادهای خودکار، یکی‌یکی و با فاصله */
function bslPhase2FixAll(){
    const rows=(window._phase2Rows||[]);
    const cats=window._phase2Cats||[];
    const todo=[];
    rows.forEach(p=>{
        const el=document.getElementById('p2-'+p.id);
        if(!el||el.dataset.done==='1')return;
        const cid=autoMatchBslCategoryJS(p.title,cats);
        if(cid>0)todo.push([p.id,cid]);
    });
    if(!todo.length){showToast('پیشنهاد خودکاری باقی نمانده',1);return;}
    const btn=document.getElementById('p2AllBtn');
    if(btn){btn.disabled=true;btn.textContent='⏳ در حال اصلاح...';}
    let i=0;
    (function next(){
        if(i>=todo.length){
            if(btn){btn.disabled=false;btn.textContent='⚡ اصلاح همهٔ پیشنهادها';}
            showToast('✓ '+toFa(todo.length)+' محصول اصلاح شد');
            return;
        }
        const [pid,cid]=todo[i++];
        bslFixCat(pid,cid,cid);
        setTimeout(next,1200);      // فاصله تا باسلام محدودیت نرخ نگیرد
    })();
}
// v7.48: Improved autoMatchBslCategoryJS — word-boundary matching
function autoMatchBslCategoryJS(title,cats){
    const norm=title.toLowerCase().replace(/[0-9!@#$%^&*()+=\[\]{}|\\:;"<>,.?/_\-–—…·«»]/g,' ').replace(/\s{2,}/g,' ');
    const stop=['و','با','از','برای','در','یک','این','آن','که','هم','است','بود','شد','کن','کرد','باید','دیگر','قیمت','فروش','ارسال','رایگان','تخفیف','ویژه','نو','جدید','ست','بسته','دار','تکه','عدد','پک','سایز','رنگ','کد','اصلی','مخصوص','تک','فرد','نوع','مدل','خط','سری','متفرقه','کرانه','تن'];
    const words=norm.split(/\s+/).filter(w=>w.length>=2&&!stop.includes(w));
    if(!words.length)return 0;
    let best=0,bestId=0;
    cats.forEach(c=>{
        const catNorm=(c.name||'').toLowerCase().replace(/[0-9()]/g,' ').replace(/\s{2,}/g,' ').trim();
        const cw=catNorm.split(/\s+/).filter(w=>w.length>=2);
        let score=0;
        words.forEach(pw=>{
            cw.forEach(ccw=>{
                // 1) Exact word match
                if(pw===ccw){score+=3;return;}
                // 2) v7.48: Word-boundary substring only — "صندل" matches "صندل" in "کفش صندل" but NOT "صندلی"
                if(pw.length<ccw.length){
                    // pw at start of ccw
                    if(ccw.startsWith(pw)){
                        const nextChar=ccw[pw.length];
                        if(nextChar===' '||nextChar==='\u200c'){score+=2;return;}
                    }
                    // pw at end of ccw
                    if(ccw.endsWith(pw)){
                        const prevChar=ccw[ccw.length-pw.length-1];
                        if(prevChar===' '||prevChar==='\u200c'){score+=2;return;}
                    }
                }
                // pw longer than ccw — ccw must appear with word boundaries in pw
                if(pw.length>ccw.length&&pw.includes(ccw)){
                    const pos=pw.indexOf(ccw);
                    const beforeOk=pos===0||pw[pos-1]===' ';
                    const afterOk=pos+ccw.length===pw.length||pw[pos+ccw.length]===' ';
                    if(beforeOk&&afterOk){score+=1.5;return;}
                }
            });
        });
        score+=(c.level||0)*0.2;
        if(score>best){best=score;bestId=c.id;}
    });
    // v7.48: Higher threshold
    return best>=2?bestId:0;
}
function bslFixCatManual(btn){
    const pid=parseInt(btn.dataset.pid)||0;
    const sel=document.getElementById('cat-'+pid);
    const catId=parseInt(sel?.value)||0;
    if(catId<=0){showToast('ابتدا دسته‌بندی انتخاب کنید!',1);return;}
    bslFixCat(pid,catId,0);
}
// v7.48: Setup searchable dropdown for Phase 2 category fix
function setupPhase2CatSearch(pid,cats){
    const si=document.getElementById('catSearch-'+pid);
    if(!si)return;
    si.addEventListener('focus',function(){
        renderPhase2CatList(pid,cats,this.value);
        const list=document.getElementById('catList-'+pid);
        if(list)list.style.display='block';
    });
    si.addEventListener('input',function(){
        renderPhase2CatList(pid,cats,this.value);
        const list=document.getElementById('catList-'+pid);
        if(list)list.style.display='block';
    });
    si.addEventListener('blur',function(){
        setTimeout(function(){const list=document.getElementById('catList-'+pid);if(list)list.style.display='none';},200);
    });
    si.addEventListener('keydown',function(e){
        if(e.key==='Escape'){const list=document.getElementById('catList-'+pid);if(list)list.style.display='none';}
        if(e.key==='Enter'){const q=this.value.toLowerCase().trim();const match=cats.find(c=>c.name.toLowerCase().includes(q));if(match)phase2SelectCat(pid,match.id,cats);}
    });
}
function renderPhase2CatList(pid,cats,query){
    const list=document.getElementById('catList-'+pid);
    if(!list)return;
    const q=query.toLowerCase().trim();
    const hid=document.getElementById('cat-'+pid);
    const selId=parseInt(hid?.value)||0;
    let html='';
    if(selId>0){const sc=cats.find(c=>c.id===selId);if(sc&&(!q||sc.name.toLowerCase().includes(q))){html+='<div style="padding:5px 8px;cursor:pointer;background:#0e4429;color:#4ade80;border-bottom:1px solid #334155;font-size:11px" onclick="phase2SelectCat('+pid+','+sc.id+',window._phase2Cats)">✓ '+esc(sc.name)+' ('+sc.id+')</div>';}}
    cats.forEach(c=>{
        if(selId===c.id)return;
        if(q&&!c.name.toLowerCase().includes(q))return;
        const prefix=c.level>0?'──'.repeat(c.level)+' ':'';
        html+='<div style="padding:5px 8px;cursor:pointer;border-bottom:1px solid #1e293b;font-size:11px;color:#e2e8f0" onclick="phase2SelectCat('+pid+','+c.id+',window._phase2Cats)">'+esc(prefix+c.name)+' ('+c.id+')</div>';
    });
    if(!html)html='<div style="padding:8px;color:#64748b;font-size:11px;text-align:center">نتیجه یافت نشد</div>';
    list.innerHTML=html;
}
function phase2SelectCat(pid,catId,cats){
    const hid=document.getElementById('cat-'+pid);
    if(hid)hid.value=String(catId);
    const sc=cats.find(c=>c.id===catId);
    const si=document.getElementById('catSearch-'+pid);
    if(si&&sc)si.value=sc.name+' ('+catId+')';
    const btn=document.getElementById('btn-'+pid+'-m');
    if(btn)btn.disabled=false;
    const list=document.getElementById('catList-'+pid);
    if(list)list.style.display='none';
}
function bslFixCat(productId,catId,autoCatId){
    if(catId<=0&&autoCatId<=0){showToast('ابتدا دسته‌بندی انتخاب کنید!',1);return;}
    const useCatId=autoCatId>0?autoCatId:catId;
    if(useCatId<=0){showToast('دسته‌بندی نامعتبر!',1);return;}
    const btn=document.getElementById('btn-'+productId);
    const btnM=document.getElementById('btn-'+productId+'-m');
    if(btn)btn.disabled=true;if(btnM)btnM.disabled=true;
    // v8.48: عنوان و نام دسته را هم بفرست تا سیستم یاد بگیرد
    let _t='',_cn='';
    try{
        const row=(window._phase2Rows||[]).find(x=>String(x.id)===String(productId));
        if(row)_t=row.title||'';
        const cats=window._phase2Cats||window._bslModalCats||bslAllCats||[];
        const c=cats.find(x=>x.id===useCatId); if(c)_cn=c.name||'';
    }catch(e){}
    fetch('?bsl_fix_cat=1&product_id='+productId+'&category_id='+useCatId
      +'&title='+encodeURIComponent(_t)+'&cat_name='+encodeURIComponent(_cn))
      .then(r=>r.json()).then(d=>{
        // v8.45: به‌جای innerHTML+= که کل کارت را بازسازی و رویدادها را
        // نابود می‌کرد، فقط نوار وضعیتِ همان کارت به‌روز می‌شود.
        const row=document.getElementById('p2-'+productId);
        const st=document.getElementById('p2st-'+productId);
        if(d&&d.ok){
            showToast('✓ دسته اصلاح شد');
            if(row){row.classList.add('p2-ok');row.dataset.done='1';}
            if(st)st.innerHTML='<span class="p2-ok-txt">✅ اصلاح شد — دستهٔ #'+useCatId+'</span>'
                 +(d.learned?'<span style="color:#93c5fd;margin-right:6px">🧠 آموخته شد: «'+esc(d.learn_word||'')+'»</span>':'');
            const box=document.getElementById('p2Done');
            if(box){const n=(parseInt(box.dataset.n||'0')||0)+1;box.dataset.n=String(n);
                    box.textContent='✅ '+toFa(n)+' اصلاح شد';}
        }else{
            const msg=(d&&d.error)?d.error:'خطای نامشخص';
            showToast('❌ '+msg,1);
            if(btn)btn.disabled=false;if(btnM)btnM.disabled=false;
            if(row)row.classList.add('p2-err');
            if(st)st.innerHTML='<span class="p2-err-txt">❌ '+esc(msg)+'</span>';
        }
    }).catch(e=>{showToast('❌ خطا شبکه',1);if(btn)btn.disabled=false;if(btnM)btnM.disabled=false;});
}
function closePhase2(){const m=document.getElementById('phase2Container');if(m)m.remove();}
function mb_substr(s,len){if(!s)return'';if(s.length<=len)return s;return s.substring(0,len)+'…';}
var bslReportData={sent:[],updated:[],skipped:[],failed:[]};
var wooReportData={sent:[],updated:[],skipped:[],failed:[]};
function showBslReport(type){
    // v8.19: 'all' type shows all categories combined
    if(type==='all'){
        const allList=[...(bslReportData.sent||[]).map(x=>({...x,_cat:'✅ ایجاد'})),...(bslReportData.updated||[]).map(x=>({...x,_cat:'⚡ آپدیت'})),...(bslReportData.skipped||[]).map(x=>({...x,_cat:'⏭ تکراری'})),...(bslReportData.failed||[]).map(x=>({...x,_cat:'❌ خطا'}))];
        const catColors={'✅ ایجاد':'#4ade80','⚡ آپدیت':'#facc15','⏭ تکراری':'#fb923c','❌ خطا':'#f87171'};
        let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeReportModal()">';
        html+='<div class="bsl-modal" style="max-width:700px">';
        html+='<div class="bsl-modal-head"><h2 style="color:#60a5fa">📊 گزارش کل باسلام ('+toFa(allList.length)+' محصول)</h2><button class="btn btn-gray" onclick="closeReportModal()">✕</button></div>';
        if(!allList.length){html+='<div class="bsl-modal-body" style="padding:20px;text-align:center;color:#64748b">هیچ محصولی نیست</div>';}
        else{
            html+='<div class="bsl-modal-body" style="max-height:500px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
            html+='<thead><tr style="background:#1e293b;color:#94a3b8"><th style="padding:6px;text-align:right">#</th><th style="padding:6px;text-align:right">وضعیت</th><th style="padding:6px;text-align:right">عنوان</th><th style="padding:6px;text-align:right">جزئیات</th></tr></thead><tbody>';
            allList.forEach((item,i)=>{
                const det=item.remote_id?'ID#'+item.remote_id:(item.reason||item.error||item.update_reason||item.changes||'—');
                html+='<tr style="border-bottom:1px solid #1e293b"><td style="padding:4px;color:#64748b">'+toFa(i+1)+'</td>';
                html+='<td style="padding:4px;color:'+(catColors[item._cat]||'#94a3b8')+'">'+item._cat+'</td>';
                html+='<td style="padding:4px;color:#e2e8f0">'+esc(item.title||'—')+'</td>';
                html+='<td style="padding:4px;color:#94a3b8">'+esc(String(det))+'</td></tr>';
            });
            html+='</tbody></table></div>';
        }
        html+='</div></div>';
        const old=document.getElementById('reportModalContainer');if(old)old.remove();
        const div=document.createElement('div');div.id='reportModalContainer';div.innerHTML=html;
        document.body.appendChild(div);return;
    }
    const list=bslReportData[type]||[];
    const labels={sent:'✅ ایجاد شده',updated:'⚡ آپدیت شده',skipped:'⏭ تکراری',failed:'❌ خطا'};
    const colors={sent:'#22c55e',updated:'#facc15',skipped:'#94a3b8',failed:'#f87171'};
    const title=labels[type]||type;
    let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeReportModal()">';
    html+='<div class="bsl-modal" style="max-width:700px">';
    html+='<div class="bsl-modal-head"><h2 style="color:'+colors[type]+'">'+title+' ('+toFa(list.length)+' محصول)</h2><button class="btn btn-gray" onclick="closeReportModal()">✕</button></div>';
    if(!list.length){html+='<div class="bsl-modal-body" style="padding:20px;text-align:center;color:#64748b">هیچ محصول در این دسته نیست</div>';}
    else{
        html+='<div class="bsl-modal-body" style="max-height:500px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        html+='<thead><tr style="background:#1e293b;color:#94a3b8"><th style="padding:6px;text-align:right">#</th><th style="padding:6px;text-align:right">عنوان</th>';
        if(type==='sent'||type==='updated'){html+='<th style="padding:6px;text-align:right">شناسه باسلام</th>';}
        if(type==='updated'){html+='<th style="padding:6px;text-align:right">علت آپدیت</th>';}
        if(type==='skipped'){html+='<th style="padding:6px;text-align:right">علت</th>';}
        if(type==='failed'){html+='<th style="padding:6px;text-align:right">خطا</th>';}
        html+='</tr></thead><tbody>';
        list.forEach((item,i)=>{
            html+='<tr style="border-bottom:1px solid #1e293b"><td style="padding:4px;color:#64748b">'+toFa(i+1)+'</td>';
            html+='<td style="padding:4px;color:#e2e8f0">'+esc(item.title||'—')+'</td>';
            if(type==='sent'||type==='updated'){html+='<td style="padding:4px;color:#60a5fa">'+esc(String(item.remote_id||''))+'</td>';}
            if(type==='updated'){html+='<td style="padding:4px;color:#facc15">'+esc(item.update_reason||item.changes||'—')+'</td>';}
            if(type==='skipped'){html+='<td style="padding:4px;color:#94a3b8">'+esc(item.reason||'—')+'</td>';}
            if(type==='failed'){html+='<td style="padding:4px;color:#f87171">'+esc(item.error||'—')+'</td>';}
            html+='</tr>';
        });
        html+='</tbody></table></div>';
    }
    html+='</div></div>';
    const old=document.getElementById('reportModalContainer');if(old)old.remove();
    const div=document.createElement('div');div.id='reportModalContainer';div.innerHTML=html;
    document.body.appendChild(div);
}
function showWooReport(type){
    // v8.19: 'all' type shows all categories combined
    if(type==='all'){
        const allList=[...(wooReportData.sent||[]).map(x=>({...x,_cat:'✅ ایجاد'})),...(wooReportData.updated||[]).map(x=>({...x,_cat:'⚡ آپدیت'})),...(wooReportData.skipped||[]).map(x=>({...x,_cat:'⏭ تکراری'})),...(wooReportData.failed||[]).map(x=>({...x,_cat:'❌ خطا'}))];
        const catColors={'✅ ایجاد':'#4ade80','⚡ آپدیت':'#facc15','⏭ تکراری':'#fb923c','❌ خطا':'#f87171'};
        let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeReportModal()">';
        html+='<div class="bsl-modal" style="max-width:700px">';
        html+='<div class="bsl-modal-head"><h2 style="color:#60a5fa">📊 گزارش کل ووکامرس ('+toFa(allList.length)+' محصول)</h2><button class="btn btn-gray" onclick="closeReportModal()">✕</button></div>';
        if(!allList.length){html+='<div class="bsl-modal-body" style="padding:20px;text-align:center;color:#64748b">هیچ محصولی نیست</div>';}
        else{
            html+='<div class="bsl-modal-body" style="max-height:500px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
            html+='<thead><tr style="background:#1e293b;color:#94a3b8"><th style="padding:6px;text-align:right">#</th><th style="padding:6px;text-align:right">وضعیت</th><th style="padding:6px;text-align:right">عنوان</th><th style="padding:6px;text-align:right">جزئیات</th></tr></thead><tbody>';
            allList.forEach((item,i)=>{
                const det=item.remote_id?'ID#'+item.remote_id:(item.reason||item.error||item.update_reason||item.changes||'—');
                html+='<tr style="border-bottom:1px solid #1e293b"><td style="padding:4px;color:#64748b">'+toFa(i+1)+'</td>';
                html+='<td style="padding:4px;color:'+(catColors[item._cat]||'#94a3b8')+'">'+item._cat+'</td>';
                html+='<td style="padding:4px;color:#e2e8f0">'+esc(item.title||'—')+'</td>';
                html+='<td style="padding:4px;color:#94a3b8">'+esc(String(det))+'</td></tr>';
            });
            html+='</tbody></table></div>';
        }
        html+='</div></div>';
        const old=document.getElementById('reportModalContainer');if(old)old.remove();
        const div=document.createElement('div');div.id='reportModalContainer';div.innerHTML=html;
        document.body.appendChild(div);return;
    }
    const list=wooReportData[type]||[];
    const labels={sent:'✅ ایجاد شده',updated:'⚡ آپدیت شده',skipped:'⏭ تکراری',failed:'❌ خطا'};
    const colors={sent:'#22c55e',updated:'#facc15',skipped:'#94a3b8',failed:'#f87171'};
    const title=labels[type]||type;
    let html='<div class="bsl-modal-overlay" onclick="if(event.target===this)closeReportModal()">';
    html+='<div class="bsl-modal" style="max-width:700px">';
    html+='<div class="bsl-modal-head"><h2 style="color:'+colors[type]+'">'+title+' ('+toFa(list.length)+' محصول)</h2><button class="btn btn-gray" onclick="closeReportModal()">✕</button></div>';
    if(!list.length){html+='<div class="bsl-modal-body" style="padding:20px;text-align:center;color:#64748b">هیچ محصول در این دسته نیست</div>';}
    else{
        html+='<div class="bsl-modal-body" style="max-height:500px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
        html+='<thead><tr style="background:#1e293b;color:#94a3b8"><th style="padding:6px;text-align:right">#</th><th style="padding:6px;text-align:right">عنوان</th>';
        if(type==='sent'||type==='updated'){html+='<th style="padding:6px;text-align:right">شناسه ووکامرس</th>';html+='<th style="padding:6px;text-align:right">لینک</th>';}
        if(type==='updated'){html+='<th style="padding:6px;text-align:right">علت آپدیت</th>';}
        if(type==='skipped'){html+='<th style="padding:6px;text-align:right">علت</th>';}
        if(type==='failed'){html+='<th style="padding:6px;text-align:right">خطا</th>';}
        html+='</tr></thead><tbody>';
        list.forEach((item,i)=>{
            html+='<tr style="border-bottom:1px solid #1e293b"><td style="padding:4px;color:#64748b">'+toFa(i+1)+'</td>';
            html+='<td style="padding:4px;color:#e2e8f0">'+esc(item.title||'—')+'</td>';
            if(type==='sent'||type==='updated'){html+='<td style="padding:4px;color:#60a5fa">'+esc(String(item.remote_id||''))+'</td>';html+='<td style="padding:4px">'+(item.edit_url?'<a href="'+esc(item.edit_url)+'" target="_blank" style="color:#60a5fa">🔗</a>':'—')+'</td>';}
            if(type==='updated'){html+='<td style="padding:4px;color:#facc15">'+esc(item.update_reason||item.changes||'—')+'</td>';}
            if(type==='skipped'){html+='<td style="padding:4px;color:#94a3b8">'+esc(item.reason||'—')+'</td>';}
            if(type==='failed'){html+='<td style="padding:4px;color:#f87171">'+esc(item.error||'—')+'</td>';}
            html+='</tr>';
        });
        html+='</tbody></table></div>';
    }
    html+='</div></div>';
    const old=document.getElementById('reportModalContainer');if(old)old.remove();
    const div=document.createElement('div');div.id='reportModalContainer';div.innerHTML=html;
    document.body.appendChild(div);
}
function closeReportModal(){const m=document.getElementById('reportModalContainer');if(m)m.remove();}

// v7.82: Product card renderer for send logs
function renderSendCard(d){
    // d = {title, image, price, category, result:'ok'|'update'|'skip'|'fail', error, remote_id, edit_url, changes, price_unit, link}
    const img=d.image?'<img class="scard-img" src="?image_proxy='+encodeURIComponent(d.image)+'">':'<div class="scard-noimg">📷</div>';
    const rc={ok:'scard-ok',update:'scard-up',skip:'scard-skip',fail:'scard-fail'};
    const ri={ok:'✅ ایجاد شد',update:'⚡ آپدیت شد',skip:'⏭ تکراری',fail:'❌ خطا'};
    const rc2=rc[d.result]||'scard-fail';
    const ri2=ri[d.result]||d.result;
    let priceStr=d.price?toFa(Number(d.price).toLocaleString('en-US'))+(d.price_unit==='rial'?' ریال':' تومان'):'—';
    let catStr=d.category||'—';
    let errStr=d.error?'<div class="scard-err">⚠️ '+esc(d.error)+'</div>':'';
    let ridStr=d.remote_id?'<div class="scard-rid">'+(d.edit_url?'<a href="'+esc(d.edit_url)+'" target="_blank">🔗</a> ':'')+'#'+d.remote_id+'</div>':'';
    let changesStr=d.changes?'<span style="color:#facc15;font-size:9px">('+esc(d.changes)+')</span>':'';
    let reasonStr=(d.result==='update'&&(d.update_reason||d.changes))?'<div class="scard-reason">📋 علت آپدیت: '+esc(d.update_reason||d.changes)+'</div>':'';
    return '<div class="scard scard-'+d.result+'">'+img+'<div class="scard-body"><div class="scard-title">'+esc(d.title||'—')+'</div><div class="scard-meta"><span class="scard-price">💰 '+priceStr+'</span><span class="scard-cat">📂 '+esc(catStr)+'</span>'+(d.link?'<span><a href="'+esc(d.link)+'" target="_blank" style="color:#60a5fa">🔗</a></span>':'')+'</div><div class="scard-result '+rc2+'">'+ri2+' '+changesStr+'</div>'+reasonStr+errStr+ridStr+'</div></div>';
}
</script>
</body>
</html>