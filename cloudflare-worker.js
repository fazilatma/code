/**
 * =====================================================================
 *  پراکسی سرور برای Cloudflare Workers
 *  (API یکسان با proxy.php / proxy.js — پارامتر ?url=)
 *
 *  دیپلوی:
 *    1. فایل را در Worker جدید جایگذاری کنید (یا با wrangler deploy)
 *    2. (اختیاری) Secret با نام PROXY_KEY تنظیم کنید تا ورکر باز نباشد
 *    3. (اختیاری) متغیر ALLOWED_DOMAINS مثل «*.barfbox.ir,*.digikala.com»
 *
 *  استفاده:
 *    https://proxy.fazilat-ma.workers.dev/?url=https://example.com/page
 *    https://proxy.fazilat-ma.workers.dev/?info=1
 *
 *  هدرهای کنترلی: X-Proxy-UA, X-Proxy-Referer, X-Proxy-Cookie, X-Proxy-Key
 * =====================================================================
 */

const VERSION = '1.0.0';

const DEFAULT_UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
const MAX_REDIRECTS = 5;
const MAX_SIZE = 50 * 1024 * 1024;

// ---------------------------------------------------------------------

function json(status, obj) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Expose-Headers': 'X-Proxy-Final-Url, X-Proxy-Cache, X-Proxy-Final-Status',
    },
  });
}

function domainMatches(host, pattern) {
  const h = host.toLowerCase().replace(/\.$/, '');
  const p = pattern.toLowerCase().trim().replace(/\.$/, '');
  if (!p) return false;
  if (p === '*') return true;
  if (p.startsWith('*.')) {
    const suffix = p.slice(1);
    return h.endsWith(suffix) && h.length > suffix.length;
  }
  if (p.startsWith('.')) return h.endsWith(p);
  return h === p;
}

function isPrivateHostname(host) {
  const h = host.toLowerCase();
  for (const b of BLOCKED_HOSTS) if (h === b) return true;
  if (/^(\d{1,3}\.){3}\d{1,3}$/.test(h)) {
    const o = h.split('.').map(Number);
    if (o.some((x) => x > 255)) return true;
    const [a, b] = o;
    if (a === 0 || a === 10 || a === 127) return true;
    if (a === 100 && b >= 64 && b <= 127) return true;
    if (a === 169 && b === 254) return true;
    if (a === 172 && b >= 16 && b <= 31) return true;
    if (a === 192 && (b === 0 || b === 168)) return true;
    if (a >= 224) return true;
  }
  return false;
}

function validateTarget(rawUrl, env) {
  let t;
  try {
    t = new URL(rawUrl);
  } catch {
    return { error: [400, 'invalid_url', 'آدرس نامعتبر است؛ نمونهٔ درست: ?url=https://example.com/page'] };
  }
  if (t.protocol !== 'http:' && t.protocol !== 'https:') {
    return { error: [400, 'bad_scheme', 'فقط http و https پشتیبانی می‌شود'] };
  }
  const host = t.hostname.toLowerCase();
  if (isPrivateHostname(host)) {
    return { error: [403, 'private_ip_blocked', `آدرس داخلی/خصوصی «${host}» مسدود شد`] };
  }
  const allowed = (env.ALLOWED_DOMAINS || '').split(',').map((s) => s.trim()).filter(Boolean);
  if (allowed.length && !allowed.some((p) => domainMatches(host, p))) {
    return { error: [403, 'domain_not_allowed', `دامنهٔ «${host}» در لیست سفید نیست`] };
  }
  return { target: t };
}

const HOP_BY_HOP = new Set([
  'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
  'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length',
  'content-encoding', 'x-proxy-ua', 'x-proxy-referer', 'x-proxy-cookie', 'x-proxy-key',
]);

function buildForwardHeaders(request, env) {
  const out = {};
  out['user-agent'] =
    request.headers.get('x-proxy-ua') || env.USER_AGENT || DEFAULT_UA;
  if (request.headers.get('x-proxy-referer')) out['referer'] = request.headers.get('x-proxy-referer');
  if (request.headers.get('x-proxy-cookie')) out['cookie'] = request.headers.get('x-proxy-cookie');
  for (const h of ['accept', 'accept-language', 'content-type', 'range']) {
    const v = request.headers.get(h);
    if (v) out[h] = v;
  }
  // بدون accept-encoding → پاسخ معمولاً فشرده نمی‌آید
  return out;
}

