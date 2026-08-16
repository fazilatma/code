/**
 * =====================================================================
 *  پراکسی سرور Node.js — بدون هیچ وابستگی خارجی (فقط ماژول‌های داخلی)
 *  هم‌رفتار با proxy.php — اجرا با:  node proxy.js
 *
 *  استفاده:
 *    GET  /?url=https://example.com/page   → دریافت از طریق پراکسی
 *    POST /?url=...                        → متد و بدنه عیناً ارسال می‌شود
 *    GET  /                                → داشبورد راهنما
 *    GET  /?info=1                         → وضعیت به‌صورت JSON
 *
 *  هدرهای کنترلی: X-Proxy-Key, X-Proxy-UA, X-Proxy-Referer,
 *                 X-Proxy-Cookie, X-Proxy-Time
 *
 *  تنظیمات با متغیر محیطی:
 *    PORT, PROXY_KEY, PROXY_ALLOWED_DOMAINS, PROXY_BLOCKED_DOMAINS,
 *    PROXY_UPSTREAM, PROXY_DIRECT_FIRST, PROXY_ALLOW_PRIVATE, PROXY_CACHE,
 *    PROXY_CACHE_DIR, PROXY_CACHE_TTL, PROXY_TIMEOUT, PROXY_MAX_SIZE,
 *    PROXY_INJECT_BASE, PROXY_VERIFY_SSL, PROXY_USER_AGENT, PROXY_REFERER
 * =====================================================================
 */

'use strict';

const http = require('http');
const https = require('https');
const tls = require('tls');
const net = require('net');
const zlib = require('zlib');
const dns = require('dns').promises;
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { URL } = require('url');

const VERSION = '1.0.0';

// ---------------------------------------------------------------------
// [۱] تنظیمات (قابل بازنویسی با متغیر محیطی)
// ---------------------------------------------------------------------
const env = process.env;
const CONFIG = {
  port: parseInt(env.PORT || '8080', 10),
  proxyKey: env.PROXY_KEY || '',
  allowedDomains: splitList(env.PROXY_ALLOWED_DOMAINS),
  blockedDomains: splitList(env.PROXY_BLOCKED_DOMAINS !== undefined
    ? env.PROXY_BLOCKED_DOMAINS : 'localhost,127.0.0.1,0.0.0.0'),
  upstreamProxies: splitList(env.PROXY_UPSTREAM), // ['http://user:pass@1.2.3.4:8080', ...]
  rotateUpstream: true,
  directFirst: env.PROXY_DIRECT_FIRST !== '0', // 0 = فقط از پراکسی بالادستی عبور کن، مستقیم تلاش نکن
  retryStatuses: [403, 429, 503],
  timeout: parseInt(env.PROXY_TIMEOUT || '30', 10),
  connectTimeout: 10,
  maxRedirects: 5,
  maxSize: parseInt(env.PROXY_MAX_SIZE || String(50 * 1024 * 1024), 10),
  maxBodySize: 20 * 1024 * 1024,
  userAgent: env.PROXY_USER_AGENT ||
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
  referer: env.PROXY_REFERER || '',
  allowPrivateIps: env.PROXY_ALLOW_PRIVATE === '1',
  verifySsl: env.PROXY_VERIFY_SSL !== '0',
  injectBase: env.PROXY_INJECT_BASE !== '0',
  forwardAuth: false,
  cacheEnabled: env.PROXY_CACHE === '1',
  cacheTtl: parseInt(env.PROXY_CACHE_TTL || '120', 10),
  cacheDir: env.PROXY_CACHE_DIR || path.join(__dirname, 'proxy-cache'),
};

function splitList(s) {
  return (s || '').split(',').map((x) => x.trim()).filter(Boolean);
}

// ---------------------------------------------------------------------
// [۲] ابزارهای کمکی
// ---------------------------------------------------------------------

function sendJson(res, status, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Access-Control-Allow-Origin': '*',
  });
  res.end(body);
}

function pError(res, status, code, message) {
  sendJson(res, status, { ok: false, error: { code, message } });
}

