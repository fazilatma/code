<?php
/**
 * deploy.php — یک‌کلیک دریافت آخرین نسخهٔ اسکریپر از گیت‌هاب
 * ---------------------------------------------------------------
 * فایل را از گیت‌هاب می‌گیرد، *قبل از نصب* صحتش را بررسی می‌کند،
 * از نسخهٔ فعلی بکاپ می‌گیرد و به‌صورت اتمیک جایگزین می‌کند.
 * اگر فایل دریافتی خراب باشد، هیچ تغییری اعمال نمی‌شود.
 *
 * روش استفاده:
 *   https://example.com/deploy.php?token=SECRET            → نصب
 *   https://example.com/deploy.php?token=SECRET&check=1    → فقط بررسی وضعیت
 *   https://example.com/deploy.php?token=SECRET&rollback=1 → بازگشت به بکاپ قبلی
 *   php deploy.php                                          → اجرا از خط فرمان (بدون توکن)
 */

// ==================== تنظیمات ====================
$CONFIG = [
    // ⚠️ حتماً این را به یک رشتهٔ تصادفی و طولانی تغییر دهید
    'token' => 'CHANGE-ME-TO-A-LONG-RANDOM-STRING',

    'repo'   => 'fazilatma/code',
    'branch' => 'arena/019fb317-code',

    // 'مسیر فایل در ریپو' => 'نام فایل روی هاست'
    'files' => [
        'scraper-v8.17.php' => 'scraper.php',
    ],

    // برای ریپوی خصوصی، یک GitHub Personal Access Token بگذارید (وگرنه خالی)
    'github_token' => '',

    'backup_dir'   => __DIR__ . '/_backups',
    'keep_backups' => 10,
    'min_bytes'    => 5000,   // فایل کوچک‌تر از این = دانلود ناقص

    // فقط برای تست محلی؛ دست نزنید
    'base_url' => 'https://raw.githubusercontent.com',
];
// =================================================

@set_time_limit(300);
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=UTF-8');

$LOG = [];
function say(string $msg): void {
    global $LOG;
    $LOG[] = $msg;
    echo $msg . "\n";
    @ob_flush(); @flush();
}
function bye(int $code): void { exit($code); }

// ---------- احراز هویت ----------
if (!$isCli) {
    $given = $_GET['token'] ?? $_POST['token'] ?? '';
    if ($CONFIG['token'] === 'CHANGE-ME-TO-A-LONG-RANDOM-STRING') {
        http_response_code(500);
        say('✗ ابتدا مقدار token را در deploy.php تغییر دهید.');
        bye(1);
    }
    if (!is_string($given) || !hash_equals($CONFIG['token'], $given)) {
        http_response_code(403);
        say('✗ دسترسی رد شد.');
        bye(1);
    }
}

/** دانلود یک فایل از گیت‌هاب (raw) */
function gh_fetch(string $url, string $token): array {
    $headers = [
        'User-Agent: deploy-script',
        'Accept: application/vnd.github.raw, text/plain, */*',
        'Cache-Control: no-cache',
    ];
    if ($token !== '') $headers[] = 'Authorization: token ' . $token;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'error' => $err ?: 'curl failed', 'body' => ''];
        if ($code !== 200)   return ['ok' => false, 'error' => 'HTTP ' . $code, 'body' => ''];
        return ['ok' => true, 'error' => '', 'body' => $body];
    }

    // fallback بدون cURL
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 120,
        'header' => implode("\r\n", $headers),
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'error' => 'file_get_contents failed', 'body' => ''];
    return ['ok' => true, 'error' => '', 'body' => $body];
}

/** بررسی نحوی فایل PHP بدون نیاز به exec */
function php_syntax_ok(string $code, ?string &$error = null): bool {
    $error = null;
    try {
        token_get_all($code, TOKEN_PARSE);
        return true;
    } catch (ParseError $e) {
        $error = $e->getMessage() . ' (خط ' . $e->getLine() . ')';
        return false;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return false;
    }
}

function prune_backups(string $dir, string $prefix, int $keep): void {
    $files = glob($dir . '/' . $prefix . '.*.bak');
    if (!$files || count($files) <= $keep) return;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $keep) as $old) @unlink($old);
}

