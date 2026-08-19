# Scraper 4 روی Cloudflare Workers

این پوشه نسخهٔ Cloudflare-native از `scraper4.php` نسخهٔ 9.80 است. برنامه از Worker ماژولی، D1، Queues، Cron Triggers و Web Crypto استفاده می‌کند و به Node.js، PostgreSQL، Bash یا فایل‌سیستم دائمی وابسته نیست. پشتیبانی runtime از R2 اختیاری است، اما استقرار پیش‌فرض برای کار بدون کارت بانکی هیچ R2 binding ندارد.

## معماری

- `worker-src/main.ts`: entrypoint و handlerهای `fetch`، `queue` و `scheduled`
- `worker-src/app.ts`: API و داشبورد Hono، احراز هویت، import/export و routeهای سازگاری
- `worker-src/db.ts` و `migrations/`: persistence، bootstrap مقاوم و migrationهای D1
- `worker-src/processor.ts`: پردازش chunked با checkpoint، توقف، retry، watchdog و ادامه در Queue
- `worker-src/scraper.ts`: استخراج فهرست/جزئیات با `HTMLRewriter`، JSON-LD و pagination کامل، شامل `next_selector`
- `worker-src/sync.ts`: WooCommerce و Basalam چندغرفه‌ای با destination map
- `worker-src/vault.ts`: رمزنگاری اتصال‌ها با PBKDF2-SHA256 و AES-GCM
- `worker-src/network.ts`: محدودسازی URL، redirect، timeout و اندازهٔ پاسخ
- `worker-src/parity.ts`: inventory دقیق 57 عملیات منوی PHP و self-test
- `scraper4.worker.js`: bundle تولیدشده و آمادهٔ Direct Upload برای به‌روزرسانی‌های بعدی

D1 منبع canonical داده است. Queue فقط شناسهٔ job را حمل می‌کند و checkpoint هر job در `app_state` می‌ماند؛ بنابراین redelivery یا restart باعث از دست‌رفتن progress نمی‌شود. هر delivery حداکثر `JOB_CHUNK_SIZE` محصول را پردازش می‌کند.

## استقرار کامل فقط با Cloudflare Dashboard

برای نصب نخست **هیچ D1 یا Queue را دستی نسازید**. `wrangler.toml` resourceها را با نام قطعی و بدون ID حساب تعریف می‌کند و `npm run worker:deploy` همهٔ آن‌ها را ایجاد، متصل و migrate می‌کند. این کار در محیط Workers Builds اجرا می‌شود؛ کاربر به terminal، Bash، R2 subscription یا کارت بانکی نیاز ندارد.

R2 عمداً در تنظیمات production تعریف نشده است، زیرا فعال‌سازی آن checkout و روش پرداخت می‌خواهد. endpoint عادی `/api/backup` همچنان backup کامل JSON را برای نگه‌داری روی دستگاه کاربر دانلود می‌کند. فقط حالت اختیاری `persist=true` که backup را مستقیم داخل R2 نگه می‌دارد در این استقرار غیرفعال است.

> نام Worker را دقیقاً `scraper4-cloudflare` بگذارید. نام Queueها ثابت است و consumer عمداً به همان نام‌ها اشاره می‌کند.

### 1. اتصال GitHub به Workers Builds

1. در Cloudflare Dashboard به **Workers & Pages** بروید.
2. **Create application / Import a repository** را انتخاب کنید و repository را از GitHub متصل کنید.
3. نام Worker را `scraper4-cloudflare` قرار دهید.
4. شاخهٔ production را `arena/01a0176d-code` انتخاب کنید.
5. Root directory را `/` یا خالی بگذارید.
6. در **Build variables and secrets** متغیر build زیر را اضافه کنید تا فایل Python قدیمی repository نصب نشود:

   ```text
   SKIP_DEPENDENCY_INSTALL=1
   ```

7. Build command را این مقدار بگذارید؛ `npm ci` وابستگی‌های Worker را نصب می‌کند:

   ```text
   npm ci && npm run worker:test
   ```

8. Deploy command را این مقدار بگذارید:

   ```text
   npm run worker:deploy
   ```

9. Preview deploy برای شاخه‌های دیگر را غیرفعال کنید تا resourceهای production توسط buildهای preview تغییر نکنند.
10. **Save and Deploy** را بزنید.

Git integration پس از هر push جدید به شاخهٔ production، repository را خودش checkout و build می‌کند. Worker در runtime دستور `git pull` اجرا نمی‌کند و نباید هم‌زمان یک GitHub Actions deploy جداگانه فعال شود.

