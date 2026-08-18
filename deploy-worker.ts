/*
 * deploy-worker.ts — پنل تک‌فایلی استقرار Cloudflare Worker از GitHub
 * -------------------------------------------------------------------
 * این فایل عمداً فقط از syntax مشترک JavaScript/TypeScript استفاده می‌کند،
 * import و dependency ندارد و می‌توان آن را مستقیماً در Cloudflare Dashboard
 * به‌عنوان Worker مدیریتی قرار داد.
 *
 * راه امن پیشنهادی:
 *   1) این Worker را با نامی مانند scraper4-deployer ایجاد کنید.
 *   2) یک Secret با نام DEPLOY_PASSWORD برای آن تعریف کنید.
 *   3) API Token کلودفلر را فقط هنگام استقرار در فرم وارد کنید؛ توکن ذخیره نمی‌شود.
 *
 * API Token مقصد باید دست‌کم مجوز Account > Workers Scripts > Edit داشته باشد.
 * Worker مقصد را جدا از این پنل انتخاب کنید تا پنل خودش را جایگزین نکند.
 */

const DEPLOY_VERSION = "1.0.0";
const DEFAULTS = {
  repo: "fazilatma/code",
  branch: "arena/01a01575-code",
  path: "scraper4.worker.js",
  worker: "scraper4",
  compatibilityDate: "2026-08-18"
};
const SECURITY_HEADERS = {
  "x-content-type-options": "nosniff",
  "referrer-policy": "no-referrer",
  "x-frame-options": "DENY",
  "permissions-policy": "camera=(), microphone=(), geolocation=()",
  "cache-control": "no-store"
};

export default {
  async fetch(request, env) {
    try {
      const url = new URL(request.url);
      if (request.method === "GET" && url.pathname === "/health") {
        return json({ ok: true, app: "scraper4-deployer", version: DEPLOY_VERSION, protected: Boolean(env.DEPLOY_PASSWORD) });
      }
      if (request.method === "GET" && url.pathname === "/") return html(PAGE);
      if (request.method !== "POST" || url.pathname !== "/api") return json({ ok: false, error: "مسیر پیدا نشد" }, 404);

      const form = await request.formData();
      requirePanelPassword(form, env);
      const action = text(form, "action") || "inspect";
      const config = readConfig(form);

      if (action === "inspect") {
        const source = await fetchGithubSource(config);
        const hash = await sha256(source.code);
        return json({
          ok: true,
          action,
          source: source.url,
          bytes: byteLength(source.code),
          lines: source.code.split("\n").length,
          sha256: hash,
          valid: validateWorkerSource(source.code),
          preview: source.code.slice(0, 800)
        });
      }

      if (action === "compare") {
        requireCloudflare(config);
        const source = await fetchGithubSource(config);
        const current = await fetchCurrentWorker(config);
        return json({
          ok: true,
          action,
          source: { bytes: byteLength(source.code), sha256: await sha256(source.code) },
          current: current.ok
            ? { bytes: byteLength(current.code), sha256: await sha256(current.code), contentType: current.contentType }
            : { found: false, status: current.status, error: current.error },
          changed: !current.ok || (await sha256(source.code)) !== (await sha256(current.code))
        });
      }

      if (action === "download_current") {
        requireCloudflare(config);
        const current = await fetchCurrentWorker(config);
        if (!current.ok) return json({ ok: false, error: current.error, status: current.status }, current.status || 502);
        return new Response(current.code, {
          headers: {
            ...SECURITY_HEADERS,
            "content-type": current.contentType || "application/javascript; charset=utf-8",
            "content-disposition": `attachment; filename="${safeName(config.worker)}-backup-${Date.now()}.js"`
          }
        });
      }

      if (action === "deploy") {
        requireCloudflare(config);
        if (config.worker === config.deployerName && config.deployerName) {
          throw new Error("برای جلوگیری از حذف پنل، Worker مقصد نباید خودِ Worker دیپلوی‌کننده باشد.");
        }
        const source = await fetchGithubSource(config);
        const validation = validateWorkerSource(source.code);
        if (!validation.ok) throw new Error(validation.error);

        const before = await fetchCurrentWorker(config);
        const sourceHash = await sha256(source.code);
        const currentHash = before.ok ? await sha256(before.code) : "";
        if (sourceHash === currentHash && !config.force) {
          return json({ ok: true, deployed: false, unchanged: true, message: "نسخه مقصد از قبل دقیقاً برابر GitHub است.", sha256: sourceHash });
        }

        const deployed = await uploadWorker(config, source.code);
        return json({
          ok: true,
          deployed: true,
          worker: config.worker,
          source: source.url,
          bytes: byteLength(source.code),
          sha256: sourceHash,
          previousSha256: currentHash || null,
          cloudflare: deployed
        });
      }

      return json({ ok: false, error: "عملیات ناشناخته است" }, 400);
    } catch (error) {
      const status = error instanceof Error && "status" in error ? Number(error.status) || 500 : 500;
      return json({ ok: false, error: error instanceof Error ? error.message : String(error) }, status);
    }
  }
};

