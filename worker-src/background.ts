import { deleteState, getRunPriorities, getState, getTriedBasalamCategories, markBasalamCategoriesTried, setState } from './db.js';
import { getEnv } from './env.js';
import { getLastAiTestResults, isChatCompatibleAiModel, isRetryableAiResult, nextAiTestBatch, suggestCategoryWithModel, testModelBatch } from './ai.js';
import { loadConnections } from './connections.js';
import { applyBasalamCategory, destinationCatalog, destinationCategories, destinationChangeStatus } from './maintenance.js';
import { buildDedupGroups, normalizeDedupKeep, parseSuffixFormats, type DedupCandidate, type DedupGroup, type DedupKeep } from './dedup.js';
import { currentAgentRun, processAgentRunMessage, recoverAgentRun } from './agent.js';
import type { BackgroundMessage } from './types.js';

export type BackgroundOutcome={outcome:'complete'|'continue'|'ignored';delaySeconds?:number};
type RunStatus='queued'|'running'|'paused'|'done'|'failed';
type BaseRun={id:string;kind:'ai-test'|'category-all'|'dedup';status:RunStatus;phase:string;stopRequested:boolean;createdAt:string;updatedAt:string;startedAt:string|null;finishedAt:string|null;attempts:number;error:string|null};
type AiTestRun=BaseRun&{kind:'ai-test';prompt:string;categoryTitle:string;onlyCandidates:boolean;delayMs:number;cursor:number;result:any;skipNext?:boolean;currentStartedAt?:string|null;currentKey?:string|null;retryJobs?:{key:string;left:number}[]};
type CategoryProduct={id:number;shopId:string;title:string;categoryId?:number};
type CategoryRunItem={id:number;shopId:string;title:string;ok:boolean;categoryId?:number;categoryName?:string;source?:string;confidence?:number;error?:string};
type CategoryRun=BaseRun&{kind:'category-all';modelKeys:string[];page:number;totalPages:number;products:CategoryProduct[];cursor:number;total:number;processed:number;changed:number;failed:number;items:CategoryRunItem[]};
type DedupTarget='woo'|'basalam';
type DedupItemLog={id:number;shopId:string;name:string;ok:boolean;action:string;error?:string};
type DedupRun=BaseRun&{kind:'dedup';target:DedupTarget;keep:DedupKeep;suffixFormats:string[];apply:boolean;page:number;totalPages:number;listingDone:boolean;grouped:boolean;products:DedupCandidate[];groups:DedupGroup[];groupCursor:number;removeCursor:number;scanned:number;groupsFound:number;duplicates:number;removed:number;failed:number;items:DedupItemLog[]};
export type BackgroundRun=AiTestRun|CategoryRun|DedupRun;

const pointerKey=(kind:BackgroundRun['kind'])=>`background_current:${kind}`;
const runKey=(kind:BackgroundRun['kind'],id:string)=>`background_run:${kind}:${id}`;
const leaseKey=(kind:BackgroundRun['kind'],id:string)=>`background_lease:${kind}:${id}`;
const now=()=>new Date().toISOString();
const active=(run:BackgroundRun|null)=>Boolean(run&&['queued','running','paused'].includes(run.status));
/** If a run shows no progress for this long, skip the current model and continue. */
const STALL_MS=45_000;
/** Lease must expire sooner than STALL so a dead isolate cannot block the watchdog. */
const LEASE_MS=35_000;
const DEFAULT_SKIP_TIMEOUT_MS=1_000;
export function aiSkipTimeoutMs(settings:any):number{
  const fromSettings=Number(settings?.ai?.skipTimeoutMs),fromEnv=Number(getEnv().AI_TEST_TIMEOUT_MS);
  const raw=Number.isFinite(fromSettings)&&fromSettings>0?fromSettings:Number.isFinite(fromEnv)&&fromEnv>0?fromEnv:DEFAULT_SKIP_TIMEOUT_MS;
  return Math.max(50,Math.min(60_000,Math.trunc(raw)));
}
const runAge=(run:BackgroundRun)=>Date.now()-(Date.parse(run.updatedAt||run.createdAt)||Date.now());
async function raceBudget<T>(work:Promise<T>,ms:number):Promise<T|undefined>{
  let timer:ReturnType<typeof setTimeout>|undefined;
  const timeout=new Promise<undefined>(resolve=>{timer=setTimeout(()=>resolve(undefined),ms)});
  try{return await Promise.race([work,timeout])}finally{if(timer)clearTimeout(timer)}
}

