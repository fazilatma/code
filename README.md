# Scraper 4

نسخهٔ Cloudflare Workers اسکرپر محصولات، همگام‌سازی WooCommerce/Basalam، اتوماسیون، AI، اعلان‌ها و ابزارهای نگهداری.

## استقرار بدون terminal

راهنمای کامل dashboard-only در [CLOUDFLARE-WORKER.md](CLOUDFLARE-WORKER.md) است. در Workers Builds کافی است repository را با نام Worker دقیق `scraper4-cloudflare` متصل کنید و این مقادیر را بگذارید:

```text
Build command:  npm ci && npm run worker:test
Deploy command: npm run worker:deploy
```

Deploy نخست D1، R2، Queue اصلی، DLQ، Queue consumer، Cron و schema دیتابیس را خودکار ایجاد و متصل می‌کند؛ ساخت دستی resource یا paste کردن UUID لازم نیست. فقط `ADMIN_TOKEN` و `VAULT_SECRET` باید پس از deploy به‌صورت Secret در Dashboard ثبت شوند.

## توسعهٔ محلی اختیاری

```bash
npm install
npm run worker:test
npm run worker:db:local
npm run worker:dev
```

- source اصلی Worker: `worker-src/`
- entrypoint: `worker-src/main.ts`
- تنظیمات و draft bindingهای Cloudflare: `wrangler.toml`
- deploy و migration خودکار: `scripts/deploy-cloudflare.mjs`
- migrationهای D1: `migrations/`
- bundle آمادهٔ Direct Upload: `scraper4.worker.js`
- مرجع رفتاری PHP: `scraper4.php`