function isPrivateIp(ipRaw) {
  let ip = (ipRaw || '').toLowerCase().trim();
  if (ip.startsWith('::ffff:')) ip = ip.slice(7);

  const ipv4 = ip.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (ipv4) {
    const oct = ipv4.slice(1).map(Number);
    if (oct.some((o) => o > 255)) return true;
    const [a, b] = oct;
    if (a === 0 || a === 10 || a === 127) return true;
    if (a === 100 && b >= 64 && b <= 127) return true;
    if (a === 169 && b === 254) return true;
    if (a === 172 && b >= 16 && b <= 31) return true;
    if (a === 192 && (b === 0 || b === 168)) return true;
    if (a === 198 && (b === 18 || b === 19)) return true;
    if (a === 198 && b === 51 && oct[2] === 100) return true;
    if (a === 203 && b === 0 && oct[2] === 113) return true;
    if (a >= 224) return true;
    return false;
  }

  if (ip.includes(':')) {
    const normalized = normalizeIpv6(ip);
    if (!normalized) return true;
    if (normalized === '0000:0000:0000:0000:0000:0000:0000:0000'
      || normalized === '0000:0000:0000:0000:0000:0000:0000:0001') return true;
    const groups = normalized.split(':');
    const first = parseInt(groups[0], 16);
    if ((first & 0xfffe) === 0xfc00) return true;          // fc00::/7
    if ((first & 0xffc0) === 0xfe80) return true;          // fe80::/10
    return false;
  }

  return true;
}

function normalizeIpv6(ip) {
  if (!ip.includes(':')) return null;
  let head = ip, tail = '';
  if (ip.includes('::')) {
    const idx = ip.indexOf('::');
    head = ip.slice(0, idx);
    tail = ip.slice(idx + 2);
  }
  const h = head ? head.split(':') : [];
  const t = tail ? tail.split(':') : [];
  const missing = 8 - h.length - t.length;
  if (missing < 0) return null;
  const groups = h.concat(new Array(missing).fill('0'), t);
  return groups.map((g) => (g === '' ? '0' : g.toLowerCase().padStart(4, '0'))).join(':');
}

function domainMatches(host, pattern) {
  const h = host.toLowerCase().replace(/\.$/, '');
  const p = pattern.toLowerCase().replace(/\.$/, '');
  if (!p) return false;
  if (p === '*') return true;
  if (p.startsWith('*.')) {
    const suffix = p.slice(1);
    return h.endsWith(suffix) && h.length > suffix.length;
  }
  if (p.startsWith('.')) return h.endsWith(p);
  return h === p;
}

function checkDomain(host) {
  for (const pattern of CONFIG.blockedDomains) {
    if (domainMatches(host, pattern)) {
      const e = new Error(`دامنهٔ «${host}» در لیست سیاه است`);
      e.status = 403; e.code = 'domain_blocked';
      throw e;
    }
  }
  if (CONFIG.allowedDomains.length > 0) {
    if (!CONFIG.allowedDomains.some((pattern) => domainMatches(host, pattern))) {
      const e = new Error(`دامنهٔ «${host}» در لیست سفید نیست`);
      e.status = 403; e.code = 'domain_not_allowed';
      throw e;
    }
  }
}

async function checkIps(host) {
  if (CONFIG.allowPrivateIps) return;
  let addresses;
  try {
    addresses = await dns.lookup(host, { all: true });
  } catch {
    const e = new Error(`رزولوش DNS برای «${host}» ناموفق بود`);
    e.status = 502; e.code = 'dns_failed';
    throw e;
  }
  for (const { address } of addresses) {
    if (isPrivateIp(address)) {
      const e = new Error(`آدرس داخلی/خصوصی (${address}) مسدود شد (محافظ SSRF)`);
      e.status = 403; e.code = 'private_ip_blocked';
      throw e;
    }
  }
}

async function validateUrl(rawUrl) {
  let u;
  try {
    u = new URL(rawUrl);
  } catch {
    const e = new Error('آدرس نامعتبر است؛ نمونهٔ درست: ?url=https://example.com/page');
    e.status = 400; e.code = 'invalid_url';
    throw e;
  }
  if (u.protocol !== 'http:' && u.protocol !== 'https:') {
    const e = new Error('فقط http و https پشتیبانی می‌شود');
    e.status = 400; e.code = 'bad_scheme';
    throw e;
  }
  const host = u.hostname.toLowerCase().replace(/^\[|\]$/g, '');
  checkDomain(host);
  await checkIps(host);
  return u;
}