async function doFetch(target, request, env) {
  let method = (request.method || 'GET').toUpperCase();
  const headers = buildForwardHeaders(request, env);
  let body = method === 'GET' || method === 'HEAD' ? undefined : request.body;

  let current = target;
  for (let hop = 0; hop <= MAX_REDIRECTS; hop++) {
    const v = validateTarget(current.toString(), env);
    if (v.error) return json(v.error[0], { ok: false, error: { code: v.error[1], message: v.error[2] } });

    let resp;
    try {
      resp = await fetch(current, { method, headers, body, redirect: 'manual' });
    } catch (e) {
      return json(502, { ok: false, error: { code: 'upstream_failed', message: e.message || 'خطای اتصال به مقصد' } });
    }

    if (resp.status >= 300 && resp.status < 400) {
      const loc = resp.headers.get('location');
      if (!loc) return json(502, { ok: false, error: { code: 'bad_redirect', message: 'هدر Location ریدایرکت خالی است' } });
      if (resp.status === 303 && method !== 'GET' && method !== 'HEAD') {
        method = 'GET';
        body = undefined; // تبدیل به GET بدون بدنه
      }
      current = new URL(loc, current);
      continue;
    }

    // ساخت پاسخ نهایی
    const outHeaders = {};
    for (const [k, val] of resp.headers.entries()) {
      const n = k.toLowerCase();
      if (HOP_BY_HOP.has(n)) continue;
      outHeaders[n] = val;
    }
    outHeaders['Access-Control-Allow-Origin'] = '*';
    outHeaders['Access-Control-Expose-Headers'] = 'X-Proxy-Final-Url, X-Proxy-Cache, X-Proxy-Final-Status';
    outHeaders['X-Proxy-Final-Url'] = current.toString();
    outHeaders['X-Proxy-Cache'] = 'MISS';
    outHeaders['X-Proxy-Final-Status'] = String(resp.status);

    const ct = (outHeaders['content-type'] || '').toLowerCase();
    let bodyOut = await resp.arrayBuffer();
    if (bodyOut.byteLength > MAX_SIZE) {
      return json(413, { ok: false, error: { code: 'response_too_large', message: 'حجم پاسخ بیش از حد مجاز است' } });
    }
    let text = new TextDecoder().decode(bodyOut);
    if (ct.includes('text/html') && text && !text.includes('data-proxy-base')) {
      const base = `<base href="${current.toString().replace(/&/g, '&amp;').replace(/"/g, '&quot;')}" data-proxy-base="1">`;
      const m = text.match(/<\/head\s*>/i);
      text = m ? text.slice(0, m.index) + base + text.slice(m.index) : base + text;
    }
    return new Response(text, { status: resp.status, headers: outHeaders });
  }
  return json(502, { ok: false, error: { code: 'too_many_redirects', message: 'تعداد ریدایرکت‌ها بیش از حد مجاز است' } });
}

