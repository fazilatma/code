import { claimJob, deleteState, findMissingProducts, getJob, getProduct, getProfile, getState, listProducts, markMissingProducts, markProfileRun, setState, stopRequested, updateJob, upsertProduct } from './db.js';
import { getEnv } from './env.js';
import { mapLimit, pageUrl, scrapeDetails, scrapeListPage, transformProduct } from './scraper.js';
import { syncBasalam, syncWoo } from './sync.js';
import { message } from './utils.js';
import type { Job, Product, Profile } from './types.js';

type ProcessResult='complete'|'continue'|'ignored';
type ScrapeCheckpoint={page:number;url:string;nextUrl:string;index:number;products?:Product[];seen:string[];retireSafe?:boolean};
type SyncCheckpoint={offset:number};
const stateKey=(jobId:string)=>`job_checkpoint:${jobId}`;
// Ten products keep detail + Woo + Basalam requests below the Free-plan subrequest ceiling.
function chunkSize():number{return Math.min(50,Math.max(1,Number(getEnv().JOB_CHUNK_SIZE)||10))}
function preserveExisting(fresh:Product,previous:Product|null):Product{
  if(!previous)return fresh;
  return {...fresh,
    title:fresh.title||previous.title,price:fresh.price>0?fresh.price:previous.price,priceText:fresh.priceText||previous.priceText,url:fresh.url||previous.url,
    image:fresh.image||previous.image,images:[...new Set([fresh.image,...(previous.images||[]),...(fresh.images||[])].filter(Boolean))],
    shortDesc:fresh.shortDesc||previous.shortDesc,longDesc:fresh.longDesc||previous.longDesc,sku:fresh.sku||previous.sku,brand:fresh.brand||previous.brand,
    stock:fresh.stock??previous.stock,weight:fresh.weight??previous.weight,category:fresh.category||previous.category,tags:fresh.tags||previous.tags,
    variations:fresh.variations?.length?fresh.variations:previous.variations,variationGroups:fresh.variationGroups?.length?fresh.variationGroups:previous.variationGroups,
    variationPrices:Object.keys(fresh.variationPrices||{}).length?fresh.variationPrices:previous.variationPrices
  };
}
type JobLog=Job['log'][number];
function reportItem(product:Product,extra:Partial<NonNullable<JobLog['item']>>={}):NonNullable<JobLog['item']>{return{sourceKey:product.sourceKey,title:product.title,url:product.url,...extra}}
function append(job:Job,text:string,level='info',event?:JobLog['event'],item?:JobLog['item']){job.log.push({at:new Date().toISOString(),level,message:text,event,item});if(job.log.length>1500)job.log=job.log.slice(-1500)}
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
  const checkpoint=await getState<ScrapeCheckpoint>(key,{page:1,url:pageUrl(profile,1),nextUrl:'',index:0,seen:[],retireSafe:true});
  checkpoint.retireSafe ??= true;
  if(await stopRequested(job.id)){job.status='stopped';return false}
  if(!checkpoint.products){
    job.phase='list';
    append(job,`صفحه ${checkpoint.page}: ${checkpoint.url}`);
    const page=await scrapeListPage(checkpoint.url,profile.selectors,profile.pagination==='next_selector'?profile.paginationValue:'',Boolean(profile.networkIndirect));
    checkpoint.url=page.url;checkpoint.nextUrl=page.nextUrl;checkpoint.index=0;
    const pageProducts=page.products.map(raw=>transformProduct(raw,profile)).filter(product=>!profile.minPrice||product.price>=profile.minPrice);
    checkpoint.products=pageProducts.filter(product=>!checkpoint.seen.includes(product.sourceKey));
    if(!pageProducts.length){
      checkpoint.retireSafe=false;
      if(checkpoint.page===1)throw new Error('در صفحهٔ اول هیچ محصولی استخراج نشد. سلکتور ظرف/فیلدها، پاسخ ضدربات و HTML مبدأ را بررسی کنید؛ محصولات قبلی بازنشسته نشدند.');
      append(job,`صفحه ${checkpoint.page} خالی بود؛ برای جلوگیری از حذف اشتباه، بازنشسته‌سازی محصولات انجام نمی‌شود.`,'warning');await finishScrape(job,profile,checkpoint);return false;
    }
    if(!checkpoint.products.length){checkpoint.retireSafe=false;append(job,`صفحه ${checkpoint.page} فقط محصولات تکراری داشت؛ حلقهٔ صفحه‌بندی متوقف شد و بازنشسته‌سازی انجام نمی‌شود.`,'warning');await finishScrape(job,profile,checkpoint);return false}
    job.total+=checkpoint.products.length;
    await setState(key,checkpoint);await save(job);
  }
  job.phase='details-save-sync';
  const start=checkpoint.index,end=Math.min(checkpoint.products.length,start+chunkSize()),batch=checkpoint.products.slice(start,end),previousByKey=new Map<string,Product|null>(),rawPriceByKey=new Map<string,number>();
  await mapLimit(batch,Math.min(4,Math.max(1,Number(getEnv().DETAIL_CONCURRENCY)||2)),async product=>{
    const previous=await getProduct(profile.id,product.sourceKey);previousByKey.set(product.sourceKey,previous);rawPriceByKey.set(product.sourceKey,product.price);Object.assign(product,preserveExisting(product,previous));
    try{Object.assign(product,await scrapeDetails(product,profile.selectors,Boolean(profile.networkIndirect)))}catch(error){const errorText=message(error);job.failed++;append(job,`${product.title}: جزئیات: ${errorText}؛ اطلاعات معتبر قبلی حفظ شد.`,'error','failed',reportItem(product,{error:errorText}))}
  });
  for(const product of batch){
    if(await stopRequested(job.id)){job.status='stopped';await setState(key,checkpoint);return false}
    const previous=previousByKey.get(product.sourceKey)||null,rawPrice=rawPriceByKey.get(product.sourceKey)??product.price;
    if(rawPrice<=0)append(job,`${product.title}: قیمت صفر یا نامعتبر از مبدأ دریافت شد.`,'warning','zero-price',reportItem(product,{newPrice:rawPrice}));
    if(product.stock===0)append(job,`${product.title}: موجودی مبدأ به صفر رسیده است.`,'warning','out-of-stock',reportItem(product));
    if(previous&&previous.price>0&&product.price>0&&previous.price!==product.price){const delta=product.price-previous.price,percent=Number((delta/previous.price*100).toFixed(2));append(job,`${product.title}: قیمت ${delta>0?'افزایش':'کاهش'} یافت (${percent}٪).`,delta>0?'warning':'info',delta>0?'price-increased':'price-decreased',reportItem(product,{oldPrice:previous.price,newPrice:product.price,delta,percent}))}
    let saved=false;
    try{const result=await upsertProduct(profile.id,product);result==='added'?job.added++:job.updated++;append(job,`${product.title}: ${result==='added'?'محصول جدید ثبت شد':'اطلاعات محصول به‌روزرسانی شد'}`,'info',result,reportItem(product));saved=true}
    catch(error){const errorText=message(error);checkpoint.retireSafe=false;job.failed++;append(job,`${product.title}: ذخیره: ${errorText}`,'error','failed',reportItem(product,{error:errorText}))}
    if(saved){await syncProduct(job,profile,product);checkpoint.seen.push(product.sourceKey)}
    checkpoint.index++;job.processed++;
  }
  checkpoint.seen=[...new Set(checkpoint.seen)];
  if(checkpoint.index<checkpoint.products.length){await setState(key,checkpoint);await save(job);return true}
  const hasNext=checkpoint.page<profile.pages&&(profile.pagination==='next_selector'?Boolean(checkpoint.nextUrl):profile.pagination!=='none');
  if(hasNext){checkpoint.page++;checkpoint.url=profile.pagination==='next_selector'?checkpoint.nextUrl:pageUrl(profile,checkpoint.page);checkpoint.nextUrl='';checkpoint.index=0;delete checkpoint.products;await setState(key,checkpoint);await save(job);return true}
  await finishScrape(job,profile,checkpoint);return false;
}
async function finishScrape(job:Job,profile:Profile,checkpoint:ScrapeCheckpoint):Promise<void>{
  job.phase='retire';
  if(checkpoint.retireSafe&&checkpoint.seen.length){
    const missing=await findMissingProducts(profile.id,checkpoint.seen),retired=await markMissingProducts(profile.id,checkpoint.seen);
    for(const product of missing.slice(0,1000))append(job,`${product.title}: دیگر در مبدأ دیده نشد و بازنشسته شد.`,'warning','removed',reportItem(product));
    if(retired)append(job,`${retired} محصول دیگر در مبدأ دیده نشد`,'warning');
  }else append(job,'اسکن کامل و قابل‌اعتماد نبود؛ برای ایمنی هیچ محصولی بازنشسته نشد.','warning');
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
  if(job.target==='woo'||job.target==='both')try{const action=await syncWoo(product,profile);append(job,`${product.title} [WooCommerce]: ${action==='created'?'ایجاد':'به‌روزرسانی'} شد.`,'info',action==='created'?'sync-created':'sync-updated',reportItem(product,{target:'woo',shop:'فروشگاه ووکامرس'}))}catch(error){const errorText=message(error);job.failed++;append(job,`${product.title} [WooCommerce]: ${errorText}`,'error','failed',reportItem(product,{target:'woo',error:errorText}))}
  if(job.target==='basalam'||job.target==='both')try{const results=await syncBasalam(product,profile);for(const result of results)append(job,`${product.title} [Basalam · ${result.shop}]: ${result.action==='created'?'ایجاد':'به‌روزرسانی'} شد.`,'info',result.action==='created'?'sync-created':'sync-updated',reportItem(product,{target:'basalam',shop:result.shop}))}catch(error){const errorText=message(error);job.failed++;append(job,`${product.title} [Basalam]: ${errorText}`,'error','failed',reportItem(product,{target:'basalam',error:errorText}))}
}

export async function retryAndEnqueue(id:string,waitUntil?:(promise:Promise<unknown>)=>void):Promise<Job|null>{
  const job=await getJob(id);if(!job)return null;
  await deleteState(stateKey(id));
  await updateJob(id,{status:'queued',phase:'waiting',stopRequested:false,error:null,finishedAt:null,processed:0,added:0,updated:0,failed:0,log:[]});
  const queued=await getJob(id);if(queued)await enqueueJob(queued,waitUntil);return queued;
}
