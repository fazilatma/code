import { Hono } from 'hono';
import { secureHeaders } from 'hono/secure-headers';
import readXlsxFile from 'read-excel-file/web-worker';
import { aiCall, aiChat, aiProviders, getLastAiTestResults, getLeaderboard, isChatCompatibleAiModel, isReasoningAiModel, parseModelKeySuffix, providerKeys, providerWithKey, recordVote, suggestCategoryWithModel, testModelBatch } from './ai.js';
import { AGENT_PROMPT_TEMPLATES, AGENT_TOOLS, AGENT_TOOL_MODELS, agentCronTick, agentModelSetupHint, controlAgentRun, createOrUpdateAgentPrompt, currentAgentRun, getAgentRunPublic, listAgentRunsPublic, publicAgentRun, removeAgentPrompt, resetAgentRun, startAgentRun } from './agent.js';
import { automationTick, autoreplyLogs, autoreplyRun, basalamChatMessagesOverview, basalamChatsOverview, basalamOrders, digest, generateReply } from './automation.js';
import { connectionStatus, loadConnections, saveConnections } from './connections.js';
import { DASHBOARD, DASHBOARD_JS } from './dashboard.js';
import { allProducts, clearFinishedJobs, createBackup, createJob, deleteJob, deleteProfile, enqueueDueProfiles, ensureSchema, findLearnedCategory, getJob, getProduct, getProfile, getState, getTriedBasalamCategories, importAutoreplyLog, importCategoryLearning, learnCategory, listCategoryLearning, listJobs, listProducts, listProfiles, markBasalamCategoriesTried, profileStats, pruneFinishedJobs, reapStalledJobs, restoreBackup, retryJob, saveProfile, setState, updateJob, upsertProduct } from './db.js';
import { configureEnv, type Env } from './env.js';
import { bulkEdit, destinationBulkEdit, destinationCatalog, destinationCategories, destinationChangeStatus, destinationDelete, destinationOverview, destinationProduct, destinationUpdate, findDestinationDuplicates, photoFix, rebuildMap, recon, retire } from './maintenance.js';
import { safeFetch, safeText, safeWooFetch } from './network.js';
import { sendNotification } from './notifications.js';
import { PHP_MENU_CAPABILITIES, runSelftest } from './parity.js';
import { runDiagnostics } from './diagnostics.js';
import { enqueueJob } from './processor.js';
import { diagnoseExtraction, numberFromText, suggestSelectors, testGallery, testSelector, testVariations } from './scraper.js';
import { createPhpSettingsBundle, decodePhpSettingsBundle, stateKeyForFile } from './settings-transfer.js';
import { syncBasalam, syncWoo } from './sync.js';
import { DEFAULT_SELECTORS, type Product, type Profile } from './types.js';
import { basicAuth, byteLength, escapeHtml, message } from './utils.js';
import { createVisualTicket, renderVisualSelector } from './visual.js';
import { controlBackgroundRun, getPublicBackgroundRun, recoverBackgroundRuns, resetBackgroundRun, retryAiTestPart, startAiTestRun, startAllUnapprovedCategoryRun, startDedupRun } from './background.js';
import { fontFile, fontStylesheet } from './fonts.js';

type Variables={requestId:string};
export const app=new Hono<{Bindings:Env;Variables:Variables}>();
const dashboardSecurity=secureHeaders({contentSecurityPolicy:{defaultSrc:["'self'"],scriptSrc:["'self'"],styleSrc:["'self'","'unsafe-inline'"],fontSrc:["'self'",'data:'],connectSrc:["'self'"],imgSrc:["'self'",'data:','https:'],objectSrc:["'none'"],frameAncestors:["'none'"]},referrerPolicy:'no-referrer'});
app.use('*',async(c,next)=>{configureEnv(c.env);c.set('requestId',crypto.randomUUID());c.header('x-content-type-options','nosniff');await next()});
app.use('*',async(c,next)=>c.req.path==='/visual'?next():dashboardSecurity(c,next));
app.onError((error,c)=>{console.error(JSON.stringify({requestId:c.get('requestId'),path:c.req.path,error:message(error)}));const text=message(error),status=/Unauthorized/.test(text)?401:/not found/i.test(text)?404:/Response exceeds|بیش از.*بایت|حداکثر.*مگابایت|too large/i.test(text)?413:/timeout|مهلت دریافت/i.test(text)?504:/invalid|required|empty|خالی|نامعتبر/i.test(text)?400:/HTTP|fetch|network|اتصال/i.test(text)?502:500;return c.json({ok:false,error:text,requestId:c.get('requestId')},status as any)});

app.get('/health',c=>c.json({ok:true,app:'scraper4-cloudflare',runtime:'cloudflare-workers',databaseReady:Boolean(c.env.DB),databaseError:c.env.DB?null:'D1 binding DB is missing',workerInWeb:Boolean(c.env.JOBS),authenticationRequired:false,version:c.env.WORKER_VERSION||'1.23.0',time:new Date().toISOString()}));
app.get('/',async c=>{await ensureSchema(c.env.DB);return c.html(DASHBOARD)});
app.get('/dashboard.js',c=>c.body(DASHBOARD_JS,200,{'content-type':'application/javascript; charset=utf-8','cache-control':'no-store'}));
app.get('/assets/fonts/:file',async c=>{const file=c.req.param('file'),css=file.match(/^([a-z]+)\.css$/i),woff=file.match(/^([a-z]+)-(\d+)\.woff2$/i);if(css)return fontStylesheet(css[1]);return woff?fontFile(woff[1],woff[2]):c.notFound()});
app.get('/visual',async c=>renderVisualSelector(c.req.query('ticket')||'',c.req.query('context')==='detail'?'detail':'list'));
app.use('/api/*',async(c,next)=>{if(!c.env.DB)return c.json({ok:false,error:'D1 binding DB is not configured'},503);await ensureSchema(c.env.DB);await next()});

