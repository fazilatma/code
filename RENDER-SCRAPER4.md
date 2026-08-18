# Scraper 4 on Render

این نسخه از صفر برای زیرساخت Render طراحی شده و به APIهای Cloudflare وابسته نیست.

## پشته انتخاب‌شده

- Node.js 20 + TypeScript
- Hono برای Web/API
- Cheerio برای استخراج HTML و CSS selector
- PostgreSQL برای پروفایل‌ها، محصولات، صف و گزارش‌ها
- صف تراکنشی PostgreSQL با `FOR UPDATE SKIP LOCKED`
- Worker داخلی در همان Web Service برای راه‌اندازی ساده

## خطای `DATABASE_URL is required`

اگر Web Service را قبلاً به GitHub متصل کرده‌اید، Render فایل `render.yaml` را به‌عنوان Blueprint اجرا نمی‌کند؛ بنابراین PostgreSQL خودکار ساخته نمی‌شود. این مراحل را یک‌بار انجام دهید:

1. در داشبورد Render گزینه **New → PostgreSQL** را بزنید.
2. بعد از آماده‌شدن دیتابیس، **Internal Database URL** را کپی کنید.
3. وارد Web Service اسکریپر شوید و در **Environment** این متغیر را اضافه کنید:
   ```text
   DATABASE_URL=<Internal Database URL>
   ```
4. یک Secret دیگر نیز بسازید:
   ```text
   ADMIN_TOKEN=<a long random value>
   ```
5. روی **Save Changes** بزنید. سرویس Restart می‌شود و جداول به‌صورت خودکار ساخته می‌شوند.

نسخه جدید حتی بدون دیتابیس Crash نمی‌کند و صفحه راه‌اندازی را نشان می‌دهد، ولی استخراج تا زمان تعریف `DATABASE_URL` فعال نخواهد شد.

## استقرار سریع

### روش Blueprint

1. در Render گزینه **New → Blueprint** را انتخاب کنید.
2. مخزن و برنچ مورد نظر را متصل کنید.
3. Render فایل `render.yaml` را تشخیص می‌دهد و Web Service و PostgreSQL را می‌سازد.
4. بعد از ساخت، متغیرهای ووکامرس و باسلام را در Environment وارد کنید.
5. مقدار تولیدشده `ADMIN_TOKEN` را کپی و در صفحه داشبورد وارد کنید.

### تنظیم Web Service موجود

- Runtime: `Node`
- Build Command:
  ```bash
  npm ci && npm run render:build
  ```
- Start Command:
  ```bash
  npm run render:start
  ```
- Health Check Path: `/health`

متغیر اجباری:

```text
DATABASE_URL=<Render PostgreSQL internal connection string>
ADMIN_TOKEN=<long random value>
RUN_WORKER_IN_WEB=true
```

اتصال مقصد:

```text
WOO_URL
WOO_KEY
WOO_SECRET
BASALAM_TOKEN
BASALAM_VENDOR_ID
BASALAM_API=https://openapi.basalam.com/v1
```

## پردازش مستقل برای مقیاس بالاتر

در حالت ساده Web Service هم API و هم صف را اجرا می‌کند. برای حجم بالا:

1. روی Web Service بگذارید `RUN_WORKER_IN_WEB=false`.
2. یک Render Background Worker از همان مخزن بسازید.
3. Build Command همان دستور بالا باشد.
4. Start Command:
   ```bash
   npm run render:worker
   ```
5. همان `DATABASE_URL` و متغیرهای مقصد را به Background Worker بدهید.

چند Worker می‌توانند هم‌زمان اجرا شوند؛ قفل تراکنشی PostgreSQL مانع اجرای تکراری یک Job می‌شود.

## Cron

در حالت `RUN_WORKER_IN_WEB=true` زمان‌بند داخلی هر دقیقه پروفایل‌های سررسیدشده را صف‌بندی می‌کند. برای Cron مستقل Render، فرمان زیر را هر دقیقه اجرا کنید:

```bash
npm run render:cron
```

و روی Web Service مقدار `RUN_WORKER_IN_WEB=false` یا Worker مستقل را فعال کنید.

## مهاجرت پروفایل‌های PHP

Endpoint زیر JSON فایل `profiles.json` قدیمی را قبول می‌کند:

```http
POST /api/import-php
Authorization: Bearer ADMIN_TOKEN
Content-Type: application/json

{"profiles": { ...محتوای profiles.json... }}
```

## نکات عملیاتی

- فایل‌سیستم Render پایدار فرض نشده؛ تمام داده مهم در PostgreSQL قرار می‌گیرد.
- API Tokenها فقط در Environment Variables تعریف شوند.
- صفحات کاملاً JavaScript-rendered با Cheerio استخراج نمی‌شوند؛ برای آن‌ها API داخلی سایت یا Browserless/Playwright service لازم است.
- Basalam API ممکن است با نسخه یا حساب شما endpoint متفاوتی داشته باشد؛ `BASALAM_API` قابل تنظیم است.
- Web Service رایگان ممکن است در نبود ترافیک Sleep شود. برای زمان‌بندی دقیق از پلن دائمی یا Cron/Background Worker استفاده کنید.

## توسعه محلی

```bash
npm ci
export DATABASE_URL=postgresql://...
export ADMIN_TOKEN=dev-secret
npm run typecheck
npm run render:build
npm start
```
