/**
 * Agentic AI engine: free tool-calling models drive server-side operations on the site.
 * Prompts (with optional minute-based schedules) are stored in D1 and executed on the
 * Queue with checkpoints, so long agent runs never depend on an open browser tab.
 */
import { aiAgentCall, aiProviders, type AiTool } from './ai.js';
import { loadConnections } from './connections.js';
import { deleteAgentPrompt, deleteAgentRun, deleteAgentRunsForPrompt, getAgentPrompt, getAgentRun, getState, listAgentPrompts, listAgentRuns, listAutoreplyLog, listCategoryLearning, listJobs, listProducts, listProfiles, profileStats, saveAgentPrompt, saveAgentRun, setState, touchAgentPromptLastRun, updateAgentRun, deleteState, type AgentPromptRow, type AgentRunRow } from './db.js';
import { buildDedupGroups, normalizeDedupKeep, parseSuffixFormats, type DedupCandidate } from './dedup.js';
import { getEnv } from './env.js';
import { destinationCatalog } from './maintenance.js';

export type AgentLog={at:string;step:number;type:'info'|'call'|'tool'|'text'|'error'|'done';text:string;tool?:string;ok?:boolean};
export type AgentRun={id:string;promptId:string;name:string;prompt:string;providerId:string;model:string;tools:string[];maxSteps:number;status:'queued'|'running'|'paused'|'done'|'failed';phase:string;messages:Array<{role:string;content:string|null;tool_call_id?:string;tool_calls?:Array<{id:string;type:string;function:{name:string;arguments:string}}>}>;logs:AgentLog[];steps:number;result:string|null;stopRequested:boolean;attempts:number;error:string|null;createdAt:string;updatedAt:string;startedAt:string|null;finishedAt:string|null};

export type AgentOutcome={outcome:'complete'|'continue'|'ignored';delaySeconds?:number};

// ─── Free tool-calling models (Cloudflare Workers AI free tier unless noted) ──
export type AgentToolModel={id:string;name:string;vendor:string;free:boolean;note:string};
export const AGENT_TOOL_MODELS:AgentToolModel[]=[
  {id:'@cf/meta/llama-3.1-8b-instruct',name:'Llama 3.1 8B Instruct',vendor:'Meta — Workers AI',free:true,note:'رایگان روی Workers AI؛ فراخوانی ابزار (tool use) را پشتیبانی می‌کند و برای اتوماسیون سبک مناسب است.'},
  {id:'@cf/qwen/qwen2.5-coder-32b-instruct',name:'Qwen2.5 Coder 32B Instruct',vendor:'Alibaba — Workers AI',free:true,note:'رایگان روی Workers AI؛ قدرتمند در فراخوانی ابزار و کارهای چندمرحله‌ای.'},
  {id:'@cf/meta/llama-3.3-70b-instruct-fp8-fast',name:'Llama 3.3 70B Instruct (FP8 Fast)',vendor:'Meta — Workers AI',free:true,note:'رایگان روی Workers AI؛ دقت بالا برای تحلیل‌های پیچیده.'},
  {id:'@cf/meta/llama-4-scout-17b-16e-instruct',name:'Llama 4 Scout 17B',vendor:'Meta — Workers AI',free:true,note:'نسل جدید Llama با پشتیبانی از فراخوانی ابزار.'},
  {id:'@cf/meta/llama-4-maverick-17b-128e-instruct',name:'Llama 4 Maverick 17B',vendor:'Meta — Workers AI',free:true,note:'نسل جدید Llama؛ قوی‌ترین مدل رایگانِ لیست برای tool use.'},
  {id:'*configured',name:'هر مدل فراخوانی‌ابزارِ ارائه‌دهنده‌های تنظیم‌شده',vendor:'OpenAI-compatible (GPT، DeepSeek، Qwen و…)',free:false,note:'از فهرست مدل‌های ارائه‌دهنده‌های ذخیره‌شده انتخاب کنید؛ فقط مدل‌هایی که tool calling پشتیبانی می‌کنند.'}
];