function absoluteUrl(location, base) {
  try {
    return new URL(location, base).toString();
  } catch {
    return '';
  }
}

function injectBase(html, url) {
  const base = `<base href="${url.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}" data-proxy-base="1">`;
  const m = html.match(/<\/head\s*>/i);
  if (m) return html.slice(0, m.index) + base + html.slice(m.index);
  return base + html;
}

// کش فایلی
async function cacheGet(key) {
  if (!CONFIG.cacheEnabled) return null;
  try {
    const meta = JSON.parse(await fs.promises.readFile(path.join(CONFIG.cacheDir, key + '.meta'), 'utf8'));
    if (meta.expires < Date.now() / 1000) return null;
    const body = await fs.promises.readFile(path.join(CONFIG.cacheDir, key + '.body'));
    return { status: meta.status, headers: meta.headers, body: body.toString('utf8') };
  } catch {
    return null;
  }
}

async function cacheSet(key, status, headers, body) {
  if (!CONFIG.cacheEnabled) return;
  try {
    await fs.promises.mkdir(CONFIG.cacheDir, { recursive: true });
    await fs.promises.writeFile(path.join(CONFIG.cacheDir, key + '.meta'), JSON.stringify({
      status, headers, expires: Math.floor(Date.now() / 1000) + CONFIG.cacheTtl,
    }));
    await fs.promises.writeFile(path.join(CONFIG.cacheDir, key + '.body'), body);
  } catch { /* بدون دسترسی به کش → نادیده بگیر */ }
}

// ---------------------------------------------------------------------
// [۳] هستهٔ پراکسی — درخواست به مقصد
// ---------------------------------------------------------------------

/** تجزیهٔ URL پراکسی بالادستی */
function parseUpstream(proxyUrl) {
  try {
    const u = new URL(proxyUrl);
    return {
      protocol: u.protocol.replace(':', ''),
      hostname: u.hostname,
      port: u.port ? parseInt(u.port, 10) : (u.protocol === 'https:' ? 443 : 80),
      auth: u.username ? 'Basic ' + Buffer.from(decodeURIComponent(u.username) + ':' + decodeURIComponent(u.password)).toString('base64') : null,
    };
  } catch {
    return null;
  }
}

