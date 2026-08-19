# Scraper 4

نسخهٔ Cloudflare Workers اسکرپر محصولات، همگام‌سازی WooCommerce/Basalam، اتوماسیون، AI، اعلان‌ها و ابزارهای نگهداری.

## استقرار بدون terminal

راهنمای کامل dashboard-only در [CLOUDFLARE-WORKER.md](CLOUDFLARE-WORKER.md) است. در Workers Builds کافی است repository را با نام Worker دقیق `scraper4-cloudflare` متصل کنید و این مقادیر را بگذارید:

```text
Build variable: SKIP_DEPENDENCY_INSTALL=1
Build command:  npm ci && npm run worker:test
Deploy command: npm run worker:deploy
```

این استقرار به R2، subscription یا کارت بانکی نیاز ندارد. deploy نخست D1، Queue اصلی، DLQ، Queue consumer، Cron و schema دیتابیس را خودکار ایجاد و متصل می‌کند؛ ساخت دستی resource یا paste کردن UUID لازم نیست. backup کامل از `/api/backup` به‌صورت فایل JSON دانلود می‌شود. ورود پنل و API فعلاً به `ADMIN_TOKEN` نیاز ندارد. فقط `VAULT_SECRET` باید پس از deploy با حداقل طول ۸ کاراکتر و به‌صورت Secret در Dashboard ثبت شود تا اطلاعات اتصال رمزگذاری شوند.

## توسعهٔ محلی اختیاری

```bash
npm install
npm run worker:test
npm run worker:db:local
npm run worker:dev
```

- source اصلی Worker: `worker-src/`
- entrypoint: `worker-src/main.ts`
- تنظیمات و bindingهای declarative Cloudflare: `wrangler.toml`
- deploy و migration خودکار: `scripts/deploy-cloudflare.mjs`
- migrationهای D1: `migrations/`
- bundle آمادهٔ Direct Upload: `scraper4.worker.js`
- مرجع رفتاری PHP: `scraper4.php`