app.post('/api/visual-ticket',async c=>{const body=await c.req.json() as any,url=new URL(String(body.url||''));if(!['http:','https:'].includes(url.protocol))return c.json({ok:false,error:'Invalid visual selector URL'},400);return c.json({ok:true,ticket:await createVisualTicket(url.href),expiresIn:300})});
app.get('/api/status',async c=>{const connections=await loadConnections();return c.json({ok:true,profiles:(await listProfiles()).length,jobs:await listJobs(10),connections:connectionStatus(connections),queue:Boolean(c.env.JOBS),storage:{d1:true,r2:Boolean(c.env.BACKUPS)}})});
app.get('/api/selftest',async c=>c.json(await runSelftest()));
app.get('/api/debug',async c=>c.json(await runDiagnostics()));
app.get('/api/parity',c=>c.json({ok:true,total:PHP_MENU_CAPABILITIES.length,capabilities:PHP_MENU_CAPABILITIES,dispatcherAudit:{reference:'scraper4.php v9.80',total:178,get:150,post:28,mapped:178,missing:0,artifact:'parity-manifest.json'}}));
app.get('/api/version',c=>c.json({ok:true,version:c.env.WORKER_VERSION||'1.23.0',runtime:'cloudflare-workers',deployment:'wrangler versions deploy / wrangler rollback'}));
app.get('/api/connections',async c=>c.json({ok:true,connections:await loadConnections(true)}));
app.post('/api/connections',async c=>c.json({ok:true,connections:await saveConnections(await c.req.json())}));
app.get('/api/ai/providers',async c=>c.json({ok:true,providers:await aiProviders(),leaderboard:await getLeaderboard()}));
app.post('/api/ai/test-all',async c=>{const b=await jsonBody(c),started=Date.now(),categoryTitle=String(b.categoryTitle||'').trim();let categories:any[]=[];if(categoryTitle)try{categories=(await destinationCategories(Boolean(b.refreshCategories))).items}catch{/* پیام و مدل‌ها حتی بدون اتصال باسلام تست می‌شوند */}const result=await testModelBatch(String(b.prompt||'سلام'),{onlyCandidates:Boolean(b.onlyCandidates),cursor:Number(b.cursor)||0,runId:String(b.runId||''),categoryTitle,categories,skipCurrent:Boolean(b.skipCurrent),skipReason:String(b.skipReason||'')});return c.json({...result,durationMs:Date.now()-started,categoryListAvailable:categories.length>0,invocationPolicy:'در هر invocation مدل هم‌ردیف همهٔ ارائه‌دهنده‌ها همزمان آزمایش می‌شود (حداکثر یکی از هر ارائه‌دهنده) تا فهرست سریع‌تر تمام شود و محدودیت نرخ رخ ندهد'})});
app.post('/api/ai/test-runs',async c=>{const started=await startAiTestRun(await jsonBody(c),(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise));return c.json({ok:true,...started},started.existing?200:202)});
app.get('/api/ai/test-runs/current',async c=>{await recoverBackgroundRuns(promise=>c.executionCtx.waitUntil(promise));return c.json({ok:true,run:await getPublicBackgroundRun('ai-test')})});
app.post('/api/ai/test-runs/control',async c=>{const b=await jsonBody(c),action=String(b.action)==='resume'?'resume':'stop';return c.json({ok:true,run:await controlBackgroundRun('ai-test',action,(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise))})});
app.post('/api/ai/test-runs/reset',async c=>{await resetBackgroundRun('ai-test');return c.json({ok:true,run:await getPublicBackgroundRun('ai-test')})});
app.post('/api/ai/test-runs/retry',async c=>{const b=await jsonBody(c),part=String(b.part)==='category'?'category':'message';return c.json({part,...await retryAiTestPart(String(b.key||''),part)})});
app.get('/api/ai/test-results',async c=>c.json({ok:true,...await getLastAiTestResults()}));
app.post('/api/ai/call',async c=>{const b=await jsonBody(c),provider=(await aiProviders()).find(p=>p.id===b.provider);if(!provider)return c.json({ok:false,error:'Provider not found'},404);const{model,keyIndex}=parseModelKeySuffix(String(b.model||''));return c.json(await aiCall(providerWithKey(provider,keyIndex),model,String(b.prompt||'سلام')))});
app.post('/api/ai/vote',async c=>{const b=await jsonBody(c);return c.json({ok:true,leaderboard:await recordVote(String(b.task||'manual'),String(b.winner||''),Array.isArray(b.candidates)?b.candidates.map(String):[])})});
app.get('/api/ai/leaderboard',async c=>c.json({ok:true,leaderboard:await getLeaderboard()}));
// ─── AI chat with capability-filtered model picker ───────────────────────────
app.get('/api/ai/chat-models',async c=>{
  const providers=(await aiProviders()).filter(p=>p.enabled!==false);
  const toolIds=new Set(AGENT_TOOL_MODELS.filter(m=>m.id!=='*configured').map(m=>m.id));
  const models:any[]=[];
  for(const p of providers)for(const model of p.models||[])models.push({providerId:p.id,providerName:p.name,model,chat:isChatCompatibleAiModel(p,model),toolCalling:toolIds.has(model),reasoning:isReasoningAiModel(p,model),keyCount:Math.max(1,providerKeys(p).length)});
  return c.json({ok:true,models:models.sort((a,b)=>String(a.providerName).localeCompare(String(b.providerName))||a.model.localeCompare(b.model))});
});
app.post('/api/ai/chat',async c=>{
  const b=await jsonBody(c),provider=(await aiProviders()).find(p=>p.id===String(b.providerId||''));
  if(!provider)return c.json({ok:false,error:'ارائه‌دهنده پیدا نشد.'},404);
  const model=String(b.model||'').trim();if(!model)return c.json({ok:false,error:'نام مدل لازم است.'},400);
  const{model:cleanModel,keyIndex}=parseModelKeySuffix(model);
  const messages=(Array.isArray(b.messages)?b.messages:[]).slice(-40).map((m:{role?:unknown;content?:unknown})=>({role:String(m.role||'user'),content:String(m.content??'')})).filter((m:{content:string})=>m.content);
  if(!messages.length||messages[messages.length-1].role!=='user')return c.json({ok:false,error:'آخرین پیام باید از سمت کاربر باشد.'},400);
  return c.json(await aiChat(providerWithKey(provider,keyIndex),cleanModel,messages,undefined,undefined,undefined,keyIndex));
});


// ─── Agentic AI: tool-calling models, prompts, scheduled runs and logs ───────
app.get('/api/ai/workers-catalog',async c=>{const{workersAiTaskGroups}=await import('./workers-ai-catalog.js');return c.json({ok:true,groups:workersAiTaskGroups(),total:(await import('./workers-ai-catalog.js')).WORKERS_AI_MODELS.length})});
app.get('/api/agent/models',async c=>{const providers=(await aiProviders()).filter(p=>p.enabled!==false);const configured=providers.flatMap(p=>(p.models||[]).map(model=>({providerId:p.id,providerName:p.name,model,keyCount:Math.max(1,providerKeys(p).length)})));return c.json({ok:true,models:AGENT_TOOL_MODELS,configured,setupHint:await agentModelSetupHint()})});
app.get('/api/agent/templates',c=>c.json({ok:true,templates:AGENT_PROMPT_TEMPLATES}));
app.get('/api/agent/tasks',c=>c.json({ok:true,tools:AGENT_TOOLS}));
app.get('/api/agent/prompts',async c=>{const{listAgentPrompts}=await import('./db.js');return c.json({ok:true,items:(await listAgentPrompts()).map(row=>({id:row.id,name:row.name,description:row.description,prompt:row.prompt,tools:JSON.parse(row.tools||'[]'),scheduleMinutes:row.scheduleMinutes,modelKey:row.modelKey,enabled:row.enabled,maxSteps:row.maxSteps,lastRunAt:row.lastRunAt,createdAt:row.createdAt,updatedAt:row.updatedAt}))})});
app.post('/api/agent/prompts',async c=>c.json({ok:true,prompt:await createOrUpdateAgentPrompt(await jsonBody(c))}));
app.delete('/api/agent/prompts/:id',async c=>{await removeAgentPrompt(c.req.param('id'));return c.json({ok:true})});
app.post('/api/agent/prompts/:id/run',async c=>{const started=await startAgentRun({promptId:c.req.param('id')},(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise));return c.json({ok:true,...started},started.existing?200:202)});
app.post('/api/agent/runs',async c=>{const started=await startAgentRun(await jsonBody(c),(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise));return c.json({ok:true,...started},started.existing?200:202)});
app.get('/api/agent/runs',async c=>c.json({ok:true,items:await listAgentRunsPublic(Math.min(100,Number(c.req.query('limit'))||40))}));
app.get('/api/agent/runs/current',async c=>c.json({ok:true,run:publicAgentRun(await currentAgentRun())}));
app.post('/api/agent/runs/control',async c=>{const b=await jsonBody(c),action=String(b.action)==='resume'?'resume':'stop';return c.json({ok:true,run:await controlAgentRun(action,(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise))})});
app.post('/api/agent/runs/reset',async c=>{await resetAgentRun();return c.json({ok:true,run:null})});
app.get('/api/agent/runs/:id',async c=>c.json({ok:true,run:await getAgentRunPublic(c.req.param('id'))}));
app.delete('/api/agent/runs/:id',async c=>{const{deleteAgentRun}=await import('./db.js');await deleteAgentRun(c.req.param('id'));return c.json({ok:true})});

