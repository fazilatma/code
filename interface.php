<?php
declare(strict_types=1);

/**
 * Secure transparent HTTP proxy for PHP 8+ / cURL.
 *
 * Usage:
 *   GET  /proxy.php                  Open the Persian access-test dashboard
 *   GET  /proxy.php?url=https%3A%2F%2Fapi.example.com%2Fv1%2Fitems%3Fx%3D1
 *   POST /proxy.php?url=https%3A%2F%2Fmy-worker.workers.dev%2Fendpoint
 *   POST /proxy.php?action=check     Dashboard connectivity-check API
 *
 * Required environment variable:
 *   PROXY_ALLOWED_HOSTS=api.example.com,my-worker.workers.dev,*.trusted-api.com
 *
 * Optional environment variables:
 *   PROXY_ALLOWED_ORIGIN=https://your-site.example   (default: no CORS header)
 *   PROXY_TIMEOUT=30                                 (seconds, max 120)
 *   PROXY_CONNECT_TIMEOUT=10                         (seconds, max 30)
 *   PROXY_MAX_BODY_BYTES=10485760                    (default: 10 MiB)
 *   PROXY_USER_AGENT=ParsPack-PHP-Proxy/1.0
 *
 * Security:
 * - Only HTTPS destinations are accepted.
 * - Destination hostname must be in PROXY_ALLOWED_HOSTS.
 * - Private/reserved IP addresses and redirects are rejected.
 * - Do not use "*" as an allowed host on a public deployment.
 */

function envString(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : trim($value);
}

function envInt(string $name, int $default, int $min, int $max): int
{
    $raw = getenv($name);
    if ($raw === false || filter_var($raw, FILTER_VALIDATE_INT) === false) {
        return $default;
    }
    return max($min, min($max, (int) $raw));
}

function sendJsonError(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => false,
        'error' => $code,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        } elseif ($key === 'CONTENT_TYPE') {
            $headers['Content-Type'] = $value;
        } elseif ($key === 'CONTENT_LENGTH') {
            $headers['Content-Length'] = $value;
        }
    }
    return $headers;
}

function hostIsAllowed(string $host, array $rules): bool
{
    $host = strtolower(rtrim($host, '.'));
    foreach ($rules as $rule) {
        $rule = strtolower(rtrim(trim($rule), '.'));
        if ($rule === '') {
            continue;
        }
        if ($rule === $host) {
            return true;
        }
        if (str_starts_with($rule, '*.')) {
            $suffix = substr($rule, 1); // e.g. .example.com
            if (str_ends_with($host, $suffix) && $host !== substr($suffix, 1)) {
                return true;
            }
        }
    }
    return false;
}

function isPublicIp(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function resolvePublicIps(string $host): array
{
    // Literal IP destination.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return isPublicIp($host) ? [$host] : [];
    }

    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records)) {
        return [];
    }

    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
        if (is_string($ip) && isPublicIp($ip)) {
            $ips[] = $ip;
        } elseif (is_string($ip)) {
            // If any DNS answer is private/reserved, reject the host entirely.
            return [];
        }
    }
    return array_values(array_unique($ips));
}

function normalizeHeaderName(string $name): string
{
    return strtolower(trim($name));
}

