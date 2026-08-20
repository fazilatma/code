# گزارش جامع تطبیق Scraper 4 PHP و Render TypeScript

تاریخ ممیزی: 2026-08-19  
نسخه PHP: 9.80  
نسخه TypeScript: 2.22.0

## روش ممیزی

ممیزی فقط منوی همبرگری نیست. موارد زیر بررسی شده‌اند:

1. تمام شرط‌های `$_GET` و `$_POST['action']` در PHP
2. Routeهای Hono و Legacy Dispatcher در TypeScript
3. عملیات رابط (`data-ma` و کنترل‌های اختصاصی)
4. جداول و stateهای دائمی
5. مسیرهای مخرب و تأییدهای APPLY/SEND/DELETE
6. رفتارهای سازگار، رفتارهای بازطراحی‌شده و موارد واقعاً باقی‌مانده

ابزار قابل تکرار:

```bash
npm run parity:audit
```

خروجی ماشین‌خوان:

```text
parity-audit.json
```

## آمار خام

| معیار | PHP | TypeScript |
|---|---:|---:|
| خطوط سورس اصلی/ماژول‌ها | 36,201 | 1,592 در 27 ماژول |
| توابع شناسایی‌شده | 896 | 302 |
| شرط‌های GET خام PHP | 150 | — |
| GETهای مرتبط پس از حذف پارامترهای صرف | 132 | — |
| Routeهای HTTP | یک فایل چندمنظوره | 112 Route |
| POST actionهای PHP | 28 | 28 مورد دارای Legacy یا Route جایگزین |
| عملیات رابط TypeScript | — | 81 |

تعداد خط یا تابع معیار برابری نیست: PHP شامل UI، endpoint، SSE و چند نسل کد سازگاری در یک فایل است؛ TypeScript منطق را بین ماژول‌ها و Routeهای چندمنظوره تقسیم می‌کند.

## پوشش Endpointهای PHP

بر اساس آخرین audit:

- GETهای مرتبط: 132
- Legacy-compatible یا adapted-route: 99
- GETهای هنوز فاقد نگاشت صریح: 33
- POST actionها: 28
- POST دارای Legacy یا Route معادل: 28

پوشش عددی خام GET حدود 75٪ است؛ اما این عدد «برابری رفتاری» نیست. برخی Routeهای جدید چند endpoint قدیمی را پوشش می‌دهند و برخی endpointهای قدیمی فقط کنترل داخلی مرورگر بوده‌اند.

## ماتریس قابلیت‌ها

| زیرسامانه | وضعیت | توضیح |
|---|---|---|
| پروفایل و ذخیره تنظیمات | نزدیک به کامل | CRUD، مهاجرت PHP، PostgreSQL، نسخه‌بندی |
| سلکتورهای فهرست | نزدیک به کامل | visual studio، count واقعی، scope کانتینر، suggest/test |
| سلکتورهای جزئیات | نزدیک به کامل | صفحه نمونه محصول، gallery، variation، visual flow |
| صفحه‌بندی | کامل در سطح PHP | query/custom/path/full/next-selector/none |
| استخراج HTML استاتیک | عملیاتی | Cheerio، HTML sanitization، جزئیات و رسانه |
| سایت‌های JavaScript-rendered | ناقص | Playwright/Browser service هنوز اضافه نشده |
| محصولات متغیر | عملیاتی | Woo structured variations و fallback JSON-LD/select |
| صف و Worker | عملیاتی و بومی Render | dedup، pause، retry، heartbeat، SSE، selected payload |
| Change Ledger و گزارش | عملیاتی | add/update/remove/diff، digest و CSV |
| WooCommerce | گسترده | sync، variable products، category، images، dedup، manager |
| Basalam | گسترده ولی account-dependent | sync چندغرفه، manager، orders/chats، rejected/category fixes |
| AI | گسترده | providers، background tests، raw analytics، candidates/master/votes |
| اعلان‌ها | عملیاتی | Bale، Rubika، webhook، events، reminders، selected sends |
| پاسخ خودکار | عملیاتی پایه | rules/AI، preview/apply، log، work hours |
| بکاپ | عملیاتی | PHP settings format، encrypted GitHub backup، restore |
| CSV/XLSX | عملیاتی | import/export محصولات و فیلدهای متغیر |
| شبکه مبدأ | عملیاتی | Direct، DoH، IP، HTTP proxy، Worker، fallback |
| نصب نسخه کد | بازطراحی‌شده | Render Deploy/Rollback جایگزین نوشتن فایل PHP شده |