app.post('/api/notifications/test',async c=>{const b=await jsonBody(c);return c.json(await sendNotification(b.channel||'webhook',String(b.text||'پیام آزمایشی اسکرپر ۴ از Cloudflare Workers')))});
app.get('/api/category-learning',async c=>c.json({ok:true,items:await listCategoryLearning(Math.min(5000,Number(c.req.query('limit'))||1000))}));
app.post('/api/category-learning/record',async c=>{const b=await jsonBody(c);return c.json({ok:true,saved:await learnCategory(String(b.title||''),Number(b.categoryId),String(b.categoryName||''),Number(b.maxWords)||5)})});
app.post('/api/category-learning/test',async c=>{const b=await jsonBody(c);return c.json({ok:true,result:await findLearnedCategory(String(b.title||''),Number(b.maxWords)||5)})});
app.post('/api/category-learning/import',async c=>c.json({ok:true,imported:await importCategoryLearning(await c.req.json())}));
app.post('/api/autoreply/test',async c=>{const b=await jsonBody(c);return c.json({ok:true,result:await generateReply(String(b.text||''))})});
app.post('/api/autoreply/run',async c=>{const b=await jsonBody(c);return c.json(await autoreplyRun(b.confirm!=='APPLY'))});
app.get('/api/autoreply/log',async c=>c.json({ok:true,items:await autoreplyLogs()}));
app.post('/api/digest',async c=>{const b=await jsonBody(c);return c.json(await digest(b.confirm!=='SEND'))});
app.get('/api/basalam/chats',async c=>c.json(await basalamChatsOverview(Number(c.req.query('limit'))||50)));
app.get('/api/basalam/chats/:id/messages',async c=>c.json(await basalamChatMessagesOverview(Number(c.req.param('id')),Number(c.req.query('limit'))||50)));
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
app.get('/api/destination/:target/products',async c=>{const target=validDestination(c.req.param('target')),legacyLimit=Math.min(100,Number(c.req.query('limit'))||25),legacyOffset=Math.max(0,Number(c.req.query('offset'))||0),perPage=Math.min(100,Number(c.req.query('per_page'))||legacyLimit),page=Math.max(1,Number(c.req.query('page'))||Math.floor(legacyOffset/perPage)+1),catalog=await destinationCatalog(target,{page,perPage,q:c.req.query('q')||'',status:c.req.query('status')||'all',shopId:c.req.query('shop')||'all',counts:c.req.query('counts')==='1'});const{products,...meta}=catalog;return c.json({...meta,items:products})});
app.get('/api/destination/:target/overview',async c=>c.json({ok:true,...await destinationOverview(validDestination(c.req.param('target')))}));
app.get('/api/destination/:target/duplicates',async c=>c.json({ok:true,groups:await findDestinationDuplicates(validDestination(c.req.param('target')),{keep:c.req.query('keep')||'',suffixFormats:c.req.query('suffix')||''})}));
// Server-side duplicate cleanup runs on the Queue with D1 checkpoints, so long shops never hit browser/network timeouts.
app.post('/api/destination/:target/dedup-runs',async c=>{const target=validDestination(c.req.param('target')),b=await jsonBody(c);const started=await startDedupRun(target,b,(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise));return c.json({ok:true,...started},started.existing?200:202)});
app.get('/api/destination/:target/dedup-runs/current',async c=>{validDestination(c.req.param('target'));await recoverBackgroundRuns(promise=>c.executionCtx.waitUntil(promise));return c.json({ok:true,run:await getPublicBackgroundRun('dedup')})});
app.post('/api/destination/:target/dedup-runs/control',async c=>{validDestination(c.req.param('target'));const b=await jsonBody(c),action=String(b.action)==='resume'?'resume':'stop';return c.json({ok:true,run:await controlBackgroundRun('dedup',action,(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise))})});
app.post('/api/destination/:target/dedup-runs/reset',async c=>{validDestination(c.req.param('target'));await resetBackgroundRun('dedup');return c.json({ok:true,run:await getPublicBackgroundRun('dedup')})});
app.get('/api/destination/:target/product/:id',async c=>c.json({ok:true,product:await destinationProduct(validDestination(c.req.param('target')),Number(c.req.param('id')),c.req.query('shop')||'')}));
app.post('/api/destination/:target/bulk',async c=>{const target=validDestination(c.req.param('target')),b=await jsonBody(c);if(Array.isArray(b.ids)&&b.ids.length>20)return c.json({ok:false,error:'در هر نوبت حداکثر ۲۰ محصول قابل ویرایش است.'},400);return c.json(await destinationBulkEdit(target,b,b.confirm==='APPLY'))});
app.post('/api/destination/basalam/category/suggest',async c=>{const b=await jsonBody(c),title=String(b.title||'').trim(),mode=String(b.mode||'learned');if(!title)return c.json({ok:false,error:'عنوان محصول خالی است.'},400);if(mode==='learned')return c.json({ok:true,mode,result:await findLearnedCategory(title,Number(b.maxWords)||5)});if(mode!=='ai')return c.json({ok:false,error:'روش پیشنهاد دسته‌بندی نامعتبر است.'},400);const categories=(await destinationCategories(Boolean(b.refreshCategories))).items,result=await suggestCategoryWithModel(title,String(b.modelKey||''),categories);return c.json({mode,categories:categories.length,...result})});
// Tried-category memory for the bulk Basalam category fix (avoids repeating failed suggestions).
app.get('/api/destination/basalam/category-tried',async c=>{const shopId=String(c.req.query('shopId')||''),id=Number(c.req.query('id'));return c.json({ok:true,tried:await getTriedBasalamCategories(shopId,id)})});
app.post('/api/destination/basalam/category-tried',async c=>{const b=await jsonBody(c);return c.json({ok:true,tried:await markBasalamCategoriesTried(String(b.shopId||''),Number(b.id),Array.isArray(b.ids)?b.ids:[])})});
app.post('/api/destination/basalam/category-runs',async c=>{const started=await startAllUnapprovedCategoryRun((promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise));return c.json({ok:true,...started},started.existing?200:202)});
app.get('/api/destination/basalam/category-runs/current',async c=>c.json({ok:true,run:await getPublicBackgroundRun('category-all')}));
app.post('/api/destination/basalam/category-runs/control',async c=>{const b=await jsonBody(c),action=String(b.action)==='resume'?'resume':'stop';return c.json({ok:true,run:await controlBackgroundRun('category-all',action,(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise))})});
app.post('/api/destination/:target/:id/update',async c=>{const target=validDestination(c.req.param('target')),b=await jsonBody(c);return c.json(await destinationUpdate(target,Number(c.req.param('id')),b,b.confirm==='APPLY',String(b.shopId||'')))});
app.post('/api/destination/:target/:id/status',async c=>{const b=await jsonBody(c);if(b.confirm!=='APPLY')return c.json({ok:false,error:'برای اعمال واقعی عبارت APPLY لازم است.'},400);return c.json(await destinationChangeStatus(validDestination(c.req.param('target')),Number(c.req.param('id')),String(b.status||''),String(b.shopId||'')))});
app.delete('/api/destination/:target/:id',async c=>{if(c.req.query('confirm')!=='DELETE')return c.json({ok:false,error:'برای حذف یا بایگانی، تأیید DELETE لازم است.'},400);return c.json(await destinationDelete(validDestination(c.req.param('target')),Number(c.req.param('id')),c.req.query('force')==='true',c.req.query('shop')||''))});
app.post('/api/products/:profileId/:sourceKey/sync/:target',async c=>{const profile=await getProfile(c.req.param('profileId')),product=await getProduct(c.req.param('profileId'),c.req.param('sourceKey')),target=validDestination(c.req.param('target'));if(!profile||!product)return c.json({ok:false,error:'Product/profile not found'},404);return c.json({ok:true,result:target==='woo'?await syncWoo(product,profile):await syncBasalam(product,profile)})});

