import { loadConnections } from './connections.js';
import { getState, setState } from './db.js';
import { assertPublicUrl, safeFetch } from './network.js';

type Provider={id:string;name:string;baseUrl:string;apiKey:string;models:string[];enabled:boolean};
type Network={mode:string;proxyUrl:string;workerUrl:string;dohUrl:string;resolveIp:string};
type AiAttempt={endpoint:string;body:string|string[];model:string;httpStatus?:number;phase:'network'|'http'|'success';error?:string};
type RequestResult={response?:Response;body?:any;rawText?:string;networkError?:string};

function providersFromAi(ai:any):Provider[]{return ai.providers.length?ai.providers:[{id:'default',name:'Default',baseUrl:ai.baseUrl,apiKey:ai.apiKey,models:ai.model?[ai.model]:[],enabled:true}]}
export async function aiProviders():Promise<Provider[]>{return providersFromAi((await loadConnections()).ai)}

export async function aiCall(provider:Provider,model:string,prompt:string,networkOverride?:Network){
  const network=networkOverride||(await loadConnections()).ai.network;
  if(!provider.baseUrl||!provider.apiKey||!model)throw new Error('تنظیمات ارائه‌دهنده/مدل کامل نیست');
  const started=Date.now();
  if(isCloudflareNative(provider.baseUrl))return cloudflareCall(provider,model,prompt,network,started);
  const endpoint=openAiEndpoint(provider.baseUrl),reportedEndpoint=safeEndpoint(endpoint),payload={model,messages:[{role:'user',content:prompt}],max_tokens:400,temperature:.2};
  const result=await requestAi(endpoint,payload,provider,network);
  if(result.networkError){const reason=safeError(result.networkError,endpoint,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt,endpoint:reportedEndpoint,latencyMs:Date.now()-started,raw:{error:reason}})}
  const response=result.response!,body=result.body,latencyMs=Date.now()-started;
  if(!response.ok)throw new AiResponseError(`HTTP ${response.status}: ${aiErrorMessage(body)||response.statusText||'AI error'}`,failureDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body));
  return successDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body);
}

async function cloudflareCall(provider:Provider,model:string,prompt:string,network:Network,started:number){
  const accountId=cloudflareAccountId(provider.baseUrl);
  if(!accountId)throw new AiResponseError('شمارهٔ حساب Cloudflare در آدرس پیدا نشد',{ok:false,phase:'configuration',provider:provider.id,providerName:provider.name,model,prompt,endpoint:safeEndpoint(provider.baseUrl),latencyMs:Date.now()-started,raw:{error:'Cloudflare account ID was not found in base URL'}});
  const models=cloudflareModelIds(model),base=`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(accountId)}/ai/run/`,messages=[{role:'user',content:prompt}],attempts:AiAttempt[]=[];
  let last:RequestResult|undefined,lastEndpoint=base+models[0];
  for(const modelId of models){
    const endpoint=base+modelId.replace(/^\/+/,''),bodies:Array<{label:string;value:any}>=[{label:'prompt',value:{prompt,max_tokens:400}},{label:'messages',value:{messages,max_tokens:400}}];
    for(const candidate of bodies){
      const result=await requestAi(endpoint,candidate.value,provider,network);last=result;lastEndpoint=endpoint;
      const error=result.networkError?safeError(result.networkError,endpoint,provider.apiKey):result.response?.ok?'':aiErrorMessage(result.body)||result.response?.statusText||'Cloudflare AI error';
      attempts.push({endpoint:safeEndpoint(endpoint),body:Object.keys(candidate.value),model:modelId,httpStatus:result.response?.status,phase:result.networkError?'network':result.response?.ok?'success':'http',...(error?{error}:{})});
      if(result.response?.ok)return successDetail(provider,model,prompt,safeEndpoint(endpoint),Date.now()-started,result.response,result.body,{cloudflare:{mode:'native',resolvedModel:modelId,triedModels:models,attempts}});
    }
  }
  const chatEndpoint=`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(accountId)}/ai/v1/chat/completions`;
  for(const modelId of models){
    const payload={model:modelId,messages,max_tokens:400},result=await requestAi(chatEndpoint,payload,provider,network);last=result;lastEndpoint=chatEndpoint;
    const error=result.networkError?safeError(result.networkError,chatEndpoint,provider.apiKey):result.response?.ok?'':aiErrorMessage(result.body)||result.response?.statusText||'Cloudflare AI error';
    attempts.push({endpoint:safeEndpoint(chatEndpoint),body:`chat-${modelId}`,model:modelId,httpStatus:result.response?.status,phase:result.networkError?'network':result.response?.ok?'success':'http',...(error?{error}:{})});
    if(result.response?.ok)return successDetail(provider,model,prompt,safeEndpoint(chatEndpoint),Date.now()-started,result.response,result.body,{cloudflare:{mode:'openai-fallback',resolvedModel:modelId,triedModels:models,attempts}});
  }
  const latencyMs=Date.now()-started,cloudflare={mode:'failed',triedModels:models,attempts};
  if(last?.networkError){const reason=safeError(last.networkError,lastEndpoint,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt,endpoint:safeEndpoint(lastEndpoint),latencyMs,cloudflare,raw:{error:reason}})}
  const response=last?.response,status=response?.status||0,body=last?.body,message=aiErrorMessage(body)||(models.length>1?`مدل «${models[0]}» یافت نشد؛ مسیر org دار «${models[1]}» نیز امتحان شد.`:'پاسخی از Cloudflare AI دریافت نشد.');
  throw new AiResponseError(`HTTP ${status}: ${message}`,{...failureDetail(provider,model,prompt,safeEndpoint(lastEndpoint),latencyMs,response,body),cloudflare});
}

