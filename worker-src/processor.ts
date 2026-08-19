import { claimJob, deleteState, getJob, getProfile, getState, listProducts, markMissingProducts, markProfileRun, setState, stopRequested, updateJob, upsertProduct } from './db.js';
import { getEnv } from './env.js';
import { mapLimit, pageUrl, scrapeDetails, scrapeListPage, transformProduct } from './scraper.js';
import { syncBasalam, syncWoo } from './sync.js';
import { message } from './utils.js';
import type { Job, Product, Profile } from './types.js';

type ProcessResult='complete'|'continue'|'ignored';
type ScrapeCheckpoint={page:number;url:string;nextUrl:string;index:number;products?:Product[];seen:string[]};
type SyncCheckpoint={offset:number};
const stateKey=(jobId:string)=>`job_checkpoint:${jobId}`;
function chunkSize():number{return Math.min(100,Math.max(1,Number(getEnv().JOB_CHUNK_SIZE)||20))}
function append(job:Job,text:string,level='info'){job.log.push({at:new Date().toISOString(),level,message:text});if(job.log.length>200)job.log=job.log.slice(-200)}
async function save(job:Job){await updateJob(job.id,{status:job.status,phase:job.phase,total:job.total,processed:job.processed,added:job.added,updated:job.updated,failed:job.failed,error:job.error,log:job.log,finishedAt:job.finishedAt})}

export async function enqueueJob(job:Job,waitUntil?:(promise:Promise<unknown>)=>void):Promise<void>{
  const queue=getEnv().JOBS;
  if(queue){await queue.send({jobId:job.id});return}
  const promise=drainInline(job.id);
  if(waitUntil){waitUntil(promise);return}
  await promise;
}
async function drainInline(jobId:string):Promise<void>{
  // Local fallback is intentionally bounded. Production deployments should bind JOBS;
  // any remaining queued checkpoint is recovered by the scheduled handler.
  for(let i=0;i<5;i++){const result=await processJob(jobId);if(result!=='continue')return}
}

export async function processJob(id:string):Promise<ProcessResult>{
  const job=await claimJob(id);
  if(!job)return'ignored';
  try{
    const profile=await getProfile(job.profileId);
    if(!profile)throw new Error('Profile not found');
    append(job,`شروع/ادامه ${job.kind==='scrape'?'استخراج':'همگام‌سازی'} «${profile.name}»`);
    const more=job.kind==='scrape'?await runScrapeChunk(job,profile):await runSyncChunk(job,profile);
    if(job.status==='stopped'){
      await deleteState(stateKey(job.id));
      job.finishedAt=new Date().toISOString();job.phase='finished';append(job,'عملیات متوقف شد','warning');await save(job);return'complete';
    }
    if(more){job.status='queued';append(job,'نقطهٔ بازیابی ذخیره شد؛ ادامه در پیام بعدی صف');await save(job);return'continue'}
    job.status='done';job.finishedAt=new Date().toISOString();job.phase='finished';append(job,'عملیات با موفقیت تمام شد');await deleteState(stateKey(job.id));await save(job);return'complete';
  }catch(error){
    job.status='failed';job.error=message(error);job.finishedAt=new Date().toISOString();job.phase='finished';append(job,job.error,'error');await save(job);return'complete';
  }
}

