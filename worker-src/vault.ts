import { getEnv, MIN_SECRET_LENGTH, validSecret } from './env.js';
import { base64ToBytes, bytesToBase64, textDecoder, textEncoder } from './utils.js';

export type ConnectionVault={
  woo:{url:string;key:string;secret:string;categoryId:number};
  basalam:{token:string;vendorId:string;api:string;preparationDays:number;weight:number;packageWeight:number;stock:number;categoryId:number;autoCategory:boolean;netIndirect:boolean;shops:Array<{name:string;token:string;vendorId:string;pricePercent:number}>};
  ai:{baseUrl:string;apiKey:string;model:string;providers:Array<{id:string;name:string;baseUrl:string;apiKey:string;models:string[];enabled:boolean}>;candidates:string[];master:string;network:{mode:string;proxyUrl:string;workerUrl:string;dohUrl:string;resolveIp:string}};
  notifications:{url:string;token:string;chatId:string;baleToken:string;baleChatId:string;rubikaToken:string;rubikaChatId:string};
};
type Envelope={version:2;salt:string;iv:string;ciphertext:string};

export const emptyConnections=():ConnectionVault=>({
  woo:{url:'',key:'',secret:'',categoryId:0},
  basalam:{token:'',vendorId:'',api:'https://openapi.basalam.com/v1',preparationDays:3,weight:500,packageWeight:600,stock:10,categoryId:0,autoCategory:false,netIndirect:false,shops:[]},
  ai:{baseUrl:'',apiKey:'',model:'',providers:[],candidates:[],master:'',network:{mode:'direct',proxyUrl:'',workerUrl:'',dohUrl:'https://cloudflare-dns.com/dns-query',resolveIp:''}},
  notifications:{url:'',token:'',chatId:'',baleToken:'',baleChatId:'',rubikaToken:'',rubikaChatId:''}
});
function password():string{const env=getEnv(),secret=env.VAULT_SECRET||env.ADMIN_TOKEN;if(!validSecret(secret))throw new Error(`برای ذخیره امن اطلاعات اتصال، VAULT_SECRET و ADMIN_TOKEN را با حداقل ${MIN_SECRET_LENGTH} کاراکتر در Cloudflare Dashboard ← Settings ← Variables and Secrets به‌صورت Secret تعریف کنید.`);return secret;}
const source=(value:Uint8Array):ArrayBuffer=>Uint8Array.from(value).buffer;
async function key(salt:Uint8Array,usage:KeyUsage[]):Promise<CryptoKey>{const material=await crypto.subtle.importKey('raw',textEncoder.encode(password()),'PBKDF2',false,['deriveKey']);return crypto.subtle.deriveKey({name:'PBKDF2',hash:'SHA-256',salt:source(salt),iterations:120_000},material,{name:'AES-GCM',length:256},false,usage);}
export async function encryptVault(value:ConnectionVault):Promise<Envelope>{const salt=crypto.getRandomValues(new Uint8Array(16)),iv=crypto.getRandomValues(new Uint8Array(12)),ciphertext=await crypto.subtle.encrypt({name:'AES-GCM',iv},await key(salt,['encrypt']),textEncoder.encode(JSON.stringify(value)));return{version:2,salt:bytesToBase64(salt),iv:bytesToBase64(iv),ciphertext:bytesToBase64(new Uint8Array(ciphertext))};}
export async function decryptVault(raw:unknown):Promise<ConnectionVault>{if(!raw)return environmentFallback();const envelope=raw as Envelope;if(envelope.version!==2)throw new Error('نسخه خزانه با Worker سازگار نیست؛ اتصال‌ها را دوباره وارد کنید.');try{const salt=base64ToBytes(envelope.salt),iv=base64ToBytes(envelope.iv),plain=await crypto.subtle.decrypt({name:'AES-GCM',iv:source(iv)},await key(salt,['decrypt']),source(base64ToBytes(envelope.ciphertext)));return mergeConnections(emptyConnections(),JSON.parse(textDecoder.decode(plain)))}catch{throw new Error('بازکردن اطلاعات اتصال ممکن نشد؛ آیا VAULT_SECRET (یا ADMIN_TOKEN در حالت fallback) تغییر کرده است؟')}}
export function environmentFallback():ConnectionVault{const env=getEnv(),result=emptyConnections();result.woo={...result.woo,url:env.WOO_URL||'',key:env.WOO_KEY||'',secret:env.WOO_SECRET||''};result.basalam={...result.basalam,token:env.BASALAM_TOKEN||'',vendorId:env.BASALAM_VENDOR_ID||'',api:env.BASALAM_API||result.basalam.api};return result;}
export function mergeConnections(base:ConnectionVault,input:any):ConnectionVault{
  const text=(value:unknown,fallback='')=>typeof value==='string'?value.trim():fallback,num=(value:unknown,fallback=0)=>value!==undefined&&Number.isFinite(Number(value))?Number(value):fallback,bool=(value:unknown,fallback=false)=>typeof value==='boolean'?value:fallback;
  const shops=Array.isArray(input?.basalam?.shops)?input.basalam.shops.map((shop:any)=>({name:text(shop?.name),token:text(shop?.token),vendorId:text(shop?.vendorId),pricePercent:num(shop?.pricePercent)})):base.basalam.shops;
  const providers=Array.isArray(input?.ai?.providers)?input.ai.providers.map((p:any,i:number)=>({id:text(p?.id)||`provider-${i+1}`,name:text(p?.name)||text(p?.id)||`Provider ${i+1}`,baseUrl:text(p?.baseUrl||p?.base_url).replace(/\/$/,''),apiKey:text(p?.apiKey||p?.api_key),models:Array.isArray(p?.models)?p.models.map(String):[],enabled:p?.enabled!==false})):base.ai.providers;
  const network={...base.ai.network,...(input?.ai?.network||{})};return{
    woo:{url:text(input?.woo?.url,base.woo.url).replace(/\/$/,''),key:text(input?.woo?.key,base.woo.key),secret:text(input?.woo?.secret,base.woo.secret),categoryId:num(input?.woo?.categoryId,base.woo.categoryId)},
    basalam:{token:text(input?.basalam?.token,base.basalam.token),vendorId:text(input?.basalam?.vendorId,base.basalam.vendorId),api:text(input?.basalam?.api,base.basalam.api).replace(/\/$/,'')||'https://openapi.basalam.com/v1',preparationDays:num(input?.basalam?.preparationDays,base.basalam.preparationDays),weight:num(input?.basalam?.weight,base.basalam.weight),packageWeight:num(input?.basalam?.packageWeight,base.basalam.packageWeight),stock:num(input?.basalam?.stock,base.basalam.stock),categoryId:num(input?.basalam?.categoryId,base.basalam.categoryId),autoCategory:bool(input?.basalam?.autoCategory,base.basalam.autoCategory),netIndirect:bool(input?.basalam?.netIndirect,base.basalam.netIndirect),shops},
    ai:{baseUrl:text(input?.ai?.baseUrl,base.ai.baseUrl).replace(/\/$/,''),apiKey:text(input?.ai?.apiKey,base.ai.apiKey),model:text(input?.ai?.model,base.ai.model),providers,candidates:Array.isArray(input?.ai?.candidates)?input.ai.candidates.map(String):base.ai.candidates,master:text(input?.ai?.master,base.ai.master),network:{mode:text(network.mode,'direct'),proxyUrl:text(network.proxyUrl),workerUrl:text(network.workerUrl),dohUrl:text(network.dohUrl,'https://cloudflare-dns.com/dns-query'),resolveIp:text(network.resolveIp)}},
    notifications:{url:text(input?.notifications?.url,base.notifications.url),token:text(input?.notifications?.token,base.notifications.token),chatId:text(input?.notifications?.chatId,base.notifications.chatId),baleToken:text(input?.notifications?.baleToken,base.notifications.baleToken),baleChatId:text(input?.notifications?.baleChatId,base.notifications.baleChatId),rubikaToken:text(input?.notifications?.rubikaToken,base.notifications.rubikaToken),rubikaChatId:text(input?.notifications?.rubikaChatId,base.notifications.rubikaChatId)}
  };
}