function checkDestination(string $url, array $allowedHosts): array
{
    $startedAt = microtime(true);
    $result = [
        'url' => $url,
        'reachable' => false,
        'allowed' => false,
        'status' => null,
        'latency_ms' => null,
        'ip' => null,
        'message' => '',
    ];

    if ($url === '' || strlen($url) > 8192) {
        $result['message'] = 'آدرس خالی یا بیش از حد طولانی است.';
        return $result;
    }

    $parts = parse_url($url);
    if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        $result['message'] = 'فقط آدرس معتبر HTTPS پذیرفته می‌شود.';
        return $result;
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        $result['message'] = 'این ساختار URL مجاز نیست.';
        return $result;
    }

    $host = strtolower(rtrim((string) $parts['host'], '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    if ($port !== 443) {
        $result['message'] = 'فقط پورت ۴۴۳ مجاز است.';
        return $result;
    }
    if (!hostIsAllowed($host, $allowedHosts)) {
        $result['message'] = 'دامنه در فهرست PROXY_ALLOWED_HOSTS نیست.';
        return $result;
    }
    $result['allowed'] = true;

    $ips = resolvePublicIps($host);
    if ($ips === []) {
        $result['message'] = 'دامنه به IP عمومی معتبر resolve نشد.';
        return $result;
    }

    $ip = $ips[array_rand($ips)];
    $result['ip'] = $ip;
    $resolveAddress = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
    $ch = curl_init($url);
    if ($ch === false) {
        $result['message'] = 'راه‌اندازی cURL ناموفق بود.';
        return $result;
    }

    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_CONNECTTIMEOUT => envInt('PROXY_CONNECT_TIMEOUT', 10, 1, 30),
        CURLOPT_TIMEOUT => envInt('PROXY_TIMEOUT', 30, 1, 120),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => envString('PROXY_USER_AGENT', 'ParsPack-PHP-Proxy/1.0'),
        CURLOPT_RESOLVE => [$host . ':443:' . $resolveAddress],
    ]);

    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $result['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    $result['status'] = $status > 0 ? $status : null;
    $result['reachable'] = $ok !== false && $status > 0;
    $result['message'] = $result['reachable']
        ? ($status >= 200 && $status < 400 ? 'دسترسی برقرار است.' : 'سرور پاسخ داد؛ کد HTTP را بررسی کنید.')
        : ('ارتباط برقرار نشد' . ($error !== '' ? ': ' . $error : '.'));
    return $result;
}

