import { getEnv } from './env.js';
import { loadConnections } from './connections.js';

export function privateIp(value:string):boolean {
  const ip=value.replace(/^\[|\]$/g,'').toLowerCase();
  if(/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip)){const p=ip.split('.').map(Number);if(p.some(x=>x<0||x>255))return true;const[a,b]=p;return a===0||a===10||a===127||a>=224||(a===169&&b===254)||(a===172&&b>=16&&b<=31)||(a===192&&b===168)||(a===100&&b>=64&&b<=127);}
  if(ip.includes(':'))return ip==='::'||ip==='::1'||ip.startsWith('fc')||ip.startsWith('fd')||ip.startsWith('fe8')||ip.startsWith('fe9')||ip.startsWith('fea')||ip.startsWith('feb')||ip.startsWith('::ffff:127.')||ip.startsWith('::ffff:10.')||ip.startsWith('::ffff:192.168.');
  return false;
}
export function assertPublicUrl(raw:string):URL {
  const url=new URL(raw);if(!['http:','https:'].includes(url.protocol))throw new Error('Only HTTP/HTTPS URLs are allowed');if(url.username||url.password)throw new Error('Credentials in URLs are not allowed');
  const host=url.hostname.toLowerCase().replace(/\.$/,'');if(!host||host==='localhost'||host.endsWith('.localhost')||host.endsWith('.local')||host.endsWith('.internal')||host.endsWith('.home')||privateIp(host))throw new Error('Private hosts are not allowed');return url;
}
async function limitedBody(response:Response,maxBytes:number):Promise<Uint8Array>{
  const declared=Number(response.headers.get('content-length')||0);if(declared>maxBytes){await response.body?.cancel();throw new Error(`Response exceeds ${maxBytes} bytes`)}if(!response.body)return new Uint8Array();
  const reader=response.body.getReader(),chunks:Uint8Array[]=[];let total=0;try{while(true){const{done,value}=await reader.read();if(done)break;if(value){total+=value.byteLength;if(total>maxBytes){await reader.cancel();throw new Error(`Response exceeds ${maxBytes} bytes`)}chunks.push(value)}}}finally{reader.releaseLock()}
  const out=new Uint8Array(total);let offset=0;for(const chunk of chunks){out.set(chunk,offset);offset+=chunk.byteLength}return out;
}
function redirectedInit(init:RequestInit,from:URL,to:URL,status:number):RequestInit{
  let next={...init};
  if(from.origin!==to.origin){const headers=new Headers(next.headers);for(const name of ['authorization','proxy-authorization','cookie'])headers.delete(name);next={...next,headers}}
  if(status===303||((status===301||status===302)&&String(next.method||'GET').toUpperCase()==='POST'))next={...next,method:'GET',body:undefined};
  return next;
}
export async function safeFetch(raw:string,init:RequestInit={},maxBytes?:number):Promise<Response>{
  let url=assertPublicUrl(raw),requestInit={...init};const env=getEnv(),limit=Math.min(25_000_000,Math.max(1000,maxBytes||Number(env.MAX_RESPONSE_BYTES)||8_000_000));
  for(let redirects=0;redirects<5;redirects++){
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort('timeout'),Math.max(1000,Number(env.REQUEST_TIMEOUT_MS)||25_000));
    try{
      const requestHeaders=new Headers({'user-agent':'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',accept:'text/html,application/xhtml+xml,application/json;q=0.9,application/xml;q=0.8,*/*;q=0.5','accept-language':'fa-IR,fa;q=0.9,en-US;q=0.7,en;q=0.6','cache-control':'no-cache'});new Headers(requestInit.headers).forEach((value,name)=>requestHeaders.set(name,value));
      const response=await fetch(url.href,{...requestInit,redirect:'manual',signal:controller.signal,headers:requestHeaders});
      if([301,302,303,307,308].includes(response.status)){
        const location=response.headers.get('location');await response.body?.cancel();if(!location)throw new Error('Redirect without location');const nextUrl=assertPublicUrl(new URL(location,url).href);requestInit=redirectedInit(requestInit,url,nextUrl,response.status);url=nextUrl;continue;
      }
      const body=await limitedBody(response,limit),headers=new Headers(response.headers);headers.set('x-scraper-final-url',url.href);return new Response(Uint8Array.from(body).buffer,{status:response.status,statusText:response.statusText,headers});
    }catch(error){if(controller.signal.aborted)throw new Error(`مهلت دریافت ${url.href} تمام شد.`);throw error}finally{clearTimeout(timeout)}
  }
  throw new Error('Too many redirects');
}
function asciiPrefix(bytes:Uint8Array,limit=8192):string{let value='';for(let i=0;i<Math.min(limit,bytes.length);i++)value+=String.fromCharCode(bytes[i]);return value}
function responseEncoding(bytes:Uint8Array,contentType:string):string{
  if(bytes[0]===0xef&&bytes[1]===0xbb&&bytes[2]===0xbf)return 'utf-8';if(bytes[0]===0xff&&bytes[1]===0xfe)return 'utf-16le';if(bytes[0]===0xfe&&bytes[1]===0xff)return 'utf-16be';
  const header=contentType.match(/charset\s*=\s*["']?([^\s;"']+)/i)?.[1];if(header)return header.toLowerCase();
  const prefix=asciiPrefix(bytes),meta=prefix.match(/<meta\b[^>]*charset\s*=\s*["']?([^\s;"'/>]+)/i)?.[1]||prefix.match(/<meta\b[^>]*content\s*=\s*["'][^"']*charset\s*=\s*([^\s;"']+)/i)?.[1];return (meta||'utf-8').toLowerCase();
}
export function decodeResponseBody(bytes:Uint8Array,contentType=''):string{
  const label=responseEncoding(bytes,contentType);try{return new TextDecoder(label,{fatal:false}).decode(bytes)}catch{return new TextDecoder('utf-8',{fatal:false}).decode(bytes)}
}
function ensureTextResponse(text:string,contentType:string,url:string):void{
  if(contentType&&!/(?:text\/|json|xml|xhtml|javascript|octet-stream)/i.test(contentType))throw new Error(`نوع پاسخ مبدأ برای استخراج مناسب نیست (${contentType}).`);
  const sample=text.slice(0,200_000);if(/(?:cf-chl-|challenge-platform|cdn-cgi\/challenge-platform|g-recaptcha|hcaptcha)/i.test(sample)||/<title[^>]*>\s*(?:Just a moment|Attention Required|Access denied)/i.test(sample))throw new Error(`صفحهٔ ضدربات/چالش به‌جای محتوای محصول از ${url} دریافت شد. روش اتصال غیرمستقیم را بررسی کنید.`);
}
async function responseText(response:Response,url:string):Promise<{text:string;url:string;contentType:string}>{
  if(!response.ok)throw new Error(`HTTP ${response.status} from ${url}`);const contentType=response.headers.get('content-type')||'',bytes=new Uint8Array(await response.arrayBuffer()),text=decodeResponseBody(bytes,contentType),finalUrl=response.headers.get('x-scraper-final-url')||url;ensureTextResponse(text,contentType,finalUrl);return{text,url:finalUrl,contentType};
}
export async function safeText(raw:string,maxBytes=8_000_000):Promise<{text:string;url:string;contentType:string}>{return responseText(await safeFetch(raw,{},maxBytes),raw)}
export async function safeTextViaWorker(raw:string,workerUrl:string,maxBytes=8_000_000):Promise<{text:string;url:string;contentType:string}>{
  const target=assertPublicUrl(raw).href,base=workerUrl.trim();if(!base)throw new Error('برای اتصال غیرمستقیم، Worker URL را در تنظیمات روش اتصال وارد کنید.');const gateway=base.includes('{url}')?base.replace('{url}',encodeURIComponent(target)):base.replace(/\/$/,'')+'/'+target.replace(/^\//,'');const response=await safeFetch(gateway,{headers:{'x-target-url':target,accept:'text/html,application/xhtml+xml'}},maxBytes),result=await responseText(response,target);return {...result,url:target};
}

const WOO_EDGE_ERRORS=new Set([520,521,522,523,524,525,526]);
function wooGatewayUrl(target:string,workerUrl:string):string{const base=workerUrl.trim();if(!base)throw new Error('آدرس Worker جایگزین ووکامرس وارد نشده است.');return base.includes('{url}')?base.replace('{url}',encodeURIComponent(target)):base.replace(/\/$/,'')+'/'+target.replace(/^\//,'')}
async function tagNetwork(response:Response,mode:'direct'|'worker',fallbackStatus=0):Promise<Response>{const headers=new Headers(response.headers);headers.set('x-scraper-network-mode',mode);if(fallbackStatus)headers.set('x-scraper-direct-status',String(fallbackStatus));return new Response(await response.arrayBuffer(),{status:response.status,statusText:response.statusText,headers})}
async function workerFetch(target:string,workerUrl:string,init:RequestInit,maxBytes:number|undefined,fallbackStatus=0):Promise<Response>{const headers=new Headers(init.headers);headers.set('x-target-url',target);headers.set('x-scraper-target-url',target);return tagNetwork(await safeFetch(wooGatewayUrl(target,workerUrl),{...init,headers},maxBytes),'worker',fallbackStatus)}
/**
 * WooCommerce-aware request path. In automatic mode a Cloudflare 52x origin
 * failure is retried once through the configured reverse Worker. The original
 * method/body/authentication are preserved and all normal safeFetch limits still apply.
 */
export async function safeWooFetch(raw:string,init:RequestInit={},maxBytes?:number):Promise<Response>{
  const target=assertPublicUrl(raw).href,connections=await loadConnections(),config=connections.woo.network||{mode:'auto',workerUrl:''},workerUrl=config.workerUrl||'';
  if(config.mode==='worker')return workerFetch(target,workerUrl,init,maxBytes);
  try{
    const direct=await safeFetch(target,init,maxBytes);
    if(config.mode==='auto'&&workerUrl&&WOO_EDGE_ERRORS.has(direct.status)){const status=direct.status;await direct.body?.cancel();return workerFetch(target,workerUrl,init,maxBytes,status)}
    return tagNetwork(direct,'direct');
  }catch(error){if(config.mode==='auto'&&workerUrl)return workerFetch(target,workerUrl,init,maxBytes);throw error}
}
