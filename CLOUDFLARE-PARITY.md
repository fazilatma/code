# گزارش تطبیق Scraper 4 با Cloudflare Workers

مرجع این ممیزی فایل **`scraper4.php` نسخه 9.80** است. این گزارش وضعیت پیاده‌سازی Cloudflare را در تاریخ 2026-08-19 ثبت می‌کند.

## نتیجهٔ قابل بررسی

| مورد | نتیجه |
|---|---:|
| dispatcherهای GET مرجع | 150 |
| actionهای POST مرجع | 28 |
| کل عملیات مرجع | **178** |
| نگاشت‌های یکتای Worker | **178** |
| عملیات بدون نگاشت | **0** |
| عملیاتی (`operational`) | 72 |
| سازگارشده با Cloudflare (`adapted`) | 74 |
| وابسته به اتصال حساب مقصد (`account-dependent`) | 32 |
| خطای audit | 0 |
| هشدار audit | 0 |

فهرست خط‌به‌خط هر 178 عملیات در [`parity-manifest.json`](./parity-manifest.json) است. خروجی ممیزی ماشینی، route/evidence هر نگاشت و کنترل‌های امنیتی در [`parity-audit.json`](./parity-audit.json) قرار دارد. generator و audit نیز به‌ترتیب در `scripts/build-parity-manifest.mjs` و `scripts/parity-audit.mjs` هستند؛ بنابراین اعداد بالا ادعای دستی یا فهرست انتخابی نیستند.

`GET /api/parity` موجودی 57 قابلیت سطح منوی PHP را به‌همراه خلاصهٔ 178 dispatcher برمی‌گرداند. matrix کامل در artifactهای بالا نگهداری می‌شود.

## معنی وضعیت‌ها

- **operational:** در Worker، D1، Queue یا داشبورد به‌شکل مستقیم قابل اجراست.
- **adapted:** رفتار کاربردی حفظ شده اما به‌علت تفاوت PHP و Cloudflare با primitive بومی Worker جایگزین شده است.
- **account-dependent:** route و منطق وجود دارد، ولی نتیجهٔ واقعی فقط با credential و حساب WooCommerce، Basalam، AI یا پیام‌رسان قابل تأیید است.
- هیچ موردی با وضعیت `missing` وجود ندارد.

## قابلیت‌های تکمیل‌شده در این ممیزی

- رفع قطعی خطای `Pbkdf2 failed ... requested 120000`: رمزگذاری جدید AES-256-GCM با PBKDF2 برابر سقف Cloudflare یعنی **100,000 iteration** انجام می‌شود.
- اعتبارسنجی envelopeهای قدیمی؛ envelope دارای iteration بیشتر از 100,000 با پیام مهاجرت روشن و پاسخ 400 رد می‌شود، نه خطای مبهم runtime.
- import/export تنظیمات PHP شامل `syncConfig`، `selectors`، `src_network`، fallback categoryها، غرفه‌ها، محصولات، variationها و وضعیت‌های عمومی.
- import پروفایل‌های `noExtract` بدون نیاز به URL یا selector؛ Cron برای آن‌ها کار sync مستقیم می‌سازد.
- پیشنهاد selector برای فهرست، جزئیات و variation؛ تست واقعی `type: "variations"` و انتخاب بصری variation.
- استخراج گروه‌ها و گزینه‌های variation و نگهداری آن‌ها در CSV/JSON/D1.
- همگام‌سازی WooCommerce به‌صورت variable parent + child variation.
- Basalam با fallback دسته‌بندی در سطح پروفایل/اتصال و ارسال چندغرفه‌ای.
- اتصال غیرمستقیم سایت مبدأ در حالت Cloudflare Worker gateway، هم برای صفحهٔ فهرست و هم جزئیات.
- endpoint فقط‌خواندنی `GET /api/debug` برای runtime، KDF، vault decrypt، bindingهای D1/Queue/DLQ، integrity/schema/relations دیتابیس و jobهای متوقف.
- داشبورد و تمام `/api/*` طبق تصمیم مالک بدون `ADMIN_TOKEN` یا Bearer قابل استفاده‌اند.