function renderDashboard(array $allowedHosts): void
{
    $rules = $allowedHosts === [] ? ['هنوز تنظیم نشده'] : array_values($allowedHosts);
    $rulesJson = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $html = <<<'HTML'
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title>درگاه ارتباط API</title>
<style>
:root{--bg:#07111f;--panel:rgba(14,27,46,.78);--line:rgba(148,163,184,.16);--text:#ecf4ff;--muted:#94a8c3;--blue:#42a5ff;--cyan:#31d8c4;--green:#35d28b;--red:#ff667c;--amber:#f8bc4b;--shadow:0 24px 80px rgba(0,0,0,.38)}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 12% 4%,rgba(22,112,255,.18),transparent 30%),radial-gradient(circle at 88% 15%,rgba(35,211,189,.12),transparent 28%),var(--bg);color:var(--text);font-family:Tahoma,"Segoe UI",sans-serif;line-height:1.7}.orb{position:fixed;border-radius:50%;filter:blur(70px);opacity:.2;pointer-events:none}.o1{width:280px;height:280px;background:#166cff;left:-90px;top:28%}.o2{width:250px;height:250px;background:#0fd0ae;right:-110px;bottom:5%}.wrap{width:min(1120px,calc(100% - 32px));margin:auto;padding:42px 0 70px}.top{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:28px}.brand{display:flex;gap:14px;align-items:center}.logo{width:54px;height:54px;border-radius:17px;display:grid;place-items:center;background:linear-gradient(135deg,var(--blue),var(--cyan));box-shadow:0 12px 35px rgba(49,216,196,.2)}.logo svg{width:29px}.brand h1{font-size:clamp(21px,4vw,30px);margin:0}.brand p{margin:2px 0 0;color:var(--muted);font-size:13px}.badge{padding:8px 13px;border:1px solid rgba(53,210,139,.25);background:rgba(53,210,139,.08);color:#8df0bb;border-radius:999px;font-size:12px;white-space:nowrap}.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-left:7px;box-shadow:0 0 12px var(--green)}.grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(280px,.7fr);gap:18px}.card{background:var(--panel);border:1px solid var(--line);border-radius:24px;box-shadow:var(--shadow);backdrop-filter:blur(18px)}.main{padding:25px}.side{padding:22px;height:max-content}.labelrow{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.labelrow label{font-weight:700}.hint{font-size:12px;color:var(--muted)}textarea{width:100%;min-height:155px;resize:vertical;border:1px solid var(--line);outline:none;border-radius:17px;background:rgba(3,11,23,.62);color:var(--text);padding:17px;font:13px/1.9 Consolas,monospace;direction:ltr;text-align:left;transition:.2s}textarea:focus{border-color:rgba(66,165,255,.65);box-shadow:0 0 0 4px rgba(66,165,255,.09)}.actions{display:flex;gap:10px;margin-top:13px}.btn{border:0;border-radius:14px;padding:11px 19px;color:white;font:700 13px Tahoma;cursor:pointer;transition:.2s}.btn:hover{transform:translateY(-1px)}.btn:disabled{opacity:.55;cursor:wait;transform:none}.primary{background:linear-gradient(120deg,#1879ee,#24b9cf);box-shadow:0 9px 25px rgba(24,121,238,.2)}.ghost{background:rgba(148,163,184,.09);border:1px solid var(--line);color:#c9d7e8}.rules{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.chip{direction:ltr;border:1px solid rgba(66,165,255,.18);background:rgba(66,165,255,.07);color:#b9dafa;border-radius:9px;padding:5px 8px;font:11px Consolas,monospace;overflow-wrap:anywhere}.side h2{font-size:15px;margin:0 0 4px}.side p{font-size:12px;color:var(--muted);margin:0}.divider{height:1px;background:var(--line);margin:19px 0}.mini{display:grid;grid-template-columns:1fr 1fr;gap:9px}.stat{background:rgba(3,11,23,.45);border:1px solid var(--line);border-radius:14px;padding:12px}.stat b{display:block;font-size:19px}.stat span{font-size:11px;color:var(--muted)}.results{margin-top:18px;display:grid;gap:10px}.empty{text-align:center;border:1px dashed rgba(148,163,184,.22);border-radius:17px;padding:27px;color:var(--muted);font-size:13px}.result{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:13px;padding:14px;border-radius:17px;background:rgba(3,11,23,.46);border:1px solid var(--line);animation:in .35s ease both}.icon{width:39px;height:39px;border-radius:12px;display:grid;place-items:center;font-weight:bold}.ok .icon{background:rgba(53,210,139,.1);color:var(--green)}.bad .icon{background:rgba(255,102,124,.1);color:var(--red)}.warn .icon{background:rgba(248,188,75,.1);color:var(--amber)}.url{font:12px Consolas,monospace;direction:ltr;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.msg{font-size:11px;color:var(--muted);margin-top:3px}.meta{text-align:left;direction:ltr}.code{display:inline-block;padding:3px 7px;border-radius:7px;background:rgba(148,163,184,.09);font:11px Consolas}.latency{display:block;color:var(--muted);font-size:10px;margin-top:4px}.docs{margin-top:18px;padding:18px 20px}.docs h2{font-size:14px;margin:0 0 7px}.docs code{display:block;direction:ltr;text-align:left;overflow:auto;padding:12px;background:rgba(3,11,23,.5);border-radius:12px;color:#9ed8ff;font:11px/1.7 Consolas}.toast{position:fixed;left:20px;bottom:20px;padding:11px 15px;border-radius:12px;background:#17263a;border:1px solid var(--line);box-shadow:var(--shadow);font-size:12px;transform:translateY(90px);opacity:0;transition:.25s}.toast.show{transform:none;opacity:1}@keyframes in{from{opacity:0;transform:translateY(7px)}}@media(max-width:780px){.wrap{padding-top:24px}.top{align-items:flex-start}.badge{display:none}.grid{grid-template-columns:1fr}.main,.side{padding:18px}.result{grid-template-columns:39px minmax(0,1fr)}.meta{grid-column:2;text-align:right}.docs{padding:16px}}
</style>
</head>
<body><div class="orb o1"></div><div class="orb o2"></div><main class="wrap">
<header class="top"><div class="brand"><div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8"><path d="M8 12h8M13 7l5 5-5 5"/><path d="M6 19a9 9 0 1 1 0-14"/></svg></div><div><h1>درگاه ارتباط API</h1><p>بررسی دسترسی خروجی سرور پارس‌پک به سرویس‌های خارجی</p></div></div><div class="badge"><i class="dot"></i> سرویس فعال است</div></header>
<section class="grid"><div class="card main"><div class="labelrow"><label for="urls">آدرس‌های مورد نظر</label><span class="hint">حداکثر ۱۰ آدرس، هر کدام در یک خط</span></div><textarea id="urls" spellcheck="false" placeholder="https://api.example.com/health&#10;https://your-worker.workers.dev/"></textarea><div class="actions"><button class="btn primary" id="check">بررسی دسترسی</button><button class="btn ghost" id="clear">پاک‌کردن</button></div><div class="results" id="results"><div class="empty">نتیجه بررسی آدرس‌ها در این قسمت نمایش داده می‌شود.</div></div></div>
<aside class="card side"><h2>دامنه‌های مجاز</h2><p>تنظیم‌شده در PROXY_ALLOWED_HOSTS</p><div class="rules" id="rules"></div><div class="divider"></div><div class="mini"><div class="stat"><b id="success">۰</b><span>قابل دسترسی</span></div><div class="stat"><b id="failed">۰</b><span>ناموفق / غیرمجاز</span></div></div><div class="divider"></div><p>این آزمایش از داخل سرور انجام می‌شود؛ بنابراین نتیجه، دسترسی واقعی PaaS به مقصد را نشان می‌دهد. پاسخ‌های HTTP مانند 401 یا 403 نیز یعنی ارتباط شبکه برقرار شده است.</p></aside></section>
<section class="card docs"><h2>نمونه استفاده از پروکسی</h2><code>GET /proxy.php?url=https%3A%2F%2Fapi.example.com%2Fv1%2Fstatus</code></section></main><div class="toast" id="toast"></div>
<script>
const rules=__RULES__;const $=s=>document.querySelector(s);const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
$('#rules').innerHTML=rules.map(x=>`<span class="chip">${esc(x)}</span>`).join('');
function toast(t){const e=$('#toast');e.textContent=t;e.classList.add('show');setTimeout(()=>e.classList.remove('show'),2400)}
$('#clear').onclick=()=>{$('#urls').value='';$('#results').innerHTML='<div class="empty">نتیجه بررسی آدرس‌ها در این قسمت نمایش داده می‌شود.</div>';$('#success').textContent='۰';$('#failed').textContent='۰'};
$('#check').onclick=async()=>{const urls=$('#urls').value.split(/\n/).map(x=>x.trim()).filter(Boolean);if(!urls.length){toast('حداقل یک آدرس وارد کنید');return}if(urls.length>10){toast('حداکثر ۱۰ آدرس قابل بررسی است');return}const b=$('#check');b.disabled=true;b.textContent='در حال بررسی…';$('#results').innerHTML='<div class="empty">در حال برقراری ارتباط از داخل سرور…</div>';try{const r=await fetch('?action=check',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({urls})});const d=await r.json();if(!r.ok)throw new Error(d.message||'خطای سرور');let good=0;$('#results').innerHTML=d.results.map(x=>{if(x.reachable)good++;const cls=x.reachable?(x.status>=200&&x.status<400?'ok':'warn'):'bad';const mark=x.reachable?'✓':'×';return `<article class="result ${cls}"><div class="icon">${mark}</div><div><div class="url" title="${esc(x.url)}">${esc(x.url)}</div><div class="msg">${esc(x.message)}${x.ip?' · '+esc(x.ip):''}</div></div><div class="meta"><span class="code">${x.status?'HTTP '+x.status:(x.allowed?'NO RESPONSE':'BLOCKED')}</span><span class="latency">${x.latency_ms!==null?x.latency_ms+' ms':'—'}</span></div></article>`}).join('');$('#success').textContent=good.toLocaleString('fa-IR');$('#failed').textContent=(d.results.length-good).toLocaleString('fa-IR')}catch(e){ $('#results').innerHTML=`<div class="empty">${esc(e.message)}</div>`;toast('بررسی انجام نشد')}finally{b.disabled=false;b.textContent='بررسی دسترسی'}};
</script></body></html>
HTML;
    echo str_replace('__RULES__', $rulesJson ?: '[]', $html);
}

