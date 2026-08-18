import { serve } from '@hono/node-server';
import { timingSafeEqual } from 'node:crypto';
import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { secureHeaders } from 'hono/secure-headers';
import { config, assertConfig } from './config.js';
import { createJob, deleteProfile, enqueueDueProfiles, getJob, getProfile, listJobs, listProducts, listProfiles, migrate, pool, saveProfile, updateJob } from './db.js';
import { DEFAULT_SELECTORS, type Profile } from './types.js';
import { testSelector } from './scraper.js';
import { workerLoop, requestWorkerStop } from './processor.js';

assertConfig();
await migrate();

const app = new Hono();
app.use('*', secureHeaders());
app.use('/api/*', cors({ origin: origin => origin, allowHeaders: ['authorization','content-type'], allowMethods: ['GET','POST','PUT','DELETE'] }));
app.onError((error, c) => { console.error(error); return c.json({ ok: false, error: error.message }, 500); });
app.get('/health', async c => {
  await pool.query('SELECT 1');
  return c.json({ ok: true, app: 'scraper4-render', runtime: process.version, workerInWeb: config.runWorkerInWeb, time: new Date().toISOString() });
});
app.get('/', c => c.html(DASHBOARD));

app.use('/api/*', async (c, next) => {
  if (!config.adminToken) return next();
  const auth = c.req.header('authorization') || '';
  if (!safeEqual(auth.replace(/^Bearer\s+/i, ''), config.adminToken)) return c.json({ ok: false, error: 'Unauthorized' }, 401);
  await next();
});

app.get('/api/status', async c => c.json({ ok: true, profiles: (await listProfiles()).length, jobs: await listJobs(10), connections: {
  woo: Boolean(config.woo.url && config.woo.key && config.woo.secret), basalam: Boolean(config.basalam.token && config.basalam.vendorId)
} }));
app.get('/api/profiles', async c => c.json({ ok: true, profiles: await listProfiles() }));
app.post('/api/profiles', async c => {
  const profile = normalizeProfile(await c.req.json()); return c.json({ ok: true, profile: await saveProfile(profile) });
});
app.delete('/api/profiles/:id', async c => c.json({ ok: await deleteProfile(c.req.param('id')) }));
app.post('/api/profiles/:id/scrape', async c => {
  const profile = await getProfile(c.req.param('id')); if (!profile) return c.json({ ok: false, error: 'Profile not found' }, 404);
  const body = await c.req.json().catch(() => ({})) as any; const target = validTarget(body.target || 'none');
  return c.json({ ok: true, job: await createJob(profile.id, 'scrape', target) }, 202);
});
app.post('/api/profiles/:id/sync', async c => {
  const profile = await getProfile(c.req.param('id')); if (!profile) return c.json({ ok: false, error: 'Profile not found' }, 404);
  const body = await c.req.json().catch(() => ({})) as any;
  return c.json({ ok: true, job: await createJob(profile.id, 'sync', validTarget(body.target || 'both')) }, 202);
});
app.get('/api/jobs', async c => c.json({ ok: true, jobs: await listJobs(Math.min(200, Number(c.req.query('limit')) || 50)) }));
app.get('/api/jobs/:id', async c => { const job = await getJob(c.req.param('id')); return job ? c.json({ ok: true, job }) : c.json({ ok: false, error: 'Job not found' }, 404); });
app.post('/api/jobs/:id/stop', async c => { await updateJob(c.req.param('id'), { stopRequested: true }); return c.json({ ok: true }); });
app.get('/api/profiles/:id/products', async c => {
  const limit = Math.min(500, Number(c.req.query('limit')) || 100), offset = Math.max(0, Number(c.req.query('offset')) || 0);
  return c.json({ ok: true, ...await listProducts(c.req.param('id'), limit, offset, c.req.query('q') || '') });
});
app.post('/api/test-selector', async c => {
  const body = await c.req.json() as any; return c.json({ ok: true, ...await testSelector(String(body.url || ''), String(body.selector || ''), String(body.type || 'text')) });
});
app.post('/api/import-php', async c => {
  const body = await c.req.json() as any; const source = typeof body.profiles === 'string' ? JSON.parse(body.profiles) : body.profiles;
  const imported: Profile[] = [];
  for (const [id, value] of Object.entries(source || {})) imported.push(await saveProfile(normalizeProfile({ ...(value as any), id })));
  return c.json({ ok: true, imported: imported.length, profiles: imported });
});

