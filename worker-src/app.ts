import { Hono } from 'hono';
import { secureHeaders } from 'hono/secure-headers';
import { aiCall, aiProviders, getLeaderboard, recordVote, testAllModels } from './ai.js';
import { automationTick, autoreplyLogs, autoreplyRun, basalamChats, basalamOrders, digest, generateReply } from './automation.js';
import { connectionStatus, loadConnections, saveConnections } from './connections.js';
import { DASHBOARD, DASHBOARD_JS } from './dashboard.js';
import { allProducts, clearFinishedJobs, createBackup, createJob, deleteJob, deleteProfile, enqueueDueProfiles, ensureSchema, findLearnedCategory, getJob, getProduct, getProfile, getState, importAutoreplyLog, importCategoryLearning, learnCategory, listCategoryLearning, listJobs, listProducts, listProfiles, profileStats, reapStalledJobs, restoreBackup, retryJob, saveProfile, setState, updateJob, upsertProduct } from './db.js';
import { configureEnv, type Env } from './env.js';
import { bulkEdit, destinationChangeStatus, destinationDelete, destinationOverview, findDestinationDuplicates, listDestinationProducts, photoFix, rebuildMap, recon, retire } from './maintenance.js';
import { safeFetch, safeText } from './network.js';
import { sendNotification } from './notifications.js';
import { PHP_MENU_CAPABILITIES, runSelftest } from './parity.js';
import { runDiagnostics } from './diagnostics.js';
import { enqueueJob } from './processor.js';
import { numberFromText, suggestSelectors, testSelector, testVariations } from './scraper.js';
import { createPhpSettingsBundle, decodePhpSettingsBundle, stateKeyForFile } from './settings-transfer.js';
import { syncBasalam, syncWoo } from './sync.js';
import { DEFAULT_SELECTORS, type Product, type Profile } from './types.js';
import { basicAuth, byteLength, escapeHtml, message } from './utils.js';
import { createVisualTicket, renderVisualSelector } from './visual.js';

type Variables={requestId:string};
export const app=new Hono<{Bindings:Env;Variables:Variables}>();
const dashboardSecurity=secureHeaders({contentSecurityPolicy:{defaultSrc:["'self'"],scriptSrc:["'self'"],styleSrc:["'self'","'unsafe-inline'"],connectSrc:["'self'"],imgSrc:["'self'",'data:','https:'],objectSrc:["'none'"],frameAncestors:["'none'"]},referrerPolicy:'no-referrer'});
app.use('*',async(c,next)=>{configureEnv(c.env);c.set('requestId',crypto.randomUUID());c.header('x-content-type-options','nosniff');await next()});
app.use('*',async(c,next)=>c.req.path==='/visual'?next():dashboardSecurity(c,next));
app.onError((error,c)=>{console.error(JSON.stringify({requestId:c.get('requestId'),path:c.req.path,error:message(error)}));const text=message(error),status=/Unauthorized/.test(text)?401:/not found/i.test(text)?404:/invalid|required|empty|خالی|نامعتبر/i.test(text)?400:/HTTP|fetch|network|اتصال/i.test(text)?502:500;return c.json({ok:false,error:text,requestId:c.get('requestId')},status as any)});

app.get('/health',c=>c.json({ok:true,app:'scraper4-cloudflare',runtime:'cloudflare-workers',databaseReady:Boolean(c.env.DB),databaseError:c.env.DB?null:'D1 binding DB is missing',workerInWeb:Boolean(c.env.JOBS),authenticationRequired:false,version:c.env.WORKER_VERSION||'1.0.0',time:new Date().toISOString()}));
app.get('/',async c=>{await ensureSchema(c.env.DB);return c.html(DASHBOARD)});
app.get('/dashboard.js',c=>c.body(DASHBOARD_JS,200,{'content-type':'application/javascript; charset=utf-8','cache-control':'no-store'}));
app.get('/visual',async c=>{const content=await renderVisualSelector(c.req.query('ticket')||'');return c.html(content,200,{'cache-control':'no-store','content-security-policy':"default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline' https: http:; font-src https: http: data:; script-src 'unsafe-inline'; frame-ancestors 'self';",'referrer-policy':'no-referrer'})});
app.use('/api/*',async(c,next)=>{if(!c.env.DB)return c.json({ok:false,error:'D1 binding DB is not configured'},503);await ensureSchema(c.env.DB);await next()});

