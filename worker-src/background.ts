import { getState, setState } from './db.js';
import { getEnv } from './env.js';
import { getLastAiTestResults, isChatCompatibleAiModel, suggestCategoryWithModel, testModelBatch } from './ai.js';
import { loadConnections } from './connections.js';
import { applyBasalamCategory, destinationCatalog, destinationCategories } from './maintenance.js';
import type { BackgroundMessage } from './types.js';

export type BackgroundOutcome={outcome:'complete'|'continue'|'ignored';delaySeconds?:number};
type RunStatus='queued'|'running'|'paused'|'done'|'failed';
type BaseRun={id:string;kind:'ai-test'|'category-all';status:RunStatus;phase:string;stopRequested:boolean;createdAt:string;updatedAt:string;startedAt:string|null;finishedAt:string|null;attempts:number;error:string|null};
type AiTestRun=BaseRun&{kind:'ai-test';prompt:string;categoryTitle:string;onlyCandidates:boolean;delayMs:number;cursor:number;result:any};
type CategoryProduct={id:number;shopId:string;title:string};
type CategoryRunItem={id:number;shopId:string;title:string;ok:boolean;categoryId?:number;categoryName?:string;source?:string;confidence?:number;error?:string};
type CategoryRun=BaseRun&{kind:'category-all';modelKeys:string[];page:number;totalPages:number;products:CategoryProduct[];cursor:number;total:number;processed:number;changed:number;failed:number;items:CategoryRunItem[]};
export type BackgroundRun=AiTestRun|CategoryRun;

const pointerKey=(kind:BackgroundRun['kind'])=>`background_current:${kind}`;
const runKey=(kind:BackgroundRun['kind'],id:string)=>`background_run:${kind}:${id}`;
const leaseKey=(kind:BackgroundRun['kind'],id:string)=>`background_lease:${kind}:${id}`;
const now=()=>new Date().toISOString();
const active=(run:BackgroundRun|null)=>Boolean(run&&['queued','running','paused'].includes(run.status));

// Queue delivery is at-least-once. This D1 compare-and-set lease ensures two deliveries
// cannot process the same checkpoint concurrently. A crashed isolate is recoverable after
// the lease expires and the scheduled recovery pass re-enqueues the run.
async function claimRun(kind:BackgroundRun['kind'],id:string):Promise<string|null>{
  const token=crypto.randomUUID(),key=leaseKey(kind,id),stamp=now(),stale=new Date(Date.now()-5*60_000).toISOString(),value=JSON.stringify({token});
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
  const{products,...safe}=run;return safe;
}
export async function getPublicBackgroundRun(kind:BackgroundRun['kind']):Promise<any>{return publicRun(await currentBackgroundRun(kind))}

async function enqueue(message:BackgroundMessage,waitUntil?:(promise:Promise<unknown>)=>void):Promise<void>{
  const queue=getEnv().JOBS;
  if(queue){await queue.send(message);return}
  const promise=drainInline(message);
  if(waitUntil)waitUntil(promise);else await promise;
}
async function drainInline(message:BackgroundMessage){for(let i=0;i<5;i++){const result=await processBackgroundMessage(message);if(result.outcome!=='continue')break}}