app.post('/api/queue-watchdog',async c=>{const b=await jsonBody(c);const settings=await getState<any>('settings',{}),stallMin=Number(b.minutes)>0?Number(b.minutes):Math.max(1,Math.ceil(Number(settings.watchdog?.stallAfter||300)/60));await recoverBackgroundRuns(promise=>c.executionCtx.waitUntil(promise));return c.json({ok:true,reaped:await reapStalledJobs(stallMin),backgroundRecovered:true,stallMinutes:stallMin})});
app.post('/api/source-test',async c=>{const b=await jsonBody(c),result=await safeText(String(b.url||''),1_000_000);return c.json({ok:true,bytes:byteLength(result.text),url:result.url,title:(result.text.match(/<title[^>]*>(.*?)<\/title>/is)?.[1]||'').replace(/<[^>]+>/g,'').trim()})});
app.post('/api/profiles/:id/extraction-diagnostic',async c=>{const profile=await getProfile(c.req.param('id'));if(!profile)return c.json({ok:false,error:'پروفایل پیدا نشد.'},404);const b=await jsonBody(c);return c.json(await diagnoseExtraction(profile,String(b.url||'')))});
app.post('/api/test-selector',async c=>{const b=await jsonBody(c);if(b.type==='variations')return c.json({ok:true,...await testVariations(String(b.url||''),String(b.selector||''))});if(b.type==='gallery')return c.json({ok:true,...await testGallery(String(b.url||''),String(b.selector||''),Number(b.max)||30,Boolean(b.skipFirst))});return c.json({ok:true,...await testSelector(String(b.url||''),String(b.selector||''),String(b.type||'text'))})});
app.post('/api/suggest-selectors',async c=>{const b=await jsonBody(c),mode=['list','detail'].includes(b.mode)?b.mode:'all';return c.json({ok:true,...await suggestSelectors(String(b.url||''),mode)})});
app.post('/api/test-connection/:target',async c=>{const target=c.req.param('target'),input=await jsonBody(c);return c.json(await connectionDiagnostic(target,input))});
app.get('/api/categories/:target',async c=>{const target=validDestination(c.req.param('target')),connections=await loadConnections();if(target==='woo'){const x=connections.woo;if(!x.url||!x.key||!x.secret)return c.json({ok:false,error:'اتصال ووکامرس کامل نیست'},400);const items:any[]=[];for(let page=1;page<=20;page++){const r=await safeWooFetch(`${x.url}/wp-json/wc/v3/products/categories?per_page=100&page=${page}`,{headers:{authorization:basicAuth(x.key,x.secret),accept:'application/json'}},3_000_000),found=await r.json() as any[];if(!r.ok)throw new Error(`Woo HTTP ${r.status}`);items.push(...found);if(found.length<100)break}return c.json({ok:true,items})}const result=await destinationCategories(c.req.query('refresh')==='1');return c.json({ok:true,...result,total:result.items.length})});

app.get('/api/profiles',async c=>c.json({ok:true,profiles:await listProfiles()}));
app.post('/api/profiles',async c=>c.json({ok:true,profile:await saveProfile(normalizeProfile(await c.req.json()))}));
app.delete('/api/profiles/:id',async c=>c.json({ok:await deleteProfile(c.req.param('id'))}));
app.post('/api/profiles/:id/scrape',async c=>createProfileJob(c,c.req.param('id'),'scrape'));
app.post('/api/profiles/:id/sync',async c=>createProfileJob(c,c.req.param('id'),'sync'));
app.get('/api/profiles/:id/products',async c=>c.json({ok:true,...await listProducts(c.req.param('id'),Math.min(500,Number(c.req.query('limit'))||100),Math.max(0,Number(c.req.query('offset'))||0),c.req.query('q')||'')}));
app.get('/api/profiles/:id/export.csv',async c=>{const result=await listProducts(c.req.param('id'),100000,0,''),fields=['sourceKey','title','price','url','image','sku','brand','stock','weight','category','shortDesc','longDesc','variations','variationGroups'],csv='\uFEFF'+fields.join(',')+'\n'+result.products.map(p=>fields.map(field=>csvCell((p as any)[field])).join(',')).join('\n');return c.body(csv,200,{'content-type':'text/csv; charset=utf-8','content-disposition':`attachment; filename="${c.req.param('id').replace(/[^a-z0-9_.-]/gi,'_')}.csv"`})});
app.post('/api/profiles/:id/import',async c=>{const profile=await getProfile(c.req.param('id'));if(!profile)return c.json({ok:false,error:'Profile not found'},404);const{records,format,wooStatus}=await importRecords(c);const opts=parseImportOptions(c.req.query('opts'));let imported=0,failed=0,skipped=0;const errors:string[]=[],mapping=opts.mapping,dedupe=String(opts.dedupe||'none');const lastTitle=new Map<string,number>();if(dedupe==='last'){for(let i=records.length-1;i>=0;i--){const rawRow=records[i],row=mapping&&Object.keys(mapping).length?applyImportMapping(rawRow,mapping):rawRow;const title=String(row.title||row.name||'').trim();if(title&&!lastTitle.has(title))lastTitle.set(title,i)}}const seenTitle=new Set<string>();for(const[index,rawRow]of records.entries())try{const row=mapping&&Object.keys(mapping).length?applyImportMapping(rawRow,mapping):rawRow;const title=String(row.title||row.name||'').trim();if(!title){if(opts.skipMissingTitle){skipped++;continue}throw new Error('title is empty')}if(dedupe==='first'&&seenTitle.has(title)){skipped++;continue}if(dedupe==='last'&&lastTitle.get(title)!==index){skipped++;continue}seenTitle.add(title);const priceRaw=String(row.price??row.priceText??'').trim();const price=normalizeImportPrice(priceRaw,opts);if(price===null){if(opts.skipMissingPrice){skipped++;continue}throw new Error(`invalid price "${priceRaw.slice(0,40)}"`)}const attrGroups:ImportAttrGroup[]=[];if(mapping&&Object.keys(mapping).length)for(const[col,field]of Object.entries(mapping)){if(field==='attributes'&&rawRow[col]!=null&&String(rawRow[col]).trim()!=='')attrGroups.push(...parseImportAttributes(rawRow[col],col))}const parsedGroups:Array<{name:string;values:string[]}>=Array.isArray(jsonValue(row.variationGroups,[]))?jsonValue(row.variationGroups,[]) as Array<{name:string;values:string[]}>:[];const variationGroups=mergeVariationGroups([...parsedGroups,...attrGroups]);const key=String(row.sourceKey||row.key||crypto.randomUUID()),image=String(row.image||'');await upsertProduct(profile.id,{sourceKey:key,title,price,priceText:priceRaw,url:String(row.url||row.link||''),image,images:image?[image]:[],sku:String(row.sku||''),brand:String(row.brand||''),stock:row.stock==null||String(row.stock).trim()===''?(opts.defaultStock>0?opts.defaultStock:undefined):Number(String(row.stock).replace(/[^\d.-]/g,'')),weight:row.weight==null?undefined:Number(String(row.weight).replace(/[^\d.-]/g,'')),category:String(row.category||''),shortDesc:String(row.shortDesc||''),longDesc:String(row.longDesc||''),variations:jsonValue(row.variations,[]),variationGroups,destinationStatus:wooStatus||undefined,sourcePage:'import',scrapedAt:new Date().toISOString()});imported++}catch(error){failed++;if(errors.length<50)errors.push(`row ${index+1}: ${message(error)}`)}await pushImportHistory({fileName:String(c.req.query('name')||'').slice(0,200),format,rows:records.length,imported,failed,skipped,wooStatus:wooStatus||undefined,opts,at:new Date().toISOString()});return c.json({ok:failed===0&&skipped<records.length,format,wooStatus,rows:records.length,imported,failed,skipped,errors})});

// ─── Advanced import: analyze file, column mapping, history ──────────────────
app.post('/api/import/analyze',async c=>{
  const file=await readImportFile(c),table=file.rows,headers=file.headers;
  const mapping=detectImportMapping(headers),mappingObj=Object.fromEntries(mapping.map(m=>[m.column,m.field]));
  const samples=table.slice(0,100).map(values=>Object.fromEntries(headers.map((h,i)=>[h,values[i]??''])));
  let missingTitle=0,missingPrice=0,zeroPrice=0,invalidPrice=0,limit=Math.min(10000,table.length);
  for(let i=0;i<limit;i++){const values=table[i],obj=Object.fromEntries(headers.map((h,j)=>[h,values[j]??''])),row=Object.keys(mappingObj).length?applyImportMapping(obj,mappingObj):normalizeImportRecord(obj),title=String(row.title||'').trim();if(!title)missingTitle++;else{const price=normalizeImportPrice(String(row.price??''),{priceUnit:'toman'});if(price===null)invalidPrice++;else if(price===0)zeroPrice++;else if(price<0)invalidPrice++}if(!String(row.price??'').trim())missingPrice++}
  const priceHint=file.rows.length?detectPriceUnit(samples):null;
  return c.json({ok:true,format:file.format,total:table.length,headers,mapping,samples,issues:{missingTitle,missingPrice,zeroPrice,invalidPrice,checked:Math.min(limit,table.length)},priceHint});
});
app.get('/api/import/history',async c=>c.json({ok:true,items:await getImportHistory()}));
app.post('/api/import/history/clear',async c=>{await setState('import_history',[]);return c.json({ok:true})});

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

