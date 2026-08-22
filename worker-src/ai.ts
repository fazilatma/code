import { MISTRAL_MODEL_ENDPOINTS } from './ai-catalog.js';
import { loadConnections } from './connections.js';
import { getState, setState } from './db.js';
import { assertPublicUrl, safeFetch } from './network.js';

export type CfAccountKey={accountId:string;token:string};
export type Provider={id:string;name:string;baseUrl:string;apiKey:string;apiKeys?:Array<string|CfAccountKey>;models:string[];reasoningModels:string[];enabled:boolean};
type Network={mode:string;proxyUrl:string;workerUrl:string;dohUrl:string;resolveIp:string};
type AiAttempt={endpoint:string;body:string|string[];model:string;httpStatus?:number;phase:'network'|'http'|'success';error?:string};
type RequestResult={response?:Response;body?:any;rawText?:string;networkError?:string};

function providersFromAi(ai:any):Provider[]{return ai.providers.length?ai.providers.map((provider:any)=>{const rawKeys=Array.isArray(provider.apiKeys)?provider.apiKeys:(provider.apiKey?[provider.apiKey]:[]);const keys=rawKeys.filter((k:any)=>k&&(typeof k==='string'?String(k).trim():String(k?.token||'').trim()));const first=keys[0]||provider.apiKey||'';const apiKey=typeof first==='string'?first:first?.token||'';return{...provider,apiKey,apiKeys:keys.length?keys:(apiKey?[apiKey]:[]),reasoningModels:Array.isArray(provider.reasoningModels)?provider.reasoningModels.map(String):[]}}):[{id:'default',name:'Default',baseUrl:ai.baseUrl,apiKey:ai.apiKey,apiKeys:ai.apiKey?[String(ai.apiKey)]:[],models:ai.model?[ai.model]:[],reasoningModels:[],enabled:true}]}

/** Active API keys of a provider (fallback to the single apiKey). */
export function providerKeys(provider:Provider):string[]{
  const keys=Array.isArray(provider.apiKeys)&&provider.apiKeys.length?provider.apiKeys:(provider.apiKey?[provider.apiKey]:[]);
  return keys.filter(k=>k&&(typeof k==='string'?String(k).trim():String((k as CfAccountKey).token||'').trim())).map(k=>typeof k==='string'?k:(k as CfAccountKey).token||'');
}
/** Clone of the provider bound to the n-th key (falls back to the first key). */
export function providerWithKey(provider:Provider,index=0):Provider{
  const keys=Array.isArray(provider.apiKeys)&&provider.apiKeys.length?provider.apiKeys:(provider.apiKey?[provider.apiKey]:[]);
  const chosen=keys[index]??keys[0]??(provider.apiKey||'');
  if(typeof chosen==='string')return{...provider,apiKey:chosen};
  const account=(chosen as CfAccountKey).accountId||cloudflareAccountId(provider.baseUrl)||'';
  const token=(chosen as CfAccountKey).token||provider.apiKey||'';
  return{...provider,apiKey:token,baseUrl:account?`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(account)}/ai/run/`:provider.baseUrl};
}
/** Parses an optional trailing `::k<n>` suffix from a model reference. */
export function parseModelKeySuffix(raw:string):{model:string;keyIndex:number}{const match=String(raw||'').match(/^(.*?)::k(\d+)$/);return match?{model:match[1],keyIndex:Math.max(0,Number(match[2])-1)}:{model:String(raw||''),keyIndex:0}}
/** Display suffix for non-primary keys, e.g. index 1 -> ' [K۲]'. */
export function aiKeySuffixLabel(index:number):string{return index>0?` [K${index+1}]`:''}
export async function aiProviders():Promise<Provider[]>{return providersFromAi((await loadConnections()).ai)}

/** Explicit user flags win first; the fallback covers common reasoning families already saved before this setting existed. */
export function isReasoningAiModel(provider:Pick<Provider,'reasoningModels'>|undefined,model:string):boolean{
  if(provider?.reasoningModels?.includes(model))return true;
  const value=String(model||'').toLowerCase();
  return /(?:^|[\/_:.-])(?:deepseek[-_.]?(?:r1|v4)|qwq|qwen3|gpt[-_.]?oss|gpt[-_.]?5|o[1-5](?:[-_.]|$)|reason(?:ing|er)?|thinking|think|magistral|leanstral|kimi[-_.]?k2|glm[-_.]?[45]|nemotron|reflection|bonsai|liquid)(?:[\/_:.-]|$)/i.test(value)||/cohere[^/]*reason/i.test(value);
}

export async function preferredAiChatModel():Promise<{provider:Provider;model:string}|null>{
  const ai=(await loadConnections()).ai,providers=providersFromAi(ai).filter(provider=>provider.enabled!==false),preferred=[ai.model,ai.master,...(Array.isArray(ai.candidates)?ai.candidates:[])].map(String).filter(Boolean);
  for(const key of preferred){const [providerId,...parts]=key.split('::'),model=parts.length?parts.join('::'):key;const provider=parts.length?providers.find(item=>item.id===providerId):providers.find(item=>item.models.includes(model));if(provider&&provider.models.includes(model)&&isChatCompatibleAiModel(provider,model))return{provider,model}}
  for(const provider of providers){const model=provider.models.find(item=>isChatCompatibleAiModel(provider,item));if(model)return{provider,model}}
  return null;
}

export type AiModelEndpoint='chat-completions'|'ocr'|'embeddings';
type AiEndpointProvider=Pick<Provider,'id'> & Partial<Pick<Provider,'baseUrl'>>;
function isMistralProvider(provider:AiEndpointProvider):boolean{return provider.id==='mistral'||/api\.mistral\.ai/i.test(String(provider.baseUrl||''))}
export function aiModelEndpoint(provider:AiEndpointProvider,model:string):AiModelEndpoint{return isMistralProvider(provider)?MISTRAL_MODEL_ENDPOINTS[model]||'chat-completions':'chat-completions'}
export function isChatCompatibleAiModel(provider:AiEndpointProvider,model:string):boolean{return aiModelEndpoint(provider,model)==='chat-completions'}

export async function aiCall(provider:Provider,model:string,prompt:string,networkOverride?:Network,timeoutMs?:number,batchId?:string){
  const network=networkOverride||(await loadConnections()).ai.network;
  if(!provider.baseUrl||!provider.apiKey||!model)throw new Error('تنظیمات ارائه‌دهنده/مدل کامل نیست');
  const started=Date.now();
  if(isCloudflareNative(provider.baseUrl))return cloudflareCall(provider,model,prompt,network,started,timeoutMs);
  const endpointType=aiModelEndpoint(provider,model);
  if(endpointType!=='chat-completions')return mistralDedicatedCall(provider,model,prompt,network,started,endpointType,timeoutMs);
  const endpoint=openAiEndpoint(provider.baseUrl),reportedEndpoint=safeEndpoint(endpoint),reasoning=isReasoningAiModel(provider,model),chatModel=canonicalAiModel(model),messages=[{role:'user',content:prompt}],payload:any={model:chatModel,messages,max_tokens:reasoning?1600:400};if(!reasoning)payload.temperature=.2;
  if(batchId)return batchChatCall(provider,chatModel,prompt,payload,network,started,timeoutMs,reasoning,batchId);
  let result=await requestAi(endpoint,payload,provider,network,timeoutMs),usedPayload=payload;
  // Providers disagree on token-limit field names and whether temperature is legal; adapt only on 400/422, never on billing/credit errors.
  for(let attempt=0;attempt<3;attempt++){
    if(result.networkError||!result.response||result.response.ok||isCreditAiStatus(result.response.status,aiErrorMessage(result.body)))break;
    const errorText=aiErrorMessage(result.body);
    if(!isPayloadShapeError(result.response.status,errorText))break;
    const adapted=adjustChatPayload(usedPayload,errorText);if(!adapted)break;
    usedPayload=adapted;result=await requestAi(endpoint,usedPayload,provider,network,timeoutMs);
  }
  if(result.networkError){const reason=safeError(result.networkError,endpoint,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt,endpoint:reportedEndpoint,latencyMs:Date.now()-started,reasoning,raw:{error:reason}})}
  const response=result.response!,body=result.body,latencyMs=Date.now()-started,errorText=aiErrorMessage(body)||response.statusText||'AI error';
  if(!response.ok&&isBatchOnlyError(response.status,errorText))return batchChatCall(provider,chatModel,prompt,usedPayload,network,started,timeoutMs,reasoning);
  if(!response.ok)throw new AiResponseError(`HTTP ${response.status}: ${errorText}`,{...failureDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body),reasoning});
  return validatedChatSuccess(provider,model,prompt,reportedEndpoint,latencyMs,response,body,{endpointType:'chat-completions',chatCompatible:true,reasoning});
}