// Queue delivery is at-least-once. This D1 compare-and-set lease ensures two deliveries
// cannot process the same checkpoint concurrently. A crashed isolate is recoverable after
// the lease expires and the scheduled recovery pass re-enqueues the run.
async function claimRun(kind:BackgroundRun['kind'],id:string):Promise<string|null>{
  const token=crypto.randomUUID(),key=leaseKey(kind,id),stamp=now(),stale=new Date(Date.now()-LEASE_MS).toISOString(),value=JSON.stringify({token});
  await getEnv().DB.prepare(`INSERT INTO app_state(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at WHERE app_state.updated_at<?`).bind(key,value,stamp,stale).run();
  const row=await getEnv().DB.prepare('SELECT value FROM app_state WHERE key=?').bind(key).first<{value:string}>();
  try{return JSON.parse(row?.value||'{}').token===token?token:null}catch{return null}
}
async function releaseRun(kind:BackgroundRun['kind'],id:string,token:string):Promise<void>{
  await getEnv().DB.prepare('DELETE FROM app_state WHERE key=? AND value=?').bind(leaseKey(kind,id),JSON.stringify({token})).run();
}

async function readRun(kind:BackgroundRun['kind'],id:string):Promise<BackgroundRun|null>{return getState<BackgroundRun|null>(runKey(kind,id),null)}
async function writeRun(run:BackgroundRun):Promise<void>{run.updatedAt=now();await setState(runKey(run.kind,run.id),run)}
export async function currentBackgroundRun(kind:BackgroundRun['kind']):Promise<BackgroundRun|null>{const id=await getState<string>(pointerKey(kind),'');return id?readRun(kind,id):null}
function publicRun(run:BackgroundRun|null):any{
  if(!run)return null;
  if(run.kind==='ai-test')return{...run,result:run.result||{}};
  if(run.kind==='dedup'){
    const{products,groups,...safe}=run;
    return{...safe,groups:groups.slice(0,250).map(group=>({title:group.title,count:group.remove.length+1,keep:group.keep,remove:group.remove.slice(0,25)})),groupsTruncated:groups.length>250};
  }
  const{products,...safe}=run;return safe;
}
export async function getPublicBackgroundRun(kind:BackgroundRun['kind']):Promise<any>{return publicRun(await currentBackgroundRun(kind))}
export async function retryAiTestPart(key:string,part:'message'|'category'){
  const stored=await getLastAiTestResults(),retryKey=String(key||'').trim();
  if(!retryKey)throw new Error('شناسه مدل برای تلاش مجدد لازم است.');
  if(!stored?.runId||!Array.isArray(stored.results)||!stored.results.length)throw new Error('نتیجهٔ ذخیره‌شده‌ای برای تلاش مجدد نیست؛ ابتدا تست مدل‌ها را اجرا کنید.');
  let categories:any[]=[];if(part==='category'&&stored.categoryTitle)try{categories=(await destinationCategories()).items}catch{/* category retry still records the missing-list error */}
  const result=await testModelBatch(String(stored.prompt||'سلام'),{runId:String(stored.runId),retryKey,retryPart:part,onlyCandidates:Boolean(stored.onlyCandidates),categoryTitle:String(stored.categoryTitle||''),categories,timeoutMs:aiSkipTimeoutMs(await getState('settings',{}))});
  const run=await currentBackgroundRun('ai-test');
  if(run&&run.kind==='ai-test'&&(run.id===stored.runId||run.result?.runId===stored.runId)){run.result={...(run.result||{}),...result,results:result.results};await writeRun(run)}
  return result;
}

async function enqueue(message:BackgroundMessage,waitUntil?:(promise:Promise<unknown>)=>void):Promise<void>{
  const queue=getEnv().JOBS;
  if(queue){await queue.send(message);return}
  const promise=drainInline(message);
  if(waitUntil)waitUntil(promise);else await promise;
}
async function drainInline(message:BackgroundMessage){for(let i=0;i<5;i++){const result=await processBackgroundMessage(message);if(result.outcome!=='continue')break}}