app.post('/api/visual-ticket',async c=>{const body=await c.req.json() as any,url=new URL(String(body.url||''));if(!['http:','https:'].includes(url.protocol))return c.json({ok:false,error:'Invalid visual selector URL'},400);return c.json({ok:true,ticket:await createVisualTicket(url.href),expiresIn:300})});
app.get('/api/status',async c=>{const connections=await loadConnections();return c.json({ok:true,profiles:(await listProfiles()).length,jobs:await listJobs(10),connections:connectionStatus(connections),queue:Boolean(c.env.JOBS),storage:{d1:true,r2:Boolean(c.env.BACKUPS)}})});
app.get('/api/selftest',async c=>c.json(await runSelftest()));
app.get('/api/debug',async c=>c.json(await runDiagnostics()));
app.get('/api/parity',c=>c.json({ok:true,total:PHP_MENU_CAPABILITIES.length,capabilities:PHP_MENU_CAPABILITIES,dispatcherAudit:{reference:'scraper4.php v9.80',total:178,get:150,post:28,mapped:178,missing:0,artifact:'parity-manifest.json'}}));
app.get('/api/version',c=>c.json({ok:true,version:c.env.WORKER_VERSION||'1.0.0',runtime:'cloudflare-workers',deployment:'wrangler versions deploy / wrangler rollback'}));
app.get('/api/connections',async c=>c.json({ok:true,connections:await loadConnections(true)}));
app.post('/api/connections',async c=>c.json({ok:true,connections:await saveConnections(await c.req.json())}));
app.get('/api/ai/providers',async c=>c.json({ok:true,providers:await aiProviders(),leaderboard:await getLeaderboard()}));
app.post('/api/ai/test-all',async c=>{const b=await jsonBody(c);return c.json({ok:true,results:await testAllModels(String(b.prompt||'سلام'),Boolean(b.onlyCandidates))})});
app.post('/api/ai/call',async c=>{const b=await jsonBody(c),provider=(await aiProviders()).find(p=>p.id===b.provider);if(!provider)return c.json({ok:false,error:'Provider not found'},404);return c.json(await aiCall(provider,String(b.model||''),String(b.prompt||'سلام')))});
app.post('/api/ai/vote',async c=>{const b=await jsonBody(c);return c.json({ok:true,leaderboard:await recordVote(String(b.task||'manual'),String(b.winner||''),Array.isArray(b.candidates)?b.candidates.map(String):[])})});
app.get('/api/ai/leaderboard',async c=>c.json({ok:true,leaderboard:await getLeaderboard()}));
app.post('/api/notifications/test',async c=>{const b=await jsonBody(c);return c.json(await sendNotification(b.channel||'webhook',String(b.text||'پیام آزمایشی اسکرپر ۴ از Cloudflare Workers')))});
app.get('/api/category-learning',async c=>c.json({ok:true,items:await listCategoryLearning(Math.min(5000,Number(c.req.query('limit'))||1000))}));
app.post('/api/category-learning/record',async c=>{const b=await jsonBody(c);return c.json({ok:true,saved:await learnCategory(String(b.title||''),Number(b.categoryId),String(b.categoryName||''),Number(b.maxWords)||5)})});
app.post('/api/category-learning/test',async c=>{const b=await jsonBody(c);return c.json({ok:true,result:await findLearnedCategory(String(b.title||''),Number(b.maxWords)||5)})});
app.post('/api/autoreply/test',async c=>{const b=await jsonBody(c);return c.json({ok:true,result:await generateReply(String(b.text||''))})});
app.post('/api/autoreply/run',async c=>{const b=await jsonBody(c);return c.json(await autoreplyRun(b.confirm!=='APPLY'))});
app.get('/api/autoreply/log',async c=>c.json({ok:true,items:await autoreplyLogs()}));
app.post('/api/digest',async c=>{const b=await jsonBody(c);return c.json(await digest(b.confirm!=='SEND'))});
app.get('/api/basalam/chats',async c=>c.json({ok:true,items:await basalamChats(Number(c.req.query('limit'))||20)}));
app.get('/api/basalam/orders',async c=>c.json({ok:true,items:await basalamOrders(Number(c.req.query('limit'))||20)}));
app.get('/api/settings',async c=>c.json({ok:true,settings:await getState('settings',{})}));
app.post('/api/settings',async c=>{await setState('settings',await c.req.json());return c.json({ok:true})});