// ─── Tool definitions shown to the user and fed to the model as JSON schema ──
export type AgentToolArg={label:string;type:'string'|'number'|'boolean';required?:boolean;hint?:string};
export type AgentToolDef={id:string;name:string;description:string;args?:Record<string,AgentToolArg>};
export const AGENT_TOOLS:AgentToolDef[]=[
  {id:'site_status',name:'وضعیت کلی سایت',description:'نمای کلی: تعداد پروفایل‌ها، کارهای در صف/در حال اجرا، اتصال‌های ووکامرس و باسلام و وضعیت صف سرور.',args:{}},
  {id:'jobs_report',name:'گزارش کارهای اخیر',description:'آخرین کارهای استخراج/همگام‌سازی با وضعیت، پیشرفت و خطا.',args:{limit:{label:'تعداد',type:'number',hint:'حداکثر ۲۰'}}},
  {id:'products_search',name:'جست‌وجوی محصولات بانک',description:'جست‌وجوی محصولات ذخیره‌شدهٔ این سایت در D1 بر اساس بخشی از عنوان.',args:{q:{label:'عبارت جست‌وجو',type:'string',required:true},limit:{label:'تعداد',type:'number'}}},
  {id:'destination_catalog',name:'فهرست محصولات مقصد',description:'فهرست صفحه‌به‌صفحه محصولات ووکامرس یا باسلام (قیمت، وضعیت، کد، غرفه).',args:{target:{label:'مقصد',type:'string'},page:{label:'صفحه',type:'number'},perPage:{label:'در هر صفحه',type:'number'},q:{label:'عبارت جست‌وجو',type:'string'}}},
  {id:'destination_overview',name:'آمار محصولات مقصد',description:'تعداد کل و شمارش وضعیت‌های محصولات ووکامرس یا باسلام (تا ۵ صفحه).',args:{target:{label:'مقصد',type:'string'}}},
  {id:'duplicates_report',name:'بررسی تکراری‌های مقصد',description:'شناسایی محصولات همنام (با حذف پسوند کد) روی ووکامرس یا باسلام و معرفی نسخه‌های تکراری بر اساس معیار نگهداشت.',args:{target:{label:'مقصد',type:'string'},keep:{label:'معیار نگهداشت',type:'string'},suffix:{label:'فرمت پسوند کد',type:'string'}}},
  {id:'categories_learned',name:'دسته‌بندی‌های یادگرفته‌شده',description:'پرکاربردترین دسته‌بندی‌هایی که از عنوان محصولات یاد گرفته شده.',args:{limit:{label:'تعداد',type:'number'}}},
  {id:'autoreply_report',name:'گزارش پاسخ‌های خودکار',description:'آخرین پاسخ‌های خودکار ثبت‌شده برای مشتریان.',args:{limit:{label:'تعداد',type:'number'}}},
  {id:'profile_stats',name:'آمار محصولات پروفایل‌ها',description:'تعداد محصولات، نقشهٔ ووکامرس/باسلام و آخرین به‌روزرسانی هر پروفایل.',args:{}}
];

const toolSchema=(tool:AgentToolDef):{type:string;properties:Record<string,unknown>;required:string[]}=>{
  const properties:Record<string,unknown>={},required:string[]=[];
  for(const[key,arg]of Object.entries(tool.args||{})){properties[key]={type:arg.type==='number'?'integer':'string',description:`${arg.label}${arg.hint?` (${arg.hint})`:''}`};if(arg.required)required.push(key)}
  return{type:'object',properties,required};
};
export function agentToolSchemas(tools:string[]):AiTool[]{return AGENT_TOOLS.filter(tool=>tools.includes(tool.id)).map(tool=>({type:'function',function:{name:tool.id,description:tool.description,parameters:toolSchema(tool)}}))}