function readConfig(form) {
  return {
    panelPassword: text(form, "panel_password"),
    accountId: text(form, "account_id"),
    cloudflareToken: text(form, "cloudflare_token"),
    githubToken: text(form, "github_token"),
    repo: text(form, "repo") || DEFAULTS.repo,
    branch: text(form, "branch") || DEFAULTS.branch,
    path: text(form, "path") || DEFAULTS.path,
    worker: safeName(text(form, "worker") || DEFAULTS.worker),
    compatibilityDate: text(form, "compatibility_date") || DEFAULTS.compatibilityDate,
    force: text(form, "force") === "1",
    deployerName: text(form, "deployer_name")
  };
}

function text(form, key) {
  const value = form.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function requirePanelPassword(form, env) {
  if (!env.DEPLOY_PASSWORD) return;
  const supplied = text(form, "panel_password") || "";
  if (!constantTimeEqual(supplied, String(env.DEPLOY_PASSWORD))) {
    throw Object.assign(new Error("رمز پنل اشتباه است."), { status: 401 });
  }
}

function requireCloudflare(config) {
  if (!/^[a-f0-9]{32}$/i.test(config.accountId)) throw new Error("Account ID کلودفلر معتبر نیست.");
  if (!config.cloudflareToken) throw new Error("Cloudflare API Token وارد نشده است.");
  if (!/^[a-z0-9][a-z0-9_-]{0,62}$/i.test(config.worker)) throw new Error("نام Worker مقصد معتبر نیست.");
  if (!/^\d{4}-\d{2}-\d{2}$/.test(config.compatibilityDate)) throw new Error("Compatibility Date معتبر نیست.");
}

function constantTimeEqual(a, b) {
  const length = Math.max(a.length, b.length);
  let mismatch = a.length ^ b.length;
  for (let i = 0; i < length; i++) mismatch |= (a.charCodeAt(i) || 0) ^ (b.charCodeAt(i) || 0);
  return mismatch === 0;
}

async function fetchGithubSource(config) {
  if (!/^[\w.-]+\/[\w.-]+$/.test(config.repo)) throw new Error("نام مخزن باید به شکل owner/repo باشد.");
  if (!config.branch || !config.path || config.path.includes("..")) throw new Error("برنچ یا مسیر فایل معتبر نیست.");
  const encodedPath = config.path.split("/").map(encodeURIComponent).join("/");
  const url = `https://api.github.com/repos/${config.repo}/contents/${encodedPath}?ref=${encodeURIComponent(config.branch)}`;
  const headers = new Headers({
    "user-agent": `scraper4-deployer/${DEPLOY_VERSION}`,
    accept: "application/vnd.github.raw+json",
    "cache-control": "no-cache"
  });
  if (config.githubToken) headers.set("authorization", `Bearer ${config.githubToken}`);
  const response = await fetch(url, { headers });
  if (!response.ok) {
    const detail = (await response.text()).slice(0, 500);
    throw new Error(`دریافت فایل از GitHub ناموفق بود: HTTP ${response.status} ${detail}`);
  }
  const code = await response.text();
  if (byteLength(code) < 128) throw new Error("فایل دریافت‌شده بیش از حد کوچک است.");
  if (byteLength(code) > 9_500_000) throw new Error("فایل از سقف امن 9.5MB بزرگ‌تر است.");
  return { code, url };
}

function validateWorkerSource(code) {
  if (/^\s*</.test(code) || /<!doctype html/i.test(code.slice(0, 1000))) return { ok: false, error: "به‌جای کد Worker، محتوای HTML دریافت شد." };
  if (!/export\s+default/.test(code)) return { ok: false, error: "فایل export default ندارد و Worker ماژولی معتبر نیست." };
  if (/\binterface\s+[A-Za-z_$]|\btype\s+[A-Za-z_$][\w$]*\s*=|:\s*(string|number|boolean|unknown|any)\b/.test(code.slice(0, 20000))) {
    return { ok: false, error: "فایل هنوز TypeScript خام است. برای Direct Upload باید فایل bundle‌شده JavaScript انتخاب شود." };
  }
  return { ok: true, error: "" };
}

async function fetchCurrentWorker(config) {
  const endpoint = `https://api.cloudflare.com/client/v4/accounts/${config.accountId}/workers/scripts/${encodeURIComponent(config.worker)}`;
  const response = await fetch(endpoint, { headers: { authorization: `Bearer ${config.cloudflareToken}`, accept: "application/javascript, multipart/form-data" } });
  if (!response.ok) {
    return { ok: false, status: response.status, error: `خواندن Worker فعلی ناموفق بود: HTTP ${response.status}`, code: "", contentType: "" };
  }
  const contentType = response.headers.get("content-type") || "";
  const raw = await response.text();
  // Cloudflare may return a multipart module bundle. It is still useful for
  // backup/download and hash comparison, even though it is not unwrapped here.
  return { ok: true, status: 200, error: "", code: raw, contentType };
}

async function uploadWorker(config, code) {
  const endpoint = `https://api.cloudflare.com/client/v4/accounts/${config.accountId}/workers/scripts/${encodeURIComponent(config.worker)}`;
  const metadata = {
    main_module: "worker.js",
    compatibility_date: config.compatibilityDate,
    compatibility_flags: []
  };
  const body = new FormData();
  body.append("metadata", new Blob([JSON.stringify(metadata)], { type: "application/json" }), "metadata.json");
  body.append("worker.js", new Blob([code], { type: "application/javascript+module" }), "worker.js");
  const response = await fetch(endpoint, {
    method: "PUT",
    headers: { authorization: `Bearer ${config.cloudflareToken}` },
    body
  });
  const raw = await response.text();
  let result;
  try { result = JSON.parse(raw); } catch { result = { raw: raw.slice(0, 2000) }; }
  if (!response.ok || result.success === false) {
    const errors = Array.isArray(result.errors) ? result.errors.map(item => item.message || JSON.stringify(item)).join(" | ") : raw.slice(0, 1000);
    throw new Error(`Cloudflare استقرار را نپذیرفت: HTTP ${response.status} ${errors}`);
  }
  return result.result || result;
}

function safeName(value) {
  return String(value || "").toLowerCase().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 63);
}