### 2. resourceهایی که deploy خودکار می‌سازد

Wrangler قفل‌شده در `package-lock.json` هنگام نخستین deploy این موارد را provision می‌کند:

| نوع | binding | نام resource ساخته‌شده | اتصال خودکار |
|---|---|---|---|
| D1 | `DB` | `scraper4-cloudflare-db` | بله |
| Queue اصلی | `JOBS` | `scraper4-cloudflare-jobs` | producer + consumer |
| Dead-letter Queue | `JOBS_DLQ` | `scraper4-cloudflare-jobs-dlq` | DLQ برای `JOBS` |
| Cron Trigger | — | `*/5 * * * *` | بله |
| Workers Logs | — | invocation logs | بله |

وجود producer دوم `JOBS_DLQ` عمدی است: Wrangler ابتدا DLQ را می‌سازد و سپس consumer اصلی را با `dead_letter_queue` نصب می‌کند. برنامه لازم نیست روی این binding پیام عادی بفرستد.

اسکریپت `scripts/deploy-cloudflare.mjs` دو مرحلهٔ غیرتعاملی دارد:

1. `wrangler deploy` با automatic provisioning؛
2. اجرای تمام migrationهای pending روی binding خودکار `DB`.

همهٔ مراحل idempotent هستند. resourceها نام قطعی دارند اما ID حساب در Git ذخیره نمی‌شود؛ بنابراین حتی اگر deploy پس از ساخت یک resource قطع شود، retry همان resource را با نام پیدا و متصل می‌کند و نمونهٔ تکراری نمی‌سازد. deployهای بعدی نیز فقط migrationهای جدید را اعمال می‌کنند. علاوه بر آن، `ensureSchema()` در شروع هر isolate تمام دستورهای `CREATE ... IF NOT EXISTS` را یک‌بار اجرا می‌کند تا database تازه یا نیمه‌کاره نیز خودکار ترمیم شود.

در log نخستین build باید پیام‌های provisioning/اتصال برای `DB`، `JOBS` و `JOBS_DLQ` و سپس پیام موفقیت migration دیده شوند. هیچ درخواست R2 یا خطای `10042` نباید وجود داشته باشد. اگر build در میانه قطع شد، **Retry deployment** امن است و resource تکراری ایجاد نمی‌کند.

### 3. افزودن secretها از Dashboard

ساخت resource با ساخت secret متفاوت است؛ secret امن را نمی‌توان داخل Git تولید یا commit کرد. پس از موفقیت نخستین deploy:

1. Worker `scraper4-cloudflare` را باز کنید.
2. به **Settings → Variables and Secrets** بروید.
3. `ADMIN_TOKEN` را به‌صورت **Secret** و با یک مقدار طولانی و تصادفی اضافه کنید.
4. `VAULT_SECRET` را نیز به‌صورت **Secret** و با مقداری مستقل، طولانی و تصادفی اضافه کنید.
5. تغییرات را Save/Deploy کنید و URL `workers.dev` را باز کنید.

`ADMIN_TOKEN` اجباری و کلید ورود API است. `VAULT_SECRET` کلید ترجیحی vault است و پس از ذخیرهٔ اتصال‌ها نباید تغییر کند. اگر تعریف نشود، برنامه برای سازگاری از `ADMIN_TOKEN` استفاده می‌کند؛ در این حالت چرخاندن token بدون export/import مجدد اتصال‌ها ممکن نیست. برای restore یک backup روی استقرار دیگر نیز همان `VAULT_SECRET` لازم است.

متغیرهای credential مقصد مانند `WOO_URL`، `WOO_KEY`، `WOO_SECRET` و `BASALAM_TOKEN` فقط fallback هستند. روش پیشنهادی، ثبت اتصال‌ها از پنل رمزگذاری‌شدهٔ خود برنامه است. هیچ secret حساسی را در `wrangler.toml` قرار ندهید.

### 4. کنترل نصب از مرورگر

بعد از تعریف secretها:

- `https://<worker>.workers.dev/health` باید `ok: true` و `databaseReady: true` بدهد.
- داشبورد را باز و `ADMIN_TOKEN` را در تب تنظیمات وارد کنید.
- endpoint احراز هویت‌شدهٔ `/api/selftest` باید `ok: true` و `total: 57` نشان دهد.
- در **Bindings** باید `DB`، `JOBS` و `JOBS_DLQ` دیده شوند؛ نبودن `BACKUPS` عمدی است.
- در **Triggers** باید Cron پنج‌دقیقه‌ای و Queue consumer دیده شوند.

نیازی به D1 Console، R2، ساخت Queue، paste کردن UUID یا اجرای migration دستی نیست.