async function connectionDiagnostic(target:string,input:any){
  const started=Date.now(),startedAt=new Date().toISOString(),connections=await loadConnections(true);
  try{
    if(target==='woo'){
      const x=connections.woo,endpoint=x.url.replace(/\/$/,'')+'/wp-json/wc/v3/products?per_page=1&status=any&_fields=id,name,status';
      if(!x.url||!x.key||!x.secret)return diagnosticConfigError(target,startedAt,started,'آدرس فروشگاه، Consumer Key و Consumer Secret را کامل کنید.',['آدرس باید با https:// شروع شود.','کلید خواندن/نوشتن را از ووکامرس ← تنظیمات ← پیشرفته ← REST API بسازید.']);
      const response=await safeWooFetch(endpoint,{headers:{authorization:basicAuth(x.key,x.secret),accept:'application/json'}},2_000_000),raw=await diagnosticBody(response),sample=Array.isArray(raw)?raw[0]||null:null,networkMode=response.headers.get('x-scraper-network-mode')||'direct',directStatus=Number(response.headers.get('x-scraper-direct-status')||0)||null;
      const recommendations=response.ok?[networkMode==='worker'&&directStatus?`اتصال مستقیم با خطای ${directStatus} روبه‌رو شد، اما مسیر Worker جایگزین با موفقیت پاسخ داد.`:'اتصال معتبر است و فهرست سبک محصولات ووکامرس پاسخ داد.','اکنون دسته‌ها را بارگذاری و یک محصول آزمایشی را با وضعیت پیش‌نویس ارسال کنید.']:connectionAdvice(response.status,'woo');
      return{ok:response.ok,target,service:'WooCommerce REST API',startedAt,durationMs:Date.now()-started,request:{method:'GET',endpoint,authentication:'Basic Auth (کلید در گزارش نمایش داده نمی‌شود)'},http:{status:response.status,statusText:response.statusText,contentType:response.headers.get('content-type')||'',finalUrl:response.headers.get('x-scraper-final-url')||endpoint,networkMode,directStatus},summary:{siteUrl:x.url,sampleProductId:sample?.id||null,sampleProductName:sample?.name||null,sampleProductStatus:sample?.status||null},recommendations,raw:redactDiagnostic(raw,[x.key,x.secret])};
    }
    if(target==='basalam'){
      const base=connections.basalam,index=Number(input?.shopIndex),shop=Number.isInteger(index)&&index>=0?base.shops[index]:null,token=shop?.token||base.token,expectedVendorId=shop?.vendorId||base.vendorId,endpoint=base.api.replace(/\/$/,'')+'/users/me';
      if(!token)return diagnosticConfigError(target,startedAt,started,'توکن باسلام وارد نشده است.',['از پنل توسعه‌دهندگان باسلام یک توکن معتبر بسازید.','توکن را بدون Bearer و بدون فاصلهٔ ابتدا/انتها وارد کنید.']);
      const response=await safeFetch(endpoint,{headers:{authorization:`Bearer ${token}`,accept:'application/json'}},2_000_000),raw=await diagnosticBody(response),vendor=raw?.vendor||raw?.data?.vendor||{},user=raw?.data||raw||{},vendorId=String(vendor.id||user.vendor_id||'');
      return{ok:response.ok,target,service:'Basalam OpenAPI',startedAt,durationMs:Date.now()-started,request:{method:'GET',endpoint,authentication:'Bearer Token (توکن در گزارش نمایش داده نمی‌شود)',shopIndex:shop?index:null},http:{status:response.status,statusText:response.statusText,contentType:response.headers.get('content-type')||'',finalUrl:response.headers.get('x-scraper-final-url')||endpoint},summary:{userId:user.id||null,userName:user.name||user.username||null,vendorId:vendorId||null,vendorTitle:vendor.title||user.vendor_title||shop?.name||null,vendorActive:vendor.is_active??null,verification:user.info_verification_status||null,configuredVendorId:expectedVendorId||null,vendorIdMatches:!expectedVendorId||!vendorId?null:String(expectedVendorId)===vendorId},recommendations:response.ok?['توکن معتبر است و مسیر users/me پاسخ داد.',...(!expectedVendorId&&vendorId?['شناسه غرفهٔ دریافت‌شده را در تنظیمات ذخیره کنید.']:[]),...(expectedVendorId&&vendorId&&String(expectedVendorId)!==vendorId?['شناسه غرفهٔ تنظیم‌شده با غرفهٔ توکن یکسان نیست؛ آن را اصلاح کنید.']:[])]:connectionAdvice(response.status,'basalam'),raw:redactDiagnostic(raw,[token])};
    }
    if(target==='ai'){
      const providers=await aiProviders(),provider=providers.find(x=>x.id===input?.provider)||providers.find(x=>x.enabled&&x.models.length),model=String(input?.model||provider?.models[0]||''),prompt=String(input?.prompt||'سلام');
      if(!provider||!model)return diagnosticConfigError(target,startedAt,started,'ارائه‌دهنده و مدل هوش مصنوعی تنظیم نشده است.',['در رابط بصری یک ارائه‌دهنده اضافه کنید.','Base URL، API Key و حداقل یک مدل را وارد و ذخیره کنید.']);
      try{const result=await aiCall(provider,model,prompt);return{...result,target,service:'AI Chat Completions',startedAt,durationMs:Date.now()-started,recommendations:['مدل پاسخ معتبر برگرداند. برای مشاهدهٔ پاسخ خام، بخش پایین مودال را بازبینی کنید.']}}catch(error){const detail=(error as any)?.detail||{};return{ok:false,target,service:'AI Chat Completions',startedAt,durationMs:Date.now()-started,provider:provider.id,providerName:provider.name,model,prompt,error:message(error),...detail,recommendations:['آدرس پایه، کلید API و نام دقیق مدل را بررسی کنید.','اگر خطا ۴۲۹ است کمی صبر کنید یا سقف تست همزمان را کم کنید.','اگر خطای شبکه است روش Worker/Gateway سازگار با HTTP را انتخاب کنید.']}}
    }
    return{ok:false,target,error:'نوع اتصال ناشناخته است.',startedAt,durationMs:Date.now()-started};
  }catch(error){return{ok:false,target,startedAt,durationMs:Date.now()-started,error:message(error),phase:'network',recommendations:['دسترسی اینترنت و آدرس سرویس را بررسی کنید.','اگر سرویس IP کلادفلر را محدود کرده است از Gateway/Worker مجاز همان سرویس استفاده کنید.','شناسه درخواست و متن خطا را برای عیب‌یابی نگه دارید.']}}
}
function diagnosticConfigError(target:string,startedAt:string,started:number,error:string,recommendations:string[]){return{ok:false,target,startedAt,durationMs:Date.now()-started,phase:'configuration',error,recommendations}}
async function diagnosticBody(response:Response){const text=await response.text();if(text.length>100_000)return{truncated:true,totalCharacters:text.length,preview:text.slice(0,100_000)};try{return JSON.parse(text)}catch{return text}}
function redactDiagnostic(value:any,secrets:string[]=[]):any{if(typeof value==='string')return secrets.filter(Boolean).reduce((text,secret)=>text.replaceAll(secret,'[پنهان]'),value);if(Array.isArray(value))return value.map(item=>redactDiagnostic(item,secrets));if(value&&typeof value==='object'){const out:any={};for(const[key,item]of Object.entries(value))out[key]=/(?:authorization|api[_-]?key|token|secret|password|consumer[_-]?(?:key|secret)|cookie)/i.test(key)?'[پنهان]':redactDiagnostic(item,secrets);return out}return value}
function connectionAdvice(status:number,target:string){if(target==='woo'&&status===522)return['خطای ۵۲۲ یعنی Cloudflare به سرور اصلی فروشگاه وصل نشده است؛ معمولاً کلید ووکامرس مقصر نیست.','در هاست فروشگاه، روشن‌بودن وب‌سرور و محدودنبودن IPهای Cloudflare را بررسی کنید.','در تنظیمات ووکامرس، روش «خودکار با مسیر جایگزین» را انتخاب و آدرس Reverse Worker مجاز را وارد کنید تا برنامه پس از ۵۲۲ خودکار دوباره تلاش کند.'];if(target==='woo'&&[520,521,523,524,525,526].includes(status))return[`خطای ${status} از لبهٔ Cloudflare دریافت شد و ارتباط با مبدأ فروشگاه کامل نشد.`,'سلامت هاست، SSL و فایروال فروشگاه را بررسی کنید یا یک Reverse Worker مجاز به‌عنوان مسیر جایگزین وارد کنید.'];if(status===401)return[target==='woo'?'کلید یا Secret ووکامرس معتبر نیست یا مجوز کافی ندارد.':'توکن باسلام نامعتبر یا منقضی است.','اطلاعات اتصال را اصلاح و دوباره تست کنید.'];if(status===403)return['سرویس درخواست را ممنوع کرده است؛ مجوز توکن/کلید و وضعیت احراز هویت را بررسی کنید.','ممکن است IP خروجی Cloudflare در سرویس مقصد محدود شده باشد.'];if(status===404)return['مسیر API پیدا نشد؛ آدرس پایه و نسخه API را بررسی کنید.'];if(status===429)return['محدودیت تعداد درخواست فعال شده است؛ چند دقیقه صبر و دوباره آزمایش کنید.'];return[`پاسخ HTTP ${status} موفق نبود. بخش «پاسخ خام» را برای پیام دقیق سرویس ببینید.`]}

