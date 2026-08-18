import { serve } from '@hono/node-server';
import { timingSafeEqual } from 'node:crypto';
import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { secureHeaders } from 'hono/secure-headers';
import { config, assertConfig } from './config.js';
import { DASHBOARD, DASHBOARD_JS, setupPage } from './dashboard.js';
import { createBackup, createJob, deleteProfile, enqueueDueProfiles, getJob, getProfile, getState, listJobs, listProducts, listProfiles, migrate, pool, profileStats, reapStalledJobs, restoreBackup, saveProfile, setState, updateJob } from './db.js';
import { DEFAULT_SELECTORS, type Profile } from './types.js';
import { safeFetch, safeText } from './network.js';
import { testSelector } from './scraper.js';
import { createVisualTicket, renderVisualSelector } from './visual.js';
import { workerLoop, requestWorkerStop } from './processor.js';

let databaseReady = false;
let databaseError = '';
async function initializeDatabase(): Promise<boolean> {
  try {
    assertConfig();
    await migrate();
    await pool.query('SELECT 1');
    databaseReady = true; databaseError = '';
    console.log('PostgreSQL connected and schema is ready');
    return true;
  } catch (error) {
    databaseReady = false;
    databaseError = error instanceof Error ? error.message : String(error);
    console.error(`DATABASE NOT READY: ${databaseError}`);
    return false;
  }
}
await initializeDatabase();