// ─── Tool runners (read-only, server-side; destructive actions stay in the UI) ──
async function capCatalog(target:string,page=1,perPage=100,q=''){
  const data:any=await destinationCatalog(target as 'woo'|'basalam',{page:Math.max(1,page),perPage:Math.min(100,Math.max(1,perPage||100)),q:String(q||''),status:'all',shopId:'all'});
  const products=(data.products||[]).map((p:any)=>({id:Number(p.id),shopId:String(p.shopId||'default'),name:String(p.name||p.title||'').slice(0,120),price:Number(p.price)||0,status:String(p.status||''),sku:String(p.sku||'').slice(0,80)}));
  return{target,total:Number(data.total||0),totalPages:Number(data.totalPages||1),page:Number(data.page||1),products:products.slice(0,100)};
}
const toolRunners:Record<string,(args:any)=>Promise<unknown>>={
  site_status:async()=>{
    const[profiles,jobs,connections]=await Promise.all([listProfiles(),listJobs(15),loadConnections()]);
    return{profiles:profiles.length,jobs:jobs.length,activeJobs:jobs.filter(job=>['queued','running'].includes(job.status)).length,wooConfigured:Boolean(connections.woo?.url&&connections.woo?.key),basalamConfigured:Boolean(connections.basalam?.token),aiProviders:(connections.ai?.providers||[]).filter((p:any)=>p.enabled!==false).length,queue:Boolean(getEnv().JOBS)};
  },
  jobs_report:async(args)=>{
    const jobs=await listJobs(Math.max(1,Math.min(20,Number(args?.limit)||10)));
    return jobs.map(job=>({id:job.id.slice(0,8),kind:job.kind,target:job.target,status:job.status,phase:job.phase,progress:`${job.processed}/${job.total}`,added:job.added,updated:job.updated,failed:job.failed,error:job.error?.slice(0,200)||null,at:job.updatedAt}));
  },
  products_search:async(args)=>{
    const q=String(args?.q||'').trim().slice(0,80);
    if(!q)return{error:'عبارت جست‌وجو خالی است',hint:'مثلاً: عطر گل محمدی'};
    const profiles=await listProfiles(),out:any[]=[];
    for(const profile of profiles.slice(0,5)){
      const{products}=await listProducts(profile.id,Math.max(1,Math.min(25,Number(args?.limit)||10)),0,q);
      for(const product of products)out.push({profile:profile.name,title:product.title.slice(0,120),price:product.price,sku:product.sku||'',url:product.url,sourceKey:product.sourceKey});
    }
    return{query:q,total:out.length,products:out.slice(0,25)};
  },
  destination_catalog:async(args)=>capCatalog(String(args?.target||'woo'),Number(args?.page)||1,Number(args?.perPage)||100,String(args?.q||'')),
  destination_overview:async(args)=>{
    const target=String(args?.target||'woo'),seen=new Map<string,string>(),statuses:Record<string,number>={};
    for(let page=1;page<=5;page++){const data:any=await destinationCatalog(target as 'woo'|'basalam',{page,perPage:100,status:'all',shopId:'all'});for(const p of data.products||[]){const key=`${p.shopId||'default'}:${p.id}`;if(seen.has(key))continue;seen.set(key,'1');statuses[String(p.status||'unknown')]=(statuses[String(p.status||'unknown')]||0)+1}if(page>=(Number(data.totalPages)||1))break}
    return{target,total:seen.size,statuses};
  },
  duplicates_report:async(args)=>{
    const target=String(args?.target||'woo')==='basalam'?'basalam':'woo',keep=normalizeDedupKeep(args?.keep),suffix=parseSuffixFormats(String(args?.suffix||'')),candidates:DedupCandidate[]=[],seenIds=new Set<string>();
    for(let page=1;page<=5;page++){
      const data:any=await destinationCatalog(target,{page,perPage:100,status:'all',shopId:'all'});
      for(const p of data.products||[]){const status=String(p.status||'');if(target==='woo'&&status==='trash')continue;if(target==='basalam'&&status==='4184')continue;const id=Number(p.id),shopId=String(p.shopId||'default'),key=`${shopId}:${id}`;if(id<=0||seenIds.has(key))continue;seenIds.add(key);candidates.push({id,shopId,name:String(p.name||p.title||'').slice(0,200),price:Number(p.price)||0,date:String(p.raw?.date_created||p.raw?.created_at||p.raw?.createdAt||''),status,sku:String(p.sku||'').slice(0,100)})}
      if(page>=(Number(data.totalPages)||1))break;
    }
    const groups=buildDedupGroups(candidates,keep,suffix),top=groups.slice(0,10).map(group=>({title:group.title,count:group.remove.length+1,keep:{id:group.keep.id,shopId:group.keep.shopId,price:group.keep.price,date:group.keep.date},remove:group.remove.slice(0,5).map(item=>({id:item.id,shopId:item.shopId,price:item.price,date:item.date}))}));
    return{target,keep,scanned:candidates.length,groupsFound:groups.length,duplicates:groups.reduce((sum,g)=>sum+g.remove.length,0),sampleGroups:top,note:'این ابزار فقط خواندنی است؛ برای حذف واقعی از بخش «حذف تکراری‌ها» استفاده کنید.'};
  },
  categories_learned:async(args)=>listCategoryLearning(Math.max(1,Math.min(50,Number(args?.limit)||20))),
  autoreply_report:async(args)=>listAutoreplyLog(Math.max(1,Math.min(50,Number(args?.limit)||20))),
  profile_stats:async()=>profileStats()
};
export const AGENT_TOOL_IDS=AGENT_TOOLS.map(tool=>tool.id);