/** Chat with full conversation history (aiCall only sends a single prompt). */
export async function aiChat(provider:Provider,model:string,messages:Array<{role:string;content:string}>,networkOverride?:Network,timeoutMs?:number,maxTokens=1200,keyIndex=0){const providerUsed=providerWithKey(provider,keyIndex);provider=providerUsed;
  const network=networkOverride||(await loadConnections()).ai.network;
  if(!provider.baseUrl||!provider.apiKey||!model)throw new Error('تنظیمات ارائه‌دهنده/مدل کامل نیست');
  const started=Date.now(),chatMessages=messages.slice(-40).map(m=>({role:String(m.role||'user'),content:String(m.content||'')}));
  if(!chatMessages.length||chatMessages[chatMessages.length-1].role!=='user')throw new Error('آخرین پیام باید از سمت کاربر باشد.');
  const lastPrompt=chatMessages[chatMessages.length-1].content;
  if(isCloudflareNative(provider.baseUrl)){
    const accountId=cloudflareAccountId(provider.baseUrl);
    if(!accountId)throw new AiResponseError('شمارهٔ حساب Cloudflare در آدرس پیدا نشد',{ok:false,phase:'configuration',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,latencyMs:Date.now()-started});
    const models=cloudflareModelIds(model),base=`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(accountId)}/ai/run/`;
    for(const modelId of models){
      const endpoint=base+modelId.replace(/^\/+/,''),payload={messages:chatMessages,max_tokens:maxTokens};
      const result=await requestAi(endpoint,payload,provider,network,timeoutMs);
      if(result.networkError)throw new AiResponseError(safeError(result.networkError,endpoint,provider.apiKey),{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,endpoint:safeEndpoint(endpoint),latencyMs:Date.now()-started,raw:{error:safeError(result.networkError,endpoint,provider.apiKey)}});
      const response=result.response!,body=result.body,errorText=aiErrorMessage(body)||response.statusText||'Cloudflare AI error';
      if(!response.ok)throw new AiResponseError(`HTTP ${response.status}: ${errorText}`,{ok:false,phase:'http',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,endpoint:safeEndpoint(endpoint),latencyMs:Date.now()-started,httpStatus:response.status,raw:body});
      const text=String(body?.result?.response||body?.choices?.[0]?.message?.content||'').trim();
      if(!text)throw new AiResponseError('مدل پاسخی برنگرداند.',{ok:false,phase:'validation',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,endpoint:safeEndpoint(endpoint),latencyMs:Date.now()-started,raw:body});
      return{ok:true,text,provider:provider.id,providerName:provider.name,model:modelId,latencyMs:Date.now()-started};
    }
    throw new AiResponseError('هیچ مدل Cloudflare برای این شناسه پیدا نشد',{ok:false,phase:'configuration',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,latencyMs:Date.now()-started});
  }
  const endpoint=openAiEndpoint(provider.baseUrl),reportedEndpoint=safeEndpoint(endpoint),reasoning=isReasoningAiModel(provider,model),chatModel=canonicalAiModel(model),payload:any={model:chatModel,messages:chatMessages,max_tokens:reasoning?maxTokens:Math.min(maxTokens,800)};if(!reasoning)payload.temperature=.7;
  let result=await requestAi(endpoint,payload,provider,network,timeoutMs),usedPayload=payload;
  for(let attempt=0;attempt<3;attempt++){
    if(result.networkError||!result.response||result.response.ok||isCreditAiStatus(result.response.status,aiErrorMessage(result.body)))break;
    const errorText=aiErrorMessage(result.body);
    if(!isPayloadShapeError(result.response.status,errorText))break;
    const adapted=adjustChatPayload(usedPayload,errorText);if(!adapted)break;
    usedPayload=adapted;result=await requestAi(endpoint,usedPayload,provider,network,timeoutMs);
  }
  if(result.networkError){const reason=safeError(result.networkError,endpoint,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,endpoint:reportedEndpoint,latencyMs:Date.now()-started,raw:{error:reason}})}
  const response=result.response!,body=result.body,latencyMs=Date.now()-started,errorText=aiErrorMessage(body)||response.statusText||'AI error';
  if(!response.ok)throw new AiResponseError(`HTTP ${response.status}: ${errorText}`,{ok:false,phase:'http',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,endpoint:reportedEndpoint,latencyMs,httpStatus:response.status,raw:body});
  const text=String(body?.choices?.[0]?.message?.content||body?.result?.response||'').trim();
  if(!text)throw new AiResponseError('مدل با وجود پاسخ HTTP موفق، هیچ متن یا پاسخ نهایی برنگرداند.',{ok:false,phase:'validation',provider:provider.id,providerName:provider.name,model,prompt:lastPrompt,endpoint:reportedEndpoint,latencyMs,raw:body});
  return{ok:true,text,provider:provider.id,providerName:provider.name,model:chatModel,latencyMs};
}

const MISTRAL_OCR_TEST_IMAGE='https://raw.githubusercontent.com/mistralai/cookbook/main/mistral/ocr/receipt.png';

// ─── Agentic AI: tool calling (function calling) ─────────────────────────────
export type AiTool={type:'function';function:{name:string;description:string;parameters:Record<string,unknown>}};
export type AiToolCall={id:string;name:string;arguments:Record<string,unknown>};
export type AiAgentTurn={text:string;toolCalls:AiToolCall[];raw:any;latencyMs:number;providerId:string;model:string};

/** Parses a `function.arguments` JSON string into an object without ever crashing the loop. */
export function parseToolArguments(raw:unknown):Record<string,unknown>{
  const value=String(raw??'');
  if(!value.trim())return{};
  try{const parsed=JSON.parse(value);return parsed&&typeof parsed==='object'&&!Array.isArray(parsed)?parsed:{raw:value}}catch{return{raw:value}}
}
/** Extracts assistant message text + tool_calls from any chat-completions shaped body. */
export function parseAgentTurn(body:any):{text:string;toolCalls:AiToolCall[]}{
  const message=body?.choices?.[0]?.message;
  if(!message||typeof message!=='object')return{text:String(body?.result?.response||body?.response||''),toolCalls:[]};
  const text=typeof message.content==='string'?message.content:'';
  const toolCalls:AiToolCall[]=Array.isArray(message.tool_calls)?message.tool_calls.filter((call:any)=>call&&call.function).map((call:any)=>({id:String(call.id||`call_${crypto.randomUUID()}`),name:String(call.function.name||''),arguments:parseToolArguments(call.function.arguments)})):[];
  return{text,toolCalls};
}

/**
 * One agentic turn: sends the conversation + tool definitions and returns the model's
 * text and/or requested tool calls. Works with Cloudflare Workers AI native models and
 * any OpenAI-compatible endpoint; provider/model must actually support tool use.
 */
