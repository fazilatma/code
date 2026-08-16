# 🛰️ پراکسی سرور (Proxy Server)

دو نسخهٔ کاملاً هم‌رفتار از یک پراکسی HTTP/HTTPS ارائه شده است — هر دو **بدون هیچ وابستگی خارجی**:

| فایل | رانتایم | مناسب برای |
|---|---|---|
| `proxy.php` | PHP 7.4+ (فقط cURL) | هاست اشتراکی، cPanel، Liara/PHP، سرورهای PHP |
| `proxy.js` | Node.js 18+ (فقط ماژول‌های داخلی) | VPS، Liara/Node، Heroku، Docker |

هر دو نسخه یک API واحد دارند، پس اسکرپر شما فرقی نمی‌کند کدام پشتش باشد.

---

## 🚀 اجرای سریع

### نسخهٔ PHP
فایل `proxy.php` را در ریشهٔ سایت خود آپلود کنید و تمام. نمونهٔ اجرای محلی:

```bash
php -S 0.0.0.0:8080 proxy.php
```

### نسخهٔ Node
```bash
node proxy.js          # یا:  PORT=8080 node proxy.js
```

سپس در مرورگر `http://server:8080/` را باز کنید — داشبورد راهنما با تست سریع نمایش داده می‌شود.

---

## 📡 روش استفاده

همهٔ درخواست‌ها فقط با یک پارامتر `url` انجام می‌شوند؛ متد و بدنهٔ درخواست عیناً به مقصد ارسال می‌شود:

```
GET  https://your-server.com/proxy.php?url=https://example.com/page
POST https://your-server.com/?url=https://api.example.com/x    (با بدنهٔ دلخواه)
```

### از داخل scraper.php (نمونه)

```php
// یک بار در بالای فایل:
function fetch_via_proxy(string $url, array $opts = []): string {
    $proxy = 'https://your-server.com/proxy.php?url=' . urlencode($url);
    $ch = curl_init($proxy);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if (!empty($opts['ua']))       curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Proxy-UA: ' . $opts['ua']]);
    if (!empty($opts['cookie']))   curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Proxy-Cookie: ' . $opts['cookie']]);
    if (!empty($opts['referer']))  curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Proxy-Referer: ' . $opts['referer']]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new RuntimeException("proxy error {$code}");
    return $html;
}
```

### از جاوااسکریپت (اسکرپر سمت مرورگر)

```js
fetch('https://your-server.com/proxy.php?url=' + encodeURIComponent(target))
  .then(r => r.text())
  .then(html => console.log(html));
```

---

## 🎛️ هدرهای کنترلی

| هدر | کاربرد |
|---|---|
| `X-Proxy-UA` | بازنویسی User-Agent ارسالی به مقصد |
| `X-Proxy-Referer` | تغییر Referer |
| `X-Proxy-Cookie` | ارسال کوکی به مقصد |
| `X-Proxy-Time` | تایم‌اوت سفارشی (ثانیه، حداکثر ۳۰۰) |
| `X-Proxy-Key` | کلید محافظت (اگر تنظیم شده باشد) |

هدرهای پاسخ پراکسی:
- `X-Proxy-Final-Url` — آدرس نهایی پس از ریدایرکت‌ها
- `X-Proxy-Final-Status` — وضعیت نهایی
- `X-Proxy-Cache` — `HIT` یا `MISS`

---

## ⚙️ تنظیمات

### در proxy.php
آرایهٔ `$CONFIG` در ابتدای فایل:

```php
$CONFIG = [
    'proxy_key'         => '',                    // کلید محافظت (خالی = آزاد)
    'allowed_domains'   => [],                    // لیست سفید؛ خالی = همه مجاز
    'blocked_domains'   => ['localhost', '127.0.0.1'],
    'upstream_proxies'  => ['http://user:pass@1.2.3.4:8080', 'socks5://5.6.7.8:1080'],
    'rotate_upstream'   => true,                  // چرخش خودکار
    'direct_first'      => true,                  // false = فقط از بالادستی عبور کن
    'retry_statuses'    => [403, 429, 503],       // تلاش مجدد با پراکسی بعدی
    'cache_enabled'     => false,                 // کش فایلی
    'cache_ttl'         => 120,
    'allow_private_ips' => false,                 // محافظ SSRF (فقط برای تست محلی true کنید)
];
```