const server = serve({ fetch: app.fetch, port: config.port, hostname: config.host }, info => console.log(`Scraper4 Render listening on http://${info.address}:${info.port}`));
let scheduler: NodeJS.Timeout | undefined;
if (config.runWorkerInWeb) {
  void workerLoop(config.workerPollMs);
  const schedule = async () => { try { const count = await enqueueDueProfiles(); if (count) console.log(`Scheduled ${count} profile(s)`); } catch (error) { console.error('Scheduler error', error); } };
  void schedule(); scheduler = setInterval(schedule, 60_000); scheduler.unref();
}
const shutdown = async () => { requestWorkerStop(); if (scheduler) clearInterval(scheduler); server.close(); await pool.end(); process.exit(0); };
process.on('SIGTERM', shutdown); process.on('SIGINT', shutdown);

function validTarget(value: string): 'none'|'woo'|'basalam'|'both' { return ['none','woo','basalam','both'].includes(value) ? value as any : 'none'; }
function safeEqual(a: string, b: string): boolean { const aa=Buffer.from(a),bb=Buffer.from(b); return aa.length===bb.length && timingSafeEqual(aa,bb); }
function idFromUrl(raw: string): string { const url = new URL(raw); return `${url.hostname}_${url.pathname}`.toLowerCase().replace(/[^a-z0-9_.-]+/g,'_').replace(/^_+|_+$/g,'').slice(0,120); }
function normalizeProfile(raw: any): Profile {
  const url = new URL(String(raw.url || '')); if (!['http:','https:'].includes(url.protocol)) throw new Error('Invalid profile URL');
  const now = new Date().toISOString(); const selectors = { ...DEFAULT_SELECTORS, ...(typeof raw.selectors === 'string' ? JSON.parse(raw.selectors) : raw.selectors || {}) };
  for (const key of ['container','title','price','link','image']) if (!selectors[key]) throw new Error(`selectors.${key} is required`);
  return { id: String(raw.id || idFromUrl(url.href)), name: String(raw.name || url.hostname), url: url.href, enabled: raw.enabled !== false,
    pages: Math.min(100,Math.max(1,Number(raw.pages)||1)), pagination: ['query_page','path_page','none'].includes(raw.pagination || raw.pagType) ? raw.pagination || raw.pagType : 'query_page',
    paginationValue: String(raw.paginationValue || raw.pagVal || 'page'), selectors, titleSuffix: String(raw.titleSuffix || ''),
    priceMode: ['none','add','percent','multiply'].includes(raw.priceMode) ? raw.priceMode : 'none', priceValue: Number(raw.priceValue ?? raw.priceVal) || 0,
    roundPrice: Math.max(0,Number(raw.roundPrice)||0), minPrice: Math.max(0,Number(raw.minPrice)||0), wooCategoryId: Number(raw.wooCategoryId)||0,
    basalamCategoryId: Number(raw.basalamCategoryId ?? raw.bslCategoryId)||0, syncWoo: Boolean(raw.syncWoo), syncBasalam: Boolean(raw.syncBasalam),
    intervalMinutes: Math.max(0,Number(raw.intervalMinutes)||0), lastRunAt: raw.lastRunAt || null, createdAt: raw.createdAt || now, updatedAt: now };
}