// ─── Run state (app_state keys, mirroring the background-run pattern) ────────
const pointerKey='background_current:agent';
const runKey=(id:string)=>`background_run:agent:${id}`;
const leaseKey=(id:string)=>`background_lease:agent:${id}`;
const now=()=>new Date().toISOString();
const STALL_MS=60_000,LEASE_MS=40_000;
const json=<T>(raw:string,fallback:T):T=>{try{return JSON.parse(raw) as T}catch{return fallback}};
const runAge=(run:AgentRun)=>Date.now()-(Date.parse(run.updatedAt||run.createdAt)||Date.now());
const active=(run:AgentRun|null)=>Boolean(run&&['queued','running','paused'].includes(run.status));

export async function currentAgentRun():Promise<AgentRun|null>{const id=await getState<string>(pointerKey,'');return id?readRun(id):null}
async function readRun(id:string):Promise<AgentRun|null>{const row=await getAgentRun(id);if(!row)return null;return agentRunFromRow(row)}
function agentRunFromRow(row:AgentRunRow):AgentRun{return{id:row.id,promptId:row.promptId,name:row.name,prompt:row.prompt,providerId:row.provider,model:row.model,tools:json<string[]>(row.tools,[]),maxSteps:row.maxSteps,status:row.status as AgentRun['status'],phase:row.phase,messages:json<AgentRun['messages']>(row.messages,[]),logs:json<AgentLog[]>(row.logs,[]),steps:row.steps,result:row.result,stopRequested:false,attempts:0,error:row.error,createdAt:row.createdAt,updatedAt:row.updatedAt,startedAt:row.startedAt,finishedAt:row.finishedAt}}
function toRow(run:AgentRun):AgentRunRow{return{id:run.id,promptId:run.promptId,name:run.name,provider:run.providerId,model:run.model,status:run.status,phase:run.phase,prompt:run.prompt,tools:JSON.stringify(run.tools),messages:JSON.stringify(run.messages),logs:JSON.stringify(run.logs),steps:run.steps,maxSteps:run.maxSteps,result:run.result,error:run.error,startedAt:run.startedAt,finishedAt:run.finishedAt,createdAt:run.createdAt,updatedAt:now()}}
async function writeRun(run:AgentRun){await updateAgentRun(toRow(run))}

