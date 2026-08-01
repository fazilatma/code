<?php
/**
 * deploy.php — پنل استقرار فایل از گیت‌هاب روی هاست
 * ==================================================================
 * یک ابزار عمومی: هر فایلی را از هر برنچی از هر ریپویی می‌گیرد و
 * با نام و در پوشهٔ دلخواه روی هاست نصب می‌کند.
 *
 * قابلیت‌ها:
 *   • مرور تعاملی فایل‌های ریپو (انتخاب برنچ ← انتخاب فایل)
 *   • انتخاب نام فایل مقصد و پوشهٔ مقصد
 *   • کارهای ذخیره‌شده (Jobs) برای استفادهٔ مجدد با یک کلیک
 *   • بررسی بدون تغییر (dry-run) و مقایسهٔ نسخه‌ها
 *   • بکاپ خودکار + بازگشت به هر نسخهٔ قبلی
 *   • اعتبارسنجی چندلایه پیش از نصب
 *   • API ساده برای cron و فراخوانی خودکار
 *
 * نصب: فایل را روی هاست بگذارید، یک‌بار باز کنید و رمز تعیین کنید.
 */

@set_time_limit(300);
@ini_set('memory_limit', '256M');

const DEPLOY_VERSION = '2.0';
const CONFIG_FILE    = __DIR__ . '/.deploy-config.json';
const BACKUP_DIR     = __DIR__ . '/_backups';
const LOG_FILE       = __DIR__ . '/.deploy-log.json';
const MIN_BYTES      = 64;
const KEEP_BACKUPS   = 20;
const KEEP_LOGS      = 100;

// ==================================================================
//  زیرساخت
// ==================================================================

function cfg_load(): array {
    if (!is_file(CONFIG_FILE)) return [];
    $j = @file_get_contents(CONFIG_FILE);
    $d = $j ? json_decode($j, true) : null;
    return is_array($d) ? $d : [];
}

function cfg_save(array $c): bool {
    $j = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents(CONFIG_FILE, $j, LOCK_EX) === false) return false;
    @chmod(CONFIG_FILE, 0600);
    return true;
}

function cfg_default(): array {
    return [
        'password_hash' => '',
        'api_token'     => '',
        'repo'          => 'fazilatma/code',
        'branch'        => 'main',
        'github_token'  => '',
        'jobs'          => [],
        'created'       => time(),
    ];
}

function log_add(array $entry): void {
    $logs = [];
    if (is_file(LOG_FILE)) {
        $d = json_decode((string)@file_get_contents(LOG_FILE), true);
        if (is_array($d)) $logs = $d;
    }
    $entry['time'] = time();
    array_unshift($logs, $entry);
    $logs = array_slice($logs, 0, KEEP_LOGS);
    @file_put_contents(LOG_FILE, json_encode($logs, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(LOG_FILE, 0600);
}

function log_read(): array {
    if (!is_file(LOG_FILE)) return [];
    $d = json_decode((string)@file_get_contents(LOG_FILE), true);
    return is_array($d) ? $d : [];
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jout($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function human_size(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 2) . ' MB';
}

/**
 * مسیر را داخل ریشهٔ اسکریپت نگه می‌دارد (جلوگیری از path traversal).
 * خروجی: مسیر مطلق امن یا null
 */
function safe_path(string $relative, bool $isDir = false): ?string {
    $relative = str_replace('\\', '/', trim($relative));
    $relative = ltrim($relative, '/');
    if ($relative === '') return $isDir ? __DIR__ : null;
    if (preg_match('~(^|/)\.\.(/|$)~', $relative)) return null;
    if (strpos($relative, "\0") !== false) return null;

    $full = __DIR__ . '/' . $relative;
    $root = realpath(__DIR__);
    if ($root === false) return null;

    $check = $isDir ? $full : dirname($full);
    $real  = realpath($check);
    if ($real === false) return $full;            // هنوز ساخته نشده
    if (strpos($real, $root) !== 0) return null;  // بیرون از ریشه
    return $full;
}

function http_get(string $url, string $ghToken = '', bool $wantJson = false): array {
    $headers = [
        'User-Agent: deploy-panel/' . DEPLOY_VERSION,
        'Accept: ' . ($wantJson ? 'application/vnd.github+json' : 'application/vnd.github.raw, text/plain, */*'),
        'Cache-Control: no-cache',
    ];
    if ($ghToken !== '') $headers[] = 'Authorization: Bearer ' . $ghToken;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'code' => 0, 'error' => $err ?: 'curl failed', 'body' => ''];
        if ($code !== 200)   return ['ok' => false, 'code' => $code, 'error' => 'HTTP ' . $code, 'body' => (string)$body];
        return ['ok' => true, 'code' => 200, 'error' => '', 'body' => (string)$body];
    }

    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 180, 'header' => implode("\r\n", $headers), 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'code' => 0, 'error' => 'درخواست ناموفق', 'body' => ''];
    $code = 200;
    if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    if ($code !== 200) return ['ok' => false, 'code' => $code, 'error' => 'HTTP ' . $code, 'body' => $body];
    return ['ok' => true, 'code' => 200, 'error' => '', 'body' => $body];
}

function gh_raw_url(string $repo, string $branch, string $path): string {
    $enc = implode('/', array_map('rawurlencode', explode('/', $path)));
    return 'https://raw.githubusercontent.com/' . $repo . '/refs/heads/' . $branch . '/' . $enc;
}

/** بررسی نحوی PHP بدون exec */
function php_syntax_check(string $code, ?string &$err = null): bool {
    $err = null;
    try {
        token_get_all($code, TOKEN_PARSE);
        return true;
    } catch (ParseError $e) {
        $err = $e->getMessage() . ' — خط ' . $e->getLine();
        return false;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        return false;
    }
}

function prune_backups(string $destName, int $keep = KEEP_BACKUPS): void {
    $files = glob(BACKUP_DIR . '/' . $destName . '.*.bak');
    if (!$files || count($files) <= $keep) return;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $keep) as $old) @unlink($old);
}