async function runScrapeChunk(job:Job,profile:Profile):Promise<boolean>{
  const key=stateKey(job.id);
  const checkpoint=await getState<ScrapeCheckpoint>(key,{page:1,url:pageUrl(profile,1),nextUrl:'',index:0,seen:[]});
  if(await stopRequested(job.id)){job.status='stopped';return false}
  if(!checkpoint.products){
    job.phase='list';
    append(job,`صفحه ${checkpoint.page}: ${checkpoint.url}`);
    const page=await scrapeListPage(checkpoint.url,profile.selectors,profile.pagination==='next_selector'?profile.paginationValue:'',Boolean(profile.networkIndirect));
    checkpoint.url=page.url;checkpoint.nextUrl=page.nextUrl;checkpoint.index=0;
    checkpoint.products=page.products.map(raw=>transformProduct(raw,profile)).filter(product=>!profile.minPrice||product.price>=profile.minPrice);
    if(!checkpoint.products.length){append(job,'محصولی پیدا نشد؛ پیمایش پایان یافت','warning');await finishScrape(job,profile,checkpoint);return false}
    job.total+=checkpoint.products.length;
    await setState(key,checkpoint);await save(job);
  }
  job.phase='details-save-sync';
  const start=checkpoint.index,end=Math.min(checkpoint.products.length,start+chunkSize()),batch=checkpoint.products.slice(start,end);
  await mapLimit(batch,Math.min(8,Math.max(1,Number(getEnv().DETAIL_CONCURRENCY)||4)),async product=>{
    try{await scrapeDetails(product,profile.selectors,Boolean(profile.networkIndirect))}catch(error){job.failed++;append(job,`${product.title}: جزئیات: ${message(error)}`,'error')}
  });
  for(const product of batch){
    if(await stopRequested(job.id)){job.status='stopped';await setState(key,checkpoint);return false}
    try{const result=await upsertProduct(profile.id,product);result==='added'?job.added++:job.updated++}
    catch(error){job.failed++;append(job,`${product.title}: ذخیره: ${message(error)}`,'error')}
    await syncProduct(job,profile,product);
    checkpoint.seen.push(product.sourceKey);checkpoint.index++;job.processed++;
  }
  checkpoint.seen=[...new Set(checkpoint.seen)];
  if(checkpoint.index<checkpoint.products.length){await setState(key,checkpoint);await save(job);return true}
  const hasNext=checkpoint.page<profile.pages&&(profile.pagination==='next_selector'?Boolean(checkpoint.nextUrl):profile.pagination!=='none');
  if(hasNext){checkpoint.page++;checkpoint.url=profile.pagination==='next_selector'?checkpoint.nextUrl:pageUrl(profile,checkpoint.page);checkpoint.nextUrl='';checkpoint.index=0;delete checkpoint.products;await setState(key,checkpoint);await save(job);return true}
  await finishScrape(job,profile,checkpoint);return false;
}
async function finishScrape(job:Job,profile:Profile,checkpoint:ScrapeCheckpoint):Promise<void>{
  job.phase='retire';
  const retired=await markMissingProducts(profile.id,checkpoint.seen);
  if(retired)append(job,`${retired} محصول دیگر در مبدأ دیده نشد`,'warning');
  await markProfileRun(profile.id);
}

async function runSyncChunk(job:Job,profile:Profile):Promise<boolean>{
  const key=stateKey(job.id),checkpoint=await getState<SyncCheckpoint>(key,{offset:0});
  if(await stopRequested(job.id)){job.status='stopped';return false}
  job.phase='sync';
  const result=await listProducts(profile.id,chunkSize(),checkpoint.offset,'');job.total=result.total;
  for(const product of result.products){
    if(await stopRequested(job.id)){job.status='stopped';await setState(key,checkpoint);return false}
    await syncProduct(job,profile,product);
    checkpoint.offset++;job.processed++;
  }
  await setState(key,checkpoint);await save(job);
  return checkpoint.offset<result.total;
}

async function syncProduct(job:Job,profile:Profile,product:Product):Promise<void>{
  if(job.target==='woo'||job.target==='both')try{await syncWoo(product,profile)}catch(error){job.failed++;append(job,`${product.title} [WooCommerce]: ${message(error)}`,'error')}
  if(job.target==='basalam'||job.target==='both')try{await syncBasalam(product,profile)}catch(error){job.failed++;append(job,`${product.title} [Basalam]: ${message(error)}`,'error')}
}

export async function retryAndEnqueue(id:string,waitUntil?:(promise:Promise<unknown>)=>void):Promise<Job|null>{
  const job=await getJob(id);if(!job)return null;
  await deleteState(stateKey(id));
  await updateJob(id,{status:'queued',phase:'waiting',stopRequested:false,error:null,finishedAt:null,processed:0,added:0,updated:0,failed:0});
  const queued=await getJob(id);if(queued)await enqueueJob(queued,waitUntil);return queued;
}