export async function startAiTestRun(input:any,waitUntil?:(promise:Promise<unknown>)=>void):Promise<{run:any;existing:boolean}>{
  const previous=await currentBackgroundRun('ai-test');if(active(previous))return{run:publicRun(previous),existing:true};
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

export async function controlBackgroundRun(kind:BackgroundRun['kind'],action:'stop'|'resume',waitUntil?:(promise:Promise<unknown>)=>void):Promise<any>{
  const run=await currentBackgroundRun(kind);if(!run)throw new Error('اجرای پس‌زمینه‌ای پیدا نشد.');
  if(action==='stop'){
    if(['done','failed'].includes(run.status))return publicRun(run);
    run.stopRequested=true;run.status='paused';run.phase='paused';await writeRun(run);return publicRun(run);
  }
  if(!['paused','failed'].includes(run.status))return publicRun(run);
  run.stopRequested=false;run.status='queued';run.phase=run.kind==='category-all'&&run.products.length===0?'listing':'waiting';run.error=null;run.finishedAt=null;run.attempts=0;await writeRun(run);await enqueue({task:run.kind,runId:run.id},waitUntil);return publicRun(run);
}

async function processAiTest(run:AiTestRun):Promise<BackgroundOutcome>{
  if(run.stopRequested||run.status==='paused')return{outcome:'complete'};
  run.status='running';run.phase='testing';run.startedAt ||= now();await writeRun(run);
  let categories:any[]=[];if(run.categoryTitle)try{categories=(await destinationCategories()).items}catch{/* Model response tests continue without Basalam categories. */}
  const result=await testModelBatch(run.prompt,{runId:run.id,cursor:run.cursor,onlyCandidates:run.onlyCandidates,categoryTitle:run.categoryTitle,categories});
  run.cursor=Number(result.nextCursor||run.cursor);run.result={...result,serverSide:true};run.attempts=0;
  const latest=await readRun('ai-test',run.id);if(latest?.stopRequested){run.stopRequested=true;run.status='paused';run.phase='paused'}else if(result.done){run.status='done';run.phase='finished';run.finishedAt=now()}else{run.status='queued';run.phase='waiting'}
  await writeRun(run);return{outcome:result.done||run.status==='paused'?'complete':'continue',delaySeconds:run.delayMs?Math.max(1,Math.ceil(run.delayMs/1000)):1};
}

function compactCategoryItem(row:any):CategoryRunItem{return{id:Number(row.id),shopId:String(row.shopId||''),title:String(row.title||''),ok:Boolean(row.ok),...(row.categoryId?{categoryId:Number(row.categoryId)}:{}),...(row.categoryName?{categoryName:String(row.categoryName)}:{}),...(row.source?{source:String(row.source)}:{}),...(row.confidence?{confidence:Number(row.confidence)}:{}),...(row.error?{error:String(row.error).slice(0,500)}:{})}}
function appendCategoryItem(run:CategoryRun,item:CategoryRunItem){run.items.push(compactCategoryItem(item));if(run.items.length>300)run.items=run.items.slice(-300)}
async function listCategoryProducts(run:CategoryRun):Promise<BackgroundOutcome>{
  const data:any=await destinationCatalog('basalam',{page:run.page,perPage:100,status:'3567',shopId:'all'}),seen=new Set(run.products.map(row=>`${row.shopId}:${row.id}`));
  for(const raw of data.products||[]){const row={id:Number(raw.id),shopId:String(raw.shopId||''),title:String(raw.title||raw.name||'').trim()},key=`${row.shopId}:${row.id}`;if(row.id>0&&row.title&&!seen.has(key)){seen.add(key);run.products.push(row)}}
  run.totalPages=Math.max(run.totalPages,Number(data.totalPages)||1);run.total=Math.max(Number(data.total)||0,run.products.length);run.attempts=0;
  if(run.page<run.totalPages){run.page++;run.status='queued';run.phase='listing'}else{run.total=run.products.length;run.status='queued';run.phase='categorizing'}
  await writeRun(run);return{outcome:'continue',delaySeconds:1};
}
async function categorizeOne(run:CategoryRun):Promise<BackgroundOutcome>{
  if(run.cursor>=run.products.length){run.status='done';run.phase='finished';run.finishedAt=now();await writeRun(run);return{outcome:'complete'}}
  const product=run.products[run.cursor],categories=(await destinationCategories()).items,suggestions=await Promise.all(run.modelKeys.map(async key=>{try{return await suggestCategoryWithModel(product.title,key,categories)}catch(error){return{ok:false,key,error:error instanceof Error?error.message:String(error)}}})),valid=suggestions.filter((row:any)=>row.ok&&Number(row.categoryId)>0),votes=new Map<number,{count:number;row:any}>();
  for(const row of valid){const id=Number(row.categoryId),vote=votes.get(id)||{count:0,row};vote.count++;votes.set(id,vote)}
  const winner=[...votes.values()].sort((a,b)=>b.count-a.count)[0];
  if(winner){
    try{const source=`هوش مصنوعی سرورساید: ${winner.count} از ${valid.length} رأی`;await applyBasalamCategory(product.id,product.shopId,Number(winner.row.categoryId),product.title,String(winner.row.categoryName||''),source);run.changed++;appendCategoryItem(run,{...product,ok:true,categoryId:Number(winner.row.categoryId),categoryName:String(winner.row.categoryName||''),source,confidence:valid.length?Math.round(winner.count/valid.length*100):0})}
    catch(error){run.failed++;appendCategoryItem(run,{...product,ok:false,error:error instanceof Error?error.message:String(error)})}
  }else{run.failed++;appendCategoryItem(run,{...product,ok:false,error:'هیچ مدل فعال، شناسهٔ دسته‌بندی معتبر برنگرداند.'})}
  run.cursor++;run.processed++;run.attempts=0;
  const latest=await readRun('category-all',run.id);if(latest?.stopRequested){run.stopRequested=true;run.status='paused';run.phase='paused'}else if(run.cursor>=run.products.length){run.status='done';run.phase='finished';run.finishedAt=now()}else{run.status='queued';run.phase='categorizing'}
  await writeRun(run);return{outcome:run.status==='queued'?'continue':'complete',delaySeconds:1};
}
async function processCategoryRun(run:CategoryRun):Promise<BackgroundOutcome>{
  if(run.stopRequested||run.status==='paused')return{outcome:'complete'};
  run.status='running';run.startedAt ||= now();await writeRun(run);
  return run.phase==='listing'||run.products.length===0?listCategoryProducts(run):categorizeOne(run);
}

export async function processBackgroundMessage(message:BackgroundMessage):Promise<BackgroundOutcome>{
  if(message.task!=='ai-test'&&message.task!=='category-all')return{outcome:'ignored'};
  const token=await claimRun(message.task,message.runId);if(!token)return{outcome:'ignored'};
  try{
    const run=await readRun(message.task,message.runId);if(!run||['done','failed','paused'].includes(run.status))return{outcome:'ignored'};
    try{return run.kind==='ai-test'?await processAiTest(run):await processCategoryRun(run)}catch(error){
      run.attempts=(run.attempts||0)+1;run.error=error instanceof Error?error.message:String(error);
      if(run.attempts>=5){run.status='failed';run.phase='failed';run.finishedAt=now()}else{run.status='queued';run.phase=run.kind==='category-all'&&run.products.length===0?'listing':'retrying'}
      await writeRun(run);return{outcome:run.status==='failed'?'complete':'continue',delaySeconds:Math.min(60,Math.pow(2,run.attempts))};
    }
  }finally{await releaseRun(message.task,message.runId,token)}
}

export async function recoverBackgroundRuns(waitUntil?:(promise:Promise<unknown>)=>void):Promise<void>{
  for(const kind of ['ai-test','category-all'] as const){const run=await currentBackgroundRun(kind);if(!run||run.stopRequested||run.status==='paused'||['done','failed'].includes(run.status))continue;const stale=Date.now()-Date.parse(run.updatedAt)>5*60_000;if(run.status==='queued'||stale){if(stale){run.status='queued';run.phase='recovered';await writeRun(run)}await enqueue({task:kind,runId:run.id},waitUntil)}}
}
