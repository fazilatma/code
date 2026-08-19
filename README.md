# Scraper 4

نسخهٔ Cloudflare Workers اسکرپر محصولات، همگام‌سازی WooCommerce/Basalam، اتوماسیون، AI، اعلان‌ها و ابزارهای نگهداری.

راهنمای نصب، migration از `scraper4.php` و عملیات production در [CLOUDFLARE-WORKER.md](CLOUDFLARE-WORKER.md) است.

```bash
npm install
npm run worker:test
npm run worker:db:local
npm run worker:dev
```

- source اصلی Worker: `worker-src/`
- entrypoint: `worker-src/main.ts`
- تنظیمات Cloudflare: `wrangler.toml`
- migrationهای D1: `migrations/`
- bundle آماده Direct Upload: `scraper4.worker.js`
- مرجع رفتاری PHP: `scraper4.php`
