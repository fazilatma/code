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
 *    X-Proxy-Rewrite  → 1/0 — فعال/غیرفعال‌کردن بازنویسی URL برای همین درخواست
 *
 *  رندر کامل صفحه از طریق پراکسی:
 *    به‌صورت پیش‌فرض فقط <base> تزریق می‌شود؛ یعنی مرورگر تصاویر/CSS/JS را
 *    مستقیم از خود مقصد می‌گیرد و اگر مقصد برای کاربر بلاک باشد، لود نمی‌شوند.
 *    با 'rewrite_urls' => true (یا هدر X-Proxy-Rewrite: 1) همهٔ URLهای صفحه
 *    (src/href/srcset/style/... و url داخل CSS) به سمت خود پراکسی بازنویسی
 *    می‌شوند تا کل صفحه — ساختار و تصاویر — از مسیر پراکسی رندر شود.
 *
 *  تنظیمات از داشبورد (proxy-settings.json در کنار فایل):
 *    آدرس ورکر کلودفلر و وضعیت بازنویسی URL از خود داشبورد قابل تغییرند؛
 *    اولویت آن‌ها بالاتر از مقادیر داخل $CONFIG است.
 *
 *  مسیر عبور هر پاسخ در هدر X-Proxy-Route گزارش می‌شود:
 *    direct (مستقیم) | upstream (پراکسی بالادستی) | worker (ورکر کلودفلر) | cache
 *
 *  فالبک ورکر کلودفلر:
 *    اگر اتصال به مقصد ناموفق باشد، درخواست از طریق cloudflare_worker_url
 *    (پیش‌فرض: https://proxy.fazilat-ma.workers.dev — اولین تنظیم در
 *    $CONFIG) رله می‌شود. علاوه بر خطاهای اتصال، وضعیت‌های
 *    fallback_on_statuses (پیش‌فرض: 403 — معمولاً به معنای ممنوع‌بودن
 *    IP هاست برای مقصد) هم پس از اتمام زنجیرهٔ مستقیم/بالادستی از ورکر
 *    عبور داده می‌شوند. ورکر باید همان API پارامتر ?url= را داشته
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
    'fallback_on_statuses' => [403],             // اگر مقصد این وضعیت‌ها را داد از فالبک ورکر استفاده کن؛ 403 معمولاً یعنی IP هاست برای مقصد ممنوع است. نمونهٔ گسترده: [403, 451]
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
    // بازنویسی کامل URLها: وقتی روشن باشد، همهٔ منابع (تصاویر، CSS، JS، لینک‌ها)
    // هم از خود پراکسی لود می‌شوند و برای سایت‌های بلاک‌شده صفحه کامل رندر می‌شود.
    // (برای اسکرپر اگر آدرس‌های اصلی لازم است، با هدر X-Proxy-Rewrite: 0 خاموش کنید.)
    'rewrite_urls'      => true,
    'rewrite_attrs'     => ['src', 'href', 'srcset', 'poster', 'data-src', 'data-lazy-src', 'data-original', 'data-bg', 'action', 'formaction'],
    'forward_auth'      => true,                  // ارسال هدر کلید API به مقصد — برای تست مدل‌های هوش مصنوعی لازم است؛ اگر لازم شد خاموش کنید
    'forward_auth_headers' => ['Authorization', 'X-API-Key', 'api-key'], // هدرهای احراز هویتی که به مقصد ارسال می‌شوند
    'cache_enabled'     => false,                 // کش فایلی پاسخ‌ها
    'cache_ttl'         => 120,                   // مدت اعتبار کش (ثانیه)
    'cache_dir'         => __DIR__ . '/proxy-cache',
    'connect_enabled'   => true,                  // پاسخ به CONNECT (پراکسی فوروارد برای HTTPS) — اگر درخواست به PHP برسد
    'tunnel_idle_timeout' => 120,                 // سقف بیکاری تونل CONNECT (ثانیه)
];

define('PROXY_VERSION', '1.2.8');
define('PROXY_BUILD', '2026-08-18-08');

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
// [۱-ب] تنظیمات ذخیره‌شده (از داشبورد قابل تغییر است)
// proxy-settings.json در کنار فایل ساخته می‌شود و اولویت آن بالاتر از
// مقادیر داخل $CONFIG است؛ با حذف فایل، به تنظیمات کد برمی‌گردید.
// ---------------------------------------------------------------------

const PROXY_SETTINGS_FILE = __DIR__ . '/proxy-settings.json';

/** خواندن تنظیمات ذخیره‌شده؛ null یعنی «ذخیره نشده → از $CONFIG» */
function p_load_settings(): array {
    $out = ['cloudflare_worker_url' => null, 'rewrite_urls' => null];
    if (!is_file(PROXY_SETTINGS_FILE)) return $out;
    $j = @json_decode((string)@file_get_contents(PROXY_SETTINGS_FILE), true);
    if (!is_array($j)) return $out;
    if (array_key_exists('cloudflare_worker_url', $j)) $out['cloudflare_worker_url'] = trim((string)$j['cloudflare_worker_url']);
    if (array_key_exists('rewrite_urls', $j)) $out['rewrite_urls'] = (bool)$j['rewrite_urls'];
    return $out;
}

/** آدرس مؤثر ورکر کلودفلر: تنظیمات داشبورد ← $CONFIG ← خالی */
function p_effective_worker_url(): string {
    $s = p_load_settings();
    if ($s['cloudflare_worker_url'] !== null) return $s['cloudflare_worker_url'];
    $cfg = $GLOBALS['CONFIG'];
    $fb = trim((string)($cfg['cloudflare_worker_url'] ?? ''));
    if ($fb === '') $fb = trim((string)($cfg['fallback_proxy'] ?? ''));
    return $fb;
}

/** آیا بازنویسی URL مؤثر است؟ تنظیمات داشبورد ← $CONFIG (هدر X-Proxy-Rewrite هم می‌تواند override کند) */
function p_effective_rewrite(): bool {
    $s = p_load_settings();
    if ($s['rewrite_urls'] !== null) return $s['rewrite_urls'];
    return !empty($GLOBALS['CONFIG']['rewrite_urls']);
}

/** ذخیرهٔ تنظیمات داشبورد */
function p_save_settings(string $workerUrl, bool $rewrite): array {
    $cur = p_load_settings();
    $cur['cloudflare_worker_url'] = trim($workerUrl);
    $cur['rewrite_urls'] = $rewrite;
    $ok = @file_put_contents(PROXY_SETTINGS_FILE, json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    return ['ok' => $ok];
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

/* ---------------------------------------------------------------------
 * بازنویسی URL — رندر کامل صفحه از طریق خود پراکسی
 * --------------------------------------------------------------------- */

/** آدرس پایهٔ خود پراکسی (برای بازنویسی لینک‌ها به سمت همین اسکریپت) */
function p_proxy_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if ($host === '') $host = 'localhost';
    $self = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/proxy.php'));
    if ($self === '' || $self === '/') $self = '/proxy.php';
    $dir = rtrim(str_replace('\\', '/', dirname($self)), '/');
    return $scheme . '://' . $host . ($dir === '' ? '' : $dir) . '/' . basename($self);
}

/** آیا بازنویسی برای این درخواست فعال است؟ (تنظیم + هدر کنترل X-Proxy-Rewrite) */
function p_rewrite_enabled(): bool {
    $rw = p_effective_rewrite();
    $h = p_in_header('X-Proxy-Rewrite');
    if ($h !== null) $rw = trim((string)$h) === '1';
    return $rw;
}

/** بازنویسی یک URL منفرد به آدرس پراکسی (در صورت نیاز) */
function p_rewrite_url(string $url, string $pageUrl, string $proxyBase): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') return $url;
    if (preg_match('~^(data:|javascript:|mailto:|tel:|blob:|about:|chrome-extension:|#)~i', $url)) return $url;
    $abs = p_absolute_url($url, $pageUrl);
    if ($abs === '') return $url;
    // از قبل پراکسی‌شده؟ دور دوم اعمال نکن
    if (stripos($abs, $proxyBase . '?url=') === 0) return $abs;
    return $proxyBase . '?url=' . rawurlencode($abs);
}

/** بازنویسی srcset (هر نامزد = «آدرس [توضیح…]») */
function p_rewrite_srcset(string $val, string $pageUrl, string $proxyBase): string {
    $parts = preg_split('~\s*,\s*~', $val);
    foreach ($parts as $i => $part) {
        if (preg_match('~^(\S+)(.*)$~s', $part, $m)) {
            $parts[$i] = p_rewrite_url($m[1], $pageUrl, $proxyBase) . $m[2];
        }
    }
    return implode(', ', $parts);
}