export async function aiAgentCall(provider:Provider,model:string,messages:Array<{role:string;content:string|null;tool_call_id?:string;tool_calls?:Array<{id:string;type:string;function:{name:string;arguments:string}}>}>,tools:AiTool[],networkOverride?:Network,timeoutMs?:number,maxTokens=2000):Promise<AiAgentTurn>{
  const network=networkOverride||(await loadConnections()).ai.network,started=Date.now();
  if(!provider.baseUrl||!provider.apiKey||!model)throw new Error('تنظیمات ارائه‌دهنده/مدل کامل نیست');
  const canonical=canonicalAiModel(model);
  if(isCloudflareNative(provider.baseUrl)){
    const accountId=cloudflareAccountId(provider.baseUrl);
    if(!accountId)throw new AiResponseError('شمارهٔ حساب Cloudflare در آدرس پیدا نشد',{ok:false,phase:'configuration',provider:provider.id,providerName:provider.name,model,prompt:messages[messages.length-1]?.content||'',latencyMs:Date.now()-started});
    const models=cloudflareModelIds(model),base=`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(accountId)}/ai/run/`;
    for(const modelId of models){
      const endpoint=base+modelId.replace(/^\/+/,''),payload={messages,tools,max_tokens:maxTokens};
      const result=await requestAi(endpoint,payload,provider,network,timeoutMs);
      if(result.networkError)throw new AiResponseError(safeError(result.networkError,endpoint,provider.apiKey),{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt:messages[messages.length-1]?.content||'',endpoint:safeEndpoint(endpoint),latencyMs:Date.now()-started,raw:{error:safeError(result.networkError,endpoint,provider.apiKey)}});
      const response=result.response!,body=result.body,errorText=aiErrorMessage(body)||response.statusText||'Cloudflare AI error';
      if(!response.ok)throw new AiResponseError(`HTTP ${response.status}: ${errorText}`,{ok:false,phase:'http',provider:provider.id,providerName:provider.name,model,prompt:messages[messages.length-1]?.content||'',endpoint:safeEndpoint(endpoint),latencyMs:Date.now()-started,httpStatus:response.status,raw:body});
      const turn=parseAgentTurn(body);
      return{...turn,raw:parseResponse(JSON.stringify(body),provider.apiKey),latencyMs:Date.now()-started,providerId:provider.id,model:modelId};
    }
    throw new AiResponseError('هیچ مدل Cloudflare برای این شناسه پیدا نشد',{ok:false,phase:'configuration',provider:provider.id,providerName:provider.name,model,prompt:messages[messages.length-1]?.content||'',latencyMs:Date.now()-started});
  }
  const endpoint=openAiEndpoint(provider.baseUrl),reasoning=isReasoningAiModel(provider,model),payload:any={model:canonical,messages,tools,tool_choice:'auto',max_tokens:maxTokens};
  if(!reasoning)payload.temperature=.2;
  let result=await requestAi(endpoint,payload,provider,network,timeoutMs),usedPayload=payload;
  // Some reasoning/tool models reject temperature or max_tokens; adapt only on payload-shape 400/422.
  for(let attempt=0;attempt<3;attempt++){
    if(result.networkError||!result.response||result.response.ok||isCreditAiStatus(result.response.status,aiErrorMessage(result.body)))break;
    const errorText=aiErrorMessage(result.body);
    if(!isPayloadShapeError(result.response.status,errorText))break;
    const adapted=adjustChatPayload(usedPayload,errorText);if(!adapted)break;
    usedPayload=adapted;result=await requestAi(endpoint,usedPayload,provider,network,timeoutMs);
  }
  if(result.networkError){const reason=safeError(result.networkError,endpoint,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt:messages[messages.length-1]?.content||'',endpoint:safeEndpoint(endpoint),latencyMs:Date.now()-started,raw:{error:reason}})}
  const response=result.response!,body=result.body,latencyMs=Date.now()-started,errorText=aiErrorMessage(body)||response.statusText||'AI error';
  if(!response.ok)throw new AiResponseError(`HTTP ${response.status}: ${errorText}`,{ok:false,phase:'http',provider:provider.id,providerName:provider.name,model,prompt:messages[messages.length-1]?.content||'',endpoint:safeEndpoint(endpoint),latencyMs,httpStatus:response.status,raw:body});
  const turn=parseAgentTurn(body);
  return{...turn,raw:parseResponse(JSON.stringify(body),provider.apiKey),latencyMs,providerId:provider.id,model:canonical};
}
async function mistralDedicatedCall(provider:Provider,model:string,prompt:string,network:Network,started:number,endpointType:Exclude<AiModelEndpoint,'chat-completions'>,timeoutMs?:number){
  const endpoint=mistralEndpoint(provider.baseUrl,endpointType),reportedEndpoint=safeEndpoint(endpoint),payload=endpointType==='embeddings'?{model,input:[prompt||'سلام']}:{model,document:{type:'image_url',image_url:MISTRAL_OCR_TEST_IMAGE},include_image_base64:false};
  const result=await requestAi(endpoint,payload,provider,network,timeoutMs);
  if(result.networkError){const reason=safeError(result.networkError,endpoint,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt,endpoint:reportedEndpoint,endpointType,chatCompatible:false,latencyMs:Date.now()-started,raw:{error:reason}})}
  const response=result.response!,body=result.body,latencyMs=Date.now()-started;
  if(!response.ok)throw new AiResponseError(`HTTP ${response.status}: ${aiErrorMessage(body)||response.statusText||'AI error'}`,{...failureDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body),endpointType,chatCompatible:false});
  if(endpointType==='embeddings'){
    const vector=body?.data?.[0]?.embedding,dimensions=Array.isArray(vector)?vector.length:Number(body?.data?.[0]?.dimensions)||0;
    if(!dimensions)throw new AiResponseError('پاسخ endpoint امبدینگ فاقد بردار معتبر بود.',{...failureDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body),phase:'validation',endpointType,chatCompatible:false});
    return successDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body,{endpointType,chatCompatible:false,text:`بردار امبدینگ معتبر با ${dimensions} بُعد دریافت شد.`,dimensions});
  }
  const pages=Array.isArray(body?.pages)?body.pages:[],text=pages.map((page:any)=>String(page?.markdown||'').trim()).filter(Boolean).join('\n\n');
  if(!pages.length)throw new AiResponseError('پاسخ endpoint OCR فاقد صفحهٔ معتبر بود.',{...failureDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body),phase:'validation',endpointType,chatCompatible:false});
  return successDetail(provider,model,prompt,reportedEndpoint,latencyMs,response,body,{endpointType,chatCompatible:false,text:text||`OCR با موفقیت ${pages.length} صفحه را پردازش کرد.`,pages:pages.length,testInput:'official-public-receipt-image'});
}

async function cloudflareCall(provider:Provider,model:string,prompt:string,network:Network,started:number,timeoutMs?:number){
  const accountId=cloudflareAccountId(provider.baseUrl);
  if(!accountId)throw new AiResponseError('شمارهٔ حساب Cloudflare در آدرس پیدا نشد',{ok:false,phase:'configuration',provider:provider.id,providerName:provider.name,model,prompt,endpoint:safeEndpoint(provider.baseUrl),latencyMs:Date.now()-started,raw:{error:'Cloudflare account ID was not found in base URL'}});
  const reasoning=isReasoningAiModel(provider,model),maxTokens=reasoning?1600:400,models=cloudflareModelIds(model),base=`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(accountId)}/ai/run/`,messages=[{role:'user',content:prompt}],attempts:AiAttempt[]=[];
  let last:RequestResult|undefined,lastEndpoint=base+models[0];
  for(const modelId of models){
    const endpoint=base+modelId.replace(/^\/+/,''),bodies:Array<{label:string;value:any}>=[{label:'prompt',value:{prompt,max_tokens:maxTokens}},{label:'messages',value:{messages,max_tokens:maxTokens}},{label:'text',value:{text:prompt,max_tokens:maxTokens}}];
    for(const candidate of bodies){
      const result=await requestAi(endpoint,candidate.value,provider,network,timeoutMs);last=result;lastEndpoint=endpoint;
      const error=result.networkError?safeError(result.networkError,endpoint,provider.apiKey):result.response?.ok?'':aiErrorMessage(result.body)||result.response?.statusText||'Cloudflare AI error';
      attempts.push({endpoint:safeEndpoint(endpoint),body:Object.keys(candidate.value),model:modelId,httpStatus:result.response?.status,phase:result.networkError?'network':result.response?.ok?'success':'http',...(error?{error}:{})});
      if(result.response?.ok)return validatedChatSuccess(provider,model,prompt,safeEndpoint(endpoint),Date.now()-started,result.response,result.body,{reasoning,cloudflare:{mode:'native',resolvedModel:modelId,triedModels:models,attempts}});
    }
  }
  const chatEndpoint=`https://api.cloudflare.com/client/v4/accounts/${encodeURIComponent(accountId)}/ai/v1/chat/completions`;
  for(const modelId of models){
    const payload={model:modelId,messages,max_tokens:maxTokens},result=await requestAi(chatEndpoint,payload,provider,network,timeoutMs);last=result;lastEndpoint=chatEndpoint;
    const error=result.networkError?safeError(result.networkError,chatEndpoint,provider.apiKey):result.response?.ok?'':aiErrorMessage(result.body)||result.response?.statusText||'Cloudflare AI error';
    attempts.push({endpoint:safeEndpoint(chatEndpoint),body:`chat-${modelId}`,model:modelId,httpStatus:result.response?.status,phase:result.networkError?'network':result.response?.ok?'success':'http',...(error?{error}:{})});
    if(result.response?.ok)return validatedChatSuccess(provider,model,prompt,safeEndpoint(chatEndpoint),Date.now()-started,result.response,result.body,{reasoning,cloudflare:{mode:'openai-fallback',resolvedModel:modelId,triedModels:models,attempts}});
  }
  const latencyMs=Date.now()-started,cloudflare={mode:'failed',triedModels:models,attempts};
  if(last?.networkError){const reason=safeError(last.networkError,lastEndpoint,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt,endpoint:safeEndpoint(lastEndpoint),latencyMs,cloudflare,raw:{error:reason}})}
  const response=last?.response,status=response?.status||0,body=last?.body,message=aiErrorMessage(body)||(models.length>1?`مدل «${models[0]}» یافت نشد؛ مسیر org دار «${models[1]}» نیز امتحان شد.`:'پاسخی از Cloudflare AI دریافت نشد.');