function isCloudflareNative(raw:string):boolean{return /\/accounts\/[^/]+\/ai\/run(?:\/|$)/i.test(unmarkdownUrl(raw))}
function cloudflareAccountId(raw:string):string{return unmarkdownUrl(raw).match(/\/accounts\/([^/]+)\/ai\/run(?:\/|$)/i)?.[1]||''}
function unmarkdownUrl(raw:string):string{const value=String(raw||'').trim(),match=value.match(/^\[[^\]]+\]\(([^)]+)\)$/);return match?.[1]||value}
function openAiEndpoint(raw:string):string{
  const value=unmarkdownUrl(raw),url=new URL(value);if(/\/chat\/completions\/?$/i.test(url.pathname))return url.toString();
  if(url.port==='11434'&&!/\/v1\/?$/i.test(url.pathname))url.pathname=url.pathname.replace(/\/$/,'')+'/v1';
  url.pathname=url.pathname.replace(/\/$/,'')+'/chat/completions';return url.toString();
}
function cloudflareModelIds(raw:string):string[]{
  const model=String(raw||'').trim().replace(/^\/+/,''),out=[model],after=model.replace(/^@cf\//i,'');
  if(after&&after.indexOf('/')<0){
    const rules:Array<[string,string]>=[['gemma-sea-lion','aisingapore'],['mistral-small','mistralai'],['llama-guard','meta'],['gpt-oss','openai'],['deepseek','deepseek-ai'],['nemotron','nvidia'],['moondream','moondream'],['embeddinggemma','google'],['hermes','nousresearch'],['uform','unum-cloud'],['plamo','pfnet'],['granite','ibm-granite'],['kimi','moonshotai'],['gemma','google'],['mistral','mistral'],['glm','zai-org'],['phi','microsoft'],['bge','baai'],['llama','meta'],['qwen','qwen'],['qwq','qwen'],['sqlcoder','defog'],['florence','microsoft'],['llava','llava-hf']];
    const rule=rules.find(([prefix])=>after.toLowerCase().startsWith(prefix));if(rule)out.push(`@cf/${rule[1]}/${after}`);if(after.toLowerCase().startsWith('llama'))out.push(`@cf/meta-llama/${after}`);
  }
  return [...new Set(out.filter(Boolean))];
}
async function requestAi(endpoint:string,payload:any,provider:Provider,network:Network):Promise<RequestResult>{
  try{const response=await networkFetch(endpoint,{method:'POST',headers:{authorization:`Bearer ${provider.apiKey}`,'content-type':'application/json'},body:JSON.stringify(payload)},network),rawText=await response.text(),body=parseResponse(rawText,provider.apiKey);return{response,body,rawText}}
  catch(error){return{networkError:error instanceof Error?error.message:String(error)}}
}
function successDetail(provider:Provider,model:string,prompt:string,endpoint:string,latencyMs:number,response:Response,body:any,extra:Record<string,unknown>={}){return{ok:true,text:extractAiText(body),latencyMs,provider:provider.id,providerName:provider.name,model,prompt,endpoint,httpStatus:response.status,httpStatusText:response.statusText,contentType:response.headers.get('content-type')||'',usage:body?.usage||body?.result?.usage||null,finishReason:body?.choices?.[0]?.finish_reason||'',raw:body,...extra}}
function failureDetail(provider:Provider,model:string,prompt:string,endpoint:string,latencyMs:number,response?:Response,body?:any){return{ok:false,phase:'http',provider:provider.id,providerName:provider.name,model,prompt,endpoint,latencyMs,httpStatus:response?.status||0,httpStatusText:response?.statusText||'',contentType:response?.headers.get('content-type')||'',raw:body??null}}
function extractAiText(body:any):string{
  const content=body?.choices?.[0]?.message?.content;
  if(typeof content==='string'&&content.trim())return content;
  if(Array.isArray(content)){const joined=content.map((item:any)=>typeof item==='string'?item:item?.text||item?.content||'').join('');if(joined.trim())return joined}
  for(const value of [body?.choices?.[0]?.text,body?.result?.response,body?.response,body?.output_text,body?.result?.text])if(typeof value==='string'&&value.trim())return value;
  return '';
}
function aiErrorMessage(body:any):string{
  if(body&&Array.isArray(body.errors))for(const error of body.errors){const message=String(error?.message||'').trim();if(message)return Number(error?.internalCode??error?.code)===5007?`مدل در Cloudflare وجود ندارد (No such model): ${message}`:message}
  return String(body?.error?.message||body?.message||body?.error||'').trim();
}

const AI_TEST_MODELS_PER_INVOCATION=1;
type AiTestTask={p:Provider;model:string;key:string};
type StoredAiTest={runId:string;startedAt:string;updatedAt:string;prompt:string;onlyCandidates:boolean;total:number;results:any[]};
function aiTestTasks(ai:any,providers:Provider[],onlyCandidates:boolean):AiTestTask[]{
  const wanted=new Set<string>(Array.isArray(ai.candidates)?ai.candidates.map(String):[]),tasks:AiTestTask[]=[];
  for(const p of providers){
    if(p.enabled===false)continue;
    for(const rawModel of p.models||[]){const model=String(rawModel||'').trim(),key=`${p.id}::${model}`;if(!model||onlyCandidates&&!wanted.has(key))continue;tasks.push({p,model,key})}
  }
  return tasks;
}
function aiTestFailure(error:unknown,task:AiTestTask,prompt:string){return error instanceof AiResponseError?{...error.detail,key:task.key}:{ok:false,phase:'unknown',key:task.key,provider:task.p.id,providerName:task.p.name,model:task.model,prompt,latencyMs:0,error:safeError(error instanceof Error?error.message:String(error),'',task.p.apiKey),raw:{error:safeError(error instanceof Error?error.message:String(error),'',task.p.apiKey)}}}

/**
 * Runs at most one model per Worker invocation. A Cloudflare-native model can
 * legitimately need several native payload/model attempts before the OpenAI
 * fallback, so a larger batch can exhaust the Free plan's 50-subrequest cap.
 * The dashboard advances `cursor`, making every model a fresh invocation while
 * this function persists one aggregate result set for the final table.
 */
export async function testModelBatch(prompt='سلام',options:{onlyCandidates?:boolean;cursor?:number;runId?:string}={}){
  const ai=(await loadConnections()).ai,providers=providersFromAi(ai),onlyCandidates=Boolean(options.onlyCandidates),tasks=aiTestTasks(ai,providers,onlyCandidates),cursor=Math.max(0,Math.trunc(Number(options.cursor)||0));
  const previous=cursor>0?await getState<StoredAiTest|null>('ai_test_results',null):null,runId=String(options.runId||previous?.runId||crypto.randomUUID()),startedAt=cursor===0||!previous?.startedAt?new Date().toISOString():previous.startedAt;
  if(cursor>0&&(!previous?.runId||previous.runId!==runId||previous.prompt!==prompt||previous.onlyCandidates!==onlyCandidates))throw new Error('نوبت آزمایش مدل‌ها منقضی یا تغییر داده شده است؛ آزمایش را از ابتدا اجرا کنید.');
  const results:any[]=cursor===0?[]:Array.isArray(previous?.results)?previous.results:[],batch=tasks.slice(cursor,cursor+AI_TEST_MODELS_PER_INVOCATION),batchResults:any[]=[];
  for(const task of batch){try{batchResults.push({...await aiCall(task.p,task.model,prompt,ai.network),key:task.key})}catch(error){batchResults.push(aiTestFailure(error,task,prompt))}}
  results.push(...batchResults);const nextCursor=Math.min(tasks.length,cursor+batch.length),done=nextCursor>=tasks.length,updatedAt=new Date().toISOString(),saved:StoredAiTest={runId,startedAt,updatedAt,prompt,onlyCandidates,total:tasks.length,results};await setState('ai_test_results',saved);
  return{ok:done&&results.some(x=>x.ok),runId,startedAt,updatedAt,prompt,total:tasks.length,cursor,nextCursor,done,batchSize:batch.length,maxModelsPerInvocation:AI_TEST_MODELS_PER_INVOCATION,succeeded:results.filter(x=>x.ok).length,failed:results.filter(x=>!x.ok).length,results,batchResults};
}
export async function getLastAiTestResults(){return getState<any>('ai_test_results',{runId:'',startedAt:null,updatedAt:null,prompt:'',total:0,results:[]})}
class AiResponseError extends Error{constructor(message:string,public detail:any){super(message);detail.error=message}}
function parseResponse(raw:string,secret=''):any{const limit=50_000,safe=secret?raw.replaceAll(secret,'[پنهان]'):raw;if(safe.length>limit)return{truncated:true,totalCharacters:safe.length,preview:safe.slice(0,limit)};try{return redactRaw(JSON.parse(safe))}catch{return safe}}
function redactRaw(value:any):any{if(Array.isArray(value))return value.map(redactRaw);if(value&&typeof value==='object'){const result:any={};for(const[key,item]of Object.entries(value))result[key]=/^(?:authorization|api[_-]?key|token|access[_-]?token|refresh[_-]?token|secret|password|consumer[_-]?(?:key|secret))$/i.test(key)?'[پنهان]':redactRaw(item);return result}return value}
function safeEndpoint(raw:string){try{const url=new URL(raw);url.username='';url.password='';for(const key of [...url.searchParams.keys()])if(/(?:key|token|secret|password|auth)/i.test(key))url.searchParams.set(key,'[پنهان]');return url.toString()}catch{return raw.replace(/([?&](?:key|token|secret|password|auth)[^=]*=)[^&]+/gi,'$1[پنهان]')}}
function safeError(raw:string,endpoint:string,apiKey:string):string{let value=String(raw||'').replaceAll(endpoint,safeEndpoint(endpoint));if(apiKey)value=value.replaceAll(apiKey,'[پنهان]');return value}
export async function recordVote(task:string,winner:string,candidates:string[]){const votes=await getState<any>('ai_votes',{scores:{},history:[]});for(const key of candidates){votes.scores[key]??={wins:0,tests:0};votes.scores[key].tests++;if(key===winner)votes.scores[key].wins++}votes.history.push({at:new Date().toISOString(),task,winner,candidates});votes.history=votes.history.slice(-1000);await setState('ai_votes',votes);return leaderboard(votes)}
export async function getLeaderboard(){return leaderboard(await getState<any>('ai_votes',{scores:{},history:[]}))}
function leaderboard(votes:any){return Object.entries(votes.scores||{}).map(([key,v]:any)=>({key,wins:v.wins||0,tests:v.tests||0,score:v.tests?Math.round(v.wins/v.tests*1000)/10:0})).sort((a,b)=>b.score-a.score||b.wins-a.wins)}
async function networkFetch(url:string,init:RequestInit,net:Network):Promise<Response>{assertPublicUrl(url);if(net.mode==='direct'||!net.mode)return safeFetch(url,init,3_000_000);if(net.workerUrl){const target=net.workerUrl.includes('{url}')?net.workerUrl.replace('{url}',encodeURIComponent(url)):net.workerUrl+(net.workerUrl.includes('?')?'&':'?')+'url='+encodeURIComponent(url);return safeFetch(target,{...init,headers:{...init.headers,'x-scraper-target':url}},3_000_000)}throw new Error(`حالت شبکه «${net.mode}» در Workers به Worker/Gateway واسط نیاز دارد؛ workerUrl را تنظیم کنید.`)}