function byteLength(value) {
  return new TextEncoder().encode(value).byteLength;
}

async function sha256(value) {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, "0")).join("");
}

function json(value, status = 200) {
  return new Response(JSON.stringify(value), {
    status,
    headers: { ...SECURITY_HEADERS, "content-type": "application/json; charset=utf-8" }
  });
}

function html(value) {
  return new Response(value, {
    headers: {
      ...SECURITY_HEADERS,
      "content-type": "text/html; charset=utf-8",
      "content-security-policy": "default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'"
    }
  });
}

const PAGE = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>استقرار Scraper 4 Worker</title><style>
:root{color-scheme:dark;--bg:#07111e;--card:#0f1d31;--line:#2a405d;--muted:#92a6c0;--blue:#38bdf8;--green:#34d399;--red:#fb7185}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 20% 0,#123456,var(--bg) 38%);font:14px Tahoma,Arial;color:#e8eef8}main{max-width:980px;margin:auto;padding:24px}h1{margin:0;font-size:25px}h1 b{color:var(--blue)}.subtitle{color:var(--muted);line-height:2}.card{background:#0f1d31ee;border:1px solid var(--line);border-radius:15px;padding:18px;margin-top:15px;box-shadow:0 16px 45px #0004}.grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.three{grid-template-columns:1fr 1fr 1fr}label{display:block;color:var(--muted);font-size:12px;margin-bottom:5px}input,button{width:100%;border:1px solid #314c6c;border-radius:9px;background:#081422;color:#e8eef8;padding:10px;font:inherit}input:focus{outline:2px solid #0284c7}button{cursor:pointer;background:#075985;border-color:#0ea5e9;font-weight:bold}button:hover{filter:brightness(1.18)}button.green{background:#065f46;border-color:#10b981}button.gray{background:#27364b;border-color:#475569}.actions{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px}.warning{border:1px solid #92400e;background:#43140788;color:#fcd34d;border-radius:10px;padding:11px;line-height:1.9}.result{white-space:pre-wrap;direction:ltr;text-align:left;background:#030913;border-radius:10px;padding:13px;min-height:120px;max-height:430px;overflow:auto;color:#b8c8db}.ok{border:1px solid #047857}.err{border:1px solid #be123c;color:#fecdd3}.small{font-size:11px;color:var(--muted)}@media(max-width:700px){.grid,.three,.actions{grid-template-columns:1fr}}
</style></head><body><main><h1>🚀 استقرار <b>Scraper 4</b> روی Cloudflare</h1><p class="subtitle">فایل bundle‌شده را از GitHub بررسی، مقایسه و مستقیماً روی Worker مقصد نصب می‌کند. هیچ توکنی در این پنل ذخیره نمی‌شود.</p>
<div class="warning">⚠️ برای این Worker یک Secret به نام <b>DEPLOY_PASSWORD</b> بسازید. API Token باید مجوز <b>Workers Scripts: Edit</b> داشته باشد. نام Worker مقصد را با نام این پنل یکسان نگذارید.</div>
<form id="form"><div class="card"><h2>🔐 دسترسی</h2><div class="grid"><div><label>رمز پنل</label><input name="panel_password" type="password" autocomplete="current-password"></div><div><label>نام همین Worker دیپلوی‌کننده</label><input name="deployer_name" placeholder="scraper4-deployer"></div><div><label>Cloudflare Account ID</label><input name="account_id" dir="ltr" autocomplete="off"></div><div><label>Cloudflare API Token</label><input name="cloudflare_token" type="password" dir="ltr" autocomplete="off"></div></div></div>
<div class="card"><h2>📦 مبدأ GitHub</h2><div class="grid three"><div><label>مخزن</label><input name="repo" dir="ltr" value="${DEFAULTS.repo}"></div><div><label>برنچ</label><input name="branch" dir="ltr" value="${DEFAULTS.branch}"></div><div><label>مسیر فایل bundle</label><input name="path" dir="ltr" value="${DEFAULTS.path}"></div></div><div style="margin-top:10px"><label>GitHub Token ـ فقط برای مخزن خصوصی</label><input name="github_token" type="password" dir="ltr" autocomplete="off"></div></div>
<div class="card"><h2>☁️ مقصد Cloudflare</h2><div class="grid"><div><label>نام Worker مقصد</label><input name="worker" dir="ltr" value="${DEFAULTS.worker}"></div><div><label>Compatibility Date</label><input name="compatibility_date" dir="ltr" value="${DEFAULTS.compatibilityDate}"></div></div><label style="margin-top:12px"><input name="force" value="1" type="checkbox" style="width:auto"> استقرار اجباری حتی اگر هش یکسان باشد</label><div class="actions"><button type="button" data-action="inspect">🔍 بررسی مبدأ</button><button type="button" class="gray" data-action="compare">⚖️ مقایسه</button><button type="button" class="gray" data-action="download_current">⬇️ بکاپ فعلی</button><button type="button" class="green" data-action="deploy">🚀 نصب روی Worker</button></div></div></form>
<div class="card"><h2>📋 نتیجه</h2><div id="result" class="result">آماده</div><p class="small">توکن‌ها فقط در حافظه همین صفحه و همان درخواست استفاده می‌شوند و در localStorage قرار نمی‌گیرند.</p></div></main><script>
const form=document.getElementById('form'),result=document.getElementById('result');document.querySelectorAll('[data-action]').forEach(button=>button.onclick=()=>run(button.dataset.action));async function run(action){if(action==='deploy'&&!confirm('Worker مقصد جایگزین شود؟'))return;result.className='result';result.textContent='در حال انجام…';const data=new FormData(form);data.set('action',action);if(!form.force.checked)data.delete('force');try{const response=await fetch('/api',{method:'POST',body:data});if(action==='download_current'&&response.ok){const blob=await response.blob(),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=(form.worker.value||'worker')+'-backup.js';a.click();URL.revokeObjectURL(a.href);result.className='result ok';result.textContent='بکاپ دانلود شد.';return}const body=await response.json();result.className='result '+(response.ok&&body.ok?'ok':'err');result.textContent=JSON.stringify(body,null,2)}catch(error){result.className='result err';result.textContent=error.message}}
</script></body></html>`;