// Priority-aware dispatch order for background runs (task-manager drag order).
// Only runs still waiting in the queue are candidates; the first entry of the
// returned list is the run the queue consumer should process next.
const RUN_KIND_ORDER=['ai-test','dedup','category-all','agent'];
export async function listQueuedBackgroundRuns(limit=5):Promise<BackgroundMessage[]>{
  const priorities=await getRunPriorities(),priorityOf=(kind:string)=>Number(priorities[kind])||0;
  const candidates:Array<{message:BackgroundMessage;kind:string;priority:number}>=[];
  for(const kind of ['ai-test','category-all','dedup'] as const){
    const run=await currentBackgroundRun(kind);
    if(run&&run.status==='queued')candidates.push({message:{task:kind,runId:run.id},kind,priority:priorityOf(kind)});
  }
  const agentRun=await currentAgentRun();
  if(agentRun&&agentRun.status==='queued')candidates.push({message:{task:'agent',runId:agentRun.id},kind:'agent',priority:priorityOf('agent')});
  return candidates.sort((a,b)=>b.priority-a.priority||RUN_KIND_ORDER.indexOf(a.kind)-RUN_KIND_ORDER.indexOf(b.kind)).slice(0,Math.max(1,limit)).map(c=>c.message);
}

/** Force-clear a stuck run (its pointer, run row, lease and shared test-results state) so a fresh run can start. */
export async function resetBackgroundRun(kind:BackgroundRun['kind']):Promise<void>{
  const id=await getState<string>(pointerKey(kind),'');
  if(id){await deleteState(runKey(kind,id));await deleteState(leaseKey(kind,id));}
  await setState(pointerKey(kind),'');
  if(kind==='ai-test')await deleteState('ai_test_results');
}

export async function startAiTestRun(input:any,waitUntil?:(promise:Promise<unknown>)=>void):Promise<{run:any;existing:boolean}>{
  const previous=await currentBackgroundRun('ai-test');
  if(previous&&active(previous)){
    // A paused (user-stopped) or a recent run is returned as-is so it isn't clobbered.
    if(previous.status==='paused'||previous.stopRequested)return{run:publicRun(previous),existing:true};
    // A run that is queued/running but has made no progress for a long time is a stuck zombie:
    // clear it so the user can start a fresh test instead of being blocked forever.
    if(runAge(previous)<STALL_MS)return{run:publicRun(previous),existing:true};
    await resetBackgroundRun('ai-test');
  }
  const timestamp=now(),id=crypto.randomUUID(),run:AiTestRun={id,kind:'ai-test',status:'queued',phase:'waiting',stopRequested:false,createdAt:timestamp,updatedAt:timestamp,startedAt:null,finishedAt:null,attempts:0,error:null,prompt:String(input?.prompt||'سلام'),categoryTitle:String(input?.categoryTitle||'').trim(),onlyCandidates:Boolean(input?.onlyCandidates),delayMs:Math.max(0,Math.min(60_000,Number(input?.delayMs)||0)),cursor:0,result:{runId:id,total:0,nextCursor:0,results:[]}};
  await writeRun(run);await setState(pointerKey('ai-test'),id);await enqueue({task:'ai-test',runId:id},waitUntil);return{run:publicRun(run),existing:false};
}

async function successfulCategoryModels():Promise<string[]>{
  const[tests,connections]=await Promise.all([getLastAiTestResults(),loadConnections()]),green=new Set((Array.isArray(tests?.results)?tests.results:[]).filter((row:any)=>row?.ok===true).map((row:any)=>`${row.provider}::${row.model}`)),ai=connections.ai,candidates=Array.isArray(ai.candidates)?ai.candidates.map(String):[],providers=ai.providers.length?ai.providers:[{id:'default',models:ai.model?[ai.model]:[],enabled:true}],configured:string[]=[];
  for(const provider of providers)if(provider.enabled!==false)for(const model of provider.models||[]){const key=`${provider.id}::${model}`;if(model&&green.has(key)&&isChatCompatibleAiModel(provider,model))configured.push(key)}
  return[...new Set([...candidates.filter(key=>green.has(key)&&configured.includes(key)),...configured])].slice(0,5);
}
export async function startAllUnapprovedCategoryRun(waitUntil?:(promise:Promise<unknown>)=>void):Promise<{run:any;existing:boolean}>{
  const previous=await currentBackgroundRun('category-all');if(active(previous))return{run:publicRun(previous),existing:true};
  const modelKeys=await successfulCategoryModels();if(!modelKeys.length)throw new Error('هیچ مدل موفقی برای دسته‌بندی پیدا نشد؛ ابتدا تست سرورساید مدل‌ها را کامل کنید.');
  // Fail fast before queueing a long job when the category connection is incomplete.
  await destinationCategories();
  const timestamp=now(),id=crypto.randomUUID(),run:CategoryRun={id,kind:'category-all',status:'queued',phase:'listing',stopRequested:false,createdAt:timestamp,updatedAt:timestamp,startedAt:null,finishedAt:null,attempts:0,error:null,modelKeys,page:1,totalPages:1,products:[],cursor:0,total:0,processed:0,changed:0,failed:0,items:[]};
  await writeRun(run);await setState(pointerKey('category-all'),id);await enqueue({task:'category-all',runId:id},waitUntil);return{run:publicRun(run),existing:false};
}

