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
  $('a').attr('href', '#').removeAttr('target');
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

const TOOLBAR = `<div id="__s4bar"><select id="__s4mode"><option value="container">📦 کانتینر</option><option value="next">➡ دکمه صفحه بعد</option><option value="title">📝 عنوان</option><option value="price">💰 قیمت</option><option value="link">🔗 لینک</option><option value="image">🖼 تصویر</option><option value="shortDesc">توضیح کوتاه</option><option value="longDesc">توضیح کامل</option><option value="sku">SKU</option><option value="brand">برند</option><option value="stock">موجودی</option><option value="weight">وزن</option><option value="category">دسته‌بندی</option><option value="gallery">گالری</option><option value="variations">تنوع‌ها</option></select><button id="__s4up">⬆ والد</button><button id="__s4down">⬇ فرزند</button><code id="__s4selector">روی عنصر مورد نظر کلیک کنید</code><span id="__s4count">۰</span><button id="__s4save">✓ ثبت سلکتور</button></div>`;
const PICKER_CSS = `#__s4bar{position:fixed!important;z-index:2147483647!important;top:0!important;left:0!important;right:0!important;min-height:48px!important;background:#0f172af2!important;color:#fff!important;border-bottom:2px solid #a855f7!important;display:flex!important;align-items:center!important;gap:6px!important;padding:6px 9px!important;font:12px Tahoma,sans-serif!important;direction:rtl!important;box-shadow:0 4px 18px #0008!important}#__s4bar select,#__s4bar button{width:auto!important;min-width:0!important;background:#334155!important;color:#fff!important;border:1px solid #64748b!important;border-radius:6px!important;padding:7px 9px!important;font:11px Tahoma!important;cursor:pointer!important}#__s4bar #__s4save{background:#22c55e!important;color:#052e16!important;border-color:#22c55e!important;font-weight:bold!important}#__s4selector{flex:1!important;direction:ltr!important;text-align:left!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;background:#020617!important;color:#f0abfc!important;padding:7px!important;border-radius:5px!important}#__s4count{color:#67e8f9!important;white-space:nowrap!important}.__s4hover{outline:3px solid #a855f7!important;outline-offset:2px!important;cursor:crosshair!important}.__s4picked{outline:4px solid #22c55e!important;outline-offset:2px!important}body{padding-top:52px!important}@media(max-width:650px){#__s4bar{flex-wrap:wrap!important}#__s4selector{order:3;flex-basis:75%!important}body{padding-top:90px!important}}`;
const PICKER_JS = String.raw`(()=>{let current=null,hover=null;const bar=document.getElementById('__s4bar'),mode=document.getElementById('__s4mode'),label=document.getElementById('__s4selector'),count=document.getElementById('__s4count');const esc=v=>{try{return CSS.escape(v)}catch{return String(v).replace(/[^a-zA-Z0-9_-]/g,'\\$&')}};function selector(el){if(!el||el===document.body||el===document.documentElement)return el?.tagName?.toLowerCase()||'body';if(el.id){const s='#'+esc(el.id);if(document.querySelectorAll(s).length===1)return s}const parts=[];let node=el;while(node&&node!==document.body&&parts.length<5){let part=node.tagName.toLowerCase();const classes=[...node.classList].filter(x=>!x.startsWith('__s4')).slice(0,2);if(classes.length)part+='.'+classes.map(esc).join('.');let s=part;try{if(document.querySelectorAll(s).length===1){parts.unshift(part);break}}catch{}const siblings=node.parentElement?[...node.parentElement.children].filter(x=>x.tagName===node.tagName):[];if(siblings.length>1)part+=':nth-of-type('+(siblings.indexOf(node)+1)+')';parts.unshift(part);node=node.parentElement}return parts.join(' > ')}function choose(el){if(current)current.classList.remove('__s4picked');current=el;current.classList.add('__s4picked');const s=selector(current);label.textContent=s;try{count.textContent=document.querySelectorAll(s).length+' مورد'}catch{count.textContent='نامعتبر'}}document.addEventListener('mouseover',e=>{if(bar.contains(e.target))return;if(hover)hover.classList.remove('__s4hover');hover=e.target;hover.classList.add('__s4hover')},true);document.addEventListener('mouseout',e=>{if(e.target?.classList)e.target.classList.remove('__s4hover')},true);document.addEventListener('click',e=>{if(bar.contains(e.target))return;e.preventDefault();e.stopPropagation();choose(e.target)},true);document.getElementById('__s4up').onclick=()=>{if(current?.parentElement&&!bar.contains(current.parentElement))choose(current.parentElement)};document.getElementById('__s4down').onclick=()=>{if(current?.firstElementChild)choose(current.firstElementChild)};document.getElementById('__s4save').onclick=()=>{if(!current)return;const s=selector(current),preview=(current.innerText||current.getAttribute('src')||current.getAttribute('href')||'').trim().replace(/\s+/g,' ').slice(0,250);parent.postMessage({type:'scraper4-selector',mode:mode.value,selector:s,preview,count:document.querySelectorAll(s).length},location.origin)};window.addEventListener('message',e=>{if(e.origin!==location.origin)return;if(e.data?.type==='scraper4-mode'&&e.data.mode)mode.value=e.data.mode})})();`;
