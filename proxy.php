<?php
/**
 * =====================================================================
 *  پراکسی سرور تک‌فایلی PHP — بدون هیچ وابستگی خارجی
 *  فقط به اکستنشن cURL نیاز دارد (روی همهٔ هاست‌ها فعال است)
 *  سازگار با PHP 7.4 به بالا
 *
 *  استفاده:
 *    proxy.php?url=https://example.com/page          → GET
 *    proxy.php?url=...  با متد POST/PUT/DELETE و بدنهٔ دلخواه
 *    proxy.php                                      → داشبورد راهنما
 *    proxy.php?info=1                               → وضعیت به‌صورت JSON
 *
 *  استفاده به‌عنوان پراکسی فوروارد (مثلاً در فیلد پروکسی اسکرپر):
 *    - مقصدهای HTTP : خودکار از طریق درخواست absolute-form پشتیبانی می‌شود.
 *    - مقصدهای HTTPS: به روش CONNECT نیاز دارد؛ روی Apache/LiteSpeed این
 *      دو خط را در .htaccess کنار proxy.php بگذارید تا CONNECT به PHP برسد:
 *
 *          RewriteEngine On
 *          RewriteCond %{REQUEST_METHOD} ^CONNECT$
 *          RewriteRule ^(.*)$ /proxy.php?__connect__=$1 [L]
 *
 *      (روی nginx/php-fpm معمولاً CONNECT به PHP نمی‌رسد؛ در آن حالت از
 *       حالت ?url= یا فیلد Worker اسکرپر استفاده کنید.)
 *
 *  استفاده از داخل اسکرپر — فیلد «Worker» (بدون هیچ تنظیم سروری):
 *      https://your-server.com/proxy.php?url={url}
 *  یا بدون الگو (پشتیبانی مسیری هم اضافه شده):
 *      https://your-server.com/proxy.php
 *      → اسکرپر می‌سازد: /proxy.php/https://target/... و خودش تشخیص داده می‌شود
 *
 *  هدرهای کنترلی (اختیاری):
 *    X-Proxy-Key      → کلید محافظت (اگر تنظیم شده باشد)
 *    X-Proxy-UA       → بازنویسی User-Agent
 *    X-Proxy-Referer  → بازنویسی Referer
 *    X-Proxy-Cookie   → ارسال کوکی به مقصد
 *    X-Proxy-Time     → تایم‌اوت سفارشی (ثانیه، حداکثر ۳۰۰)
 *
 *  فالبک ورکر کلودفلر:
 *    اگر اتصال به مقصد ناموفق باشد، درخواست از طریق cloudflare_worker_url
 *    (پیش‌فرض: https://proxy.fazilat-ma.workers.dev — اولین تنظیم در
 *    $CONFIG) رله می‌شود. ورکر باید همان API پارامتر ?url= را داشته
 *    باشد — کد آماده در cloudflare-worker.js.
 * =====================================================================
 */

// ---------------------------------------------------------------------
// [۱] تنظیمات — این بخش را مطابق نیاز خودتان تغییر دهید
// ---------------------------------------------------------------------
$CONFIG = [

    // ╔══════════════════════════════════════════════════════════════╗
    // ║  ☁️  آدرس ورکر کلودفلر  —  همین‌جا بگذارید یا عوض کنید        ║
    // ╠══════════════════════════════════════════════════════════════╣
    // ║  اگر اتصال مستقیم (یا از طریق پراکسی‌های بالادستی) به مقصد   ║
    // ║  ناموفق باشد، درخواست به‌صورت خودکار از این ورکر رله می‌شود. ║
    // ║  ورکر باید API پارامتر ?url= داشته باشد (کد آماده در فایل     ║
    // ║  cloudflare-worker.js).  خالی = غیرفعال.                     ║
    // ╚══════════════════════════════════════════════════════════════╝
    'cloudflare_worker_url' => 'https://proxy.fazilat-ma.workers.dev',

    'fallback_key'      => '',                    // کلید ورکر فالبک (اگر ورکر کلید داشته باشد)
    'fallback_on_statuses' => [],                 // اگر مقصد این وضعیت‌ها را هم داد از فالبک استفاده کن؛ نمونه: [403, 451]
    'proxy_key'         => '',                    // کلید محافظت؛ خالی = بدون نیاز به کلید
    'allowed_domains'   => [],                    // لیست سفید؛ خالی = همهٔ دامنه‌ها مجاز. نمونه: ['barfbox.ir', '*.digikala.com']
    'blocked_domains'   => ['localhost', '127.0.0.1', '0.0.0.0'],
    'upstream_proxies'  => [],                    // پراکسی‌های بالادستی برای چرخش. نمونه: ['http://user:pass@1.2.3.4:8080', 'socks5://5.6.7.8:1080']
    'rotate_upstream'   => true,                  // چرخش خودکار بین پراکسی‌های بالادستی
    'direct_first'      => true,                  // false = فقط از پراکسی بالادستی عبور کن، مستقیم تلاش نکن
    'retry_statuses'    => [403, 429, 503],       // اگر مقصد این وضعیت‌ها را داد، با پراکسی بالادستی بعدی دوباره تلاش کن
    'timeout'           => 30,                    // تایم‌اوت کل هر درخواست (ثانیه)
    'connect_timeout'   => 10,                    // تایم‌اوت اتصال
    'max_redirects'     => 5,                     // حداکثر تعداد ریدایرکت
    'max_size'          => 50 * 1024 * 1024,      // حداکثر حجم پاسخ مقصد (بایت)
    'max_body_size'     => 20 * 1024 * 1024,      // حداکثر حجم بدنهٔ درخواست ورودی (بایت)
    'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'referer'           => '',                    // ریفرر پیش‌فرض؛ خالی = بدون ریفرر
    'allow_private_ips' => false,                 // اتصال به IPهای داخلی (محافظ SSRF) — فقط برای تست محلی true کنید
    'verify_ssl'        => true,                  // اعتبارسنجی گواهی SSL مقصد
    'inject_base'       => true,                  // تزریق <base href> در پاسخ‌های HTML تا لینک‌های نسبی درست کار کنند
    'forward_auth'      => true,                  // ارسال هدر کلید API به مقصد — برای تست مدل‌های هوش مصنوعی لازم است؛ اگر لازم شد خاموش کنید
    'forward_auth_headers' => ['Authorization', 'X-API-Key', 'api-key'], // هدرهای احراز هویتی که به مقصد ارسال می‌شوند
    'cache_enabled'     => false,                 // کش فایلی پاسخ‌ها
    'cache_ttl'         => 120,                   // مدت اعتبار کش (ثانیه)
    'cache_dir'         => __DIR__ . '/proxy-cache',
    'connect_enabled'   => true,                  // پاسخ به CONNECT (پراکسی فوروارد برای HTTPS) — اگر درخواست به PHP برسد
    'tunnel_idle_timeout' => 120,                 // سقف بیکاری تونل CONNECT (ثانیه)
];

define('PROXY_VERSION', '1.1.4');
define('PROXY_BUILD', '2026-08-17-02');

// پلی‌فیل توابع رشته‌ای برای PHP 7.4
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// ---------------------------------------------------------------------
// [۲] ابزارهای کمکی
// ---------------------------------------------------------------------

/** خروجی امن HTML */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** پاسخ خطا به‌صورت JSON */
function p_error(int $status, string $code, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE);
    exit;
}

/** دریافت مقدار هدر ورودی (بدون حساسیت به بزرگی حروف) */
function p_in_header(string $name): ?string {
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $name) === 0) return (string)$v;
        }
    }
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? (string)$_SERVER[$key] : null;
}