app.get('/api/backup',async c=>{const backup=await createBackup();if(c.req.query('persist')==='true'){if(!c.env.BACKUPS)return c.json({ok:false,error:'Persistent R2 backups are disabled because this deployment has no R2 subscription. Use /api/backup without persist=true to download the complete JSON backup.'},400);const key=`scraper4/${new Date().toISOString().replace(/[:.]/g,'-')}.json`;await c.env.BACKUPS.put(key,JSON.stringify(backup),{httpMetadata:{contentType:'application/json'},customMetadata:{app:'scraper4-cloudflare'}});return c.json({ok:true,persisted:true,key,backup})}return c.json(backup,200,{'content-disposition':`attachment; filename="scraper4-cloudflare-${Date.now()}.json"`})});
app.post('/api/restore',async c=>c.json({ok:true,result:await restoreBackup(await c.req.json())}));
app.get('/api/settings-export',async c=>{const bundle=await createPhpSettingsBundle(new URL(c.req.url).host),stamp=new Date().toISOString().replace(/[-:T]/g,'').slice(0,15);return c.json(bundle,200,{'content-disposition':`attachment; filename="settings_${stamp}.json"`})});
app.post('/api/settings-import',async c=>c.json(await importPhpSettings(await c.req.json())));
app.post('/api/import-php',async c=>{const body=await jsonBody(c),source=typeof body.profiles==='string'?JSON.parse(body.profiles):body.profiles,imported:Profile[]=[];for(const[id,value]of Object.entries(source||{}))imported.push(await saveProfile(normalizeProfile({...value as any,id})));return c.json({ok:true,imported:imported.length,profiles:imported})});

app.get('/api/profile-stats',async c=>c.json({ok:true,items:await profileStats()}));
app.post('/api/maintenance/recon/:target',async c=>{const target=validDestination(c.req.param('target')),b=await jsonBody(c);return c.json({ok:true,report:await recon(target,String(b.profileId||''))})});
app.post('/api/maintenance/rebuild/:target',async c=>{const target=validDestination(c.req.param('target')),b=await jsonBody(c);return c.json(await rebuildMap(target,String(b.profileId||'')))});
app.post('/api/maintenance/retire/:target',async c=>{const target=validDestination(c.req.param('target')),b=await jsonBody(c);return c.json(await retire(target,String(b.profileId||''),String(b.action||'report'),b.confirm==='APPLY'))});
app.post('/api/maintenance/bulk/:target',async c=>{const target=validDestination(c.req.param('target')),b=await jsonBody(c);return c.json(await bulkEdit(target,b,b.confirm==='APPLY'))});
app.post('/api/maintenance/photo-fix',async c=>{const b=await jsonBody(c);return c.json(await photoFix(String(b.profileId||''),b.confirm==='APPLY'))});
app.get('/api/destination/:target/products',async c=>{const target=validDestination(c.req.param('target')),all=await listDestinationProducts(target),q=String(c.req.query('q')||'').toLowerCase(),filtered=q?all.filter(x=>x.name.toLowerCase().includes(q)||String(x.id)===q):all,limit=Math.min(200,Number(c.req.query('limit'))||50),offset=Math.max(0,Number(c.req.query('offset'))||0);return c.json({ok:true,total:filtered.length,items:filtered.slice(offset,offset+limit)})});
app.get('/api/destination/:target/overview',async c=>c.json({ok:true,...await destinationOverview(validDestination(c.req.param('target')))}));
app.get('/api/destination/:target/duplicates',async c=>c.json({ok:true,groups:await findDestinationDuplicates(validDestination(c.req.param('target')))}));
app.post('/api/destination/:target/:id/status',async c=>{const b=await jsonBody(c);if(b.confirm!=='APPLY')return c.json({ok:false,error:'confirm APPLY is required'},400);return c.json(await destinationChangeStatus(validDestination(c.req.param('target')),Number(c.req.param('id')),String(b.status||'')))});
app.delete('/api/destination/:target/:id',async c=>{if(c.req.query('confirm')!=='DELETE')return c.json({ok:false,error:'confirm DELETE is required'},400);return c.json(await destinationDelete(validDestination(c.req.param('target')),Number(c.req.param('id')),c.req.query('force')==='true'))});
app.post('/api/products/:profileId/:sourceKey/sync/:target',async c=>{const profile=await getProfile(c.req.param('profileId')),product=await getProduct(c.req.param('profileId'),c.req.param('sourceKey')),target=validDestination(c.req.param('target'));if(!profile||!product)return c.json({ok:false,error:'Product/profile not found'},404);return c.json({ok:true,result:target==='woo'?await syncWoo(product,profile):await syncBasalam(product,profile)})});