## موارد باقی‌مانده با اولویت بالا

### 1. Browser Rendering واقعی

صفحات React/Vue/Next که محصول را بعد از اجرای JavaScript می‌سازند با Cheerio قابل استخراج نیستند. برای برابری عملی باید یکی از این موارد اضافه شود:

- Playwright در Background Worker
- Browserless
- سرویس Browser Rendering خارجی

### 2. Endpointهای صف قدیمی با قرارداد دقیق

این نام‌ها هنوز نگاشت صریح یک‌به‌یک ندارند یا فقط با API جدید جایگزین شده‌اند:

```text
bsl_queue_cancel
bsl_queue_clear_done
bsl_queue_delete
bsl_queue_detail
bsl_queue_get_products
bsl_queue_mark_done
bsl_queue_restart_server
bsl_queue_save_products
bsl_queue_start_server
bsl_queue_update_progress
woo_queue_clear_done
woo_queue_delete
woo_queue_save_products
woo_queue_start_server
extract_queue_clear_done
extract_queue_delete
```

صف جدید بیشتر این رفتارها را با `/api/jobs` و `/api/queue` پوشش می‌دهد، ولی شکل پاسخ قدیمی دقیقاً یکسان نیست.

### 3. ابزارهای نصب‌کننده GitHub در PHP

```text
vc_branches
vc_check
vc_deploy_info
vc_files
vc_settings
```

در Render، Build/Deploy/Rollback جایگزین مناسب است. اگر API دقیق قدیمی لازم باشد باید یک Adapter به Render Deploy API و GitHub Trees API نوشته شود.

### 4. برخی ابزارهای تخصصی Basalam

```text
bsl_clear_temp
bsl_client_stream
bsl_download_ai_texts
bsl_queue_* legacy contract
```

مدیریت محصول، وضعیت، دسته، AI fix، سفارش و گفتگو وجود دارد؛ اما قرارداد دقیق این endpointهای قدیمی هنوز کامل نیست.

### 5. چت آزمایشی پاسخ خودکار

`ar_chat` به‌شکل محیط حبابی کامل PHP هنوز بازسازی نشده است. موتور rules/AI و اجرای واقعی وجود دارد، ولی UI آزمایش گفت‌وگویی می‌تواند دقیق‌تر شود.

### 6. گزارش‌های بسیار جزئی SSE قدیمی

SSE عمومی Job وجود دارد، ولی نام تمام eventهای قدیمی مانند `basalam_stream`, `woo_stream`, `detail_stream` عیناً تکرار نشده‌اند. API جدید eventهای `progress/done/error` می‌فرستد.

## باگ‌ها و ریسک‌های باقی‌مانده

1. API Basalam ممکن است براساس Scope یا نسخه حساب، شکل پاسخ متفاوت داشته باشد.
2. عملیات مقصد به حساب Sandbox واقعی برای تست End-to-End نیاز دارد.
3. Proxy SOCKS در مسیر مبدأ پشتیبانی نشده؛ HTTP/HTTPS Proxy پشتیبانی می‌شود.
4. Restart وسط عملیات غیرصفی طولانی مانند batch maintenance می‌تواند آن درخواست را قطع کند؛ این ابزارها باید در آینده به Job منتقل شوند.
5. فایل‌های بسیار بزرگ Excel و بکاپ با سقف اندازه محافظت می‌شوند، ولی همچنان حافظه مصرف می‌کنند.
6. تغییر `ADMIN_TOKEN` خزانه و بکاپ‌های رمزنگاری‌شده قبلی را غیرقابل رمزگشایی می‌کند.

## نتیجه صادقانه

نسخه TypeScript دیگر یک نمونه ساده نیست و بیشتر زیرسامانه‌های مهم PHP را به‌صورت بومی Render پیاده کرده است؛ بااین‌حال هنوز نمی‌توان ادعای «تطبیق مو‌به‌مو 100٪» کرد. فاصله اصلی در Browser Rendering، قراردادهای Legacy ریز صف، ابزارهای نصب نسخه و چند endpoint تخصصی Basalam است.

معیار پایان واقعی پروژه باید این باشد:

1. صفر endpoint مهم بدون Mapping یا تصمیم Adaptation مستند
2. تست End-to-End با WooCommerce و Basalam آزمایشی
3. Fixture برای تمام parserها
4. تست پاسخ Legacy برای endpointهایی که مصرف‌کننده خارجی دارند
5. ثبت صریح مواردی که به‌دلیل معماری Render عمداً متفاوت‌اند
