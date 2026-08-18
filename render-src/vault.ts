import { createCipheriv, createDecipheriv, randomBytes, scryptSync } from 'node:crypto';
import { config } from './config.js';

export type ConnectionVault = {
  woo: { url: string; key: string; secret: string };
  basalam: { token: string; vendorId: string; api: string };
  ai: { baseUrl: string; apiKey: string; model: string };
  notifications: { url: string; token: string; chatId: string };
};

type Envelope = { version: 1; salt: string; iv: string; tag: string; ciphertext: string };

export const emptyConnections = (): ConnectionVault => ({
  woo: { url: '', key: '', secret: '' },
  basalam: { token: '', vendorId: '', api: 'https://openapi.basalam.com/v1' },
  ai: { baseUrl: '', apiKey: '', model: '' },
  notifications: { url: '', token: '', chatId: '' }
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
  result.woo={url:config.woo.url,key:config.woo.key,secret:config.woo.secret};
  result.basalam={token:config.basalam.token,vendorId:config.basalam.vendorId,api:config.basalam.api};
  return result;
}

export function mergeConnections(base: ConnectionVault, input: any): ConnectionVault {
  const text=(value:unknown,fallback='')=>typeof value==='string'?value.trim():fallback;
  return {
    woo:{url:text(input?.woo?.url,base.woo.url).replace(/\/$/,''),key:text(input?.woo?.key,base.woo.key),secret:text(input?.woo?.secret,base.woo.secret)},
    basalam:{token:text(input?.basalam?.token,base.basalam.token),vendorId:text(input?.basalam?.vendorId,base.basalam.vendorId),api:text(input?.basalam?.api,base.basalam.api).replace(/\/$/,'')||'https://openapi.basalam.com/v1'},
    ai:{baseUrl:text(input?.ai?.baseUrl,base.ai.baseUrl).replace(/\/$/,''),apiKey:text(input?.ai?.apiKey,base.ai.apiKey),model:text(input?.ai?.model,base.ai.model)},
    notifications:{url:text(input?.notifications?.url,base.notifications.url),token:text(input?.notifications?.token,base.notifications.token),chatId:text(input?.notifications?.chatId,base.notifications.chatId)}
  };
}