/** ارسال یک درخواست (با یا بدون پراکسی بالادستی) و برگرداندن کامل پاسخ */
function requestOnce(target, method, headers, body, upstream, timeoutSec) {
  return new Promise((resolve) => {
    let finished = false;
    let req = null;
    const finish = (obj) => { if (!finished) { finished = true; clearTimeout(timer); resolve(obj); } };

    const isHttps = target.protocol === 'https:';
    const baseHeaders = { ...headers, Host: target.host };
    const reqOptions = { method, path: target.pathname + target.search, headers: baseHeaders };

    // تایم‌اوت کلی
    const timer = setTimeout(() => {
      if (req) req.destroy(new Error('تایم‌اوت درخواست پراکسی'));
      finish({ status: 504, headers: {}, body: '', error: `تایم‌اوت (${timeoutSec} ثانیه)` });
    }, timeoutSec * 1000);

    const onResponse = (targetRes) => {
      const status = targetRes.statusCode || 502;
      const rawHeaders = {};
      for (let i = 0; i < targetRes.rawHeaders.length; i += 2) {
        const k = targetRes.rawHeaders[i].toLowerCase();
        let v = targetRes.rawHeaders[i + 1];
        if (k === 'location') v = absoluteUrl(v, target.toString());
        (rawHeaders[k] = rawHeaders[k] || []).push(v);
      }

      // رمزگشایی فشرده‌سازی
      const enc = (rawHeaders['content-encoding'] || []).join('').toLowerCase();
      let stream = targetRes;
      if (enc === 'gzip') stream = targetRes.pipe(zlib.createGunzip());
      else if (enc === 'deflate') stream = targetRes.pipe(zlib.createInflate());
      else if (enc === 'br') stream = targetRes.pipe(zlib.createBrotliDecompress());

      const chunks = [];
      let size = 0;
      stream.on('data', (c) => {
        size += c.length;
        if (size > CONFIG.maxSize) {
          targetRes.destroy();
          stream.destroy();
          finish({ status: 413, headers: {}, body: '', error: `حجم پاسخ بیش از حد مجاز (${CONFIG.maxSize} بایت)` });
        } else {
          chunks.push(c);
        }
      });
      stream.on('end', () => finish({ status, headers: rawHeaders, body: Buffer.concat(chunks).toString('utf8'), error: null }));
      stream.on('error', (e) => finish({ status: 502, headers: {}, body: '', error: e.message || 'خطای دریافت پاسخ' }));
    };

    const onError = (e) => {
      finish({ status: 502, headers: {}, body: '', error: (e && e.message) ? e.message : 'خطای اتصال به مقصد' });
    };

    if (upstream) {
      const p = parseUpstream(upstream);
      if (!p) return finish({ status: 502, headers: {}, body: '', error: 'آدرس پراکسی بالادستی نامعتبر است' });
      const proxyMod = p.protocol === 'https' ? https : http;

      if (isHttps) {
        // تونل CONNECT برای مقصد https
        const targetPort = target.port ? parseInt(target.port, 10) : 443;
        const connectOpts = {
          host: p.hostname, port: p.port, method: 'CONNECT',
          path: `${target.hostname}:${targetPort}`,
          headers: { Host: `${target.hostname}:${targetPort}` },
        };
        if (p.auth) connectOpts.headers['Proxy-Authorization'] = p.auth;
        const connectReq = proxyMod.request(connectOpts);
        req = connectReq;
        connectReq.setTimeout(CONFIG.connectTimeout * 1000, () => connectReq.destroy(new Error('تایم‌اوت اتصال به پراکسی بالادستی')));
        connectReq.on('connect', (res, socket) => {
          if (res.statusCode !== 200) {
            socket.destroy();
            return finish({ status: 502, headers: {}, body: '', error: `پراکسی بالادستی CONNECT را رد کرد (${res.statusCode})` });
          }
          const tlsSocket = tls.connect({
            socket,
            ...(net.isIP(target.hostname) ? {} : { servername: target.hostname }),
            rejectUnauthorized: CONFIG.verifySsl,
          });
          tlsSocket.on('error', onError);
          tlsSocket.once('secureConnect', () => {
            req = https.request({
              ...reqOptions,
              host: target.hostname,
              port: targetPort,
              headers: baseHeaders,
              createConnection: () => tlsSocket,
              setHost: false,
            });
            req.on('response', onResponse);
            req.on('error', onError);
            if (body) req.write(body);
            req.end();
          });
        });
        connectReq.on('error', onError);
        connectReq.end();
        return;
      }

      // مقصد http از طریق پراکسی بالادستی
      req = proxyMod.request({
        ...reqOptions,
        host: p.hostname,
        port: p.port,
        path: target.toString(),
        headers: { ...baseHeaders, ...(p.auth ? { 'Proxy-Authorization': p.auth } : {}) },
      });
      req.on('response', onResponse);
      req.on('error', onError);
      if (body) req.write(body);
      req.end();
      return;
    }

    // اتصال مستقیم — با IP رزولوشده تا از DNS rebinding جلوگیری شود
    dns.lookup(target.hostname, { all: true }).then((addresses) => {
      if (finished) return;
      const ip = addresses.map((a) => a.address).find((a) => !isPrivateIp(a)) || addresses[0].address;
      if (isHttps) {
        const targetPort = target.port ? parseInt(target.port, 10) : 443;
        req = https.request({
          ...reqOptions,
          host: ip,
          port: targetPort,
          ...(net.isIP(target.hostname) ? {} : { servername: target.hostname }),
          rejectUnauthorized: CONFIG.verifySsl,
          headers: baseHeaders,
        });
      } else {
        const targetPort = target.port ? parseInt(target.port, 10) : 80;
        req = http.request({
          ...reqOptions,
          host: ip,
          port: targetPort,
          headers: baseHeaders,
        });
      }
      req.on('response', onResponse);
      req.on('error', onError);
      if (body) req.write(body);
      req.end();
    }).catch((e) => {
      finish({ status: 502, headers: {}, body: '', error: 'رزولوش DNS ناموفق بود: ' + (e.message || e) });
    });
  });
}