const guide=/no such model|not found|bad input|oneof|one of/i.test(message)?' این مدل در کاتالوگ فعلی Workers AI وجود ندارد (احتمالاً بازنشسته شده یا شناسه اشتباه است). از AI ← راهنما ← کاتالوگ کامل مدل‌های Workers AI یک مدل فعال انتخاب کنید.':' برای مدل‌های تصویری، ورودی «text» نیز امتحان شد.';
  throw new AiResponseError(`HTTP ${status}: ${message}${guide}`,{...failureDetail(provider,model,prompt,safeEndpoint(lastEndpoint),latencyMs,response,body),cloudflare});
}

function isCloudflareNative(raw:string):boolean{return /\/accounts\/[^/]+\/ai\/run(?:\/|$)/i.test(unmarkdownUrl(raw))}
function cloudflareAccountId(raw:string):string{return unmarkdownUrl(raw).match(/\/accounts\/([^/]+)\/ai\/run(?:\/|$)/i)?.[1]||''}
function unmarkdownUrl(raw:string):string{const value=String(raw||'').trim(),match=value.match(/^\[[^\]]+\]\(([^)]+)\)$/);return match?.[1]||value}
function openAiEndpoint(raw:string):string{
  const value=unmarkdownUrl(raw),url=new URL(value);if(/\/chat\/completions\/?$/i.test(url.pathname))return url.toString();
  if(url.port==='11434'&&!/\/v1\/?$/i.test(url.pathname))url.pathname=url.pathname.replace(/\/$/,'')+'/v1';
  url.pathname=url.pathname.replace(/\/$/,'')+'/chat/completions';return url.toString();
}
function mistralEndpoint(raw:string,type:Exclude<AiModelEndpoint,'chat-completions'>):string{
  const url=new URL(unmarkdownUrl(raw)),suffix=type==='ocr'?'ocr':'embeddings';
  url.pathname=url.pathname.replace(/\/(?:chat\/completions|ocr|embeddings)\/?$/i,'').replace(/\/$/,'')+'/'+suffix;
  return url.toString();
}
function cloudflareModelIds(raw:string):string[]{
  const model=String(raw||'').trim().replace(/^\/+/,''),out=[model],after=model.replace(/^@cf\//i,'');
  if(after&&after.indexOf('/')<0){
    const rules:Array<[string,string]>=[['gemma-sea-lion','aisingapore'],['mistral-small','mistralai'],['llama-guard','meta'],['gpt-oss','openai'],['deepseek','deepseek-ai'],['nemotron','nvidia'],['moondream','moondream'],['embeddinggemma','google'],['hermes','nousresearch'],['uform','unum-cloud'],['plamo','pfnet'],['granite','ibm-granite'],['kimi','moonshotai'],['gemma','google'],['mistral','mistral'],['glm','zai-org'],['phi','microsoft'],['bge','baai'],['llama','meta'],['qwen','qwen'],['qwq','qwen'],['sqlcoder','defog'],['florence','microsoft'],['llava','llava-hf']];
    const rule=rules.find(([prefix])=>after.toLowerCase().startsWith(prefix));if(rule)out.push(`@cf/${rule[1]}/${after}`);
    // Meta's hosted ids use `meta/llama-*`, never `meta-llama/*`; rewrite a user-typed meta-llama/ prefix.
    if(after.startsWith('meta-llama/'))out.push(`@cf/meta/${after.slice('meta-llama/'.length)}`);
  }
  return [...new Set(out.filter(Boolean))];
}
function canonicalAiModel(model:string){return String(model||'').trim().replace(/^~+/,'')}
function isOpenRouter(provider:Pick<Provider,'id'|'name'|'baseUrl'>,endpoint=''){return provider.id==='openrouter'||/openrouter/i.test(String(provider.name||''))||/openrouter\.ai/i.test(String(provider.baseUrl||endpoint||''))}
function aiRequestHeaders(provider:Provider,endpoint:string,method:'POST'|'GET'='POST'):Record<string,string>{
  const headers:Record<string,string>={authorization:`Bearer ${provider.apiKey}`,accept:'application/json','user-agent':'Scraper4/1.26.1'};
  if(method==='POST')headers['content-type']='application/json';
  if(isOpenRouter(provider,endpoint)){headers['http-referer']='https://scraper4.workers.dev';headers.referer='https://scraper4.workers.dev';headers['x-title']='Scraper 4'}
  return headers;
}
function isSecurityPolicyError(status:number,message:string){return status===403&&/access denied by security policy|security policy|cf-mitigated|bot fight/i.test(String(message||''))}
async function requestAi(endpoint:string,payload:any,provider:Provider,network:Network,timeoutMs?:number):Promise<RequestResult>{
  try{
    const headers=aiRequestHeaders(provider,endpoint,'POST');
    let response=await networkFetch(endpoint,{method:'POST',headers,body:JSON.stringify(payload)},network,timeoutMs),rawText=await response.text(),body=parseResponse(rawText,provider.apiKey);
    if(isSecurityPolicyError(response.status,aiErrorMessage(body))){
      const retryHeaders:Record<string,string>={...headers,'user-agent':'Scraper4-AI/1.15'};delete retryHeaders.referer;delete retryHeaders['http-referer'];delete retryHeaders['x-title'];
      response=await networkFetch(endpoint,{method:'POST',headers:retryHeaders,body:JSON.stringify(payload)},network,timeoutMs);rawText=await response.text();body=parseResponse(rawText,provider.apiKey);
    }
    return{response,body,rawText};
  }catch(error){return{networkError:error instanceof Error?error.message:String(error)}}
}
async function requestAiGet(endpoint:string,provider:Provider,network:Network,timeoutMs?:number):Promise<RequestResult>{
  try{const response=await networkFetch(endpoint,{method:'GET',headers:aiRequestHeaders(provider,endpoint,'GET')},network,timeoutMs),rawText=await response.text(),body=parseResponse(rawText,provider.apiKey);return{response,body,rawText}}
  catch(error){return{networkError:error instanceof Error?error.message:String(error)}}
}
function isCreditAiText(value:string){return /(?:^|\b)(?:402|insufficient[_.\s-]?quota|insufficient[_.\s-]?credit|credit[_.\s-]?balance|payment[_.\s-]?required|billing|out of credits|no credits|موجودی اعتبار|اعتبار.*تمام|شارژ.*تمام)(?:\b|$)/i.test(String(value||''))&&!/(?:429|rate.?limit|too many requests)/i.test(String(value||''))}
function isCreditAiStatus(status:number,message:string){return status===402||isCreditAiText(`${status} ${message}`)}
function isPayloadShapeError(status:number,message:string){return(status===400||status===422)&&!isCreditAiText(message)&&!isBatchOnlyError(status,message)}
function adjustChatPayload(payload:any,errorText:string):any|null{
  const msg=String(errorText||''),next={...payload};let changed=false;
  if(/temperature/i.test(msg)&&'temperature' in next){delete next.temperature;changed=true}
  if(/max_completion_tokens/i.test(msg)&&next.max_tokens!=null){next.max_completion_tokens=next.max_tokens;delete next.max_tokens;changed=true}
  else if(/max_tokens/i.test(msg)&&next.max_completion_tokens!=null){next.max_tokens=next.max_completion_tokens;delete next.max_completion_tokens;changed=true}
  else if(/max_tokens|max_completion_tokens/i.test(msg)&&next.max_tokens!=null){next.max_completion_tokens=next.max_tokens;delete next.max_tokens;changed=true}
  if(!changed&&/unsupported (?:parameter|value|argument)|unknown argument|unrecognized request argument|extra fields not permitted/i.test(msg)){
    const slim:any={model:payload.model,messages:payload.messages};
    if(payload.max_completion_tokens)slim.max_completion_tokens=payload.max_completion_tokens;else if(payload.max_tokens)slim.max_tokens=payload.max_tokens;
    return slim;
  }
  return changed?next:null;
}
function isBatchOnlyError(status:number,message:string){return(status===404||status===400||status===403)&&/batch api|\/api\/beta\/batches/i.test(message)}
function batchApiUrl(chatEndpoint:string){const url=new URL(chatEndpoint);url.pathname='/api/beta/batches';url.search='';url.hash='';return url.toString()}
function sleep(ms:number){return new Promise(resolve=>setTimeout(resolve,Math.max(0,ms)))}
async function batchChatCall(provider:Provider,model:string,prompt:string,chatPayload:any,network:Network,started:number,timeoutMs:number|undefined,reasoning:boolean,existingId=''){
  const batchUrl=batchApiUrl(openAiEndpoint(provider.baseUrl)),reported=safeEndpoint(batchUrl),deadline=started+Math.max(4_000,timeoutMs||20_000);
  const chatBody:any={messages:chatPayload.messages};if(chatPayload.max_tokens)chatBody.max_tokens=chatPayload.max_tokens;if(chatPayload.max_completion_tokens)chatBody.max_completion_tokens=chatPayload.max_completion_tokens;if('temperature' in chatPayload)chatBody.temperature=chatPayload.temperature;
  let batchId=String(existingId||'').trim(),result:RequestResult|undefined,body:any,response:Response|undefined;
  if(!batchId){
    result=await requestAi(batchUrl,{endpoint:'/v1/chat/completions',model,requests:[{custom_id:'s4-1',body:chatBody}]},provider,network,Math.max(1000,deadline-Date.now()));
    if(result.networkError){const reason=safeError(result.networkError,batchUrl,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt,endpoint:reported,endpointType:'batch',latencyMs:Date.now()-started,reasoning,raw:{error:reason}})}
    response=result.response;body=result.body;batchId=String(body?.id||'');
    if(!response?.ok&&response?.status!==202)throw new AiResponseError(`HTTP ${response?.status||0}: ${aiErrorMessage(body)||'Batch API error'}`,{...failureDetail(provider,model,prompt,reported,Date.now()-started,response,body),reasoning,endpointType:'batch',batchId:batchId||null});
    if(!batchId)throw new AiResponseError('Batch API شناسهٔ دسته برنگرداند.',{...failureDetail(provider,model,prompt,reported,Date.now()-started,response,body),reasoning,endpointType:'batch'});
  }
  const terminal=new Set(['completed','failed','expired','cancelled']);
  while(!terminal.has(String(body?.status||''))&&Date.now()<deadline){
    await sleep(Math.min(800,Math.max(150,deadline-Date.now())));
    result=await requestAiGet(`${batchUrl.replace(/\/$/,'')}/${encodeURIComponent(batchId)}`,provider,network,Math.max(1000,deadline-Date.now()));
    if(result.networkError){const reason=safeError(result.networkError,batchUrl,provider.apiKey);throw new AiResponseError(reason,{ok:false,phase:'network',provider:provider.id,providerName:provider.name,model,prompt,endpoint:reported,endpointType:'batch',batchId,latencyMs:Date.now()-started,reasoning,raw:{error:reason}})}
    response=result.response;body=result.body;
  }
  const latencyMs=Date.now()-started,status=String(body?.status||''),pending=!terminal.has(status);
  if(status!=='completed')throw new AiResponseError(pending?`Batch API هنوز تمام نشده (وضعیت: ${status||'pending'}).`:`HTTP ${response?.status||0}: ${aiErrorMessage(body)||status||'Batch API error'}`,{...failureDetail(provider,model,prompt,reported,latencyMs,response,body),reasoning,endpointType:'batch',batchId,phase:pending?'batch-pending':'http',retryable:pending,skipped:pending});
  const item=(Array.isArray(body?.results)?body.results:[]).find((row:any)=>row.custom_id==='s4-1')||body?.results?.[0],innerError=item?.error,inner=item?.response?.body??item?.body,innerStatus=Number(item?.response?.status_code??item?.response?.status??(inner?200:0));
  if(innerError)throw new AiResponseError(String(innerError.message||innerError.code||innerError),{...failureDetail(provider,model,prompt,reported,latencyMs,response,body),reasoning,endpointType:'batch',batchId});
  if(!inner||innerStatus>=400)throw new AiResponseError(`HTTP ${innerStatus}: ${aiErrorMessage(inner)||'پاسخ Batch API خالی بود.'}`,{...failureDetail(provider,model,prompt,reported,latencyMs,response,inner||body),reasoning,endpointType:'batch',batchId});
  return validatedChatSuccess(provider,model,prompt,reported,latencyMs,response!,inner,{endpointType:'batch',chatCompatible:true,reasoning,batchId});
}
function cleanReasoningTags(value:string):string{const original=String(value||'').trim(),without=original.replace(/<think(?:ing)?>[\s\S]*?<\/think(?:ing)?>/gi,'').replace(/<\/?(?:analysis|reasoning)>/gi,'').trim();return without||original}
function extractAiResponse(body:any):{text:string;reasoningText:string;reasoningOnly:boolean}{
  const choices=Array.isArray(body?.choices)?body.choices:[],reasoningParts:string[]=[],answers:string[]=[];
  for(const choice of choices){const message=choice?.message||{};for(const value of [message?.reasoning_content,message?.reasoning])if(typeof value==='string'&&value.trim())reasoningParts.push(value.trim());const content=message?.content;if(typeof content==='string'&&content.trim())answers.push(content.trim());else if(Array.isArray(content)){const joined=content.map((item:any)=>typeof item==='string'?item:item?.text||item?.content||'').join('').trim();if(joined)answers.push(joined)}if(typeof choice?.text==='string'&&choice.text.trim())answers.push(choice.text.trim());if(typeof message?.refusal==='string'&&message.refusal.trim())answers.push(message.refusal.trim())}
  for(const value of [body?.output_text,body?.result?.response,body?.result?.text,body?.response,body?.text,body?.data?.[0]?.text])if(typeof value==='string'&&value.trim())answers.push(value.trim());
  const output=body?.output;if(Array.isArray(output)){for(const item of output){if(typeof item==='string'&&item.trim())answers.push(item.trim());else if(item&&typeof item==='object'){if(typeof item.text==='string'&&item.text.trim())answers.push(item.text.trim());const content=item.content;if(typeof content==='string'&&content.trim())answers.push(content.trim());else if(Array.isArray(content)){const joined=content.map((segment:any)=>typeof segment==='string'?segment:segment?.text||segment?.content||'').join('').trim();if(joined)answers.push(joined)}}}}else if(Array.isArray(output?.choices)){for(const choice of output.choices){const value=choice?.message?.content||choice?.text;if(typeof value==='string'&&value.trim())answers.push(value.trim())}}
  const reasoningText=reasoningParts.join('\n\n').trim(),answer=answers.find(Boolean)||'',cleaned=cleanReasoningTags(answer);return{text:cleaned||(reasoningText?cleanReasoningTags(reasoningText):''),reasoningText,reasoningOnly:!answer&&Boolean(reasoningText)};
}
function successDetail(provider:Provider,model:string,prompt:string,endpoint:string,latencyMs:number,response:Response,body:any,extra:Record<string,unknown>={}){const extracted=extractAiResponse(body);return{ok:true,text:extracted.text,reasoningText:extracted.reasoningText,reasoningOnly:extracted.reasoningOnly,latencyMs,provider:provider.id,providerName:provider.name,model,prompt,endpoint,httpStatus:response.status,httpStatusText:response.statusText,contentType:response.headers.get('content-type')||'',usage:body?.usage||body?.result?.usage||null,finishReason:body?.choices?.[0]?.finish_reason||'',raw:body,...extra}}
function validatedChatSuccess(provider:Provider,model:string,prompt:string,endpoint:string,latencyMs:number,response:Response,body:any,extra:Record<string,unknown>={}){const detail=successDetail(provider,model,prompt,endpoint,latencyMs,response,body,extra);if(!detail.text.trim())throw new AiResponseError('مدل با وجود پاسخ HTTP موفق، هیچ متن یا پاسخ نهایی برنگرداند.',{...detail,ok:false,phase:'validation'});return detail}
function failureDetail(provider:Provider,model:string,prompt:string,endpoint:string,latencyMs:number,response?:Response,body?:any){return{ok:false,phase:'http',provider:provider.id,providerName:provider.name,model,prompt,endpoint,latencyMs,httpStatus:response?.status||0,httpStatusText:response?.statusText||'',contentType:response?.headers.get('content-type')||'',raw:body??null}}
function aiErrorMessage(body:any):string{
  if(body&&Array.isArray(body.errors))for(const error of body.errors){const message=String(error?.message||'').trim();if(message)return Number(error?.internalCode??error?.code)===5007?`مدل در Cloudflare وجود ندارد (No such model): ${message}`:message}
  return String(body?.error?.message||body?.message||body?.error||'').trim();
}