// ---------- CORS ----------
$allowedOrigin = envString('PROXY_ALLOWED_ORIGIN');
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($allowedOrigin !== '' && ($allowedOrigin === '*' || hash_equals($allowedOrigin, $requestOrigin))) {
    header('Access-Control-Allow-Origin: ' . ($allowedOrigin === '*' ? '*' : $requestOrigin));
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');
    header('Access-Control-Expose-Headers: Content-Type, Content-Length, ETag, Last-Modified, Retry-After, X-Request-Id');
    header('Access-Control-Max-Age: 86400');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
    header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
    sendJsonError(405, 'method_not_allowed', 'این متد پشتیبانی نمی‌شود.');
}

if (!extension_loaded('curl')) {
    sendJsonError(500, 'curl_missing', 'افزونه cURL روی PHP فعال نیست.');
}

$allowedHosts = array_values(array_filter(array_map('trim', explode(',', envString('PROXY_ALLOWED_HOSTS')))));
$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';

// Dashboard: opening proxy.php without parameters shows the visual access tester.
if ($method === 'GET' && $action === '' && !isset($_GET['url'])) {
    renderDashboard($allowedHosts);
    exit;
}

// Server-side batch connectivity test used by the dashboard.
if ($action === 'check') {
    if ($method !== 'POST') {
        sendJsonError(405, 'method_not_allowed', 'برای بررسی دسترسی از POST استفاده کنید.');
    }
    if ($allowedHosts === []) {
        sendJsonError(503, 'proxy_not_configured', 'متغیر PROXY_ALLOWED_HOSTS تنظیم نشده است.');
    }
    $rawCheckBody = file_get_contents('php://input', false, null, 0, 65537);
    if ($rawCheckBody === false || strlen($rawCheckBody) > 65536) {
        sendJsonError(413, 'check_request_too_large', 'حجم درخواست بررسی بیش از حد مجاز است.');
    }
    $payload = json_decode($rawCheckBody, true);
    $urls = is_array($payload) && isset($payload['urls']) && is_array($payload['urls']) ? $payload['urls'] : [];
    if ($urls === [] || count($urls) > 10) {
        sendJsonError(422, 'invalid_urls', 'بین ۱ تا ۱۰ آدرس ارسال کنید.');
    }
    $results = [];
    foreach ($urls as $url) {
        if (!is_string($url)) {
            $results[] = checkDestination('', $allowedHosts);
            continue;
        }
        $results[] = checkDestination(trim($url), $allowedHosts);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Read the raw query parameter without PHP converting dots/spaces in keys.
$targetUrl = isset($_GET['url']) && is_string($_GET['url']) ? trim($_GET['url']) : '';
if ($targetUrl === '') {
    sendJsonError(400, 'missing_url', 'پارامتر url الزامی است.');
}
if (strlen($targetUrl) > 8192) {
    sendJsonError(414, 'url_too_long', 'آدرس مقصد بیش از حد طولانی است.');
}

$parts = parse_url($targetUrl);
if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
    sendJsonError(400, 'invalid_url', 'فقط URL معتبر با پروتکل HTTPS پذیرفته می‌شود.');
}
if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
    sendJsonError(400, 'invalid_url', 'نام کاربری، رمز عبور یا fragment در URL مجاز نیست.');
}