app.post('/api/queue-watchdog',async c=>{const b=await jsonBody(c);return c.json({ok:true,reaped:await reapStalledJobs(Number(b.minutes)||30)})});
app.post('/api/source-test',async c=>{const b=await jsonBody(c),result=await safeText(String(b.url||''),1_000_000);return c.json({ok:true,bytes:byteLength(result.text),url:result.url,title:(result.text.match(/<title[^>]*>(.*?)<\/title>/is)?.[1]||'').replace(/<[^>]+>/g,'').trim()})});
app.post('/api/test-selector',async c=>{const b=await jsonBody(c);if(b.type==='variations')return c.json({ok:true,...await testVariations(String(b.url||''),String(b.selector||''))});return c.json({ok:true,...await testSelector(String(b.url||''),String(b.selector||''),String(b.type||'text'))})});
app.post('/api/suggest-selectors',async c=>{const b=await jsonBody(c),mode=['list','detail'].includes(b.mode)?b.mode:'all';return c.json({ok:true,...await suggestSelectors(String(b.url||''),mode)})});
app.post('/api/test-connection/:target',async c=>{const target=c.req.param('target'),connections=await loadConnections(true);if(target==='woo'){const x=connections.woo;if(!x.url||!x.key||!x.secret)return c.json({ok:false,error:'تنظیمات ووکامرس کامل نیست'},400);const r=await safeFetch(x.url+'/wp-json/wc/v3/system_status',{headers:{authorization:basicAuth(x.key,x.secret),accept:'application/json'}},2_000_000);return c.json({ok:r.ok,code:r.status})}if(target==='basalam'){const x=connections.basalam;if(!x.token)return c.json({ok:false,error:'توکن باسلام خالی است'},400);const r=await safeFetch(x.api+'/categories',{headers:{authorization:`Bearer ${x.token}`,accept:'application/json'}},2_000_000);return c.json({ok:r.ok,code:r.status})}if(target==='ai'){const provider=(await aiProviders()).find(x=>x.enabled&&x.models.length);if(!provider)return c.json({ok:false,error:'تنظیمات AI کامل نیست'},400);return c.json(await aiCall(provider,provider.models[0],'سلام'))}return c.json({ok:false,error:'Unknown connection'},404)});
app.get('/api/categories/:target',async c=>{const target=validDestination(c.req.param('target')),connections=await loadConnections();if(target==='woo'){const x=connections.woo;if(!x.url||!x.key||!x.secret)return c.json({ok:false,error:'اتصال ووکامرس کامل نیست'},400);const items:any[]=[];for(let page=1;page<=20;page++){const r=await safeFetch(`${x.url}/wp-json/wc/v3/products/categories?per_page=100&page=${page}`,{headers:{authorization:basicAuth(x.key,x.secret),accept:'application/json'}},3_000_000),found=await r.json() as any[];if(!r.ok)throw new Error(`Woo HTTP ${r.status}`);items.push(...found);if(found.length<100)break}return c.json({ok:true,items})}const x=connections.basalam;if(!x.token)return c.json({ok:false,error:'توکن باسلام خالی است'},400);const r=await safeFetch(`${x.api}/categories`,{headers:{authorization:`Bearer ${x.token}`,accept:'application/json'}},5_000_000),body=await r.json() as any;return c.json({ok:r.ok,items:body?.data||body?.categories||body})});