export type AiCategoryOption={id:number;name:string;path?:string;parentId?:number|null;leaf?:boolean};
function categoryRows(title:string,categories:AiCategoryOption[]){
  const words=normalizeCategoryText(title).split(' ').filter(word=>word.length>1),rows=categories.filter(row=>Number.isInteger(Number(row.id))&&Number(row.id)>0&&(row.leaf!==false||!categories.some(other=>Number(other.parentId)===Number(row.id))));
  return rows.map((row,index)=>{const name=String(row.path||row.name),normalized=normalizeCategoryText(name),score=words.reduce((sum,word)=>sum+(normalized.includes(word)?word.length+2:0),0);return{row,index,name,score}}).sort((a,b)=>b.score-a.score||a.index-b.index).slice(0,500);
}
function normalizeCategoryText(value:string){return String(value||'').toLowerCase().replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200f\u200e]/g,' ').replace(/[^\p{L}\p{N}]+/gu,' ').replace(/\s+/g,' ').trim()}
function categoryPrompt(title:string,categories:AiCategoryOption[]){const ranked=categoryRows(title,categories),allowed:AiCategoryOption[]=[],lines:string[]=[];let length=0;for(const item of ranked){const line=`${item.row.id} | ${item.name}`;if(length+line.length+1>18_000)break;lines.push(line);allowed.push(item.row);length+=line.length+1}if(!lines.length)throw new Error('فهرست معتبر دسته‌بندی باسلام در دسترس نیست.');return{allowed,prompt:`برای محصول زیر فقط مناسب‌ترین شناسه دسته‌بندی باسلام را از فهرست مجاز انتخاب کن. شناسه باید دقیقاً یکی از اعداد فهرست باشد. اگر مدل استدلالی هستی، فکرکردن را داخلی انجام بده و در پاسخ نهایی هیچ عدد دیگری ننویس. پاسخ نهایی فقط JSON کوتاه {"category_id":123,"reason":"..."} باشد.\nمحصول: ${title}\nفهرست مجاز:\n${lines.join('\n')}`}}
function parseCategoryId(text:string,categories:AiCategoryOption[]){const source=String(text||''),valid=new Set(categories.map(row=>Number(row.id)));for(const candidate of [source,...[...source.matchAll(/```(?:json)?\s*([\s\S]*?)```/gi)].map(match=>match[1])])try{const parsed=JSON.parse(candidate.trim());const id=Number(parsed?.category_id??parsed?.categoryId??parsed?.id);if(valid.has(id))return id}catch{/* response can contain prose */}for(const match of source.matchAll(/["']?category_(?:id)?["']?\s*[:=]\s*["']?(\d+)/gi)){const id=Number(match[1]);if(valid.has(id))return id}const numbers=[...source.matchAll(/\d+/g)].map(match=>Number(match[0])).filter(id=>valid.has(id));return numbers.length?numbers.at(-1)!:0}
async function categoryWithTask(task:AiTestTask,title:string,categories:AiCategoryOption[],network:Network,timeoutMs?:number){
  if(!isChatCompatibleAiModel(task.p,task.model))throw new AiResponseError('این مدل endpoint اختصاصی دارد و برای گفت‌وگو یا دسته‌بندی کاندید نمی‌شود.',{ok:false,skipped:true,phase:'unsupported-task',provider:task.p.id,providerName:task.p.name,model:task.model,prompt:title,endpointType:aiModelEndpoint(task.p,task.model),chatCompatible:false,latencyMs:0,raw:{reason:'dedicated endpoint model'}});
  const prepared=categoryPrompt(title,categories),detail=await aiCall(task.p,task.model,prepared.prompt,network,timeoutMs),categoryId=parseCategoryId(detail.text,prepared.allowed),category=prepared.allowed.find(row=>Number(row.id)===categoryId);
  if(!category)throw new AiResponseError('مدل هیچ شناسهٔ معتبر از فهرست دسته‌بندی باسلام برنگرداند.',{...detail,ok:false,phase:'validation',categoryTitle:title,categoryId:0,allowedCategoryCount:prepared.allowed.length});
  return{...detail,categoryTitle:title,categoryId,categoryName:String(category.name),categoryPath:String(category.path||category.name),allowedCategoryCount:prepared.allowed.length};
}
export async function suggestCategoryWithModel(title:string,modelKey:string,categories:AiCategoryOption[]){
  const ai=(await loadConnections()).ai,providers=providersFromAi(ai),[providerId,...modelParts]=String(modelKey||'').split('::'),model=modelParts.join('::'),provider=providers.find(item=>item.id===providerId&&item.enabled!==false&&item.models.includes(model));
  if(!String(title||'').trim())throw new Error('عنوان محصول برای دسته‌بندی لازم است.');if(!provider||!model)throw new Error('مدل انتخاب‌شده در تنظیمات فعال هوش مصنوعی پیدا نشد.');
  const task={p:provider,model,key:`${provider.id}::${model}`,keyIndex:0,keyLabel:''};try{return{...await categoryWithTask(task,String(title).trim(),categories,ai.network),key:task.key}}catch(error){return aiTestFailure(error,task,String(title).trim())}
}