/** بازنویسی url(...) داخل CSS خام */
function p_rewrite_css(string $css, string $cssUrl, string $proxyBase): string {
    return preg_replace_callback('~url\(\s*(["\']?)([^)"\']+)\1\s*\)~i', function ($m) use ($cssUrl, $proxyBase) {
        return 'url("' . p_rewrite_url($m[2], $cssUrl, $proxyBase) . '")';
    }, $css);
}

/**
 * بازنویسی کامل HTML:
 *   - حذف <base>های موجود و گذاشتن base به سمت خود پراکسی (برای درخواست‌های JS)
 *   - بازنویسی src/href/srcset/... به ?url= پراکسی
 *   - بازنویسی url(...) داخل style های inline
 */
function p_rewrite_html(string $html, string $pageUrl, string $proxyBase): string {
    $html = preg_replace('~<base\b[^>]*>~i', '', $html);
    $base = '<base href="' . htmlspecialchars($proxyBase . '?url=' . rawurlencode($pageUrl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" data-proxy-base="1">';
    if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $pos) . $base . substr($html, $pos);
    } else {
        $html = $base . $html;
    }

    $attrs = (array)($GLOBALS['CONFIG']['rewrite_attrs'] ?? ['src', 'href', 'srcset', 'poster', 'data-src']);
    $pattern = '~\s(' . implode('|', array_map(function ($a) { return preg_quote($a, '~'); }, $attrs)) . ')\s*=\s*("([^"]*)"|\'([^\']*)\')~i';
    $html = preg_replace_callback($pattern, function ($m) use ($pageUrl, $proxyBase) {
        $attr = strtolower($m[1]);
        $val = $m[3] !== '' ? $m[3] : ($m[4] ?? '');
        $quote = $m[3] !== '' ? '"' : "'";
        if ($val === '') return $m[0];
        $new = $attr === 'srcset'
            ? p_rewrite_srcset($val, $pageUrl, $proxyBase)
            : p_rewrite_url($val, $pageUrl, $proxyBase);
        if ($new === $val) return $m[0];
        return ' ' . $attr . '=' . $quote . $new . $quote;
    }, $html);

    // style="...url(...)..."
    $html = preg_replace_callback('~style\s*=\s*("([^"]*)"|\'([^\']*)\')~i', function ($m) use ($pageUrl, $proxyBase) {
        $val = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
        $quote = $m[2] !== '' ? '"' : "'";
        if (stripos($val, 'url(') === false) return $m[0];
        return 'style=' . $quote . p_rewrite_css($val, $pageUrl, $proxyBase) . $quote;
    }, $html);

    return $html;
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
            $res['via'] = ($proxy === null || $proxy === '') ? 'direct' : 'upstream';
            return $res; // پاسخ قابل‌قبول
        }
        // خطا یا وضعیت قابل‌تلاش‌مجدد → با پراکسی بعدی ادامه بده
    }

    // فالبک: اگر اتصال به مقصد ناموفق بود (یا وضعیت قابل‌فالبک دریافت شد)،
    // از ورکر کلودفلر عبور کن
    if ($last !== null && ($last['error'] !== null || in_array($last['status'], $fallbackStatuses, true))) {
        $fb = p_fallback_attempt($url, $method, $headers, $body, $timeout);
        if ($fb !== null && $fb['error'] === null) {
            $fb['via'] = 'worker';
            return $fb;
        }
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
            if ($res['error'] === null) {
                $res['via'] = 'upstream';
                return $res;
            }
        }
    }
    $fb = p_fallback_attempt($url, $method, $headers, $body, $timeout);
    if ($fb !== null && $fb['error'] === null) {
        $fb['via'] = 'worker';
        return $fb;
    }
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
    return p_effective_worker_url();
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
        p_emit_response($cached['status'], $cached['headers'], $cached['body'], $final, true, 'cache');
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
    // وقتی بازنویسی کامل فعال است، p_rewrite_html خودش base را می‌گذارد
    $ct = strtolower(implode(' ', $result['headers']['content-type'] ?? []));
    if ($cfg['inject_base'] && !p_rewrite_enabled()
        && strpos($ct, 'text/html') !== false && $result['body'] !== ''
        && strpos($result['body'], 'data-proxy-base') === false) {
        $result['body'] = p_inject_base($result['body'], $final);
    }

    if ($method === 'GET' && $result['status'] >= 200 && $result['status'] < 400) {
        p_cache_set($cacheKey, $result['status'], $result['headers'], $result['body']);
    }

    p_emit_response($result['status'], $result['headers'], $result['body'], $final, false, (string)($result['via'] ?? 'direct'));
}

