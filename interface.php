<?php
declare(strict_types=1);

/*
 * SIMPLE CONFIGURATION — no PROXY_ALLOWED_HOSTS environment variable needed.
 * Add the exact hostname of your Cloudflare Worker or another API here.
 * Do not write https:// or a path; hostname only.
 */
const PROXY_TARGET_HOSTS = [
    'ai.fazilat-ma.workers.dev',
    'openrouter.ai',
    'api.together.xyz',
    'api.groq.com',
    'router.huggingface.co',
    'api.cloudflare.com',
    'generativelanguage.googleapis.com',
    'api.cerebras.ai',
    'api.mistral.ai',
    'api.cohere.com',
    // Other common AI providers:
    'api.openai.com',
    'api.anthropic.com',
    'api.x.ai',
    'api.deepseek.com',
    'api.perplexity.ai',
];

// Public HTTPS endpoints used by the dashboard's one-click connectivity test.
// This test checks network reachability only and does not need or store API keys.
const PROVIDER_TEST_URLS = [
    'Cloudflare Worker' => 'https://ai.fazilat-ma.workers.dev/',
    'OpenRouter' => 'https://openrouter.ai/api/v1/models',
    'Together AI' => 'https://api.together.xyz/v1/models',
    'Groq' => 'https://api.groq.com/openai/v1/models',
    'Hugging Face' => 'https://router.huggingface.co/v1/models',
    'Cloudflare API' => 'https://api.cloudflare.com/client/v4/',
    'Google Gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
    'Cerebras' => 'https://api.cerebras.ai/v1/models',
    'Mistral' => 'https://api.mistral.ai/v1/models',
    'Cohere' => 'https://api.cohere.com/v1/models',
];