/** چرخش بین پراکسی‌های بالادستی: تلاش تا دریافت پاسخ قابل‌قبول */
async function rotateAttempt(target, method, headers, body, timeoutSec) {
  const list = CONFIG.rotateUpstream ? CONFIG.upstreamProxies : [];
  const attempts = CONFIG.directFirst ? [null, ...list] : (list.length ? list : [null]);
  let last = null;
  for (const proxy of attempts) {
    const res = await requestOnce(target, method, headers, body, proxy, timeoutSec);
    last = res;
    if (!res.error && !CONFIG.retryStatuses.includes(res.status)) return res;
  }
  return last || { status: 502, headers: {}, body: '', error: 'نامشخص' };
}

// ---------------------------------------------------------------------
// [۴] پردازش درخواست پراکسی
// ---------------------------------------------------------------------

async function handleProxy(req, res) {
  try {
    // کلید محافظت
    if (CONFIG.proxyKey) {
      const q = new URL(req.url, 'http://x');
      const key = q.searchParams.get('key') || req.headers['x-proxy-key'] || '';
      const a = Buffer.from(String(key));
      const b = Buffer.from(String(CONFIG.proxyKey));
      if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
        return pError(res, 401, 'bad_key', 'کلید پراکسی نامعتبر است');
      }
    }

    const q = new URL(req.url, 'http://x');
    const rawUrl = q.searchParams.get('url');
    if (!rawUrl) return pError(res, 400, 'missing_url', 'پارامتر url ارسال نشده است؛ نمونه: ?url=https://example.com');

    const method = (req.method || 'GET').toUpperCase();
    if (!['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'].includes(method)) {
      return pError(res, 405, 'bad_method', `متد ${method} پشتیبانی نمی‌شود`);
    }

    // تایم‌اوت سفارشی
    let timeoutSec = CONFIG.timeout;
    const t = req.headers['x-proxy-time'];
    if (t && /^\d+$/.test(String(t))) timeoutSec = Math.max(1, Math.min(300, parseInt(t, 10)));

    // بدنهٔ درخواست
    let body = '';
    if (method !== 'GET' && method !== 'HEAD') {
      const chunks = [];
      let size = 0;
      let tooLarge = false;
      for await (const c of req) {
        size += c.length;
        if (size > CONFIG.maxBodySize) { tooLarge = true; break; }
        chunks.push(c);
      }
      if (tooLarge) return pError(res, 413, 'body_too_large', 'حجم بدنهٔ درخواست بیش از حد مجاز است');
      body = Buffer.concat(chunks).toString('utf8');
    }

    // هدرهای قابل ارسال
    const forward = {};
    for (const h of ['accept', 'accept-language', 'content-type', 'range', 'if-none-match', 'if-modified-since']) {
      if (req.headers[h]) forward[h] = req.headers[h];
    }
    if (CONFIG.forwardAuth && req.headers['authorization']) forward['authorization'] = req.headers['authorization'];
    if (req.headers['x-proxy-ua']) forward['user-agent'] = req.headers['x-proxy-ua'];
    if (req.headers['x-proxy-referer']) forward['referer'] = req.headers['x-proxy-referer'];
    if (req.headers['x-proxy-cookie']) forward['cookie'] = req.headers['x-proxy-cookie'];
    if (!forward['user-agent']) forward['user-agent'] = CONFIG.userAgent;
    if (CONFIG.referer) forward['referer'] = CONFIG.referer;

    // اعتبارسنجی اولیه
    let current = await validateUrl(rawUrl);

    // کش
    const cacheKey = crypto.createHash('sha1').update(method + '\n' + current + '\n' + body).digest('hex');
    const cached = (method === 'GET' || method === 'HEAD') ? await cacheGet(cacheKey) : null;
    if (cached) return emitResponse(res, cached.status, cached.headers, cached.body, current.toString(), true, method);

    // دنبال‌کردن ریدایرکت‌ها با اعتبارسنجی هر پرش
    let result = null;
    let m = method, b = body, hdrs = forward;
    let cur = current;
    for (let hop = 0; hop <= CONFIG.maxRedirects; hop++) {
      cur = await validateUrl(cur.toString()); // هر پرش دوباره چک می‌شود
      result = await rotateAttempt(cur, m, hdrs, b, timeoutSec);
      if (result.error) return pError(res, result.status, 'upstream_failed', result.error);

      const status = result.status;
      if (status >= 300 && status < 400 && result.headers.location && result.headers.location.length) {
        const loc = result.headers.location[result.headers.location.length - 1];
        if (!loc) return pError(res, 502, 'bad_redirect', 'هدر Location ریدایرکت خالی است');
        if (status === 303 && m !== 'GET' && m !== 'HEAD') {
          m = 'GET'; b = ''; hdrs = { 'user-agent': CONFIG.userAgent };
        }
        cur = new URL(loc);
        continue;
      }
      break;
    }
    if (!result || result.error) return pError(res, 502, 'too_many_redirects', 'تعداد ریدایرکت‌ها بیش از حد مجاز است');

    // تزریق <base> برای HTML
    const ct = (result.headers['content-type'] || []).join(' ').toLowerCase();
    if (CONFIG.injectBase && ct.includes('text/html') && result.body) {
      result.body = injectBase(result.body, cur.toString());
    }

    if (m === 'GET' && result.status >= 200 && result.status < 400) {
      await cacheSet(cacheKey, result.status, result.headers, result.body);
    }

    return emitResponse(res, result.status, result.headers, result.body, cur.toString(), false, method);
  } catch (e) {
    return pError(res, e.status || 500, e.code || 'internal', e.message || 'خطای داخلی');
  }
}