const DASHBOARD = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scraper 4 Render</title><style>
:root{color-scheme:dark;--bg:#07111e;--card:#101e31;--line:#29415f;--muted:#91a7c1;--blue:#38bdf8;--green:#34d399;--red:#fb7185}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 20% 0,#123757,var(--bg) 38%);font:14px Tahoma;color:#e8eef8}main{max-width:1100px;margin:auto;padding:22px}.top,.row{display:flex;align-items:center;justify-content:space-between;gap:10px}h1 b{color:var(--blue)}.card{background:#101e31ee;border:1px solid var(--line);border-radius:14px;padding:17px;margin-top:14px}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.five{grid-template-columns:2fr repeat(4,1fr)}label{display:block;color:var(--muted);font-size:12px;margin-bottom:4px}input,select,button{width:100%;padding:9px;border-radius:8px;border:1px solid #34506e;background:#081522;color:#e8eef8;font:inherit}button{cursor:pointer;background:#075985;border-color:#0ea5e9;font-weight:bold}.green{background:#065f46}.red{background:#881337}.actions{display:flex;gap:6px}.actions button{width:auto}.item{padding:11px;border:1px solid #263e58;background:#081522;border-radius:9px;margin-top:8px}.item small{display:block;color:var(--muted);direction:ltr}.log{white-space:pre-wrap;background:#040b13;padding:12px;border-radius:9px;min-height:80px;max-height:400px;overflow:auto}@media(max-width:720px){.grid,.five{grid-template-columns:1fr}.top,.row{align-items:stretch;flex-direction:column}.actions{flex-wrap:wrap}}</style></head><body><main><div class="top"><h1>اسکرپر <b>۴</b> روی Render</h1><span>Node.js · Hono · PostgreSQL · Cheerio</span></div><div class="card grid"><div><label>ADMIN_TOKEN</label><input id="token" type="password"></div><button onclick="load()">اتصال</button></div><div class="card"><h2>پروفایل جدید</h2><div class="grid"><div><label>نام</label><input id="name"></div><div><label>URL</label><input id="url" dir="ltr"></div></div><div class="grid five" style="margin-top:10px"><div><label>محصول</label><input id="container" value="li.product" dir="ltr"></div><div><label>عنوان</label><input id="title" value="h2" dir="ltr"></div><div><label>قیمت</label><input id="price" value=".price" dir="ltr"></div><div><label>لینک</label><input id="link" value="a[href]" dir="ltr"></div><div><label>تصویر</label><input id="image" value="img" dir="ltr"></div></div><div class="grid" style="margin-top:10px"><div><label>تعداد صفحات</label><input id="pages" type="number" value="1"></div><div><label>اجرای دوره‌ای، دقیقه (۰=خاموش)</label><input id="interval" type="number" value="0"></div></div><button class="green" onclick="saveProfile()" style="margin-top:10px">ذخیره</button></div><div class="card"><h2>پروفایل‌ها</h2><div id="profiles"></div></div><div class="card"><h2>وضعیت</h2><div id="out" class="log">آماده</div></div></main><script>
const $=id=>document.getElementById(id),H=()=>({'content-type':'application/json',authorization:'Bearer '+$('token').value});async function api(p,o={}){const r=await fetch(p,{...o,headers:{...H(),...(o.headers||{})}}),d=await r.json();if(!r.ok)throw Error(d.error||r.status);return d}function out(v){$('out').textContent=JSON.stringify(v,null,2)}async function load(){localStorage.s4rt=$('token').value;try{const d=await api('/api/profiles');$('profiles').innerHTML=d.profiles.map(p=>'<div class="item row"><div><b>'+esc(p.name)+'</b><small>'+esc(p.url)+'</small></div><div class="actions"><button onclick="run(\''+p.id+'\')">استخراج</button><button class="green" onclick="sync(\''+p.id+'\')">ارسال</button><button class="red" onclick="delp(\''+p.id+'\')">حذف</button></div></div>').join('')||'پروفایلی نیست';out(await api('/api/status'))}catch(e){out({error:e.message})}}async function saveProfile(){try{await api('/api/profiles',{method:'POST',body:JSON.stringify({name:$('name').value,url:$('url').value,pages:+$('pages').value,intervalMinutes:+$('interval').value,selectors:{container:$('container').value,title:$('title').value,price:$('price').value,link:$('link').value,image:$('image').value}})});load()}catch(e){out({error:e.message})}}async function run(id){try{const d=await api('/api/profiles/'+id+'/scrape',{method:'POST',body:'{}'});out(d);watch(d.job.id)}catch(e){out({error:e.message})}}async function sync(id){try{const d=await api('/api/profiles/'+id+'/sync',{method:'POST',body:JSON.stringify({target:'both'})});out(d);watch(d.job.id)}catch(e){out({error:e.message})}}function watch(id){const t=setInterval(async()=>{try{const d=await api('/api/jobs/'+id);out(d.job);if(!['queued','running'].includes(d.job.status))clearInterval(t)}catch(e){clearInterval(t)}},1500)}async function delp(id){if(confirm('حذف شود؟')){await api('/api/profiles/'+id,{method:'DELETE'});load()}}function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}$('token').value=localStorage.s4rt||'';load();
</script></body></html>`;
