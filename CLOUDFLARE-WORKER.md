# Scraper 4 روی Cloudflare Workers

این پوشه نسخهٔ Cloudflare-native از `scraper4.php` نسخهٔ 9.80 است. برنامه از Worker ماژولی، D1، Queues، Cron Triggers، R2 و Web Crypto استفاده می‌کند و به Node.js، PostgreSQL یا فایل‌سیستم دائمی وابسته نیست.

## معماری

- `worker-src/main.ts`: entrypoint و handlerهای `fetch`، `queue` و `scheduled`
- `worker-src/app.ts`: API و داشبورد Hono، احراز هویت، import/export و routeهای سازگاری
- `worker-src/db.ts` و `migrations/`: persistence و migrationهای D1
- `worker-src/processor.ts`: پردازش chunked با checkpoint، توقف، retry، watchdog و ادامه در Queue
- `worker-src/scraper.ts`: استخراج فهرست/جزئیات با `HTMLRewriter`، JSON-LD و انواع pagination از جمله `next_selector`
- `worker-src/sync.ts`: WooCommerce و Basalam چندغرفه‌ای با destination map
- `worker-src/vault.ts`: رمزنگاری اتصال‌ها با PBKDF2-SHA256 و AES-GCM
- `worker-src/network.ts`: محدودسازی URL، redirect، timeout و اندازهٔ پاسخ
- `worker-src/parity.ts`: inventory دقیق 57 عملیات منوی PHP و self-test
- `scraper4.worker.js`: bundle تولیدشده و آمادهٔ Direct Upload

D1 منبع canonical داده است. Queue فقط شناسهٔ job را حمل می‌کند و checkpoint هر job در `app_state` می‌ماند؛ بنابراین redelivery یا restart باعث از دست رفتن progress نمی‌شود. هر delivery حداکثر `JOB_CHUNK_SIZE` محصول را پردازش می‌کند.

## نصب نخست

نیازمندی‌ها: Node.js 20+ و یک حساب Cloudflare با Workers، D1، Queues و R2.

```bash
npm ci
npx wrangler login
npx wrangler d1 create scraper4-db
npx wrangler queues create scraper4-jobs
npx wrangler queues create scraper4-jobs-dlq
npx wrangler r2 bucket create scraper4-backups
```

شناسهٔ واقعی D1 را به‌جای UUID صفر در `wrangler.toml` بگذارید. سپس:

```bash
npx wrangler secret put ADMIN_TOKEN
npx wrangler secret put VAULT_SECRET
npm run worker:db:remote
npm run worker:test
npm run worker:deploy
```

`ADMIN_TOKEN` اجباری است و برای API استفاده می‌شود. `VAULT_SECRET` را نیز طولانی و تصادفی بسازید و پس از ذخیرهٔ اتصال‌ها تغییر ندهید؛ vault با کلیدی مشتق‌شده از آن رمز می‌شود. اگر `VAULT_SECRET` تعریف نشود، برنامه برای سازگاری از `ADMIN_TOKEN` استفاده می‌کند و در آن حالت چرخاندن token بدون export/import مجدد اتصال‌ها ممکن نیست. برای بازیابی backup روی استقرار دیگر نیز همان `VAULT_SECRET` لازم است. پس از استقرار، URL Worker را باز و `ADMIN_TOKEN` را در تب تنظیمات وارد کنید.

برای credentialهای مقصد، استفاده از پنل رمزگذاری‌شده پیشنهاد می‌شود. متغیرهای `WOO_URL`، `WOO_KEY`، `WOO_SECRET`، `BASALAM_TOKEN` و مشابه فقط fallback هستند و secretهای حساس نباید داخل `wrangler.toml` commit شوند.

## توسعه و آزمایش محلی

```bash
npm install
npm run worker:db:local
npx wrangler dev --ip 0.0.0.0 --var ADMIN_TOKEN:local-test-token
```

Cron محلی:

```bash
curl http://127.0.0.1:8787/cdn-cgi/local/scheduled
```

مجموعهٔ کنترل‌های قبل از deploy:

```bash
npm run worker:test
npx wrangler deploy --dry-run
```

این دستورها typecheck سخت‌گیرانه، bundle، تست runtime/security، تطابق migration و inventory 57 قابلیتی را اجرا می‌کنند.

## مهاجرت داده از PHP

دو مسیر وجود دارد:

1. در داشبورد Worker، **تنظیمات عمومی ← انتقال همه تنظیمات** را باز کنید و bundle خروجی PHP را import کنید.
2. فقط برای `profiles.json` قدیمی، endpoint زیر را استفاده کنید:

```bash
curl -X POST https://WORKER.example/api/import-php \
  -H 'Authorization: Bearer ADMIN_TOKEN' \
  -H 'Content-Type: application/json' \
  --data-binary '{"profiles": { ... }}'
```

فرمت `scraper4-php-compatible` برای فایل‌های اتصال، تنظیمات، category learning، autoreply و profile/product پشتیبانی می‌شود. اتصال‌های plaintext ورودی بلافاصله با Web Crypto رمز می‌شوند.

پیش از migration بزرگ از endpoint `/api/backup?persist=true` استفاده کنید تا نسخه‌ای هم در R2 ذخیره شود. دانلود backup معمولی از `/api/backup` و بازیابی از `/api/restore` انجام می‌شود.

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

## استقرار با پنل `deploy-worker.ts`

پنل تک‌فایلی، `scraper4.worker.js` را از GitHub deploy می‌کند و bindingهای موجود را با `keep_bindings` نگه می‌دارد. این پنل برای **به‌روزرسانی کد بعد از نصب نخست** است. نصب نخست و تغییر D1/Queue consumer/Cron/R2 باید با Wrangler انجام شود، چون upload یک فایل JavaScript این منابع account-level را ایجاد نمی‌کند.

هر بار پس از تغییر source، bundle tracked را بازسازی کنید:

```bash
npm run worker:build
```

## نسخه و rollback

```bash
npx wrangler versions list
npx wrangler deployments list
npx wrangler rollback
```

برای تغییر schema، migration جدید بسازید؛ migration قبلی را بعد از استفادهٔ production ویرایش نکنید.
