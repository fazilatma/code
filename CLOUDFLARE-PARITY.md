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
- بازنویسی extraction با یک parse از HTML، پشتیبانی selectorهای چندگانه، URLهای `data-*`/`onclick`، charsetهای قدیمی، redirect، anti-bot و سقف response. لینک خالی دیگر به‌اشتباه URL صفحهٔ فهرست نمی‌شود.
- تکمیل کارت ناقص DOM از JSON-LD (به‌جای fallback فقط در نبود همهٔ کارت‌ها) با تطبیق URL/SKU/عنوان یکتا؛ تصویر، توضیحات، وزن، SKU، برند، قیمت، موجودی، برچسب و variationها حفظ می‌شوند و `hasVariant` به محصول تکراری تبدیل نمی‌شود.
- استخراج fallback تنوع‌های JSON-LD شامل `hasVariant`، `color`، `size`، `material`، `pattern`، `additionalProperty` و قیمت هر گزینه.
- رعایت دقیق خاموش بودن گالری و `skip_first` حتی برای آرایهٔ تک‌عضوی؛ JSON-LD یا تصویر variation در حالت خاموش عکس اضافه نمی‌کند.
- استخراج گروه‌ها و گزینه‌های variation و نگهداری آن‌ها در CSV/JSON/D1؛ هنگام شکست جزئیات، دادهٔ معتبر قبلی از جمله `tags` حفظ می‌شود.
- همگام‌سازی WooCommerce به‌صورت variable parent + child variation.
- Basalam با fallback دسته‌بندی در سطح پروفایل/اتصال و ارسال چندغرفه‌ای.
- اتصال غیرمستقیم سایت مبدأ در حالت Cloudflare Worker gateway، هم برای صفحهٔ فهرست و هم جزئیات.
- endpoint فقط‌خواندنی `GET /api/debug` برای runtime، KDF، vault decrypt، bindingهای D1/Queue/DLQ، integrity/schema/relations دیتابیس و jobهای متوقف.
- داشبورد و تمام `/api/*` طبق تصمیم مالک بدون `ADMIN_TOKEN` یا Bearer قابل استفاده‌اند.
- drawer تنظیمات، ترتیب و عنوان هر ۱۸ بخش مرجع را حفظ می‌کند. شمار کنترل‌های مرجع به‌ترتیب بخش‌ها `3, 0, 28, 12, 10, 39, 27, 6, 9, 7, 12, 6, 5, 2, 4, 26, 5, 3` ممیزی شده است. کنترل‌های اضافه فقط adaptationهای بصری لازم‌اند: مدیریت چندغرفه‌ای باسلام، provider/model/candidate هوش مصنوعی، قواعد پاسخ آماده، import فایل و گزارش‌های مودالی؛ این موارد جای textarea خام JSON را گرفته‌اند.
- استعلام ووکامرس، باسلام و AI واقعاً endpoint مقصد را فراخوانی می‌کند و در موفقیت یا شکست مودال تشخیصی امن می‌دهد. تست پاسخ خودکار و نگهبان صف نیز مودال جامع دارند؛ وضعیت اعلان بر اساس نتیجهٔ واقعی گزارش می‌شود. جدول تست AI کلیک‌پذیر است و metadata و پاسخ خام redactشدهٔ هر اجرا را نشان می‌دهد.
- import/export پیشرفته می‌تواند فایل JSON بگیرد، اما مسیر عادی تنظیم غرفه، ارائه‌دهنده، مدل، کاندید و قاعدهٔ پاسخ کاملاً بصری است.
- تب مستقل «مدیریت جامع مقصد» مانند editor نسخهٔ PHP، کاتالوگ واقعی صفحه‌بندی‌شدهٔ WooCommerce و Basalam را با جست‌وجوی عنوان/شناسه، فیلتر وضعیت، شمارش وضعیت، انتخاب غرفه، جزئیات و ویرایش تکی، تغییر وضعیت، حذف/بایگانی، انتخاب حداکثر ۲۰ مورد و ویرایش گروهی preview/apply نمایش می‌دهد. قیمت Basalam در UI تومان و روی API ریال است؛ حذف Basalam به‌دلیل نبود endpoint حذف دائمی، دقیقاً به بایگانی وضعیت `4184` تبدیل می‌شود. عملیات گروهی Basalam ابتدا endpoint دسته‌ای غرفه و سپس fallback تکی را اجرا می‌کند.
- دکمهٔ «عیب‌یابی استخراج» هر پروفایل بدون write یا sync، همان pipeline واقعی را روی شبکه، parser فهرست، evidence سلکتورها و یک صفحهٔ جزئیات اجرا و هر مرحله را با نمونه و راهکار در modal نشان می‌دهد؛ بنابراین موفقیت fixture جای شکست واقعی سایت را پنهان نمی‌کند.
- برای Cloudflare Workers AI، URL بومی `/accounts/{account}/ai/run/` دیگر هرگز با `/chat/completions` ترکیب نمی‌شود. ابتدا payloadهای native `prompt` و `messages` روی شناسهٔ مدل واردشده و مسیر سازمان‌دار احتمالی امتحان می‌شوند؛ فقط پس از شکست این مسیرها، endpoint رسمی `/ai/v1/chat/completions` fallback است. گزارش تلاش‌ها endpoint/body/status را بدون Secret نگه می‌دارد و خطای `No route for that URI` قابل تشخیص است.
- بخش «گزارش تغییرات کد» داخل drawer، تغییرات مدیریت مقصد، AI، عیب‌یاب extraction و نوسازی UI را با تاریخ ثبت می‌کند.

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