/** One model per provider per invocation keeps each provider at 1 in-flight request (avoids rate limits) while finishing the list faster. */
export const AI_TEST_MAX_PARALLEL=10;
const AI_TEST_MODELS_PER_INVOCATION=AI_TEST_MAX_PARALLEL;
type AiTestTask={p:Provider;model:string;key:string;keyIndex:number;keyLabel:string};
type StoredAiTest={runId:string;startedAt:string;updatedAt:string;prompt:string;categoryTitle:string;onlyCandidates:boolean;total:number;results:any[]};
type AiTestOptions={onlyCandidates?:boolean;cursor?:number;runId?:string;categoryTitle?:string;categories?:AiCategoryOption[];skipCurrent?:boolean;skipReason?:string;timeoutMs?:number;retryKey?:string;retryKeys?:string[];retryPart?:'message'|'category'|'both'};
export function aiTestProviderId(key:string){return String(key||'').split('::')[0]}
export function nextAiTestBatch<T>(items:T[],cursor:number,providerOf:(item:T)=>string,max=AI_TEST_MAX_PARALLEL):{batch:T[];nextCursor:number}{
  const batch:T[]=[];const seen=new Set<string>();
  for(let i=Math.max(0,cursor);i<items.length;i++){
    const id=providerOf(items[i]);
    if(seen.has(id)||batch.length>=max)break;
    seen.add(id);batch.push(items[i]);
  }
  return{batch,nextCursor:cursor+batch.length};
}
export function isCreditAiResult(row:any):boolean{
  const blob=[row?.error,row?.phase,row?.httpStatus,row?.raw?.error,row?.raw?.code,row?.raw?.message].map(x=>String(x||'')).join(' ');
  return isCreditAiText(blob)||Number(row?.httpStatus)===402;
}
export function isRetryableAiResult(row:any):boolean{
  if(!row||row.ok||isCreditAiResult(row))return false;
  if(row.skipped||row.phase==='transport-skip'||row.phase==='batch-pending'||row.retryable)return true;
  return /مهلت دریافت|timeout|AbortError|گیر کردن|خودکار رد|batch api هنوز|\b429\b|rate.?limit|too many requests|overloaded|temporarily unavailable|security policy|access denied by security policy/i.test([row.error,row.phase,row.catResponse,row.httpStatus].map(x=>String(x||'')).join(' '));
}
function aiTestTasks(ai:any,providers:Provider[],onlyCandidates:boolean):AiTestTask[]{
  const wanted=new Set<string>(Array.isArray(ai.candidates)?ai.candidates.map(String):[]),columns:AiTestTask[][]=[];
  for(const p of providers){
    if(p.enabled===false)continue;
    const column:AiTestTask[]=[];
    const keyCount=Math.max(1,providerKeys(p).length);
    for(const rawModel of p.models||[]){
      const model=String(rawModel||'').trim();if(!model)continue;
      const primaryKey=`${p.id}::${model}`;
      if(onlyCandidates&&(!wanted.has(primaryKey)||!isChatCompatibleAiModel(p,model)))continue;
      // One task per API key; models of the 2nd+ keys get a visible suffix.
      for(let ki=0;ki<keyCount;ki++){
        const key=ki===0?primaryKey:`${p.id}::${model}::k${ki+1}`;
        column.push({p:providerWithKey(p,ki),model,key,keyIndex:ki,keyLabel:aiKeySuffixLabel(ki)});
      }
    }
    if(column.length)columns.push(column);
  }
  const tasks:AiTestTask[]=[],max=columns.reduce((n,column)=>Math.max(n,column.length),0);
  for(let i=0;i<max;i++)for(const column of columns)if(column[i])tasks.push(column[i]);
  return tasks;
}
function aiTestFailure(error:unknown,task:AiTestTask,prompt:string){return error instanceof AiResponseError?{...error.detail,key:task.key,keyIndex:task.keyIndex,keyLabel:task.keyLabel}:{ok:false,phase:'unknown',key:task.key,keyIndex:task.keyIndex,keyLabel:task.keyLabel,provider:task.p.id,providerName:task.p.name,model:task.model,prompt,latencyMs:0,error:safeError(error instanceof Error?error.message:String(error),'',task.p.apiKey),raw:{error:safeError(error instanceof Error?error.message:String(error),'',task.p.apiKey)}}}