async function createProfileJob(c:any,id:string,kind:'scrape'|'sync'){const profile=await getProfile(id);if(!profile)return c.json({ok:false,error:'Profile not found'},404);const b=await c.req.json().catch(()=>({})),target=validTarget(b.target||(kind==='sync'?'both':'none')),job=await createJob(profile.id,kind,target);if(job.status==='queued')await enqueueJob(job,(promise:Promise<unknown>)=>c.executionCtx.waitUntil(promise));return c.json({ok:true,job},202)}
async function jsonBody(c:any):Promise<any>{return c.req.json().catch(()=>({}))}
function validTarget(value:string):'none'|'woo'|'basalam'|'both'{return['none','woo','basalam','both'].includes(value)?value as any:'none'}
function validDestination(value:string):'woo'|'basalam'{if(value!=='woo'&&value!=='basalam')throw new Error('Invalid target');return value}
function csvCell(value:unknown){const text=value&&typeof value==='object'?JSON.stringify(value):String(value??'');return`"${text.replace(/"/g,'""')}"`}
async function importRecords(c:any):Promise<{records:Record<string,any>[];format:'csv'|'xlsx'|'json';wooStatus:Product['destinationStatus']|''}>{const contentType=String(c.req.header('content-type')||'').toLowerCase(),requested=String(c.req.query('format')||'').toLowerCase();let format:'csv'|'xlsx'|'json'='json',records:Record<string,any>[]=[],wooStatus:Product['destinationStatus']|''='';if(contentType.includes('application/json')){const body=await jsonBody(c);records=Array.isArray(body.rows)?body.rows:typeof body.csv==='string'?parseCsv(body.csv):[];wooStatus=validWooImportStatus(body.wooStatus)}else{const file=await readImportFile(c);format=file.format;records=file.rows.map(values=>normalizeImportRecord(Object.fromEntries(file.headers.map((key,i)=>[key,values[i]||'']))));wooStatus=validWooImportStatus(c.req.query('wooStatus'))}return{records,format,wooStatus}}

type ImportFile={format:'csv'|'xlsx';headers:string[];rows:string[][]};
async function readImportFile(c:any):Promise<ImportFile>{
  const requested=String(c.req.query('format')||'').toLowerCase(),contentType=String(c.req.header('content-type')||'').toLowerCase(),size=Number(c.req.header('content-length')||0);
  if(size>10*1024*1024)throw new Error('فایل درون‌ریزی باید حداکثر ۱۰ مگابایت باشد.');
  const buffer=await c.req.arrayBuffer();
  if(buffer.byteLength>10*1024*1024)throw new Error('فایل درون‌ریزی باید حداکثر ۱۰ مگابایت باشد.');
  if(requested==='xlsx'||contentType.includes('spreadsheetml')){const table=await readXlsxFile(buffer);const headers=(table[0]||[]).map(String);return{format:'xlsx',headers,rows:table.slice(1).map((row:unknown[])=>row.map(cell=>cell==null?'':String(cell)))}}
  const rows=parseCsvRaw(new TextDecoder().decode(buffer));if(!rows.length)return{format:'csv',headers:[],rows:[]};const headers=rows[0];return{format:'csv',headers,rows:rows.slice(1)};
}
function parseCsvRaw(text:string):string[][]{const rows:string[][]=[];let row:string[]=[],cell='',quoted=false;const input=text.replace(/^\uFEFF/,'');for(let i=0;i<input.length;i++){const ch=input[i];if(quoted){if(ch==='"'&&input[i+1]==='"'){cell+='"';i++}else if(ch==='"')quoted=false;else cell+=ch}else if(ch==='"')quoted=true;else if(ch===','){row.push(cell);cell=''}else if(ch==='\n'){row.push(cell);rows.push(row);row=[];cell=''}else if(ch!=='\r')cell+=ch}if(cell||row.length){row.push(cell);rows.push(row)}return rows.filter((x,index)=>index===0||x.some(Boolean))}