const app = new Hono();
const dashboardHeaders = secureHeaders({
  contentSecurityPolicy: {
    defaultSrc: ["'self'"], scriptSrc: ["'self'"], styleSrc: ["'self'", "'unsafe-inline'"],
    connectSrc: ["'self'"], imgSrc: ["'self'", 'data:', 'https:'], objectSrc: ["'none'"], frameAncestors: ["'none'"]
  }
});
app.use('*', async (c, next) => c.req.path === '/visual' ? next() : dashboardHeaders(c, next));
app.use('/api/*', cors({ origin: origin => origin, allowHeaders: ['authorization','content-type'], allowMethods: ['GET','POST','PUT','DELETE'] }));
app.onError((error, c) => { console.error(error); return c.json({ ok: false, error: error.message }, 500); });
app.get('/health', c => c.json({
  ok: true,
  app: 'scraper4-render',
  runtime: process.version,
  databaseReady,
  databaseError: databaseReady ? null : databaseError,
  workerInWeb: config.runWorkerInWeb,
  time: new Date().toISOString()
}));
app.get('/', c => c.html(databaseReady ? DASHBOARD : setupPage(databaseError)));
app.get('/dashboard.js', c => c.body(DASHBOARD_JS, 200, { 'content-type': 'application/javascript; charset=utf-8', 'cache-control': 'no-store' }));
app.get('/visual', async c => {
  try {
    const content = await renderVisualSelector(c.req.query('ticket') || '');
    return c.html(content, 200, {
      'cache-control': 'no-store',
      'content-security-policy': "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline' https: http:; font-src https: http: data:; script-src 'unsafe-inline'; frame-ancestors 'self';",
      'referrer-policy': 'no-referrer'
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return c.html(`<html dir="rtl"><body style="background:#0f172a;color:#fca5a5;font-family:Tahoma;padding:30px"><h2>خطای انتخاب‌گر بصری</h2><p>${message.replace(/[&<>]/g, '')}</p></body></html>`, 400);
  }
});

app.use('/api/*', async (c, next) => {
  if (!databaseReady) return c.json({ ok: false, error: 'Database is not configured', detail: databaseError, setup: 'Create Render PostgreSQL and set DATABASE_URL to its Internal Database URL.' }, 503);
  if (!config.adminToken) return next();
  const auth = c.req.header('authorization') || '';
  if (!safeEqual(auth.replace(/^Bearer\s+/i, ''), config.adminToken)) return c.json({ ok: false, error: 'Unauthorized' }, 401);
  await next();
});

app.post('/api/visual-ticket', async c => {
  const body = await c.req.json() as { url?: string };
  const url = new URL(String(body.url || ''));
  if (!['http:', 'https:'].includes(url.protocol)) return c.json({ ok: false, error: 'Invalid visual selector URL' }, 400);
  return c.json({ ok: true, ticket: createVisualTicket(url.href), expiresIn: 300 });
});
app.get('/api/status', async c => c.json({ ok: true, profiles: (await listProfiles()).length, jobs: await listJobs(10), connections: {
  woo: Boolean(config.woo.url && config.woo.key && config.woo.secret), basalam: Boolean(config.basalam.token && config.basalam.vendorId)
} }));
app.get('/api/settings', async c => c.json({ ok:true, settings: await getState('settings', {}) }));
app.post('/api/settings', async c => { const settings=await c.req.json(); await setState('settings',settings); return c.json({ok:true}); });
app.get('/api/backup', async c => c.json(await createBackup(), 200, { 'content-disposition': `attachment; filename="scraper4-render-${Date.now()}.json"` }));
app.post('/api/restore', async c => c.json({ok:true,result:await restoreBackup(await c.req.json())}));
app.get('/api/profile-stats', async c => c.json({ok:true,items:await profileStats()}));
app.post('/api/queue-watchdog', async c => { const body=await c.req.json().catch(()=>({})) as any; return c.json({ok:true,reaped:await reapStalledJobs(Number(body.minutes)||30)}); });
app.post('/api/source-test', async c => { const body=await c.req.json() as any; const result=await safeText(String(body.url||''),1_000_000); return c.json({ok:true,bytes:Buffer.byteLength(result.text),url:result.url,title:(result.text.match(/<title[^>]*>(.*?)<\/title>/is)?.[1]||'').replace(/<[^>]+>/g,'').trim()}); });
app.post('/api/test-connection/:target', async c => {
  const target=c.req.param('target');
  if(target==='woo') { if(!config.woo.url||!config.woo.key||!config.woo.secret) return c.json({ok:false,error:'WooCommerce environment variables are incomplete'},400); const auth=`Basic ${Buffer.from(`${config.woo.key}:${config.woo.secret}`).toString('base64')}`; const r=await safeFetch(config.woo.url.replace(/\/$/,'')+'/wp-json/wc/v3/system_status',{headers:{authorization:auth,accept:'application/json'}},2_000_000); return c.json({ok:r.ok,code:r.status}); }
  if(target==='basalam') { if(!config.basalam.token) return c.json({ok:false,error:'BASALAM_TOKEN is empty'},400); const r=await safeFetch(config.basalam.api+'/categories',{headers:{authorization:`Bearer ${config.basalam.token}`,accept:'application/json'}},2_000_000); return c.json({ok:r.ok,code:r.status}); }
  if(target==='ai') { const s=await getState<any>('settings',{}),ai=s.ai||{}; if(!ai.baseUrl||!ai.apiKey||!ai.model) return c.json({ok:false,error:'AI settings are incomplete'},400); const endpoint=String(ai.baseUrl).replace(/\/$/,'')+(String(ai.baseUrl).includes('/chat/completions')?'':'/chat/completions'); const r=await safeFetch(endpoint,{method:'POST',headers:{authorization:`Bearer ${ai.apiKey}`,'content-type':'application/json'},body:JSON.stringify({model:ai.model,messages:[{role:'user',content:'سلام'}],max_tokens:20})},2_000_000); return c.json({ok:r.ok,code:r.status,body:await r.json().catch(()=>null)}); }
  return c.json({ok:false,error:'Unknown connection'},404);
});
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
let backgroundStarted = false;
function startBackground(): void {
  if (!config.runWorkerInWeb || !databaseReady || backgroundStarted) return;
  backgroundStarted = true;
  void workerLoop(config.workerPollMs);
  const schedule = async () => { try { const count = await enqueueDueProfiles(); if (count) console.log(`Scheduled ${count} profile(s)`); } catch (error) { console.error('Scheduler error', error); } };
  void schedule(); scheduler = setInterval(schedule, 60_000); scheduler.unref();
}
startBackground();
const databaseRetry = setInterval(async () => { if (!databaseReady && config.databaseUrl && await initializeDatabase()) startBackground(); }, 30_000);
databaseRetry.unref();
const shutdown = async () => { requestWorkerStop(); clearInterval(databaseRetry); if (scheduler) clearInterval(scheduler); server.close(); await pool.end(); process.exit(0); };
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
