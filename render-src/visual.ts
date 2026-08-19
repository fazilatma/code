import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto';
import * as cheerio from 'cheerio';
import { config } from './config.js';
import { sourceText } from './source-network.js';

const ephemeralSecret = randomBytes(32).toString('hex');
const secret = () => config.adminToken || ephemeralSecret;

type Ticket = { url: string; expires: number };

export function createVisualTicket(url: string): string {
  const payload: Ticket = { url, expires: Date.now() + 5 * 60_000 };
  const encoded = Buffer.from(JSON.stringify(payload)).toString('base64url');
  const signature = createHmac('sha256', secret()).update(encoded).digest('base64url');
  return `${encoded}.${signature}`;
}

export function readVisualTicket(ticket: string): Ticket {
  const [encoded, signature] = ticket.split('.');
  if (!encoded || !signature) throw new Error('Visual selector ticket is invalid');
  const expected = createHmac('sha256', secret()).update(encoded).digest('base64url');
  const a = Buffer.from(signature), b = Buffer.from(expected);
  if (a.length !== b.length || !timingSafeEqual(a, b)) throw new Error('Visual selector ticket signature is invalid');
  const payload = JSON.parse(Buffer.from(encoded, 'base64url').toString('utf8')) as Ticket;
  if (!payload.url || payload.expires < Date.now()) throw new Error('Visual selector ticket has expired');
  return payload;
}

export async function renderVisualSelector(ticket: string): Promise<string> {
  const { url } = readVisualTicket(ticket);
  const page = await sourceText(url, 6_000_000);
  const $ = cheerio.load(page.text, { scriptingEnabled: false });
  $('script,iframe,object,embed,form,noscript').remove();
  $('meta[http-equiv="Content-Security-Policy"],meta[http-equiv="content-security-policy"],meta[http-equiv="refresh"],base').remove();
  $('a').each((_i,el)=>{const node=$(el),raw=node.attr('href')||'';try{const target=new URL(raw,page.url);if(['http:','https:'].includes(target.protocol)&&!privateLiteral(target.hostname))node.attr('data-s4-href',target.href)}catch{}node.attr('href','#').removeAttr('target')});
  $('*').each((_i, el) => {
    for (const name of Object.keys(('attribs' in el ? el.attribs : {}) || {})) {
      if (/^on/i.test(name) || ['srcdoc', 'nonce'].includes(name.toLowerCase())) $(el).removeAttr(name);
    }
  });
  // Resolve resources against the final URL. Private literal addresses are removed.
  $('[src],[href],[poster]').each((_i, el) => {
    const node = $(el);
    for (const attr of ['src', 'href', 'poster']) {
      const raw = node.attr(attr); if (!raw || raw === '#') continue;
      try { const absolute = new URL(raw, page.url); if (!['http:','https:','data:'].includes(absolute.protocol) || privateLiteral(absolute.hostname)) node.removeAttr(attr); else node.attr(attr, absolute.href); }
      catch { node.removeAttr(attr); }
    }
  });
  $('[srcset]').each((_i, el) => {
    const node = $(el), raw = node.attr('srcset') || '';
    const resolved = raw.split(',').map(part => { const [value, size=''] = part.trim().split(/\s+/,2); try { const absolute = new URL(value, page.url); return privateLiteral(absolute.hostname) ? '' : `${absolute.href} ${size}`.trim(); } catch { return ''; } }).filter(Boolean).join(', ');
    resolved ? node.attr('srcset', resolved) : node.removeAttr('srcset');
  });
  $('head').prepend('<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">');
  $('head').append(`<style>${PICKER_CSS}</style>`);
  $('body').prepend(TOOLBAR);
  $('body').append(`<script>${PICKER_JS}</script>`);
  return $.html();
}

function privateLiteral(host: string): boolean {
  const h = host.toLowerCase();
  return h === 'localhost' || h === '::1' || h.endsWith('.local') || /^127\./.test(h) || /^10\./.test(h) || /^192\.168\./.test(h) || /^169\.254\./.test(h) || /^172\.(1[6-9]|2\d|3[01])\./.test(h);
}