## توسعه و آزمایش محلی اختیاری

این بخش فقط برای توسعه‌دهنده‌ای است که terminal دارد و برای نصب dashboard-only لازم نیست:

```bash
npm install
npm run worker:db:local
npm run worker:dev
```

Cron محلی:

```bash
curl http://127.0.0.1:8787/cdn-cgi/local/scheduled
```

کنترل‌های قبل از deploy:

```bash
npm run worker:test
npx wrangler deploy --dry-run
```

این دستورها typecheck سخت‌گیرانه، bundle، تست runtime/security، کنترل bindingهای declarative، تطابق migration و inventory 57 قابلیتی را اجرا می‌کنند.

## مهاجرت داده از PHP

دو مسیر وجود دارد:

1. در داشبورد Worker، **تنظیمات عمومی ← انتقال همه تنظیمات** را باز کنید و bundle خروجی PHP را import کنید.
2. فقط برای `profiles.json` قدیمی، درخواست `POST /api/import-php` با `Authorization: Bearer ADMIN_TOKEN` قابل استفاده است.

فرمت `scraper4-php-compatible` برای فایل‌های اتصال، تنظیمات، category learning، autoreply و profile/product پشتیبانی می‌شود. اتصال‌های plaintext ورودی بلافاصله با Web Crypto رمز می‌شوند.

پیش از migration بزرگ، endpoint `/api/backup` را اجرا و فایل JSON دانلودشده را روی دستگاه خود نگه‌داری کنید. بازیابی از `/api/restore` انجام می‌شود. حالت `/api/backup?persist=true` فقط برای استقرارهای دارای R2 است و در نسخهٔ بدون subscription پیام راهنمای کنترل‌شده برمی‌گرداند.

## API و امنیت

- `GET /health` عمومی است و secret نمایش نمی‌دهد.
- داشبورد و JavaScript عمومی‌اند، اما تمام `/api/*` به `Authorization: Bearer <ADMIN_TOKEN>` نیاز دارند.
- عملیات مخرب علاوه بر token به عبارت تأیید `APPLY` یا `DELETE` نیاز دارند.
- Visual Selector ticket امضاشده و پنج‌دقیقه‌ای دارد؛ HTML مقصد sanitize می‌شود.
- fetch مقصد فقط HTTP/HTTPS عمومی را می‌پذیرد، redirect را دوباره اعتبارسنجی می‌کند و پاسخ محدود دارد.
- مستقیم‌رفتن از طریق SOCKS، custom DNS یا proxy سطح socket در Workers ممکن نیست. modeهای غیرمستقیم AI از `workerUrl` به‌عنوان gateway HTTP استفاده می‌کنند.

endpointهای تشخیصی مهم:

```text
GET  /api/status
GET  /api/selftest
GET  /api/parity
GET  /api/jobs
POST /api/queue-watchdog
POST /api/source-test
```

## Cron، Queue و بازیابی

Cron هر پنج دقیقه:

- jobهای running راکد را با watchdog می‌بندد؛
- profileهای موعدرسیده را enqueue می‌کند؛
- jobهای queued بدون پیام را recover می‌کند؛
- autoreply و digest زمان‌بندی‌شده را اجرا می‌کند.

Scrape در مرز هر chunk، شماره صفحه، URL بعدی، محصولات page، offset و source keyهای دیده‌شده را checkpoint می‌کند. `next_selector` واقعاً href لینک بعد را از HTML می‌گیرد. retirement فقط پس از پایان موفق پیمایش اجرا می‌شود. خطای هر مقصد برای هر محصول ثبت می‌شود و مقصد/محصول بعدی ادامه پیدا می‌کند.

## Direct Upload و به‌روزرسانی

`deploy-worker.ts` فقط `scraper4.worker.js` را upload می‌کند و bindingهای موجود را با `keep_bindings` نگه می‌دارد. این مسیر برای **به‌روزرسانی کد پس از bootstrap موفق** است؛ نصب نخست باید از Workers Builds و `npm run worker:deploy` انجام شود، زیرا Direct Upload به‌تنهایی resourceهای account-level یا migrationها را provision نمی‌کند.

هر بار پس از تغییر source، bundle tracked با `npm run worker:build` بازسازی می‌شود.

## نسخه و rollback

نسخه‌ها و deploymentها از تب **Deployments** خود Worker در Dashboard دیده می‌شوند و rollback از همان رابط قابل انجام است. برای تغییر schema، migration جدید بسازید؛ migration قبلی را بعد از استفاده در production ویرایش نکنید.