// ─── Import column mapping & options ─────────────────────────────────────────
const IMPORT_FIELDS:Array<{field:string;labels:string[]}>=[
  {field:'title',labels:['title','name','product','عنوان','نام','ناممحصول','نام محصول','نامکالا']},
  {field:'price',labels:['price','قیمت','قیمتفروش','قیمت فروش','قیمتکالا']},
  {field:'url',labels:['url','link','لینک','آدرس','آدرسصفحه','منبع']},
  {field:'image',labels:['image','img','تصویر','عکس','تصویراصلی','تصویراصلی']},
  {field:'sku',labels:['sku','کد','کدمحصول','کد محصول','شناسهکالا','code','productcode']},
  {field:'brand',labels:['brand','برند']},
  {field:'stock',labels:['stock','موجودی','تعداد','تعدادموجودی']},
  {field:'weight',labels:['weight','وزن']},
  {field:'category',labels:['category','دسته','دستهبندی','گروه','گروهکالا']},
  {field:'shortDesc',labels:['shortdesc','توضیحکوتاه','مختصر']},
  {field:'longDesc',labels:['longdesc','description','desc','توضیحات','توضیحکامل','متن']},
  {field:'tags',labels:['tags','تگ','برچسب']},
  {field:'sourceKey',labels:['sourcekey','key','شناسه','شناسهمحصول','id']},
  {field:'variations',labels:['variations','تنوع','تنوعها']},
  {field:'attributes',labels:['attributes','attribute','attr','ویژگی','ویژگیها','خصوصیات','مشخصات','خصیصه','خصوصیت']}
];
const normalizeImportHeader=(value:string)=>String(value||'').trim().toLowerCase().replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[\s_\-‌.]+/g,'').replace(/[«»"']/g,'');
function detectImportMapping(headers:string[]):Array<{column:string;field:string;confidence:number}>{
  const used=new Set<string>(),out:Array<{column:string;field:string;confidence:number}>=[];
  for(const header of headers){
    const key=normalizeImportHeader(header);if(!key)continue;
    let best:null|{field:string;confidence:number}=null;
    for(const def of IMPORT_FIELDS){
      for(const label of def.labels){
        const norm=normalizeImportHeader(label);
        if(key===norm){best={field:def.field,confidence:1};break}
        if(key.includes(norm)&&norm.length>=3&&(!best||best.confidence<0.9)){best={field:def.field,confidence:0.85};break}
        if(norm.includes(key)&&key.length>=3&&(!best||best.confidence<0.75)){best={field:def.field,confidence:0.7};break}
      }
      if(best?.confidence===1)break;
    }
    if(best&&!used.has(best.field)){used.add(best.field);out.push({column:header,field:best.field,confidence:best.confidence})}
  }
  return out;
}
function applyImportMapping(row:Record<string,any>,mapping:Record<string,string>):Record<string,any>{
  const out:Record<string,any>={};
  for(const[column,field]of Object.entries(mapping||{})){if(column in row)out[field]=row[column]}
  for(const[key,value]of Object.entries(row)){out[key]??=value}
  return out;
}

// ─── Import: product attributes (ویژگی‌ها) parsing ───────────────────────────
type ImportAttrGroup={name:string;values:string[]};
/** Parses a mapped column cell into attribute groups:
 *  JSON: [{"name":"رنگ","values":["قرمز","آبی"]}] or {"رنگ":"قرمز"}
 *  Text: "رنگ:قرمز، آبی|سایز:M، L" (groups split by | ; : or = after name; values by ، , /)
 *  Plain values: "قرمز، آبی" → attribute name becomes the column header. */
function parseImportAttributes(value:unknown,columnName=''):ImportAttrGroup[]{
  const raw=String(value??'').trim();if(!raw)return[];
  const out:ImportAttrGroup[]=[],merge=(name:string,vals:string[])=>{const n=String(name||'').trim().slice(0,60),list=vals.map(v=>String(v||'').trim()).filter(Boolean).slice(0,200);if(!n||!list.length)return;const found=out.find(g=>g.name===n);if(found)found.values=[...new Set([...found.values,...list])];else out.push({name:n,values:[...new Set(list)]})};
  if(raw.startsWith('[')||raw.startsWith('{')){try{const parsed=JSON.parse(raw);
    if(Array.isArray(parsed))for(const item of parsed){if(item&&typeof item==='object'){const name=String(item.name||item.attr||'').trim(),values=Array.isArray(item.values)?item.values:item.value!==undefined?[item.value]:item.options;if(name&&values)merge(name,(values as unknown[]).map(String))}}
    else if(parsed&&typeof parsed==='object')for(const[name,values]of Object.entries(parsed))merge(name,Array.isArray(values)?(values as unknown[]).map(String):[String(values)]);
    if(out.length)return out}catch{/* fall through to text parsing */}}
  const groupParts=raw.split(/[|;\\n]/).map(p=>p.trim()).filter(Boolean);
  for(const part of groupParts){
    const colon=part.search(/[:=]/);
    if(colon>0){const name=part.slice(0,colon).trim(),values=part.slice(colon+1).split(/[,،/]+/).map(v=>v.trim()).filter(Boolean);if(name&&values.length)merge(name,values)}
    else{const values=part.split(/[,،/]+/).map(v=>v.trim()).filter(Boolean);if(values.length)merge(columnName||'ویژگی',values)}
  }
  return out;
}
function mergeVariationGroups(groups:Array<{name:string;values:string[]}>):Array<{name:string;values:string[]}>{
  const out:Array<{name:string;values:string[]}>=[];for(const group of groups||[]){const name=String(group?.name||'').trim(),values=Array.isArray(group?.values)?group.values.map(String):[];if(!name||!values.length)continue;const found=out.find(g=>g.name===name);if(found)found.values=[...new Set([...found.values,...values])];else out.push({name,values:[...new Set(values)]})}return out;
}
type ImportOptions={mapping:Record<string,string>;skipMissingTitle:boolean;skipMissingPrice:boolean;priceUnit:'toman'|'rial'|'multiply10'|'none';defaultStock:number;dedupe:'none'|'first'|'last'};
function parseImportOptions(raw:unknown):ImportOptions{
  const o=typeof raw==='string'?jsonValue<Record<string,any>>(raw,{}):(raw&&typeof raw==='object'?raw:{}) as Record<string,any>;
  const mapping:Record<string,string>={};
  if(o.mapping&&typeof o.mapping==='object')for(const[col,field]of Object.entries(o.mapping as Record<string,any>)){const f=String(field||'').trim();if(f)mapping[String(col)]=f}
  const unit=String(o.priceUnit||'none'),dedupe=String(o.dedupe||'none');return{mapping,skipMissingTitle:o.skipMissingTitle!==false,skipMissingPrice:Boolean(o.skipMissingPrice),priceUnit:['toman','rial','multiply10','none'].includes(unit)?unit as ImportOptions['priceUnit']:'none',defaultStock:Math.max(0,Math.trunc(Number(o.defaultStock)||0)),dedupe:['none','first','last'].includes(dedupe)?dedupe as ImportOptions['dedupe']:'none'};
}
function normalizeImportPrice(raw:string,opts:Pick<ImportOptions,'priceUnit'>):number|null{
  const text=String(raw??'').trim();if(!text)return 0;
  let digits=text.replace(/[۰-۹٠-٩]/g,ch=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(ch)>=0?String('۰۱۲۳۴۵۶۷۸۹'.indexOf(ch)):String('٠١٢٣٤٥٦٧٨٩'.indexOf(ch)));
  digits=digits.replace(/[^\d.,-]/g,'').replace(/\./g,'').replace(/^,+/,'').replace(/,+/g,'');
  const number=Number(digits);if(!Number.isFinite(number))return null;
  if(opts.priceUnit==='rial')return Math.round(number/10);
  if(opts.priceUnit==='multiply10')return Math.round(number*10);
  return Math.round(number);
}
function detectPriceUnit(samples:Array<Record<string,any>>):'rial'|'toman'|null{
  const values:number[]=[];for(const row of samples){const n=normalizeImportPrice(String(row.price??row.قیمت??''),{priceUnit:'none'});if(n&&n>0)values.push(n)}
  if(!values.length)return null;
  const avg=values.reduce((a,b)=>a+b,0)/values.length;
  return avg>=10000&&values.every(v=>v%10===0)?'rial':'toman';
}
async function getImportHistory():Promise<any[]>{const items=await getState<any[]>('import_history',[]);return Array.isArray(items)?items.slice(-60):[]}
async function pushImportHistory(entry:any):Promise<void>{const items=await getImportHistory();items.push(entry);await setState('import_history',items.slice(-60))}
function validWooImportStatus(value:unknown):Product['destinationStatus']|''{const status=String(value||'');return status==='draft'||status==='publish'||status==='pending'||status==='private'?status:''}
function recordsFromSheet(table:unknown[][]):Record<string,any>[]{const rows=table.filter(row=>row.some(cell=>cell!==null&&cell!==undefined&&String(cell).trim()!=='')),headers=(rows.shift()||[]).map(String);return rows.map(values=>Object.fromEntries(headers.map((header,index)=>[header,values[index]??''])))}
function normalizeImportRecord(row:Record<string,any>):Record<string,any>{const aliases:Record<string,string>={title:'title',name:'title','نام':'title','ناممحصول':'title','عنوان':'title','عنوانمحصول':'title',price:'price','قیمت':'price','قیمتفروش':'price',url:'url',link:'url','لینک':'url','آدرس':'url','آدرسصفحه':'url',image:'image','تصویر':'image','عکس':'image',sku:'sku','کدمحصول':'sku','شناسهکالا':'sku',sourcekey:'sourceKey',key:'sourceKey','شناسه':'sourceKey',brand:'brand','برند':'brand',stock:'stock','موجودی':'stock',weight:'weight','وزن':'weight',category:'category','دستهبندی':'category',shortdesc:'shortDesc','توضیحکوتاه':'shortDesc',longdesc:'longDesc',description:'longDesc','توضیحات':'longDesc'};const out:Record<string,any>={};for(const[key,value]of Object.entries(row)){const normalized=key.trim().toLowerCase().replace(/[\s_\-‌]+/g,'').replace(/[يى]/g,'ی').replace(/ك/g,'ک'),target=aliases[normalized]||key;out[target]=value}return out}
function jsonValue<T>(value:unknown,fallback:T):T{if(value&&typeof value==='object')return value as T;try{return JSON.parse(String(value||'')) as T}catch{return fallback}}
function parseCsv(text:string):Record<string,string>[]{const rows:string[][]=[];let row:string[]=[],cell='',quoted=false;const input=text.replace(/^\uFEFF/,'');for(let i=0;i<input.length;i++){const ch=input[i];if(quoted){if(ch==='"'&&input[i+1]==='"'){cell+='"';i++}else if(ch==='"')quoted=false;else cell+=ch}else if(ch==='"')quoted=true;else if(ch===','){row.push(cell);cell=''}else if(ch==='\n'){row.push(cell);rows.push(row);row=[];cell=''}else if(ch!=='\r')cell+=ch}if(cell||row.length){row.push(cell);rows.push(row)}const headers=rows.shift()?.map(x=>x.trim())||[];return rows.filter(x=>x.some(Boolean)).map(values=>Object.fromEntries(headers.map((key,i)=>[key,values[i]||''])))}
function idFromUrl(raw:string):string{const url=new URL(raw),id=`${url.hostname}_${decodeURIComponent(url.pathname)}`.toLowerCase().replace(/[^\p{L}\p{N}_.-]+/gu,'_').replace(/^_+|_+$/g,'').slice(0,120);return id||crypto.randomUUID()}
function selectorString(value:unknown):string{
  if(typeof value==='string')return value.trim();
  if(value&&typeof value==='object'){
    const row=value as Record<string,unknown>;if(row.enabled===false)return '';
    return typeof row.selector==='string'?row.selector.trim():'';
  }
  return '';
}
function selectorRecord(raw:unknown,keepEmpty=false):Record<string,string>{
  const parsed=typeof raw==='string'?jsonValue<Record<string,unknown>>(raw,{}):raw;
  if(!parsed||typeof parsed!=='object'||Array.isArray(parsed))return {};
  const out:Record<string,string>={};for(const [key,value] of Object.entries(parsed as Record<string,unknown>)){const selector=selectorString(value),explicit=typeof value==='string'||Boolean(value&&typeof value==='object'&&('selector' in value||'enabled' in value));if(selector||(keepEmpty&&explicit))out[key]=selector}return out;
}
function booleanValue(value:unknown,fallback=false):boolean{if(value===undefined||value===null)return fallback;if(typeof value==='string')return['1','true','yes','on'].includes(value.trim().toLowerCase());return Boolean(value)}
function normalizedGallery(raw:unknown):Profile['gallery']|null{
  const parsed=typeof raw==='string'?jsonValue<Record<string,unknown>>(raw,{}):raw;
  if(!parsed||typeof parsed!=='object'||Array.isArray(parsed))return null;
  const cfg=parsed as Record<string,unknown>,rawMode=String(cfg.mode||'off'),mode=(['off','auto','manual','number'].includes(rawMode)?rawMode:'off') as NonNullable<Profile['gallery']>['mode'];
  const from=Math.max(0,Math.trunc(Number(cfg.from)||0)),to=Math.max(from,Math.trunc(Number(cfg.to)||10));
  return{mode,box:selectorString(cfg.box),selectors:selectorString(cfg.selectors),pattern:selectorString(cfg.pattern),from,to,max:Math.max(1,Math.min(30,Math.trunc(Number(cfg.max)||30))),skip_first:booleanValue(cfg.skip_first??cfg.skipFirst)};
}
function gallerySelector(cfg:NonNullable<Profile['gallery']>):string{
  if(cfg.mode==='auto')return cfg.box;if(cfg.mode==='manual')return cfg.selectors;if(cfg.mode!=='number'||!cfg.pattern.includes('{n}'))return '';
  const to=Math.min(cfg.to,cfg.from+60);return Array.from({length:to-cfg.from+1},(_,index)=>cfg.pattern.replaceAll('{n}',String(cfg.from+index))).join('\n');
}
export function normalizeProfile(raw:any):Profile {
  const sync=typeof raw.syncConfig==='string'?jsonValue<Record<string,any>>(raw.syncConfig,{}):raw.syncConfig||{};
  const on=(value:unknown)=>[true,1,'1','true','on'].includes(value as any);
  const noExtract=on(raw.noExtract??sync.noExtract);
  const rawUrl=String(raw.url||'').trim()||(noExtract?`https://import.invalid/${encodeURIComponent(String(raw.id||raw.key||'products'))}`:'');
  const url=new URL(rawUrl);
  if(!['http:','https:'].includes(url.protocol))throw new Error('Invalid profile URL');
  const previousCreated=String(raw.createdAt||raw.created_at||new Date().toISOString());
  const rawSelectorOptions=typeof raw.selectors==='string'?jsonValue<Record<string,unknown>>(raw.selectors,{}):((raw.selectors&&typeof raw.selectors==='object')?raw.selectors:{});
  const selectors:{[key:string]:unknown}={...DEFAULT_SELECTORS,...selectorRecord(raw.selectors,true),...selectorRecord(raw.listSelectors,true)};
  if(Number(rawSelectorOptions.galleryMax)>0)selectors.galleryMax=Math.max(1,Math.min(30,Math.trunc(Number(rawSelectorOptions.galleryMax))));
  if(rawSelectorOptions.gallerySkipFirst!==undefined)selectors.gallerySkipFirst=booleanValue(rawSelectorOptions.gallerySkipFirst);
  const detail=selectorRecord(raw.detailSelectors);
  for(const [key,value] of Object.entries(detail))selectors[key==='image'?'detailImage':key]=value;
  const gallery=normalizedGallery(raw.gallery);
  if(gallery){selectors.gallery=gallerySelector(gallery);selectors.galleryMax=gallery.max;selectors.gallerySkipFirst=gallery.skip_first}
  if(!selectors.container){if(noExtract)selectors.container='body';else throw new Error('selectors.container is required')}
  const pagination=String(raw.pagination||raw.pagType||'query_page') as Profile['pagination'];
  const target=String(sync.target||'');
  const indirect=on(raw.networkIndirect??raw.net_indirect);
  const fallbackIds=raw.basalamFallbackCategoryIds??raw.bslFallbackCatIds;
  return {
    id:String(raw.id||raw.key||idFromUrl(url.href)),name:String(raw.name||url.hostname),url:url.href,enabled:raw.enabled===undefined?true:on(raw.enabled),
    pages:Math.min(100,Math.max(1,Number(raw.pages)||1)),
    pagination:['query_page','query_custom','path_page','path_pattern','full_pattern','next_selector','none'].includes(pagination)?pagination:'query_page',
    paginationValue:String(raw.paginationValue||raw.pagVal||'page'),selectors:selectors as Profile['selectors'],gallery:gallery||undefined,titleSuffix:String(raw.titleSuffix||''),
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

export async function scheduledTasks(env:Env,waitUntil:(promise:Promise<unknown>)=>void):Promise<void>{
  configureEnv(env);await ensureSchema(env.DB);
  const settings=await getState<any>('settings',{}),lockMin=clampNumber(settings.general?.cronLockMin,30,1,240);
  if(!await acquireCronLock(lockMin))return;
  try{
    if(settings.watchdog?.enabled!==false)await reapStalledJobs(Math.max(0.5,Number(settings.watchdog?.stallAfter||300)/60));
    await pruneFinishedJobs(clampNumber(settings.general?.keepReports,20,1,200));
    const due=await enqueueDueProfiles(),queued=(await listJobs(200)).filter(job=>job.status==='queued'),seen=new Set<string>();
    for(const job of [...due,...queued])if(!seen.has(job.id)){seen.add(job.id);await enqueueJob(job,waitUntil)}
    await recoverBackgroundRuns(waitUntil);
    waitUntil(agentCronTick((promise:Promise<unknown>)=>waitUntil(promise)));
    waitUntil(automationTick());
    waitUntil(maybeCronPing(settings));
  }finally{await releaseCronLock()}
}
function clampNumber(value:unknown,fallback:number,min:number,max:number){const n=Number(value);return Number.isFinite(n)?Math.min(max,Math.max(min,n)):fallback}
async function acquireCronLock(minutes:number){const lock=await getState<any>('cron_lock',{}),age=lock.at?Date.now()-Date.parse(lock.at):Infinity;if(lock.held&&Number.isFinite(age)&&age<minutes*60_000)return false;await setState('cron_lock',{held:true,at:new Date().toISOString()});return true}
async function releaseCronLock(){await setState('cron_lock',{held:false,at:new Date().toISOString()})}
async function maybeCronPing(settings:any){
  if(!settings.notifications?.events?.cronPing)return;
  const everyMin=clampNumber(settings.notifications?.pingEvery,360,1,10_080),last=await getState<any>('cron_ping',{});
  if(last.at&&Date.now()-Date.parse(last.at)<everyMin*60_000)return;
  const text=`📡 پینگ کران اسکرپر ۴\nزمان: ${new Date().toISOString()}\nضربان سرور هر ۱ دقیقه است و تنظیمات عمومی روی همین اجرا اعمال شد.`;
  const delivery:any[]=[];
  for(const channel of ['bale','rubika','webhook'] as const)try{delivery.push({channel,...await sendNotification(channel,text)})}catch(error){delivery.push({channel,skipped:true,error:error instanceof Error?error.message:String(error)})}
  if(delivery.some(item=>item.ok))await setState('cron_ping',{at:new Date().toISOString(),delivery});
}