function emitResponse(res, status, headers, body, finalUrl, fromCache, method) {
  const out = {};
  const skip = new Set(['content-length', 'content-encoding', 'transfer-encoding', 'connection', 'keep-alive']);
  for (const [name, values] of Object.entries(headers || {})) {
    const n = name.toLowerCase();
    if (skip.has(n) || out[n]) continue;
    if (values.length === 1) out[n] = values[0];
    else out[n] = values;
  }
  out['Access-Control-Allow-Origin'] = '*';
  out['Access-Control-Expose-Headers'] = 'X-Proxy-Final-Url, X-Proxy-Cache, X-Proxy-Final-Status';
  out['X-Proxy-Final-Url'] = finalUrl;
  out['X-Proxy-Cache'] = fromCache ? 'HIT' : 'MISS';
  out['X-Proxy-Final-Status'] = String(status);
  out['Content-Length'] = Buffer.byteLength(body);
  res.writeHead(status, out);
  if (method !== 'HEAD') res.end(body);
  else res.end();
}

// ---------------------------------------------------------------------
// [۵] داشبورد راهنما
// ---------------------------------------------------------------------

function dashboard() {
  const st = {
    cacheState: !CONFIG.cacheEnabled ? 'غیرفعال' : 'فعال',
    keyState: CONFIG.proxyKey ? 'فعال' : 'بدون کلید',
    upstreamCount: CONFIG.upstreamProxies.length,
    allowedCount: CONFIG.allowedDomains.length,
    blockedCount: CONFIG.blockedDomains.length,
  };
  const cards = `
    <div class="card"><div class="card-v">${process.version}</div><div class="card-l">نسخهٔ Node</div></div>
    <div class="card"><div class="card-v ok">فعال</div><div class="card-l">پروتکل http/https</div></div>
    <div class="card"><div class="card-v">${st.upstreamCount}</div><div class="card-l">پراکسی بالادستی</div></div>
    <div class="card"><div class="card-v">${st.cacheState}</div><div class="card-l">کش</div></div>
    <div class="card"><div class="card-v">${st.keyState}</div><div class="card-l">کلید محافظت</div></div>
    <div class="card"><div class="card-v">${st.allowedCount} / ${st.blockedCount}</div><div class="card-l">سفید / سیاه</div></div>`;

  return `<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پراکسی سرور</title>
<style>
:root { --bg:#0f1420; --card:#171e2e; --line:#232c42; --txt:#e8ecf5; --mut:#8b94ad; --acc:#3d8bff; --ok:#2ecc71; --bad:#ff5c5c; }
* { box-sizing:border-box; }
body { margin:0; font-family:'Vazirmatn',Tahoma,sans-serif; background:var(--bg); color:var(--txt); line-height:1.8; }
.wrap { max-width:900px; margin:0 auto; padding:24px 16px 64px; }
h1 { font-size:1.5rem; margin:0 0 4px; }
h1 span { color:var(--acc); }
.sub { color:var(--mut); font-size:.9rem; margin-bottom:24px; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:28px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px; text-align:center; }
.card-v { font-size:1.15rem; font-weight:700; }
.card-v.ok { color:var(--ok); } .card-v.bad { color:var(--bad); }
.card-l { color:var(--mut); font-size:.78rem; }
h2 { font-size:1.05rem; margin:28px 0 10px; border-bottom:1px solid var(--line); padding-bottom:8px; }
code, pre { direction:ltr; background:#0b0f1a; border:1px solid var(--line); border-radius:8px; font-family:Consolas,monospace; }
code { padding:2px 7px; font-size:.85rem; color:#9fd0ff; }
pre { padding:12px 14px; overflow-x:auto; font-size:.82rem; margin:8px 0 16px; }
.testbox { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.testbox input { flex:1; min-width:240px; background:#0b0f1a; border:1px solid var(--line); color:var(--txt); border-radius:8px; padding:10px 12px; direction:ltr; font-family:Consolas,monospace; }
button { background:var(--acc); color:#fff; border:0; border-radius:8px; padding:10px 18px; cursor:pointer; font-family:inherit; }
button:hover { filter:brightness(1.1); }
#result { margin-top:14px; }
#result pre { max-height:320px; overflow:auto; white-space:pre-wrap; word-break:break-all; }
.meta { color:var(--mut); font-size:.82rem; }
iframe { width:100%; height:420px; border:1px solid var(--line); border-radius:10px; background:#fff; margin-top:10px; }
ul { padding-right:20px; }
li { margin:4px 0; }
a { color:var(--acc); }
</style>
</head>
<body>
<div class="wrap">
<h1>🛰️ پراکسی <span>سرور</span></h1>
<div class="sub">نسخهٔ ${VERSION} — Node.js بدون وابستگی — پاسخ‌ها را از سمت سرور می‌گیرد تا IP و ساختار درخواست شما مخفی بماند.</div>
<div class="cards">${cards}</div>

<h2>🧪 تست سریع</h2>
<div class="testbox">
<input id="u" placeholder="https://example.com/page" value="https://registry.npmjs.org/express">
<button onclick="run()">دریافت از طریق پراکسی</button>
</div>
<div id="result"></div>

<h2>📡 روش استفاده</h2>
<p>کافیست پارامتر <code>url</code> را بدهید؛ متد و بدنهٔ درخواست شما عیناً به مقصد ارسال می‌شود:</p>
<pre>https://your-server.com/?url=https://example.com/page</pre>
<p>مثال با جاوااسکریپت (اسکرپر سمت مرورگر):</p>
<pre>fetch('https://your-server.com/?url=' + encodeURIComponent(target))
  .then(r =&gt; r.text())
  .then(html =&gt; console.log(html));</pre>
<p>ارسال POST با بدنه:</p>
<pre>fetch('https://your-server.com/?url=' + encodeURIComponent(api), {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({a: 1})
});</pre>

<h2>🎛️ هدرهای کنترلی</h2>
<ul>
<li><code>X-Proxy-UA</code> — تغییر User-Agent ارسالی به مقصد</li>
<li><code>X-Proxy-Referer</code> — تغییر Referer</li>
<li><code>X-Proxy-Cookie</code> — ارسال کوکی به مقصد</li>
<li><code>X-Proxy-Time</code> — تایم‌اوت سفارشی (ثانیه، حداکثر ۳۰۰)</li>
<li><code>X-Proxy-Key</code> — کلید محافظت (اگر در تنظیمات فعال باشد)</li>
</ul>
<p>هدرهای پاسخ پراکسی: <code>X-Proxy-Final-Url</code> (آدرس نهایی پس از ریدایرکت‌ها)، <code>X-Proxy-Final-Status</code> و <code>X-Proxy-Cache</code>.</p>

<h2>🛡️ امنیت و امکانات</h2>
<ul>
<li>محافظ SSRF — اتصال به IPهای داخلی/خصوصی مسدود است (در تنظیمات قابل تغییر)</li>
<li>لیست سفید/سیاه دامنه‌ها با پشتیبانی از الگوی <code>*.domain.com</code></li>
<li>چرخش خودکار بین پراکسی‌های بالادستی (http) و تلاش مجدد روی خطاهای ۴۰۳/۴۲۹/۵۰۳</li>
<li>اتصال مستقیم با IP رزولوشده برای جلوگیری از DNS rebinding</li>
<li>رمزگشایی خودکار gzip / deflate / brotli — کش فایلی اختیاری — تزریق <code>&lt;base&gt;</code> برای لینک‌های نسبی</li>
</ul>
</div>
<script>
function run() {
  var u = document.getElementById('u').value.trim();
  var out = document.getElementById('result');
  if (!u) { out.innerHTML = ''; return; }
  out.innerHTML = '<div class="meta">در حال دریافت…</div>';
  fetch('?url=' + encodeURIComponent(u))
    .then(function (r) {
      var info = 'وضعیت: ' + r.status + ' | نوع محتوا: ' + (r.headers.get('content-type') || '—') +
                 ' | آدرس نهایی: ' + (r.headers.get('x-proxy-final-url') || '—') +
                 ' | کش: ' + (r.headers.get('x-proxy-cache') || '—');
      var ct = (r.headers.get('content-type') || '').toLowerCase();
      if (ct.indexOf('text/html') !== -1) {
        return r.text().then(function (t) {
          out.innerHTML = '<div class="meta">' + info + '</div>' +
            '<iframe srcdoc="' + t.replace(/"/g, '&quot;') + '"></iframe>' +
            '<details><summary>مشاهدهٔ HTML خام</summary><pre>' + t.replace(/</g, '&lt;') + '</pre></details>';
        });
      }
      return r.text().then(function (t) {
        out.innerHTML = '<div class="meta">' + info + '</div><pre>' + t.replace(/</g, '&lt;') + '</pre>';
      });
    })
    .catch(function (e) {
      out.innerHTML = '<div class="meta">خطا: ' + e + '</div>';
    });
}
</script>
</body>
</html>`;
}