/** بررسی اینکه IP خصوصی/داخلی است (محافظ SSRF) */
function p_is_private_ip(string $ip): bool {
    $ip = strtolower(trim($ip));
    if (strpos($ip, '::ffff:') === 0) $ip = substr($ip, 7); // IPv4 نگاشت‌شده در IPv6

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        if ($n === false) return true;
        $ranges = [
            [ip2long('0.0.0.0'),      ip2long('0.255.255.255')],
            [ip2long('10.0.0.0'),     ip2long('10.255.255.255')],
            [ip2long('100.64.0.0'),   ip2long('100.127.255.255')],
            [ip2long('127.0.0.0'),    ip2long('127.255.255.255')],
            [ip2long('169.254.0.0'),  ip2long('169.254.255.255')],
            [ip2long('172.16.0.0'),   ip2long('172.31.255.255')],
            [ip2long('192.0.0.0'),    ip2long('192.0.0.255')],
            [ip2long('192.0.2.0'),    ip2long('192.0.2.255')],
            [ip2long('192.168.0.0'),  ip2long('192.168.255.255')],
            [ip2long('198.18.0.0'),   ip2long('198.19.255.255')],
            [ip2long('198.51.100.0'), ip2long('198.51.100.255')],
            [ip2long('203.0.113.0'),  ip2long('203.0.113.255')],
            [ip2long('224.0.0.0'),    ip2long('255.255.255.255')],
        ];
        foreach ($ranges as $r) {
            if ($n >= $r[0] && $n <= $r[1]) return true;
        }
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if ($ip === '::' || $ip === '::1') return true;
        $hex = bin2hex(inet_pton($ip));
        if ($hex === false) return true;
        $first = hexdec(substr($hex, 0, 2)) & 0xFE;      // fc00::/7 → بیت هفتم صفر
        if ($first === 0xFC) return true;
        if (str_starts_with($hex, 'fe8') || str_starts_with($hex, 'fe9')
            || str_starts_with($hex, 'fea') || str_starts_with($hex, 'feb')) return true; // fe80::/10
        return false;
    }

    return true; // غیرقابل شناسایی → مسدود
}

/** تطبیق نام دامنه با الگو (پشتیبانی از *.example.com و پسوند) */
function p_domain_matches(string $host, string $pattern): bool {
    $host = strtolower(trim($host, '.'));
    $pattern = strtolower(trim($pattern, '.'));
    if ($pattern === '') return false;
    if ($pattern === '*') return true;
    if (strpos($pattern, '*.') === 0) {
        $suffix = substr($pattern, 1); // '.example.com'
        return str_ends_with($host, $suffix) && strlen($host) > strlen($suffix);
    }
    if ($pattern[0] === '.') {
        return str_ends_with($host, $pattern);
    }
    return $host === $pattern;
}

/** اعتبارسنجی دامنه (لیست سفید/سیاه) */
function p_check_domain(string $host): void {
    foreach ($GLOBALS['CONFIG']['blocked_domains'] as $pattern) {
        if (p_domain_matches($host, $pattern)) {
            p_error(403, 'domain_blocked', "دامنهٔ «{$host}» در لیست سیاه است");
        }
    }
    $allowed = $GLOBALS['CONFIG']['allowed_domains'];
    if (!empty($allowed)) {
        foreach ($allowed as $pattern) {
            if (p_domain_matches($host, $pattern)) return;
        }
        p_error(403, 'domain_not_allowed', "دامنهٔ «{$host}» در لیست سفید نیست");
    }
}

/** بررسی IPهای رزولوشده (محافظ SSRF / DNS rebinding) */
/** رزولوش همهٔ IPهای یک دامنه با زنجیرهٔ چندروشه (مقاوم در برابر هاست‌های محدود) */
function p_resolve_host_ips(string $host): array {
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || $ips === []) {
        $ips = [];
        $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (!empty($r['ip']))       $ips[] = (string)$r['ip'];
                elseif (!empty($r['ipv6'])) $ips[] = (string)$r['ipv6'];
            }
        }
    }
    if ($ips === []) {
        $one = @gethostbyname($host);
        if ($one !== false && $one !== $host && filter_var($one, FILTER_VALIDATE_IP)) {
            $ips = [$one];
        }
    }
    return array_values(array_unique($ips));
}

/** بررسی سخت IPهای رزولوشده (محافظ SSRF / DNS rebinding) — برای تونل CONNECT */
function p_check_ips(string $host): void {
    if ($GLOBALS['CONFIG']['allow_private_ips']) return;
    $ips = p_resolve_host_ips($host);
    if ($ips === []) {
        p_error(502, 'dns_failed', "رزولوش DNS برای «{$host}» ناموفق بود");
    }
    foreach ($ips as $ip) {
        if (p_is_private_ip($ip)) {
            p_error(403, 'private_ip_blocked', "آدرس داخلی/خصوصی ({$ip}) مسدود شد (محافظ SSRF)");
        }
    }
}

/**
 * سیاست DNS مقصد:
 *   ok       → دامنهٔ سالم؛ زنجیرهٔ عادی (مستقیم ← بالادستی ← ورکر)
 *   blocked  → IP لفظیِ خصوصی؛ همیشه مسدود (محافظ SSRF)
 *   filtered → دامنه‌ای که روی این سرور DNS آن مسموم/فیلتر است (به IP داخلی
 *              مثل 10.10.34.35 یا 10.10.34.34 رزولوش می‌شود یا اصلاً حل
 *              نمی‌شود)؛ اتصال مستقیم بی‌فایده است و باید از مسیر جایگزین
 *              (پراکسی بالادستی یا ورکر کلودفلر) رفت
 */
function p_dns_policy(string $host): string {
    if ($GLOBALS['CONFIG']['allow_private_ips']) return 'ok';
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return p_is_private_ip($host) ? 'blocked' : 'ok';
    }
    $ips = p_resolve_host_ips($host);
    if ($ips === []) return 'filtered';
    foreach ($ips as $ip) {
        if (p_is_private_ip($ip)) return 'filtered';
    }
    return 'ok';
}

/** اعتبارسنجی کامل URL */
function p_validate_url(string $url): array {
    if (strlen($url) > 8192) p_error(400, 'url_too_long', 'طول URL بیش از حد مجاز است');
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        p_error(400, 'invalid_url', 'آدرس نامعتبر است؛ نمونهٔ درست: ?url=https://example.com/page');
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
        p_error(400, 'bad_scheme', 'فقط http و https پشتیبانی می‌شود');
    }
    $host = strtolower(trim($parts['host'], '[]'));
    if ($host === '') p_error(400, 'invalid_url', 'میزبان نامعتبر است');
    p_check_domain($host);
    // سیاست DNS جدا برگردانده می‌شود؛ فقط IP لفظیِ خصوصی همین‌جا مسدود می‌شود.
    // دامنه‌های DNS-مسموم (مثل facebook.com روی هاست ایرانی) اجازهٔ عبور به
    // مرحلهٔ بعد را دارند تا از مسیر جایگزین (ورکر کلودفلر) گرفته شوند.
    $dns = p_dns_policy($host);
    if ($dns === 'blocked') {
        p_error(403, 'private_ip_blocked', "آدرس داخلی/خصوصی ({$host}) مسدود شد (محافظ SSRF)");
    }
    return ['url' => $url, 'host' => $host, 'scheme' => $scheme, 'dns' => $dns];
}

/** مطلق‌کردن URL نسبی ریدایرکت */
function p_absolute_url(string $location, string $base): string {
    if (preg_match('~^https?://~i', $location)) return $location;
    $bp = parse_url($base);
    if (!$bp || empty($bp['host'])) return '';
    if (strpos($location, '//') === 0) return ($bp['scheme'] ?? 'https') . ':' . $location;
    $root = ($bp['scheme'] ?? 'https') . '://' . $bp['host']
          . (isset($bp['port']) ? ':' . $bp['port'] : '');
    if ($location !== '' && $location[0] === '/') return $root . $location;
    $dir = preg_replace('~/[^/]*$~', '/', $bp['path'] ?? '/');
    return $root . $dir . $location;
}

