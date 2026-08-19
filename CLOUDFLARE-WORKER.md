# Scraper 4 روی Cloudflare Workers

این پوشه نسخهٔ Cloudflare-native از `scraper4.php` نسخهٔ 9.80 است. برنامه از Worker ماژولی، D1، Queues، Cron Triggers و Web Crypto استفاده می‌کند و به Node.js، PostgreSQL، Bash یا فایل‌سیستم دائمی وابسته نیست. پشتیبانی runtime از R2 اختیاری است، اما استقرار پیش‌فرض برای کار بدون کارت بانکی هیچ R2 binding ندارد.

## معماری

- `worker-src/main.ts`: entrypoint و handlerهای `fetch`، `queue` و `scheduled`
- `worker-src/app.ts`: API و داشبورد Hono، import/export، diagnostics و routeهای سازگاری (طبق تصمیم مالک بدون صفحهٔ ورود و `ADMIN_TOKEN`)
- `worker-src/db.ts` و `migrations/`: persistence، bootstrap مقاوم و migrationهای D1
- `worker-src/processor.ts`: پردازش chunked با checkpoint، توقف، retry، watchdog و ادامه در Queue
- `worker-src/scraper.ts`: استخراج فهرست/جزئیات با `HTMLRewriter`، JSON-LD و pagination کامل، شامل `next_selector`
- `worker-src/sync.ts`: WooCommerce و Basalam چندغرفه‌ای با destination map
- `worker-src/vault.ts`: رمزنگاری اتصال‌ها با PBKDF2-SHA256 و AES-GCM
- `worker-src/network.ts`: محدودسازی URL، redirect، timeout و اندازهٔ پاسخ
- `worker-src/parity.ts`: inventory دقیق 57 عملیات منوی PHP و self-test
- `scraper4.worker.js`: bundle تولیدشده و آمادهٔ Direct Upload برای به‌روزرسانی‌های بعدی

D1 منبع canonical داده است. Queue فقط شناسهٔ job را حمل می‌کند و checkpoint هر job در `app_state` می‌ماند؛ بنابراین redelivery یا restart باعث از دست‌رفتن progress نمی‌شود. هر delivery حداکثر `JOB_CHUNK_SIZE` محصول را پردازش می‌کند.

### مشخصات اجرایی Cloudflare، نه یک سرور ثابت