app.get('/api/profiles',async c=>c.json({ok:true,profiles:await listProfiles()}));
app.post('/api/profiles',async c=>c.json({ok:true,profile:await saveProfile(normalizeProfile(await c.req.json()))}));
app.delete('/api/profiles/:id',async c=>c.json({ok:await deleteProfile(c.req.param('id'))}));
app.post('/api/profiles/:id/scrape',async c=>createProfileJob(c,c.req.param('id'),'scrape'));
app.post('/api/profiles/:id/sync',async c=>createProfileJob(c,c.req.param('id'),'sync'));
app.get('/api/profiles/:id/products',async c=>c.json({ok:true,...await listProducts(c.req.param('id'),Math.min(500,Number(c.req.query('limit'))||100),Math.max(0,Number(c.req.query('offset'))||0),c.req.query('q')||'')}));
app.get('/api/profiles/:id/export.csv',async c=>{const result=await listProducts(c.req.param('id'),100000,0,''),fields=['sourceKey','title','price','url','image','sku','brand','stock','weight','category','shortDesc','longDesc','variations','variationGroups'],csv='\uFEFF'+fields.join(',')+'\n'+result.products.map(p=>fields.map(field=>csvCell((p as any)[field])).join(',')).join('\n');return c.body(csv,200,{'content-type':'text/csv; charset=utf-8','content-disposition':`attachment; filename="${c.req.param('id').replace(/[^a-z0-9_.-]/gi,'_')}.csv"`})});
app.post('/api/profiles/:id/import',async c=>{const profile=await getProfile(c.req.param('id'));if(!profile)return c.json({ok:false,error:'Profile not found'},404);const b=await jsonBody(c),records=Array.isArray(b.rows)?b.rows:typeof b.csv==='string'?parseCsv(b.csv):[];let imported=0,failed=0;const errors:string[]=[];for(const[index,row]of records.entries())try{const title=String(row.title||row.name||'').trim();if(!title)throw new Error('title is empty');const key=String(row.sourceKey||row.key||crypto.randomUUID()),image=String(row.image||'');await upsertProduct(profile.id,{sourceKey:key,title,price:numberFromText(String(row.price||0)),priceText:String(row.price||''),url:String(row.url||row.link||''),image,images:image?[image]:[],sku:String(row.sku||''),brand:String(row.brand||''),stock:row.stock==null?undefined:Number(row.stock),weight:row.weight==null?undefined:Number(row.weight),category:String(row.category||''),shortDesc:String(row.shortDesc||''),longDesc:String(row.longDesc||''),variations:jsonValue(row.variations,[]),variationGroups:jsonValue(row.variationGroups,[]),sourcePage:'import',scrapedAt:new Date().toISOString()});imported++}catch(error){failed++;if(errors.length<50)errors.push(`row ${index+1}: ${message(error)}`)}return c.json({ok:failed===0,imported,failed,errors})});

app.get('/api/jobs',async c=>c.json({ok:true,jobs:await listJobs(Math.min(200,Number(c.req.query('limit'))||50))}));
app.get('/api/jobs/:id',async c=>{const job=await getJob(c.req.param('id'));return job?c.json({ok:true,job}):c.json({ok:false,error:'Job not found'},404)});
app.post('/api/jobs/:id/stop',async c=>{await updateJob(c.req.param('id'),{stopRequested:true});return c.json({ok:true})});
app.post('/api/jobs/:id/retry',async c=>{const job=await retryJob(c.req.param('id'));if(!job)return c.json({ok:false,error:'Job cannot be retried'},409);await enqueueJob(job,p=>c.executionCtx.waitUntil(p));return c.json({ok:true,job})});
app.delete('/api/jobs/:id',async c=>c.json({ok:await deleteJob(c.req.param('id'))}));
app.delete('/api/jobs',async c=>c.json({ok:true,deleted:await clearFinishedJobs()}));

