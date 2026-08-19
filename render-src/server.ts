import { serve } from '@hono/node-server';
import { timingSafeEqual } from 'node:crypto';
import { Hono } from 'hono';
import { secureHeaders } from 'hono/secure-headers';
import { streamSSE } from 'hono/streaming';
import { aiCall, aiProviders, aiTestState, candidateTest, getLeaderboard, networkDiagnostics, recordVote, startAiTest, stopAiTest, testAllModels } from './ai.js';
import { automationTick, autoreplyLogs, autoreplyRun, basalamChats, basalamOrders, digest, generateReply } from './automation.js';
import { config, assertConfig } from './config.js';
import { connectionStatus, loadConnections, saveConnections } from './connections.js';
import { DASHBOARD, DASHBOARD_JS, setupPage } from './dashboard.js';
import { createBackup, createJob, deleteProfile, enqueueDueProfiles, findLearnedCategory, getJob, getProduct, getProfile, getState, importAutoreplyLog, importCategoryLearning, learnCategory, listCategoryLearning, listJobs, listProducts, listProfiles, migrate, pool, profileStats, reapStalledJobs, restoreBackup, retryJob, deleteJob, clearFinishedJobs, saveProfile, setState, updateJob, upsertProduct } from './db.js';
import { DEFAULT_SELECTORS, type Product, type Profile } from './types.js';
import { safeFetch, safeText } from './network.js';
import { sendNotification } from './notifications.js';
import { PHP_MENU_CAPABILITIES, runSelftest } from './parity.js';
import { bulkEdit, deduplicateWoo, destinationChangeStatus, destinationDelete, destinationOverview, findDestinationDuplicates, fixBasalamCategory, listDestinationProducts, photoFix, rebuildMap, recon, rejectedBasalam, retire, suffixReport } from './maintenance.js';
import { numberFromText, selectorWorkbench, suggestGallery, suggestListSelectors, testDetailSelectors, testSelector, validateSelectorConfig } from './scraper.js';
import { syncBasalam, syncWoo } from './sync.js';
import { createPhpSettingsBundle, decodePhpSettingsBundle, stateKeyForFile } from './settings-transfer.js';
import { productsToXlsx, xlsxToRows } from './spreadsheet.js';
import { createVisualTicket, renderVisualSelector } from './visual.js';
import { APP_BUILD_DATE, APP_RELEASE, APP_VERSION } from './version.js';
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
app.onError((error, c) => { console.error(error); return c.json({ ok: false, error: error.message }, 500); });
app.get('/health', c => c.json({
  ok: true,
  app: 'scraper4-render',
  version: APP_VERSION,
  buildDate: APP_BUILD_DATE,
  release: APP_RELEASE,
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
app.use('/scraper4.php',async(c,next)=>{if(!databaseReady)return c.json({ok:false,error:'Database is not configured'},503);if(!config.adminToken)return next();const auth=(c.req.header('authorization')||'').replace(/^Bearer\s+/i,''),queryToken=c.req.query('api_token')||'';if(!safeEqual(auth||queryToken,config.adminToken))return c.json({ok:false,error:'Unauthorized'},401);await next()});

app.post('/api/visual-ticket', async c => {
  const body = await c.req.json() as { url?: string };
  const url = new URL(String(body.url || ''));
  if (!['http:', 'https:'].includes(url.protocol)) return c.json({ ok: false, error: 'Invalid visual selector URL' }, 400);
  return c.json({ ok: true, ticket: createVisualTicket(url.href), expiresIn: 300 });
});
app.get('/api/version',c=>c.json({ok:true,version:APP_VERSION,buildDate:APP_BUILD_DATE,release:APP_RELEASE}));
app.get('/api/status', async c => { const connections=await loadConnections(); return c.json({ ok:true,version:APP_VERSION,buildDate:APP_BUILD_DATE,profiles:(await listProfiles()).length,jobs:await listJobs(10),connections:connectionStatus(connections) }); });
app.get('/api/selftest',async c=>c.json(await runSelftest()));
app.get('/api/parity',c=>c.json({ok:true,total:PHP_MENU_CAPABILITIES.length,capabilities:PHP_MENU_CAPABILITIES}));
app.get('/api/connections', async c => c.json({ok:true,connections:await loadConnections(true)}));
app.post('/api/connections', async c => c.json({ok:true,connections:await saveConnections(await c.req.json())}));
app.get('/api/ai/providers',async c=>c.json({ok:true,providers:await aiProviders(),leaderboard:await getLeaderboard()}));
app.post('/api/ai/test-all',async c=>{const body=await c.req.json().catch(()=>({})) as any;return c.json({ok:true,results:await testAllModels(String(body.prompt||'سلام'),Boolean(body.onlyCandidates))})});
app.post('/api/ai/call',async c=>{const body=await c.req.json() as any,providers=await aiProviders(),provider=providers.find(p=>p.id===body.provider);if(!provider)return c.json({ok:false,error:'Provider not found'},404);return c.json(await aiCall(provider,String(body.model||''),String(body.prompt||'سلام')))});
app.post('/api/ai/vote',async c=>{const body=await c.req.json() as any;return c.json({ok:true,leaderboard:await recordVote(String(body.task||'manual'),String(body.winner||''),Array.isArray(body.candidates)?body.candidates.map(String):[])})});
app.get('/api/ai/leaderboard',async c=>c.json({ok:true,leaderboard:await getLeaderboard()}));
app.post('/api/ai/test-start',async c=>c.json({ok:true,state:await startAiTest(await c.req.json().catch(()=>({})))}));
app.get('/api/ai/test-status',async c=>c.json({ok:true,state:await aiTestState()}));
app.post('/api/ai/test-stop',async c=>c.json(await stopAiTest()));
app.post('/api/ai/candidates/:task',async c=>{const task=c.req.param('task');if(!['category','autoreply'].includes(task))return c.json({ok:false,error:'Invalid task'},400);const body=await c.req.json() as any;return c.json({ok:true,...await candidateTest(task as any,String(body.input||''))})});
app.get('/api/ai/network-diagnostics',async c=>c.json(await networkDiagnostics()));
app.post('/api/notifications/test',async c=>{const body=await c.req.json() as any;return c.json(await sendNotification(body.channel||'webhook',String(body.text||'پیام آزمایشی اسکرپر ۴')))});
app.get('/api/category-learning',async c=>c.json({ok:true,items:await listCategoryLearning(Math.min(5000,Number(c.req.query('limit'))||1000))}));
app.post('/api/category-learning/record',async c=>{const b=await c.req.json() as any;return c.json({ok:true,saved:await learnCategory(String(b.title||''),Number(b.categoryId),String(b.categoryName||''),Number(b.maxWords)||5)})});
app.post('/api/category-learning/test',async c=>{const b=await c.req.json() as any;return c.json({ok:true,result:await findLearnedCategory(String(b.title||''),Number(b.maxWords)||5)})});
app.post('/api/autoreply/test',async c=>{const b=await c.req.json() as any;return c.json({ok:true,result:await generateReply(String(b.text||''))})});
app.post('/api/autoreply/run',async c=>{const b=await c.req.json().catch(()=>({})) as any;return c.json(await autoreplyRun(b.confirm!=='APPLY'))});
app.get('/api/autoreply/log',async c=>c.json({ok:true,items:await autoreplyLogs()}));
app.post('/api/digest',async c=>{const b=await c.req.json().catch(()=>({})) as any;return c.json(await digest(b.confirm!=='SEND'))});
app.get('/api/basalam/chats',async c=>c.json({ok:true,items:await basalamChats(Number(c.req.query('limit'))||20)}));
app.get('/api/basalam/orders',async c=>c.json({ok:true,items:await basalamOrders(Number(c.req.query('limit'))||20)}));
app.get('/api/settings', async c => c.json({ ok:true, settings: await getState('settings', {}) }));
app.post('/api/settings', async c => { const settings=await c.req.json(); await setState('settings',settings); return c.json({ok:true}); });
app.get('/api/backup', async c => c.json(await createBackup(), 200, { 'content-disposition': `attachment; filename="scraper4-render-${Date.now()}.json"` }));
app.post('/api/restore', async c => c.json({ok:true,result:await restoreBackup(await c.req.json())}));
app.get('/api/settings-export', async c => {
  const bundle=await createPhpSettingsBundle(new URL(c.req.url).host),stamp=new Date().toISOString().replace(/[-:T]/g,'').slice(0,15);
  return c.json(bundle,200,{'content-disposition':`attachment; filename="settings_${stamp}.json"`});
});
app.post('/api/settings-import', async c => {
  const files=decodePhpSettingsBundle(await c.req.json());let profiles=0,products=0,states=0,categories=0,autoreplyLogs=0,connections=false;const warnings:string[]=[];
  const rawProfiles=files['profiles.json'];
  if(rawProfiles&&typeof rawProfiles==='object')for(const [id,raw] of Object.entries(rawProfiles as Record<string,any>)){
    try{const profile=normalizeProfile({...raw,id});await saveProfile(profile);profiles++;for(const product of legacyProducts(raw?.products)){await upsertProduct(profile.id,product);products++;}}
    catch(error){warnings.push(`${id}: ${error instanceof Error?error.message:String(error)}`)}
  }
  const rawConnections=files['connections.json'] as any;
  if(rawConnections){const woo=rawConnections.woocommerce||rawConnections.woo||{},basalam=rawConnections.basalam||{},ai=rawConnections.ai||{};await saveConnections({woo:{url:woo.url||woo.store_url||'',key:woo.consumer_key||woo.ck||woo.key||'',secret:woo.consumer_secret||woo.cs||woo.secret||'',categoryId:woo.category_id||0},basalam:{token:basalam.token||'',vendorId:String(basalam.vendor_id||basalam.vendorId||''),api:basalam.api_base||basalam.api||'https://openapi.basalam.com/v1',preparationDays:basalam.preparation_days,weight:basalam.weight,packageWeight:basalam.package_weight,stock:basalam.stock,categoryId:basalam.category_id,autoCategory:basalam.auto_category,netIndirect:basalam.net_indirect,shops:basalam.shops},ai:{baseUrl:ai.base_url||ai.baseUrl||'',apiKey:ai.api_key||ai.apiKey||'',model:ai.model||'',activeProvider:ai.activeProvider||ai.active_provider||'',providers:ai.providers,candidates:ai.candidates,master:ai.master,network:ai.network},notifications:rawConnections.notifications||{}});connections=true;}
  if(files['category_learning.json'])categories=await importCategoryLearning(files['category_learning.json']);
  if(files['autoreply_log.json'])autoreplyLogs=await importAutoreplyLog(files['autoreply_log.json']);
  for(const [file,value] of Object.entries(files)){const key=stateKeyForFile(file);if(key){await setState(key,value);states++;}}
  return c.json({ok:true,format:'scraper4-php-compatible',imported:{profiles,products,states,categories,autoreplyLogs,connections},warnings});
});
app.get('/api/profile-stats', async c => c.json({ok:true,items:await profileStats()}));
app.post('/api/maintenance/recon/:target',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);const body=await c.req.json().catch(()=>({})) as any;return c.json({ok:true,report:await recon(target as any,String(body.profileId||''))})});
app.post('/api/maintenance/rebuild/:target',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);const body=await c.req.json().catch(()=>({})) as any;return c.json(await rebuildMap(target as any,String(body.profileId||'')))});
app.post('/api/maintenance/retire/:target',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);const body=await c.req.json() as any,apply=body.confirm==='APPLY';return c.json(await retire(target as any,String(body.profileId||''),String(body.action||'report'),apply))});
app.post('/api/maintenance/bulk/:target',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);const body=await c.req.json() as any;return c.json(await bulkEdit(target as any,body,body.confirm==='APPLY'))});
app.post('/api/maintenance/photo-fix',async c=>{const body=await c.req.json() as any;return c.json(await photoFix(String(body.profileId||''),body.confirm==='APPLY'))});
app.get('/api/destination/:target/products',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);const all=await listDestinationProducts(target as any),q=String(c.req.query('q')||'').toLowerCase(),filtered=q?all.filter(x=>x.name.toLowerCase().includes(q)||String(x.id)===q):all,limit=Math.min(200,Number(c.req.query('limit'))||50),offset=Math.max(0,Number(c.req.query('offset'))||0);return c.json({ok:true,total:filtered.length,items:filtered.slice(offset,offset+limit)})});
app.get('/api/destination/:target/overview',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);return c.json({ok:true,...await destinationOverview(target as any)})});
app.get('/api/destination/:target/duplicates',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);return c.json({ok:true,groups:await findDestinationDuplicates(target as any)})});
app.get('/api/destination/:target/suffix-report',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);return c.json(await suffixReport(target as any))});
app.post('/api/destination/woo/deduplicate',async c=>{const body=await c.req.json().catch(()=>({})) as any;return c.json(await deduplicateWoo(body.confirm==='APPLY'))});
app.get('/api/destination/basalam/rejected',async c=>c.json({ok:true,items:await rejectedBasalam()}));
app.post('/api/destination/basalam/:id/fix-category',async c=>{const body=await c.req.json() as any;if(body.confirm!=='APPLY')return c.json({ok:false,error:'confirm APPLY is required'},400);return c.json(await fixBasalamCategory(Number(c.req.param('id')),Number(body.categoryId)))});
app.post('/api/destination/:target/:id/status',async c=>{const target=c.req.param('target'),body=await c.req.json() as any;if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);if(body.confirm!=='APPLY')return c.json({ok:false,error:'confirm APPLY is required'},400);return c.json(await destinationChangeStatus(target as any,Number(c.req.param('id')),String(body.status||'')))});
app.delete('/api/destination/:target/:id',async c=>{const target=c.req.param('target');if(!['woo','basalam'].includes(target))return c.json({ok:false,error:'Invalid target'},400);if(c.req.query('confirm')!=='DELETE')return c.json({ok:false,error:'confirm DELETE is required'},400);return c.json(await destinationDelete(target as any,Number(c.req.param('id')),c.req.query('force')==='true'))});
app.post('/api/products/:profileId/:sourceKey/sync/:target',async c=>{const profile=await getProfile(c.req.param('profileId')),product=await getProduct(c.req.param('profileId'),c.req.param('sourceKey')),target=c.req.param('target');if(!profile||!product)return c.json({ok:false,error:'Product/profile not found'},404);if(target==='woo')return c.json({ok:true,result:await syncWoo(product,profile)});if(target==='basalam')return c.json({ok:true,result:await syncBasalam(product,profile)});return c.json({ok:false,error:'Invalid target'},400)});
app.post('/api/queue-watchdog', async c => { const body=await c.req.json().catch(()=>({})) as any; return c.json({ok:true,reaped:await reapStalledJobs(Number(body.minutes)||30)}); });
app.post('/api/source-test', async c => { const body=await c.req.json() as any; const result=await safeText(String(body.url||''),1_000_000); return c.json({ok:true,bytes:Buffer.byteLength(result.text),url:result.url,title:(result.text.match(/<title[^>]*>(.*?)<\/title>/is)?.[1]||'').replace(/<[^>]+>/g,'').trim()}); });
app.post('/api/test-connection/:target', async c => {
  const target=c.req.param('target'),connections=await loadConnections(true);
  if(target==='woo') { const x=connections.woo;if(!x.url||!x.key||!x.secret)return c.json({ok:false,error:'تنظیمات ووکامرس کامل نیست'},400);const auth=`Basic ${Buffer.from(`${x.key}:${x.secret}`).toString('base64')}`,r=await safeFetch(x.url+'/wp-json/wc/v3/system_status',{headers:{authorization:auth,accept:'application/json'}},2_000_000);return c.json({ok:r.ok,code:r.status}); }
  if(target==='basalam') { const x=connections.basalam;if(!x.token)return c.json({ok:false,error:'توکن باسلام خالی است'},400);const r=await safeFetch(x.api+'/categories',{headers:{authorization:`Bearer ${x.token}`,accept:'application/json'}},2_000_000);return c.json({ok:r.ok,code:r.status}); }
  if(target==='ai') { const ai=connections.ai;if(!ai.baseUrl||!ai.apiKey||!ai.model)return c.json({ok:false,error:'تنظیمات هوش مصنوعی کامل نیست'},400);const endpoint=ai.baseUrl+(ai.baseUrl.includes('/chat/completions')?'':'/chat/completions'),r=await safeFetch(endpoint,{method:'POST',headers:{authorization:`Bearer ${ai.apiKey}`,'content-type':'application/json'},body:JSON.stringify({model:ai.model,messages:[{role:'user',content:'سلام'}],max_tokens:20})},2_000_000);return c.json({ok:r.ok,code:r.status,body:await r.json().catch(()=>null)}); }
  return c.json({ok:false,error:'Unknown connection'},404);
});
app.get('/api/categories/:target',async c=>{const target=c.req.param('target'),connections=await loadConnections();if(target==='woo'){const x=connections.woo;if(!x.url||!x.key||!x.secret)return c.json({ok:false,error:'اتصال ووکامرس کامل نیست'},400);const auth=`Basic ${Buffer.from(`${x.key}:${x.secret}`).toString('base64')}`,items:any[]=[];for(let page=1;page<=20;page++){const r=await safeFetch(`${x.url}/wp-json/wc/v3/products/categories?per_page=100&page=${page}`,{headers:{authorization:auth,accept:'application/json'}},3_000_000),rows=await r.json() as any[];if(!r.ok)return c.json({ok:false,error:`Woo HTTP ${r.status}`},502);items.push(...rows);if(rows.length<100)break}return c.json({ok:true,items})}if(target==='basalam'){const x=connections.basalam;if(!x.token)return c.json({ok:false,error:'توکن باسلام خالی است'},400);const r=await safeFetch(`${x.api}/categories`,{headers:{authorization:`Bearer ${x.token}`,accept:'application/json'}},5_000_000),body=await r.json() as any;return c.json({ok:r.ok,items:body?.data||body?.categories||body})}return c.json({ok:false,error:'Invalid target'},400)});
app.get('/api/profiles', async c => c.json({ ok: true, profiles: await listProfiles() }));
app.post('/api/profiles', async c => {
  const profile=normalizeProfile(await c.req.json()),selectorErrors=validateSelectorConfig(profile.selectors);if(Object.keys(selectorErrors).length)return c.json({ok:false,error:'یک یا چند سلکتور نامعتبر است',selectorErrors},400);return c.json({ok:true,profile:await saveProfile(profile)});
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
app.get('/api/jobs/:id/events',c=>streamSSE(c,async stream=>{let last='',seq=0;for(let i=0;i<600;i++){const job=await getJob(c.req.param('id'));if(!job){await stream.writeSSE({event:'error',data:JSON.stringify({error:'Job not found'}),id:String(++seq)});break}const data=JSON.stringify(job);if(data!==last){await stream.writeSSE({event:'progress',data,id:String(++seq)});last=data}if(!['queued','running'].includes(job.status)){await stream.writeSSE({event:'done',data,id:String(++seq)});break}await stream.sleep(1000)}}));
app.get('/api/jobs/:id', async c => { const job = await getJob(c.req.param('id')); return job ? c.json({ ok: true, job }) : c.json({ ok: false, error: 'Job not found' }, 404); });
app.post('/api/jobs/:id/stop', async c => { await updateJob(c.req.param('id'), { stopRequested: true }); return c.json({ ok: true }); });
app.post('/api/jobs/:id/retry',async c=>{const job=await retryJob(c.req.param('id'));return job?c.json({ok:true,job}):c.json({ok:false,error:'Job cannot be retried'},409)});
app.delete('/api/jobs/:id',async c=>c.json({ok:await deleteJob(c.req.param('id'))}));
app.delete('/api/jobs',async c=>c.json({ok:true,deleted:await clearFinishedJobs()}));
app.get('/api/profiles/:id/products', async c => {
  const limit = Math.min(500, Number(c.req.query('limit')) || 100), offset = Math.max(0, Number(c.req.query('offset')) || 0);
  return c.json({ ok: true, ...await listProducts(c.req.param('id'), limit, offset, c.req.query('q') || '') });
});
app.get('/api/profiles/:id/export.xlsx',async c=>{const result=await listProducts(c.req.param('id'),100000,0,''),buffer=await productsToXlsx(result.products);return c.body(buffer as any,200,{'content-type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','content-disposition':`attachment; filename="${c.req.param('id').replace(/[^a-z0-9_.-]/gi,'_')}.xlsx"`})});
app.post('/api/profiles/:id/import.xlsx',async c=>{const profile=await getProfile(c.req.param('id'));if(!profile)return c.json({ok:false,error:'Profile not found'},404);const body=await c.req.json() as any;if(typeof body.base64!=='string')return c.json({ok:false,error:'base64 is required'},400);return c.json(await importRows(profile,await xlsxToRows(Buffer.from(body.base64,'base64'))))});
app.get('/api/profiles/:id/export.csv',async c=>{const result=await listProducts(c.req.param('id'),100000,0,''),fields=['sourceKey','title','price','url','image','sku','brand','stock','weight','category','shortDesc','longDesc'],csv='\uFEFF'+fields.join(',')+'\n'+result.products.map(p=>fields.map(field=>csvCell((p as any)[field])).join(',')).join('\n');return c.body(csv,200,{'content-type':'text/csv; charset=utf-8','content-disposition':`attachment; filename="${c.req.param('id').replace(/[^a-z0-9_.-]/gi,'_')}.csv"`})});
app.post('/api/profiles/:id/import',async c=>{const profile=await getProfile(c.req.param('id'));if(!profile)return c.json({ok:false,error:'Profile not found'},404);const body=await c.req.json() as any,rows=Array.isArray(body.rows)?body.rows:typeof body.csv==='string'?parseCsv(body.csv):[];return c.json(await importRows(profile,rows))});
app.post('/api/selector-workbench',async c=>{const body=await c.req.json() as any;return c.json({ok:true,...await selectorWorkbench(String(body.url||''),{...DEFAULT_SELECTORS,...(body.selectors||{})})})});
app.post('/api/selector-suggest',async c=>{const body=await c.req.json() as any;return c.json({ok:true,suggestions:await suggestListSelectors(String(body.url||''))})});
app.post('/api/detail-selectors-test',async c=>{const body=await c.req.json() as any;return c.json({ok:true,...await testDetailSelectors(String(body.url||''),{...DEFAULT_SELECTORS,...(body.selectors||{})})})});
app.post('/api/gallery-suggest',async c=>{const body=await c.req.json() as any;return c.json({ok:true,suggestions:await suggestGallery(String(body.url||''))})});
app.post('/api/test-selector', async c => {
  const body = await c.req.json() as any; return c.json({ ok: true, ...await testSelector(String(body.url || ''), String(body.selector || ''), String(body.type || 'text')) });
});
app.post('/api/import-php', async c => {
  const body = await c.req.json() as any; const source = typeof body.profiles === 'string' ? JSON.parse(body.profiles) : body.profiles;
  const imported: Profile[] = [];
  for (const [id, value] of Object.entries(source || {})) imported.push(await saveProfile(normalizeProfile({ ...(value as any), id })));
  return c.json({ ok: true, imported: imported.length, profiles: imported });
});
app.all('/scraper4.php',legacyHandler);

const server = serve({ fetch: app.fetch, port: config.port, hostname: config.host }, info => console.log(`Scraper4 Render listening on http://${info.address}:${info.port}`));
let scheduler: NodeJS.Timeout | undefined;
let backgroundStarted = false;
function startBackground(): void {
  if (!config.runWorkerInWeb || !databaseReady || backgroundStarted) return;
  backgroundStarted = true;
  void workerLoop(config.workerPollMs);
  const schedule = async () => { try { const count=await enqueueDueProfiles();if(count)console.log(`Scheduled ${count} profile(s)`);const automation=await automationTick();if(Object.keys(automation).length)console.log('Automation',JSON.stringify(automation)); } catch (error) { console.error('Scheduler error', error); } };
  void schedule(); scheduler = setInterval(schedule, 60_000); scheduler.unref();
}
startBackground();
const databaseRetry = setInterval(async () => { if (!databaseReady && config.databaseUrl && await initializeDatabase()) startBackground(); }, 30_000);
databaseRetry.unref();
const shutdown = async () => { requestWorkerStop(); clearInterval(databaseRetry); if (scheduler) clearInterval(scheduler); server.close(); await pool.end(); process.exit(0); };
process.on('SIGTERM', shutdown); process.on('SIGINT', shutdown);

async function legacyHandler(c:any){const q=c.req.query(),has=(key:string)=>Object.prototype.hasOwnProperty.call(q,key);if(c.req.method==='POST'){const body=await c.req.parseBody({all:true}),action=String(body.action||q.action||'');if(action==='save_profile'){const listSelectors=parseJsonField(body.selectors)||{},detailRaw=parseJsonField(body.detailSelectors)||{},detailSelectors=Object.fromEntries(Object.entries(detailRaw).map(([key,value]:any)=>[key,typeof value==='string'?value:value?.selector||''])),raw:any={...body,selectors:{...listSelectors,...detailSelectors},pagination:body.pagType,paginationValue:body.pagVal,priceValue:body.priceVal};const profile=normalizeProfile(raw),selectorErrors=validateSelectorConfig(profile.selectors);if(Object.keys(selectorErrors).length)return c.json({ok:false,error:'سلکتور نامعتبر',selectorErrors},400);return c.json({ok:true,key:profile.id,profile:await saveProfile(profile),message:'پروفایل ذخیره شد'})}if(action==='delete_profile'){const id=String(body.profile_key||body.id||'');return c.json({ok:await deleteProfile(id),key:id})}if(action==='load_connections')return c.json({ok:true,connections:await loadConnections(true)});if(action==='save_connections'){const value=parseJsonField(body.connections)||body;return c.json({ok:true,connections:await saveConnections(value)})}if(action==='test_woo'||action==='test_basalam'||action==='test_ai'){const target=action.replace('test_',''),connections=await loadConnections(true);if(target==='woo'){const x=connections.woo,auth=`Basic ${Buffer.from(`${x.key}:${x.secret}`).toString('base64')}`,r=await safeFetch(`${x.url}/wp-json/wc/v3/system_status`,{headers:{authorization:auth}},2_000_000);return c.json({ok:r.ok,code:r.status})}if(target==='basalam'){const x=connections.basalam,r=await safeFetch(`${x.api}/categories`,{headers:{authorization:`Bearer ${x.token}`}},2_000_000);return c.json({ok:r.ok,code:r.status})}const providers=await aiProviders(),provider=providers.find(x=>x.enabled&&x.models.length);return provider?c.json(await aiCall(provider,provider.models[0],'سلام')):c.json({ok:false,error:'مدل فعال نیست'},400)}if(action==='ai_import_providers'){const value=parseJsonField(body.providers||body.json)||[],connections=await loadConnections(true);connections.ai.providers=Array.isArray(value)?value:value.providers||[];return c.json({ok:true,connections:await saveConnections(connections)})}if(action==='ai_candidates_save'||action==='ai_select'){const connections=await loadConnections(true);if(action==='ai_candidates_save')connections.ai.candidates=parseJsonField(body.candidates)||[];else{connections.ai.master=String(body.model||'');if(body.provider&&body.model)connections.ai.model=String(body.model)}return c.json({ok:true,connections:await saveConnections(connections)})}if(action==='ai_vote'){return c.json({ok:true,leaderboard:await recordVote(String(body.task||'manual'),String(body.winner||''),parseJsonField(body.candidates)||[])})}if(action==='backup_restore'){const file=body.file,bundle=file instanceof File?JSON.parse(await file.text()):parseJsonField(body.bundle||body.json);if(!bundle)return c.json({ok:false,error:'بسته بکاپ نامعتبر است'},400);if(bundle.app==='scraper4-render')return c.json({ok:true,result:await restoreBackup(bundle)});return c.json({ok:false,error:'برای فایل settings_*.json از /api/settings-import استفاده کنید'},409)}if(action==='ar_save_rules'){const settings=await getState<any>('settings',{});settings.autoreply??={};settings.autoreply.rules=parseJsonField(body.rules)||[];await setState('settings',settings);return c.json({ok:true})}if(action==='catlearn_import'){return c.json({ok:true,imported:await importCategoryLearning(parseJsonField(body.data)||{})})}if(action==='cron_run'){return c.json({ok:true,enqueued:await enqueueDueProfiles(),automation:await automationTick()})}return c.json({ok:false,error:`Legacy POST action not ported: ${action}`},501)}
if(has('profiles')||has('all_profiles')){const profiles=await listProfiles();return c.json(Object.fromEntries(profiles.map(p=>[p.id,p])))}if(has('load_profile')){const value=String(q.load_profile||q.profile_key||''),profile=(await listProfiles()).find(p=>p.id===value||p.url===value);return profile?c.json({ok:true,key:profile.id,profile}):c.json({ok:false,error:'پروفایل پیدا نشد'},404)}if(has('poll_extract')||has('extract_queue_status')||has('queues_overview'))return c.json({ok:true,jobs:await listJobs(100)});if(has('poll_woo')||has('woo_queue_status'))return c.json({ok:true,jobs:(await listJobs(100)).filter(j=>j.target==='woo'||j.target==='both')});if(has('poll_bsl')||has('bsl_queue_status'))return c.json({ok:true,jobs:(await listJobs(100)).filter(j=>j.target==='basalam'||j.target==='both')});if(has('extract_stop')||has('woo_stop')||has('bsl_stop')){const id=String(q.queue_id||q.id||'');if(id)await updateJob(id,{stopRequested:true});return c.json({ok:true})}if(has('cron_run'))return c.json({ok:true,enqueued:await enqueueDueProfiles(),automation:await automationTick()});if(has('cron_last'))return c.json({ok:true,jobs:await listJobs(10)});if(has('whoami'))return c.json({ok:true,runtime:'render-node',version:APP_VERSION});if(has('selftest')||has('sec_check'))return c.json(await runSelftest());if(has('backup_export'))return c.json(await createPhpSettingsBundle(new URL(c.req.url).host));if(has('backup_status'))return c.json({ok:true,available:true,storage:'PostgreSQL'});if(has('bsl_products'))return c.json({ok:true,items:await listDestinationProducts('basalam')});if(has('bsl_status_overview'))return c.json({ok:true,...await destinationOverview('basalam')});if(has('bsl_find_duplicates'))return c.json({ok:true,groups:await findDestinationDuplicates('basalam')});if(has('bsl_rejected_cats'))return c.json({ok:true,items:await rejectedBasalam()});if(has('bsl_orders_list'))return c.json({ok:true,rows:await basalamOrders(Number(q.per_page)||20)});if(has('bsl_chats_list'))return c.json({ok:true,rows:await basalamChats(Number(q.limit)||20)});if(has('ai_providers_status'))return c.json({ok:true,providers:await aiProviders()});if(has('ai_candidates')){const x=(await loadConnections()).ai;return c.json({ok:true,candidates:x.candidates,master:x.master,leaderboard:await getLeaderboard()})}if(has('ai_test_start'))return c.json({ok:true,state:await startAiTest({prompt:String(q.msg||'سلام'),categoryTitle:String(q.title||'ادو پرفیوم'),perProvider:Number(q.per_provider)||50,delayMs:Number(q.delay)||120,onlyUntested:q.only_untested==='1'})});if(has('ai_test_status'))return c.json({ok:true,state:await aiTestState()});if(has('ai_test_stop'))return c.json(await stopAiTest());if(has('ai_candidates_category'))return c.json({ok:true,...await candidateTest('category',String(q.title||q.text||''))});if(has('ai_candidates_reply'))return c.json({ok:true,...await candidateTest('autoreply',String(q.text||''))});if(has('ai_probe'))return c.json(await networkDiagnostics());if(has('ai_test_all'))return c.json({ok:true,results:await testAllModels(String(q.msg||'سلام'))});if(has('ar_rules'))return c.json({ok:true,rules:(await getState<any>('settings',{})).autoreply?.rules||[]});if(has('ar_log'))return c.json({ok:true,items:await autoreplyLogs()});if(has('ar_test'))return c.json({ok:true,result:await generateReply(String(q.text||'سلام'))});if(has('ar_run'))return c.json(await autoreplyRun(q.dry!=='0'));if(has('digest'))return c.json(await digest(q.send!=='1'));if(has('recon'))return c.json({ok:true,report:await recon(q.target==='bsl'?'basalam':'woo',String(q.profile||''))});if(has('photo_fix'))return c.json(await photoFix(String(q.profile||''),q.apply==='1'));if(has('suffix_report'))return c.json(await suffixReport(q.target==='bsl'?'basalam':'woo'));if(has('suggest_selectors'))return c.json({ok:true,suggestions:await suggestListSelectors(String(q.suggest_selectors||q.url||''))});if(has('suggest_detail_selectors'))return c.json({ok:true,...await testDetailSelectors(String(q.suggest_detail_selectors||q.url||''),DEFAULT_SELECTORS)});if(has('gallery_suggest'))return c.json({ok:true,suggestions:await suggestGallery(String(q.gallery_suggest||q.url||''))});if(has('test_selector'))return c.json({ok:true,...await testSelector(String(q.test_selector||q.url||''),String(q.selector||''),String(q.type||'text'))});if(has('image_proxy')){const r=await safeFetch(String(q.image_proxy),{},10_000_000),type=r.headers.get('content-type')||'';if(!r.ok||!type.startsWith('image/'))return c.json({ok:false,error:'Invalid image'},400);return new Response(r.body,{headers:{'content-type':type,'cache-control':'private,max-age=300'}})}return c.json({ok:false,error:'Legacy endpoint not ported',query:Object.keys(q)},501)}
function parseJsonField(value:unknown){if(typeof value!=='string')return value;try{return JSON.parse(value)}catch{return null}}

async function importRows(profile:Profile,rows:any[]){let imported=0,failed=0;const errors:string[]=[];for(const [index,row] of rows.entries())try{const title=String(row.title||row.name||'').trim();if(!title)throw Error('title is empty');const key=String(row.sourceKey||row.key||crypto.randomUUID()),image=String(row.image||'');await upsertProduct(profile.id,{sourceKey:key,title,price:numberFromText(String(row.price||0)),priceText:String(row.price||''),url:String(row.url||row.link||''),image,images:image?[image]:[],sku:String(row.sku||''),brand:String(row.brand||''),stock:row.stock==null||row.stock===''?undefined:Number(row.stock),weight:row.weight==null||row.weight===''?undefined:Number(row.weight),category:String(row.category||''),shortDesc:String(row.shortDesc||''),longDesc:String(row.longDesc||''),sourcePage:'import',scrapedAt:new Date().toISOString()});imported++}catch(error){failed++;if(errors.length<50)errors.push(`row ${index+1}: ${error instanceof Error?error.message:String(error)}`)}return{ok:failed===0,imported,failed,errors}}
function csvCell(value:unknown){return `"${String(value??'').replace(/"/g,'""')}"`}
function parseCsv(text:string):Record<string,string>[] {const rows:string[][]=[];let row:string[]=[],cell='',quoted=false;const input=text.replace(/^\uFEFF/,'');for(let i=0;i<input.length;i++){const ch=input[i];if(quoted){if(ch==='"'&&input[i+1]==='"'){cell+='"';i++}else if(ch==='"')quoted=false;else cell+=ch}else if(ch==='"')quoted=true;else if(ch===','){row.push(cell);cell=''}else if(ch==='\n'){row.push(cell);rows.push(row);row=[];cell=''}else if(ch!=='\r')cell+=ch}if(cell||row.length){row.push(cell);rows.push(row)}const headers=rows.shift()?.map(x=>x.trim())||[];return rows.filter(x=>x.some(Boolean)).map(values=>Object.fromEntries(headers.map((key,i)=>[key,values[i]||''])))}
function validTarget(value: string): 'none'|'woo'|'basalam'|'both' { return ['none','woo','basalam','both'].includes(value) ? value as any : 'none'; }
function safeEqual(a: string, b: string): boolean { const aa=Buffer.from(a),bb=Buffer.from(b); return aa.length===bb.length && timingSafeEqual(aa,bb); }
function idFromUrl(raw: string): string { const url = new URL(raw); return `${url.hostname}_${url.pathname}`.toLowerCase().replace(/[^a-z0-9_.-]+/g,'_').replace(/^_+|_+$/g,'').slice(0,120); }
function legacyProducts(raw: unknown): Product[] {
  const entries:Array<[string,any]>=[];
  if(Array.isArray(raw))for(const item of raw){if(Array.isArray(item)&&item.length>=2)entries.push([String(item[0]),item[1]]);else if(item&&typeof item==='object')entries.push([String((item as any).sourceKey||(item as any).key||crypto.randomUUID()),item]);}
  else if(raw&&typeof raw==='object')for(const [key,value] of Object.entries(raw as Record<string,any>))entries.push([key,value]);
  return entries.filter(([,p])=>p&&p.title).map(([key,p])=>{const images=Array.isArray(p.images)?p.images.filter((x:unknown)=>typeof x==='string'&&!String(x).startsWith('data:')):[];const image=String(p.image||images[0]||'');if(image&&!images.includes(image)&&!image.startsWith('data:'))images.unshift(image);return{sourceKey:key,title:String(p.title),price:numberFromText(String(p.finalPrice??p.price??0)),priceText:String(p.priceText??p.price??''),url:String(p.url||p.link||''),image:image.startsWith('data:')?'':image,images,shortDesc:String(p.shortDesc||''),longDesc:String(p.longDesc||''),sku:String(p.sku||''),brand:String(p.brand||''),stock:p.stock==null?undefined:Number(p.stock),weight:p.weight==null?undefined:Number(p.weight),category:String(p.category||''),sourcePage:String(p.sourcePage||''),scrapedAt:new Date().toISOString()}});
}
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