/**
 * Secure transparent HTTP proxy for PHP 8+ / cURL.
 *
 * Usage:
 *   GET  /proxy.php                  Open the Persian access-test dashboard
 *   GET  /proxy.php?url=https%3A%2F%2Fapi.example.com%2Fv1%2Fitems%3Fx%3D1
 *   POST /proxy.php?url=https%3A%2F%2Fmy-worker.workers.dev%2Fendpoint
 *   POST /proxy.php?action=check     Dashboard connectivity-check API
 *
 * Allowed proxy destinations are configured in PROXY_TARGET_HOSTS at the top
 * of this file. The visual connectivity tester accepts any public HTTPS URL.
 *
 * Optional environment variables:
 *   PROXY_ALLOWED_ORIGIN=https://your-site.example   (default: no CORS header)
 *   PROXY_TIMEOUT=30                                 (seconds, max 120)
 *   PROXY_CONNECT_TIMEOUT=10                         (seconds, max 30)
 *   PROXY_MAX_BODY_BYTES=10485760                    (default: 10 MiB)
 *   PROXY_USER_AGENT=ParsPack-PHP-Proxy/1.0
 *
 * File manager environment variables:
 *   FILE_MANAGER_ROOT=/path/to/managed/storage
 *   FILE_MANAGER_PASSWORD=use-a-long-random-password
 *   FILE_MANAGER_MAX_UPLOAD_BYTES=20971520
 *   FILE_MANAGER_BLOCKED_EXTENSIONS=php,phtml,phar,htaccess,user.ini
 *
 * File manager URL:
 *   GET /proxy.php?panel=files
 *
 * Security:
 * - Only HTTPS destinations are accepted.
 * - Proxy destination hostname must be in PROXY_TARGET_HOSTS.
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

function checkDestination(string $url, array $allowedHosts, bool $requireAllowedHost = false): array
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
    if ($requireAllowedHost && !hostIsAllowed($host, $allowedHosts)) {
        $result['message'] = 'این دامنه برای عبور درخواست از پروکسی تعریف نشده است.';
        return $result;
    }
    // Connectivity checks may target any public HTTPS host; private/reserved IPs remain blocked.
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
    $providerUrlsJson = json_encode(PROVIDER_TEST_URLS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
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
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 12% 4%,rgba(22,112,255,.18),transparent 30%),radial-gradient(circle at 88% 15%,rgba(35,211,189,.12),transparent 28%),var(--bg);color:var(--text);font-family:Tahoma,"Segoe UI",sans-serif;line-height:1.7}.orb{position:fixed;border-radius:50%;filter:blur(70px);opacity:.2;pointer-events:none}.o1{width:280px;height:280px;background:#166cff;left:-90px;top:28%}.o2{width:250px;height:250px;background:#0fd0ae;right:-110px;bottom:5%}.wrap{width:min(1120px,calc(100% - 32px));margin:auto;padding:42px 0 70px}.top{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:28px}.brand{display:flex;gap:14px;align-items:center}.logo{width:54px;height:54px;border-radius:17px;display:grid;place-items:center;background:linear-gradient(135deg,var(--blue),var(--cyan));box-shadow:0 12px 35px rgba(49,216,196,.2)}.logo svg{width:29px}.brand h1{font-size:clamp(21px,4vw,30px);margin:0}.brand p{margin:2px 0 0;color:var(--muted);font-size:13px}.badge{padding:8px 13px;border:1px solid rgba(53,210,139,.25);background:rgba(53,210,139,.08);color:#8df0bb;border-radius:999px;font-size:12px;white-space:nowrap}.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-left:7px;box-shadow:0 0 12px var(--green)}.grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(280px,.7fr);gap:18px}.card{background:var(--panel);border:1px solid var(--line);border-radius:24px;box-shadow:var(--shadow);backdrop-filter:blur(18px)}.main{padding:25px}.side{padding:22px;height:max-content}.labelrow{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.labelrow label{font-weight:700}.hint{font-size:12px;color:var(--muted)}textarea{width:100%;min-height:155px;resize:vertical;border:1px solid var(--line);outline:none;border-radius:17px;background:rgba(3,11,23,.62);color:var(--text);padding:17px;font:13px/1.9 Consolas,monospace;direction:ltr;text-align:left;transition:.2s}textarea:focus{border-color:rgba(66,165,255,.65);box-shadow:0 0 0 4px rgba(66,165,255,.09)}.actions{display:flex;gap:10px;margin-top:13px}.btn{border:0;border-radius:14px;padding:11px 19px;color:white;font:700 13px Tahoma;cursor:pointer;transition:.2s}.btn:hover{transform:translateY(-1px)}.btn:disabled{opacity:.55;cursor:wait;transform:none}.primary{background:linear-gradient(120deg,#1879ee,#24b9cf);box-shadow:0 9px 25px rgba(24,121,238,.2)}.ghost{background:rgba(148,163,184,.09);border:1px solid var(--line);color:#c9d7e8}.rules{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.chip{direction:ltr;border:1px solid rgba(66,165,255,.18);background:rgba(66,165,255,.07);color:#b9dafa;border-radius:9px;padding:5px 8px;font:11px Consolas,monospace;overflow-wrap:anywhere}.side h2{font-size:15px;margin:0 0 4px}.side p{font-size:12px;color:var(--muted);margin:0}.divider{height:1px;background:var(--line);margin:19px 0}.mini{display:grid;grid-template-columns:1fr 1fr;gap:9px}.stat{background:rgba(3,11,23,.45);border:1px solid var(--line);border-radius:14px;padding:12px}.stat b{display:block;font-size:19px}.stat span{font-size:11px;color:var(--muted)}.results{margin-top:18px;display:grid;gap:10px}.empty{text-align:center;border:1px dashed rgba(148,163,184,.22);border-radius:17px;padding:27px;color:var(--muted);font-size:13px}.result{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:13px;padding:14px;border-radius:17px;background:rgba(3,11,23,.46);border:1px solid var(--line);animation:in .35s ease both}.icon{width:39px;height:39px;border-radius:12px;display:grid;place-items:center;font-weight:bold}.ok .icon{background:rgba(53,210,139,.1);color:var(--green)}.bad .icon{background:rgba(255,102,124,.1);color:var(--red)}.warn .icon{background:rgba(248,188,75,.1);color:var(--amber)}.url{font:12px Consolas,monospace;direction:ltr;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.msg{font-size:11px;color:var(--muted);margin-top:3px}.meta{text-align:left;direction:ltr}.code{display:inline-block;padding:3px 7px;border-radius:7px;background:rgba(148,163,184,.09);font:11px Consolas}.latency{display:block;color:var(--muted);font-size:10px;margin-top:4px}.docs{margin-top:18px;padding:18px 20px}.docs h2{font-size:14px;margin:0 0 7px}.docs code{display:block;direction:ltr;text-align:left;overflow:auto;padding:12px;background:rgba(3,11,23,.5);border-radius:12px;color:#9ed8ff;font:11px/1.7 Consolas}.toast{position:fixed;left:20px;bottom:20px;padding:11px 15px;border-radius:12px;background:#17263a;border:1px solid var(--line);box-shadow:var(--shadow);font-size:12px;transform:translateY(90px);opacity:0;transition:.25s}.toast.show{transform:none;opacity:1}.tablink{font-family:Tahoma;cursor:pointer}.tabs{display:flex;gap:7px;margin:0 0 18px;padding:5px;width:max-content;max-width:100%;background:rgba(14,27,46,.72);border:1px solid var(--line);border-radius:14px}.tab{border:0;background:transparent;color:var(--muted);padding:9px 14px;border-radius:10px;font:12px Tahoma;cursor:pointer}.tab.active{color:white;background:linear-gradient(120deg,rgba(24,121,238,.72),rgba(36,185,207,.55));box-shadow:0 7px 22px rgba(0,0,0,.2)}.filepanel{height:calc(100vh - 185px);min-height:650px}.filepanel iframe{width:100%;height:100%;border:1px solid var(--line);border-radius:23px;background:#07111e;box-shadow:var(--shadow)}@keyframes in{from{opacity:0;transform:translateY(7px)}}@media(max-width:780px){.wrap{padding-top:24px}.top{align-items:flex-start}.badge{display:none}.grid{grid-template-columns:1fr}.main,.side{padding:18px}.result{grid-template-columns:39px minmax(0,1fr)}.meta{grid-column:2;text-align:right}.docs{padding:16px}}
</style>
</head>
<body><div class="orb o1"></div><div class="orb o2"></div><main class="wrap">
<header class="top"><div class="brand"><div class="logo"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8"><path d="M8 12h8M13 7l5 5-5 5"/><path d="M6 19a9 9 0 1 1 0-14"/></svg></div><div><h1>درگاه ارتباط API</h1><p>بررسی دسترسی خروجی سرور پارس‌پک به سرویس‌های خارجی</p></div></div><button class="badge tablink" data-tab="files">مدیریت فایل‌ها ←</button></header>
<nav class="tabs"><button class="tab active" data-tab="proxy">بررسی دسترسی API</button><button class="tab" data-tab="files">فایل‌منیجر</button></nav><div id="proxyPanel" class="pagepanel">
<section class="grid"><div class="card main"><div class="labelrow"><label for="urls">آدرس‌های مورد نظر</label><span class="hint">حداکثر ۱۰ آدرس، هر کدام در یک خط</span></div><textarea id="urls" spellcheck="false" placeholder="https://api.example.com/health&#10;https://your-worker.workers.dev/"></textarea><div class="actions"><button class="btn primary" id="check">بررسی دسترسی</button><button class="btn ghost" id="providers">تست همه ارائه‌دهندگان</button><button class="btn ghost" id="clear">پاک‌کردن</button></div><div class="results" id="results"><div class="empty">نتیجه بررسی آدرس‌ها در این قسمت نمایش داده می‌شود.</div></div></div>
<aside class="card side"><h2>دامنه‌های مجاز</h2><p>مقصدهای مجاز پروکسی در ابتدای فایل</p><div class="rules" id="rules"></div><div class="divider"></div><div class="mini"><div class="stat"><b id="success">۰</b><span>قابل دسترسی</span></div><div class="stat"><b id="failed">۰</b><span>ناموفق / غیرمجاز</span></div></div><div class="divider"></div><p>این آزمایش از داخل سرور انجام می‌شود؛ بنابراین نتیجه، دسترسی واقعی PaaS به مقصد را نشان می‌دهد. پاسخ‌های HTTP مانند 401 یا 403 نیز یعنی ارتباط شبکه برقرار شده است.</p></aside></section>
<section class="card docs"><h2>نمونه استفاده از پروکسی</h2><code>GET /proxy.php?url=https%3A%2F%2Fapi.example.com%2Fv1%2Fstatus</code></section></div><section id="filesPanel" class="pagepanel filepanel" hidden><iframe id="fileFrame" title="مدیریت فایل‌ها" data-src="?panel=files"></iframe></section></main><div class="toast" id="toast"></div>
<script>
const rules=__RULES__;const providerUrls=__PROVIDERS__;const $=s=>document.querySelector(s);const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
$('#rules').innerHTML=rules.map(x=>`<span class="chip">${esc(x)}</span>`).join('');
function switchTab(name){const files=name==='files';$('#proxyPanel').hidden=files;$('#filesPanel').hidden=!files;document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x.dataset.tab===name));if(files){const f=$('#fileFrame');if(!f.getAttribute('src'))f.src=f.dataset.src;location.hash='files'}else{history.replaceState(null,'',location.pathname+location.search)}}document.querySelectorAll('[data-tab]').forEach(x=>x.addEventListener('click',()=>switchTab(x.dataset.tab)));if(location.hash==='#files')switchTab('files');
function toast(t){const e=$('#toast');e.textContent=t;e.classList.add('show');setTimeout(()=>e.classList.remove('show'),2400)}
$('#providers').onclick=()=>{$('#urls').value=Object.values(providerUrls).join('\n');$('#check').click()};
$('#clear').onclick=()=>{$('#urls').value='';$('#results').innerHTML='<div class="empty">نتیجه بررسی آدرس‌ها در این قسمت نمایش داده می‌شود.</div>';$('#success').textContent='۰';$('#failed').textContent='۰'};
$('#check').onclick=async()=>{const urls=$('#urls').value.split(/\n/).map(x=>x.trim()).filter(Boolean);if(!urls.length){toast('حداقل یک آدرس وارد کنید');return}if(urls.length>10){toast('حداکثر ۱۰ آدرس قابل بررسی است');return}const b=$('#check');b.disabled=true;b.textContent='در حال بررسی…';$('#results').innerHTML='<div class="empty">در حال برقراری ارتباط از داخل سرور…</div>';try{const r=await fetch('?action=check',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({urls})});const d=await r.json();if(!r.ok)throw new Error(d.message||'خطای سرور');let good=0;$('#results').innerHTML=d.results.map(x=>{if(x.reachable)good++;const cls=x.reachable?(x.status>=200&&x.status<400?'ok':'warn'):'bad';const mark=x.reachable?'✓':'×';return `<article class="result ${cls}"><div class="icon">${mark}</div><div><div class="url" title="${esc(x.url)}">${esc(x.url)}</div><div class="msg">${esc(x.message)}${x.ip?' · '+esc(x.ip):''}</div></div><div class="meta"><span class="code">${x.status?'HTTP '+x.status:(x.allowed?'NO RESPONSE':'BLOCKED')}</span><span class="latency">${x.latency_ms!==null?x.latency_ms+' ms':'—'}</span></div></article>`}).join('');$('#success').textContent=good.toLocaleString('fa-IR');$('#failed').textContent=(d.results.length-good).toLocaleString('fa-IR')}catch(e){ $('#results').innerHTML=`<div class="empty">${esc(e.message)}</div>`;toast('بررسی انجام نشد')}finally{b.disabled=false;b.textContent='بررسی دسترسی'}};
</script></body></html>
HTML;
    echo str_replace(
        ['__RULES__', '__PROVIDERS__'],
        [$rulesJson ?: '[]', $providerUrlsJson ?: '{}'],
        $html
    );
}

// ---------- Password-protected file manager ----------
function fmJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fmInside(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

function fmRoot(): string
{
    $configured = envString('FILE_MANAGER_ROOT');
    if ($configured === '') {
        throw new RuntimeException('متغیر FILE_MANAGER_ROOT تنظیم نشده است.');
    }
    if (!str_starts_with($configured, DIRECTORY_SEPARATOR)) {
        $configured = __DIR__ . DIRECTORY_SEPARATOR . $configured;
    }
    if (!is_dir($configured) && !@mkdir($configured, 0750, true)) {
        throw new RuntimeException('ساخت پوشه ریشه فایل‌منیجر ممکن نیست.');
    }
    $root = realpath($configured);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('مسیر ریشه فایل‌منیجر معتبر نیست.');
    }
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    if ($root === '') {
        throw new RuntimeException('استفاده از ریشه سیستم‌عامل به‌عنوان FILE_MANAGER_ROOT مجاز نیست.');
    }
    return $root;
}

function fmCleanRel(string $relative): string
{
    if (str_contains($relative, "\0")) {
        throw new RuntimeException('مسیر نامعتبر است.');
    }
    $relative = str_replace('\\', '/', trim($relative));
    $parts = [];
    foreach (explode('/', trim($relative, '/')) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') throw new RuntimeException('خروج از مسیر مجاز نیست.');
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function fmPath(string $relative, bool $mustExist = true): string
{
    $root = fmRoot();
    $relative = fmCleanRel($relative);
    if ($relative === '') return $root;
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($mustExist) {
        $real = realpath($candidate);
        if ($real === false || !fmInside($real, $root)) {
            throw new RuntimeException('فایل یا پوشه پیدا نشد.');
        }
        return $real;
    }
    $parent = realpath(dirname($candidate));
    if ($parent === false || !fmInside($parent, $root)) {
        throw new RuntimeException('پوشه مقصد معتبر نیست.');
    }
    return $parent . DIRECTORY_SEPARATOR . basename($candidate);
}

function fmValidName(string $name): string
{
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..' || basename($name) !== $name || preg_match('/[\\x00-\\x1F\\x7F\\/\\\\]/u', $name)) {
        throw new RuntimeException('نام انتخاب‌شده معتبر نیست.');
    }
    return $name;
}

function fmBlockedFile(string $name): bool
{
    $default = 'php,php3,php4,php5,php7,php8,phtml,phar,htaccess,user.ini';
    $blocked = array_filter(array_map(fn($x) => strtolower(ltrim(trim($x), '.')), explode(',', envString('FILE_MANAGER_BLOCKED_EXTENSIONS', $default))));
    $lower = strtolower($name);
    if (in_array($lower, ['.htaccess', '.user.ini'], true)) return true;
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $blocked, true);
}

function fmDeleteTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!@unlink($path)) throw new RuntimeException('حذف فایل ممکن نشد.');
        return;
    }
    $items = scandir($path);
    if ($items === false) throw new RuntimeException('خواندن پوشه ممکن نشد.');
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') fmDeleteTree($path . DIRECTORY_SEPARATOR . $item);
    }
    if (!@rmdir($path)) throw new RuntimeException('حذف پوشه ممکن نشد.');
}

function fmCopyTree(string $source, string $destination): void
{
    if (is_link($source)) throw new RuntimeException('کپی symbolic link مجاز نیست.');
    if (is_file($source)) {
        if (!@copy($source, $destination)) throw new RuntimeException('کپی فایل ممکن نشد.');
        return;
    }
    if (!@mkdir($destination, 0750, false)) throw new RuntimeException('ساخت پوشه مقصد ممکن نشد.');
    $items = scandir($source);
    if ($items === false) throw new RuntimeException('خواندن پوشه ممکن نشد.');
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') fmCopyTree($source . DIRECTORY_SEPARATOR . $item, $destination . DIRECTORY_SEPARATOR . $item);
    }
}

function fmRequireCsrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    $saved = $_SESSION['fm_csrf'] ?? '';
    if (!is_string($sent) || !is_string($saved) || $saved === '' || !hash_equals($saved, $sent)) {
        fmJson(['ok' => false, 'message' => 'نشست یا CSRF token معتبر نیست.'], 419);
    }
}

function fmRender(bool $authenticated, string $message = ''): void
{
    $csrf = $authenticated ? (string) ($_SESSION['fm_csrf'] ?? '') : '';
    $csrfJson = json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $messageHtml = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    $html = <<<'HTML'
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>مدیریت فایل‌ها</title><style>
:root{--bg:#07111e;--card:rgba(15,28,47,.88);--line:rgba(148,163,184,.16);--txt:#edf5ff;--muted:#91a5bf;--blue:#479dff;--cyan:#2bd8c0;--green:#3bd692;--red:#ff657a;--amber:#f6bb51}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at 10% 5%,rgba(44,112,255,.18),transparent 28%),radial-gradient(circle at 92% 18%,rgba(43,216,192,.12),transparent 25%),var(--bg);color:var(--txt);font-family:Tahoma,"Segoe UI",sans-serif}.wrap{width:min(1180px,calc(100% - 28px));margin:auto;padding:30px 0 60px}.card{background:var(--card);border:1px solid var(--line);border-radius:22px;box-shadow:0 25px 80px rgba(0,0,0,.34);backdrop-filter:blur(18px)}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:20px}.brand{display:flex;align-items:center;gap:12px}.logo{width:48px;height:48px;border-radius:15px;display:grid;place-items:center;background:linear-gradient(135deg,var(--blue),var(--cyan));font-size:23px}.brand h1{font-size:22px;margin:0}.brand p{font-size:12px;color:var(--muted);margin:2px 0}.btn{border:1px solid var(--line);background:rgba(148,163,184,.08);color:var(--txt);padding:10px 14px;border-radius:12px;font:12px Tahoma;cursor:pointer;transition:.2s}.btn:hover{transform:translateY(-1px);border-color:rgba(71,157,255,.4)}.primary{border:0;background:linear-gradient(120deg,#247be6,#22b8c9);font-weight:bold}.danger{color:#ff9bab}.toolbar{display:flex;flex-wrap:wrap;gap:8px;padding:15px}.pathbar{display:flex;align-items:center;gap:7px;overflow:auto;padding:14px 17px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);direction:ltr}.crumb{white-space:nowrap;color:#a8cffb;cursor:pointer;font:12px Consolas}.sep{color:#52657d}.drop{margin:15px;padding:18px;border:1px dashed rgba(71,157,255,.32);border-radius:15px;text-align:center;color:var(--muted);font-size:12px;transition:.2s}.drop.over{background:rgba(71,157,255,.09);border-color:var(--blue)}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:730px}th{text-align:right;padding:12px 16px;color:var(--muted);font-size:11px;font-weight:normal;border-bottom:1px solid var(--line)}td{padding:12px 16px;border-bottom:1px solid rgba(148,163,184,.08);font-size:12px}tr:hover td{background:rgba(148,163,184,.035)}.name{display:flex;align-items:center;gap:10px;cursor:pointer;direction:ltr;text-align:left}.ico{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:rgba(71,157,255,.09);font-size:16px}.folder .ico{color:var(--amber);background:rgba(246,187,81,.08)}.muted{color:var(--muted)}.rowactions{display:flex;gap:5px}.ib{border:0;background:transparent;color:#9fb3ca;padding:5px 7px;cursor:pointer;border-radius:7px}.ib:hover{background:rgba(148,163,184,.1);color:white}.empty{text-align:center;padding:55px;color:var(--muted)}.login{width:min(430px,calc(100% - 30px));margin:12vh auto 0;padding:28px}.login h1{margin:0 0 6px}.login p{color:var(--muted);font-size:12px}.login input{width:100%;margin:16px 0 10px;background:#091525;border:1px solid var(--line);border-radius:13px;padding:13px;color:white;outline:none}.login input:focus{border-color:var(--blue)}.login .btn{width:100%;padding:13px}.error{color:#ff9aaa;font-size:12px}.toast{position:fixed;left:18px;bottom:18px;background:#17283e;border:1px solid var(--line);padding:11px 15px;border-radius:12px;font-size:12px;opacity:0;transform:translateY(70px);transition:.25s;box-shadow:0 15px 50px #0008}.toast.on{opacity:1;transform:none}.loader{padding:50px;text-align:center;color:var(--muted)}input[type=file]{display:none}@media(max-width:700px){.wrap{padding-top:18px}.brand p{display:none}.toolbar{padding:11px}.hide-sm{display:none}}
</style></head><body>
__BODY__
<div class="toast" id="toast"></div><script>__SCRIPT__</script></body></html>
HTML;

    if (!$authenticated) {
        $body = '<main class="login card"><div class="logo">▣</div><h1>ورود به مدیریت فایل‌ها</h1><p>برای ادامه، رمز مدیریتی تعریف‌شده در سرور را وارد کنید.</p>' . ($messageHtml !== '' ? '<div class="error">' . $messageHtml . '</div>' : '') . '<form method="post" action="?panel=files&fm_action=login"><input type="password" name="password" autocomplete="current-password" required placeholder="رمز مدیریت"><button class="btn primary" type="submit">ورود امن</button></form></main>';
        echo str_replace(['__BODY__', '__SCRIPT__'], [$body, ''], $html);
        return;
    }

    $body = '<main class="wrap"><header class="top"><div class="brand"><div class="logo">▣</div><div><h1>مدیریت فایل‌ها</h1><p>فضای ذخیره‌سازی کنترل‌شده سرور</p></div></div><div><a class="btn" href="?#proxy" target="_top">پنل API</a> <button class="btn danger" id="logout">خروج</button></div></header><section class="card"><div class="toolbar"><button class="btn primary" id="uploadBtn">آپلود فایل</button><button class="btn" id="newFolder">پوشه جدید</button><button class="btn" id="newFile">فایل جدید</button><button class="btn" id="refresh">تازه‌سازی</button><input type="file" id="picker" multiple></div><nav class="pathbar" id="crumbs"></nav><div class="drop" id="drop">فایل‌ها را برای آپلود در این قسمت رها کنید</div><div class="tablewrap"><table><thead><tr><th>نام</th><th>حجم</th><th>آخرین تغییر</th><th>دسترسی</th><th>عملیات</th></tr></thead><tbody id="rows"><tr><td colspan="5" class="loader">در حال بارگذاری…</td></tr></tbody></table></div></section></main>';
    $script = <<<'JS'
const csrf=__CSRF__;let current='';const $=s=>document.querySelector(s);const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function toast(t){const e=$('#toast');e.textContent=t;e.classList.add('on');setTimeout(()=>e.classList.remove('on'),2300)}function size(n){if(n===null)return'—';const u=['B','KB','MB','GB'];let i=0;while(n>=1024&&i<3){n/=1024;i++}return(n<10&&i?n.toFixed(1):Math.round(n))+' '+u[i]}
async function api(action,data=null,method='POST'){const o={method,headers:{'X-CSRF-Token':csrf}};if(data&&method!=='GET'){if(data instanceof FormData)o.body=data;else{o.headers['Content-Type']='application/json';o.body=JSON.stringify(data)}}const r=await fetch(`?panel=files&fm_action=${encodeURIComponent(action)}`+(method==='GET'&&data?'&'+new URLSearchParams(data):''),o);const d=await r.json();if(!r.ok)throw new Error(d.message||'خطای سرور');return d}
function crumbs(){const p=current?current.split('/'):[];let built='';let h='<span class="crumb" data-p="">ROOT</span>';for(const x of p){built+=(built?'/':'')+x;h+='<span class="sep">/</span><span class="crumb" data-p="'+esc(built)+'">'+esc(x)+'</span>'}$('#crumbs').innerHTML=h;document.querySelectorAll('.crumb').forEach(x=>x.onclick=()=>load(x.dataset.p))}
async function load(path=''){current=path;crumbs();$('#rows').innerHTML='<tr><td colspan="5" class="loader">در حال بارگذاری…</td></tr>';try{const d=await api('list',{path},'GET');if(!d.items.length){$('#rows').innerHTML='<tr><td colspan="5" class="empty">این پوشه خالی است.</td></tr>';return}$('#rows').innerHTML=d.items.map(x=>`<tr><td><div class="name ${x.type==='folder'?'folder':''}" data-open="${esc(x.path)}" data-type="${x.type}"><span class="ico">${x.type==='folder'?'▰':'▤'}</span><span>${esc(x.name)}</span></div></td><td class="muted">${size(x.size)}</td><td class="muted">${esc(x.modified)}</td><td class="muted hide-sm">${esc(x.permissions)}</td><td><div class="rowactions">${x.type==='file'?`<button class="ib" data-act="download" data-p="${esc(x.path)}" title="دانلود">↓</button>`:''}<button class="ib" data-act="rename" data-p="${esc(x.path)}" data-name="${esc(x.name)}" title="تغییر نام">✎</button><button class="ib" data-act="copy" data-p="${esc(x.path)}" title="کپی">⧉</button><button class="ib" data-act="move" data-p="${esc(x.path)}" title="انتقال">↗</button><button class="ib" data-act="delete" data-p="${esc(x.path)}" title="حذف">×</button></div></td></tr>`).join('');bind()}catch(e){$('#rows').innerHTML=`<tr><td colspan="5" class="empty">${esc(e.message)}</td></tr>`}}
function bind(){document.querySelectorAll('[data-open]').forEach(e=>e.onclick=()=>{if(e.dataset.type==='folder')load(e.dataset.open);else location.href='?panel=files&fm_action=download&path='+encodeURIComponent(e.dataset.open)});document.querySelectorAll('[data-act]').forEach(e=>e.onclick=async ev=>{ev.stopPropagation();const a=e.dataset.act,p=e.dataset.p;try{if(a==='download'){location.href='?panel=files&fm_action=download&path='+encodeURIComponent(p);return}if(a==='delete'){if(!confirm('این مورد و تمام محتوای آن حذف شود؟'))return;await api('delete',{path:p})}if(a==='rename'){const n=prompt('نام جدید:',e.dataset.name);if(!n)return;await api('rename',{path:p,name:n})}if(a==='copy'||a==='move'){const d=prompt('مسیر کامل مقصد نسبت به ROOT:',p);if(!d||d===p)return;await api(a,{source:p,destination:d})}toast('عملیات با موفقیت انجام شد');load(current)}catch(x){toast(x.message)}})}
async function upload(files){if(!files.length)return;const f=new FormData();f.append('path',current);[...files].forEach(x=>f.append('files[]',x));try{const d=await api('upload',f);toast(d.message);load(current)}catch(e){toast(e.message)}}
$('#uploadBtn').onclick=()=>$('#picker').click();$('#picker').onchange=e=>upload(e.target.files);$('#refresh').onclick=()=>load(current);$('#newFolder').onclick=async()=>{const n=prompt('نام پوشه جدید:');if(!n)return;try{await api('mkdir',{path:current,name:n});load(current)}catch(e){toast(e.message)}};$('#newFile').onclick=async()=>{const n=prompt('نام فایل جدید:');if(!n)return;try{await api('create',{path:current,name:n});load(current)}catch(e){toast(e.message)}};$('#logout').onclick=async()=>{await api('logout',{});location.reload()};const drop=$('#drop');['dragenter','dragover'].forEach(n=>drop.addEventListener(n,e=>{e.preventDefault();drop.classList.add('over')}));['dragleave','drop'].forEach(n=>drop.addEventListener(n,e=>{e.preventDefault();drop.classList.remove('over')}));drop.ondrop=e=>upload(e.dataTransfer.files);load();
JS;
    $script = str_replace('__CSRF__', $csrfJson ?: '""', $script);
    echo str_replace(['__BODY__', '__SCRIPT__'], [$body, $script], $html);
}

function handleFileManager(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('PARS_FM');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $action = isset($_GET['fm_action']) && is_string($_GET['fm_action']) ? $_GET['fm_action'] : '';
    $password = envString('FILE_MANAGER_PASSWORD');
    if ($password === '') {
        fmRender(false, 'فایل‌منیجر غیرفعال است؛ FILE_MANAGER_PASSWORD را تنظیم کنید.');
        exit;
    }

    if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $attempts = (int) ($_SESSION['fm_attempts'] ?? 0);
        $lockedUntil = (int) ($_SESSION['fm_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            fmRender(false, 'تلاش‌های ناموفق زیاد است؛ کمی بعد دوباره امتحان کنید.');
            exit;
        }
        $given = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if (hash_equals($password, $given)) {
            session_regenerate_id(true);
            $_SESSION['fm_auth'] = true;
            $_SESSION['fm_csrf'] = bin2hex(random_bytes(24));
            $_SESSION['fm_attempts'] = 0;
            header('Location: ?panel=files');
            exit;
        }
        $attempts++;
        $_SESSION['fm_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['fm_locked_until'] = time() + 300;
            $_SESSION['fm_attempts'] = 0;
        }
        fmRender(false, 'رمز واردشده صحیح نیست.');
        exit;
    }

    if (($_SESSION['fm_auth'] ?? false) !== true) {
        fmRender(false);
        exit;
    }
    if (!isset($_SESSION['fm_csrf'])) $_SESSION['fm_csrf'] = bin2hex(random_bytes(24));

    try {
        if ($action === 'download' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $path = fmPath((string) ($_GET['path'] ?? ''));
            if (!is_file($path) || is_link($path)) throw new RuntimeException('فایل قابل دانلود نیست.');
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($path)));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }

        if ($action === 'list' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $rel = fmCleanRel((string) ($_GET['path'] ?? ''));
            $dir = fmPath($rel);
            if (!is_dir($dir)) throw new RuntimeException('مسیر یک پوشه نیست.');
            $items = [];
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $full = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_link($full)) continue;
                $isDir = is_dir($full);
                $itemRel = ltrim(($rel !== '' ? $rel . '/' : '') . $name, '/');
                $items[] = ['name' => $name, 'path' => $itemRel, 'type' => $isDir ? 'folder' : 'file', 'size' => $isDir ? null : (@filesize($full) ?: 0), 'modified' => date('Y-m-d H:i', @filemtime($full) ?: time()), 'permissions' => substr(sprintf('%o', @fileperms($full) ?: 0), -4)];
            }
            usort($items, fn($a, $b) => $a['type'] === $b['type'] ? strnatcasecmp($a['name'], $b['name']) : ($a['type'] === 'folder' ? -1 : 1));
            fmJson(['ok' => true, 'path' => $rel, 'items' => $items]);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            fmRender(true);
            exit;
        }
        fmRequireCsrf();
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $data = str_starts_with($contentType, 'application/json') ? json_decode(file_get_contents('php://input') ?: '{}', true) : $_POST;
        if (!is_array($data)) $data = [];

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            fmJson(['ok' => true]);
        }
        if ($action === 'mkdir' || $action === 'create') {
            $parentRel = fmCleanRel((string) ($data['path'] ?? ''));
            $name = fmValidName((string) ($data['name'] ?? ''));
            if ($action === 'create' && fmBlockedFile($name)) throw new RuntimeException('ساخت این نوع فایل به دلایل امنیتی مجاز نیست.');
            $targetRel = ($parentRel !== '' ? $parentRel . '/' : '') . $name;
            $target = fmPath($targetRel, false);
            if (file_exists($target)) throw new RuntimeException('موردی با این نام وجود دارد.');
            $ok = $action === 'mkdir' ? @mkdir($target, 0750) : @touch($target);
            if (!$ok) throw new RuntimeException('ساخت مورد جدید ممکن نشد.');
            fmJson(['ok' => true]);
        }
        if ($action === 'rename') {
            $source = fmPath((string) ($data['path'] ?? ''));
            if ($source === fmRoot()) throw new RuntimeException('تغییر نام ریشه مجاز نیست.');
            $name = fmValidName((string) ($data['name'] ?? ''));
            if (is_file($source) && fmBlockedFile($name)) throw new RuntimeException('این پسوند مجاز نیست.');
            $dest = dirname($source) . DIRECTORY_SEPARATOR . $name;
            if (file_exists($dest) || !@rename($source, $dest)) throw new RuntimeException('تغییر نام ممکن نشد.');
            fmJson(['ok' => true]);
        }
        if ($action === 'delete') {
            $path = fmPath((string) ($data['path'] ?? ''));
            if ($path === fmRoot()) throw new RuntimeException('حذف ریشه مجاز نیست.');
            fmDeleteTree($path);
            fmJson(['ok' => true]);
        }
        if ($action === 'copy' || $action === 'move') {
            $source = fmPath((string) ($data['source'] ?? ''));
            if ($source === fmRoot()) throw new RuntimeException('انتقال یا کپی ریشه مجاز نیست.');
            $destinationRel = fmCleanRel((string) ($data['destination'] ?? ''));
            if ($destinationRel === '') throw new RuntimeException('مسیر مقصد نمی‌تواند ریشه باشد.');
            $destination = fmPath($destinationRel, false);
            if (file_exists($destination)) throw new RuntimeException('مقصد از قبل وجود دارد.');
            if (is_file($source) && fmBlockedFile(basename($destination))) throw new RuntimeException('این پسوند مجاز نیست.');
            if (is_dir($source) && fmInside($destination, $source)) throw new RuntimeException('پوشه را نمی‌توان داخل خودش قرار داد.');
            if ($action === 'copy') fmCopyTree($source, $destination);
            else {
                if (!@rename($source, $destination)) {
                    fmCopyTree($source, $destination);
                    fmDeleteTree($source);
                }
            }
            fmJson(['ok' => true]);
        }
        if ($action === 'upload') {
            $parent = fmPath((string) ($data['path'] ?? ''));
            if (!is_dir($parent)) throw new RuntimeException('مسیر آپلود معتبر نیست.');
            $files = $_FILES['files'] ?? null;
            if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) throw new RuntimeException('فایلی دریافت نشد.');
            $max = envInt('FILE_MANAGER_MAX_UPLOAD_BYTES', 20 * 1024 * 1024, 1, 200 * 1024 * 1024);
            $done = 0;
            foreach ($files['name'] as $i => $rawName) {
                $name = fmValidName(basename((string) $rawName));
                if (fmBlockedFile($name)) throw new RuntimeException('آپلود فایل اجرایی یا تنظیماتی مجاز نیست: ' . $name);
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('آپلود فایل ناموفق بود: ' . $name);
                if ((int) ($files['size'][$i] ?? 0) > $max) throw new RuntimeException('حجم فایل بیش از حد مجاز است: ' . $name);
                $target = $parent . DIRECTORY_SEPARATOR . $name;
                if (file_exists($target)) throw new RuntimeException('فایل از قبل وجود دارد: ' . $name);
                if (!is_uploaded_file($files['tmp_name'][$i]) || !@move_uploaded_file($files['tmp_name'][$i], $target)) throw new RuntimeException('ذخیره فایل ممکن نشد: ' . $name);
                @chmod($target, 0640);
                $done++;
            }
            fmJson(['ok' => true, 'message' => $done . ' فایل آپلود شد.']);
        }
        fmJson(['ok' => false, 'message' => 'عملیات ناشناخته است.'], 404);
    } catch (Throwable $e) {
        fmJson(['ok' => false, 'message' => $e->getMessage()], 400);
    }
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

if (isset($_GET['panel']) && $_GET['panel'] === 'files') {
    handleFileManager();
    exit;
}

if (!extension_loaded('curl')) {
    sendJsonError(500, 'curl_missing', 'افزونه cURL روی PHP فعال نیست.');
}

$allowedHosts = array_values(array_filter(array_map('trim', PROXY_TARGET_HOSTS)));
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
    sendJsonError(503, 'proxy_not_configured', 'فهرست PROXY_TARGET_HOSTS در ابتدای فایل خالی است.');
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