### در proxy.js
متغیرهای محیطی با نام مشابه:

```bash
PORT=8080 \
PROXY_KEY=my-secret \
PROXY_ALLOWED_DOMAINS='*.barfbox.ir,*.digikala.com' \
PROXY_BLOCKED_DOMAINS='' \
PROXY_UPSTREAM='http://user:pass@1.2.3.4:8080,http://user:pass@5.6.7.8:8080' \
PROXY_DIRECT_FIRST=0 \
PROXY_CACHE=1 \
PROXY_ALLOW_PRIVATE=0 \
node proxy.js
```

---

## 🛡️ امکانات امنیتی و فنی

- **محافظ SSRF** — اتصال به IPهای داخلی/خصوصی (شامل `169.254.169.254`) مسدود است؛ هر پرش ریدایرکت هم دوباره اعتبارسنجی می‌شود
- **جلوگیری از DNS rebinding** — نسخهٔ Node مستقیماً به IP رزولوشده وصل می‌شود
- **لیست سفید/سیاه دامنه** با پشتیبانی از الگوی `*.domain.com`
- **زنجیرهٔ پراکسی بالادستی** — چرخش خودکار بین چند پراکسی (http در هر دو نسخه؛ socks5 فقط در نسخهٔ PHP) با تلاش مجدد روی خطاها و وضعیت‌های 403/429/503
- **تونل CONNECT** برای مقصدهای HTTPS از طریق پراکسی بالادستی http
- **رمزگشایی خودکار** gzip / deflate / brotli
- **تزریق `<base>`** در پاسخ‌های HTML تا لینک‌ها و تصاویر نسبی درست کار کنند
- **کش فایلی** اختیاری با TTL
- **CORS** کامل — از هر دامنه‌ای قابل فراخوانی
- کلید محافظت با مقایسهٔ زمان-ثابت (timing-safe)
- محدودیت حجم پاسخ و بدنهٔ درخواست، محدودیت تعداد ریدایرکت و تایم‌اوت

---

## 🧪 تست

نسخهٔ Node با سناریوهای زیر به‌صورت کامل تست شده است:

- ✅ GET/POST/PUT/DELETE/HEAD با بدنه و هدر دلخواه
- ✅ دنبال‌کردن ریدایرکت (302/303) و گزارش آدرس نهایی
- ✅ رمزگشایی gzip — تزریق `<base>` — چند Set-Cookie
- ✅ زنجیرهٔ بالادستی: http مستقیم + https از طریق تونل CONNECT + احراز هویت Basic
- ✅ چرخش و تلاش مجدد روی 403 از طریق پراکسی بعدی
- ✅ محافظ SSRF (IP داخلی، localhost، متادیتای 169.254) — کلید محافظت — لیست سیاه
- ✅ محدودیت حجم پاسخ (413) — متد نامعتبر (405) — URL نامعتبر (400)
- ✅ کش (HIT/MISS) — CORS/OPTIONS — درخواست واقعی به اینترنت

سینتکس `proxy.php` با پارسر PHP (گرامر PHP 7/8) اعتبارسنجی شده است.

---

## ⚠️ نکات مهم

1. **پراکسی عمومی نسازید.** حتماً `proxy_key` یا `allowed_domains` را تنظیم کنید، وگرنه هر کسی می‌تواند از سرور شما به‌عنوان پراکسی رایگان استفاده کند.
2. `allow_private_ips = true` فقط برای تست محلی است؛ در محیط واقعی آن را `false` نگه دارید.
3. اگر هاست شما به مقصد دسترسی ندارد (تحریم/بلاک)، از `upstream_proxies` با پراکسی‌های خارجی استفاده کنید.
4. برای دیپلوی روی Liara/Heroku، نسخهٔ Node را با دستور `node proxy.js` اجرا کنید؛ نسخهٔ PHP روی هر هاست PHP بدون تنظیم خاصی کار می‌کند.