async function claimRun(id:string):Promise<string|null>{
  const token=crypto.randomUUID(),key=leaseKey(id),stamp=now(),stale=new Date(Date.now()-LEASE_MS).toISOString(),value=JSON.stringify({token});
  await getEnv().DB.prepare(`INSERT INTO app_state(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at WHERE app_state.updated_at<?`).bind(key,value,stamp,stale).run();
  const row=await getEnv().DB.prepare('SELECT value FROM app_state WHERE key=?').bind(key).first<{value:string}>();
  try{return json<{token:string}>(row?.value||'{}',{token:''}).token===token?token:null}catch{return null}
}
async function releaseRun(id:string,token:string){await getEnv().DB.prepare('DELETE FROM app_state WHERE key=? AND value=?').bind(leaseKey(id),JSON.stringify({token})).run()}
async function enqueue(message:{task:'agent';runId:string},waitUntil?:(promise:Promise<unknown>)=>void):Promise<void>{
  const queue=getEnv().JOBS;
  if(queue){await queue.send(message);return}
  const promise=drainInline(message);
  if(waitUntil)waitUntil(promise);else await promise;
}
async function drainInline(message:{task:'agent';runId:string}){for(let i=0;i<5;i++){const result=await processAgentRunMessage(message);if(result.outcome!=='continue')break}}
export function publicAgentRun(run:AgentRun|null):any{
  if(!run)return null;
  const logs=run.logs.slice(-200),messages=run.messages.slice(-30);
  return{id:run.id,promptId:run.promptId,name:run.name,prompt:run.prompt,provider:run.providerId,model:run.model,tools:run.tools,maxSteps:run.maxSteps,status:run.status,phase:run.phase,steps:run.steps,result:run.result||null,error:run.error,createdAt:run.createdAt,updatedAt:run.updatedAt,startedAt:run.startedAt,finishedAt:run.finishedAt,logs,messages};
}

// ─── Model resolution ─────────────────────────────────────────────────────────
async function resolveAgentModel(modelKey:string,providerId:string,model:string):Promise<{provider:any;model:string;resolvedKey:string}|null>{
  const providers=(await aiProviders()).filter(provider=>provider.enabled!==false);
  const wanted=String(modelKey||'').trim();
  if(wanted&&wanted!=='*configured'){
    const[pid,...parts]=wanted.split('::'),wantedModel=parts.length?parts.join('::'):wanted;
    const provider=parts.length?providers.find(item=>item.id===pid):providers.find(item=>item.models.includes(wantedModel));
    if(provider&&provider.models.includes(wantedModel))return{provider,model:wantedModel,resolvedKey:wanted};
  }
  if(providerId&&model){
    const provider=providers.find(item=>item.id===providerId);
    if(provider&&provider.models.includes(model))return{provider,model,resolvedKey:`${provider.id}::${model}`};
    if(provider&&AGENT_TOOL_MODELS.some(candidate=>candidate.id===model))return{provider,model,resolvedKey:`${provider.id}::${model}`};
  }
  for(const provider of providers){
    for(const modelId of provider.models){
      if(AGENT_TOOL_MODELS.some(candidate=>candidate.id===modelId))return{provider,model:modelId,resolvedKey:`${provider.id}::${modelId}`};
    }
  }
  return null;
}

// ─── Step execution ───────────────────────────────────────────────────────────
const SYSTEM_PROMPT=`شما یک عامل (Agent) خودکار مدیریت فروشگاه هستید که به ابزارهای سرور این سایت دسترسی دارد. قوانین:
۱. برای هر پرسش ابتدا مشخص کنید به کدام داده نیاز دارید و همان ابزارها را به‌ترتیب فراخوانی کنید.
۲. هرگز پاسخ را حدس نزنید؛ اطلاعات را فقط از خروجی ابزارها بگیرید.
۳. اگر ابزاری خطا داد، خطا را در خلاصه ذکر کنید و در صورت امکان با ابزار دیگری ادامه دهید.
۴. وقتی دادهٔ کافی جمع شد، خلاصهٔ نهایی را به فارسی روان و خوانا بنویسید؛ اعداد را فارسی بنویسید و مهم‌ترین‌ها را با «•» لیست کنید.
۵. اگر درخواست شامل عملیات حذف/تغییر بود، فقط گزارش بدهید و بگویید در رابط کاربری قابل انجام است؛ ابزارهای شما فقط خواندنی هستند.`;