/**
 * Long-running duplicate cleanup for a destination shop. Runs fully server-side on the Queue so
 * the browser can close: pages are listed one Queue message at a time, grouping is a single CPU
 * pass, and removals happen in small batches — every step checkpoints to D1.
 */
export async function startDedupRun(target:DedupTarget,input:any,waitUntil?:(promise:Promise<unknown>)=>void):Promise<{run:any;existing:boolean}>{
  const previous=await currentBackgroundRun('dedup');
  if(previous&&active(previous)){
    // A run belonging to the other shop is not "current" for this request: clear it so the
    // chosen target always gets a fresh server-side run instead of silently reusing the other shop's.
    if(previous.kind==='dedup'&&previous.target!==target)await resetBackgroundRun('dedup');
    else if(previous.status==='paused'||previous.stopRequested)return{run:publicRun(previous),existing:true};
    else if(runAge(previous)<STALL_MS)return{run:publicRun(previous),existing:true};
    else await resetBackgroundRun('dedup');
  }
  // Fail fast with a clear connection error before queueing a long job.
  const connections=await loadConnections();
  if(target==='woo'){const woo=connections.woo;if(!woo.url||!woo.key||!woo.secret)throw new Error('اتصال ووکامرس کامل نیست؛ آدرس و کلیدهای API را در بخش اتصال‌ها ذخیره کنید.')}
  else{const basalam=connections.basalam;if(!basalam.token||!basalam.vendorId)throw new Error('اتصال باسلام کامل نیست؛ توکن و شناسهٔ غرفه را در بخش اتصال‌ها ذخیره کنید.')}
  const timestamp=now(),id=crypto.randomUUID(),run:DedupRun={id,kind:'dedup',status:'queued',phase:'listing',stopRequested:false,createdAt:timestamp,updatedAt:timestamp,startedAt:null,finishedAt:null,attempts:0,error:null,target,keep:normalizeDedupKeep(input?.keep),suffixFormats:parseSuffixFormats(input?.suffixFormats),apply:Boolean(input?.apply),page:1,totalPages:1,listingDone:false,grouped:false,products:[],groups:[],groupCursor:0,removeCursor:0,scanned:0,groupsFound:0,duplicates:0,removed:0,failed:0,items:[]};
  await writeRun(run);await setState(pointerKey('dedup'),id);await enqueue({task:'dedup',runId:id},waitUntil);return{run:publicRun(run),existing:false};
}

export async function controlBackgroundRun(kind:BackgroundRun['kind'],action:'stop'|'resume',waitUntil?:(promise:Promise<unknown>)=>void):Promise<any>{
  const run=await currentBackgroundRun(kind);if(!run)throw new Error('اجرای پس‌زمینه‌ای پیدا نشد.');
  if(action==='stop'){
    if(['done','failed'].includes(run.status))return publicRun(run);
    run.stopRequested=true;run.status='paused';run.phase='paused';await writeRun(run);return publicRun(run);
  }
  if(!['paused','failed'].includes(run.status))return publicRun(run);
  run.stopRequested=false;run.status='queued';run.phase=run.kind==='category-all'&&run.products.length===0?'listing':run.kind==='dedup'?(!run.listingDone?'listing':!run.grouped?'grouping':'removing'):'waiting';run.error=null;run.finishedAt=null;run.attempts=0;await writeRun(run);await enqueue({task:run.kind,runId:run.id},waitUntil);return publicRun(run);
}