/** ارسال پاسخ نهایی به کلاینت */
function p_emit_response(int $status, array $headers, string $body, string $finalUrl, bool $fromCache, string $route = 'direct'): void {
    // بازنویسی URL در صورت فعال بودن: HTML و CSS از همین‌جا رد می‌شوند تا
    // تصاویر/استایل/اسکریپت‌ها هم از مسیر خود پراکسی لود شوند
    if (p_rewrite_enabled() && $body !== '') {
        $ctEmit = strtolower(implode(' ', $headers['content-type'] ?? []));
        if (strpos($ctEmit, 'text/html') !== false) {
            $body = p_rewrite_html($body, $finalUrl, p_proxy_base_url());
        } elseif (strpos($ctEmit, 'text/css') !== false) {
            $body = p_rewrite_css($body, $finalUrl, p_proxy_base_url());
        }
    }

    // CORS
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Max-Age: 86400');
    header('Access-Control-Expose-Headers: X-Proxy-Final-Url, X-Proxy-Cache, X-Proxy-Final-Status, X-Proxy-Route');
    header('X-Proxy-Final-Url: ' . $finalUrl);
    header('X-Proxy-Cache: ' . ($fromCache ? 'HIT' : 'MISS'));
    header('X-Proxy-Final-Status: ' . $status);
    header('X-Proxy-Route: ' . $route);

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
// [۵-آ] پنل هوش مصنوعی — تست مدل‌ها + چت + درون‌ریزی/برون‌ریزی JSON
// تنظیمات در ai-providers.json کنار فایل ذخیره می‌شود.
// ---------------------------------------------------------------------

const AI_PROVIDERS_FILE = __DIR__ . '/ai-providers.json';

/** ساختار پیش‌فرض — همان قالب کانفیگ شما با کلید خالی؛ کلیدها را خودتان درون‌ریزی کنید */
function ai_providers_seed(): array {
    return [
        'ollama'      => ['id' => 'ollama', 'name' => 'Ollama', 'vendor' => 'ollama-models', 'url' => 'http://127.0.0.1:11434', 'apiKeys' => [], 'enabled' => false, 'models' => []],
        'openrouter'  => ['id' => 'openrouter', 'name' => 'OpenRouter', 'vendor' => 'openrouter', 'url' => 'https://openrouter.ai/api/v1', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'together'    => ['id' => 'together', 'name' => 'Together', 'vendor' => 'customendpoint', 'url' => 'https://api.together.xyz/v1/chat/completions', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'groq'        => ['id' => 'groq', 'name' => 'Groq', 'vendor' => 'groq', 'url' => 'https://api.groq.com/openai/v1/chat/completions', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'huggingface' => ['id' => 'huggingface', 'name' => 'Hugging Face', 'vendor' => 'huggingface', 'url' => 'https://router.huggingface.co/v1/chat/completions', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'cloudflare'  => ['id' => 'cloudflare', 'name' => 'Cloudflare Workers AI', 'vendor' => 'customendpoint', 'url' => 'https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/run/{MODEL}', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'gemini'      => ['id' => 'gemini', 'name' => 'Google AI Studio / Gemini', 'vendor' => 'customendpoint', 'url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'cerebras'    => ['id' => 'cerebras', 'name' => 'Cerebras', 'vendor' => 'customendpoint', 'url' => 'https://api.cerebras.ai/v1/chat/completions', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'mistral'     => ['id' => 'mistral', 'name' => 'Mistral', 'vendor' => 'customendpoint', 'url' => 'https://api.mistral.ai/v1/chat/completions', 'apiKeys' => [], 'enabled' => true, 'models' => []],
        'cohere'      => ['id' => 'cohere', 'name' => 'Cohere', 'vendor' => 'customendpoint', 'url' => 'https://api.cohere.com/compatibility/v1/chat/completions', 'apiKeys' => [], 'enabled' => true, 'models' => []],
    ];
}

function ai_providers_load(): array {
    if (!is_file(AI_PROVIDERS_FILE)) return ai_providers_seed();
    $j = @json_decode((string)@file_get_contents(AI_PROVIDERS_FILE), true);
    $d = is_array($j) ? $j : [];
    if (isset($d['providers']) && is_array($d['providers'])) $d = $d['providers'];
    return $d;
}

function ai_providers_save(array $providers): bool {
    return @file_put_contents(AI_PROVIDERS_FILE, json_encode($providers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

/** نرمال‌سازی یک ارائه‌دهنده — هم فرمت شما (apiKey) و هم چندکلیدی (apiKeys) را می‌پذیرد */
function ai_provider_normalize(array $p): ?array {
    $rawId = trim((string)($p['id'] ?? $p['name'] ?? ''));
    if ($rawId === '') return null;
    $id = strtolower((string)preg_replace('~[^a-z0-9_-]+~i', '-', $rawId));
    $url = trim((string)($p['url'] ?? ''));
    if (preg_match('~^\[([^\]]+)\]\(([^)]+)\)$~', $url, $m)) $url = trim($m[2]);
    $keys = [];
    if (!empty($p['apiKeys']) && is_array($p['apiKeys'])) {
        foreach ($p['apiKeys'] as $k) {
            $s = is_array($k) ? trim((string)($k['key'] ?? '')) : trim((string)$k);
            if ($s !== '') $keys[] = ['key' => $s, 'label' => is_array($k) ? trim((string)($k['label'] ?? '')) : ''];
        }
    } elseif (!empty($p['apiKey'])) {
        $keys[] = ['key' => trim((string)$p['apiKey']), 'label' => ''];
    }
    $models = [];
    foreach ((array)($p['models'] ?? []) as $m) {
        if (is_array($m) && !empty($m['id'])) $models[] = $m;
    }
    return [
        'id'      => $id,
        'name'    => trim((string)($p['name'] ?? $id)),
        'vendor'  => trim((string)($p['vendor'] ?? 'customendpoint')),
        'url'     => $url,
        'apiKeys' => $keys,
        'enabled' => !empty($p['enabled']),
        'models'  => $models,
    ];
}

/** ساخت آدرس chat/completions — منطق مشابه اسکرپر: /ai/run/ دست‌نخورده، ollama → /v1 */
function ai_endpoint_url(array $p, string $model = ''): string {
    $raw = trim((string)($p['url'] ?? ''));
    if (preg_match('~^\[([^\]]+)\]\(([^)]+)\)$~', $raw, $m)) $raw = trim($m[2]);
    if ($raw === '') return '';
    if (stripos($raw, '/ai/run/') !== false) {
        if ($model !== '' && strpos($raw, '{MODEL}') !== false) return str_replace('{MODEL}', rawurlencode($model), $raw);
        return $raw;
    }
    $vendor = strtolower((string)($p['vendor'] ?? ''));
    if ($vendor === 'ollama-models' || strpos($raw, '11434') !== false) {
        return rtrim($raw, '/') . '/v1/chat/completions';
    }
    if (preg_match('~/chat/completions/?$~i', $raw)) return $raw;
    return rtrim($raw, '/') . '/chat/completions';
}

/** استخراج متن پاسخ — هم فرمت OpenAI و هم Cloudflare (result.choices) */
function ai_extract_content(array $body): string {
    $c = $body['choices'][0]['message']['content'] ?? '';
    if (is_string($c) && trim($c) !== '') return trim($c);
    $c = $body['choices'][0]['message']['reasoning'] ?? '';
    if (is_string($c) && trim($c) !== '') return trim($c);
    $c = $body['result']['choices'][0]['message']['content'] ?? '';
    if (is_string($c) && trim($c) !== '') return trim($c);
    $c = $body['result']['response'] ?? '';
    if (is_string($c) && trim($c) !== '') return trim($c);
    $c = $body['response'] ?? '';
    if (is_string($c) && trim($c) !== '') return trim($c);
    $c = $body['data'][0]['text'] ?? '';
    if (is_string($c) && trim($c) !== '') return trim($c);
    return '';
}

/** درخواست AI از مسیر زنجیرهٔ خود پراکسی (مستقیم ← بالادستی ← ورکر کلودفلر) */
function ai_proxy_call(string $url, string $apiKey, string $payloadJson): array {
    $cfg = $GLOBALS['CONFIG'];
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;
    $timeout = max(30, min(180, (int)$cfg['timeout']));
    $v = p_validate_url($url); // اعتبارسنجی امنیتی (دامنه/سیاست DNS)
    $start = microtime(true);
    if (($v['dns'] ?? 'ok') === 'filtered') {
        $res = p_filtered_attempt($url, 'POST', $headers, $payloadJson, $timeout);
    } else {
        $res = p_rotate_attempt($url, 'POST', $headers, $payloadJson, $timeout);
    }
    return ['res' => $res, 'ms' => (int)round((microtime(true) - $start) * 1000)];
}

/** لیست ارائه‌دهنده‌ها (کلیدها ماسک‌شده) */
function p_ai_providers_list(): void {
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    foreach (ai_providers_load() as $p) {
        $p = ai_provider_normalize((array)$p);
        if ($p === null) continue;
        $keys = [];
        foreach ($p['apiKeys'] as $k) {
            $s = (string)$k['key'];
            $keys[] = ['label' => $k['label'], 'masked' => (strlen($s) > 8 ? substr($s, 0, 4) . '…' . substr($s, -4) : '•••')];
        }
        $out[] = [
            'id' => $p['id'], 'name' => $p['name'], 'vendor' => $p['vendor'], 'url' => $p['url'],
            'enabled' => $p['enabled'], 'keys' => $keys,
            'models' => array_values(array_map(function ($m) { return ['id' => $m['id'] ?? '', 'name' => $m['name'] ?? ($m['id'] ?? '')]; }, $p['models'])),
        ];
    }
    echo json_encode(['ok' => true, 'providers' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** برون‌ریزی JSON در قالب کانفیگ شما (apiKey + apiKeys) */
function p_ai_export(): void {
    $out = [];
    foreach (ai_providers_load() as $p) {
        $p = ai_provider_normalize((array)$p);
        if ($p === null) continue;
        $first = $p['apiKeys'][0]['key'] ?? '';
        $entry = [
            'id' => $p['id'], 'name' => $p['name'], 'vendor' => $p['vendor'], 'url' => $p['url'],
            'apiKey' => $first, 'enabled' => $p['enabled'], 'models' => $p['models'],
        ];
        if (count($p['apiKeys']) > 1) $entry['apiKeys'] = $p['apiKeys'];
        $out[$p['id']] = $entry;
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ai-providers.json"');
    echo $json;
    exit;
}

/** درون‌ریزی — کل مجموعه جایگزین می‌شود (بعد از درون‌ریزی ذخیره هم می‌شود) */
function p_ai_import(): void {
    $jsonStr = trim((string)($_POST['providers_json'] ?? ''));
    $parsed = json_decode($jsonStr, true);
    if (!is_array($parsed)) p_error(400, 'bad_json', 'JSON نامعتبر: ' . json_last_error_msg());
    if (isset($parsed['providers']) && is_array($parsed['providers'])) $parsed = $parsed['providers'];
    $providers = [];
    $count = 0;
    foreach ($parsed as $key => $p) {
        if (!is_array($p)) continue;
        $p = ai_provider_normalize($p);
        if ($p === null) continue;
        $providers[$p['id']] = $p;
        $count++;
    }
    if ($count === 0) p_error(400, 'no_providers', 'هیچ ارائه‌دهندهٔ معتبری در JSON پیدا نشد');
    if (!ai_providers_save($providers)) p_error(500, 'save_failed', 'نوشتن ai-providers.json ممکن نشد');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'count' => $count], JSON_UNESCAPED_UNICODE);
    exit;
}

/** ذخیره/به‌روزرسانی مشخصات یک ارائه‌دهنده — کلیدها و مدل‌ها دست‌نخورده می‌مانند */
function p_ai_save_provider(): void {
    $id = strtolower(trim((string)($_POST['id'] ?? '')));
    if ($id === '') p_error(400, 'bad_provider', 'شناسهٔ ارائه‌دهنده خالی است');
    $providers = ai_providers_load();
    $existing = isset($providers[$id]) ? (array)$providers[$id] : null;
    $p = ai_provider_normalize([
        'id' => $id,
        'name' => $_POST['name'] ?? $id,
        'vendor' => $_POST['vendor'] ?? 'customendpoint',
        'url' => $_POST['url'] ?? '',
        'enabled' => !empty($_POST['enabled']),
        'apiKeys' => (array)($existing['apiKeys'] ?? []),
        'models' => (array)($existing['models'] ?? []),
    ]);
    if ($p === null) p_error(400, 'bad_provider', 'شناسهٔ ارائه‌دهنده خالی است');
    $providers[$p['id']] = $p;
    if (!ai_providers_save($providers)) p_error(500, 'save_failed', 'نوشتن ai-providers.json ممکن نشد');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'id' => $p['id']], JSON_UNESCAPED_UNICODE);
    exit;
}

/** افزودن کلید API به یک ارائه‌دهنده */
function p_ai_add_key(): void {
    $id = strtolower(trim((string)($_POST['id'] ?? '')));
    $key = trim((string)($_POST['key'] ?? ''));
    if ($key === '') p_error(400, 'no_key', 'کلید خالی است');
    $providers = ai_providers_load();
    if (!isset($providers[$id])) p_error(404, 'no_provider', 'ارائه‌دهنده پیدا نشد');
    $providers[$id]['apiKeys'] = (array)($providers[$id]['apiKeys'] ?? []);
    $providers[$id]['apiKeys'][] = ['key' => $key, 'label' => trim((string)($_POST['label'] ?? ''))];
    ai_providers_save($providers);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/** حذف کلید API از یک ارائه‌دهنده */
function p_ai_del_key(): void {
    $id = strtolower(trim((string)($_POST['id'] ?? '')));
    $idx = (int)($_POST['index'] ?? -1);
    $providers = ai_providers_load();
    if (!isset($providers[$id])) p_error(404, 'no_provider', 'ارائه‌دهنده پیدا نشد');
    $keys = (array)($providers[$id]['apiKeys'] ?? []);
    if (isset($keys[$idx])) unset($keys[$idx]);
    $providers[$id]['apiKeys'] = array_values($keys);
    ai_providers_save($providers);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/** حذف ارائه‌دهنده */
function p_ai_delete_provider(): void {
    $id = strtolower(trim((string)($_POST['id'] ?? '')));
    $providers = ai_providers_load();
    if (isset($providers[$id])) unset($providers[$id]);
    ai_providers_save($providers);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/** تفسیر مشترک نتیجهٔ یک درخواست AI: ok/status/ms/via/error/content */
function ai_interpret_result(array $res, int $ms): array {
    $bodyArr = json_decode((string)$res['body'], true);
    if (!is_array($bodyArr)) $bodyArr = [];
    $content = ai_extract_content($bodyArr);
    $ok = $res['error'] === null && (int)$res['status'] === 200 && $content !== '';
    $err = '';
    if (!$ok) {
        if ($res['error'] !== null) $err = $res['error'];
        elseif ((int)$res['status'] === 401) $err = 'کلید API نامعتبر (۴۰۱)';
        elseif ((int)$res['status'] === 429) $err = 'محدودیت نرخ (۴۲۹)';
        elseif ((int)$res['status'] === 402) $err = 'عدم موجودی/پرداخت (۴۰۲)';
        else $err = trim((string)($bodyArr['error']['message'] ?? ($bodyArr['message'] ?? $bodyArr['error'] ?? '')));
        if ($err === '') $err = 'HTTP ' . (int)$res['status'] . ($content !== '' ? ' — ' . $content : '');
    }
    return [
        'ok' => $ok, 'status' => (int)$res['status'], 'ms' => $ms,
        'via' => (string)($res['via'] ?? ''), 'error' => $err,
        'content' => $ok ? $content : '',
        'raw' => (string)($res['body'] ?? ''),
    ];
}

/** اجرای تست مدل یا چت — خروجی مشترک */
function p_ai_run(bool $isChat): void {
    header('Content-Type: application/json; charset=utf-8');
    $pid = trim((string)($_POST['provider_id'] ?? ''));
    $mid = trim((string)($_POST['model_id'] ?? ''));
    $ki  = max(0, (int)($_POST['key_index'] ?? 0));
    $providers = ai_providers_load();
    if (!isset($providers[$pid])) p_error(404, 'no_provider', 'ارائه‌دهنده پیدا نشد');
    $p = ai_provider_normalize((array)$providers[$pid]);
    $key = (string)($p['apiKeys'][$ki]['key'] ?? ($p['apiKeys'][0]['key'] ?? ''));
    $url = ai_endpoint_url($p, $mid);
    if ($url === '') p_error(400, 'no_url', 'آدرس ارائه‌دهنده خالی است');

    if ($isChat) {
        $messages = json_decode((string)($_POST['messages'] ?? '[]'), true) ?: [];
        if ($messages === []) p_error(400, 'no_messages', 'پیامی برای چت نیست');
        $payload = ['model' => $mid, 'messages' => $messages, 'max_tokens' => 800];
    } else {
        // پیام تست استاندارد «سلام» — از کلاینت هم قابل بازنویسی است
        $msg = trim((string)($_POST['test_message'] ?? 'سلام'));
        if ($msg === '') $msg = 'سلام';
        $payload = ['model' => $mid, 'messages' => [['role' => 'user', 'content' => $msg]], 'max_tokens' => 24];
    }

    $r = ai_proxy_call($url, $key, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $out = ai_interpret_result($r['res'], $r['ms']);
    echo json_encode(array_merge($out, ['model' => $mid]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** تست گروهی همهٔ مدل‌های یک ارائه‌دهنده با پیام «سلام» */
function p_ai_test_all(): void {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(0); // لیست مدل‌ها ممکن است طولانی باشد
    $pid = trim((string)($_POST['provider_id'] ?? ''));
    $ki  = max(0, (int)($_POST['key_index'] ?? 0));
    $msg = trim((string)($_POST['test_message'] ?? 'سلام'));
    if ($msg === '') $msg = 'سلام';
    $providers = ai_providers_load();
    if (!isset($providers[$pid])) p_error(404, 'no_provider', 'ارائه‌دهنده پیدا نشد');
    $p = ai_provider_normalize((array)$providers[$pid]);
    $key = (string)($p['apiKeys'][$ki]['key'] ?? ($p['apiKeys'][0]['key'] ?? ''));
    if ($key === '') p_error(400, 'no_key', 'برای این ارائه‌دهنده کلیدی تنظیم نشده است');
    if (ai_endpoint_url($p, '') === '') p_error(400, 'no_url', 'آدرس ارائه‌دهنده خالی است');

    $results = [];
    foreach ((array)$p['models'] as $m) {
        $mid = (string)($m['id'] ?? '');
        if ($mid === '') continue;
        $ep = ai_endpoint_url($p, $mid);
        $payload = ['model' => $mid, 'messages' => [['role' => 'user', 'content' => $msg]], 'max_tokens' => 24];
        $r = ai_proxy_call($ep, $key, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $out = ai_interpret_result($r['res'], $r['ms']);
        $results[] = array_merge([
            'model' => $mid,
            'name'  => (string)($m['name'] ?? $mid),
        ], $out);
        if (connection_aborted()) break;
    }
    $passed = 0;
    foreach ($results as $x) if ($x['ok']) $passed++;
    echo json_encode([
        'ok'           => true,
        'test_message' => $msg,
        'total'        => count($results),
        'passed'       => $passed,
        'failed'       => count($results) - $passed,
        'results'      => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
.aiacc { background:var(--card); border:1px solid var(--line); border-radius:12px; margin-bottom:10px; overflow:hidden; }
.aiacc-hdr { display:flex; align-items:center; gap:10px; padding:11px 14px; cursor:pointer; flex-wrap:wrap; transition:background .15s; }
.aiacc-hdr:hover { background:#1a2136; }
.aiacc-arrow { color:var(--mut); width:14px; display:inline-block; font-size:.8rem; }
.aiacc-body { border-top:1px solid var(--line); padding:12px 14px; }
.aitwrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid var(--line); border-radius:8px; margin-top:4px; }
table.ait { width:100%; min-width:820px; border-collapse:collapse; font-size:.76rem; }
table.ait th, table.ait td { border:1px solid var(--line); padding:5px 8px; text-align:right; white-space:nowrap; }
table.ait th { background:#0b0f1a; color:var(--mut); }
table.ait td.ltr, table.ait th.ltr { direction:ltr; text-align:left; font-family:Consolas,monospace; }
.st-ok { color:var(--ok); font-weight:700; }
.st-bad { color:var(--bad); font-weight:700; }
.st-run { color:var(--acc); font-weight:700; }
.aimodal { background:var(--card); border:1px solid var(--line); border-radius:14px; max-width:640px; width:92%; max-height:82vh; overflow:auto; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.55); }
.aimodal pre { margin:0; padding:10px 12px; background:#0b0f1a; border:1px solid var(--line); border-radius:8px; max-height:230px; overflow:auto; white-space:pre-wrap; word-break:break-word; direction:ltr; text-align:left; font-size:.78rem; font-family:Consolas,monospace; }
iframe { width:100%; height:420px; border:1px solid var(--line); border-radius:10px; background:#fff; margin-top:10px; }
ul { padding-right:20px; }
li { margin:4px 0; }
a { color:var(--acc); }
</style>
</head>
<body>
<div class="wrap">
<div class="tabs" style="display:flex;gap:8px;margin-bottom:20px">
<button id="tabBtnProxy" class="tabbtn" onclick="showPane('proxy')" style="flex:1;padding:10px;border-radius:10px;border:1px solid var(--line);background:var(--acc);color:#fff;cursor:pointer;font-family:inherit;font-weight:700">🛰️ پراکسی</button>
<button id="tabBtnAi" class="tabbtn" onclick="showPane('ai')" style="flex:1;padding:10px;border-radius:10px;border:1px solid var(--line);background:var(--card);color:var(--txt);cursor:pointer;font-family:inherit;font-weight:700">🤖 هوش مصنوعی</button>
</div>
<div id="pane-proxy">
<h1>🛰️ پراکسی <span>سرور</span></h1>
<div class="sub">نسخهٔ {$ver} — تک‌فایلی PHP، بدون وابستگی — پاسخ‌ها را از سمت سرور می‌گیرد تا IP و ساختار درخواست شما مخفی بماند.</div>
{$statusHtml}

<h2>🧪 تست سریع</h2>
<div class="testbox">
<input id="u" placeholder="https://example.com/page" value="https://registry.npmjs.org/express">
<button onclick="run()">دریافت از طریق پراکسی</button>
</div>
<div id="result"></div>

<h2>⚙️ تنظیمات (در proxy-settings.json ذخیره می‌شود)</h2>
<div class="crow" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
<label style="font-size:.85rem">آدرس ورکر کلودفلر:</label>
<input id="cfgWorker" placeholder="https://proxy.fazilat-ma.workers.dev" style="flex:1;min-width:260px;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:10px 12px;direction:ltr;font-family:Consolas,monospace">
</div>
<div class="crow" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
<label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer">
<input type="checkbox" id="cfgRewrite" style="width:15px;height:15px"> بازنویسی کامل URL (تصاویر و CSS هم از پراکسی لود شوند)
</label>
</div>
<div class="testbox">
<button onclick="saveSettings()" style="flex:1">💾 ذخیره تنظیمات</button>
<button onclick="loadSettings()" style="flex:1">↺ بارگذاری مجدد</button>
</div>
<div id="cfgStatus" class="meta" style="margin-bottom:8px"></div>
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
<li>فالبک خودکار به ورکر کلودفلر در صورت شکست اتصال به مقصد یا خطای ۴۰۳ (IP ممنوع) — قابل تغییر در تنظیمات</li>
<li>شناسایی DNS مسموم/فیلترشده (مثل facebook.com روی هاست ایرانی) و عبور خودکار از ورکر کلودفلر یا پراکسی بالادستی</li>
<li>انتقال کامل کلید API (Authorization / x-api-key) به مقصد — حتی از مسیر فالبک ورکر — برای تست مدل‌های هوش مصنوعی</li>
<li>حالت پراکسی فوروارد: CONNECT برای HTTPS و absolute-form برای HTTP — قابل استفاده در فیلد پروکسی اسکرپر</li>
<li>سازگار با فیلد «Worker» اسکرپر: الگوی <code>?url={url}</code> و حالت مسیری، بدون هیچ تنظیم سروری</li>
<li>ریدایرکت‌ها به‌صورت امن دنبال می‌شوند و هر پرش دوباره اعتبارسنجی می‌شود</li>
<li>رمزگشایی خودکار gzip / deflate / brotli — کش فایلی اختیاری — تزریق <code>&lt;base&gt;</code> برای لینک‌های نسبی</li>
<li>بازنویسی کامل URL (<code>rewrite_urls</code>): تصاویر، CSS و JS هم از خود پراکسی لود می‌شوند — رندر کامل سایت‌های بلاک‌شده</li>
<li>آدرس ورکر کلودفلر و بازنویسی URL از خود داشبورد قابل تغییرند (ذخیره در proxy-settings.json)</li>
<li>هدر <code>X-Proxy-Route</code>: مسیر واقعی عبور هر پاسخ — مستقیم / بالادستی / ورکر کلودفلر / کش</li>
</ul>
</div>
<div id="pane-ai" style="display:none">
<h1>🤖 هوش <span>مصنوعی</span></h1>
<div class="sub">تست مدل‌ها، مدیریت چند کلید API برای هر ارائه‌دهنده، درون‌ریزی/برون‌ریزی JSON و چت — همهٔ درخواست‌ها از زنجیرهٔ خود پراکسی عبور می‌کنند.</div>

<div class="testbox">
<button onclick="aiImportOpen()">📥 درون‌ریزی JSON</button>
<button onclick="aiExport()">📤 برون‌ریزی JSON</button>
<button onclick="aiAddProvider()">➕ افزودن ارائه‌دهنده</button>
<button onclick="aiLoad()">↺ بارگذاری مجدد</button>
<button onclick="aiExpandAll(true)">⊞ باز کردن همه</button>
<button onclick="aiExpandAll(false)">⊟ بستن همه</button>
</div>
<div id="aiImportBox" style="display:none;margin-bottom:12px">
<textarea id="aiImportJson" rows="7" placeholder='{"openrouter":{"id":"openrouter","name":"OpenRouter","url":"https://openrouter.ai/api/v1","apiKey":"sk-...","enabled":true,"models":[...]}}' style="width:100%;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:10px;direction:ltr;font-family:Consolas,monospace;font-size:.8rem"></textarea>
<div class="testbox">
<input type="file" id="aiImportFile" accept=".json,application/json" onchange="aiImportFile()" style="flex:1;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:8px">
<button onclick="aiImport()">درون‌ریزی</button>
</div>
</div>
<div id="aiProviders"></div>
<div id="aiModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:999;align-items:flex-start;justify-content:center;padding:24px 8px" onclick="if(event.target===this)aiCloseModal()">
<div class="aimodal">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:10px">
<b id="aiModalTitle" style="font-size:1rem">نتیجهٔ تست</b>
<button onclick="aiCloseModal()" style="padding:4px 12px;font-size:.85rem">✕</button>
</div>
<div id="aiModalBody"></div>
</div>
</div>

<h2>💬 چت با مدل</h2>
<div class="testbox">
<select id="aiChatProv" onchange="aiChatFillModels()" style="flex:1;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:10px"></select>
<select id="aiChatModel" onchange="AI_CHAT_SEL.model=this.value" style="flex:1;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:10px"></select>
<select id="aiChatKey" onchange="AI_CHAT_SEL.key=this.value" style="flex:0.6;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:10px"></select>
</div>
<div id="aiChatLog" style="height:340px;overflow:auto;background:#0b0f1a;border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;font-size:.85rem"></div>
<div class="testbox">
<input id="aiChatMsg" placeholder="پیام خود را بنویسید..." style="flex:1;min-width:240px;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:10px 12px" onkeydown="if(event.key==='Enter')aiChatSend()">
<button onclick="aiChatSend()">ارسال</button>
</div>
</div>
</div>
<script>
var ROUTE_LABEL = {
  direct:   '🟢 مستقیم (بدون واسطه)',
  upstream: '🟡 پراکسی بالادستی',
  worker:   '🔶 فالبک — ورکر کلودفلر',
  cache:    '⚪ از کش',
  '':       '—'
};

function routeLabel(r) {
  return ROUTE_LABEL[r] || r || '—';
}

function run() {
  var u = document.getElementById('u').value.trim();
  var out = document.getElementById('result');
  if (!u) { out.innerHTML = ''; return; }
  out.innerHTML = '<div class="meta">در حال دریافت…</div>';
  fetch('?url=' + encodeURIComponent(u))
    .then(function (r) {
      var route = r.headers.get('x-proxy-route') || '';
      var info = 'وضعیت: ' + r.status +
                 ' | مسیر عبور: <b>' + routeLabel(route) + '</b>' +
                 ' | نوع محتوا: ' + (r.headers.get('content-type') || '—') +
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

function saveSettings() {
  var st = document.getElementById('cfgStatus');
  var fd = new FormData();
  fd.append('action', 'save_settings');
  fd.append('worker_url', document.getElementById('cfgWorker').value.trim());
  fd.append('rewrite', document.getElementById('cfgRewrite').checked ? '1' : '0');
  st.textContent = 'در حال ذخیره…';
  fetch('', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.ok) {
        st.textContent = '✓ ذخیره شد — ورکر: ' + (d.worker_url || 'خالی (فالبک غیرفعال)')
                       + ' — بازنویسی: ' + (d.rewrite_urls ? 'فعال' : 'غیرفعال')
                       + (d.reload ? ' — صفحه را تازه کنید تا کارت وضعیت به‌روز شود' : '');
        setTimeout(function () { location.reload(); }, 1200);
      } else {
        st.textContent = '✗ ' + (d.error || 'خطا در ذخیره');
      }
    })
    .catch(function () { st.textContent = '✗ خطای شبکه'; });
}

function loadSettings() {
  fetch('?settings')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      document.getElementById('cfgWorker').value = d.worker_url || '';
      document.getElementById('cfgRewrite').checked = !!d.rewrite_urls;
      document.getElementById('cfgStatus').textContent = 'وضعیت فعلی: ورکر ' + (d.worker_url || 'خالی')
        + ' — بازنویسی: ' + (d.rewrite_urls ? 'فعال' : 'غیرفعال');
    })
    .catch(function () {});
}
loadSettings();

// ---------- تب‌ها ----------
function showPane(name) {
  document.getElementById('pane-proxy').style.display = (name === 'proxy') ? '' : 'none';
  document.getElementById('pane-ai').style.display = (name === 'ai') ? '' : 'none';
  var b1 = document.getElementById('tabBtnProxy'), b2 = document.getElementById('tabBtnAi');
  b1.style.background = (name === 'proxy') ? 'var(--acc)' : 'var(--card)';
  b1.style.color = (name === 'proxy') ? '#fff' : 'var(--txt)';
  b2.style.background = (name === 'ai') ? 'var(--acc)' : 'var(--card)';
  b2.style.color = (name === 'ai') ? '#fff' : 'var(--txt)';
  if (name === 'ai') aiLoad();
}

// ---------- پنل هوش مصنوعی ----------
var AI_PROVIDERS = {};
function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function aiLoad() {
  fetch('?ai=providers').then(function (r) { return r.json(); }).then(function (d) {
    if (d && d.ok) { AI_PROVIDERS = d.providers || []; aiRender(); aiChatFill(); }
  }).catch(function () {});
}

var AI_RES = {};
var AI_OPEN = {};
var AI_RUNNING = {};

function aiFind(pid) {
  for (var i = 0; i < AI_PROVIDERS.length; i++) if (AI_PROVIDERS[i].id === pid) return AI_PROVIDERS[i];
  return null;
}

function aiExpandAll(open) {
  for (var i = 0; i < AI_PROVIDERS.length; i++) {
    var pid = AI_PROVIDERS[i].id;
    AI_OPEN[pid] = !!open;
    var b = document.getElementById('body_' + pid);
    var a = document.getElementById('arw_' + pid);
    if (b) b.style.display = open ? '' : 'none';
    if (a) a.textContent = open ? '▾' : '▸';
  }
}

function aiToggle(pid) {
  AI_OPEN[pid] = !AI_OPEN[pid];
  var b = document.getElementById('body_' + pid);
  var a = document.getElementById('arw_' + pid);
  if (b) b.style.display = AI_OPEN[pid] ? '' : 'none';
  if (a) a.textContent = AI_OPEN[pid] ? '▾' : '▸';
}

function aiRender() {
  var box = document.getElementById('aiProviders');
  var h = '';
  for (var i = 0; i < AI_PROVIDERS.length; i++) {
    var p = AI_PROVIDERS[i];
    var open = !!AI_OPEN[p.id];
    h += '<div class="aiacc">'
       + '<div class="aiacc-hdr" onclick="aiToggle(\'' + esc(p.id) + '\')">'
       + '<span class="aiacc-arrow" id="arw_' + esc(p.id) + '">' + (open ? '▾' : '▸') + '</span>'
       + '<b>' + esc(p.name) + '</b>'
       + '<span class="badge" style="background:#1d4ed8;color:#fff;padding:2px 10px;border-radius:999px;font-size:10px">' + esc(p.vendor) + '</span>'
       + '<span style="font-size:.72rem;color:var(--mut)">' + p.keys.length + ' کلید · ' + p.models.length + ' مدل</span>'
       + '<span style="flex:1"></span>'
       + '<label style="font-size:.75rem;display:flex;align-items:center;gap:4px;cursor:pointer" onclick="event.stopPropagation()">'
       + '<input type="checkbox" id="en_' + esc(p.id) + '" ' + (p.enabled ? 'checked' : '') + ' style="width:13px;height:13px"> فعال</label>'
       + '<button onclick="event.stopPropagation();aiTestAll(\'' + esc(p.id) + '\')">🧪 تست همه</button>'
       + '<button onclick="event.stopPropagation();aiProvSave(\'' + esc(p.id) + '\')">💾</button>'
       + '<button onclick="event.stopPropagation();aiProvDel(\'' + esc(p.id) + '\')">🗑️</button>'
       + '</div>'
       + '<div class="aiacc-body" id="body_' + esc(p.id) + '" style="' + (open ? '' : 'display:none') + '">'
       + '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">'
       + '<span style="font-size:.78rem;color:var(--mut)">آدرس:</span>'
       + '<input id="url_' + esc(p.id) + '" value="' + esc(p.url) + '" placeholder="https://api..." style="flex:1;background:#0b0f1a;border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:8px 10px;direction:ltr;font-family:Consolas,monospace;font-size:.78rem">'
       + '</div>'
       + '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">'
       + '<span style="font-size:.78rem;color:var(--mut)">کلیدهای API:</span>'
       + '<button onclick="aiKeyAdd(\'' + esc(p.id) + '\')" style="padding:3px 10px;font-size:.72rem">➕ کلید</button>'
       + '</div>';
    if (p.keys.length > 0) {
      h += '<div style="margin-bottom:8px">';
      for (var k = 0; k < p.keys.length; k++) {
        h += '<div style="display:flex;gap:8px;margin-top:4px;font-size:.72rem;align-items:center">'
           + '<span style="color:var(--mut);min-width:100px">' + (p.keys[k].label ? esc(p.keys[k].label) : ('کلید ' + (k + 1))) + '</span>'
           + '<code style="flex:1;overflow:hidden;text-overflow:ellipsis">' + esc(p.keys[k].masked) + '</code>'
           + '<button onclick="aiKeyDel(\'' + esc(p.id) + '\',' + k + ')" style="padding:2px 8px;font-size:.72rem">🗑️</button>'
           + '</div>';
      }
      h += '</div>';
    }
    h += '<div style="font-size:.78rem;color:var(--mut);margin:6px 0">مدل‌ها (' + p.models.length + ') — پیام تست: «سلام»</div>';
    h += '<div class="aitwrap"><table class="ait"><thead><tr>'
       + '<th style="width:32px">#</th>'
       + '<th class="ltr">شناسهٔ مدل</th>'
       + '<th style="width:82px">وضعیت</th>'
       + '<th style="width:55px" class="ltr">کد</th>'
       + '<th style="width:72px" class="ltr">زمان</th>'
       + '<th style="width:80px" class="ltr">مسیر</th>'
       + '<th class="ltr">پاسخ / خطا</th>'
       + '<th style="width:60px"></th>'
       + '</tr></thead><tbody id="mtb_' + esc(p.id) + '">';
    if (p.models.length === 0) {
      h += '<tr><td colspan="8" style="color:var(--mut)">مدلی ثبت نشده — با «درون‌ریزی JSON» مدل‌ها را بیاورید</td></tr>';
    } else {
      for (var m = 0; m < p.models.length; m++) h += aiRowHtml(p.id, m, p.models[m]);
    }
    h += '</tbody></table></div>'
       + '<div id="all_' + esc(p.id) + '" style="margin-top:8px;font-size:.78rem;color:var(--mut)"></div>'
       + '</div></div>';
  }
  box.innerHTML = h || '<div class="meta">ارائه‌دهنده‌ای نیست — «درون‌ریزی JSON» بزنید.</div>';
}

function aiRowHtml(pid, m, model) {
  var res = AI_RES[pid + '_' + m];
  var st, code, ms, via, detCell;
  if (res && res.running) {
    st = '<span class="st-run">⏳ در حال تست</span>';
    code = '—'; ms = '—'; via = '—';
    detCell = '<td class="ltr" style="color:var(--mut)">—</td>';
  } else if (res) {
    st = res.ok ? '<span class="st-ok">✓ موفق</span>' : '<span class="st-bad">✗ ناموفق</span>';
    code = String(res.status || '0');
    ms = String(res.ms || 0) + 'ms';
    via = res.via || 'direct';
    var det = res.ok ? (res.content || '') : (res.error || '');
    detCell = '<td class="ltr" style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(det) + '">' + esc(det) + '</td>';
  } else {
    st = '<span style="color:var(--mut)">—</span>';
    code = '—'; ms = '—'; via = '—';
    detCell = '<td class="ltr" style="color:var(--mut)">—</td>';
  }
  return '<tr onclick="aiOpenModal(\'' + esc(pid) + '\',' + m + ')" style="cursor:pointer" title="کلیک برای مشاهدهٔ جزئیات کامل">'
    + '<td>' + (m + 1) + '</td>'
    + '<td class="ltr" title="' + esc(model.name || model.id) + '">' + esc(model.id) + '</td>'
    + '<td>' + st + '</td>'
    + '<td class="ltr">' + code + '</td>'
    + '<td class="ltr">' + ms + '</td>'
    + '<td class="ltr">' + via + '</td>'
    + detCell
    + '<td><button onclick="event.stopPropagation();aiTest(\'' + esc(pid) + '\',' + m + ')" style="padding:3px 8px;font-size:.72rem">🧪</button></td>'
    + '</tr>';
}

function aiUpdateRow(pid, m) {
  var tb = document.getElementById('mtb_' + pid);
  if (!tb || !tb.rows || !tb.rows[m]) return;
  var p = aiFind(pid);
  if (!p || !p.models[m]) return;
  tb.rows[m].outerHTML = aiRowHtml(pid, m, p.models[m]);
}

// ---------- مودال نتیجهٔ تست ----------
function aiOpenModal(pid, m) {
  var p = aiFind(pid);
  if (!p || !p.models[m]) return;
  var model = p.models[m];
  var res = AI_RES[pid + '_' + m];
  document.getElementById('aiModalTitle').textContent = p.name + ' — ' + model.id;
  var b = document.getElementById('aiModalBody');
  var h = '';
  h += '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">';
  if (!res) {
    h += '<span style="color:var(--mut);font-size:.8rem">هنوز تست نشده</span>';
  } else if (res.running) {
    h += '<span class="st-run" style="font-size:.8rem">⏳ در حال تست…</span>';
  } else if (res.ok) {
    h += '<span class="st-ok" style="font-size:.8rem">✓ موفق</span>';
  } else {
    h += '<span class="st-bad" style="font-size:.8rem">✗ ناموفق</span>';
  }
  h += '<span class="badge" style="background:#1d4ed8;color:#fff;padding:2px 10px;border-radius:999px;font-size:10px">' + esc(p.vendor) + '</span></div>';
  h += '<table class="ait" style="min-width:0"><tbody>';
  var fields = [
    ['شناسهٔ مدل', model.id, true],
    ['نام مدل', model.name || '—', false],
    ['وضعیت HTTP', res && !res.running ? String(res.status) : '—', true],
    ['زمان پاسخ', res && !res.running ? (res.ms || 0) + ' ms' : '—', true],
    ['مسیر عبور', res && !res.running ? (res.via || 'direct') : '—', true],
    ['پیام تست', '«سلام»', false],
    ['کلید استفاده‌شده', 'کلید ۱', false]
  ];
  for (var i = 0; i < fields.length; i++) {
    h += '<tr><td style="color:var(--mut);width:130px">' + fields[i][0] + '</td>'
       + '<td class="ltr">' + esc(fields[i][1]) + '</td></tr>';
  }
  h += '</tbody></table>';
  if (res && !res.running) {
    h += '<div style="margin-top:12px;font-size:.8rem;color:var(--mut)">' + (res.ok ? 'پاسخ کامل مدل:' : 'جزئیات خطا:') + '</div>';
    h += '<pre>' + esc(res.ok ? (res.content || '—') : (res.error || '—')) + '</pre>';
    h += '<div style="margin-top:12px;font-size:.8rem;color:var(--mut)">پاسخ خام (Raw Response):</div>';
    h += '<pre>' + esc(res.raw || '—') + '</pre>';
  }
  h += '<div class="testbox" style="margin-top:12px;margin-bottom:0">'
     + '<button onclick="aiModalRetest(\'' + esc(pid) + '\',' + m + ')" style="flex:1">🧪 تست مجدد</button>'
     + '<button onclick="aiCloseModal()" style="flex:1;background:#334155">بستن</button>'
     + '</div>';
  b.innerHTML = h;
  document.getElementById('aiModalOverlay').style.display = 'flex';
}

function aiModalRetest(pid, m) {
  var p = aiFind(pid);
  if (!p || !p.models[m]) return;
  AI_RES[pid + '_' + m] = { running: true };
  aiUpdateRow(pid, m);
  aiOpenModal(pid, m);
  var fd = new FormData();
  fd.append('action', 'ai_test_model');
  fd.append('provider_id', pid);
  fd.append('model_id', p.models[m].id);
  fd.append('key_index', '0');
  fd.append('test_message', 'سلام');
  fetch('', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
    AI_RES[pid + '_' + m] = d;
    aiUpdateRow(pid, m);
    aiOpenModal(pid, m); // به‌روزرسانی زندهٔ مودال بعد از تست
  }).catch(function () {
    AI_RES[pid + '_' + m] = { ok: false, status: 0, ms: 0, via: '', error: 'خطای شبکه' };
    aiUpdateRow(pid, m);
    aiOpenModal(pid, m);
  });
}

function aiCloseModal() {
  var o = document.getElementById('aiModalOverlay');
  if (o) o.style.display = 'none';
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') aiCloseModal();
});

function aiTest(pid, midx) {
  var p = aiFind(pid);
  if (!p || !p.models[midx]) return;
  AI_RES[pid + '_' + midx] = { running: true };
  aiUpdateRow(pid, midx);
  var fd = new FormData();
  fd.append('action', 'ai_test_model');
  fd.append('provider_id', pid);
  fd.append('model_id', p.models[midx].id);
  fd.append('key_index', '0');
  fd.append('test_message', 'سلام');
  fetch('', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
    AI_RES[pid + '_' + midx] = d;
    aiUpdateRow(pid, midx);
  }).catch(function () {
    AI_RES[pid + '_' + midx] = { ok: false, status: 0, ms: 0, via: '', error: 'خطای شبکه' };
    aiUpdateRow(pid, midx);
  });
}

function aiTestAll(pid) {
  var p = aiFind(pid);
  if (!p) return;
  if (p.models.length === 0) { alert('این ارائه‌دهنده مدلی ندارد — با «درون‌ریزی JSON» مدل‌ها را بیاورید'); return; }
  if (AI_RUNNING[pid]) { alert('یک تست گروهی در حال اجراست'); return; }
  if (!confirm('تست همهٔ ' + p.models.length + ' مدل با پیام «سلام»؟' + ' ممکن است چند دقیقه طول بکشد.')) return;
  AI_RUNNING[pid] = true;
  var sum = document.getElementById('all_' + pid);
  var total = p.models.length;
  for (var m = 0; m < total; m++) { AI_RES[pid + '_' + m] = { running: true }; aiUpdateRow(pid, m); }
  var passed = 0, failed = 0;
  function step(i) {
    if (i >= total) {
      AI_RUNNING[pid] = false;
      if (sum) sum.innerHTML = 'تست کامل شد: <b style="color:#4ade80">' + passed + ' موفق</b>'
        + (failed ? ' · <b style="color:#f87171">' + failed + ' ناموفق</b>' : '')
        + ' از ' + total + ' — پیام تست: «سلام»';
      return;
    }
    if (sum) sum.innerHTML = 'در حال تست ' + (i + 1) + ' از ' + total + ' … (موفق: ' + passed + ' · ناموفق: ' + failed + ')';
    var m = i;
    var fd = new FormData();
    fd.append('action', 'ai_test_model');
    fd.append('provider_id', pid);
    fd.append('model_id', p.models[m].id);
    fd.append('key_index', '0');
    fd.append('test_message', 'سلام');
    fetch('', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
      AI_RES[pid + '_' + m] = d;
      if (d.ok) passed++; else failed++;
      aiUpdateRow(pid, m);
      step(i + 1);
    }).catch(function () {
      AI_RES[pid + '_' + m] = { ok: false, status: 0, ms: 0, via: '', error: 'خطای شبکه' };
      failed++;
      aiUpdateRow(pid, m);
      step(i + 1);
    });
  }
  step(0);
}


// ---------- چت ----------
var AI_CHAT_HIST = [];
var AI_CHAT_SEL = { prov: '', model: '', key: '0' };

function aiChatFill() {
  var ps = document.getElementById('aiChatProv');
  var prevProv = AI_CHAT_SEL.prov || ps.value;
  ps.innerHTML = '';
  for (var i = 0; i < AI_PROVIDERS.length; i++) {
    var o = document.createElement('option');
    o.value = AI_PROVIDERS[i].id; o.textContent = AI_PROVIDERS[i].name;
    ps.appendChild(o);
  }
  // حفظ انتخاب قبلی به‌جای بازنشانی به اولین ارائه‌دهنده
  var pFound = false;
  for (var j = 0; j < ps.options.length; j++) if (ps.options[j].value === prevProv) pFound = true;
  if (pFound && prevProv) ps.value = prevProv;
  AI_CHAT_SEL.prov = ps.value;
  aiChatFillModels();
}

function aiChatFillModels() {
  var pid = document.getElementById('aiChatProv').value;
  AI_CHAT_SEL.prov = pid;
  var ms = document.getElementById('aiChatModel'), ks = document.getElementById('aiChatKey');
  var prevModel = AI_CHAT_SEL.model;
  var prevKey = (AI_CHAT_SEL.key !== undefined && AI_CHAT_SEL.key !== null) ? String(AI_CHAT_SEL.key) : '0';
  ms.innerHTML = ''; ks.innerHTML = '';
  var p = null;
  for (var i = 0; i < AI_PROVIDERS.length; i++) if (AI_PROVIDERS[i].id === pid) p = AI_PROVIDERS[i];
  if (!p) return;
  for (var m = 0; m < p.models.length; m++) {
    var o = document.createElement('option');
    o.value = p.models[m].id; o.textContent = p.models[m].id;
    ms.appendChild(o);
  }
  // حفظ انتخاب قبلی مدل (اگر هنوز در ارائه‌دهندهٔ جدید وجود دارد)
  var mFound = false;
  for (var j2 = 0; j2 < ms.options.length; j2++) if (ms.options[j2].value === prevModel) mFound = true;
  if (mFound && prevModel) ms.value = prevModel;
  AI_CHAT_SEL.model = ms.value || '';

  for (var k = 0; k < p.keys.length; k++) {
    var o2 = document.createElement('option');
    o2.value = String(k); o2.textContent = 'کلید ' + (k + 1) + (p.keys[k].label ? ' (' + p.keys[k].label + ')' : '');
    ks.appendChild(o2);
  }
  var kFound = false;
  for (var j3 = 0; j3 < ks.options.length; j3++) if (ks.options[j3].value === prevKey) kFound = true;
  if (kFound) ks.value = prevKey;
  AI_CHAT_SEL.key = ks.value || '0';
}
function aiChatLog(role, text, meta) {
  var log = document.getElementById('aiChatLog');
  var d = document.createElement('div');
  d.style.marginBottom = '8px';
  d.style.maxWidth = '90%';
  d.style.marginLeft = (role === 'user') ? 'auto' : '0';
  d.style.marginRight = (role === 'user') ? '0' : 'auto';
  d.style.background = (role === 'user') ? '#1d4ed8' : '#1e293b';
  d.style.color = '#e8ecf5';
  d.style.padding = '8px 12px';
  d.style.borderRadius = '12px';
  d.style.whiteSpace = 'pre-wrap';
  d.innerHTML = esc(text) + (meta ? '<div style="font-size:.65rem;color:#94a3b8;margin-top:4px">' + meta + '</div>' : '');
  log.appendChild(d);
  log.scrollTop = log.scrollHeight;
}
function aiChatSend() {
  var msg = document.getElementById('aiChatMsg').value.trim();
  if (!msg) return;
  var pid = document.getElementById('aiChatProv').value;
  var mid = document.getElementById('aiChatModel').value;
  if (!pid || !mid) { alert('اول ارائه‌دهنده و مدل را انتخاب کنید'); return; }
  AI_CHAT_SEL.prov = pid; AI_CHAT_SEL.model = mid;
  AI_CHAT_SEL.key = document.getElementById('aiChatKey').value || '0';
  document.getElementById('aiChatMsg').value = '';
  AI_CHAT_HIST.push({ role: 'user', content: msg });
  aiChatLog('user', msg, '');
  if (AI_CHAT_HIST.length > 20) AI_CHAT_HIST = AI_CHAT_HIST.slice(-20);
  var fd = new FormData();
  fd.append('action', 'ai_chat');
  fd.append('provider_id', pid);
  fd.append('model_id', mid);
  fd.append('key_index', document.getElementById('aiChatKey').value || '0');
  fd.append('messages', JSON.stringify(AI_CHAT_HIST));
  aiChatLog('assistant', '⏳ …', '');
  fetch('', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
    var log = document.getElementById('aiChatLog');
    log.removeChild(log.lastChild);
    if (d.ok) {
      AI_CHAT_HIST.push({ role: 'assistant', content: d.content });
      aiChatLog('assistant', d.content, d.status + ' · ' + d.ms + 'ms · مسیر: ' + (d.via || 'direct'));
    } else {
      aiChatLog('assistant', '✗ ' + (d.error || 'خطا'), d.status + ' · مسیر: ' + (d.via || ''));
    }
  }).catch(function () {
    var log = document.getElementById('aiChatLog');
    log.removeChild(log.lastChild);
    aiChatLog('assistant', '✗ خطای شبکه', '');
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
        'rewrite_urls'     => p_effective_rewrite(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** گزارش تنظیمات مؤثر برای داشبورد */
function p_settings_info(): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'          => true,
        'worker_url'  => p_effective_worker_url(),
        'rewrite_urls'=> p_effective_rewrite(),
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

if (isset($_GET['settings'])) {
    p_settings_info();
}

if (($_POST['action'] ?? '') === 'save_settings') {
    header('Content-Type: application/json; charset=utf-8');
    p_check_proxy_key();
    $workerRaw = trim((string)($_POST['worker_url'] ?? ''));
    if ($workerRaw !== ''
        && (!preg_match('~^https?://~i', $workerRaw) || !parse_url($workerRaw, PHP_URL_HOST))) {
        p_error(400, 'bad_worker_url', 'آدرس ورکر باید https:// باشد یا خالی');
    }
    $rewrite = !empty($_POST['rewrite']) && $_POST['rewrite'] !== '0' && $_POST['rewrite'] !== 'false';
    $res = p_save_settings($workerRaw, $rewrite);
    if (empty($res['ok'])) p_error(500, 'save_failed', 'نوشتن proxy-settings.json ممکن نشد');
    echo json_encode([
        'ok'           => true,
        'worker_url'   => p_effective_worker_url(),
        'rewrite_urls' => p_effective_rewrite(),
        'message'      => 'تنظیمات ذخیره شد',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['selftest'])) {
    p_selftest();
}

// پنل هوش مصنوعی
if (($_GET['ai'] ?? '') === 'providers') p_ai_providers_list();
if (($_GET['ai'] ?? '') === 'export')   p_ai_export();
$aiAction = (string)($_POST['action'] ?? '');
if ($aiAction === 'ai_import')         p_ai_import();
if ($aiAction === 'ai_save_provider')  p_ai_save_provider();
if ($aiAction === 'ai_delete_provider') p_ai_delete_provider();
if ($aiAction === 'ai_add_key')        p_ai_add_key();
if ($aiAction === 'ai_del_key')        p_ai_del_key();
if ($aiAction === 'ai_test_model')     p_ai_run(false);
if ($aiAction === 'ai_test_all')       p_ai_test_all();
if ($aiAction === 'ai_chat')           p_ai_run(true);

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