function ensure_backup_dir(): void {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    $ht = BACKUP_DIR . '/.htaccess';
    if (is_dir(BACKUP_DIR) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    $idx = BACKUP_DIR . '/index.html';
    if (is_dir(BACKUP_DIR) && !is_file($idx)) @file_put_contents($idx, '');
}

/**
 * روی سرورهایی که فایل‌های dot را سرو می‌کنند، تنظیمات و لاگ نباید
 * از طریق وب قابل خواندن باشند. یک .htaccess کنار اسکریپت می‌گذاریم.
 */
function ensure_self_guard(): void {
    $ht = __DIR__ . '/.htaccess';
    $rule = "<FilesMatch \"^\\.deploy-(config|log)\\.json$\">\n"
          . "  Require all denied\n  Deny from all\n</FilesMatch>\n";
    if (!is_file($ht)) { @file_put_contents($ht, $rule); return; }
    $cur = (string)@file_get_contents($ht);
    if (strpos($cur, '.deploy-config') === false) {
        @file_put_contents($ht, rtrim($cur) . "\n\n" . $rule);
    }
}

/* =====================================================================
 *  پشتیبان‌گیری ورک‌اسپیس هاست → گیت‌هاب
 * ===================================================================== */

/** فایل‌هایی که هرگز نباید در ریپو منتشر شوند (کلید و رمز دارند) */
function wb_secret_files(): array {
    return [
        'connections.json',      // توکن باسلام، کلید ووکامرس، کلید AI
        '.deploy-config.json',   // هش رمز پنل و توکن API
        '.versioncheck.json',    // ممکن است توکن گیت‌هاب داشته باشد
        '.deploy-log.json',
        '.htaccess',
    ];
}

/** پوشه‌هایی که ارزش بکاپ ندارند یا حجیم‌اند */
function wb_skip_dirs(): array {
    return ['_backups', 'uploads', '.git', 'node_modules', 'vendor', '.cache', 'tmp'];
}

function wb_is_secret(string $rel): bool {
    $base = basename($rel);
    if (in_array($base, wb_secret_files(), true)) return true;
    // فایل‌های موقت صف/وضعیت هم داده‌های کاری‌اند، نه کد
    if (preg_match('~^(bsl|woo|extract)_(queue|products_temp|progress|stop_signal)~', $base)) return true;
    if (preg_match('~^bsl_queue_products_.*\.json$~', $base)) return true;
    return false;
}

/**
 * محتوای فایل را برای نشت کلید بررسی می‌کند — یک تور ایمنی دوم،
 * چون ممکن است کاربر فایلی با نام دیگر داشته باشد که کلید داخلش است.
 */
function wb_looks_secret(string $data): bool {
    if (preg_match('~eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}~', $data)) return true;   // JWT
    if (preg_match('~\bck_[a-f0-9]{32,}~i', $data)) return true;                            // WooCommerce key
    if (preg_match('~\bcs_[a-f0-9]{32,}~i', $data)) return true;
    if (preg_match('~\bgh[pousr]_[A-Za-z0-9]{30,}~', $data)) return true;                   // GitHub token
    if (preg_match('~\bAIza[A-Za-z0-9_-]{30,}~', $data)) return true;                       // Google/Gemini
    if (preg_match('~\$2y\$\d\d\$[./A-Za-z0-9]{50,}~', $data)) return true;                 // bcrypt hash
    return false;
}

/**
 * فایل‌های پوشهٔ جاری را جمع می‌کند.
 * $includeCsv: الگوهای اضافی جدا شده با کاما (مثلاً "*.json")
 * $withSecrets: اگر true باشد فایل‌های حساس هم وارد می‌شوند (فقط ریپوی خصوصی!)
 */
function wb_collect(string $includeCsv = '', bool $withSecrets = false, int $maxBytes = 25000000, string $encPass = ''): array {
    $root = realpath(__DIR__);
    $files = []; $skipped = []; $bytes = 0; $secretHits = []; $encrypted = [];
    $skipDirs = wb_skip_dirs();
    $encMode = $encPass !== '';   // حالت رمزنگاری: چیزی جا نمی‌ماند

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($cur) use ($skipDirs) {
                if ($cur->isDir()) return !in_array($cur->getFilename(), $skipDirs, true);
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $abs = $f->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
        if ($rel === '') continue;

        $size = (int)$f->getSize();
        if ($size > 5000000) { $skipped[] = ['path' => $rel, 'why' => 'بزرگ‌تر از ۵ مگابایت']; continue; }
        if (preg_match('~\.(lock|tmp)$~i', $rel)) { $skipped[] = ['path' => $rel, 'why' => 'فایل موقت']; continue; }

        $data = @file_get_contents($abs);
        if ($data === false) { $skipped[] = ['path' => $rel, 'why' => 'قابل خواندن نیست']; continue; }

        $isSecret = wb_is_secret($rel) || wb_looks_secret($data);

        if ($isSecret) {
            $secretHits[] = $rel;
            if ($encMode) {
                // رمزنگاری و ارسال با پسوند .enc — محتوای اصلی هرگز خام نمی‌رود
                try {
                    $data = wb_encrypt($data, $encPass);
                } catch (Throwable $e) {
                    $skipped[] = ['path' => $rel, 'why' => 'رمزنگاری ناموفق — رد شد'];
                    continue;
                }
                $rel .= '.enc';
                $size = strlen($data);
                $encrypted[] = $rel;
            } elseif (!$withSecrets) {
                $skipped[] = ['path' => $rel, 'why' => '🔑 حاوی کلید — رد شد'];
                continue;
            }
        }

        if ($bytes + $size > $maxBytes) { $skipped[] = ['path' => $rel, 'why' => 'عبور از سقف حجم کل']; continue; }

        $files[] = ['path' => $rel, 'size' => $size, 'data' => $data, 'enc' => $isSecret && $encMode];
        $bytes += $size;
    }

    usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
    usort($skipped, fn($a, $b) => strcmp($a['path'], $b['path']));
    return ['files' => $files, 'skipped' => $skipped, 'bytes' => $bytes,
            'secret_hits' => $secretHits, 'encrypted' => $encrypted];
}

/** درخواست به GitHub API با متد دلخواه */
function wb_api(string $method, string $url, string $token, ?array $body = null): array {
    $hdr = [
        'User-Agent: deploy-panel-backup',
        'Accept: application/vnd.github+json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!function_exists('curl_init')) return ['ok' => false, 'code' => 0, 'error' => 'cURL در دسترس نیست', 'data' => null];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => $hdr, CURLOPT_ENCODING => '',
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'code' => 0, 'error' => $err ?: 'ارتباط ناموفق', 'data' => null];
    $d = json_decode((string)$raw, true);
    if ($code >= 200 && $code < 300) return ['ok' => true, 'code' => $code, 'error' => '', 'data' => $d];

    $m = is_array($d) && !empty($d['message']) ? $d['message'] : ('HTTP ' . $code);
    if ($code === 401) $m = 'توکن گیت‌هاب نامعتبر است (۴۰۱)';
    if ($code === 403) $m = 'دسترسی رد شد (۴۰۳) — توکن باید مجوز repo داشته باشد';
    if ($code === 404) $m = 'ریپو یا برنچ پیدا نشد (۴۰۴)';
    return ['ok' => false, 'code' => $code, 'error' => $m, 'data' => $d];
}

/**
 * ساخت کامیت با Git Data API:
 *   blob برای هر فایل → یک tree → commit → به‌روزرسانی ref
 */
function wb_push(string $repo, string $branch, string $message, string $token, array $files): array {
    $base = 'https://api.github.com/repos/' . $repo;
    $steps = [];
    $step = function (string $l, bool $ok, string $n = '') use (&$steps) { $steps[] = ['label' => $l, 'ok' => $ok, 'note' => $n]; };

    // ۱) کامیت پایه: برنچ موجود یا برنچ پیش‌فرض
    $parent = null; $baseTree = null; $created = false;
    $ref = wb_api('GET', $base . '/git/ref/heads/' . rawurlencode($branch), $token);
    if ($ref['ok']) {
        $parent = $ref['data']['object']['sha'] ?? null;
        $step('برنچ موجود', true, $branch);
    } else {
        $repoInfo = wb_api('GET', $base, $token);
        if (!$repoInfo['ok']) { $step('خواندن ریپو', false, $repoInfo['error']); return ['ok' => false, 'error' => $repoInfo['error'], 'steps' => $steps]; }
        $def = $repoInfo['data']['default_branch'] ?? 'main';
        $defRef = wb_api('GET', $base . '/git/ref/heads/' . rawurlencode($def), $token);
        if ($defRef['ok']) $parent = $defRef['data']['object']['sha'] ?? null;
        $created = true;
        $step('برنچ جدید ساخته می‌شود', true, $branch . ' (از ' . $def . ')');
    }
    if ($parent) {
        $pc = wb_api('GET', $base . '/git/commits/' . $parent, $token);
        if ($pc['ok']) $baseTree = $pc['data']['tree']['sha'] ?? null;
    }

    // ۲) blob برای هر فایل
    $tree = [];
    foreach ($files as $f) {
        $b = wb_api('POST', $base . '/git/blobs', $token, [
            'content'  => base64_encode($f['data']),
            'encoding' => 'base64',
        ]);
        if (!$b['ok']) { $step('آپلود ' . $f['path'], false, $b['error']); return ['ok' => false, 'error' => 'آپلود ناموفق: ' . $f['path'] . ' — ' . $b['error'], 'steps' => $steps]; }
        $tree[] = ['path' => $f['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => $b['data']['sha']];
    }
    $step('آپلود فایل‌ها', true, count($tree) . ' فایل');

    // ۳) درخت
    $tp = ['tree' => $tree];
    if ($baseTree) $tp['base_tree'] = $baseTree;
    $t = wb_api('POST', $base . '/git/trees', $token, $tp);
    if (!$t['ok']) { $step('ساخت درخت', false, $t['error']); return ['ok' => false, 'error' => $t['error'], 'steps' => $steps]; }
    $step('ساخت درخت', true);

    // ۴) کامیت
    $cp = ['message' => $message, 'tree' => $t['data']['sha']];
    if ($parent) $cp['parents'] = [$parent];
    $c = wb_api('POST', $base . '/git/commits', $token, $cp);
    if (!$c['ok']) { $step('ساخت کامیت', false, $c['error']); return ['ok' => false, 'error' => $c['error'], 'steps' => $steps]; }
    $sha = $c['data']['sha'];
    $step('ساخت کامیت', true, substr($sha, 0, 7));

    // ۵) به‌روزرسانی/ساخت ref
    if ($created) {
        $u = wb_api('POST', $base . '/git/refs', $token, ['ref' => 'refs/heads/' . $branch, 'sha' => $sha]);
    } else {
        $u = wb_api('PATCH', $base . '/git/refs/heads/' . rawurlencode($branch), $token, ['sha' => $sha, 'force' => false]);
    }
    if (!$u['ok']) { $step('ثبت برنچ', false, $u['error']); return ['ok' => false, 'error' => $u['error'], 'steps' => $steps]; }
    $step('ثبت برنچ', true, $branch);

    return ['ok' => true, 'branch' => $branch, 'commit' => substr($sha, 0, 7),
            'count' => count($tree), 'created' => $created, 'steps' => $steps,
            'url' => 'https://github.com/' . $repo . '/tree/' . $branch];
}

/* =====================================================================
 *  رمزنگاری فایل‌های حساس
 *  AES-256-GCM با کلید مشتق‌شده از عبارت عبور (PBKDF2-SHA256).
 *  GCM انتخاب شده چون علاوه بر رمزنگاری، دستکاری را هم تشخیص می‌دهد.
 * ===================================================================== */

const WB_ENC_MAGIC = 'SCRENC1';
const WB_ENC_ITER  = 210000;   // مطابق توصیهٔ OWASP برای PBKDF2-SHA256

/** فایل رمزنگاری‌شده را به شکل متنی و قابل نگهداری در گیت برمی‌گرداند */
function wb_encrypt(string $plain, string $pass): string {
    $salt = random_bytes(16);
    $iv   = random_bytes(12);                       // اندازهٔ استاندارد GCM
    $key  = hash_pbkdf2('sha256', $pass, $salt, WB_ENC_ITER, 32, true);
    $tag  = '';
    $ct   = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('رمزنگاری ناموفق');

    // هدر خوانا + بدنهٔ base64 با طول ثابت، تا diff گیت قابل فهم بماند
    $b64 = chunk_split(base64_encode($ct), 76, "\n");
    return WB_ENC_MAGIC . "\n"
         . 'iter:' . WB_ENC_ITER . "\n"
         . 'salt:' . base64_encode($salt) . "\n"
         . 'iv:'   . base64_encode($iv) . "\n"
         . 'tag:'  . base64_encode($tag) . "\n"
         . "--\n" . $b64;
}

/** رمزگشایی؛ در صورت اشتباه بودن رمز یا دستکاری فایل، null برمی‌گرداند */
function wb_decrypt(string $blob, string $pass): ?string {
    if (strncmp($blob, WB_ENC_MAGIC, strlen(WB_ENC_MAGIC)) !== 0) return null;
    $parts = explode("--\n", $blob, 2);
    if (count($parts) !== 2) return null;
    $head = $parts[0]; $body = preg_replace('~\s+~', '', $parts[1]);

    $get = function (string $k) use ($head): string {
        return preg_match('~^' . $k . ':(.*)$~m', $head, $m) ? trim($m[1]) : '';
    };
    $iter = (int)$get('iter'); $salt = base64_decode($get('salt'), true);
    $iv = base64_decode($get('iv'), true); $tag = base64_decode($get('tag'), true);
    $ct = base64_decode($body, true);
    if ($iter < 1000 || !$salt || !$iv || !$tag || $ct === false) return null;

    $key = hash_pbkdf2('sha256', $pass, $salt, $iter, 32, true);
    $out = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $out === false ? null : $out;   // false یعنی رمز غلط یا فایل دستکاری شده
}

/**
 * هستهٔ استقرار — همهٔ مسیرها از اینجا عبور می‌کنند.
 */
function do_deploy(array $job, array $cfg, bool $dryRun = false): array {
    $repo   = trim($job['repo']   ?? $cfg['repo']   ?? '');
    $branch = trim($job['branch'] ?? $cfg['branch'] ?? '');
    $src    = trim($job['source'] ?? '');
    $destN  = trim($job['dest']   ?? '');
    $folder = trim($job['folder'] ?? '');
    $chkPhp = !array_key_exists('check_php', $job) || !empty($job['check_php']);
    $doBak  = !array_key_exists('backup', $job) || !empty($job['backup']);
    $minB   = isset($job['min_bytes']) ? max(0, (int)$job['min_bytes']) : MIN_BYTES;
    $perm   = isset($job['chmod']) && $job['chmod'] !== '' ? $job['chmod'] : '';

    $steps = [];
    $addStep = function (string $label, bool $ok, string $note = '') use (&$steps) {
        $steps[] = ['label' => $label, 'ok' => $ok, 'note' => $note];
    };

    if ($repo === '' || $branch === '' || $src === '') {
        return ['ok' => false, 'error' => 'ریپو، برنچ و فایل مبدأ الزامی هستند', 'steps' => $steps];
    }
    if ($destN === '') $destN = basename($src);
    $destN = basename($destN);
    if ($destN === '' || $destN === '.' || $destN === '..') {
        return ['ok' => false, 'error' => 'نام فایل مقصد نامعتبر است', 'steps' => $steps];
    }

    // مسیر مقصد
    $dirAbs = safe_path($folder, true);
    if ($dirAbs === null) {
        return ['ok' => false, 'error' => 'پوشهٔ مقصد نامعتبر است (خارج از ریشه)', 'steps' => $steps];
    }
    if (!is_dir($dirAbs)) {
        if ($dryRun) {
            $addStep('پوشهٔ مقصد', true, 'ساخته خواهد شد: ' . ($folder ?: '.'));
        } elseif (!@mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
            return ['ok' => false, 'error' => 'ساخت پوشهٔ مقصد ناموفق: ' . $folder, 'steps' => $steps];
        }
    }
    $dest = rtrim($dirAbs, '/') . '/' . $destN;
    $relDest = ltrim(($folder !== '' ? rtrim($folder, '/') . '/' : '') . $destN, '/');

    // ۱) دانلود
    $url = gh_raw_url($repo, $branch, $src);
    $ghTok = $cfg['github_token'] ?? '';
    $res = http_get($url, $ghTok);
    // یک توکن نامعتبر باعث ۴۰۱ می‌شود حتی روی ریپوی عمومی؛ بدون توکن دوباره تلاش کن
    if (!$res['ok'] && $res['code'] === 401 && $ghTok !== '') {
        $retry = http_get($url, '');
        if ($retry['ok']) {
            $res = $retry;
            $addStep('توکن گیت‌هاب', true, 'توکن نامعتبر بود — بدون آن ادامه داده شد');
        }
    }
    if (!$res['ok']) {
        $hint = '';
        if ($res['code'] === 404)      $hint = ' (مسیر یا برنچ اشتباه است؟)';
        elseif ($res['code'] === 401)  $hint = ' — توکن گیت‌هاب نامعتبر است؛ در تنظیمات __CLEAR__ بگذارید';
        elseif ($res['code'] === 403)  $hint = ($ghTok !== '' ? ' — توکن مجوز لازم را ندارد' : ' — محدودیت نرخ گیت‌هاب، کمی بعد تلاش کنید');
        $addStep('دانلود از گیت‌هاب', false, $res['error'] . $hint);
        return ['ok' => false, 'error' => 'دانلود ناموفق: ' . $res['error'] . $hint, 'steps' => $steps];
    }
    $body = $res['body'];
    $size = strlen($body);
    $addStep('دانلود از گیت‌هاب', true, human_size($size));

    // ۲) حجم
    if ($size < $minB) {
        $addStep('بررسی حجم', false, $size . ' بایت — کمتر از حد مجاز ' . $minB);
        return ['ok' => false, 'error' => 'حجم فایل مشکوک است؛ نصب لغو شد', 'steps' => $steps];
    }
    $addStep('بررسی حجم', true, human_size($size));

    // ۳) بررسی نحوی PHP
    $isPhp = preg_match('~\.php\d?$~i', $destN) === 1;
    if ($chkPhp && $isPhp) {
        if (strncmp(ltrim($body), '<?php', 5) !== 0 && strncmp(ltrim($body), '<?', 2) !== 0) {
            $addStep('اعتبارسنجی PHP', false, 'فایل با <?php شروع نمی‌شود');
            return ['ok' => false, 'error' => 'محتوا کد PHP نیست (صفحهٔ خطا دریافت شد؟)', 'steps' => $steps];
        }
        if (!php_syntax_check($body, $synErr)) {
            $addStep('اعتبارسنجی PHP', false, $synErr);
            return ['ok' => false, 'error' => 'خطای نحوی در فایل دریافتی؛ نصب لغو شد', 'steps' => $steps];
        }
        $addStep('اعتبارسنجی PHP', true, 'بدون خطای نحوی');
    } elseif ($chkPhp) {
        $addStep('اعتبارسنجی PHP', true, 'رد شد (فایل PHP نیست)');
    }

    // ۴) مقایسه
    $newHash = hash('sha256', $body);
    $oldHash = is_file($dest) ? (string)hash_file('sha256', $dest) : '';
    $exists  = is_file($dest);
    $same    = $exists && $oldHash === $newHash;

    if ($same) {
        $addStep('مقایسه با نسخهٔ فعلی', true, 'یکسان است — نیازی به نصب نیست');
        return ['ok' => true, 'changed' => false, 'same' => true, 'dest' => $relDest,
                'size' => $size, 'hash' => $newHash, 'steps' => $steps,
                'message' => 'بدون تغییر — فایل روی هاست از قبل به‌روز است'];
    }
    $addStep('مقایسه با نسخهٔ فعلی', true, $exists ? 'متفاوت است — به‌روزرسانی لازم است' : 'فایل جدید است');

    if ($dryRun) {
        return ['ok' => true, 'changed' => true, 'dry' => true, 'dest' => $relDest,
                'size' => $size, 'hash' => $newHash, 'old_hash' => $oldHash, 'steps' => $steps,
                'message' => $exists ? 'نسخهٔ جدید آماده نصب است (اجرا نشد)' : 'فایل جدید آماده نصب است (اجرا نشد)'];
    }

    // ۵) قابل نوشتن؟
    $probe = $exists ? $dest : $dirAbs;
    if (!is_writable($probe)) {
        $addStep('بررسی دسترسی نوشتن', false, 'قابل نوشتن نیست: ' . $relDest);
        return ['ok' => false, 'error' => 'سطح دسترسی اجازهٔ نوشتن نمی‌دهد (chmod را بررسی کنید)', 'steps' => $steps];
    }
    $addStep('بررسی دسترسی نوشتن', true);

    // ۶) بکاپ
    $bakName = '';
    if ($doBak && $exists) {
        ensure_backup_dir();
        $bakName = $destN . '.' . date('Ymd-His') . '.bak';
        if (!@copy($dest, BACKUP_DIR . '/' . $bakName)) {
            $addStep('بکاپ نسخهٔ فعلی', false, 'کپی ناموفق');
            return ['ok' => false, 'error' => 'بکاپ‌گیری ناموفق بود؛ برای امنیت، نصب لغو شد', 'steps' => $steps];
        }
        $addStep('بکاپ نسخهٔ فعلی', true, $bakName);
    }

    // ۷) نوشتن اتمیک
    $tmp = $dest . '.tmp-' . bin2hex(random_bytes(5));
    if (@file_put_contents($tmp, $body, LOCK_EX) !== $size) {
        @unlink($tmp);
        $addStep('نوشتن فایل', false, 'نوشتن ناقص (فضای دیسک؟)');
        return ['ok' => false, 'error' => 'نوشتن فایل موقت ناموفق بود', 'steps' => $steps];
    }
    if (hash_file('sha256', $tmp) !== $newHash) {
        @unlink($tmp);
        $addStep('نوشتن فایل', false, 'هش فایل نوشته‌شده مطابقت ندارد');
        return ['ok' => false, 'error' => 'فایل نوشته‌شده مخدوش است؛ نصب لغو شد', 'steps' => $steps];
    }
    if ($perm !== '' && preg_match('~^0?[0-7]{3}$~', $perm)) {
        @chmod($tmp, intval($perm, 8));
    } elseif ($exists) {
        @chmod($tmp, fileperms($dest) & 0777);
    }
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        $addStep('جایگزینی اتمیک', false, 'rename ناموفق');
        return ['ok' => false, 'error' => 'جایگزینی فایل ناموفق بود', 'steps' => $steps];
    }
    $addStep('جایگزینی اتمیک', true, $relDest);

    @clearstatcache(true, $dest);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
    if ($doBak) prune_backups($destN);

    return ['ok' => true, 'changed' => true, 'dest' => $relDest, 'size' => $size,
            'hash' => $newHash, 'old_hash' => $oldHash, 'backup' => $bakName,
            'steps' => $steps, 'message' => 'با موفقیت نصب شد'];
}

// ==================================================================
//  احراز هویت
// ==================================================================

$cfg     = cfg_load();
$isSetup = empty($cfg['password_hash']);

if (session_status() === PHP_SESSION_NONE) {
    @session_name('deploypanel');
    @session_start();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isApi  = $action !== '';

/** درخواست‌های خودکار (cron / اسکریپت) با api_token */
function api_token_ok(array $cfg): bool {
    if (empty($cfg['api_token'])) return false;
    $t = $_GET['api_token'] ?? $_POST['api_token'] ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals($cfg['api_token'], $t);
}

$loggedIn = !empty($_SESSION['deploy_ok']) && !$isSetup;
$apiAuth  = api_token_ok($cfg);
$authed   = $loggedIn || $apiAuth;
$isCli    = PHP_SAPI === 'cli';
if ($isCli) $authed = true;

// ---------- اجرای خط فرمان (cron) ----------
if ($isCli && !$isSetup) {
    $jobName = $argv[1] ?? '';
    $jobs = $cfg['jobs'] ?? [];
    $run = [];
    foreach ($jobs as $j) {
        if ($jobName === '' || ($j['name'] ?? '') === $jobName) $run[] = $j;
    }
    if (!$run) { fwrite(STDERR, "کاری برای اجرا یافت نشد.\n"); exit(1); }
    $bad = 0;
    foreach ($run as $j) {
        $r = do_deploy($j, $cfg, false);
        $tag = $r['ok'] ? ($r['changed'] ?? false ? 'به‌روز شد' : 'بدون تغییر') : 'ناموفق';
        echo sprintf("[%s] %s → %s : %s\n", $tag, $j['source'] ?? '?', $r['dest'] ?? '?', $r['ok'] ? ($r['message'] ?? '') : ($r['error'] ?? ''));
        log_add(['type' => 'cron', 'job' => $j['name'] ?? '', 'ok' => $r['ok'],
                 'changed' => $r['changed'] ?? false, 'dest' => $r['dest'] ?? '',
                 'msg' => $r['ok'] ? ($r['message'] ?? '') : ($r['error'] ?? '')]);
        if (!$r['ok']) $bad++;
    }
    exit($bad ? 1 : 0);
}

// ==================================================================
//  API
// ==================================================================

if ($isApi) {

    // --- راه‌اندازی اولیه ---
    if ($action === 'setup') {
        if (!$isSetup) jout(['ok' => false, 'error' => 'قبلاً راه‌اندازی شده است'], 400);
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) jout(['ok' => false, 'error' => 'رمز باید حداقل ۸ کاراکتر باشد'], 400);
        $c = cfg_default();
        $c['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        $c['api_token']     = bin2hex(random_bytes(24));
        $c['repo']          = trim((string)($_POST['repo'] ?? 'fazilatma/code'));
        if (!cfg_save($c)) jout(['ok' => false, 'error' => 'ذخیرهٔ تنظیمات ناموفق — سطح دسترسی پوشه را بررسی کنید'], 500);
        ensure_self_guard();
        ensure_backup_dir();
        $_SESSION['deploy_ok'] = true;
        jout(['ok' => true]);
    }

    if ($action === 'login') {
        if ($isSetup) jout(['ok' => false, 'error' => 'ابتدا راه‌اندازی کنید'], 400);
        $pw = (string)($_POST['password'] ?? '');
        usleep(300000); // کند کردن حملهٔ brute force
        if (!password_verify($pw, $cfg['password_hash'])) {
            log_add(['type' => 'login_fail', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            jout(['ok' => false, 'error' => 'رمز نادرست است'], 403);
        }
        $_SESSION['deploy_ok'] = true;
        jout(['ok' => true]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        @session_destroy();
        jout(['ok' => true]);
    }

    if (!$authed) jout(['ok' => false, 'error' => 'ابتدا وارد شوید', 'need_auth' => true], 403);

    // --- لیست برنچ‌ها ---
    if ($action === 'branches') {
        $repo = trim((string)($_GET['repo'] ?? $cfg['repo'] ?? ''));
        if ($repo === '') jout(['ok' => false, 'error' => 'ریپو مشخص نشده']);
        $r = http_get('https://api.github.com/repos/' . $repo . '/branches?per_page=100', $cfg['github_token'] ?? '', true);
        if (!$r['ok']) {
            $msg = $r['code'] === 404 ? 'ریپو یافت نشد (خصوصی است؟ توکن گیت‌هاب لازم است)' : $r['error'];
            jout(['ok' => false, 'error' => $msg]);
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d)) jout(['ok' => false, 'error' => 'پاسخ نامعتبر از گیت‌هاب']);
        $out = [];
        foreach ($d as $b) {
            if (!empty($b['name'])) $out[] = ['name' => $b['name'], 'sha' => substr((string)($b['commit']['sha'] ?? ''), 0, 7)];
        }
        jout(['ok' => true, 'branches' => $out]);
    }

    // --- درخت فایل‌های یک برنچ ---
    if ($action === 'tree') {
        $repo   = trim((string)($_GET['repo'] ?? $cfg['repo'] ?? ''));
        $branch = trim((string)($_GET['branch'] ?? ''));
        if ($repo === '' || $branch === '') jout(['ok' => false, 'error' => 'ریپو و برنچ لازم است']);
        $u = 'https://api.github.com/repos/' . $repo . '/git/trees/' . rawurlencode($branch) . '?recursive=1';
        $r = http_get($u, $cfg['github_token'] ?? '', true);
        if (!$r['ok']) jout(['ok' => false, 'error' => $r['error']]);
        $d = json_decode($r['body'], true);
        if (!isset($d['tree']) || !is_array($d['tree'])) jout(['ok' => false, 'error' => 'درخت فایل دریافت نشد']);
        $files = [];
        foreach ($d['tree'] as $n) {
            if (($n['type'] ?? '') !== 'blob') continue;
            $files[] = ['path' => $n['path'], 'size' => (int)($n['size'] ?? 0)];
        }
        usort($files, fn($a, $b) => strcasecmp($a['path'], $b['path']));
        jout(['ok' => true, 'files' => $files, 'truncated' => !empty($d['truncated'])]);
    }

    // --- پوشه‌های موجود روی هاست ---
    if ($action === 'folders') {
        $out = [''];
        $rii = @scandir(__DIR__);
        if (is_array($rii)) {
            foreach ($rii as $e) {
                if ($e === '.' || $e === '..') continue;
                if ($e[0] === '.' || $e === '_backups') continue;
                if (is_dir(__DIR__ . '/' . $e)) {
                    $out[] = $e;
                    $sub = @scandir(__DIR__ . '/' . $e);
                    if (is_array($sub)) {
                        foreach ($sub as $s) {
                            if ($s === '.' || $s === '..' || $s[0] === '.') continue;
                            if (is_dir(__DIR__ . '/' . $e . '/' . $s)) $out[] = $e . '/' . $s;
                        }
                    }
                }
            }
        }
        sort($out);
        jout(['ok' => true, 'folders' => array_values(array_unique($out)), 'root' => basename(__DIR__)]);
    }

    // --- وضعیت فایل روی هاست ---
    if ($action === 'local_info') {
        $folder = (string)($_GET['folder'] ?? '');
        $name   = basename((string)($_GET['dest'] ?? ''));
        $dirAbs = safe_path($folder, true);
        if ($dirAbs === null || $name === '') jout(['ok' => true, 'exists' => false]);
        $p = rtrim($dirAbs, '/') . '/' . $name;
        if (!is_file($p)) jout(['ok' => true, 'exists' => false]);
        jout(['ok' => true, 'exists' => true, 'size' => filesize($p),
              'size_h' => human_size((int)filesize($p)),
              'mtime' => date('Y-m-d H:i:s', (int)filemtime($p)),
              'hash' => substr((string)hash_file('sha256', $p), 0, 12),
              'writable' => is_writable($p),
              'perms' => substr(sprintf('%o', fileperms($p)), -4)]);
    }

    // --- اجرای استقرار / بررسی ---
    if ($action === 'deploy' || $action === 'dryrun') {
        $job = [
            'repo'      => (string)($_POST['repo'] ?? ''),
            'branch'    => (string)($_POST['branch'] ?? ''),
            'source'    => (string)($_POST['source'] ?? ''),
            'dest'      => (string)($_POST['dest'] ?? ''),
            'folder'    => (string)($_POST['folder'] ?? ''),
            'check_php' => !empty($_POST['check_php']),
            'backup'    => !empty($_POST['backup']),
            'chmod'     => (string)($_POST['chmod'] ?? ''),
            'min_bytes' => (string)($_POST['min_bytes'] ?? '') !== '' ? (int)$_POST['min_bytes'] : MIN_BYTES,
        ];
        $r = do_deploy($job, $cfg, $action === 'dryrun');
        log_add(['type' => $action, 'source' => $job['source'], 'dest' => $r['dest'] ?? '',
                 'ok' => $r['ok'], 'changed' => $r['changed'] ?? false,
                 'msg' => $r['ok'] ? ($r['message'] ?? '') : ($r['error'] ?? '')]);
        jout($r);
    }

    // --- اجرای گروهی کارهای ذخیره‌شده ---
    if ($action === 'run_jobs') {
        $names = json_decode((string)($_POST['names'] ?? '[]'), true);
        $dry   = !empty($_POST['dry']);
        if (!is_array($names)) $names = [];
        $jobs = $cfg['jobs'] ?? [];
        $results = [];
        foreach ($jobs as $j) {
            if ($names && !in_array($j['name'] ?? '', $names, true)) continue;
            $r = do_deploy($j, $cfg, $dry);
            $results[] = ['name' => $j['name'] ?? '', 'result' => $r];
            log_add(['type' => $dry ? 'dryrun_job' : 'deploy_job', 'job' => $j['name'] ?? '',
                     'ok' => $r['ok'], 'changed' => $r['changed'] ?? false, 'dest' => $r['dest'] ?? '',
                     'msg' => $r['ok'] ? ($r['message'] ?? '') : ($r['error'] ?? '')]);
        }
        jout(['ok' => true, 'results' => $results]);
    }

    // --- مدیریت کارها ---
    if ($action === 'jobs_list') {
        jout(['ok' => true, 'jobs' => array_values($cfg['jobs'] ?? [])]);
    }

    if ($action === 'job_save') {
        $j = [
            'name'      => trim((string)($_POST['name'] ?? '')),
            'repo'      => trim((string)($_POST['repo'] ?? '')),
            'branch'    => trim((string)($_POST['branch'] ?? '')),
            'source'    => trim((string)($_POST['source'] ?? '')),
            'dest'      => trim((string)($_POST['dest'] ?? '')),
            'folder'    => trim((string)($_POST['folder'] ?? '')),
            'check_php' => !empty($_POST['check_php']),
            'backup'    => !empty($_POST['backup']),
            'chmod'     => trim((string)($_POST['chmod'] ?? '')),
            'min_bytes' => (string)($_POST['min_bytes'] ?? '') !== '' ? (int)$_POST['min_bytes'] : MIN_BYTES,
        ];
        if ($j['name'] === '')   jout(['ok' => false, 'error' => 'نام کار الزامی است']);
        if ($j['source'] === '') jout(['ok' => false, 'error' => 'فایل مبدأ الزامی است']);
        $jobs = $cfg['jobs'] ?? [];
        $found = false;
        foreach ($jobs as $i => $old) {
            if (($old['name'] ?? '') === $j['name']) { $jobs[$i] = $j; $found = true; break; }
        }
        if (!$found) $jobs[] = $j;
        $cfg['jobs'] = array_values($jobs);
        if (!cfg_save($cfg)) jout(['ok' => false, 'error' => 'ذخیره ناموفق']);
        jout(['ok' => true, 'jobs' => $cfg['jobs'], 'updated' => $found]);
    }

    if ($action === 'job_delete') {
        $n = trim((string)($_POST['name'] ?? ''));
        $jobs = array_values(array_filter($cfg['jobs'] ?? [], fn($j) => ($j['name'] ?? '') !== $n));
        $cfg['jobs'] = $jobs;
        cfg_save($cfg);
        jout(['ok' => true, 'jobs' => $jobs]);
    }

    // --- بکاپ‌ها ---
    /* =============================================================
     * پشتیبان‌گیری از ورک‌اسپیس هاست به گیت‌هاب
     * فایل‌ها با Git Data API آپلود می‌شوند (blob → tree → commit)،
     * پس یک کامیت تمیز ساخته می‌شود و تاریخچه دست‌نخورده می‌ماند.
     * ============================================================= */

    // پیش‌نمایش: چه چیزی آپلود می‌شود و چه چیزی رد می‌شود
    if ($action === 'wb_scan') {
        $inc = trim((string)($_POST['include'] ?? $_GET['include'] ?? ''));
        $secrets = !empty($_POST['secrets'] ?? $_GET['secrets'] ?? '');
        $pass = (string)($_POST['enc_pass'] ?? '');
        $r = wb_collect($inc, $secrets, 25000000, $pass);
        // فقط فهرست برمی‌گردد، نه محتوای فایل‌ها
        $list = array_map(fn($f) => ['path' => $f['path'], 'size' => $f['size'], 'enc' => !empty($f['enc'])], $r['files']);
        jout(['ok' => true, 'files' => $list, 'skipped' => $r['skipped'],
              'total_bytes' => $r['bytes'], 'total_h' => human_size($r['bytes']),
              'secret_hits' => $r['secret_hits'], 'encrypted' => $r['encrypted']]);
    }

    /* بازگرداندن یک فایل رمزشده از گیت‌هاب به هاست */
    if ($action === 'wb_restore') {
        $repo   = trim((string)($_POST['repo'] ?? $cfg['repo'] ?? ''));
        $branch = trim((string)($_POST['branch'] ?? ''));
        $path   = trim((string)($_POST['path'] ?? ''));
        $pass   = (string)($_POST['enc_pass'] ?? '');
        if (!preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) jout(['ok' => false, 'error' => 'نام ریپو نامعتبر']);
        if ($branch === '' || $path === '')            jout(['ok' => false, 'error' => 'برنچ و مسیر فایل لازم است']);
        if ($pass === '')                              jout(['ok' => false, 'error' => 'عبارت رمز لازم است']);
        if (substr($path, -4) !== '.enc')              jout(['ok' => false, 'error' => 'فقط فایل‌های .enc قابل بازگردانی‌اند']);

        $res = http_get(gh_raw_url($repo, $branch, $path), $cfg['github_token'] ?? '');
        if (!$res['ok']) jout(['ok' => false, 'error' => 'دانلود ناموفق: ' . $res['error']]);

        $plain = wb_decrypt($res['body'], $pass);
        if ($plain === null) jout(['ok' => false, 'error' => 'رمزگشایی ناموفق — عبارت رمز اشتباه است یا فایل دستکاری شده']);

        $dest = safe_path(substr($path, 0, -4));   // حذف پسوند .enc
        if ($dest === null) jout(['ok' => false, 'error' => 'مسیر مقصد نامعتبر']);
        if (is_file($dest)) {
            ensure_backup_dir();
            @copy($dest, BACKUP_DIR . '/' . basename($dest) . '.' . date('Ymd-His') . '.bak');
        }
        if (@file_put_contents($dest, $plain, LOCK_EX) === false) jout(['ok' => false, 'error' => 'نوشتن فایل ناموفق']);
        @chmod($dest, 0600);
        log_add(['type' => 'wb_restore', 'dest' => basename($dest), 'ok' => true]);
        jout(['ok' => true, 'restored' => basename($dest), 'size' => strlen($plain)]);
    }

    if ($action === 'wb_push') {
        $repo   = trim((string)($_POST['repo'] ?? $cfg['repo'] ?? ''));
        $branch = trim((string)($_POST['branch'] ?? '')) ?: ('host-backup/' . date('Ymd-His'));
        $inc    = trim((string)($_POST['include'] ?? ''));
        $secrets= !empty($_POST['secrets']);
        $msg    = trim((string)($_POST['message'] ?? '')) ?: ('Host workspace backup ' . date('Y-m-d H:i'));
        $tok    = trim((string)($_POST['gh_token'] ?? '')) ?: (string)($cfg['github_token'] ?? '');

        if (!preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) jout(['ok' => false, 'error' => 'نام ریپو نامعتبر (قالب user/repo)']);
        if ($tok === '') jout(['ok' => false, 'error' => 'برای نوشتن در گیت‌هاب توکن لازم است (دسترسی repo)']);
        // نام برنچ نباید شامل .. یا / ابتدایی/انتهایی باشد (گیت هم اجازه نمی‌دهد)
        if (!preg_match('~^[\w][\w./-]*$~', $branch) || strpos($branch, '..') !== false
            || substr($branch, -1) === '/' || strpos($branch, '//') !== false) {
            jout(['ok' => false, 'error' => 'نام برنچ نامعتبر است']);
        }

        $pass = (string)($_POST['enc_pass'] ?? '');
        if ($pass !== '' && strlen($pass) < 10) {
            jout(['ok' => false, 'error' => 'عبارت رمز باید حداقل ۱۰ کاراکتر باشد']);
        }
        $r = wb_collect($inc, $secrets, 25000000, $pass);
        if (!$r['files']) jout(['ok' => false, 'error' => 'فایلی برای آپلود پیدا نشد']);
        $out = wb_push($repo, $branch, $msg, $tok, $r['files']);
        $out['encrypted'] = $r['encrypted'] ?? [];
        jout($out);
    }

    if ($action === 'backups') {
        ensure_backup_dir();
        $files = glob(BACKUP_DIR . '/*.bak') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $out = [];
        foreach ($files as $f) {
            $b = basename($f);
            $out[] = ['file' => $b, 'target' => preg_replace('~\.\d{8}-\d{6}\.bak$~', '', $b),
                      'size' => human_size((int)filesize($f)),
                      'time' => date('Y-m-d H:i:s', (int)filemtime($f))];
        }
        jout(['ok' => true, 'backups' => $out]);
    }

    if ($action === 'restore') {
        $b = basename((string)($_POST['file'] ?? ''));
        $folder = (string)($_POST['folder'] ?? '');
        if ($b === '' || !preg_match('~\.bak$~', $b)) jout(['ok' => false, 'error' => 'نام بکاپ نامعتبر']);
        $src = BACKUP_DIR . '/' . $b;
        if (!is_file($src)) jout(['ok' => false, 'error' => 'بکاپ یافت نشد']);
        $target = preg_replace('~\.\d{8}-\d{6}\.bak$~', '', $b);
        $dirAbs = safe_path($folder, true);
        if ($dirAbs === null) jout(['ok' => false, 'error' => 'پوشهٔ مقصد نامعتبر']);
        $dest = rtrim($dirAbs, '/') . '/' . $target;

        // پیش از بازگردانی، از وضعیت فعلی هم بکاپ بگیر
        if (is_file($dest)) @copy($dest, BACKUP_DIR . '/' . $target . '.' . date('Ymd-His') . '.bak');
        if (!@copy($src, $dest)) jout(['ok' => false, 'error' => 'بازگردانی ناموفق (دسترسی نوشتن؟)']);
        @clearstatcache(true, $dest);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);
        log_add(['type' => 'restore', 'file' => $b, 'dest' => $target, 'ok' => true]);
        jout(['ok' => true, 'message' => 'بازگردانی شد: ' . $target]);
    }

    if ($action === 'backup_delete') {
        $b = basename((string)($_POST['file'] ?? ''));
        if ($b === '' || !preg_match('~\.bak$~', $b)) jout(['ok' => false, 'error' => 'نامعتبر']);
        @unlink(BACKUP_DIR . '/' . $b);
        jout(['ok' => true]);
    }

    // --- تاریخچه ---
    if ($action === 'history') jout(['ok' => true, 'log' => log_read()]);
    if ($action === 'history_clear') { @unlink(LOG_FILE); jout(['ok' => true]); }

    // --- تنظیمات ---
    if ($action === 'settings_get') {
        jout(['ok' => true, 'settings' => [
            'repo'       => $cfg['repo'] ?? '',
            'branch'     => $cfg['branch'] ?? '',
            'api_token'  => $cfg['api_token'] ?? '',
            'has_gh'     => !empty($cfg['github_token']),
            'version'    => DEPLOY_VERSION,
            'php'        => PHP_VERSION,
            'dir'        => __DIR__,
            'writable'   => is_writable(__DIR__),
            'curl'       => function_exists('curl_init'),
        ]]);
    }

    if ($action === 'settings_save') {
        $cfg['repo']   = trim((string)($_POST['repo'] ?? $cfg['repo']));
        $cfg['branch'] = trim((string)($_POST['branch'] ?? $cfg['branch']));
        $gh = (string)($_POST['github_token'] ?? '');
        if ($gh === '__CLEAR__')      $cfg['github_token'] = '';
        elseif (trim($gh) !== '')     $cfg['github_token'] = trim($gh);
        if (!empty($_POST['new_password'])) {
            $np = (string)$_POST['new_password'];
            if (strlen($np) < 8) jout(['ok' => false, 'error' => 'رمز جدید باید حداقل ۸ کاراکتر باشد']);
            $cfg['password_hash'] = password_hash($np, PASSWORD_DEFAULT);
        }
        if (!empty($_POST['regen_token'])) $cfg['api_token'] = bin2hex(random_bytes(24));
        if (!cfg_save($cfg)) jout(['ok' => false, 'error' => 'ذخیره ناموفق']);
        jout(['ok' => true, 'api_token' => $cfg['api_token']]);
    }

    jout(['ok' => false, 'error' => 'عملیات ناشناخته: ' . $action], 400);
}

// ==================================================================
//  رابط کاربری
// ==================================================================
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>پنل استقرار گیت‌هاب</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:Tahoma,system-ui,-apple-system,sans-serif;background:#0f172a;color:#e2e8f0;
     min-height:100vh;padding:16px;line-height:1.7;direction:rtl}
.wrap{max-width:1100px;margin:0 auto}
h1{font-size:19px;margin-bottom:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sub{font-size:12px;color:#64748b;margin-bottom:18px}
.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:16px;margin-bottom:14px}
.card h2{font-size:14px;margin-bottom:12px;color:#93c5fd;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
label{display:block;font-size:12px;color:#94a3b8;margin-bottom:5px}
input,select,textarea{width:100%;background:#0f172a;border:1px solid #475569;color:#fff;
     padding:10px 12px;border-radius:8px;font-size:13px;font-family:inherit}
input:focus,select:focus{outline:none;border-color:#3b82f6}
input[type=checkbox]{width:auto;margin-left:6px;vertical-align:middle}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.fld{margin-bottom:12px}
.btn{padding:10px 16px;border:none;border-radius:8px;font-weight:700;cursor:pointer;
     font-size:13px;font-family:inherit;transition:.15s;white-space:nowrap}
.btn:hover{opacity:.88}.btn:active{transform:scale(.98)}
.btn:disabled{opacity:.45;cursor:not-allowed}
.b-blue{background:linear-gradient(135deg,#3b82f6,#06b6d4);color:#04121f}
.b-green{background:#22c55e;color:#052e13}
.b-gray{background:#475569;color:#e2e8f0}
.b-red{background:#ef4444;color:#fff}
.b-amber{background:#f59e0b;color:#3d2600}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.tab{padding:9px 16px;background:#1e293b;border:1px solid #334155;border-radius:9px;
     cursor:pointer;font-size:13px;font-weight:700;color:#94a3b8;transition:.15s}
.tab:hover{color:#e2e8f0}
.tab.on{background:#3b82f6;color:#04121f;border-color:#3b82f6}
.pane{display:none}.pane.on{display:block}
.hint{font-size:11px;color:#64748b;margin-top:5px}
.chip{display:inline-block;background:#0f172a;border:1px solid #334155;border-radius:6px;
      padding:2px 8px;font-size:11px;color:#94a3b8;font-family:ui-monospace,monospace}
.msg{padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:12px;display:none}
.msg.on{display:block}
.m-ok{background:#052e16;border:1px solid #16a34a;color:#86efac}
.m-err{background:#450a0a;border:1px solid #dc2626;color:#fca5a5}
.m-info{background:#0c2340;border:1px solid #2563eb;color:#93c5fd}
.m-warn{background:#422006;border:1px solid #d97706;color:#fcd34d}
.steps{margin-top:12px;font-size:12.5px}
.step{display:flex;gap:9px;padding:7px 10px;border-radius:7px;background:#0f172a;margin-bottom:5px;align-items:flex-start}
.step .ic{flex:0 0 auto;font-weight:700}
.step.ok .ic{color:#4ade80}.step.no .ic{color:#f87171}
.step .tx{flex:1}
.step .nt{color:#64748b;font-size:11px;font-family:ui-monospace,monospace;word-break:break-all}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{padding:9px 8px;text-align:right;border-bottom:1px solid #334155}
th{color:#93c5fd;font-size:11.5px;font-weight:700}
td.mono{font-family:ui-monospace,monospace;font-size:11.5px;word-break:break-all}
.empty{text-align:center;color:#64748b;padding:26px;font-size:13px}
.spin{display:inline-block;width:13px;height:13px;border:2px solid #64748b;
      border-top-color:#3b82f6;border-radius:50%;animation:sp .7s linear infinite;vertical-align:-2px}
@keyframes sp{to{transform:rotate(360deg)}}
.search{position:relative}
.results{position:absolute;top:100%;left:0;right:0;background:#0f172a;border:1px solid #475569;
         border-radius:8px;max-height:260px;overflow-y:auto;z-index:50;display:none;margin-top:3px}
.results.on{display:block}
.ritem{padding:8px 12px;cursor:pointer;font-size:12px;font-family:ui-monospace,monospace;
       border-bottom:1px solid #1e293b;display:flex;justify-content:space-between;gap:10px}
.ritem:hover,.ritem.sel{background:#1e3a5f}
.ritem .sz{color:#64748b;font-size:10.5px;flex:0 0 auto}
.jobcard{background:#0f172a;border:1px solid #334155;border-radius:9px;padding:12px;margin-bottom:9px}
.jobcard .jn{font-weight:700;font-size:13px;color:#93c5fd;margin-bottom:5px}
.jobcard .jp{font-size:11px;color:#64748b;font-family:ui-monospace,monospace;
              word-break:break-all;margin-bottom:9px}
.login{max-width:420px;margin:9vh auto}
code{background:#0f172a;padding:2px 7px;border-radius:5px;font-size:11.5px;
     font-family:ui-monospace,monospace;word-break:break-all;color:#a5b4fc}
.tok{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
@media(max-width:720px){.grid,.grid3{grid-template-columns:1fr}body{padding:11px}}
</style>
</head>
<body>
<div class="wrap">

<?php if ($isSetup): ?>
<!-- ============ راه‌اندازی اولیه ============ -->
<div class="login">
  <h1>🚀 پنل استقرار</h1>
  <p class="sub">راه‌اندازی اولیه — یک رمز برای محافظت از پنل تعیین کنید.</p>
  <div class="card">
    <div id="msg" class="msg"></div>
    <div class="fld">
      <label>رمز عبور پنل (حداقل ۸ کاراکتر)</label>
      <input type="password" id="pw" autocomplete="new-password" placeholder="یک رمز قوی">
    </div>
    <div class="fld">
      <label>تکرار رمز</label>
      <input type="password" id="pw2" autocomplete="new-password">
    </div>
    <div class="fld">
      <label>ریپوی پیش‌فرض</label>
      <input type="text" id="repo" value="fazilatma/code" placeholder="user/repo">
    </div>
    <button class="btn b-blue" style="width:100%" onclick="doSetup()">راه‌اندازی</button>
    <?php if (!is_writable(__DIR__)): ?>
      <div class="msg m-err on" style="margin-top:12px">
        ⚠ پوشه قابل نوشتن نیست. سطح دسترسی را روی ۷۵۵ تنظیم کنید.
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
async function doSetup(){
  const pw=document.getElementById('pw').value, pw2=document.getElementById('pw2').value;
  const m=document.getElementById('msg');
  const show=(t,c)=>{m.textContent=t;m.className='msg on '+c;};
  if(pw.length<8) return show('رمز باید حداقل ۸ کاراکتر باشد','m-err');
  if(pw!==pw2)    return show('رمزها یکسان نیستند','m-err');
  const fd=new FormData();
  fd.append('password',pw);
  fd.append('repo',document.getElementById('repo').value);
  const r=await fetch('?action=setup',{method:'POST',body:fd}).then(r=>r.json());
  if(r.ok) location.reload(); else show(r.error||'خطا','m-err');
}
document.getElementById('pw2').addEventListener('keydown',e=>{if(e.key==='Enter')doSetup()});
</script>

<?php elseif (!$loggedIn): ?>
<!-- ============ ورود ============ -->
<div class="login">
  <h1>🔒 پنل استقرار</h1>
  <p class="sub">برای ادامه رمز عبور را وارد کنید.</p>
  <div class="card">
    <div id="msg" class="msg"></div>
    <div class="fld">
      <label>رمز عبور</label>
      <input type="password" id="pw" autocomplete="current-password" autofocus>
    </div>
    <button class="btn b-blue" style="width:100%" onclick="doLogin()">ورود</button>
  </div>
</div>
<script>
async function doLogin(){
  const m=document.getElementById('msg');
  const fd=new FormData(); fd.append('password',document.getElementById('pw').value);
  const r=await fetch('?action=login',{method:'POST',body:fd}).then(r=>r.json());
  if(r.ok) location.reload();
  else {m.textContent=r.error||'خطا';m.className='msg on m-err';}
}
document.getElementById('pw').addEventListener('keydown',e=>{if(e.key==='Enter')doLogin()});
</script>

<?php else: ?>
<!-- ============ پنل اصلی ============ -->
<h1>🚀 پنل استقرار گیت‌هاب <span class="chip">v<?=DEPLOY_VERSION?></span></h1>
<p class="sub">فایل‌ها را از گیت‌هاب مستقیم روی هاست نصب کنید — بدون کپی و پیست.</p>

<div class="tabs">
  <div class="tab on" data-p="deploy">📦 استقرار</div>
  <div class="tab" data-p="jobs">⭐ کارهای ذخیره‌شده</div>
  <div class="tab" data-p="backups">🗄️ بکاپ‌ها</div>
  <div class="tab" data-p="wbackup">☁️ بکاپ هاست</div>
  <div class="tab" data-p="history">📜 تاریخچه</div>
  <div class="tab" data-p="settings">⚙️ تنظیمات</div>
  <div style="flex:1"></div>
  <div class="tab" onclick="logout()">خروج</div>
</div>

<!-- ---------- استقرار ---------- -->
<div class="pane on" id="p-deploy">
  <div class="card">
    <h2>① مبدأ — از کجا بگیرد</h2>
    <div class="grid">
      <div class="fld">
        <label>ریپو</label>
        <div class="row">
          <input type="text" id="d_repo" style="flex:1" placeholder="user/repo">
          <button class="btn b-gray" onclick="loadBranches()">بارگذاری</button>
        </div>
      </div>
      <div class="fld">
        <label>برنچ</label>
        <select id="d_branch" onchange="loadTree()"><option value="">— ابتدا ریپو را بارگذاری کنید —</option></select>
      </div>
    </div>
    <div class="fld search">
      <label>فایل مبدأ <span id="fcount" class="chip" style="display:none"></span></label>
      <input type="text" id="d_source" placeholder="مسیر فایل در ریپو..." autocomplete="off"
             oninput="filterFiles()" onfocus="filterFiles()">
      <div class="results" id="fres"></div>
      <div class="hint">می‌توانید تایپ کنید یا از فهرست انتخاب کنید.</div>
    </div>
  </div>

  <div class="card">
    <h2>② مقصد — کجا نصب شود</h2>
    <div class="grid">
      <div class="fld">
        <label>پوشهٔ مقصد</label>
        <div class="row">
          <input type="text" id="d_folder" list="folderlist" style="flex:1" placeholder="خالی = کنار همین فایل" oninput="checkLocal()">
          <datalist id="folderlist"></datalist>
        </div>
        <div class="hint">نسبت به پوشهٔ deploy.php — مثلاً <code>tools</code> یا <code>a/b</code></div>
      </div>
      <div class="fld">
        <label>نام فایل نهایی</label>
        <input type="text" id="d_dest" placeholder="خالی = همان نام مبدأ" oninput="checkLocal()">
        <div class="hint" id="localinfo">—</div>
      </div>
    </div>
    <div class="grid3">
      <div class="fld">
        <label>سطح دسترسی (chmod)</label>
        <select id="d_chmod">
          <option value="">حفظ وضعیت فعلی</option>
          <option value="644">۶۴۴ — استاندارد</option>
          <option value="640">۶۴۰ — محدودتر</option>
          <option value="600">۶۰۰ — فقط مالک</option>
          <option value="755">۷۵۵ — اجرایی</option>
        </select>
      </div>
      <div class="fld">
        <label>حداقل حجم مجاز (بایت)</label>
        <input type="number" id="d_min" value="64" min="0">
        <div class="hint">جلوی نصب فایل ناقص را می‌گیرد</div>
      </div>
      <div class="fld">
        <label>محافظت</label>
        <div style="padding-top:7px;font-size:12.5px">
          <label style="display:block;color:#e2e8f0;margin-bottom:6px">
            <input type="checkbox" id="d_php" checked> بررسی نحوی PHP
          </label>
          <label style="display:block;color:#e2e8f0">
            <input type="checkbox" id="d_bak" checked> بکاپ پیش از نصب
          </label>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div id="d_msg" class="msg"></div>
    <div id="d_steps" class="steps"></div>
    <div class="row" style="margin-top:12px">
      <button class="btn b-amber" onclick="run(true)">🔍 بررسی (بدون تغییر)</button>
      <button class="btn b-green" onclick="run(false)">🚀 نصب کن</button>
      <div style="flex:1"></div>
      <button class="btn b-gray" onclick="saveJobPrompt()">⭐ ذخیره به‌عنوان کار</button>
    </div>
  </div>
</div>

<!-- ---------- کارها ---------- -->
<div class="pane" id="p-jobs">
  <div class="card">
    <h2>⭐ کارهای ذخیره‌شده</h2>
    <p class="hint" style="margin-bottom:12px">
      ترکیب‌های پرتکرار را ذخیره کنید تا با یک کلیک اجرا شوند.
    </p>
    <div id="j_msg" class="msg"></div>
    <div class="row" style="margin-bottom:13px">
      <button class="btn b-green" onclick="runJobs(false)">🚀 اجرای همه</button>
      <button class="btn b-amber" onclick="runJobs(true)">🔍 بررسی همه</button>
      <button class="btn b-gray" onclick="loadJobs()">↻ تازه‌سازی</button>
    </div>
    <div id="j_list"><div class="empty">در حال بارگذاری...</div></div>
    <div id="j_out" class="steps"></div>
  </div>
</div>

<!-- ---------- بکاپ‌ها ---------- -->
<!-- ---------- بکاپ ورک‌اسپیس هاست ---------- -->
<div class="pane" id="p-wbackup">
  <div class="card">
    <h2>☁️ ارسال ورک‌اسپیس هاست به گیت‌هاب</h2>
    <p class="hint" style="margin-bottom:12px">
      همهٔ فایل‌های پوشهٔ جاری در یک کامیت به برنچ دلخواه فرستاده می‌شوند.
      هم بکاپ مطمئن است و هم اجازه می‌دهد کد با تنظیمات و شرایط واقعی هاست بررسی شود.
    </p>

    <div class="msg m-info on" style="font-size:12px;line-height:1.8">
      🔐 <b>با تعیین عبارت رمز، همهٔ فایل‌ها ارسال می‌شوند</b> — فایل‌های حاوی کلید
      (<code>connections.json</code>، <code>.deploy-config.json</code> و…) پیش از ارسال با
      <b>AES-256-GCM</b> رمزنگاری شده و با پسوند <code>.enc</code> ذخیره می‌شوند.
      بدون عبارت رمز، این فایل‌ها اصلاً ارسال نمی‌شوند.
    </div>

    <div class="grid">
      <div class="fld">
        <label>ریپو</label>
        <input type="text" id="wb_repo" placeholder="user/repo">
      </div>
      <div class="fld">
        <label>برنچ مقصد</label>
        <input type="text" id="wb_branch" placeholder="host-backup/1405-05-10">
        <div class="hint">اگر وجود نداشته باشد ساخته می‌شود. توصیه: یک برنچ جدا از کد.</div>
      </div>
    </div>
    <div class="fld">
      <label>پیام کامیت</label>
      <input type="text" id="wb_msg" placeholder="Host workspace backup">
    </div>
    <div class="fld">
      <label>توکن گیت‌هاب (با دسترسی <code>repo</code>)</label>
      <input type="password" id="wb_token" placeholder="اگر در تنظیمات ذخیره شده، خالی بگذارید">
      <div class="hint">برای <b>نوشتن</b> در گیت‌هاب توکن الزامی است، حتی برای ریپوی عمومی.</div>
    </div>

    <div class="fld" style="border:1px solid #22c55e;border-radius:9px;padding:11px;background:#052e1620">
      <label style="color:#86efac;font-weight:700">🔐 عبارت رمز (برای رمزنگاری فایل‌های حساس)</label>
      <input type="password" id="wb_pass" placeholder="حداقل ۱۰ کاراکتر — جایی امن نگه دارید">
      <div class="hint" style="line-height:1.8">
        با پر کردن این فیلد، <b>همهٔ</b> فایل‌های پوشه ارسال می‌شوند و فایل‌های حساس
        رمزنگاری‌شده می‌روند. اگر خالی باشد، فایل‌های حساس ارسال نمی‌شوند.<br>
        ⚠️ <b>این رمز در هیچ‌جا ذخیره نمی‌شود.</b> اگر فراموشش کنید، بازگرداندن آن فایل‌ها
        غیرممکن است.
      </div>
    </div>

    <div class="fld">
      <label style="display:flex;align-items:center;gap:7px;cursor:pointer;color:#fca5a5">
        <input type="checkbox" id="wb_secrets"> ارسال فایل‌های حساس <b>بدون رمزنگاری</b>
      </label>
      <div class="hint" style="color:#fca5a5">
        فقط برای ریپوی خصوصی. اگر ریپو عمومی باشد، توکن‌های شما برای همه منتشر می‌شوند.
      </div>
    </div>

    <div id="wb_msgbox" class="msg"></div>
    <div class="row">
      <button class="btn b-amber" onclick="wbScan()">🔍 پیش‌نمایش فایل‌ها</button>
      <button class="btn b-green" onclick="wbPush()" id="wb_btn">☁️ ارسال به گیت‌هاب</button>
    </div>
    <div id="wb_steps" class="steps"></div>
    <div id="wb_list" style="margin-top:12px"></div>
  </div>

  <div class="card">
    <h2>🔓 بازگرداندن فایل رمزشده</h2>
    <p class="hint" style="margin-bottom:12px">
      یک فایل <code>.enc</code> را از گیت‌هاب می‌گیرد، رمزگشایی می‌کند و روی هاست می‌نویسد.
      پیش از بازنویسی، از نسخهٔ فعلی بکاپ گرفته می‌شود.
    </p>
    <div class="grid">
      <div class="fld">
        <label>برنچ</label>
        <input type="text" id="wr_branch" placeholder="host-backup/...">
      </div>
      <div class="fld">
        <label>مسیر فایل در ریپو</label>
        <input type="text" id="wr_path" placeholder="connections.json.enc">
      </div>
    </div>
    <div class="fld">
      <label>عبارت رمز</label>
      <input type="password" id="wr_pass" placeholder="همان رمزی که هنگام بکاپ استفاده شد">
    </div>
    <div id="wr_msg" class="msg"></div>
    <button class="btn b-amber" onclick="wbRestore()">🔓 رمزگشایی و بازگرداندن</button>
  </div>
</div>

<div class="pane" id="p-backups">
  <div class="card">
    <h2>🗄️ بکاپ‌ها</h2>
    <p class="hint" style="margin-bottom:12px">
      پیش از هر نصب، نسخهٔ فعلی اینجا ذخیره می‌شود. ۲۰ نسخهٔ آخر نگه داشته می‌شوند.
    </p>
    <div id="b_msg" class="msg"></div>
    <button class="btn b-gray" style="margin-bottom:12px" onclick="loadBackups()">↻ تازه‌سازی</button>
    <div id="b_list"><div class="empty">در حال بارگذاری...</div></div>
  </div>
</div>

<!-- ---------- تاریخچه ---------- -->
<div class="pane" id="p-history">
  <div class="card">
    <h2>📜 تاریخچه</h2>
    <div class="row" style="margin-bottom:12px">
      <button class="btn b-gray" onclick="loadHistory()">↻ تازه‌سازی</button>
      <button class="btn b-red" onclick="clearHistory()">پاک کردن</button>
    </div>
    <div id="h_list"><div class="empty">در حال بارگذاری...</div></div>
  </div>
</div>

<!-- ---------- تنظیمات ---------- -->
<div class="pane" id="p-settings">
  <div class="card">
    <h2>⚙️ تنظیمات</h2>
    <div id="s_msg" class="msg"></div>
    <div class="grid">
      <div class="fld">
        <label>ریپوی پیش‌فرض</label>
        <input type="text" id="s_repo">
      </div>
      <div class="fld">
        <label>برنچ پیش‌فرض</label>
        <input type="text" id="s_branch">
      </div>
    </div>
    <div class="fld">
      <label>توکن گیت‌هاب (فقط برای ریپوی خصوصی)</label>
      <input type="password" id="s_gh" placeholder="خالی بگذارید تا تغییر نکند">
      <div class="hint">
        وضعیت فعلی: <span id="s_ghstate">—</span> ·
        برای حذف، عبارت <code>__CLEAR__</code> را وارد کنید.
      </div>
    </div>
    <div class="fld">
      <label>رمز جدید پنل</label>
      <input type="password" id="s_pw" placeholder="خالی بگذارید تا تغییر نکند">
    </div>
    <button class="btn b-blue" onclick="saveSettings()">ذخیرهٔ تنظیمات</button>
  </div>

  <div class="card">
    <h2>🤖 اجرای خودکار</h2>
    <p class="hint" style="margin-bottom:10px">
      برای فراخوانی بدون ورود (cron یا وب‌هوک) از توکن API استفاده کنید.
    </p>
    <div class="fld">
      <label>توکن API</label>
      <div class="tok">
        <input type="text" id="s_api" readonly style="flex:1;font-family:ui-monospace,monospace;font-size:11.5px">
        <button class="btn b-gray" onclick="copyApi()">کپی</button>
        <button class="btn b-red" onclick="regenApi()">تولید مجدد</button>
      </div>
    </div>
    <div class="fld">
      <label>نمونهٔ فراخوانی وب</label>
      <div id="s_url"><code>—</code></div>
    </div>
    <div class="fld">
      <label>نمونهٔ cron (بدون نیاز به توکن)</label>
      <div><code id="s_cron">—</code></div>
      <div class="hint">بدون نام کار، همهٔ کارهای ذخیره‌شده اجرا می‌شوند.</div>
    </div>
  </div>

  <div class="card">
    <h2>ℹ️ وضعیت سیستم</h2>
    <table>
      <tr><th style="width:36%">نسخهٔ PHP</th><td id="i_php" class="mono">—</td></tr>
      <tr><th>پوشهٔ نصب</th><td id="i_dir" class="mono">—</td></tr>
      <tr><th>قابل نوشتن</th><td id="i_w" class="mono">—</td></tr>
      <tr><th>cURL</th><td id="i_curl" class="mono">—</td></tr>
    </table>
  </div>
</div>

<script>
const $=id=>document.getElementById(id);
let FILES=[], JOBS=[], SETTINGS={};

/* ---------- تب‌ها ---------- */
document.querySelectorAll('.tab[data-p]').forEach(t=>{
  t.onclick=()=>{
    document.querySelectorAll('.tab[data-p]').forEach(x=>x.classList.remove('on'));
    document.querySelectorAll('.pane').forEach(x=>x.classList.remove('on'));
    t.classList.add('on');
    $('p-'+t.dataset.p).classList.add('on');
    if(t.dataset.p==='jobs')     loadJobs();
    if(t.dataset.p==='backups')  loadBackups();
    if(t.dataset.p==='history')  loadHistory();
    if(t.dataset.p==='settings') loadSettings();
    if(t.dataset.p==='wbackup')  wbInit();
  };
});

function msg(el,text,cls){
  const m=$(el);
  m.innerHTML=text;
  m.className='msg on '+cls;
}
function hide(el){ $(el).className='msg'; }
const esc=s=>{const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;};

async function api(action, data, method){
  const opt={method:method||'GET'};
  let url='?action='+encodeURIComponent(action);
  if(data && opt.method==='POST'){
    const fd=new FormData();
    for(const k in data) fd.append(k, data[k]===true?'1':(data[k]===false?'':data[k]));
    opt.body=fd;
  } else if(data){
    for(const k in data) url+='&'+k+'='+encodeURIComponent(data[k]);
  }
  const r=await fetch(url,opt);
  const j=await r.json().catch(()=>({ok:false,error:'پاسخ نامعتبر از سرور'}));
  if(j.need_auth){ location.reload(); return {ok:false,error:'نشست منقضی شد'}; }
  return j;
}

/* ---------- مبدأ ---------- */
async function loadBranches(){
  const repo=$('d_repo').value.trim();
  if(!repo) return msg('d_msg','ابتدا نام ریپو را وارد کنید','m-err');
  msg('d_msg','<span class="spin"></span> دریافت برنچ‌ها...','m-info');
  const r=await api('branches',{repo});
  if(!r.ok) return msg('d_msg','✗ '+esc(r.error),'m-err');
  const s=$('d_branch');
  s.innerHTML='';
  r.branches.forEach(b=>{
    const o=document.createElement('option');
    o.value=b.name; o.textContent=b.name+'  ('+b.sha+')';
    s.appendChild(o);
  });
  const pref=SETTINGS.branch||'';
  if(pref && r.branches.some(b=>b.name===pref)) s.value=pref;
  hide('d_msg');
  loadTree();
}

async function loadTree(){
  const repo=$('d_repo').value.trim(), branch=$('d_branch').value;
  if(!repo||!branch) return;
  msg('d_msg','<span class="spin"></span> دریافت فهرست فایل‌ها...','m-info');
  const r=await api('tree',{repo,branch});
  if(!r.ok){ FILES=[]; return msg('d_msg','✗ '+esc(r.error),'m-err'); }
  FILES=r.files;
  const c=$('fcount'); c.style.display='inline-block'; c.textContent=FILES.length+' فایل';
  hide('d_msg');
  checkLocal();
}

function filterFiles(){
  const q=$('d_source').value.trim().toLowerCase();
  const box=$('fres');
  if(!FILES.length){ box.classList.remove('on'); return; }
  const hit=FILES.filter(f=>f.path.toLowerCase().includes(q)).slice(0,60);
  if(!hit.length){ box.classList.remove('on'); return; }
  box.innerHTML=hit.map(f=>
    '<div class="ritem" data-p="'+esc(f.path)+'"><span>'+esc(f.path)+'</span>'+
    '<span class="sz">'+fmtSize(f.size)+'</span></div>').join('');
  box.querySelectorAll('.ritem').forEach(el=>{
    el.onmousedown=e=>{ e.preventDefault(); pickFile(el.dataset.p); };
  });
  box.classList.add('on');
}
function fmtSize(b){
  if(b<1024) return b+' B';
  if(b<1048576) return (b/1024).toFixed(1)+' KB';
  return (b/1048576).toFixed(2)+' MB';
}
function pickFile(p){
  $('d_source').value=p;
  $('fres').classList.remove('on');
  if(!$('d_dest').value.trim()) $('d_dest').value=p.split('/').pop();
  checkLocal();
}
document.addEventListener('click',e=>{
  if(!e.target.closest('.search')) $('fres').classList.remove('on');
});

/* ---------- وضعیت فایل مقصد ---------- */
let lt=null;
function checkLocal(){
  clearTimeout(lt);
  lt=setTimeout(async()=>{
    const dest=$('d_dest').value.trim()||$('d_source').value.trim().split('/').pop();
    if(!dest){ $('localinfo').textContent='—'; return; }
    const r=await api('local_info',{folder:$('d_folder').value.trim(),dest});
    if(r.exists){
      $('localinfo').innerHTML='روی هاست: '+r.size_h+' · '+esc(r.mtime)+
        ' · <span class="chip">'+esc(r.hash)+'</span>'+
        (r.writable?'':' <span style="color:#f87171">غیرقابل نوشتن!</span>');
    } else {
      $('localinfo').innerHTML='<span style="color:#4ade80">فایل جدید — روی هاست وجود ندارد</span>';
    }
  },350);
}

/* ---------- اجرا ---------- */
function jobFromForm(){
  return {
    repo:$('d_repo').value.trim(),
    branch:$('d_branch').value,
    source:$('d_source').value.trim(),
    dest:$('d_dest').value.trim(),
    folder:$('d_folder').value.trim(),
    check_php:$('d_php').checked,
    backup:$('d_bak').checked,
    chmod:$('d_chmod').value,
    min_bytes:$('d_min').value
  };
}

function renderSteps(el,steps){
  if(!steps||!steps.length){ $(el).innerHTML=''; return; }
  $(el).innerHTML=steps.map(s=>
    '<div class="step '+(s.ok?'ok':'no')+'"><span class="ic">'+(s.ok?'✓':'✗')+'</span>'+
    '<span class="tx">'+esc(s.label)+(s.note?'<div class="nt">'+esc(s.note)+'</div>':'')+'</span></div>'
  ).join('');
}

async function run(dry){
  const j=jobFromForm();
  if(!j.source) return msg('d_msg','فایل مبدأ را انتخاب کنید','m-err');
  msg('d_msg','<span class="spin"></span> '+(dry?'در حال بررسی...':'در حال نصب...'),'m-info');
  $('d_steps').innerHTML='';
  const r=await api(dry?'dryrun':'deploy', j, 'POST');
  renderSteps('d_steps', r.steps);
  if(!r.ok) return msg('d_msg','✗ '+esc(r.error||'خطا'),'m-err');
  if(r.same)      msg('d_msg','✓ '+esc(r.message),'m-info');
  else if(r.dry)  msg('d_msg','⚠ '+esc(r.message)+' — برای اعمال، «نصب کن» را بزنید','m-warn');
  else            msg('d_msg','✓ '+esc(r.message)+' → <code>'+esc(r.dest)+'</code>'+
                      (r.backup?' · بکاپ: <code>'+esc(r.backup)+'</code>':''),'m-ok');
  checkLocal();
}

/* ---------- کارها ---------- */
async function saveJobPrompt(){
  const j=jobFromForm();
  if(!j.source) return msg('d_msg','ابتدا فایل مبدأ را انتخاب کنید','m-err');
  const def=(j.dest||j.source.split('/').pop());
  const name=prompt('نام این کار:', def);
  if(!name) return;
  j.name=name;
  const r=await api('job_save', j, 'POST');
  if(!r.ok) return msg('d_msg','✗ '+esc(r.error),'m-err');
  JOBS=r.jobs;
  msg('d_msg','✓ ذخیره شد: '+esc(name)+(r.updated?' (به‌روزرسانی)':''),'m-ok');
}

async function loadJobs(){
  const r=await api('jobs_list');
  JOBS=r.jobs||[];
  const el=$('j_list');
  if(!JOBS.length){ el.innerHTML='<div class="empty">هنوز کاری ذخیره نشده.<br>از تب «استقرار» یک کار بسازید.</div>'; return; }
  el.innerHTML=JOBS.map((j,i)=>
    '<div class="jobcard"><div class="jn">⭐ '+esc(j.name)+'</div>'+
    '<div class="jp">'+esc(j.repo||'')+' @ '+esc(j.branch||'')+'<br>'+
    esc(j.source)+' → '+esc((j.folder?j.folder+'/':'')+(j.dest||j.source.split('/').pop()))+'</div>'+
    '<div class="row">'+
      '<button class="btn b-green" onclick="runOne('+i+',false)">🚀 اجرا</button>'+
      '<button class="btn b-amber" onclick="runOne('+i+',true)">🔍 بررسی</button>'+
      '<button class="btn b-gray" onclick="editJob('+i+')">✎ ویرایش در فرم</button>'+
      '<button class="btn b-red" onclick="delJob('+i+')">حذف</button>'+
    '</div></div>').join('');
}

async function runOne(i,dry){
  const j=JOBS[i]; if(!j) return;
  msg('j_msg','<span class="spin"></span> '+esc(j.name)+'...','m-info');
  const r=await api('run_jobs',{names:JSON.stringify([j.name]),dry:dry?'1':''}, 'POST');
  const res=(r.results&&r.results[0])?r.results[0].result:{ok:false,error:'بدون پاسخ'};
  renderSteps('j_out',res.steps);
  if(!res.ok)          msg('j_msg','✗ '+esc(j.name)+': '+esc(res.error),'m-err');
  else if(res.same)    msg('j_msg','✓ '+esc(j.name)+': بدون تغییر','m-info');
  else if(res.dry)     msg('j_msg','⚠ '+esc(j.name)+': نسخهٔ جدید موجود است','m-warn');
  else                 msg('j_msg','✓ '+esc(j.name)+': نصب شد → '+esc(res.dest),'m-ok');
}

async function runJobs(dry){
  if(!JOBS.length) return msg('j_msg','کاری برای اجرا نیست','m-err');
  msg('j_msg','<span class="spin"></span> اجرای '+JOBS.length+' کار...','m-info');
  const r=await api('run_jobs',{names:'[]',dry:dry?'1':''}, 'POST');
  if(!r.ok) return msg('j_msg','✗ '+esc(r.error||'خطا'),'m-err');
  let ok=0,ch=0,bad=0,rows='';
  r.results.forEach(x=>{
    const v=x.result;
    if(!v.ok) bad++; else { ok++; if(v.changed) ch++; }
    rows+='<div class="step '+(v.ok?'ok':'no')+'"><span class="ic">'+(v.ok?'✓':'✗')+'</span>'+
          '<span class="tx">'+esc(x.name)+'<div class="nt">'+
          esc(v.ok?(v.message||''):(v.error||''))+'</div></span></div>';
  });
  $('j_out').innerHTML=rows;
  msg('j_msg', (bad?'⚠':'✓')+' موفق: '+ok+' · تغییر: '+ch+' · ناموفق: '+bad,
      bad?'m-warn':'m-ok');
}

function editJob(i){
  const j=JOBS[i]; if(!j) return;
  $('d_repo').value=j.repo||''; $('d_source').value=j.source||'';
  $('d_dest').value=j.dest||''; $('d_folder').value=j.folder||'';
  $('d_php').checked=j.check_php!==false; $('d_bak').checked=j.backup!==false;
  $('d_chmod').value=j.chmod||''; $('d_min').value=j.min_bytes||64;
  document.querySelector('.tab[data-p="deploy"]').click();
  loadBranches().then(()=>{ if(j.branch){ $('d_branch').value=j.branch; loadTree(); } });
}

async function delJob(i){
  const j=JOBS[i]; if(!j) return;
  if(!confirm('حذف کار «'+j.name+'»؟')) return;
  const r=await api('job_delete',{name:j.name},'POST');
  JOBS=r.jobs||[]; loadJobs();
}

/* ---------- بکاپ‌ها ---------- */
async function loadBackups(){
  const r=await api('backups');
  const el=$('b_list');
  if(!r.backups||!r.backups.length){ el.innerHTML='<div class="empty">هنوز بکاپی ساخته نشده.</div>'; return; }
  el.innerHTML='<table><tr><th>فایل مقصد</th><th>زمان</th><th>حجم</th><th></th></tr>'+
    r.backups.map(b=>
      '<tr><td class="mono">'+esc(b.target)+'</td><td class="mono">'+esc(b.time)+'</td>'+
      '<td class="mono">'+esc(b.size)+'</td><td style="white-space:nowrap">'+
      '<button class="btn b-amber" onclick="restore(\''+esc(b.file)+'\')">بازگردانی</button> '+
      '<button class="btn b-red" onclick="delBackup(\''+esc(b.file)+'\')">حذف</button></td></tr>'
    ).join('')+'</table>';
}
async function restore(f){
  const folder=prompt('بازگردانی در کدام پوشه؟ (خالی = ریشه)','');
  if(folder===null) return;
  const r=await api('restore',{file:f,folder},'POST');
  msg('b_msg', r.ok?('✓ '+esc(r.message)):('✗ '+esc(r.error)), r.ok?'m-ok':'m-err');
}
async function delBackup(f){
  if(!confirm('این بکاپ حذف شود؟')) return;
  await api('backup_delete',{file:f},'POST');
  loadBackups();
}

/* ---------- تاریخچه ---------- */
async function loadHistory(){
  const r=await api('history');
  const el=$('h_list');
  if(!r.log||!r.log.length){ el.innerHTML='<div class="empty">تاریخچه‌ای ثبت نشده.</div>'; return; }
  el.innerHTML='<table><tr><th>زمان</th><th>نوع</th><th>مقصد</th><th>نتیجه</th></tr>'+
    r.log.map(e=>{
      const d=new Date(e.time*1000).toLocaleString('fa-IR');
      const ic=e.ok?'<span style="color:#4ade80">✓</span>':'<span style="color:#f87171">✗</span>';
      return '<tr><td class="mono">'+esc(d)+'</td><td>'+ic+' '+esc(e.type||'')+'</td>'+
             '<td class="mono">'+esc(e.dest||e.job||e.file||'')+'</td>'+
             '<td>'+esc(e.msg||'')+'</td></tr>';
    }).join('')+'</table>';
}
async function clearHistory(){
  if(!confirm('کل تاریخچه پاک شود؟')) return;
  await api('history_clear'); loadHistory();
}

/* ---------- تنظیمات ---------- */
async function loadSettings(){
  const r=await api('settings_get');
  if(!r.ok) return;
  SETTINGS=r.settings;
  $('s_repo').value=SETTINGS.repo||'';
  $('s_branch').value=SETTINGS.branch||'';
  $('s_api').value=SETTINGS.api_token||'';
  $('s_ghstate').textContent=SETTINGS.has_gh?'تنظیم شده':'تنظیم نشده';
  $('i_php').textContent=SETTINGS.php;
  $('i_dir').textContent=SETTINGS.dir;
  $('i_w').innerHTML=SETTINGS.writable?'<span style="color:#4ade80">بله</span>':'<span style="color:#f87171">خیر — chmod 755</span>';
  $('i_curl').innerHTML=SETTINGS.curl?'<span style="color:#4ade80">فعال</span>':'<span style="color:#fcd34d">غیرفعال (از fallback استفاده می‌شود)</span>';
  const base=location.origin+location.pathname;
  $('s_url').innerHTML='<code>'+esc(base+'?action=run_jobs&api_token='+(SETTINGS.api_token||''))+'</code>';
  $('s_cron').textContent='*/30 * * * * /usr/bin/php '+SETTINGS.dir+'/'+location.pathname.split('/').pop();
}
async function saveSettings(){
  const d={repo:$('s_repo').value.trim(),branch:$('s_branch').value.trim()};
  if($('s_gh').value) d.github_token=$('s_gh').value;
  if($('s_pw').value) d.new_password=$('s_pw').value;
  const r=await api('settings_save', d, 'POST');
  if(!r.ok) return msg('s_msg','✗ '+esc(r.error),'m-err');
  $('s_gh').value=''; $('s_pw').value='';
  msg('s_msg','✓ ذخیره شد','m-ok');
  loadSettings();
}
async function regenApi(){
  if(!confirm('توکن API از نو ساخته شود؟ آدرس‌های قبلی از کار می‌افتند.')) return;
  const r=await api('settings_save',{regen_token:'1'},'POST');
  if(r.ok){ msg('s_msg','✓ توکن جدید ساخته شد','m-ok'); loadSettings(); }
}
function copyApi(){
  const i=$('s_api'); i.select();
  navigator.clipboard.writeText(i.value).then(()=>msg('s_msg','✓ کپی شد','m-ok'));
}

/* ---------- بکاپ ورک‌اسپیس هاست ---------- */
function wbInit(){
  if(!$('wb_repo').value) $('wb_repo').value = SETTINGS.repo || '';
  if(!$('wb_branch').value){
    const d=new Date(), p=n=>String(n).padStart(2,'0');
    $('wb_branch').value='host-backup/'+d.getFullYear()+p(d.getMonth()+1)+p(d.getDate())+'-'+p(d.getHours())+p(d.getMinutes());
  }
}

function wbRenderList(r){
  let h='';
  if(r.files && r.files.length){
    h+='<div style="font-size:12px;color:#4ade80;font-weight:700;margin:8px 0 4px">✓ ارسال می‌شود ('+r.files.length+' فایل · '+esc(r.total_h)+')</div>';
    h+='<div style="max-height:220px;overflow-y:auto;border:1px solid #334155;border-radius:8px">';
    r.files.forEach(f=>{
      h+='<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 9px;border-bottom:1px solid #1e293b;font-size:11.5px;font-family:ui-monospace,monospace">'
        +'<span style="color:'+(f.enc?'#86efac':'#e2e8f0')+'">'+(f.enc?'🔐 ':'')+esc(f.path)+'</span>'
        +'<span style="color:#64748b;flex:0 0 auto">'+fmtSize(f.size)+'</span></div>';
    });
    h+='</div>';
  }
  if(r.skipped && r.skipped.length){
    h+='<div style="font-size:12px;color:#fbbf24;font-weight:700;margin:12px 0 4px">⊘ رد شد ('+r.skipped.length+')</div>';
    h+='<div style="max-height:180px;overflow-y:auto;border:1px solid #334155;border-radius:8px">';
    r.skipped.forEach(f=>{
      h+='<div style="display:flex;justify-content:space-between;gap:10px;padding:5px 9px;border-bottom:1px solid #1e293b;font-size:11.5px">'
        +'<span style="color:#94a3b8;font-family:ui-monospace,monospace">'+esc(f.path)+'</span>'
        +'<span style="color:#64748b;flex:0 0 auto">'+esc(f.why)+'</span></div>';
    });
    h+='</div>';
  }
  $('wb_list').innerHTML=h;
}

async function wbScan(){
  msg('wb_msgbox','<span class="spin"></span> در حال بررسی فایل‌ها...','m-info');
  $('wb_steps').innerHTML='';
  const r=await api('wb_scan',{include:'',secrets:$('wb_secrets').checked?'1':'',enc_pass:$('wb_pass').value},'POST');
  if(!r.ok) return msg('wb_msgbox','✗ '+esc(r.error||'خطا'),'m-err');
  wbRenderList(r);
  let m='✓ '+r.files.length+' فایل آمادهٔ ارسال ('+esc(r.total_h)+')';
  if(r.encrypted && r.encrypted.length)      m+=' · 🔐 '+r.encrypted.length+' فایل رمزنگاری می‌شود';
  else if(r.secret_hits && r.secret_hits.length) m+=' · 🔑 '+r.secret_hits.length+' فایل حساس کنار گذاشته شد';
  msg('wb_msgbox',m,'m-ok');
}

async function wbRestore(){
  const branch=$('wr_branch').value.trim(), path=$('wr_path').value.trim(), pass=$('wr_pass').value;
  if(!branch||!path) return msg('wr_msg','برنچ و مسیر فایل را وارد کنید','m-err');
  if(!pass)          return msg('wr_msg','عبارت رمز را وارد کنید','m-err');
  if(!confirm('فایل «'+path+'» رمزگشایی و روی هاست نوشته شود؟')) return;

  msg('wr_msg','<span class="spin"></span> در حال دریافت و رمزگشایی...','m-info');
  const r=await api('wb_restore',{repo:$('wb_repo').value.trim(),branch,path,enc_pass:pass},'POST');
  if(!r.ok) return msg('wr_msg','✗ '+esc(r.error||'خطا'),'m-err');
  $('wr_pass').value='';
  msg('wr_msg','✓ بازگردانی شد: <code>'+esc(r.restored)+'</code> ('+r.size+' بایت)','m-ok');
}

async function wbPush(){
  const repo=$('wb_repo').value.trim(), branch=$('wb_branch').value.trim();
  if(!repo)   return msg('wb_msgbox','نام ریپو را وارد کنید','m-err');
  if(!branch) return msg('wb_msgbox','نام برنچ را وارد کنید','m-err');

  const pass=$('wb_pass').value;
  if(pass && pass.length<10) return msg('wb_msgbox','عبارت رمز باید حداقل ۱۰ کاراکتر باشد','m-err');
  if(pass && !confirm('🔐 عبارت رمز را جایی امن یادداشت کرده‌اید؟\n\n'
                     +'این رمز در هیچ‌جا ذخیره نمی‌شود. بدون آن، فایل‌های رمزشده\n'
                     +'قابل بازگرداندن نخواهند بود.')) return;
  if($('wb_secrets').checked && !pass &&
     !confirm('⚠️ هشدار جدی\n\nفایل‌های حاوی توکن و کلید بدون رمزنگاری ارسال می‌شوند.\n'
             +'اگر ریپو عمومی باشد، همه به آن‌ها دسترسی خواهند داشت.\n\nادامه می‌دهید؟')) return;
  if(!confirm('ارسال ورک‌اسپیس هاست به «'+branch+'»؟')) return;

  const btn=$('wb_btn');
  btn.disabled=true; btn.textContent='⏳ در حال ارسال...';
  msg('wb_msgbox','<span class="spin"></span> آپلود در حال انجام است — برای پروژهٔ بزرگ ممکن است طول بکشد...','m-info');
  $('wb_steps').innerHTML='';

  const r=await api('wb_push',{
    repo, branch, include:'', message:$('wb_msg').value.trim(),
    secrets:$('wb_secrets').checked?'1':'', gh_token:$('wb_token').value, enc_pass:pass
  },'POST');

  btn.disabled=false; btn.textContent='☁️ ارسال به گیت‌هاب';
  renderSteps('wb_steps', r.steps);
  if(!r.ok) return msg('wb_msgbox','✗ '+esc(r.error||'خطا'),'m-err');
  $('wb_token').value='';
  if(!$('wr_branch').value) $('wr_branch').value=branch;   // آماده برای بازگردانی
  msg('wb_msgbox','✓ '+r.count+' فایل ارسال شد'
    +(r.encrypted&&r.encrypted.length?' (🔐 '+r.encrypted.length+' رمزشده)':'')
    +' · کامیت <code>'+esc(r.commit)+'</code>'
    +(r.created?' · برنچ ساخته شد':'')
    +' · <a href="'+esc(r.url)+'" target="_blank" style="color:#93c5fd">مشاهده در گیت‌هاب ↗</a>','m-ok');
}

async function logout(){ await api('logout'); location.reload(); }

/* ---------- شروع ---------- */
(async()=>{
  const r=await api('settings_get');
  if(r.ok){
    SETTINGS=r.settings;
    // اجازه می‌دهد اسکریپر مستقیم روی تب بکاپ باز کند: deploy.php#wbackup
    const want=(location.hash||'').replace('#','');
    if(want){
      const t=document.querySelector('.tab[data-p="'+want.replace(/[^a-z]/gi,'')+'"]');
      if(t) t.click();
    }
    $('d_repo').value=SETTINGS.repo||'';
    const fr=await api('folders');
    if(fr.ok){
      $('folderlist').innerHTML=fr.folders.map(f=>'<option value="'+esc(f)+'">').join('');
    }
    if(SETTINGS.repo) loadBranches();
  }
})();
</script>
<?php endif; ?>

</div>
</body>
</html>