// ---------------------------------------------------------------------
// [۶] سرور
// ---------------------------------------------------------------------

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, 'http://x');
  const pathname = u.pathname;

  // CORS برای همهٔ پاسخ‌ها
  res.setHeader('Access-Control-Allow-Origin', '*');

  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Methods': 'GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS',
      'Access-Control-Allow-Headers': '*',
      'Access-Control-Max-Age': '86400',
    });
    return res.end();
  }

  if (pathname === '/favicon.ico') {
    res.writeHead(204);
    return res.end();
  }

  if (u.searchParams.has('info')) {
    return sendJson(res, 200, {
      ok: true,
      name: 'node-zero-dep-proxy',
      version: VERSION,
      node: process.version,
      cache_enabled: CONFIG.cacheEnabled,
      upstream_count: CONFIG.upstreamProxies.length,
      allowed_count: CONFIG.allowedDomains.length,
      blocked_count: CONFIG.blockedDomains.length,
      private_ips: CONFIG.allowPrivateIps,
      auth_required: !!CONFIG.proxyKey,
    });
  }

  if (u.searchParams.has('url')) {
    return handleProxy(req, res);
  }

  const html = dashboard();
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Content-Length': Buffer.byteLength(html) });
  res.end(html);
});

server.listen(CONFIG.port, '0.0.0.0', () => {
  console.log(`🛰️  پراکسی سرور روی پورت ${CONFIG.port} اجرا شد`);
  console.log(`    داشبورد:  http://0.0.0.0:${CONFIG.port}/`);
  console.log(`    مثال:     http://0.0.0.0:${CONFIG.port}/?url=https://example.com`);
  console.log(`    وضعیت:    http://0.0.0.0:${CONFIG.port}/?info=1`);
  if (CONFIG.upstreamProxies.length) console.log(`    پراکسی بالادستی: ${CONFIG.upstreamProxies.length} عدد (چرخش فعال)`);
  if (CONFIG.allowPrivateIps) console.log('    ⚠️ محافظ SSRF غیرفعال است (فقط برای تست محلی)');
});