function aiTestResponse(saved:StoredAiTest,tasks:AiTestTask[],cursor:number,nextCursor:number,batchResults:any[],replayed=false){
  const results=saved.results,done=nextCursor>=tasks.length,messageSucceeded=results.filter(x=>x.ok).length,messageFailed=results.filter(x=>!x.ok).length,categoryAttempted=Boolean(saved.categoryTitle),categorySucceeded=categoryAttempted?results.filter(x=>x.categoryResult?.ok).length:0,categorySkipped=categoryAttempted?results.filter(x=>x.categoryResult?.skipped).length:0,categoryFailed=categoryAttempted?results.filter(x=>x.categoryResult&&!x.categoryResult.ok&&!x.categoryResult.skipped).length:0,skipped=results.filter(x=>x.skipped||x.phase==='transport-skip').length;
  return{ok:done&&messageSucceeded>0,runId:saved.runId,startedAt:saved.startedAt,updatedAt:saved.updatedAt,prompt:saved.prompt,categoryTitle:saved.categoryTitle,total:tasks.length,cursor,nextCursor,done,batchSize:batchResults.length,maxModelsPerInvocation:AI_TEST_MODELS_PER_INVOCATION,succeeded:messageSucceeded,failed:messageFailed,messageSucceeded,messageFailed,categorySucceeded,categoryFailed,categorySkipped,skipped,replayed,results,batchResults};
}
function skippedAiTestResult(task:AiTestTask,prompt:string,categoryTitle:string,reason:string){
  const error=String(reason||'پس از چند تلاش، پاسخ این نوبت از Worker دریافت نشد.');
  return{ok:false,skipped:true,retryable:true,phase:'transport-skip',key:task.key,keyIndex:task.keyIndex,keyLabel:task.keyLabel,provider:task.p.id,providerName:task.p.name,model:task.model,prompt,latencyMs:0,error,raw:{error},categoryTitle,categoryResult:categoryTitle?{ok:false,skipped:true,phase:'transport-skip',key:task.key,keyIndex:task.keyIndex,keyLabel:task.keyLabel,provider:task.p.id,providerName:task.p.name,model:task.model,prompt:categoryTitle,latencyMs:0,error,raw:{error}}:null,catResponse:categoryTitle?error:''};
}
async function executeAiTestTask(task:AiTestTask,prompt:string,categoryTitle:string,categories:AiCategoryOption[],network:Network,timeoutMs?:number,skipCurrent=false,skipReason='',batchId=''){
  if(skipCurrent)return skippedAiTestResult(task,prompt,categoryTitle,skipReason);
  let message:any;try{message={...await aiCall(task.p,task.model,prompt,network,timeoutMs,batchId),key:task.key}}catch(error){message=aiTestFailure(error,task,prompt)}
  let categoryResult:any=null;
  if(categoryTitle&&!message.ok)categoryResult={ok:false,skipped:true,phase:'message-failed',key:task.key,provider:task.p.id,providerName:task.p.name,model:task.model,prompt:categoryTitle,latencyMs:0,error:'چون پاسخ پیام ناموفق بود، تست دسته‌بندی این مدل رد شد تا صف گیر نکند.',raw:{reason:'message-failed'}};
  else if(categoryTitle&&!isChatCompatibleAiModel(task.p,task.model))categoryResult={ok:false,skipped:true,phase:'unsupported-task',key:task.key,provider:task.p.id,providerName:task.p.name,model:task.model,prompt:categoryTitle,endpointType:aiModelEndpoint(task.p,task.model),chatCompatible:false,latencyMs:0,error:'این مدل endpoint اختصاصی دارد؛ تست خود مدل انجام شد اما برای دسته‌بندی گفت‌وگویی مناسب نیست.',raw:{reason:'dedicated endpoint model'}};
  else if(categoryTitle&&categories.length)try{categoryResult={...await categoryWithTask(task,categoryTitle,categories,network,timeoutMs),key:task.key}}catch(error){categoryResult=aiTestFailure(error,task,categoryTitle)}
  const row:any={...message,categoryTitle,categoryResult,keyIndex:task.keyIndex,keyLabel:task.keyLabel,catResponse:categoryResult?.ok?`${categoryResult.categoryName} (#${categoryResult.categoryId})`:categoryResult?.error||(!categories.length?'فهرست دسته‌بندی در دسترس نیست':'')};
  row.retryable=isRetryableAiResult(row);return row;
}
function categoryResponseText(categoryResult:any,categories:AiCategoryOption[]){return categoryResult?.ok?`${categoryResult.categoryName} (#${categoryResult.categoryId})`:categoryResult?.error||(!categories.length?'فهرست دسته‌بندی در دسترس نیست':'')}
async function executeAiTestPart(task:AiTestTask,prompt:string,categoryTitle:string,categories:AiCategoryOption[],network:Network,timeoutMs:number|undefined,part:'message'|'category',previousRow:any){
  if(part==='message'){
    let message:any;try{message={...await aiCall(task.p,task.model,prompt,network,timeoutMs,String(previousRow?.batchId||'')),key:task.key}}catch(error){message=aiTestFailure(error,task,prompt)}
    const row:any={...previousRow,...message,key:task.key,keyIndex:task.keyIndex,keyLabel:task.keyLabel,categoryTitle,categoryResult:previousRow?.categoryResult??null,catResponse:previousRow?.catResponse||'',messageRetryCount:Number(previousRow?.messageRetryCount||0)+1,retryCount:Number(previousRow?.retryCount||0)+1};
    row.retryable=isRetryableAiResult(row);return row;
  }
  let categoryResult:any=null;
  if(!categoryTitle)categoryResult={ok:false,phase:'configuration',key:task.key,provider:task.p.id,providerName:task.p.name,model:task.model,prompt:'',latencyMs:0,error:'عنوان دسته برای تست تنظیم نشده است.',raw:{reason:'missing-category-title'}};
  else if(!isChatCompatibleAiModel(task.p,task.model))categoryResult={ok:false,skipped:true,phase:'unsupported-task',key:task.key,provider:task.p.id,providerName:task.p.name,model:task.model,prompt:categoryTitle,endpointType:aiModelEndpoint(task.p,task.model),chatCompatible:false,latencyMs:0,error:'این مدل endpoint اختصاصی دارد و برای دسته‌بندی گفت‌وگویی مناسب نیست.',raw:{reason:'dedicated endpoint model'}};
  else if(!categories.length)categoryResult={ok:false,phase:'configuration',key:task.key,provider:task.p.id,providerName:task.p.name,model:task.model,prompt:categoryTitle,latencyMs:0,error:'فهرست دسته‌بندی در دسترس نیست',raw:{reason:'no-categories'}};
  else try{categoryResult={...await categoryWithTask(task,categoryTitle,categories,network,timeoutMs),key:task.key}}catch(error){categoryResult=aiTestFailure(error,task,categoryTitle)}
  const row:any={...previousRow,key:task.key,keyIndex:task.keyIndex,keyLabel:task.keyLabel,categoryTitle,categoryResult,catResponse:categoryResponseText(categoryResult,categories),categoryRetryCount:Number(previousRow?.categoryRetryCount||0)+1,retryCount:Number(previousRow?.retryCount||0)+1};
  row.retryable=isRetryableAiResult(row);return row;
}
async function executeAiTestRound(batch:AiTestTask[],prompt:string,categoryTitle:string,categories:AiCategoryOption[],network:Network,timeoutMs:number|undefined,skipCurrent:boolean,skipReason:string,results:any[]){
  if(!batch.length)return [];
  if(skipCurrent)return batch.map(task=>skippedAiTestResult(task,prompt,categoryTitle,skipReason));
  return Promise.all(batch.map(task=>executeAiTestTask(task,prompt,categoryTitle,categories,network,timeoutMs,false,'',String(results.find(item=>item.key===task.key)?.batchId||''))));
}
/**
 * Runs one model-index round across providers per Worker invocation (first of
 * every provider, then second of every provider, …). Parallel calls never hit
 * the same provider twice, which finishes the list faster and avoids rate
 * limits. Results are persisted before responding and a repeated runId/cursor
 * replays the stored rows.
 */