// ---------- حالت rollback ----------
if (!empty($_GET['rollback'])) {
    say('== بازگشت به آخرین بکاپ ==');
    foreach ($CONFIG['files'] as $dest) {
        $backups = glob($CONFIG['backup_dir'] . '/' . basename($dest) . '.*.bak');
        if (!$backups) { say("  - $dest: بکاپی یافت نشد"); continue; }
        usort($backups, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $latest = $backups[0];
        if (@copy($latest, __DIR__ . '/' . $dest)) {
            say("  ✓ $dest ← " . basename($latest));
        } else {
            say("  ✗ $dest: بازگردانی ناموفق");
        }
    }
    bye(0);
}

// ---------- اجرای اصلی ----------
say('== دریافت از گیت‌هاب ==');
say('ریپو : ' . $CONFIG['repo']);
say('برنچ : ' . $CONFIG['branch']);
say('زمان : ' . date('Y-m-d H:i:s'));
say('');

if (!is_dir($CONFIG['backup_dir'])) @mkdir($CONFIG['backup_dir'], 0755, true);
// جلوگیری از دسترسی وب به بکاپ‌ها
$ht = $CONFIG['backup_dir'] . '/.htaccess';
if (is_dir($CONFIG['backup_dir']) && !file_exists($ht)) {
    @file_put_contents($ht, "Require all denied\nDeny from all\n");
}

$checkOnly = !empty($_GET['check']);
$changed = 0; $failed = 0; $skipped = 0;

foreach ($CONFIG['files'] as $srcPath => $destName) {
    $destName = basename($destName);
    $dest = __DIR__ . '/' . $destName;

    // refs/heads/ باعث می‌شود برنچ‌های دارای «/» هم درست کار کنند
    $url = rtrim($CONFIG['base_url'], '/') . '/' . $CONFIG['repo']
         . '/refs/heads/' . $CONFIG['branch'] . '/'
         . implode('/', array_map('rawurlencode', explode('/', $srcPath)));

    say("→ $srcPath");

    $res = gh_fetch($url, $CONFIG['github_token']);
    if (!$res['ok']) { say('  ✗ دانلود ناموفق: ' . $res['error']); $failed++; say(''); continue; }

    $body = $res['body'];
    $size = strlen($body);

    // --- اعتبارسنجی پیش از نصب ---
    if ($size < $CONFIG['min_bytes']) {
        say("  ✗ حجم مشکوک ({$size} بایت) — نصب لغو شد"); $failed++; say(''); continue;
    }
    if (strncmp(ltrim($body), '<?php', 5) !== 0) {
        say('  ✗ فایل با <?php شروع نمی‌شود — نصب لغو شد'); $failed++; say(''); continue;
    }
    if (!php_syntax_ok($body, $synErr)) {
        say('  ✗ خطای نحوی: ' . $synErr); say('  نصب لغو شد.'); $failed++; say(''); continue;
    }

    $newHash = hash('sha256', $body);
    $oldHash = is_file($dest) ? hash_file('sha256', $dest) : '';

    say('  حجم    : ' . number_format($size) . ' بایت');
    say('  syntax : سالم');
    say('  sha256 : ' . substr($newHash, 0, 12));

    if ($newHash === $oldHash) { say('  = بدون تغییر'); $skipped++; say(''); continue; }
    if ($checkOnly) { say('  ! نسخهٔ جدید موجود است (حالت بررسی)'); $changed++; say(''); continue; }

    // --- بکاپ ---
    if (is_file($dest)) {
        $bak = $CONFIG['backup_dir'] . '/' . $destName . '.' . date('Ymd-His') . '.bak';
        if (!@copy($dest, $bak)) { say('  ✗ بکاپ ناموفق — نصب لغو شد'); $failed++; say(''); continue; }
        say('  بکاپ   : ' . basename($bak));
    }

    // --- نوشتن اتمیک ---
    $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $body, LOCK_EX) !== $size) {
        @unlink($tmp); say('  ✗ نوشتن ناموفق (فضای دیسک یا سطح دسترسی؟)'); $failed++; say(''); continue;
    }
    if (hash_file('sha256', $tmp) !== $newHash) {
        @unlink($tmp); say('  ✗ فایل نوشته‌شده مخدوش است'); $failed++; say(''); continue;
    }
    if (is_file($dest)) @chmod($tmp, fileperms($dest) & 0777);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp); say('  ✗ جایگزینی ناموفق'); $failed++; say(''); continue;
    }

    @clearstatcache(true, $dest);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($dest, true);

    say('  ✓ نصب شد → ' . $destName);
    prune_backups($CONFIG['backup_dir'], $destName, $CONFIG['keep_backups']);
    $changed++;
    say('');
}

say('== نتیجه ==');
say("به‌روزرسانی: $changed   بدون تغییر: $skipped   ناموفق: $failed");
if ($failed > 0) { http_response_code(500); bye(1); }
say('انجام شد ✓');
bye(0);