$host = strtolower(rtrim((string) $parts['host'], '.'));
$port = isset($parts['port']) ? (int) $parts['port'] : 443;
if ($port !== 443) {
    sendJsonError(403, 'port_not_allowed', 'فقط پورت 443 مجاز است.');
}

if ($allowedHosts === []) {
    sendJsonError(503, 'proxy_not_configured', 'متغیر PROXY_ALLOWED_HOSTS تنظیم نشده است.');
}
if (!hostIsAllowed($host, $allowedHosts)) {
    sendJsonError(403, 'host_not_allowed', 'دامنه مقصد در فهرست مجاز نیست.');
}

$publicIps = resolvePublicIps($host);
if ($publicIps === []) {
    sendJsonError(403, 'unsafe_destination', 'دامنه مقصد به IP عمومی معتبر resolve نشد.');
}

$maxBodyBytes = envInt('PROXY_MAX_BODY_BYTES', 10 * 1024 * 1024, 0, 50 * 1024 * 1024);
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($contentLength > $maxBodyBytes) {
    sendJsonError(413, 'body_too_large', 'حجم بدنه درخواست بیش از حد مجاز است.');
}

$body = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
if ($body === false) {
    sendJsonError(400, 'body_read_failed', 'خواندن بدنه درخواست ممکن نبود.');
}
if (strlen($body) > $maxBodyBytes) {
    sendJsonError(413, 'body_too_large', 'حجم بدنه درخواست بیش از حد مجاز است.');
}