## سازگارسازی‌های مهم PHP → Cloudflare

| رفتار PHP | پیاده‌سازی Cloudflare |
|---|---|
| فایل‌های JSON و state محلی | D1 و `app_state` |
| حلقه/worker پردازشی PHP | Cloudflare Queue consumer |
| Cron سیستم‌عامل | Worker Cron Trigger |
| polling/stream خروجی طولانی | jobهای D1 + polling داشبورد |
| دانلود backup سرور | فایل JSON دانلودی و restore؛ بدون R2 |
| cURL مستقیم | `fetch` امن با کنترل SSRF، redirect و سقف اندازه |
| proxy/DoH/DNS pin/SOCKS | `fetch` مستقیم یا Worker HTTPS gateway؛ transportهای غیرقابل‌پیاده‌سازی صریحاً رد می‌شوند |
| فایل session و CSRF PHP | API بدون login بنا بر تصمیم مالک؛ محدودیت body، validation و security header حفظ شده است |
| چندفرایندی PHP | Queue batch و checkpointهای D1 |
| بروزرسانی فایل PHP | استقرار versioned از Workers Builds/Wrangler |

### محدودیت صریح network mode

Cloudflare Workers اجازهٔ پیاده‌سازی همان transportهای cURL شامل SOCKS، DNS pinning و proxy دلخواه PHP را نمی‌دهد. حالت `worker` واقعاً اجرا می‌شود و URL هدف را بعد از SSRF validation از gateway می‌گیرد. modeهای `doh`، `dns` و `proxy` در فایل انتقال حفظ می‌شوند، اما هنگام اجرا به‌جای fallback پنهان، خطای روشن می‌دهند.

## امنیت Secret و انتقال تنظیمات

- `VAULT_SECRET` حداقل 8 کاراکتر است و فقط برای vault و ticket انتخاب‌گر بصری استفاده می‌شود.
- مقدار `VAULT_SECRET`، credentialها و tokenها در `/api/debug`، log یا پاسخ diagnostics بازگردانده نمی‌شوند.
- vault از salt و IV تصادفی و AES-GCM استفاده می‌کند.
- فایل settings export بنا بر ماهیت انتقال تنظیمات ممکن است credentialهای اتصال را در خود داشته باشد؛ داشبورد دربارهٔ محرمانه نگه‌داشتن فایل هشدار می‌دهد.
- R2 binding وجود ندارد؛ backup/restore تنها فایل JSON دانلودی است و به Billing یا payment method نیاز ندارد.

## تست و بازتولید ممیزی

```text
npm test
node scripts/build-parity-manifest.mjs
npm run parity:audit
npx wrangler deploy --dry-run
```

Regression suite موارد زیر را واقعاً اجرا می‌کند:

1. round-trip رمزگذاری/رمزگشایی vault در iteration=100000 و رد envelope=120000؛
2. diagnostics بدون افشای markerهای Secret؛
3. import ساختار سازگار PHP شامل محصولات و variationها؛
4. routeهای پیشنهاد selector و استخراج variation روی HTML آزمایشی؛
5. health، dashboard headers، API بدون token، نبود importهای Node در bundle، همگامی schema و auto-provision config.

آخرین نتیجه: **10/10 test موفق**، typecheck و build موفق، و parity audit با `ok: true` و **178/178 mapping**.

## فایل‌های راهنما

- راهنمای استقرار فقط از رابط وب Cloudflare: [`CLOUDFLARE-WORKER.md`](./CLOUDFLARE-WORKER.md)
- manifest کامل: [`parity-manifest.json`](./parity-manifest.json)
- audit نهایی: [`parity-audit.json`](./parity-audit.json)
- تست‌های regression: [`worker-tests/regression.test.mjs`](./worker-tests/regression.test.mjs)