function withAiTimings(run:AiTestRun,result:any,startedMs:number,skippedStuck:boolean){
  const ms=Math.max(0,Date.now()-startedMs),previous=Array.isArray(run.result?.timingSamples)?run.result.timingSamples as number[]:[],samples=[...previous,ms].slice(-80),avg=samples.length?Math.round(samples.reduce((a,b)=>a+b,0)/samples.length):0,names=(Array.isArray(result.batchResults)?result.batchResults:[]).map((row:any)=>`${row.providerName||row.provider||''} / ${row.model||''}`.trim()).filter(Boolean);
  return{...result,serverSide:true,lastModelAt:now(),lastModelMs:ms,avgMs:avg,timingSamples:samples,skippedStuck:Number(run.result?.skippedStuck||0)+(skippedStuck?1:0),currentKey:null,currentStartedAt:null,lastModelName:names.join(' · ')||run.result?.lastModelName||''};
}
function queueRetryJobs(results:any[]){return (Array.isArray(results)?results:[]).filter(isRetryableAiResult).map((row:any)=>({key:String(row.key),left:3}))}
async function processAiTest(run:AiTestRun):Promise<BackgroundOutcome>{
  if(run.stopRequested||run.status==='paused')return{outcome:'complete'};
  const settings=await getState<any>('settings',{}),callTimeout=aiSkipTimeoutMs(settings),envBudget=Number(getEnv().AI_TEST_MODEL_BUDGET_MS);
  const budget=Math.max(callTimeout+50,Math.min(60_000,Number.isFinite(envBudget)&&envBudget>0?envBudget:callTimeout*2+200));
  const retrying=Array.isArray(run.retryJobs)&&run.retryJobs.length>0;
  run.status='running';run.phase=retrying?'retrying':'testing';run.startedAt||=now();run.currentStartedAt=now();run.error=null;
  let categories:any[]=[];if(run.categoryTitle)try{categories=(await destinationCategories()).items}catch{/* Model response tests continue without Basalam categories. */}
  const skip=Boolean(run.skipNext);run.skipNext=false;const started=Date.now();
  const options:any={runId:run.id,cursor:run.cursor,onlyCandidates:run.onlyCandidates,categoryTitle:run.categoryTitle,categories,timeoutMs:callTimeout,skipCurrent:skip,skipReason:skip?'این مدل پاسخ نداد و نگهبان صف برای جلوگیری از گیر کردن آن را رد کرد.':''};
  if(retrying)options.retryKeys=nextAiTestBatch(run.retryJobs!,0,job=>String(job.key).split('::')[0]).batch.map(job=>job.key);
  let result:any=await raceBudget(testModelBatch(run.prompt,options),budget),stuck=false;
  if(!result){stuck=true;result=await testModelBatch(run.prompt,{...options,skipCurrent:true,skipReason:'مهلت پاسخ این مدل تمام شد و برای ادامهٔ صف خودکار رد شد.'})}
  const skipped=stuck||skip||Boolean((result.batchResults||[]).some((row:any)=>row?.skipped&&row?.phase==='transport-skip'));
  if(retrying){
    const done=new Set((result.batchResults||[]).map((item:any)=>item.key));
    run.retryJobs=run.retryJobs!.flatMap(job=>{
      if(!done.has(job.key))return[job];
      const row=(result.results||[]).find((item:any)=>item.key===job.key);
      if(!isRetryableAiResult(row))return[];
      const left=job.left-1;return left>0?[{...job,left}]:[];
    });
  }else{
    run.cursor=Number(result.nextCursor||run.cursor);
    if(result.done)run.retryJobs=queueRetryJobs(result.results);
  }
  run.result={...withAiTimings(run,result,started,skipped),retryPending:(run.retryJobs||[]).length};run.attempts=0;run.currentKey=null;run.currentStartedAt=null;
  const latest=await readRun('ai-test',run.id);
  if(latest?.stopRequested){run.stopRequested=true;run.status='paused';run.phase='paused'}
  else if((run.retryJobs||[]).length){run.status='queued';run.phase='retrying'}
  else if(result.done||retrying){run.status='done';run.phase='finished';run.finishedAt=now();run.retryJobs=[]}
  else{run.status='queued';run.phase='waiting'}
  await writeRun(run);return{outcome:run.status==='queued'?'continue':'complete',delaySeconds:run.delayMs?Math.max(1,Math.ceil(run.delayMs/1000)):1};
}

