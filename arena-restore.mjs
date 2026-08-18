#!/usr/bin/env node
/**
 * arena-restore.mjs — بردنِ یک بستهٔ بکاپ به محیط تست آرنا
 * ==================================================================
 * v9.16
 *
 * چرا این هست: تست‌های این مخزن تا حالا با پروفایل‌های ساختگی اجرا
 * می‌شدند. هر باگی که فقط با دادهٔ واقعی شما بروز می‌کند — سلکتور خاص،
 * گالری با ساختار عجیب، پروفایلی که لینک ندارد — در آن تست‌ها دیده
 * نمی‌شد. با این ابزار همان دادهٔ واقعی (بدون رازها) وارد محیط تست
 * می‌شود.
 *
 * استفاده:
 *   node arena-restore.mjs <backup_YYYYmmdd_HHMMSS.json> [--keep-secrets]
 *
 * خروجی در arena-fixtures/ می‌نشیند و توسط هارنس‌های تست خوانده می‌شود.
 * به‌صورت پیش‌فرض همهٔ توکن‌ها و رمزها پاک می‌شوند، چون این پوشه ممکن
 * است به‌اشتباه کامیت شود. arena-fixtures/ در .gitignore هم هست.
 */
import fs from 'fs';
import path from 'path';

const args = process.argv.slice(2);
const src = args.find(a => !a.startsWith('--'));
const keepSecrets = args.includes('--keep-secrets');

if (!src) {
  console.error('استفاده: node arena-restore.mjs <backup_*.json> [--keep-secrets]');
  process.exit(1);
}
if (!fs.existsSync(src)) {
  console.error('فایل پیدا نشد: ' + src);
  process.exit(1);
}

const bundle = JSON.parse(fs.readFileSync(src, 'utf8'));
if (!bundle || !bundle.files) {
  console.error('این فایل یک بستهٔ بکاپ معتبر نیست.');
  process.exit(1);
}

const OUT = path.join(process.cwd(), 'arena-fixtures');
fs.mkdirSync(OUT, { recursive: true });

/** هر چیزی که بوی راز بدهد پاک می‌شود */
const SECRET_KEYS = /^(token|api_key|apikey|secret|password|pass|consumer_key|consumer_secret|webhook|refresh_token|access_token)$/i;
function scrub(v) {
  if (Array.isArray(v)) return v.map(scrub);
  if (v && typeof v === 'object') {
    const o = {};
    for (const [k, val] of Object.entries(v)) {
      o[k] = SECRET_KEYS.test(k) && typeof val === 'string' && val !== ''
        ? '__REMOVED__' : scrub(val);
    }
    return o;
  }
  return v;
}

let written = 0, profiles = 0;
const summary = [];
for (const [name, meta] of Object.entries(bundle.files)) {
  let text = Buffer.from(meta.b64 || '', 'base64').toString('utf8');
  if (!keepSecrets && name.endsWith('.json')) {
    try { text = JSON.stringify(scrub(JSON.parse(text)), null, 2); } catch (e) { /* not json, leave */ }
  }
  fs.writeFileSync(path.join(OUT, name), text);
  written++;
  if (name === 'profiles.json') {
    try {
      const p = JSON.parse(text);
      profiles = Object.keys(p).length;
      for (const [k, v] of Object.entries(p)) {
        const prods = Array.isArray(v.products) ? v.products.length : 0;
        const withGallery = Array.isArray(v.products)
          ? v.products.filter(e => Array.isArray(e?.[1]?.images) && e[1].images.length > 1).length : 0;
        const dsel = Object.keys(v.detailSelectors || {}).length;
        summary.push({
          key: k,
          name: v.name || k,
          url: v.url || '',
          products: prods,
          withGallery,
          galleryMode: (v.gallery || {}).mode || 'off',
          detailSelectors: dsel,
          detailSync: v.detailSync || null,
          syncEnabled: !!(v.syncConfig || {}).enabled,
        });
      }
    } catch (e) { /* ignore */ }
  }
}

fs.writeFileSync(path.join(OUT, '_summary.json'),
  JSON.stringify({ from: path.basename(src), created_at: bundle.created_at_h,
                   app_version: bundle.version, files: written, profiles, summary }, null, 2));

console.log('بستهٔ بکاپ وارد شد: ' + path.basename(src));
console.log('  ساخته‌شده در : ' + (bundle.created_at_h || '-') + '  (v' + (bundle.version || '?') + ')');
console.log('  فایل‌ها      : ' + written);
console.log('  پروفایل‌ها   : ' + profiles);
console.log('  رازها       : ' + (keepSecrets ? 'دست‌نخورده (مراقب باشید)' : 'پاک شد'));
if (summary.length) {
  console.log('\n  پروفایل‌ها:');
  for (const s of summary) {
    console.log('   • ' + s.name + '  محصول=' + s.products
      + '  گالری‌دار=' + s.withGallery
      + '  گالری=' + s.galleryMode
      + '  فیلد جزئیات=' + s.detailSelectors
      + '  استخراج دوره‌ای=' + (s.detailSync && s.detailSync.enabled ? 'روشن' : 'خاموش'));
  }
}
console.log('\nمسیر: ' + OUT);
console.log('حالا هارنس‌های تست می‌توانند از همین فایل‌ها استفاده کنند.');