async function dashboard(env) {
  const keyState = env.PROXY_KEY ? 'فعال' : 'بدون کلید';
  const allowedCount = (env.ALLOWED_DOMAINS || '').split(',').filter((s) => s.trim()).length;
  const html = `<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پراکسی ورکر کلودفلر</title>
<style>
:root { --bg:#0f1420; --card:#171e2e; --line:#232c42; --txt:#e8ecf5; --mut:#8b94ad; --acc:#f6821f; --ok:#2ecc71; }
* { box-sizing:border-box; }
body { margin:0; font-family:'Vazirmatn',Tahoma,sans-serif; background:var(--bg); color:var(--txt); line-height:1.8; }
.wrap { max-width:800px; margin:0 auto; padding:24px 16px 64px; }
h1 { font-size:1.5rem; margin:0 0 4px; }
h1 span { color:var(--acc); }
.sub { color:var(--mut); font-size:.9rem; margin-bottom:24px; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:28px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px; text-align:center; }
.card-v { font-size:1.15rem; font-weight:700; }
.card-v.ok { color:var(--ok); }
.card-l { color:var(--mut); font-size:.78rem; }
h2 { font-size:1.05rem; margin:28px 0 10px; border-bottom:1px solid var(--line); padding-bottom:8px; }
code, pre { direction:ltr; background:#0b0f1a; border:1px solid var(--line); border-radius:8px; font-family:Consolas,monospace; }
code { padding:2px 7px; font-size:.85rem; color:#9fd0ff; }
pre { padding:12px 14px; overflow-x:auto; font-size:.82rem; margin:8px 0 16px; }
.testbox { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.testbox input { flex:1; min-width:240px; background:#0b0f1a; border:1px solid var(--line); color:var(--txt); border-radius:8px; padding:10px 12px; direction:ltr; font-family:Consolas,monospace; }
button { background:var(--acc); color:#fff; border:0; border-radius:8px; padding:10px 18px; cursor:pointer; font-family:inherit; }
#result { margin-top:14px; }
#result pre { max-height:320px; overflow:auto; white-space:pre-wrap; word-break:break-all; }
.meta { color:var(--mut); font-size:.82rem; }
</style>
</head>
<body>
<div class="wrap">
<h1>🛰️ پراکسی <span>کلودفلر</span></h1>
<div class="sub">نسخهٔ ${VERSION} — Worker فالبک برای proxy.php / proxy.js</div>
<div class="cards">
<div class="card"><div class="card-v ok">فعال</div><div class="card-l">وضعیت</div></div>
<div class="card"><div class="card-v">${keyState}</div><div class="card-l">کلید محافظت</div></div>
<div class="card"><div class="card-v">${allowedCount}</div><div class="card-l">دامنهٔ مجاز</div></div>
</div>

<h2>🧪 تست سریع</h2>
<div class="testbox">
<input id="u" placeholder="https://example.com/page" value="https://example.com">
<button onclick="run()">دریافت از طریق ورکر</button>
</div>
<div id="result"></div>

<h2>📡 روش استفاده</h2>
<pre>https://proxy.fazilat-ma.workers.dev/?url=https://example.com/page</pre>
<p>هدرهای کنترلی: <code>X-Proxy-UA</code>، <code>X-Proxy-Referer</code>، <code>X-Proxy-Cookie</code> و <code>X-Proxy-Key</code></p>
</div>
<script>
function run() {
  var u = document.getElementById('u').value.trim();
  var out = document.getElementById('result');
  if (!u) { out.innerHTML = ''; return; }
  out.innerHTML = '<div class="meta">در حال دریافت…</div>';
  fetch('?url=' + encodeURIComponent(u))
    .then(function (r) {
      var info = 'وضعیت: ' + r.status + ' | آدرس نهایی: ' + (r.headers.get('x-proxy-final-url') || '—');
      return r.text().then(function (t) {
        out.innerHTML = '<div class="meta">' + info + '</div><pre>' + t.replace(/</g, '&lt;') + '</pre>';
      });
    })
    .catch(function (e) { out.innerHTML = '<div class="meta">خطا: ' + e + '</div>'; });
}
</script>
</body>
</html>`;
  return new Response(html, { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

// ---------------------------------------------------------------------
// نقطهٔ ورود Worker
// ---------------------------------------------------------------------

export default {
  async fetch(request, env) {
    const u = new URL(request.url);

    if (request.method === 'OPTIONS') {
      return new Response(null, {
        status: 204,
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS',
          'Access-Control-Allow-Headers': '*',
          'Access-Control-Max-Age': '86400',
        },
      });
    }

    if (u.searchParams.has('info')) {
      return json(200, {
        ok: true,
        name: 'cloudflare-worker-proxy',
        version: VERSION,
        key_required: !!env.PROXY_KEY,
        allowed_count: (env.ALLOWED_DOMAINS || '').split(',').filter((s) => s.trim()).length,
      });
    }

    const rawUrl = u.searchParams.get('url');
    if (!rawUrl) return dashboard(env);

    // کلید محافظت
    if (env.PROXY_KEY) {
      const key = u.searchParams.get('key') || request.headers.get('x-proxy-key') || '';
      if (key !== env.PROXY_KEY) {
        return json(401, { ok: false, error: { code: 'bad_key', message: 'کلید پراکسی نامعتبر است' } });
      }
    }

    const v = validateTarget(rawUrl, env);
    if (v.error) return json(v.error[0], { ok: false, error: { code: v.error[1], message: v.error[2] } });

    return doFetch(v.target, request, env);
  },
};