function compactCategoryItem(row:any):CategoryRunItem{return{id:Number(row.id),shopId:String(row.shopId||''),title:String(row.title||''),ok:Boolean(row.ok),...(row.categoryId?{categoryId:Number(row.categoryId)}:{}),...(row.categoryName?{categoryName:String(row.categoryName)}:{}),...(row.source?{source:String(row.source)}:{}),...(row.confidence?{confidence:Number(row.confidence)}:{}),...(row.error?{error:String(row.error).slice(0,500)}:{})}}
function appendCategoryItem(run:CategoryRun,item:CategoryRunItem){run.items.push(compactCategoryItem(item));if(run.items.length>300)run.items=run.items.slice(-300)}
async function listCategoryProducts(run:CategoryRun):Promise<BackgroundOutcome>{
  const data:any=await destinationCatalog('basalam',{page:run.page,perPage:100,status:'3567',shopId:'all'}),seen=new Set(run.products.map(row=>`${row.shopId}:${row.id}`));
  for(const raw of data.products||[]){const row={id:Number(raw.id),shopId:String(raw.shopId||''),title:String(raw.title||raw.name||'').trim(),categoryId:Number(raw.categoryId||raw.category_id||raw.raw?.category_id||0)||undefined},key=`${row.shopId}:${row.id}`;if(row.id>0&&row.title&&!seen.has(key)){seen.add(key);run.products.push(row)}}
  run.totalPages=Math.max(run.totalPages,Number(data.totalPages)||1);run.total=Math.max(Number(data.total)||0,run.products.length);run.attempts=0;
  if(run.page<run.totalPages){run.page++;run.status='queued';run.phase='listing'}else{run.total=run.products.length;run.status='queued';run.phase='categorizing'}
  await writeRun(run);return{outcome:'continue',delaySeconds:1};
}
async function categorizeOne(run:CategoryRun):Promise<BackgroundOutcome>{
  if(run.cursor>=run.products.length){run.status='done';run.phase='finished';run.finishedAt=now();await writeRun(run);return{outcome:'complete'}}
  const product=run.products[run.cursor],categories=(await destinationCategories()).items,currentCategory=Number(product.categoryId)||0,tried=new Set(await getTriedBasalamCategories(product.shopId,product.id));
  // Sequential voting with early stop: models answer one by one; as soon as a category
  // reaches the majority threshold we stop asking the remaining models.
  const modelKeys=run.modelKeys,threshold=Math.floor(modelKeys.length/2)+1,votes=new Map<number,{count:number;row:any}>(),triedHits:number[]=[];
  let responded=0;
  for(const key of modelKeys){
    responded++;
    let suggestion:any;
    try{suggestion=await suggestCategoryWithModel(product.title,key,categories)}catch(error){suggestion={ok:false,key,error:error instanceof Error?error.message:String(error)}}
    if(!suggestion?.ok)continue;
    const id=Number(suggestion.categoryId);
    if(!(Number.isInteger(id)&&id>0))continue;
    if(tried.has(id)){triedHits.push(id);continue}
    const vote=votes.get(id)||{count:0,row:suggestion};vote.count++;votes.set(id,vote);
    if(vote.count>=threshold)break;
  }
  const winner=[...votes.values()].sort((a,b)=>b.count-a.count)[0];
  if(winner&&currentCategory&&Number(winner.row.categoryId)===currentCategory){
    // The model's majority already matches the product's stored category: nothing to do.
    run.processed++;appendCategoryItem(run,{...product,ok:true,categoryId:currentCategory,categoryName:String(winner.row.categoryName||''),source:`دستهٔ فعلی تأیید شد (${winner.count} از ${responded} مدل)`,confidence:responded?Math.round(winner.count/responded*100):0,error:undefined});
  }else if(winner){
    try{const source=`هوش مصنوعی سرورساید: ${winner.count} از ${responded} مدل`;await applyBasalamCategory(product.id,product.shopId,Number(winner.row.categoryId),product.title,String(winner.row.categoryName||''),source);run.changed++;appendCategoryItem(run,{...product,ok:true,categoryId:Number(winner.row.categoryId),categoryName:String(winner.row.categoryName||''),source,confidence:responded?Math.round(winner.count/responded*100):0})}
    catch(error){await markBasalamCategoriesTried(product.shopId,product.id,[Number(winner.row.categoryId)]);run.failed++;appendCategoryItem(run,{...product,ok:false,error:(error instanceof Error?error.message:String(error))+' (دستهٔ پیشنهادی برای این محصول ثبت شد تا دوباره امتحان نشود.)'})}
  }else if(triedHits.length){
    // Every suggestion the models made for this product was already tried before.
    run.failed++;appendCategoryItem(run,{...product,ok:false,error:'همهٔ دسته‌بندی‌های پیشنهادی مدل‌ها قبلاً برای این محصول امتحان شده‌اند و نتیجهٔ قطعی نداشتند؛ در اجرای بعدی از آنها صرف‌نظر می‌شود.'});
  }else{run.failed++;appendCategoryItem(run,{...product,ok:false,error:'هیچ مدل فعال، شناسهٔ دسته‌بندی معتبر برنگرداند.'})}
  run.cursor++;run.processed++;run.attempts=0;
  const latest=await readRun('category-all',run.id);if(latest?.stopRequested){run.stopRequested=true;run.status='paused';run.phase='paused'}else if(run.cursor>=run.products.length){run.status='done';run.phase='finished';run.finishedAt=now()}else{run.status='queued';run.phase='categorizing'}
  await writeRun(run);return{outcome:run.status==='queued'?'continue':'complete',delaySeconds:1};
}
async function processCategoryRun(run:CategoryRun):Promise<BackgroundOutcome>{
  if(run.stopRequested||run.status==='paused')return{outcome:'complete'};
  // No start write: categorizeOne/listCategoryProducts persist the checkpoint at
  // the end of every invocation, so an extra write here only wastes D1 write quota.
  run.status='running';run.startedAt ||= now();
  return run.phase==='listing'||run.products.length===0?listCategoryProducts(run):categorizeOne(run);
}