async function runToolStep(run:AgentRun,index:number):Promise<boolean>{
  const call=run.messages[run.messages.length-1]?.tool_calls?.[index];
  if(!call)return false;
  const args=json<Record<string,unknown>>(call.function?.arguments||'{}',{});
  run.logs.push({at:now(),step:run.steps+1,type:'call',tool:call.function?.name||'',text:`فراخوانی ابزار ${call.function?.name||''} با ورودی: ${JSON.stringify(args).slice(0,300)}`});
  let payload:unknown;
  try{payload=await (toolRunners[call.function?.name||'']?toolRunners[call.function?.name||''](args):{ok:false,error:`ابزار «${call.function?.name||''}» شناخته نشد.`})}catch(error){payload={ok:false,error:error instanceof Error?error.message:String(error)}}
  const text=JSON.stringify(payload).slice(0,6000);
  run.logs.push({at:now(),step:run.steps+1,type:'tool',tool:call.function?.name||'',ok:Boolean(payload&&(payload as any).ok!==false),text:`نتیجه: ${text.slice(0,600)}`});
  run.messages.push({role:'tool',tool_call_id:call.id,content:text});
  return true;
}

/**
 * One Queue delivery performs a bounded slice of work: one model turn plus up to two
 * tool calls, then checkpoints. Long prompts continue across deliveries.
 */
export async function processAgentRunMessage(message:{task:'agent';runId:string}):Promise<AgentOutcome>{
  const token=await claimRun(message.runId);if(!token)return{outcome:'ignored'};
  try{
    const run=await readRun(message.runId);
    if(!run||['done','failed','paused'].includes(run.status))return{outcome:'ignored'};
    try{return await executeAgentStep(run)}
    catch(error){
      run.attempts++;run.error=error instanceof Error?error.message:String(error);
      if(run.attempts>=4){run.status='failed';run.phase='failed';run.finishedAt=now();run.logs.push({at:now(),step:run.steps,type:'error',text:`اجرا ناموفق شد: ${run.error}`})}
      else{run.status='queued';run.phase='retrying'}
      await writeRun(run);return{outcome:run.status==='failed'?'complete':'continue',delaySeconds:Math.min(60,Math.pow(2,run.attempts))};
    }
  }finally{await releaseRun(message.runId,token)}
}
async function executeAgentStep(run:AgentRun):Promise<AgentOutcome>{
  run.status='running';run.startedAt ||= now();
  if(run.messages.length===0){
    const tools=agentToolSchemas(run.tools);
    run.messages.push({role:'system',content:`${SYSTEM_PROMPT}\n\nابزارهای در دسترس (JSON Schema):\n${JSON.stringify(tools).slice(0,6000)}`});
    run.messages.push({role:'user',content:run.prompt||'وضعیت سایت را بررسی کن.'});
    run.logs.push({at:now(),step:0,type:'info',text:`شروع اجرا با مدل ${run.model||'خودکار'}؛ ابزارهای فعال: ${run.tools.join('، ')||'—'}`});
  }
  const resolved=await resolveAgentModel('',run.providerId,run.model);
  if(!resolved){run.status='failed';run.phase='failed';run.finishedAt=now();run.error='مدل دارای فراخوانی ابزار پیدا نشد؛ یک مدل از لیست مدل‌های ایجنتیک انتخاب و ذخیره کنید.';run.logs.push({at:now(),step:run.steps,type:'error',text:run.error});await writeRun(run);return{outcome:'complete'}}
  const chatMessages=run.messages.filter(m=>m.content!==null||(m.tool_calls?.length??0)>0).map(m=>({role:m.role,content:m.content??'',...(m.tool_calls?.length?{tool_calls:m.tool_calls}:{})}));
  const turn=await aiAgentCall(resolved.provider,resolved.model,chatMessages,agentToolSchemas(run.tools),undefined,undefined,run.maxSteps<=3?1200:2000);
  const toolCalls=turn.toolCalls||[];
  if(toolCalls.length){
    run.messages.push({role:'assistant',content:turn.text||null,tool_calls:toolCalls.map(call=>({id:call.id,type:'function',function:{name:call.name,arguments:JSON.stringify(call.arguments||{})}}))});
    let executed=0;
    for(let index=0;index<toolCalls.length&&executed<2;index++){if(await runToolStep(run,index))executed++}
    run.steps++;
    const latest=await readRun(run.id);
    if(latest?.stopRequested){run.stopRequested=true;run.status='paused';run.phase='paused';run.logs.push({at:now(),step:run.steps,type:'info',text:'توقف امن در checkpoint ثبت شد.'})}
    else if(run.steps>=run.maxSteps){run.status='done';run.phase='finished';run.finishedAt=now();run.result=turn.text||`به سقف ${run.maxSteps} گام رسید؛ پاسخ: ${(turn.text||'').slice(0,400)||'—'}`;run.logs.push({at:now(),step:run.steps,type:'done',text:'به سقف گام‌ها رسید؛ اجرا پایان یافت.'})}
    else{run.status='queued';run.phase='thinking';run.logs.push({at:now(),step:run.steps,type:'info',text:'گام بعدی در صف سرور قرار گرفت.'})}
  }else{
    run.result=(turn.text||'').trim();
    run.steps++;
    if(!run.result){run.status='failed';run.phase='failed';run.finishedAt=now();run.error='مدل پاسخی برنگرداند؛ ممکن است فراخوانی ابزار پشتیبانی نشود.';run.logs.push({at:now(),step:run.steps,type:'error',text:run.error})}
    else{run.status='done';run.phase='finished';run.finishedAt=now();run.logs.push({at:now(),step:run.steps,type:'done',text:'پاسخ نهایی مدل دریافت شد.'})}
  }
  await writeRun(run);
  return{outcome:run.status==='queued'?'continue':'complete',delaySeconds:1};
}

