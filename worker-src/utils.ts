export const textEncoder = new TextEncoder();
export const textDecoder = new TextDecoder();

export function bytesToBase64(bytes: Uint8Array): string {
  let out=''; for(let i=0;i<bytes.length;i+=0x8000) out+=String.fromCharCode(...bytes.subarray(i,i+0x8000)); return btoa(out);
}
export function base64ToBytes(value:string):Uint8Array { const raw=atob(value),bytes=new Uint8Array(raw.length);for(let i=0;i<raw.length;i++)bytes[i]=raw.charCodeAt(i);return bytes; }
export function utf8ToBase64(value:string):string{return bytesToBase64(textEncoder.encode(value));}
export function base64ToUtf8(value:string):string{return textDecoder.decode(base64ToBytes(value));}
export function basicAuth(username:string,password:string):string{return `Basic ${utf8ToBase64(`${username}:${password}`)}`;}
export function byteLength(value:string):number{return textEncoder.encode(value).byteLength;}
export function escapeHtml(value:unknown):string{return String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]!));}
export function message(error:unknown):string{return error instanceof Error?error.message:String(error);}
export async function sha256(value:string):Promise<string>{const bytes=new Uint8Array(await crypto.subtle.digest('SHA-256',textEncoder.encode(value)));return [...bytes].map(x=>x.toString(16).padStart(2,'0')).join('');}
export function safeEqual(a:string,b:string):boolean {const length=Math.max(a.length,b.length),aa=a.padEnd(length,'\0'),bb=b.padEnd(length,'\0');let diff=a.length^b.length;for(let i=0;i<length;i++)diff|=aa.charCodeAt(i)^bb.charCodeAt(i);return diff===0;}