/** تجزیهٔ هدرهای خام پاسخ cURL */
function p_parse_headers(string $raw, string $url): array {
    $lines = preg_split("~\r?\n~", $raw);
    $status = 0;
    $headers = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
            $status = (int)$m[1];
            continue;
        }
        if (strpos($line, ':') !== false) {
            $parts = explode(':', $line, 2);
            $k = strtolower(trim($parts[0]));
            $v = trim($parts[1]);
            if ($k === 'location' && !preg_match('~^https?://~i', $v)) {
                $v = p_absolute_url($v, $url);
            }
            $headers[$k][] = $v;
        }
    }
    return [$status, $headers];
}

/** تزریق <base> در HTML تا لینک‌های نسبی درست کار کنند */
function p_inject_base(string $html, string $url): string {
    $base = '<base href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" data-proxy-base="1">';
    if (preg_match('~</head\s*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        return substr($html, 0, $m[0][1]) . $base . substr($html, $m[0][1]);
    }
    return $base . $html;
}

/** کش فایلی */
function p_cache_get(string $key): ?array {
    $cfg = $GLOBALS['CONFIG'];
    if (!$cfg['cache_enabled']) return null;
    $metaFile = $cfg['cache_dir'] . '/' . $key . '.meta';
    $bodyFile = $cfg['cache_dir'] . '/' . $key . '.body';
    if (!is_file($metaFile) || !is_file($bodyFile)) return null;
    $meta = json_decode((string)@file_get_contents($metaFile), true);
    if (!is_array($meta) || (int)($meta['expires'] ?? 0) < time()) {
        @unlink($metaFile); @unlink($bodyFile);
        return null;
    }
    $body = @file_get_contents($bodyFile);
    if ($body === false) return null;
    return ['status' => (int)$meta['status'], 'headers' => $meta['headers'], 'body' => $body];
}

function p_cache_set(string $key, int $status, array $headers, string $body): void {
    $cfg = $GLOBALS['CONFIG'];
    if (!$cfg['cache_enabled']) return;
    $dir = $cfg['cache_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    if (!is_writable($dir)) return;
    @file_put_contents($dir . '/' . $key . '.meta', json_encode([
        'status' => $status, 'headers' => $headers, 'expires' => time() + (int)$cfg['cache_ttl'],
    ]), LOCK_EX);
    @file_put_contents($dir . '/' . $key . '.body', $body, LOCK_EX);
}

// ---------------------------------------------------------------------
// [۳] هستهٔ پراکسی — ارسال درخواست به مقصد (با cURL)
// ---------------------------------------------------------------------

/**
 * اجرای یک درخواست با cURL
 * @return array{status:int, headers:array, body:string, error:?string}
 */
function p_curl_once(string $url, string $method, array $headers, string $body, ?string $proxy, int $timeout): array {
    $cfg = $GLOBALS['CONFIG'];
    $ch = curl_init($url);
    if ($ch === false) return ['status' => 502, 'headers' => [], 'body' => '', 'error' => 'راه‌اندازی cURL ناموفق بود'];

    $received = 0;
    $tooBig   = false;
    $raw      = '';
    $maxSize  = (int)$cfg['max_size'];
    $opts = [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_HEADER            => true,
        CURLOPT_FOLLOWLOCATION    => false,
        CURLOPT_CONNECTTIMEOUT    => (int)$cfg['connect_timeout'],
        CURLOPT_TIMEOUT           => $timeout,
        CURLOPT_USERAGENT         => $cfg['user_agent'],
        CURLOPT_ENCODING          => '',   // رمزگشایی خودکار gzip/deflate/br
        CURLOPT_SSL_VERIFYPEER    => (bool)$cfg['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST    => $cfg['verify_ssl'] ? 2 : 0,
        CURLOPT_PROTOCOLS         => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS   => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER        => $headers,
        CURLOPT_MAXREDIRS         => 0,
        // نکتهٔ مهم: وقتی WRITEFUNCTION تنظیم شده، curl_exec به‌جای رشتهٔ
        // پاسخ فقط true/false برمی‌گرداند؛ داده (هدرها + بدنه) باید همین‌جا
        // در بافر جمع شود — وگرنه بدنه‌ای برای تجزیه وجود ندارد.
        CURLOPT_WRITEFUNCTION     => function ($ch2, $chunk) use (&$received, &$raw, &$tooBig, $maxSize) {
            $len = strlen($chunk);
            if ($received + $len > $maxSize) {
                $tooBig = true;
                return 0; // قطع اتصال
            }
            $received += $len;
            $raw .= $chunk;
            return $len;
        },
    ];
    if ($cfg['referer'] !== '') $opts[CURLOPT_REFERER] = $cfg['referer'];
    if ($proxy !== null && $proxy !== '') $opts[CURLOPT_PROXY] = $proxy;

    $method = strtoupper($method);
    if ($method === 'HEAD') {
        $opts[CURLOPT_NOBODY] = true;
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== '') $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);
    $execOk = curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = $errno ? (curl_error($ch) ?: "خطای cURL شمارهٔ {$errno}") : null;
    $info   = curl_getinfo($ch);
    curl_close($ch);

    if ($tooBig) {
        return ['status' => 413, 'headers' => [], 'body' => '', 'error' => "حجم پاسخ بیش از حد مجاز ({$maxSize} بایت)"];
    }
    if ($execOk === false || $raw === '') {
        return ['status' => 502, 'headers' => [], 'body' => '', 'error' => $error ?? 'پاسخ خالی از مقصد'];
    }

    // جدا کردن هدرها از بدنه: اول CRLFCRLF، بعد LF‌LF، بعد header_size خود cURL
    $headerSize   = strpos($raw, "\r\n\r\n");
    $headerEndLen = 4;
    if ($headerSize === false) {
        $headerSize   = strpos($raw, "\n\n");
        $headerEndLen = 2;
    }
    if ($headerSize === false) {
        $headerSize   = (int)($info['header_size'] ?? 0);
        $headerEndLen = 0;
        if ($headerSize <= 0 || $headerSize > strlen($raw)) $headerSize = -1;
    }
    if ($headerSize < 0) {
        return ['status' => 502, 'headers' => [], 'body' => '', 'error' => 'ساختار پاسخ مقصد نامعتبر است'];
    }
    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize + $headerEndLen);
    $parsed = p_parse_headers($rawHeaders, $url);
    $status = $parsed[0] ?: (int)($info['http_code'] ?? 0);
    if ($status === 0) {
        return ['status' => 502, 'headers' => [], 'body' => '', 'error' => $error ?? 'پاسخی از مقصد دریافت نشد'];
    }
    return ['status' => $status, 'headers' => $parsed[1], 'body' => $body, 'error' => null];
}

/** چرخش بین پراکسی‌های بالادستی: تلاش تا دریافت پاسخ قابل‌قبول */
function p_rotate_attempt(string $url, string $method, array $headers, string $body, int $timeout): array {
    $cfg = $GLOBALS['CONFIG'];
    $list = $cfg['rotate_upstream'] ? $cfg['upstream_proxies'] : [];
    $attempts = $list;
    if ($cfg['direct_first'] || empty($attempts)) {
        array_unshift($attempts, null); // تلاش مستقیم اول (بدون پراکسی)
    }
    $retryStatuses = array_map('intval', $cfg['retry_statuses']);
    $fallbackStatuses = array_map('intval', $cfg['fallback_on_statuses']);
    $last = null;

    foreach ($attempts as $proxy) {
        $res = p_curl_once($url, $method, $headers, $body, $proxy, $timeout);
        $last = $res;
        if ($res['error'] === null
            && !in_array($res['status'], $retryStatuses, true)
            && !in_array($res['status'], $fallbackStatuses, true)) {
            return $res; // پاسخ قابل‌قبول
        }
        // خطا یا وضعیت قابل‌تلاش‌مجدد → با پراکسی بعدی ادامه بده
    }

    // فالبک: اگر اتصال به مقصد ناموفق بود (یا وضعیت قابل‌فالبک دریافت شد)،
    // از ورکر کلودفلر عبور کن
    if ($last !== null && ($last['error'] !== null || in_array($last['status'], $fallbackStatuses, true))) {
        $fb = p_fallback_attempt($url, $method, $headers, $body, $timeout);
        if ($fb !== null && $fb['error'] === null) return $fb;
    }

    return $last ?? ['status' => 502, 'headers' => [], 'body' => '', 'error' => 'نامشخص'];
}

/**
 * مسیر جایگزین برای دامنه‌های DNS-مسموم/فیلترشده:
 * اتصال مستقیم بی‌فایده است (به IP فیلترینگ می‌خورد)، پس بدون آن:
 *   ۱) پراکسی‌های بالادستی — آن‌ها DNS را از شبکهٔ خودشان حل می‌کنند
 *   ۲) ورکر کلودفلر — DNS را از شبکهٔ کلودفلر حل می‌کند
 */
function p_filtered_attempt(string $url, string $method, array $headers, string $body, int $timeout): array {
    $cfg = $GLOBALS['CONFIG'];
    if ($cfg['rotate_upstream']) {
        foreach ((array)$cfg['upstream_proxies'] as $proxy) {
            if ($proxy === null || $proxy === '') continue;
            $res = p_curl_once($url, $method, $headers, $body, $proxy, $timeout);
            if ($res['error'] === null) return $res;
        }
    }
    $fb = p_fallback_attempt($url, $method, $headers, $body, $timeout);
    if ($fb !== null && $fb['error'] === null) return $fb;
    return [
        'status'  => 502,
        'headers' => [],
        'body'    => '',
        'error'   => 'DNS مقصد روی این سرور فیلتر یا مسموم است و هیچ مسیر جایگزینی (پراکسی بالادستی یا ورکر کلودفلر) در دسترس نبود',
    ];
}

/**
 * آدرس مؤثر ورکر فالبک:
 * اولویت با cloudflare_worker_url است؛ fallback_proxy (نام قدیمی) هم
 * برای سازگاری پشتیبانی می‌شود.
 */
function p_fallback_url(array $cfg): string {
    $fb = trim((string)($cfg['cloudflare_worker_url'] ?? ''));
    if ($fb === '') $fb = trim((string)($cfg['fallback_proxy'] ?? ''));
    return $fb;
}

/**
 * تلاش از طریق فالبک (ورکر کلودفلر با API پارامتر ?url=).
 * کنترل‌هدرها به‌صورت X-Proxy-* به ورکر منتقل می‌شوند تا مقصد
 * همان User-Agent/Referer/Cookie را ببیند.
 */
function p_fallback_attempt(string $url, string $method, array $headers, string $body, int $timeout): ?array {
    $cfg = $GLOBALS['CONFIG'];
    $fb = p_fallback_url($cfg);
    if ($fb === '') return null;
    if (!preg_match('~^https?://~i', $fb)) $fb = 'https://' . $fb;

    $fbParts = parse_url($fb);
    $targetParts = parse_url($url);
    // جلوگیری از حلقه: اگر فالبک همان مقصد باشد (هاست + پورت)، استفاده نشود
    $fbScheme = strtolower($fbParts['scheme'] ?? 'https');
    $tScheme = strtolower($targetParts['scheme'] ?? 'https');
    $fbPort = isset($fbParts['port']) ? (int)$fbParts['port'] : ($fbScheme === 'https' ? 443 : 80);
    $tPort = isset($targetParts['port']) ? (int)$targetParts['port'] : ($tScheme === 'https' ? 443 : 80);
    if (!empty($fbParts['host']) && !empty($targetParts['host'])
        && strcasecmp($fbParts['host'], $targetParts['host']) === 0 && $fbPort === $tPort) {
        return null;
    }

    $sep = (strpos($fb, '?') === false) ? '?' : '&';
    $fbUrl = $fb . $sep . 'url=' . rawurlencode($url);

    // استخراج کنترل‌هدرها از هدرهای مقصد
    $effectiveUa = $cfg['user_agent'];
    $referer = $cfg['referer'];
    $cookie = null;
    $contentType = null;
    $accept = null;
    $authValue = null;      // Authorization
    $apiKeyValue = null;    // X-API-Key / api-key
    foreach ($headers as $h) {
        if (preg_match('~^User-Agent:\s*(.+)$~i', $h, $m)) $effectiveUa = trim($m[1]);
        elseif (preg_match('~^Referer:\s*(.+)$~i', $h, $m)) $referer = trim($m[1]);
        elseif (preg_match('~^Cookie:\s*(.+)$~i', $h, $m)) $cookie = trim($m[1]);
        elseif (preg_match('~^Content-Type:\s*(.+)$~i', $h, $m)) $contentType = trim($m[1]);
        elseif (preg_match('~^Accept:\s*(.+)$~i', $h, $m)) $accept = trim($m[1]);
        elseif (preg_match('~^Authorization:\s*(.+)$~i', $h, $m)) $authValue = trim($m[1]);
        elseif (preg_match('~^(?:X-API-Key|api-key):\s*(.+)$~i', $h, $m)) $apiKeyValue = trim($m[1]);
    }

    $ctrl = [];
    $ctrl[] = 'X-Proxy-UA: ' . $effectiveUa;
    if ($referer !== '') $ctrl[] = 'X-Proxy-Referer: ' . $referer;
    if ($cookie !== null && $cookie !== '') $ctrl[] = 'X-Proxy-Cookie: ' . $cookie;
    if ($contentType !== null && $contentType !== '') $ctrl[] = 'Content-Type: ' . $contentType;
    if ($accept !== null && $accept !== '') $ctrl[] = 'Accept: ' . $accept;
    // کلید API باید تا مقصد نهایی همراه درخواست بماند — ورکر ما این
    // هدرهای میانی را به Authorization / x-api-key واقعی برمی‌گرداند
    if ($authValue !== null && $authValue !== '') $ctrl[] = 'X-Proxy-Auth: ' . $authValue;
    if ($apiKeyValue !== null && $apiKeyValue !== '') $ctrl[] = 'X-Proxy-Api-Key: ' . $apiKeyValue;
    if (!empty($cfg['fallback_key'])) $ctrl[] = 'X-Proxy-Key: ' . $cfg['fallback_key'];

    $res = p_curl_once($fbUrl, $method, $ctrl, $body, null, $timeout);
    if ($res['error'] !== null) return null; // ورکر هم در دسترس نبود → خطای اصلی را برگردان
    return $res;
}

// ---------------------------------------------------------------------
// [۴] پردازش درخواست پراکسی
// ---------------------------------------------------------------------

/** بررسی کلید محافظت (در صورت فعال بودن) */
function p_check_proxy_key(): void {
    $cfg = $GLOBALS['CONFIG'];
    if ($cfg['proxy_key'] === '') return;
    $key = isset($_GET['key']) ? (string)$_GET['key'] : (string)(p_in_header('X-Proxy-Key') ?? '');
    if (!hash_equals($cfg['proxy_key'], $key)) {
        p_error(401, 'bad_key', 'کلید پراکسی نامعتبر است');
    }
}

/** حالت رله: proxy.php?url=... */
function p_handle_proxy(): void {
    $url = (string)($_GET['url'] ?? '');
    if ($url === '') p_error(400, 'missing_url', 'پارامتر url ارسال نشده است؛ نمونه: ?url=https://example.com');
    p_handle_relay_url($url);
}

/** حالت پراکسی فوروارد: درخواست absolute-form (مقصدهای HTTP) */
function p_handle_forward(string $absoluteUrl): void {
    p_handle_relay_url($absoluteUrl);
}

/** نقطهٔ مشترک هر دو حالت رله — بررسی کلید و اجرا */
function p_handle_relay_url(string $url): void {
    p_check_proxy_key();
    p_relay_request($url);
}

/**
 * پشتیبانی از الگوی مسیری Worker اسکرپر (بدون {url}):
 *   https://host/proxy.php/https://example.com/page
 * اگر بعد از نام اسکریپت یک URL کامل http(s) آمده باشد همان را
 * برمی‌گرداند؛ در غیر این صورت خالی.
 */
function p_path_style_url(): string {
    $uri   = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path  = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
    $query = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
    $self  = (string)($_SERVER['SCRIPT_NAME'] ?? '');

    $rest = '';
    if ($self !== '' && $self !== '/') {
        $prefix = rtrim($self, '/');
        if (strpos($path, $prefix . '/') === 0) {
            $rest = substr($path, strlen($prefix) + 1);
        }
    }
    if ($rest === '') {
        // روتر/فال‌بک: هر مسیری که مستقیم با http(s):// شروع شود
        if (!preg_match('~^/(https?://.+)$~i', $path, $m)) return '';
        $rest = $m[1];
    }
    if (!preg_match('~^https?://~i', $rest)) return '';

    // در حالت مسیری، کوئریِ مقصد به کوئریِ درخواست بیرونی تبدیل می‌شود؛
    // آن را به آدرس مقصد برگردان تا پارامترها (مثل ?page=2) حفظ شوند
    if ($query !== '') {
        $rest .= (strpos($rest, '?') === false ? '?' : '&') . $query;
    }
    return $rest;
}

/** هستهٔ مشترک رله — از هر دو حالت بالا استفاده می‌شود */
function p_relay_request(string $url): void {
    $cfg = $GLOBALS['CONFIG'];

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
        p_error(405, 'bad_method', "متد {$method} پشتیبانی نمی‌شود");
    }

    // تایم‌اوت سفارشی
    $timeout = (int)$cfg['timeout'];
    $t = p_in_header('X-Proxy-Time');
    if ($t !== null && ctype_digit(trim($t))) {
        $timeout = max(1, min(300, (int)trim($t)));
    }

    // بدنهٔ درخواست ورودی
    $body = '';
    $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($cl > $cfg['max_body_size']) p_error(413, 'body_too_large', 'حجم بدنهٔ درخواست بیش از حد مجاز است');
    if ($cl > 0) $body = (string)file_get_contents('php://input');

    // هدرهای قابل ارسال به مقصد
    $forward = [];
    foreach (['Accept', 'Accept-Language', 'Content-Type', 'Range', 'If-None-Match', 'If-Modified-Since'] as $hd) {
        $v = p_in_header($hd);
        if ($v !== null && $v !== '') $forward[] = $hd . ': ' . $v;
    }
    if ($cfg['forward_auth']) {
        foreach ((array)$cfg['forward_auth_headers'] as $authHeader) {
            $v = p_in_header((string)$authHeader);
            if ($v !== null && $v !== '') $forward[] = $authHeader . ': ' . $v;
        }
    }
    foreach (['X-Proxy-UA' => 'User-Agent', 'X-Proxy-Referer' => 'Referer', 'X-Proxy-Cookie' => 'Cookie'] as $from => $to) {
        $v = p_in_header($from);
        if ($v !== null && $v !== '') $forward[] = $to . ': ' . $v;
    }

    // اعتبارسنجی اولیه
    p_validate_url($url);
    $current = $url;
    $final = $current;

    // کش (فقط GET/HEAD)
    $cacheKey = sha1($method . "\n" . $current . "\n" . $body);
    $cached = ($method === 'GET' || $method === 'HEAD') ? p_cache_get($cacheKey) : null;
    if ($cached !== null) {
        p_emit_response($cached['status'], $cached['headers'], $cached['body'], $final, true);
    }

    // دنبال‌کردن ریدایرکت‌ها به‌صورت دستی (با اعتبارسنجی هر پرش)
    $result = null;
    for ($hop = 0; $hop <= (int)$cfg['max_redirects']; $hop++) {
        $v = p_validate_url($current); // دامنه + IP هر پرش دوباره چک می‌شود
        if (($v['dns'] ?? 'ok') === 'filtered') {
            // DNS مسموم/فیلتر (مثل facebook.com روی هاست ایرانی):
            // اتصال مستقیم به IP فیلترینگ می‌خورد → مستقیم سراغ مسیر جایگزین
            $result = p_filtered_attempt($current, $method, $forward, $body, $timeout);
            if ($result['error'] !== null) {
                p_error($result['status'], 'dns_filtered', $result['error']);
            }
        } else {
            $result = p_rotate_attempt($current, $method, $forward, $body, $timeout);
            if ($result['error'] !== null) {
                p_error($result['status'], 'upstream_failed', $result['error']);
            }
        }
        $status = $result['status'];
        if ($status >= 300 && $status < 400 && !empty($result['headers']['location'])) {
            $loc = end($result['headers']['location']);
            if ($loc === '' || $loc === false) p_error(502, 'bad_redirect', 'هدر Location ریدایرکت خالی است');
            if ($status === 303 && $method !== 'GET' && $method !== 'HEAD') {
                $method = 'GET';
                $body = '';
                $forward = [];
            }
            $current = $loc;
            $final = $current;
            continue;
        }
        $final = $current;
        break;
    }
    if ($result === null || $result['error'] !== null) {
        p_error(502, 'too_many_redirects', 'تعداد ریدایرکت‌ها بیش از حد مجاز است');
    }

    // تزریق <base> برای HTML (اگر زنجیرهٔ فالبک قبلاً تزریق نکرده باشد)
    $ct = strtolower(implode(' ', $result['headers']['content-type'] ?? []));
    if ($cfg['inject_base'] && strpos($ct, 'text/html') !== false && $result['body'] !== ''
        && strpos($result['body'], 'data-proxy-base') === false) {
        $result['body'] = p_inject_base($result['body'], $final);
    }

    if ($method === 'GET' && $result['status'] >= 200 && $result['status'] < 400) {
        p_cache_set($cacheKey, $result['status'], $result['headers'], $result['body']);
    }

    p_emit_response($result['status'], $result['headers'], $result['body'], $final, false);
}