// ─── Start / control / recovery ──────────────────────────────────────────────
export async function startAgentRun(input:any,waitUntil?:(promise:Promise<unknown>)=>void):Promise<{run:any;existing:boolean}>{
  const previous=await currentAgentRun();
  if(previous&&active(previous)){
    if(previous.status==='paused'||previous.stopRequested)return{run:publicAgentRun(previous),existing:true};
    if(runAge(previous)<STALL_MS)return{run:publicAgentRun(previous),existing:true};
    await resetAgentRun();
  }
  let promptRow:AgentPromptRow|null=null;
  if(input?.promptId)promptRow=await getAgentPrompt(String(input.promptId));
  const prompt=String(input?.prompt||promptRow?.prompt||'').trim();
  if(!prompt)throw new Error('متن پرامپت خالی است.');
  const toolsInput:string[]=Array.isArray(input?.tools)?(input.tools as unknown[]).map(String):promptRow?json<string[]>(promptRow.tools,[]):[];
  const tools:string[]=[...new Set(toolsInput.filter((id:string)=>AGENT_TOOL_IDS.includes(id)))];
  const toolsFinal:string[]=tools.length?tools:[...AGENT_TOOL_IDS];
  const maxSteps=Math.max(1,Math.min(12,Number(input?.maxSteps)||promptRow?.maxSteps||6));
  const modelKey=String(input?.modelKey||promptRow?.modelKey||'');
  const resolved=await resolveAgentModel(modelKey,String(input?.providerId||''),String(input?.model||''));
  const timestamp=now(),id=crypto.randomUUID(),run:AgentRun={id,promptId:String(input?.promptId||promptRow?.id||''),name:String(input?.name||promptRow?.name||'اجرای دستی'),prompt,providerId:resolved?.provider.id||String(input?.providerId||''),model:resolved?.model||String(input?.model||''),tools:toolsFinal,maxSteps,status:'queued',phase:'starting',messages:[],logs:[],steps:0,result:null,stopRequested:false,attempts:0,error:null,createdAt:timestamp,updatedAt:timestamp,startedAt:null,finishedAt:null};
  await saveAgentRun(toRow(run));await setState(pointerKey,id);
  try{await enqueue({task:'agent',runId:id},waitUntil)}catch(error){run.status='failed';run.error=error instanceof Error?error.message:String(error);await writeRun(run)}
  return{run:publicAgentRun(run),existing:false};
}
export async function controlAgentRun(action:'stop'|'resume',waitUntil?:(promise:Promise<unknown>)=>void):Promise<any>{
  const run=await currentAgentRun();if(!run)throw new Error('اجرای ایجنتیک فعالی پیدا نشد.');
  if(action==='stop'){if(['done','failed'].includes(run.status))return publicAgentRun(run);run.stopRequested=true;run.status='paused';run.phase='paused';await writeRun(run);return publicAgentRun(run)}
  if(!['paused','failed'].includes(run.status))return publicAgentRun(run);
  run.stopRequested=false;run.status='queued';run.phase='resumed';await writeRun(run);await enqueue({task:'agent',runId:run.id},waitUntil);return publicAgentRun(run);
}
export async function resetAgentRun():Promise<void>{
  const id=await getState<string>(pointerKey,'');
  if(id){await deleteAgentRun(id);await deleteState(leaseKey(id))}
  await setState(pointerKey,'');
}
export async function recoverAgentRun(waitUntil?:(promise:Promise<unknown>)=>void):Promise<void>{
  const run=await currentAgentRun();
  if(!run||run.stopRequested||run.status==='paused'||['done','failed'].includes(run.status))return;
  if(runAge(run)<=STALL_MS)return;
  run.status='queued';run.phase='recovered';run.error=null;await writeRun(run);await enqueue({task:'agent',runId:run.id},waitUntil);
}