const TOOLBAR = `<div id="__s4bar"><select id="__s4mode"><option value="container">📦 کانتینر</option><option value="next">➡ دکمه صفحه بعد</option><option value="title">📝 عنوان</option><option value="price">💰 قیمت</option><option value="link">🔗 لینک</option><option value="image">🖼 تصویر</option><option value="shortDesc">توضیح کوتاه</option><option value="longDesc">توضیح کامل</option><option value="sku">SKU</option><option value="brand">برند</option><option value="stock">موجودی</option><option value="weight">وزن</option><option value="category">دسته‌بندی</option><option value="gallery">گالری</option><option value="variations">تنوع‌ها</option></select><button id="__s4up">⬆ والد</button><button id="__s4down">⬇ فرزند</button><button id="__s4prev">← قبلی</button><button id="__s4next">بعدی →</button><button id="__s4open">🔗 بازکردن</button><code id="__s4selector">روی عنصر مورد نظر کلیک کنید</code><span id="__s4count">۰</span><button id="__s4save">✓ ثبت سلکتور</button></div>`;
const PICKER_CSS = `#__s4bar{position:fixed!important;z-index:2147483647!important;top:0!important;left:0!important;right:0!important;min-height:48px!important;background:#0f172af2!important;color:#fff!important;border-bottom:2px solid #a855f7!important;display:flex!important;align-items:center!important;gap:6px!important;padding:6px 9px!important;font:12px Tahoma,sans-serif!important;direction:rtl!important;box-shadow:0 4px 18px #0008!important}#__s4bar select,#__s4bar button{width:auto!important;min-width:0!important;background:#334155!important;color:#fff!important;border:1px solid #64748b!important;border-radius:6px!important;padding:7px 9px!important;font:11px Tahoma!important;cursor:pointer!important}#__s4bar #__s4save{background:#22c55e!important;color:#052e16!important;border-color:#22c55e!important;font-weight:bold!important}#__s4selector{flex:1!important;direction:ltr!important;text-align:left!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;background:#020617!important;color:#f0abfc!important;padding:7px!important;border-radius:5px!important}#__s4count{color:#67e8f9!important;white-space:nowrap!important}.__s4hover{outline:3px solid #a855f7!important;outline-offset:2px!important;cursor:crosshair!important}.__s4picked{outline:4px solid #22c55e!important;outline-offset:2px!important}body{padding-top:52px!important}@media(max-width:650px){#__s4bar{flex-wrap:wrap!important}#__s4selector{order:3;flex-basis:75%!important}body{padding-top:90px!important}}`;
const PICKER_JS = String.raw`(()=>{let current=null,hover=null;const bar=document.getElementById('__s4bar'),mode=document.getElementById('__s4mode'),label=document.getElementById('__s4selector'),count=document.getElementById('__s4count');const esc=v=>{try{return CSS.escape(v)}catch{return String(v).replace(/[^a-zA-Z0-9_-]/g,'\\$&')}};const validClass=value=>value&&!value.startsWith('__s4')&&!/^(active|selected|hover|focus|open|show|current|loaded)$/i.test(value)&&value.length<50&&!/[a-f0-9]{12,}/i.test(value);function unique(value){try{return document.querySelectorAll(value).length===1}catch{return false}}function selector(el){if(!el||el===document.body||el===document.documentElement)return el?.tagName?.toLowerCase()||'body';if(el.id&&unique('#'+esc(el.id)))return'#'+esc(el.id);for(const attr of ['data-testid','data-product-id','itemprop','name']){const value=el.getAttribute(attr);if(value){const candidate=el.tagName.toLowerCase()+'['+attr+'="'+esc(value)+'"]';if(unique(candidate))return candidate}}const parts=[];let node=el;while(node&&node!==document.body&&parts.length<6){let part=node.tagName.toLowerCase(),classes=[...node.classList].filter(validClass).slice(0,3);if(classes.length)part+='.'+classes.map(esc).join('.');if(unique(part)){parts.unshift(part);break}const siblings=node.parentElement?[...node.parentElement.children].filter(x=>x.tagName===node.tagName):[];if(siblings.length>1)part+=':nth-of-type('+(siblings.indexOf(node)+1)+')';parts.unshift(part);const combined=parts.join(' > ');if(unique(combined))break;node=node.parentElement}return parts.join(' > ')}function preview(el){return(el.getAttribute('data-s4-href')||el.getAttribute('data-large_image')||el.getAttribute('data-src')||el.getAttribute('src')||el.innerText||'').trim().replace(/\s+/g,' ').slice(0,250)}function choose(el){if(!el||bar.contains(el))return;if(current)current.classList.remove('__s4picked');current=el;current.classList.add('__s4picked');const s=selector(current);label.textContent=s;let total=0;try{total=document.querySelectorAll(s).length;count.textContent=total+' مورد'}catch{count.textContent='نامعتبر'}parent.postMessage({type:'scraper4-preview',mode:mode.value,selector:s,preview:preview(current),count:total,tag:current.tagName.toLowerCase()},location.origin)}document.addEventListener('mouseover',e=>{if(bar.contains(e.target))return;if(hover)hover.classList.remove('__s4hover');hover=e.target;hover.classList.add('__s4hover')},true);document.addEventListener('mouseout',e=>{if(e.target?.classList)e.target.classList.remove('__s4hover')},true);document.addEventListener('click',e=>{if(bar.contains(e.target))return;e.preventDefault();e.stopPropagation();choose(e.target)},true);document.getElementById('__s4up').onclick=()=>choose(current?.parentElement);document.getElementById('__s4down').onclick=()=>choose(current?.firstElementChild);document.getElementById('__s4prev').onclick=()=>choose(current?.previousElementSibling);document.getElementById('__s4next').onclick=()=>choose(current?.nextElementSibling);document.getElementById('__s4open').onclick=()=>{if(!current)return;const link=current.closest('a')||current.querySelector('a'),url=link?.getAttribute('data-s4-href');if(url)parent.postMessage({type:'scraper4-navigate',url},location.origin)};document.getElementById('__s4save').onclick=()=>{if(!current)return;const s=selector(current);parent.postMessage({type:'scraper4-selector',mode:mode.value,selector:s,preview:preview(current),count:document.querySelectorAll(s).length},location.origin)};document.addEventListener('keydown',e=>{if(e.key==='ArrowUp'){e.preventDefault();choose(current?.parentElement)}if(e.key==='ArrowDown'){e.preventDefault();choose(current?.firstElementChild)}if(e.key==='ArrowLeft')choose(current?.previousElementSibling);if(e.key==='ArrowRight')choose(current?.nextElementSibling);if(e.key==='Enter')document.getElementById('__s4save').click()},true);window.addEventListener('message',e=>{if(e.origin!==location.origin)return;if(e.data?.type==='scraper4-mode'&&e.data.mode)mode.value=e.data.mode})})();`;