// Stable compatibility routes retained for clients of the first Worker port.
app.get('/legacy/profiles',async c=>c.json({ok:true,data:await listProfiles()}));
app.get('/legacy/profiles/:id/products',async c=>c.json({ok:true,data:(await listProducts(c.req.param('id'),500,0,'')).products}));
app.get('/legacy/jobs',async c=>c.json({ok:true,data:await listJobs(100)}));
app.post('/legacy/profiles',async c=>c.json({ok:true,data:await saveProfile(normalizeProfile(await c.req.json()))}));
app.post('/legacy/profiles/:id/extract',async c=>createProfileJob(c,c.req.param('id'),'scrape'));
app.post('/legacy/profiles/:id/sync',async c=>createProfileJob(c,c.req.param('id'),'sync'));
app.get('/legacy/backup',async c=>c.json({ok:true,data:await createBackup()}));
app.post('/legacy/restore',async c=>c.json({ok:true,data:await restoreBackup(await c.req.json())}));

async function createProfileJob(c:any,id:string,kind:'scrape'|'sync'){const profile=await getProfile(id);if(!profile)return c.json({ok:false,error:'Profile not found'},404);const b=await c.req.json().catch(()=>({})),target=validTarget(b.target||(kind==='sync'?'both':'none')),job=await createJob(profile.id,kind,target);if(job.status==='queued')await enqueueJob(job,(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise));return c.json({ok:true,job},202)}
async function jsonBody(c:any):Promise<any>{return c.req.json().catch(()=>({}))}
function validTarget(value:string):'none'|'woo'|'basalam'|'both'{return['none','woo','basalam','both'].includes(value)?value as any:'none'}
function validDestination(value:string):'woo'|'basalam'{if(value!=='woo'&&value!=='basalam')throw new Error('Invalid target');return value}
function csvCell(value:unknown){const text=value&&typeof value==='object'?JSON.stringify(value):String(value??'');return`"${text.replace(/"/g,'""')}"`}
function jsonValue<T>(value:unknown,fallback:T):T{if(value&&typeof value==='object')return value as T;try{return JSON.parse(String(value||'')) as T}catch{return fallback}}
function parseCsv(text:string):Record<string,string>[]{const rows:string[][]=[];let row:string[]=[],cell='',quoted=false;const input=text.replace(/^\uFEFF/,'');for(let i=0;i<input.length;i++){const ch=input[i];if(quoted){if(ch==='"'&&input[i+1]==='"'){cell+='"';i++}else if(ch==='"')quoted=false;else cell+=ch}else if(ch==='"')quoted=true;else if(ch===','){row.push(cell);cell=''}else if(ch==='\n'){row.push(cell);rows.push(row);row=[];cell=''}else if(ch!=='\r')cell+=ch}if(cell||row.length){row.push(cell);rows.push(row)}const headers=rows.shift()?.map(x=>x.trim())||[];return rows.filter(x=>x.some(Boolean)).map(values=>Object.fromEntries(headers.map((key,i)=>[key,values[i]||''])))}
function idFromUrl(raw:string):string{const url=new URL(raw),id=`${url.hostname}_${decodeURIComponent(url.pathname)}`.toLowerCase().replace(/[^\p{L}\p{N}_.-]+/gu,'_').replace(/^_+|_+$/g,'').slice(0,120);return id||crypto.randomUUID()}
function normalizeProfile(raw:any):Profile {
  const sync=typeof raw.syncConfig==='string'?jsonValue<Record<string,any>>(raw.syncConfig,{}):raw.syncConfig||{};
  const on=(value:unknown)=>[true,1,'1','true','on'].includes(value as any);
  const noExtract=on(raw.noExtract??sync.noExtract);
  const rawUrl=String(raw.url||'').trim()||(noExtract?`https://import.invalid/${encodeURIComponent(String(raw.id||raw.key||'products'))}`:'');
  const url=new URL(rawUrl);
  if(!['http:','https:'].includes(url.protocol))throw new Error('Invalid profile URL');
  const previousCreated=String(raw.createdAt||raw.created_at||new Date().toISOString());
  const detail=typeof raw.detailSelectors==='string'?jsonValue<Record<string,string>>(raw.detailSelectors,{}):raw.detailSelectors||{};
  const selectors={...DEFAULT_SELECTORS,...(typeof raw.selectors==='string'?jsonValue(raw.selectors,{}):raw.selectors||{}),...detail};
  if(raw.gallery?.selectors&&!selectors.gallery)selectors.gallery=raw.gallery.selectors;
  if(!selectors.container){if(noExtract)selectors.container='body';else throw new Error('selectors.container is required')}
  const pagination=String(raw.pagination||raw.pagType||'query_page') as Profile['pagination'];
  const target=String(sync.target||'');
  const indirect=on(raw.networkIndirect??raw.net_indirect);
  const fallbackIds=raw.basalamFallbackCategoryIds??raw.bslFallbackCatIds;
  return {
    id:String(raw.id||raw.key||idFromUrl(url.href)),name:String(raw.name||url.hostname),url:url.href,enabled:raw.enabled===undefined?true:on(raw.enabled),
    pages:Math.min(100,Math.max(1,Number(raw.pages)||1)),
    pagination:['query_page','query_custom','path_page','path_pattern','full_pattern','next_selector','none'].includes(pagination)?pagination:'query_page',
    paginationValue:String(raw.paginationValue||raw.pagVal||'page'),selectors,titleSuffix:String(raw.titleSuffix||''),
    priceMode:['none','add','percent','multiply'].includes(raw.priceMode)?raw.priceMode:'none',priceValue:Number(raw.priceValue??raw.priceVal)||0,
    roundPrice:Math.max(0,Number(raw.roundPrice)||0),minPrice:Math.max(0,Number(raw.minPrice)||0),wooCategoryId:Number(raw.wooCategoryId)||0,
    basalamCategoryId:Number(raw.basalamCategoryId??raw.bslCategoryId)||0,
    basalamFallbackCategoryIds:Array.isArray(fallbackIds)?fallbackIds.map(Number).filter(Boolean):[],networkIndirect:indirect,noExtract,
    syncWoo:raw.syncWoo===undefined?on(sync.enabled)&&['woo','both'].includes(target):on(raw.syncWoo),
    syncBasalam:raw.syncBasalam===undefined?on(sync.enabled)&&['basalam','bsl','both'].includes(target):on(raw.syncBasalam),
    intervalMinutes:Math.max(0,Number(raw.intervalMinutes??(sync.enabled?Math.ceil(Number(sync.interval||0)/60):raw.interval))||0),
    lastRunAt:raw.lastRunAt||null,createdAt:previousCreated,updatedAt:new Date().toISOString()
  };
}
function legacyProducts(raw:unknown):Product[]{const entries:Array<[string,any]>=[];if(Array.isArray(raw))for(const item of raw){if(Array.isArray(item)&&item.length>=2)entries.push([String(item[0]),item[1]]);else if(item&&typeof item==='object')entries.push([String((item as any).sourceKey||(item as any).key||crypto.randomUUID()),item])}else if(raw&&typeof raw==='object')for(const[key,value]of Object.entries(raw as Record<string,any>))entries.push([key,value]);return entries.filter(([,p])=>p&&p.title).map(([key,p])=>{const images=Array.isArray(p.images)?p.images.filter((x:unknown)=>typeof x==='string'&&!String(x).startsWith('data:')):[],image=String(p.image||images[0]||'');if(image&&!images.includes(image)&&!image.startsWith('data:'))images.unshift(image);return{sourceKey:key,title:String(p.title),price:numberFromText(String(p.finalPrice??p.price??0)),priceText:String(p.priceText??p.price??''),url:String(p.url||p.link||''),image:image.startsWith('data:')?'':image,images,shortDesc:String(p.shortDesc||''),longDesc:String(p.longDesc||''),sku:String(p.sku||''),brand:String(p.brand||''),stock:p.stock==null?undefined:Number(p.stock),weight:p.weight==null?undefined:Number(p.weight),category:String(p.category||''),variations:Array.isArray(p.variations)?p.variations.map(String):[],variationGroups:Array.isArray(p.variationGroups||p.variation_groups)?(p.variationGroups||p.variation_groups):[],variationPrices:p.variationPrices||p.variation_prices||{},sourcePage:String(p.sourcePage||''),scrapedAt:new Date().toISOString()}})}
async function importPhpSettings(bundle:any){const files=decodePhpSettingsBundle(bundle);let profiles=0,products=0,states=0,categories=0,autoreplyLogs=0,connections=false;const warnings:string[]=[];const rawProfiles=files['profiles.json'];if(rawProfiles&&typeof rawProfiles==='object')for(const[id,raw]of Object.entries(rawProfiles as Record<string,any>))try{const profile=normalizeProfile({...raw,id});await saveProfile(profile);profiles++;for(const product of legacyProducts(raw?.products)){await upsertProduct(profile.id,product);products++}}catch(error){warnings.push(`${id}: ${message(error)}`)}const rawConnections=files['connections.json'] as any;if(rawConnections){const woo=rawConnections.woocommerce||rawConnections.woo||{},basalam=rawConnections.basalam||{},ai=rawConnections.ai||{};await saveConnections({woo:{url:woo.url||woo.store_url||'',key:woo.consumer_key||woo.ck||woo.key||'',secret:woo.consumer_secret||woo.cs||woo.secret||'',categoryId:woo.category_id||0},basalam:{token:basalam.token||'',vendorId:String(basalam.vendor_id||basalam.vendorId||''),api:basalam.api_base||basalam.api||'https://openapi.basalam.com/v1',preparationDays:basalam.preparation_days,weight:basalam.weight,packageWeight:basalam.package_weight,stock:basalam.stock,categoryId:basalam.category_id,fallbackCategoryIds:basalam.fallback_cat_ids,autoCategory:basalam.auto_category,netIndirect:basalam.net_indirect,shops:basalam.shops||((basalam.vendors||[]).map((shop:any)=>({name:shop.name||shop.shop_name||'',token:shop.token||'',vendorId:String(shop.vendor_id||shop.vendorId||''),pricePercent:shop.price_mode==='percent'?Number(shop.price_val||0):0})))},ai:{baseUrl:ai.base_url||ai.baseUrl||'',apiKey:ai.api_key||ai.apiKey||'',model:ai.model||'',providers:ai.providers,candidates:ai.candidates,master:ai.master,network:ai.network||(rawConnections.src_network?{mode:rawConnections.src_network.mode,proxyUrl:rawConnections.src_network.proxy||'',workerUrl:rawConnections.src_network.worker_url||'',dohUrl:rawConnections.src_network.doh_url||'',resolveIp:rawConnections.src_network.resolve_ip||''}:undefined)},notifications:rawConnections.notifications||{}});connections=true}if(files['category_learning.json'])categories=await importCategoryLearning(files['category_learning.json']);if(files['autoreply_log.json'])autoreplyLogs=await importAutoreplyLog(files['autoreply_log.json']);for(const[file,value]of Object.entries(files)){const key=stateKeyForFile(file);if(key){await setState(key,value);states++}}return{ok:true,format:'scraper4-php-compatible',imported:{profiles,products,states,categories,autoreplyLogs,connections},warnings}}

export async function scheduledTasks(env:Env,waitUntil:(promise:Promise<unknown>)=>void):Promise<void>{configureEnv(env);await ensureSchema(env.DB);await reapStalledJobs(30);const due=await enqueueDueProfiles(),queued=(await listJobs(200)).filter(job=>job.status==='queued'),seen=new Set<string>();for(const job of [...due,...queued])if(!seen.has(job.id)){seen.add(job.id);await enqueueJob(job,waitUntil)}waitUntil(automationTick())}