function appendDedupItem(run:DedupRun,item:DedupItemLog){run.items.push({...item,name:String(item.name||'').slice(0,160),...(item.error?{error:String(item.error).slice(0,400)}:{})});if(run.items.length>400)run.items=run.items.slice(-400)}
/** One catalog page per Queue message keeps every invocation far below the subrequest ceiling. */
async function dedupListPage(run:DedupRun):Promise<BackgroundOutcome>{
  const data:any=await destinationCatalog(run.target,{page:run.page,perPage:100,status:'all',shopId:'all'}),seen=new Set(run.products.map(row=>`${row.shopId}:${row.id}`));
  for(const raw of data.products||[]){
    const status=String(raw.status||'');
    if(run.target==='woo'&&status==='trash')continue;
    if(run.target==='basalam'&&status==='4184')continue;
    const id=Number(raw.id)||0,shopId=String(raw.shopId||'default'),key=`${shopId}:${id}`;
    if(id<=0||seen.has(key))continue;seen.add(key);
    run.products.push({id,shopId,name:String(raw.name||raw.title||'').slice(0,200),price:Number(raw.price)||0,date:String(raw.raw?.date_created||raw.raw?.created_at||raw.raw?.createdAt||''),status,sku:String(raw.sku||'').slice(0,100)});
  }
  run.totalPages=Math.max(run.totalPages,Number(data.totalPages)||1);run.scanned=run.products.length;run.attempts=0;
  if(run.page<run.totalPages&&run.page<200){run.page++;run.phase='listing'}else{run.listingDone=true;run.phase='grouping'}
  run.status='queued';await writeRun(run);return{outcome:'continue',delaySeconds:1};
}
async function dedupGroupProducts(run:DedupRun):Promise<BackgroundOutcome>{
  run.groups=buildDedupGroups(run.products,run.keep,run.suffixFormats);
  run.groupsFound=run.groups.length;run.duplicates=run.groups.reduce((sum,group)=>sum+group.remove.length,0);
  run.products=[];run.grouped=true;run.attempts=0;run.groupCursor=0;run.removeCursor=0;
  if(!run.apply||run.duplicates===0){run.status='done';run.phase='finished';run.finishedAt=now();await writeRun(run);return{outcome:'complete'}}
  run.status='queued';run.phase='removing';await writeRun(run);return{outcome:'continue',delaySeconds:1};
}
/**
 * Removes up to DEDUP_REMOVE_BATCH duplicates per invocation. Every invocation costs
 * ~3 D1 writes (lease + checkpoint + lease release) regardless of how many products
 * it removes, so a bigger batch cuts the daily D1 write quota burn significantly.
 * 10 sequential destination status calls stay far below the subrequest ceiling.
 */