// ─── Scheduled prompts ────────────────────────────────────────────────────────
export async function agentCronTick(waitUntil:(promise:Promise<unknown>)=>void):Promise<void>{
  const current=await currentAgentRun();
  if(current&&active(current))return;
  const prompts=await listAgentPrompts();
  for(const prompt of prompts){
    if(!prompt.enabled||prompt.scheduleMinutes<=0)continue;
    const last=prompt.lastRunAt?Date.parse(prompt.lastRunAt):null;
    const due=!last||Date.now()-last>=prompt.scheduleMinutes*60_000;
    if(!due)continue;
    const started=await startAgentRun({promptId:prompt.id},waitUntil);
    if(started.existing){continue}
    await touchAgentPromptLastRun(prompt.id,now());
  }
}

// ─── Prompt CRUD helpers for the API ─────────────────────────────────────────
export async function createOrUpdateAgentPrompt(input:any):Promise<AgentPromptRow>{
  const id=String(input?.id||crypto.randomUUID()),name=String(input?.name||'بدون نام').trim().slice(0,120),prompt=String(input?.prompt||'').trim();
  if(!prompt)throw new Error('متن پرامپت خالی است.');
  const previous=input?.id?await getAgentPrompt(id):null;
  const tools=[...new Set((Array.isArray(input?.tools)?input.tools.map(String):[]).filter((tool:string)=>AGENT_TOOL_IDS.includes(tool)))];
  const scheduleMinutes=Math.max(0,Math.min(525600,Math.trunc(Number(input?.scheduleMinutes)||0)));
  const timestamp=now(),row:AgentPromptRow={id,name,description:String(input?.description||'').slice(0,500),prompt,tools:JSON.stringify(tools.length?tools:AGENT_TOOL_IDS),scheduleMinutes,modelKey:String(input?.modelKey||'').slice(0,200),enabled:input?.enabled===undefined?true:Boolean(input.enabled),maxSteps:Math.max(1,Math.min(12,Number(input?.maxSteps)||6)),lastRunAt:previous?.lastRunAt||null,createdAt:previous?.createdAt||timestamp,updatedAt:timestamp};
  await saveAgentPrompt(row);return row;
}
export async function removeAgentPrompt(id:string):Promise<void>{await deleteAgentRunsForPrompt(id);await deleteAgentPrompt(id)}
export async function listAgentRunsPublic(limit=40):Promise<any[]>{return(await listAgentRuns(limit)).map(row=>({id:row.id,promptId:row.promptId,name:row.name,model:row.model||row.provider,status:row.status,phase:row.phase,steps:row.steps,maxSteps:row.maxSteps,result:row.result?String(row.result).slice(0,300):null,error:row.error,createdAt:row.createdAt,updatedAt:row.updatedAt,finishedAt:row.finishedAt}))}
export async function getAgentRunPublic(id:string):Promise<any>{const row=await getAgentRun(id);if(!row)return null;const run=agentRunFromRow(row);return publicAgentRun(run)}
