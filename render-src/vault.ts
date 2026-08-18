import { createCipheriv, createDecipheriv, randomBytes, scryptSync } from 'node:crypto';
import { config } from './config.js';

export type ConnectionVault = {
  woo: { url: string; key: string; secret: string; categoryId: number };
  basalam: { token: string; vendorId: string; api: string; preparationDays:number; weight:number; packageWeight:number; stock:number; categoryId:number; autoCategory:boolean; netIndirect:boolean; shops:Array<{name:string;token:string;vendorId:string;pricePercent:number}> };
  ai: { baseUrl: string; apiKey: string; model: string };
  notifications: { url: string; token: string; chatId: string; baleToken:string; baleChatId:string; rubikaToken:string; rubikaChatId:string };
};

type Envelope = { version: 1; salt: string; iv: string; tag: string; ciphertext: string };

export const emptyConnections = (): ConnectionVault => ({
  woo: { url: '', key: '', secret: '', categoryId:0 },
  basalam: { token: '', vendorId: '', api: 'https://openapi.basalam.com/v1', preparationDays:3, weight:500, packageWeight:600, stock:10, categoryId:0, autoCategory:false, netIndirect:false, shops:[] },
  ai: { baseUrl: '', apiKey: '', model: '' },
  notifications: { url: '', token: '', chatId: '', baleToken:'', baleChatId:'', rubikaToken:'', rubikaChatId:'' }
});

function password(): string {
  if (!config.adminToken) throw new Error('برای ذخیره امن اطلاعات اتصال، ابتدا ADMIN_TOKEN را در Render تعریف کنید.');
  return config.adminToken;
}

export function encryptVault(value: ConnectionVault): Envelope {
  const salt=randomBytes(16),iv=randomBytes(12),key=scryptSync(password(),salt,32);
  const cipher=createCipheriv('aes-256-gcm',key,iv);
  const ciphertext=Buffer.concat([cipher.update(JSON.stringify(value),'utf8'),cipher.final()]);
  return { version:1,salt:salt.toString('base64'),iv:iv.toString('base64'),tag:cipher.getAuthTag().toString('base64'),ciphertext:ciphertext.toString('base64') };
}

export function decryptVault(raw: unknown): ConnectionVault {
  if (!raw) return environmentFallback();
  const envelope=raw as Envelope;
  if (envelope.version!==1) throw new Error('نسخه اطلاعات رمزنگاری‌شده پشتیبانی نمی‌شود.');
  try {
    const salt=Buffer.from(envelope.salt,'base64'),iv=Buffer.from(envelope.iv,'base64'),key=scryptSync(password(),salt,32);
    const decipher=createDecipheriv('aes-256-gcm',key,iv);decipher.setAuthTag(Buffer.from(envelope.tag,'base64'));
    const value=JSON.parse(Buffer.concat([decipher.update(Buffer.from(envelope.ciphertext,'base64')),decipher.final()]).toString('utf8'));
    return mergeConnections(emptyConnections(),value);
  } catch { throw new Error('بازکردن اطلاعات اتصال ممکن نشد؛ آیا ADMIN_TOKEN تغییر کرده است؟'); }
}

export function environmentFallback(): ConnectionVault {
  const result=emptyConnections();
  result.woo={...result.woo,url:config.woo.url,key:config.woo.key,secret:config.woo.secret};
  result.basalam={...result.basalam,token:config.basalam.token,vendorId:config.basalam.vendorId,api:config.basalam.api};
  return result;
}

export function mergeConnections(base: ConnectionVault, input: any): ConnectionVault {
  const text=(value:unknown,fallback='')=>typeof value==='string'?value.trim():fallback;
  const num=(value:unknown,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const bool=(value:unknown,fallback=false)=>typeof value==='boolean'?value:fallback;
  const shops=Array.isArray(input?.basalam?.shops)?input.basalam.shops.map((shop:any)=>({name:text(shop?.name),token:text(shop?.token),vendorId:text(shop?.vendorId),pricePercent:num(shop?.pricePercent)})):base.basalam.shops;
  return {
    woo:{url:text(input?.woo?.url,base.woo.url).replace(/\/$/,''),key:text(input?.woo?.key,base.woo.key),secret:text(input?.woo?.secret,base.woo.secret),categoryId:num(input?.woo?.categoryId,base.woo.categoryId)},
    basalam:{token:text(input?.basalam?.token,base.basalam.token),vendorId:text(input?.basalam?.vendorId,base.basalam.vendorId),api:text(input?.basalam?.api,base.basalam.api).replace(/\/$/,'')||'https://openapi.basalam.com/v1',preparationDays:num(input?.basalam?.preparationDays,base.basalam.preparationDays),weight:num(input?.basalam?.weight,base.basalam.weight),packageWeight:num(input?.basalam?.packageWeight,base.basalam.packageWeight),stock:num(input?.basalam?.stock,base.basalam.stock),categoryId:num(input?.basalam?.categoryId,base.basalam.categoryId),autoCategory:bool(input?.basalam?.autoCategory,base.basalam.autoCategory),netIndirect:bool(input?.basalam?.netIndirect,base.basalam.netIndirect),shops},
    ai:{baseUrl:text(input?.ai?.baseUrl,base.ai.baseUrl).replace(/\/$/,''),apiKey:text(input?.ai?.apiKey,base.ai.apiKey),model:text(input?.ai?.model,base.ai.model)},
    notifications:{url:text(input?.notifications?.url,base.notifications.url),token:text(input?.notifications?.token,base.notifications.token),chatId:text(input?.notifications?.chatId,base.notifications.chatId),baleToken:text(input?.notifications?.baleToken,base.notifications.baleToken),baleChatId:text(input?.notifications?.baleChatId,base.notifications.baleChatId),rubikaToken:text(input?.notifications?.rubikaToken,base.notifications.rubikaToken),rubikaChatId:text(input?.notifications?.rubikaChatId,base.notifications.rubikaChatId)}
  };
}