/** ارسال پاسخ نهایی به کلاینت */
function p_emit_response(int $status, array $headers, string $body, string $finalUrl, bool $fromCache): void {
    // CORS
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Max-Age: 86400');
    header('Access-Control-Expose-Headers: X-Proxy-Final-Url, X-Proxy-Cache, X-Proxy-Final-Status');
    header('X-Proxy-Final-Url: ' . $finalUrl);
    header('X-Proxy-Cache: ' . ($fromCache ? 'HIT' : 'MISS'));
    header('X-Proxy-Final-Status: ' . $status);

    http_response_code($status);
    $skip = ['content-length', 'content-encoding', 'transfer-encoding', 'connection', 'keep-alive'];
    $sent = [];
    foreach ($headers as $name => $values) {
        $name = strtolower($name);
        if (in_array($name, $skip, true)) continue;
        foreach ($values as $v) {
            if ($name === 'set-cookie') {
                header("Set-Cookie: {$v}", false); // چند کوکی مجاز است
            } elseif (!in_array($name, $sent, true)) {
                header("{$name}: {$v}");
                $sent[] = $name;
            }
        }
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

// ---------------------------------------------------------------------
// [۴-ب] حالت پراکسی فوروارد (CONNECT + absolute-form)
// ---------------------------------------------------------------------

/**
 * آیا درخواست به شکل absolute-form رسیده؟
 * این شکلِ استاندارد پراکسی فوروارد برای مقصدهای HTTP است:
 *   GET http://example.com/page HTTP/1.1
 */
function p_absolute_form_target(): string {
    $ru = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('~^https?://~i', $ru)) return $ru;
    return '';
}

/** احراز هویت تونل CONNECT با Proxy-Authorization یا X-Proxy-Key */
function p_tunnel_key_ok(): bool {
    $cfg = $GLOBALS['CONFIG'];
    if ($cfg['proxy_key'] === '') return true;
    $pa = (string)(p_in_header('Proxy-Authorization') ?? '');
    if (preg_match('~^Basic\s+(.+)$~i', trim($pa), $m)) {
        $dec = base64_decode(trim($m[1]), true);
        if ($dec !== false) {
            $parts = explode(':', $dec, 2);
            $user = $parts[0] ?? '';
            if (hash_equals($cfg['proxy_key'], $user)) return true;
        }
    }
    $xk = (string)(p_in_header('X-Proxy-Key') ?? '');
    return $xk !== '' && hash_equals($cfg['proxy_key'], $xk);
}

/**
 * تونل CONNECT — پراکسی فوروارد برای مقصدهای HTTPS.
 *
 * ⚠️ این مسیر فقط وقتی کار می‌کند که درخواست CONNECT به خودِ PHP برسد.
 * در Apache/LiteSpeed این دو خط را در .htaccess کنار proxy.php بگذارید:
 *
 *     RewriteEngine On
 *     RewriteCond %{REQUEST_METHOD} ^CONNECT$
 *     RewriteRule ^(.*)$ /proxy.php?__connect__=$1 [L]
 *
 * (در nginx/php-fpm معمولاً CONNECT به PHP نمی‌رسد؛ برای آن حالت‌ها از
 *  حالت رلهٔ ?url= یا فیلد Worker اسکرپر استفاده کنید.)
 */
function p_connect_tunnel(): void {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['connect_enabled'])) {
        p_error(405, 'connect_disabled', 'CONNECT در تنظیمات غیرفعال است');
    }

    if (!p_tunnel_key_ok()) {
        http_response_code(407);
        header('Proxy-Authenticate: Basic realm="php-single-file-proxy"');
        header('Content-Type: text/plain; charset=utf-8');
        echo '407 Proxy Authentication Required';
        exit;
    }

    // مقصد: CONNECT host:port  یا  [ipv6]:port
    $raw = trim((string)($_GET['__connect__'] ?? $_SERVER['REQUEST_URI'] ?? ''));
    if ($raw !== '' && $raw[0] === '/') $raw = ltrim($raw, '/');
    if (!preg_match('~^(?:\[([^\]]+)\]|([^:\s/]+)):(\d{1,5})$~', $raw, $m)) {
        p_error(400, 'bad_connect_target', 'قالب CONNECT باید host:port باشد');
    }
    $host = strtolower(trim($m[1] !== '' ? $m[1] : $m[2], '[]'));
    $port = (int)$m[3];
    if ($host === '' || $port < 1 || $port > 65535) {
        p_error(400, 'bad_connect_target', 'قالب CONNECT باید host:port باشد');
    }

    // همان سیاست‌های امنیتی حالت رله
    p_check_domain($host);
    if (!$cfg['allow_private_ips']) p_check_ips($host);

    set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_clean();

    $remote = null;
    $via = 'direct';

    // زنجیره: اگر پراکسی بالادستی http تنظیم شده، اول از آن تونل بزن
    if ($cfg['rotate_upstream']) {
        foreach ((array)$cfg['upstream_proxies'] as $upStr) {
            $u = parse_url((string)$upStr);
            if (!is_array($u) || empty($u['host']) || (strtolower($u['scheme'] ?? 'http') !== 'http')) continue;
            $uh = $u['host'];
            $uport = (int)($u['port'] ?? 80);
            $sock = @stream_socket_client("tcp://{$uh}:{$uport}", $eno, $estr, (int)$cfg['connect_timeout']);
            if (!$sock) continue;
            stream_set_timeout($sock, 15);
            $auth = '';
            if (!empty($u['user'])) {
                $auth = 'Proxy-Authorization: Basic '
                      . base64_encode(rawurldecode($u['user']) . ':' . rawurldecode($u['pass'] ?? '')) . "\r\n";
            }
            $req = "CONNECT {$host}:{$port} HTTP/1.1\r\nHost: {$host}:{$port}\r\n{$auth}\r\n";
            @fwrite($sock, $req);
            $resp = '';
            $deadline = microtime(true) + 15;
            while (!feof($sock) && strpos($resp, "\r\n\r\n") === false && microtime(true) < $deadline) {
                $piece = @fread($sock, 1024);
                if ($piece === false || $piece === '') { usleep(20000); continue; }
                $resp .= $piece;
            }
            if (preg_match('~^HTTP/\S+\s+2\d\d~', $resp)) {
                $remote = $sock;
                $via = 'upstream';
                break;
            }
            @fclose($sock);
        }
    }

    if ($remote === null) {
        $remote = @stream_socket_client("tcp://{$host}:{$port}", $eno, $estr, (int)$cfg['connect_timeout']);
        if (!$remote) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo '502 Tunnel failed: ' . ($estr !== '' ? $estr : "cannot connect to {$host}:{$port}");
            exit;
        }
        $via = 'direct';
    }

    // پاسخ موفقیت تونل — از این پس بایت‌ها خام پمپ می‌شوند
    header('HTTP/1.1 200 Connection Established');
    header('X-Tunnel-Via: ' . $via);
    header('Proxy-Agent: php-single-file-proxy/' . PROXY_VERSION);
    header('Connection: keep-alive');
    @ob_end_flush();
    flush();

    $chunk = 65536;
    $maxIdle = max(10, (int)($cfg['tunnel_idle_timeout'] ?? 120));
    stream_set_timeout($remote, 30);
    stream_set_blocking($remote, false);

    $in = @fopen('php://input', 'rb');
    if ($in) @stream_set_blocking($in, false);

    $idle = 0.0;
    $last = microtime(true);
    $running = true;

    while ($running) {
        if (connection_aborted()) break;
        $now = microtime(true);
        $idle += ($now - $last);
        $last = $now;
        if ($idle > $maxIdle) break;

        $busy = false;

        // ۱) مقصد → کلاینت
        $r = [$remote]; $w = null; $e = null;
        $n = @stream_select($r, $w, $e, 0, 50000);
        if ($n === false) break;
        if ($n > 0) {
            $out = @fread($remote, $chunk);
            if ($out !== false && $out !== '') {
                echo $out;
                flush();
                $busy = true;
            } elseif (feof($remote)) {
                break;
            }
        }

        // ۲) کلاینت → مقصد
        if ($in) {
            $data = @fread($in, $chunk);
            if ($data !== false && $data !== '') {
                $off = 0;
                $len = strlen($data);
                $giveup = microtime(true) + 10;
                while ($off < $len) {
                    $wrote = @fwrite($remote, substr($data, $off));
                    if ($wrote === false || $wrote === 0) {
                        if (microtime(true) > $giveup) { $running = false; break; }
                        usleep(10000);
                        continue;
                    }
                    $off += $wrote;
                }
                $busy = true;
            } elseif (feof($in)) {
                // بدنهٔ درخواست تمام شد — ممکن است کلاینت هنوز برای پاسخ صبر کند
                fclose($in);
                $in = null;
            }
        }

        if ($busy) $idle = 0.0;
        else usleep(10000);
    }

    if ($in) fclose($in);
    if (is_resource($remote)) fclose($remote);
    exit;
}