Worker ماشین مجازی اختصاصی با CPU یا vCPU قابل‌انتخاب نیست؛ در V8 isolateهای Cloudflare روی شبکهٔ جهانی اجرا و خودکار scale می‌شود. طبق [Limits رسمی](https://developers.cloudflare.com/workers/platform/limits/) در پلن Free حدود مهم عبارت‌اند از: **128 MB حافظه برای هر isolate، 10 ms زمان CPU برای هر HTTP request، 100,000 درخواست در روز، 50 subrequest و 6 اتصال خروجی همزمان برای هر invocation، bundle فشردهٔ 3 MB و startup حداکثر 1 ثانیه**. انتظار پاسخ شبکه CPU time محسوب نمی‌شود. درخواست HTTP hard wall-time عمومی ندارد، ولی به باز ماندن client وابسته است؛ Queue/Cron/Alarm حداکثر 15 دقیقه wall-time و `waitUntil()` حداکثر 30 ثانیه پس از response/disconnect فرصت دارد.

در پلن Paid حافظه همچنان 128 MB است؛ CPU درخواست HTTP به‌طور پیش‌فرض 30 ثانیه و قابل افزایش تا 5 دقیقه و subrequest پیش‌فرض 10,000 است. سقف body ورودی به plan اصلی حساب Cloudflare وابسته است (Free/Pro برابر 100 MB)، نه صرفاً Workers plan. جدول کامل، معنای connection و محدودیت bundle در [`CLOUDFLARE-PARITY.md`](./CLOUDFLARE-PARITY.md) ثبت شده است. dry-run فعلی پروژه فقط **491.62 KiB raw / 120.67 KiB gzip** است.

پروژه برای پلن Free تنظیم شده: `DETAIL_CONCURRENCY=2`، `JOB_CHUNK_SIZE=10`، checkpoint در D1 و Queue برای ادامهٔ کار. این adaptation به‌جای نگه‌داشتن یک process طولانی PHP، هر اجرا را کوچک و قابل retry می‌کند.

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
3. `VAULT_SECRET` را به‌صورت **Secret** و با یک مقدار تصادفی حداقل ۸ کاراکتری اضافه کنید.
4. تغییرات را Save/Deploy کنید و URL `workers.dev` را باز کنید.

ورود پنل و API فعلاً به `ADMIN_TOKEN` نیاز ندارد. `VAULT_SECRET` کلید رمزگذاری vault است و پس از ذخیرهٔ اتصال‌ها نباید تغییر یا حذف شود. برای restore یک backup روی استقرار دیگر نیز همان `VAULT_SECRET` لازم است.

متغیرهای credential مقصد مانند `WOO_URL`، `WOO_KEY`، `WOO_SECRET` و `BASALAM_TOKEN` فقط fallback هستند. روش پیشنهادی، ثبت اتصال‌ها از پنل رمزگذاری‌شدهٔ خود برنامه است. هیچ secret حساسی را در `wrangler.toml` قرار ندهید.

### 4. کنترل نصب از مرورگر

بعد از تعریف secret:

- `https://<worker>.workers.dev/health` باید `ok: true`، `databaseReady: true` و `authenticationRequired: false` بدهد.
- داشبورد باید بدون کادر ورود مستقیماً اطلاعات را بارگذاری کند.
- endpoint `/api/selftest` باید بدون توکن `ok: true` و `total: 57` نشان دهد.
- endpoint `/api/debug` باید بررسی‌های runtime، vault، D1 و Queue را بدون نمایش مقدار Secret برگرداند.
- `/api/parity` خلاصهٔ 178 dispatcher و inventory سطح منو را نشان می‌دهد؛ matrix کامل در `parity-manifest.json` است.
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

این دستورها typecheck سخت‌گیرانه، bundle و ۲۶ تست runtime/security/extraction/Cloudflare-AI/مدیریت مقصد/UI را اجرا می‌کنند؛ audit جداگانه نیز bindingهای declarative، migrationها و 178 mapping سازگاری را کنترل می‌کند.

## مهاجرت داده از PHP

دو مسیر وجود دارد:

1. در داشبورد Worker، **تنظیمات عمومی ← انتقال همه تنظیمات** را باز کنید و bundle خروجی PHP را import کنید.
2. فقط برای `profiles.json` قدیمی، درخواست `POST /api/import-php` بدون هدر ورود قابل استفاده است.

فرمت `scraper4-php-compatible` برای فایل‌های اتصال، تنظیمات، category learning، autoreply، profile/product و variation پشتیبانی می‌شود. `syncConfig`، `src_network`، پروفایل‌های `noExtract`، fallback category و غرفه‌ها نیز normalize می‌شوند. اتصال‌های plaintext ورودی بلافاصله با Web Crypto رمز می‌شوند.

PBKDF2 در Worker دقیقاً با سقف Cloudflare یعنی 100,000 iteration اجرا می‌شود. اگر فایل حاوی envelope رمزگذاری‌شدهٔ قدیمی با iteration بیشتر باشد، import به‌جای خطای runtime با پیام سازگاری و HTTP 400 متوقف می‌شود؛ در این حالت از نسخه PHP یک settings export تازه با اتصال‌های قابل انتقال بگیرید و همان فایل را از داشبورد وارد کنید. `VAULT_SECRET` هیچ‌گاه در log یا `/api/debug` برگردانده نمی‌شود.

پیش از migration بزرگ، endpoint `/api/backup` را اجرا و فایل JSON دانلودشده را روی دستگاه خود نگه‌داری کنید. بازیابی از `/api/restore` انجام می‌شود. حالت `/api/backup?persist=true` فقط برای استقرارهای دارای R2 است و در نسخهٔ بدون subscription پیام راهنمای کنترل‌شده برمی‌گرداند.

## API و امنیت

- `GET /health` عمومی است و secret نمایش نمی‌دهد.
- ورود با `ADMIN_TOKEN` فعلاً غیرفعال است و تمام `/api/*` بدون هدر ورود در دسترس‌اند؛ URL Worker را عمومی منتشر نکنید.
- عملیات مخرب همچنان به عبارت تأیید `APPLY` یا `DELETE` نیاز دارند.
- Visual Selector ticket امضاشده و پنج‌دقیقه‌ای دارد؛ HTML مقصد sanitize می‌شود.
- fetch مقصد فقط HTTP/HTTPS عمومی را می‌پذیرد، redirect را دوباره اعتبارسنجی می‌کند و پاسخ محدود دارد.
- مستقیم‌رفتن از طریق SOCKS، custom DNS یا proxy سطح socket در Workers ممکن نیست. حالت `workerUrl` به‌عنوان gateway HTTP هم برای AI و هم scraping فهرست/جزئیات عملیاتی است؛ modeهای غیرقابل‌اجرا با خطای روشن رد می‌شوند.

endpointهای تشخیصی مهم:

```text
GET  /api/status
GET  /api/selftest
GET  /api/debug
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