export async function testModelBatch(prompt='سلام',options:AiTestOptions={}){
  const ai=(await loadConnections()).ai,providers=providersFromAi(ai),onlyCandidates=Boolean(options.onlyCandidates),tasks=aiTestTasks(ai,providers,onlyCandidates),cursor=Math.max(0,Math.trunc(Number(options.cursor)||0)),categoryTitle=String(options.categoryTitle||'').trim(),categories=Array.isArray(options.categories)?options.categories:[],requestedRunId=String(options.runId||'').trim();
  const previous=requestedRunId||cursor>0?await getState<StoredAiTest|null>('ai_test_results',null):null,runId=requestedRunId||crypto.randomUUID(),sameRun=Boolean(previous?.runId&&previous.runId===runId&&previous.prompt===prompt&&previous.categoryTitle===categoryTitle&&previous.onlyCandidates===onlyCandidates),startedAt=sameRun&&previous?.startedAt?previous.startedAt:new Date().toISOString();
  if(cursor>0&&!sameRun)throw new Error('نوبت آزمایش مدل‌ها منقضی یا تغییر داده شده است؛ آزمایش را از ابتدا اجرا کنید.');
  const results:any[]=sameRun&&Array.isArray(previous?.results)?[...previous.results]:[];
  if(cursor>results.length)throw new Error('ترتیب ادامهٔ آزمایش معتبر نیست؛ آخرین نتیجه را باز کنید و دوباره ادامه دهید.');
  const timeoutMs=Number(options.timeoutMs)>0?Number(options.timeoutMs):undefined,retryKeys=[...new Set([...(Array.isArray(options.retryKeys)?options.retryKeys:[]).map(String),String(options.retryKey||'')].map(value=>value.trim()).filter(Boolean))];
  if(retryKeys.length){
    if(!sameRun)throw new Error('نوبت آزمایش مدل‌ها منقضی یا تغییر داده شده است؛ آزمایش را از ابتدا اجرا کنید.');
    const retryTasks=retryKeys.map(key=>{const task=tasks.find(item=>item.key===key);if(!task)throw new Error('مدل برای تلاش مجدد پیدا نشد.');return task});
    const part=options.retryPart==='message'||options.retryPart==='category'?options.retryPart:'both';
    const batchResults:any[]=[];
    if(part!=='both'&&retryTasks.length===1){
      const task=retryTasks[0],existing=results.find(item=>item.key===task.key),row=await executeAiTestPart(task,prompt,categoryTitle,categories,ai.network,timeoutMs,part,existing||{});
      const index=results.findIndex(item=>item.key===task.key);if(index>=0)results[index]=row;else results.push(row);batchResults.push(row);
    }else{
      const rows=await executeAiTestRound(retryTasks,prompt,categoryTitle,categories,ai.network,timeoutMs,Boolean(options.skipCurrent),String(options.skipReason||''),results);
      for(const row of rows){const index=results.findIndex(item=>item.key===row.key);row.retryCount=Number((index>=0?results[index]:null)?.retryCount||0)+1;if(index>=0)results[index]=row;else results.push(row);batchResults.push(row)}
    }
    const updatedAt=new Date().toISOString(),saved:StoredAiTest={runId,startedAt,updatedAt,prompt,categoryTitle,onlyCandidates,total:tasks.length,results};await setState('ai_test_results',saved);
    return aiTestResponse(saved,tasks,cursor,Math.max(cursor,results.length),batchResults,false);
  }
  const round=nextAiTestBatch(tasks,cursor,task=>task.p.id);
  if(sameRun&&cursor<results.length){const nextCursor=Math.min(tasks.length,Math.max(round.nextCursor,cursor+1));const batchResults=results.slice(cursor,nextCursor),saved={...previous!,total:tasks.length,results};return aiTestResponse(saved,tasks,cursor,nextCursor,batchResults,true)}
  const batch=round.batch,batchResults=await executeAiTestRound(batch,prompt,categoryTitle,categories,ai.network,timeoutMs,Boolean(options.skipCurrent),String(options.skipReason||''),results);
  const latestSaved=await getState<StoredAiTest|null>('ai_test_results',null);
  if(latestSaved?.runId===runId&&Array.isArray(latestSaved.results)&&latestSaved.results.length>cursor){const nextCursor=Math.min(tasks.length,latestSaved.results.length);return aiTestResponse(latestSaved,tasks,cursor,nextCursor,latestSaved.results.slice(cursor,Math.min(latestSaved.results.length,round.nextCursor)),true)}
  results.push(...batchResults);const nextCursor=Math.min(tasks.length,cursor+batch.length),updatedAt=new Date().toISOString(),saved:StoredAiTest={runId,startedAt,updatedAt,prompt,categoryTitle,onlyCandidates,total:tasks.length,results};await setState('ai_test_results',saved);
  return aiTestResponse(saved,tasks,cursor,nextCursor,batchResults,false);
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
async function networkFetch(url:string,init:RequestInit,net:Network,timeoutMs?:number):Promise<Response>{assertPublicUrl(url);if(net.mode==='direct'||!net.mode)return safeFetch(url,init,3_000_000,timeoutMs);if(net.workerUrl){const target=net.workerUrl.includes('{url}')?net.workerUrl.replace('{url}',encodeURIComponent(url)):net.workerUrl+(net.workerUrl.includes('?')?'&':'?')+'url='+encodeURIComponent(url);return safeFetch(target,{...init,headers:{...init.headers,'x-scraper-target':url}},3_000_000,timeoutMs)}throw new Error(`حالت شبکه «${net.mode}» در Workers به Worker/Gateway واسط نیاز دارد؛ workerUrl را تنظیم کنید.`)}