// ---------------------------------------------------------------------
// [۵] داشبورد راهنما
// ---------------------------------------------------------------------

function p_dashboard(): void {
    $cfg = $GLOBALS['CONFIG'];
    $ver = PROXY_VERSION;
    $curlOk = function_exists('curl_init');
    $cacheDir = $cfg['cache_dir'];
    $cacheState = !$cfg['cache_enabled'] ? 'غیرفعال' : ((is_dir($cacheDir) && is_writable($cacheDir)) || @mkdir($cacheDir, 0755, true) ? 'فعال' : 'بدون دسترسی');
    $upstreamCount = count($cfg['upstream_proxies']);
    $allowedCount = count($cfg['allowed_domains']);
    $blockedCount = count($cfg['blocked_domains']);
    $keyState = $cfg['proxy_key'] === '' ? 'بدون کلید' : 'فعال';
    $phpVersion = PHP_VERSION;
    $fallbackState = p_fallback_url($cfg) === '' ? 'غیرفعال' : 'فعال';
    $fallbackHost = '—';
    $fbParts = parse_url(p_fallback_url($cfg));
    if (!empty($fbParts['host'])) $fallbackHost = h($fbParts['host']);

    $statusHtml = "<div class='cards'>"
        . "<div class='card'><div class='card-v'>" . h($phpVersion) . "</div><div class='card-l'>نسخهٔ PHP</div></div>"
        . "<div class='card'><div class='card-v " . ($curlOk ? 'ok' : 'bad') . "'>" . ($curlOk ? 'فعال' : 'غیرفعال!') . "</div><div class='card-l'>cURL</div></div>"
        . "<div class='card'><div class='card-v'>" . $upstreamCount . "</div><div class='card-l'>پراکسی بالادستی</div></div>"
        . "<div class='card'><div class='card-v " . ($fallbackState === 'فعال' ? 'ok' : '') . "' title=\"" . $fallbackHost . "\">" . h($fallbackState) . "</div><div class='card-l'>فالبک کلودفلر</div></div>"
        . "<div class='card'><div class='card-v'>" . h($cacheState) . "</div><div class='card-l'>کش</div></div>"
        . "<div class='card'><div class='card-v'>" . h($keyState) . "</div><div class='card-l'>کلید محافظت</div></div>"
        . "<div class='card'><div class='card-v'>" . $allowedCount . ' / ' . $blockedCount . "</div><div class='card-l'>سفید / سیاه</div></div>"
        . "</div>";

    echo <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پراکسی سرور</title>
<style>
:root { --bg:#0f1420; --card:#171e2e; --line:#232c42; --txt:#e8ecf5; --mut:#8b94ad; --acc:#3d8bff; --ok:#2ecc71; --bad:#ff5c5c; }
* { box-sizing:border-box; }
body { margin:0; font-family:'Vazirmatn',Tahoma,sans-serif; background:var(--bg); color:var(--txt); line-height:1.8; }
.wrap { max-width:900px; margin:0 auto; padding:24px 16px 64px; }
h1 { font-size:1.5rem; margin:0 0 4px; }
h1 span { color:var(--acc); }
.sub { color:var(--mut); font-size:.9rem; margin-bottom:24px; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:28px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px; text-align:center; }
.card-v { font-size:1.15rem; font-weight:700; }
.card-v.ok { color:var(--ok); } .card-v.bad { color:var(--bad); }
.card-l { color:var(--mut); font-size:.78rem; }
h2 { font-size:1.05rem; margin:28px 0 10px; border-bottom:1px solid var(--line); padding-bottom:8px; }
code, pre { direction:ltr; background:#0b0f1a; border:1px solid var(--line); border-radius:8px; font-family:Consolas,monospace; }
code { padding:2px 7px; font-size:.85rem; color:#9fd0ff; }
pre { padding:12px 14px; overflow-x:auto; font-size:.82rem; margin:8px 0 16px; }
.testbox { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.testbox input { flex:1; min-width:240px; background:#0b0f1a; border:1px solid var(--line); color:var(--txt); border-radius:8px; padding:10px 12px; direction:ltr; font-family:Consolas,monospace; }
button { background:var(--acc); color:#fff; border:0; border-radius:8px; padding:10px 18px; cursor:pointer; font-family:inherit; }
button:hover { filter:brightness(1.1); }
#result { margin-top:14px; }
#result pre { max-height:320px; overflow:auto; white-space:pre-wrap; word-break:break-all; }
.meta { color:var(--mut); font-size:.82rem; }
iframe { width:100%; height:420px; border:1px solid var(--line); border-radius:10px; background:#fff; margin-top:10px; }
ul { padding-right:20px; }
li { margin:4px 0; }
a { color:var(--acc); }
</style>
</head>
<body>
<div class="wrap">
<h1>🛰️ پراکسی <span>سرور</span></h1>
<div class="sub">نسخهٔ {$ver} — تک‌فایلی PHP، بدون وابستگی — پاسخ‌ها را از سمت سرور می‌گیرد تا IP و ساختار درخواست شما مخفی بماند.</div>
{$statusHtml}

<h2>🧪 تست سریع</h2>
<div class="testbox">
<input id="u" placeholder="https://example.com/page" value="https://registry.npmjs.org/express">
<button onclick="run()">دریافت از طریق پراکسی</button>
</div>
<div id="result"></div>

<h2>📡 روش استفاده</h2>
<p>کافیست پارامتر <code>url</code> را بدهید؛ متد و بدنهٔ درخواست شما عیناً به مقصد ارسال می‌شود:</p>
<pre>https://your-server.com/proxy.php?url=https://example.com/page</pre>
<p>استفاده از داخل اسکرپر (فیلد «Worker») — بدون نیاز به هیچ تنظیم سروری:</p>
<pre>https://your-server.com/proxy.php?url={url}</pre>
<p>الگوی مسیری هم پشتیبانی می‌شود (بدون {url}):</p>
<pre>https://your-server.com/proxy.php/https://example.com/page</pre>
<p>تست اتصال شبکه به یک مقصد از خود سرور (بدون کلید — گزارش هر پرش زنجیره):</p>
<pre>https://your-server.com/proxy.php?selftest=https%3A%2F%2Fapi.groq.com%2Fopenai%2Fv1%2Fmodels</pre>
<p>مثال با جاوااسکریپت (اسکرپر سمت مرورگر):</p>
<pre>fetch('https://your-server.com/proxy.php?url=' + encodeURIComponent(target))
  .then(r =&gt; r.text())
  .then(html =&gt; console.log(html));</pre>
<p>مثال با PHP (از داخل scraper.php):</p>
<pre>&dollar;html = file_get_contents('https://your-server.com/proxy.php?url=' . urlencode(&dollar;target));</pre>
<p>ارسال POST با بدنه:</p>
<pre>fetch('https://your-server.com/proxy.php?url=' + encodeURIComponent(api), {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({a: 1})
});</pre>

<h2>🎛️ هدرهای کنترلی</h2>
<ul>
<li><code>X-Proxy-UA</code> — تغییر User-Agent ارسالی به مقصد</li>
<li><code>X-Proxy-Referer</code> — تغییر Referer</li>
<li><code>X-Proxy-Cookie</code> — ارسال کوکی به مقصد</li>
<li><code>X-Proxy-Time</code> — تایم‌اوت سفارشی (ثانیه، حداکثر ۳۰۰)</li>
<li><code>X-Proxy-Key</code> — کلید محافظت (اگر در تنظیمات فعال باشد)</li>
</ul>
<p>هدرهای پاسخ پراکسی: <code>X-Proxy-Final-Url</code> (آدرس نهایی پس از ریدایرکت‌ها)، <code>X-Proxy-Final-Status</code> و <code>X-Proxy-Cache</code>.</p>

<h2>🛡️ امنیت و امکانات</h2>
<ul>
<li>محافظ SSRF — اتصال به IPهای داخلی/خصوصی مسدود است (در تنظیمات قابل تغییر)</li>
<li>لیست سفید/سیاه دامنه‌ها با پشتیبانی از الگوی <code>*.domain.com</code></li>
<li>چرخش خودکار بین پراکسی‌های بالادستی (http و socks5) و تلاش مجدد روی خطاهای ۴۰۳/۴۲۹/۵۰۳</li>
<li>فالبک خودکار به ورکر کلودفلر در صورت شکست اتصال به مقصد (قابل تغییر در تنظیمات)</li>
<li>شناسایی DNS مسموم/فیلترشده (مثل facebook.com روی هاست ایرانی) و عبور خودکار از ورکر کلودفلر یا پراکسی بالادستی</li>
<li>انتقال کامل کلید API (Authorization / x-api-key) به مقصد — حتی از مسیر فالبک ورکر — برای تست مدل‌های هوش مصنوعی</li>
<li>حالت پراکسی فوروارد: CONNECT برای HTTPS و absolute-form برای HTTP — قابل استفاده در فیلد پروکسی اسکرپر</li>
<li>سازگار با فیلد «Worker» اسکرپر: الگوی <code>?url={url}</code> و حالت مسیری، بدون هیچ تنظیم سروری</li>
<li>ریدایرکت‌ها به‌صورت امن دنبال می‌شوند و هر پرش دوباره اعتبارسنجی می‌شود</li>
<li>رمزگشایی خودکار gzip / deflate / brotli — کش فایلی اختیاری — تزریق <code>&lt;base&gt;</code> برای لینک‌های نسبی</li>
</ul>
</div>
<script>
function run() {
  var u = document.getElementById('u').value.trim();
  var out = document.getElementById('result');
  if (!u) { out.innerHTML = ''; return; }
  out.innerHTML = '<div class="meta">در حال دریافت…</div>';
  fetch('?url=' + encodeURIComponent(u))
    .then(function (r) {
      var info = 'وضعیت: ' + r.status + ' | نوع محتوا: ' + (r.headers.get('content-type') || '—') +
                 ' | آدرس نهایی: ' + (r.headers.get('x-proxy-final-url') || '—') +
                 ' | کش: ' + (r.headers.get('x-proxy-cache') || '—');
      var ct = (r.headers.get('content-type') || '').toLowerCase();
      if (ct.indexOf('text/html') !== -1) {
        return r.text().then(function (t) {
          out.innerHTML = '<div class="meta">' + info + '</div>' +
            '<iframe srcdoc="' + t.replace(/"/g, '&quot;') + '"></iframe>' +
            '<details><summary>مشاهدهٔ HTML خام</summary><pre>' + t.replace(/</g, '&lt;') + '</pre></details>';
        });
      }
      return r.text().then(function (t) {
        out.innerHTML = '<div class="meta">' + info + '</div><pre>' + t.replace(/</g, '&lt;') + '</pre>';
      });
    })
    .catch(function (e) {
      out.innerHTML = '<div class="meta">خطا: ' + e + '</div>';
    });
}
</script>
</body>
</html>
HTML;
    exit;
}

/**
 * تست اتصال شبکه به یک مقصد از خود سرور — بدون کلید و بدون بدنه.
 * هر پرش زنجیره (مستقیم ← بالادستی ← ورکر) جداگانه گزارش می‌شود تا
 * معلوم شود کدام لایه کار می‌کند. «موفق» یعنی پاسخی از مقصد برگشته
 * (حتی 401/403) — یعنی شبکه برقرار است.
 */
function p_selftest(): void {
    $cfg = $GLOBALS['CONFIG'];
    $target = (string)($_GET['selftest'] ?? '');
    if ($target === '') p_error(400, 'missing_target', 'پارامتر selftest خالی است؛ نمونه: ?selftest=https%3A%2F%2Fapi.groq.com%2Fv1%2Fmodels');

    $v = p_validate_url($target); // آدرس نامعتبر/مسدود با JSON خارج می‌شود
    $timeout = max(5, min(20, (int)$cfg['timeout']));
    $headers = ['Accept: */*'];
    $attempts = [];
    $ok = false;
    $result = 'none';

    $try = function (string $via, ?array $res, float $t0) use (&$attempts, &$ok, &$result) {
        $attempts[] = [
            'via'    => $via,
            'status' => $res !== null ? $res['status'] : null,
            'error'  => $res !== null ? $res['error'] : null,
            'ms'     => (int)round((microtime(true) - $t0) * 1000),
        ];
        if ($res !== null && $res['error'] === null) { // هر پاسخ HTTP = شبکه برقرار است
            $ok = true;
            $result = $via;
            return true;
        }
        return false;
    };

    if (($v['dns'] ?? 'ok') === 'ok') {
        $t0 = microtime(true);
        $try('direct', p_curl_once($target, 'GET', $headers, '', null, $timeout), $t0);
    }
    if (!$ok && $cfg['rotate_upstream']) {
        foreach ((array)$cfg['upstream_proxies'] as $px) {
            if ($px === null || $px === '') continue;
            $t0 = microtime(true);
            if ($try('upstream', p_curl_once($target, 'GET', $headers, '', $px, $timeout), $t0)) break;
        }
    }
    if (!$ok) {
        $t0 = microtime(true);
        $fb = p_fallback_attempt($target, 'GET', $headers, '', $timeout);
        if ($fb === null) {
            $attempts[] = ['via' => 'worker', 'status' => null, 'error' => 'فالبک تنظیم نشده یا همان مقصد است', 'ms' => (int)round((microtime(true) - $t0) * 1000)];
        } else {
            $try('worker', $fb, $t0);
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'       => $ok,
        'target'   => $target,
        'policy'   => $v['dns'] ?? 'ok',
        'result'   => $result,
        'attempts' => $attempts,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function p_info(): void {
    $cfg = $GLOBALS['CONFIG'];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'               => true,
        'name'             => 'php-single-file-proxy',
        'version'          => PROXY_VERSION,
        'build'            => PROXY_BUILD,
        'php'              => PHP_VERSION,
        'curl'             => function_exists('curl_init'),
        'cache_enabled'    => (bool)$cfg['cache_enabled'],
        'upstream_count'   => count($cfg['upstream_proxies']),
        'allowed_count'    => count($cfg['allowed_domains']),
        'blocked_count'    => count($cfg['blocked_domains']),
        'private_ips'      => (bool)$cfg['allow_private_ips'],
        'auth_required'    => $cfg['proxy_key'] !== '',
        'connect_enabled'  => (bool)($cfg['connect_enabled'] ?? true),
        'fallback_worker'  => p_fallback_url($cfg) !== '' ? p_fallback_url($cfg) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------
// [۶] نقطهٔ ورود
// ---------------------------------------------------------------------

// حالت روتر php -S: فایل‌های استاتیک موجود را مستقیم سرو کن
// (فقط مسیرهای محلی؛ درخواست‌های absolute-form پراکسی فوروارد را رد نمی‌کنیم)
if (PHP_SAPI === 'cli-server' && strpos((string)($_SERVER['REQUEST_URI'] ?? ''), 'http') !== 0) {
    $staticPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($staticPath !== '/' && $staticPath !== '/proxy.php' && $staticPath !== '/index.php'
        && is_file(__DIR__ . $staticPath)) {
        return false;
    }
}

$pReqMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($pReqMethod === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// پراکسی فوروارد: CONNECT برای مقصدهای HTTPS
if ($pReqMethod === 'CONNECT') {
    p_connect_tunnel();
}

// پراکسی فوروارد: درخواست absolute-form برای مقصدهای HTTP
// (مثل: GET http://example.com/page HTTP/1.1)
$pAbsolute = p_absolute_form_target();
if ($pAbsolute !== '') {
    p_handle_forward($pAbsolute);
}

if (isset($_GET['info'])) {
    p_info();
}

if (isset($_GET['selftest'])) {
    p_selftest();
}

if (isset($_GET['url'])) {
    p_handle_proxy();
}

// حالت رلهٔ مسیری: /proxy.php/https://example.com/page
// (الگوی Worker اسکرپر بدون {url})
$pPathUrl = p_path_style_url();
if ($pPathUrl !== '') {
    p_handle_relay_url($pPathUrl);
}

p_dashboard();