// Hop-by-hop and proxy-specific request headers must not be forwarded.
$blockedRequestHeaders = [
    'host', 'connection', 'keep-alive', 'proxy-authenticate',
    'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade',
    'content-length', 'forwarded', 'via', 'x-forwarded-for',
    'x-forwarded-host', 'x-forwarded-proto', 'cf-connecting-ip',
    'cf-ipcountry', 'cf-ray', 'cdn-loop',
];
$outgoingHeaders = [];
foreach (requestHeaders() as $name => $value) {
    $normalized = normalizeHeaderName((string) $name);
    if ($normalized === '' || in_array($normalized, $blockedRequestHeaders, true)) {
        continue;
    }
    if (is_array($value)) {
        foreach ($value as $item) {
            $outgoingHeaders[] = $name . ': ' . $item;
        }
    } else {
        $outgoingHeaders[] = $name . ': ' . $value;
    }
}

$responseHeaders = [];
$statusCode = 502;
$ch = curl_init($targetUrl);
if ($ch === false) {
    sendJsonError(500, 'curl_init_failed', 'راه‌اندازی cURL ناموفق بود.');
}

$resolveIp = $publicIps[array_rand($publicIps)];
$resolveAddress = str_contains($resolveIp, ':') ? '[' . $resolveIp . ']' : $resolveIp;
$resolveEntry = $host . ':' . $port . ':' . $resolveAddress;

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $outgoingHeaders,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS => 0,
    CURLOPT_CONNECTTIMEOUT => envInt('PROXY_CONNECT_TIMEOUT', 10, 1, 30),
    CURLOPT_TIMEOUT => envInt('PROXY_TIMEOUT', 30, 1, 120),
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_USERAGENT => envString('PROXY_USER_AGENT', 'ParsPack-PHP-Proxy/1.0'),
    CURLOPT_ENCODING => '',
    CURLOPT_RESOLVE => [$resolveEntry], // Pin DNS result to reduce DNS-rebinding risk.
    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$statusCode): int {
        $length = strlen($line);
        $trimmed = trim($line);
        if ($trimmed === '') {
            return $length;
        }
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $trimmed, $matches)) {
            $statusCode = (int) $matches[1];
            $responseHeaders = [];
            return $length;
        }
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $responseHeaders[] = [trim(substr($line, 0, $pos)), trim(substr($line, $pos + 1))];
        }
        return $length;
    },
]);

if (!in_array($method, ['GET', 'HEAD'], true) || $body !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}
if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
}

$responseBody = curl_exec($ch);
if ($responseBody === false) {
    $error = curl_error($ch);
    curl_close($ch);
    sendJsonError(502, 'upstream_error', 'خطا در ارتباط با مقصد: ' . $error);
}
curl_close($ch);

$blockedResponseHeaders = [
    'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
    'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length',
    'access-control-allow-origin', 'access-control-allow-credentials',
    'access-control-expose-headers',
];

http_response_code($statusCode);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
foreach ($responseHeaders as [$name, $value]) {
    if (!in_array(normalizeHeaderName($name), $blockedResponseHeaders, true)) {
        header($name . ': ' . $value, false);
    }
}

if ($method !== 'HEAD') {
    echo $responseBody;
}