## معماری و حدود سخت‌افزاری/اجرایی Worker

Cloudflare برای Worker یک سرور ثابت با مدل CPU، تعداد vCPU یا مقدار RAM اختصاصی اعلام نمی‌کند. کد در **V8 isolate**های چندمستأجر روی شبکهٔ جهانی اجرا و خودکار scale می‌شود؛ بنابراین بیان درستِ «مشخصات سخت‌افزاری»، حدود تضمین‌شدهٔ runtime است، نه نام پردازنده یا یک ماشین مجازی ثابت.

طبق [صفحهٔ رسمی Limits](https://developers.cloudflare.com/workers/platform/limits/) با تاریخ به‌روزرسانی 2026-07-28:

| محدودیت | Free | Paid |
|---|---:|---:|
| حافظهٔ هر isolate (مشترک میان invocationهای همزمان همان isolate) | 128 MB | 128 MB |
| CPU هر HTTP request | 10 ms | پیش‌فرض 30 ثانیه، قابل افزایش تا 5 دقیقه |
| درخواست روزانه | 100,000 | بدون سقف روزانهٔ عمومی |
| subrequest خروجی در هر invocation | 50 | 10,000 به‌صورت پیش‌فرض؛ قابل تنظیم تا سقف اعلام‌شدهٔ plan |
| اتصال خروجی همزمان در انتظار header | 6 | 6 |
| اندازهٔ bundle فشرده | 3 MB | 10 MB |
| اندازهٔ bundle پیش از فشرده‌سازی | 64 MB | 64 MB |
| startup | 1 ثانیه | 1 ثانیه |
| مدت wall-time درخواست HTTP | hard limit ندارد؛ به بازبودن client وابسته است | همان |
| Queue consumer / Cron / Alarm | حداکثر 15 دقیقه wall-time؛ CPU طبق نوع trigger/plan | حداکثر 15 دقیقه wall-time |
| ادامهٔ کار `waitUntil` بعد از response/disconnect | تا 30 ثانیه | تا 30 ثانیه |
| body ورودی بر اساس Cloudflare account plan | 100 MB در Free/Pro، 200 MB Business، 500 MB Enterprise پیش‌فرض | وابسته به account plan |
| body پاسخ Worker | سقف اجباری عمومی ندارد | سقف اجباری عمومی ندارد |

هر redirect نیز subrequest محسوب می‌شود. انتظار شبکه CPU time نیست، اما parse، رمزنگاری، sanitize و ساخت JSON از CPU و حافظه مصرف می‌کنند. به همین علت این پروژه پاسخ مبدأ را محدود، پردازش را checkpoint و هر پیام صف را روی ۱۰ محصول نگه می‌دارد؛ concurrency جزئیات نیز ۲ است تا از ۶ connection و ۵۰ subrequest پلن Free عبور نکند. سقف داخلی body/response پروژه ممکن است عمداً از سقف Cloudflare کمتر باشد.

خروجی dry-run فعلی bundle: **771.48 KiB upload / 179.85 KiB gzip**؛ بسیار پایین‌تر از سقف 3 MB پلن Free.

## تست و بازتولید ممیزی

```text
npm test
node scripts/build-parity-manifest.mjs
npm run parity:audit
npx wrangler deploy --dry-run
```

مجموعهٔ regression و extraction موارد زیر را واقعاً اجرا می‌کند:

1. round-trip رمزگذاری/رمزگشایی vault در iteration=100000 و رد envelope=120000؛
2. diagnostics بدون افشای markerهای Secret و metadata/raw امن تست AI؛ شکست شبکهٔ AI، حذف credential در redirect بین originها، timeout تا پایان body و توقف response بیش‌ازحد نیز regression دارند؛
3. import ساختار سازگار PHP شامل محصولات و variationها، و درون‌ریزی واقعی CSV/XLSX با سرستون فارسی، وضعیت ووکامرس، سقف ۱۰ MiB و پاسخ JSON خطا؛
4. routeهای پیشنهاد selector، استخراج مستقیم list/detail از fixture فارسی و fallbackهای JSON-LD/variation/gallery؛
5. merge و dedupe کارت، checkpoint، حفظ دادهٔ معتبر قبلی و جلوگیری از retire شدن ناامن هنگام شکست ذخیره/parse؛
6. dispatch بومی Cloudflare Workers AI، payloadهای prompt/messages، مدل‌های ساده و سازمان‌دار، chat fallback و redaction؛
7. API جامع مقصد شامل catalog/search/page/shop/status، preview/apply تکی و گروهی، تبدیل تومان/ریال و archive وضعیت 4184 باسلام؛
8. diagnostic پروفایل روی network/list/selectors/detail بدون write و اتصال دکمه و modal آن؛
9. هجده بخش و ترتیب dashboard، editorهای بصری، چهار pane بازطراحی‌شده، محیط مستقل مدیریت مقصد، گزارش تغییرات کد، modalهای جامع و listenerهای واقعی؛
10. health، dashboard headers، API بدون token، نبود importهای Node در bundle، همگامی schema و auto-provision config.

آخرین نتیجه: **29/29 test موفق**، typecheck و build موفق؛ runtime مستقل LinkeDOM نیز راه‌اندازی، bootstrap API، شش تب و کنترل‌های paneهای بازطراحی‌شده را تأیید کرد. parity audit نیز `ok: true` و **178/178 mapping** بدون missing/extra، warning یا error است. smoke کامل `ok: true` و deploy dry-run نیز موفق است.

## فایل‌های راهنما

- راهنمای استقرار فقط از رابط وب Cloudflare: [`CLOUDFLARE-WORKER.md`](./CLOUDFLARE-WORKER.md)
- manifest کامل: [`parity-manifest.json`](./parity-manifest.json)
- audit نهایی: [`parity-audit.json`](./parity-audit.json)
- تست‌های regression: [`worker-tests/regression.test.mjs`](./worker-tests/regression.test.mjs)
- تست‌های extraction، gallery، JSON-LD و UI: [`worker-tests/extraction.test.mjs`](./worker-tests/extraction.test.mjs)
- fixtureهای فارسی: [`worker-tests/fixtures/`](./worker-tests/fixtures/)
