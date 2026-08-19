import { getEnv } from './env.js';
import { textDecoder } from './utils.js';

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
  const declared=Number(response.headers.get('content-length')||0);if(declared>maxBytes)throw new Error(`Response exceeds ${maxBytes} bytes`);if(!response.body)return new Uint8Array();
  const reader=response.body.getReader(),chunks:Uint8Array[]=[];let total=0;try{while(true){const{done,value}=await reader.read();if(done)break;if(value){total+=value.byteLength;if(total>maxBytes){await reader.cancel();throw new Error(`Response exceeds ${maxBytes} bytes`)}chunks.push(value)}}}finally{reader.releaseLock()}
  const out=new Uint8Array(total);let offset=0;for(const chunk of chunks){out.set(chunk,offset);offset+=chunk.byteLength}return out;
}
export async function safeFetch(raw:string,init:RequestInit={},maxBytes?:number):Promise<Response>{
  let url=assertPublicUrl(raw),requestInit={...init};const env=getEnv(),limit=Math.min(25_000_000,Math.max(1000,maxBytes||Number(env.MAX_RESPONSE_BYTES)||8_000_000));
  for(let redirects=0;redirects<5;redirects++){
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort('timeout'),Math.max(1000,Number(env.REQUEST_TIMEOUT_MS)||25_000));let response:Response;
    try{response=await fetch(url.href,{...requestInit,redirect:'manual',signal:controller.signal,headers:{'user-agent':'Scraper4-Cloudflare/1.0',accept:'text/html,application/json;q=0.9,*/*;q=0.8',...requestInit.headers}})}finally{clearTimeout(timeout)}
    if([301,302,303,307,308].includes(response.status)){const location=response.headers.get('location');await response.body?.cancel();if(!location)throw new Error('Redirect without location');url=assertPublicUrl(new URL(location,url).href);if(response.status===303){requestInit={...requestInit,method:'GET',body:undefined}}continue;}
    const body=await limitedBody(response,limit),headers=new Headers(response.headers);headers.set('x-scraper-final-url',url.href);return new Response(Uint8Array.from(body).buffer,{status:response.status,statusText:response.statusText,headers});
  }
  throw new Error('Too many redirects');
}
export async function safeText(raw:string,maxBytes=8_000_000):Promise<{text:string;url:string;contentType:string}>{const response=await safeFetch(raw,{},maxBytes);if(!response.ok)throw new Error(`HTTP ${response.status} from ${raw}`);return{text:textDecoder.decode(await response.arrayBuffer()),url:response.headers.get('x-scraper-final-url')||raw,contentType:response.headers.get('content-type')||''};}