const DEDUP_REMOVE_BATCH=10;
/** Woo goes to trash, Basalam to archive 4184 — both reversible. */
async function dedupRemoveBatch(run:DedupRun):Promise<BackgroundOutcome>{
  let done=0;
  while(done<DEDUP_REMOVE_BATCH){
    const group=run.groups[run.groupCursor];
    if(!group)break;
    const item=group.remove[run.removeCursor];
    if(!item){run.groupCursor++;run.removeCursor=0;continue}
    try{
      if(run.target==='woo')await destinationChangeStatus('woo',item.id,'trash');
      else await destinationChangeStatus('basalam',item.id,'4184',item.shopId);
      run.removed++;appendDedupItem(run,{id:item.id,shopId:item.shopId,name:item.name,ok:true,action:run.target==='woo'?'به زباله‌دان ووکامرس منتقل شد':'در باسلام بایگانی (۴۱۸۴) شد'});
    }catch(error){run.failed++;appendDedupItem(run,{id:item.id,shopId:item.shopId,name:item.name,ok:false,action:'ناموفق',error:error instanceof Error?error.message:String(error)})}
    run.removeCursor++;done++;
  }
  run.attempts=0;
  const finished=!run.groups[run.groupCursor];
  const latest=await readRun('dedup',run.id);
  if(latest?.stopRequested){run.stopRequested=true;run.status='paused';run.phase='paused'}
  else if(finished){run.status='done';run.phase='finished';run.finishedAt=now()}
  else{run.status='queued';run.phase='removing'}
  await writeRun(run);return{outcome:run.status==='queued'?'continue':'complete',delaySeconds:1};
}
async function processDedupRun(run:DedupRun):Promise<BackgroundOutcome>{
  if(run.stopRequested||run.status==='paused')return{outcome:'complete'};
  // No write here on purpose: every phase function persists the full checkpoint
  // at the end of the invocation, so this extra write would only burn D1's daily
  // write quota (100k rows/day on the free plan) without adding safety.
  run.status='running';run.startedAt ||= now();
  if(!run.listingDone)return dedupListPage(run);
  if(!run.grouped)return dedupGroupProducts(run);
  return dedupRemoveBatch(run);
}

export async function processBackgroundMessage(message:BackgroundMessage):Promise<BackgroundOutcome>{
  if(message.task==='agent')return processAgentRunMessage(message as {task:'agent';runId:string});
  if(message.task!=='ai-test'&&message.task!=='category-all'&&message.task!=='dedup')return{outcome:'ignored'};
  const token=await claimRun(message.task,message.runId);if(!token)return{outcome:'ignored'};
  try{
    const run=await readRun(message.task,message.runId);if(!run||['done','failed','paused'].includes(run.status))return{outcome:'ignored'};
    try{return run.kind==='ai-test'?await processAiTest(run):run.kind==='dedup'?await processDedupRun(run):await processCategoryRun(run)}catch(error){
      run.attempts=(run.attempts||0)+1;run.error=error instanceof Error?error.message:String(error);
      if(run.attempts>=5){run.status='failed';run.phase='failed';run.finishedAt=now()}else{run.status='queued';run.phase=run.kind==='category-all'&&run.products.length===0?'listing':'retrying'}
      await writeRun(run);return{outcome:run.status==='failed'?'complete':'continue',delaySeconds:Math.min(60,Math.pow(2,run.attempts))};
    }
  }finally{await releaseRun(message.task,message.runId,token)}
}

export async function recoverBackgroundRuns(waitUntil?:(promise:Promise<unknown>)=>void):Promise<void>{
  await recoverAgentRun(waitUntil);
  for(const kind of ['ai-test','category-all','dedup'] as const){
    const run=await currentBackgroundRun(kind);
    if(!run||run.stopRequested||run.status==='paused'||['done','failed'].includes(run.status))continue;
    if(runAge(run)<=STALL_MS)continue;
    if(run.kind==='ai-test'){
      run.skipNext=true;run.status='queued';run.phase='watchdog-skip';run.error=null;await writeRun(run);await enqueue({task:kind,runId:run.id},waitUntil);continue;
    }
    run.status='queued';run.phase='recovered';await writeRun(run);await enqueue({task:kind,runId:run.id},waitUntil);
  }
}
