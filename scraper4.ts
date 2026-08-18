/*
 * Scraper 4 — Cloudflare Workers single-file TypeScript edition
 * ------------------------------------------------------------------
 * No binding is required: strict single-file mode uses the Workers Cache API.
 * Optional durable upgrade: bind a KV namespace as SCRAPER_KV.
 * Recommended secrets:
 *   ADMIN_TOKEN, WOO_URL, WOO_KEY, WOO_SECRET, BASALAM_TOKEN, BASALAM_VENDOR_ID
 *
 * Example wrangler.toml (kept out of this file intentionally):
 *   main = "scraper4.ts"
 *   compatibility_date = "2026-08-18"
 *   [[kv_namespaces]]
 *   binding = "SCRAPER_KV"
 *   id = "..."
 *   [triggers]
 *   crons = ["every fifteen minutes"] // use the equivalent cron expression
 */

import { Hono } from "hono";

interface KVNamespace {
  get(key: string, type?: "text"): Promise<string | null>;
  get<T>(key: string, type: "json"): Promise<T | null>;
  put(key: string, value: string, options?: { expirationTtl?: number; metadata?: unknown }): Promise<void>;
  delete(key: string): Promise<void>;
  list(options?: { prefix?: string; cursor?: string; limit?: number }): Promise<{ keys: Array<{ name: string }>; list_complete: boolean; cursor?: string }>;
}
interface ExecutionContext { waitUntil(promise: Promise<unknown>): void; passThroughOnException(): void }
interface ScheduledController { scheduledTime: number; cron: string }
declare class HTMLRewriter {
  on(selector: string, handlers: { element?(element: HtmlElement): void | Promise<void>; text?(text: HtmlTextChunk): void | Promise<void> }): HTMLRewriter;
  transform(response: Response): Response;
}
interface HtmlElement { getAttribute(name: string): string | null; tagName: string }
interface HtmlTextChunk { text: string; lastInTextNode: boolean }

interface CacheStorage { default: { match(request: Request | string): Promise<Response | undefined>; put(request: Request | string, response: Response): Promise<void>; delete(request: Request | string): Promise<boolean> } }
declare const caches: CacheStorage;

interface Env {
  // Optional: when omitted, the strict single-file build falls back to the
  // Workers Cache API. KV is still accepted as an upgrade for durable state.
  SCRAPER_KV?: KVNamespace;
  ADMIN_TOKEN?: string;
  WOO_URL?: string;
  WOO_KEY?: string;
  WOO_SECRET?: string;
  BASALAM_TOKEN?: string;
  BASALAM_VENDOR_ID?: string;
  BASALAM_API?: string;
  USER_AGENT?: string;
}

type Json = null | boolean | number | string | Json[] | { [key: string]: Json };
type SelectorMap = {
  container: string; title: string; price: string; link: string; image: string;
  shortDesc?: string; longDesc?: string; sku?: string; brand?: string;
  stock?: string; weight?: string; category?: string;
};
type Profile = {
  id: string; name: string; url: string; enabled: boolean; pages: number;
  pagination: "query_page" | "path_page" | "next" | "none";
  paginationValue?: string; selectors: SelectorMap;
  titleSuffix?: string; priceMode?: "none" | "add" | "percent" | "multiply";
  priceValue?: number; roundPrice?: number; minPrice?: number;
  wooCategoryId?: number; basalamCategoryId?: number;
  syncWoo?: boolean; syncBasalam?: boolean; createdAt: number; updatedAt: number;
};
type Product = {
  key: string; title: string; price: number; priceText: string; url: string; image: string;
  images: string[]; shortDesc?: string; longDesc?: string; sku?: string; brand?: string;
  stock?: number; weight?: number; category?: string; sourcePage: string; scrapedAt: number;
};
type Job = {
  id: string; profileId: string; status: "queued" | "running" | "done" | "failed" | "stopped";
  phase: string; total: number; processed: number; added: number; updated: number; failed: number;
  startedAt: number; updatedAt: number; finishedAt?: number; error?: string; stop?: boolean;
  log: Array<{ at: number; level: string; message: string }>;
};
type Connections = {
  woo?: { url: string; key: string; secret: string };
  basalam?: { token: string; vendorId: string; api: string };
};

const VERSION = "10.0.0-worker";
const JSON_HEADERS = { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" };
const DEFAULT_SELECTORS: SelectorMap = {
  container: "li.product", title: "h2, h3, .woocommerce-loop-product__title",
  price: ".price, .amount", link: "a[href]", image: "img"
};

type HonoBindings = { Bindings: Env };
const hono = new Hono<HonoBindings>();

// Hono owns the Worker HTTP lifecycle. The compatibility dispatcher below is
// deliberately retained because the original PHP UI calls many query-based
// endpoints instead of REST paths.
hono.use("*", async (c, next) => {
  const started = Date.now();
  await next();
  c.header("x-scraper4-version", VERSION);
  c.header("x-content-type-options", "nosniff");
  c.header("referrer-policy", "same-origin");
  c.header("x-response-time", `${Date.now() - started}ms`);
});
hono.all("*", c => route(c.req.raw, c.env, c.executionCtx as unknown as ExecutionContext));
hono.onError((error, c) => {
  console.error(error);
  return c.json({ ok: false, error: errorMessage(error) }, 500);
});
hono.notFound(c => c.json({ ok: false, error: "مسیر پیدا نشد" }, 404));

const app = {
  fetch: hono.fetch,
  async scheduled(controller: ScheduledController, env: Env, ctx: ExecutionContext): Promise<void> {
    ctx.waitUntil(runScheduled(env, controller.scheduledTime));
  }
};
export default app;

async function route(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
  const url = new URL(request.url);
  const path = url.pathname.replace(/\/+$/, "") || "/";

  if (path === "/health") return json({ ok: true, app: "scraper4-worker", version: VERSION, storage: env.SCRAPER_KV ? "kv" : "cache", time: Date.now() });
  if (path === "/" && request.method === "GET" && !url.search) return html(DASHBOARD);
  if (!authorized(request, env)) return json({ ok: false, error: "دسترسی غیرمجاز" }, 401, { "www-authenticate": "Bearer" });
  const legacy = await legacyRoute(request, url, env, ctx);
  if (legacy) return legacy;

  if (path === "/api/status" && request.method === "GET") {
    return json({ ok: true, version: VERSION, profiles: (await profilesLoad(env)).length, latestJob: await getJson<Job>(env, "jobs:latest") });
  }
  if (path === "/api/profiles" && request.method === "GET") return json({ ok: true, profiles: await profilesLoad(env) });
  if (path === "/api/profiles" && request.method === "POST") {
    const input = await bodyObject(request);
    const profile = normalizeProfile(input);
    const profiles = await profilesLoad(env);
    const at = profiles.findIndex(p => p.id === profile.id);
    if (at >= 0) profile.createdAt = profiles[at].createdAt;
    if (at >= 0) profiles[at] = profile; else profiles.push(profile);
    await putJson(env, "profiles", profiles);
    return json({ ok: true, profile });
  }
  if (path.startsWith("/api/profiles/") && request.method === "DELETE") {
    const id = decodeURIComponent(path.slice("/api/profiles/".length));
    const profiles = await profilesLoad(env);
    const filtered = profiles.filter(p => p.id !== id);
    if (profiles.length === filtered.length) return json({ ok: false, error: "پروفایل پیدا نشد" }, 404);
    await putJson(env, "profiles", filtered);
    await deleteProducts(env, id);
    return json({ ok: true });
  }
  if (path === "/api/connections" && request.method === "GET") {
    const c = effectiveConnections(await connectionsLoad(env), env);
    return json({ ok: true, connections: maskConnections(c) });
  }
  if (path === "/api/connections" && request.method === "POST") {
    const old = await connectionsLoad(env); const body = await bodyObject(request);
    const merged = mergeConnections(old, body as Partial<Connections>);
    await putJson(env, "connections", merged);
    return json({ ok: true, connections: maskConnections(effectiveConnections(merged, env)) });
  }
  if (path === "/api/test-selector" && request.method === "POST") {
    const b = await bodyObject(request); const target = requiredUrl(b.url); const selector = requiredString(b.selector, "selector");
    const values = await collectText(target, selector, env, 10);
    return json({ ok: true, count: values.length, values });
  }
  if (path === "/api/suggest-selectors" && request.method === "POST") {
    const b = await bodyObject(request); return json(await suggestSelectors(requiredUrl(b.url), env));
  }
  if (path === "/api/scrape" && request.method === "POST") {
    const b = await bodyObject(request); const profile = await profileById(env, String(b.profileId || ""));
    const running = await getJson<Job>(env, `job-active:${profile.id}`);
    if (running && ["queued", "running"].includes(running.status)) return json({ ok: false, error: "این پروفایل هم‌اکنون در حال اجرا است", job: running }, 409);
    const job = newJob(profile.id); await saveJob(env, job);
    ctx.waitUntil(runScrape(profile, job, env, Boolean(b.sync)));
    return json({ ok: true, started: true, job }, 202);
  }
  if (path.startsWith("/api/jobs/") && request.method === "GET") {
    const id = decodeURIComponent(path.slice("/api/jobs/".length));
    const job = await getJson<Job>(env, `job:${id}`);
    return job ? json({ ok: true, job }) : json({ ok: false, error: "کار پیدا نشد" }, 404);
  }
  if (path.startsWith("/api/jobs/") && path.endsWith("/stop") && request.method === "POST") {
    const id = decodeURIComponent(path.slice("/api/jobs/".length, -"/stop".length));
    const job = await getJson<Job>(env, `job:${id}`);
    if (!job) return json({ ok: false, error: "کار پیدا نشد" }, 404);
    job.stop = true; job.updatedAt = Date.now(); await saveJob(env, job);
    return json({ ok: true });
  }
  if (path.startsWith("/api/products/") && request.method === "GET") {
    const profileId = decodeURIComponent(path.slice("/api/products/".length));
    const limit = Math.min(500, Math.max(1, Number(url.searchParams.get("limit")) || 100));
    const cursor = url.searchParams.get("cursor") || undefined;
    const page = await productPage(env, profileId, limit, cursor);
    return json({ ok: true, ...page });
  }
  if (path === "/api/sync" && request.method === "POST") {
    const b = await bodyObject(request); const profile = await profileById(env, String(b.profileId || ""));
    const target = String(b.target || "both"); const products = await allProducts(env, profile.id);
    const result = await syncProducts(products, profile, target, env);
    return json({ ok: true, result });
  }
  if (path === "/api/export" && request.method === "GET") {
    const profileId = String(url.searchParams.get("profileId") || "");
    const products = await allProducts(env, profileId);
    return new Response(toCsv(products), { headers: { "content-type": "text/csv; charset=utf-8", "content-disposition": `attachment; filename="${safeId(profileId)}.csv"` } });
  }
  if (path === "/api/import-php" && request.method === "POST") {
    const b = await bodyObject(request);
    const profiles = importPhpProfiles(b.profiles);
    await putJson(env, "profiles", profiles);
    if (b.connections) await putJson(env, "connections", b.connections);
    return json({ ok: true, imported: profiles.length, warning: "کلیدهای محرمانه را بهتر است با wrangler secret ذخیره کنید." });
  }
  if (path === "/proxy/image" && request.method === "GET") return imageProxy(url.searchParams.get("url") || "", env);
  return json({ ok: false, error: "مسیر پیدا نشد" }, 404);
}

async function legacyRoute(request: Request, url: URL, env: Env, ctx: ExecutionContext): Promise<Response | null> {
  // Compatibility layer for the query/form contract used by scraper4.php.
  // Endpoints that have a Worker-native equivalent are intentionally kept.
  const q = url.searchParams;
  if (request.method === "GET") {
    if (q.has("whoami") || q.has("selftest")) return json({ ok: true, runtime: "cloudflare-workers", language: "typescript", version: VERSION, storage: env.SCRAPER_KV ? "kv" : "cache" });
    if (q.has("profiles") || q.has("all_profiles")) {
      const list = await profilesLoad(env); return json(Object.fromEntries(list.map(p => [p.id, p])));
    }
    if (q.has("load_profile")) {
      const value = q.get("load_profile") || q.get("profile_key") || ""; const list = await profilesLoad(env);
      const profile = list.find(p => p.id === value || p.url === value || p.id === profileKeySafe(value));
      return profile ? json({ ok: true, key: profile.id, profile }) : json({ ok: false, error: "پروفایل پیدا نشد" }, 404);
    }
    if (q.has("poll_extract") || q.has("extract_queue_status")) return json({ ok: true, progress: await getJson<Job>(env, "jobs:latest") });
    if (q.has("cron_last")) return json((await getJson<Job>(env, "jobs:latest")) || { ok: false, error: "هنوز اجرا نشده" });
    if (q.has("cron_run")) {
      ctx.waitUntil(runScheduled(env, Date.now())); return json({ ok: true, started: true, detached: true });
    }
    if (q.has("image_proxy") || q.has("rp")) return imageProxy(q.get("image_proxy") || q.get("rp") || "", env);
    if (q.has("test_selector")) {
      const target = requiredUrl(q.get("test_selector")); const selector = requiredString(q.get("selector"), "selector");
      const values = await collectText(target, selector, env, 10); return json({ ok: true, count: values.length, value: values[0] || "", values });
    }
    if (q.has("suggest_selectors")) return json(await suggestSelectors(requiredUrl(q.get("suggest_selectors")), env));
    if (q.has("extract_stop")) {
      const job = await getJson<Job>(env, "jobs:latest"); if (job) { job.stop = true; await saveJob(env, job); } return json({ ok: true });
    }
    if (q.has("csv") || q.has("excel") || q.get("action") === "csv" || q.get("action") === "excel") {
      const id = q.get("profile") || q.get("profile_key") || ""; return new Response(toCsv(await allProducts(env, id)), { headers: { "content-type": "text/csv; charset=utf-8", "content-disposition": `attachment; filename="${safeId(id)}.csv"` } });
    }
    if (q.has("woo_categories")) return json(await wooCategories(env));
    if (q.has("bsl_categories")) return json(await basalamCategories(env));
    if (q.has("sync_status")) return json((await getJson(env, "legacy:sync_state")) || {});
    if (q.has("remote_map")) return json((await getJson(env, "legacy:remote_map")) || {});
    if (q.has("catlearn")) return json((await getJson(env, "legacy:category_learning")) || {});
    if (q.has("ar_rules")) return json((await getJson(env, "legacy:autoreply_rules")) || []);
    if (q.has("ar_log")) return json((await getJson(env, "legacy:autoreply_log")) || []);
    if (q.has("ai_candidates")) return json((await getJson(env, "legacy:ai_candidates")) || []);
    if (q.has("ai_providers_status")) return json(maskAiProviders((await getJson<any[]>(env, "legacy:ai_providers")) || []));
    if (q.has("queues_overview")) return json({ ok: true, extract: await getJson<Job>(env, "jobs:latest"), woo: await getJson(env, "legacy:woo_queue"), basalam: await getJson(env, "legacy:bsl_queue") });
    if (q.has("extract_report")) return json((await getJson<Job>(env, `job:${q.get("queue_id") || ""}`)) || { ok: false, error: "گزارش پیدا نشد" });
    if (q.has("backup_export") || q.has("backup_download")) {
      const bundle = await backupBuild(env); return new Response(JSON.stringify(bundle), { headers: { "content-type": "application/json; charset=utf-8", "content-disposition": `attachment; filename="scraper4-backup-${Date.now()}.json"` } });
    }
    if (q.has("backup_status")) return json((await getJson(env, "legacy:backup_log")) || []);
    if (q.has("sync")) {
      const profile = await profileById(env, q.get("profile_key") || q.get("profile") || ""); const job = newJob(profile.id); await saveJob(env, job); ctx.waitUntil(runScrape(profile, job, env, true)); return json({ ok: true, started: true, queue_id: job.id });
    }
  }
  if (request.method !== "POST" || url.pathname !== "/") return null;
  const body = await bodyObject(request); const action = String(body.action || q.get("action") || "");
  if (!action) return null;
  if (action === "save_profile") {
    const rawSelectors = parseMaybeJson(body.selectors) || {
      container: body.containerSelector || body.container || DEFAULT_SELECTORS.container,
      title: body.titleSelector || body.title || DEFAULT_SELECTORS.title,
      price: body.priceSelector || body.price || DEFAULT_SELECTORS.price,
      link: body.linkSelector || body.link || DEFAULT_SELECTORS.link,
      image: body.imageSelector || body.image || DEFAULT_SELECTORS.image
    };
    const profile = normalizeProfile({ ...body, pages: body.pages, pagination: body.pagType || body.pagination, paginationValue: body.pagVal || body.paginationValue, priceValue: body.priceVal ?? body.priceValue, selectors: rawSelectors });
    const profiles = await profilesLoad(env); const at = profiles.findIndex(p => p.id === profile.id); if (at >= 0) { profile.createdAt = profiles[at].createdAt; profiles[at] = profile; } else profiles.push(profile);
    await putJson(env, "profiles", profiles); return json({ ok: true, key: profile.id, message: "پروفایل ذخیره شد", selectors: profile.selectors, has_selectors: true });
  }
  if (action === "delete_profile") {
    const value = String(body.url || body.profile_key || ""); const id = value.startsWith("http") ? profileKey(value) : value;
    const list = await profilesLoad(env); await putJson(env, "profiles", list.filter(p => p.id !== id)); await deleteProducts(env, id); return json({ ok: true, key: id });
  }
  if (action === "load_connections") return json({ ok: true, connections: maskConnections(effectiveConnections(await connectionsLoad(env), env)) });
  if (action === "save_connections") {
    const incoming = (parseMaybeJson(body.connections) || body) as Partial<Connections>; const merged = mergeConnections(await connectionsLoad(env), incoming); await putJson(env, "connections", merged); return json({ ok: true });
  }
  if (action === "test_woo") return json(await testWoo(env));
  if (action === "test_basalam") return json(await testBasalam(env));
  if (action === "woo_categories") return json(await wooCategories(env));
  if (action === "bsl_categories") return json(await basalamCategories(env));
  if (action === "cron_run") { ctx.waitUntil(runScheduled(env, Date.now())); return json({ ok: true, started: true, detached: true }); }
  if (action === "save_sync" || action === "update_sync_state") {
    const value = parseMaybeJson(body.syncConfig || body.state || body.json) || body; await putJson(env, "legacy:sync_state", value); return json({ ok: true });
  }
  if (action === "ar_save_rules") {
    const rules = parseMaybeJson(body.rules || body.json) || []; await putJson(env, "legacy:autoreply_rules", rules); return json({ ok: true, count: Array.isArray(rules) ? rules.length : 0 });
  }
  if (action === "catlearn_import") {
    const learned = parseMaybeJson(body.data || body.json) || {}; await putJson(env, "legacy:category_learning", learned); return json({ ok: true, count: Object.keys(learned).length });
  }
  if (action === "ai_import_providers") {
    const providers = parseMaybeJson(body.providers || body.json) || []; await putJson(env, "legacy:ai_providers", providers); return json({ ok: true, count: Array.isArray(providers) ? providers.length : 0 });
  }
  if (action === "ai_candidates_save") {
    const candidates = parseMaybeJson(body.candidates || body.json) || []; await putJson(env, "legacy:ai_candidates", candidates); return json({ ok: true });
  }
  if (action === "ai_select") {
    const selected = { provider: body.provider || "", model: body.model || "", updated_at: Date.now() }; await putJson(env, "legacy:ai_selected", selected); return json({ ok: true, selected });
  }
  if (action === "ai_vote") {
    const votes = (await getJson<any[]>(env, "legacy:ai_votes")) || []; votes.push({ ...body, action: undefined, at: Date.now() }); if (votes.length > 1000) votes.splice(0, votes.length - 1000); await putJson(env, "legacy:ai_votes", votes); return json({ ok: true });
  }
  if (action === "backup_run") {
    const bundle = await backupBuild(env); await putJson(env, "legacy:last_backup", bundle); const log = (await getJson<any[]>(env, "legacy:backup_log")) || []; log.push({ at: Date.now(), files: Object.keys(bundle.data).length, ok: true }); await putJson(env, "legacy:backup_log", log.slice(-50)); return json({ ok: true, files: Object.keys(bundle.data).length, bundle });
  }
  if (action === "backup_restore") {
    const bundle = parseMaybeJson(body.bundle || body.json); if (!bundle) return json({ ok: false, error: "بستهٔ بکاپ نامعتبر است" }, 400); return json(await backupRestore(env, bundle));
  }
  if (action === "backup_save_cfg") {
    const cfg = parseMaybeJson(body.config || body.json) || body; delete cfg.action; await putJson(env, "legacy:backup_config", cfg); return json({ ok: true });
  }
  if (action === "csv" || action === "excel") {
    const id = String(body.profile_key || body.profile || ""); return new Response(toCsv(await allProducts(env, id)), { headers: { "content-type": "text/csv; charset=utf-8" } });
  }
  return json({ ok: false, error: `اندپوینت قدیمی «${action}» در حالت تک‌فایلی Workers قابل اجرا نیست`, unsupported: true }, 501);
}
function parseMaybeJson(value: unknown): any {
  if (typeof value !== "string") return value;
  try { return JSON.parse(value); } catch { return null; }
}
function profileKeySafe(value: string): string { try { return profileKey(value); } catch { return value; } }
function maskAiProviders(providers: any[]): any[] {
  return providers.map(provider => ({ ...provider, apiKey: provider.apiKey ? mask(String(provider.apiKey)) : "", key: provider.key ? mask(String(provider.key)) : "" }));
}
type BackupBundle = { app: string; version: string; created_at: number; data: Record<string, unknown> };
const BACKUP_STATE_KEYS = ["profiles", "connections", "legacy:sync_state", "legacy:remote_map", "legacy:category_learning", "legacy:autoreply_rules", "legacy:autoreply_log", "legacy:ai_providers", "legacy:ai_candidates", "legacy:ai_selected", "legacy:ai_votes"];
async function backupBuild(env: Env): Promise<BackupBundle> {
  const data: Record<string, unknown> = {};
  for (const key of BACKUP_STATE_KEYS) { const value = await getJson(env, key); if (value !== null) data[key] = value; }
  const profiles = (data.profiles || []) as Profile[];
  for (const profile of profiles) data[`products:${profile.id}`] = await allProducts(env, profile.id);
  // Connections are retained for compatibility. Treat exported bundles as secrets.
  return { app: "scraper4-worker", version: VERSION, created_at: Date.now(), data };
}
async function backupRestore(env: Env, bundle: any): Promise<object> {
  if (!bundle || bundle.app !== "scraper4-worker" || typeof bundle.data !== "object") return { ok: false, error: "ساختار بکاپ معتبر نیست" };
  let restored = 0;
  for (const [key, value] of Object.entries(bundle.data as Record<string, unknown>)) {
    if (key.startsWith("products:")) {
      const profileId = key.slice("products:".length); const products = Array.isArray(value) ? value as Product[] : [];
      for (const product of products) await putJson(env, productStorageKey(profileId, product.key), product);
      await putJson(env, `product-index:${profileId}`, products.map(p => p.key)); restored += products.length; continue;
    }
    if (BACKUP_STATE_KEYS.includes(key)) { await putJson(env, key, value); restored++; }
  }
  return { ok: true, restored };
}

function authorized(request: Request, env: Env): boolean {
  if (!env.ADMIN_TOKEN) return true;
  const auth = request.headers.get("authorization") || "";
  const token = auth.startsWith("Bearer ") ? auth.slice(7) : request.headers.get("x-admin-token") || "";
  return timingSafeEqual(token, env.ADMIN_TOKEN);
}
function timingSafeEqual(a: string, b: string): boolean {
  const aa = new TextEncoder().encode(a), bb = new TextEncoder().encode(b); let x = aa.length ^ bb.length;
  for (let i = 0; i < Math.max(aa.length, bb.length); i++) x |= (aa[i] || 0) ^ (bb[i] || 0);
  return x === 0;
}
function json(value: unknown, status = 200, extra: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(value), { status, headers: { ...JSON_HEADERS, ...extra } });
}
function html(value: string): Response { return new Response(value, { headers: { "content-type": "text/html; charset=utf-8", "content-security-policy": "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'" } }); }
function errorMessage(e: unknown): string { return e instanceof Error ? e.message : String(e); }
async function bodyObject(request: Request): Promise<Record<string, any>> {
  const type = request.headers.get("content-type") || "";
  if (type.includes("application/json")) return await request.json() as Record<string, any>;
  const form = await request.formData(); const out: Record<string, any> = {};
  for (const [k, v] of form) out[k] = typeof v === "string" ? v : v.name;
  return out;
}
function cacheStateUrl(key: string): string { return `https://scraper4-state.invalid/${encodeURIComponent(key)}`; }
async function getJson<T>(env: Env, key: string): Promise<T | null> {
  if (env.SCRAPER_KV) return env.SCRAPER_KV.get<T>(key, "json");
  const response = await caches.default.match(cacheStateUrl(key));
  if (!response) return null;
  try { return await response.json() as T; } catch { return null; }
}
async function putJson(env: Env, key: string, data: unknown, ttl?: number): Promise<void> {
  const value = JSON.stringify(data); if (value.length > 24_000_000) throw new Error(`حجم ${key} از محدودیت ذخیره‌سازی بیشتر است`);
  if (env.SCRAPER_KV) { await env.SCRAPER_KV.put(key, value, ttl ? { expirationTtl: ttl } : undefined); return; }
  await caches.default.put(cacheStateUrl(key), new Response(value, { headers: {
    "content-type": "application/json", "cache-control": `public, max-age=${ttl || 31536000}`
  } }));
}
async function deleteState(env: Env, key: string): Promise<void> {
  if (env.SCRAPER_KV) { await env.SCRAPER_KV.delete(key); return; }
  await caches.default.delete(cacheStateUrl(key));
}

async function profilesLoad(env: Env): Promise<Profile[]> { return (await getJson<Profile[]>(env, "profiles")) || []; }
async function profileById(env: Env, id: string): Promise<Profile> {
  const p = (await profilesLoad(env)).find(x => x.id === id); if (!p) throw new Error("پروفایل پیدا نشد"); return p;
}
function normalizeProfile(raw: Record<string, any>): Profile {
  const url = requiredUrl(raw.url); const now = Date.now();
  const selectors = { ...DEFAULT_SELECTORS, ...(raw.selectors || {}) } as SelectorMap;
  for (const k of ["container", "title", "price", "link", "image"] as const) requiredString(selectors[k], `selectors.${k}`);
  return {
    id: safeId(String(raw.id || profileKey(url))), name: String(raw.name || new URL(url).hostname).trim(), url,
    enabled: raw.enabled !== false, pages: Math.min(100, Math.max(1, Number(raw.pages) || 1)),
    pagination: ["query_page", "path_page", "next", "none"].includes(raw.pagination) ? raw.pagination : "query_page",
    paginationValue: String(raw.paginationValue || "page"), selectors,
    titleSuffix: String(raw.titleSuffix || ""), priceMode: raw.priceMode || "none", priceValue: Number(raw.priceValue) || 0,
    roundPrice: Math.max(0, Number(raw.roundPrice) || 0), minPrice: Math.max(0, Number(raw.minPrice) || 0),
    wooCategoryId: Number(raw.wooCategoryId) || 0, basalamCategoryId: Number(raw.basalamCategoryId) || 0,
    syncWoo: Boolean(raw.syncWoo), syncBasalam: Boolean(raw.syncBasalam), createdAt: Number(raw.createdAt) || now, updatedAt: now
  };
}
function profileKey(url: string): string {
  const u = new URL(url); return safeId(`${u.hostname}_${u.pathname.replace(/\/page\/\d+\/?$/i, "")}`);
}
function safeId(s: string): string { return s.toLowerCase().replace(/[^a-z0-9_.-]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 120) || crypto.randomUUID(); }
function requiredString(v: unknown, name: string): string { const s = String(v || "").trim(); if (!s) throw new Error(`${name} اجباری است`); return s; }
function requiredUrl(v: unknown): string { const s = requiredString(v, "url"); const u = new URL(s); if (!/^https?:$/.test(u.protocol)) throw new Error("فقط آدرس HTTP/HTTPS مجاز است"); assertPublicHost(u.hostname); return u.href; }
function assertPublicHost(host: string): void {
  const h = host.toLowerCase().replace(/\.$/, "");
  if (h === "localhost" || h.endsWith(".local") || h.endsWith(".internal") || h === "0.0.0.0" || h === "::1") throw new Error("آدرس داخلی مجاز نیست");
  const m = h.match(/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/); if (!m) return;
  const [a, b] = [Number(m[1]), Number(m[2])];
  if (a === 10 || a === 127 || a === 0 || a >= 224 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168)) throw new Error("IP خصوصی مجاز نیست");
}

async function safeFetch(input: string, env: Env, opts: { method?: string; headers?: HeadersInit; body?: BodyInit; maxBytes?: number } = {}): Promise<Response> {
  let current = requiredUrl(input); const maxBytes = opts.maxBytes || 8_000_000;
  for (let redirect = 0; redirect < 5; redirect++) {
    const response = await fetch(current, { method: opts.method || "GET", headers: { "user-agent": env.USER_AGENT || "Mozilla/5.0 (compatible; Scraper4Worker/10.0)", accept: "text/html,application/json;q=0.9,*/*;q=0.8", ...(opts.headers || {}) }, body: opts.body, redirect: "manual" });
    if ([301, 302, 303, 307, 308].includes(response.status)) {
      const location = response.headers.get("location"); if (!location) throw new Error("ریدایرکت بدون مقصد"); current = requiredUrl(new URL(location, current).href); continue;
    }
    const len = Number(response.headers.get("content-length") || 0); if (len > maxBytes) throw new Error("پاسخ سایت بیش از حد بزرگ است");
    return response;
  }
  throw new Error("تعداد ریدایرکت بیش از حد است");
}
async function responseTextLimited(response: Response, max = 8_000_000): Promise<string> {
  if (!response.ok) throw new Error(`HTTP ${response.status} از ${response.url}`);
  const text = await response.text(); if (text.length > max) throw new Error("HTML بیش از حد بزرگ است"); return text;
}
function absoluteUrl(value: string, base: string): string {
  if (!value || /^(data:|javascript:|blob:|#)/i.test(value)) return "";
  try { const u = new URL(value, base); return /^https?:$/.test(u.protocol) ? u.href : ""; } catch { return ""; }
}
function normalizeText(s: string): string { return s.replace(/[\u200c\u200d\u200e\u200f\ufeff]/g, " ").replace(/\s+/g, " ").trim(); }
function numberFromText(s: string): number {
  const en = s.replace(/[۰-۹]/g, d => String("۰۱۲۳۴۵۶۷۸۹".indexOf(d))).replace(/[٠-٩]/g, d => String("٠١٢٣٤٥٦٧٨٩".indexOf(d)));
  const groups = en.match(/[\d][\d,٬.\s]*/g) || []; if (!groups.length) return 0;
  return Math.max(...groups.map(x => Number(x.replace(/[^\d]/g, "")) || 0));
}
function productKey(url: string, title: string): string { return safeId(url ? new URL(url).pathname : title).slice(0, 100); }

class TextCollector {
  values: string[] = []; private current = ""; constructor(private limit = 1000) {}
  text(chunk: HtmlTextChunk): void { if (this.values.length >= this.limit) return; this.current += chunk.text; if (chunk.lastInTextNode) { const v = normalizeText(this.current); if (v) this.values.push(v); this.current = ""; } }
}
class AttrCollector {
  values: string[] = []; constructor(private attrs: string[], private limit = 1000) {}
  element(el: HtmlElement): void { if (this.values.length >= this.limit) return; for (const a of this.attrs) { const v = el.getAttribute(a); if (v && v !== "#") { this.values.push(v); return; } } }
}
async function drain(response: Response): Promise<void> { await response.arrayBuffer(); }
async function collectText(url: string, selector: string, env: Env, limit = 1000): Promise<string[]> {
  const response = await safeFetch(url, env); const h = new TextCollector(limit); await drain(new HTMLRewriter().on(selector, h).transform(response)); return h.values;
}

function scopedSelector(container: string, child: string): string {
  // A comma starts a new selector in CSS. Build the cartesian product so
  // "li.product" + "h2,h3" never turns the second branch into a global h3.
  const parents = container.split(",").map(x => x.trim()).filter(Boolean);
  const children = child.split(",").map(x => x.trim()).filter(Boolean);
  return parents.flatMap(parent => children.map(value => `${parent} ${value}`)).join(",");
}
async function extractProducts(pageUrl: string, selectors: SelectorMap, env: Env): Promise<Product[]> {
  const response = await safeFetch(pageUrl, env); if (!response.ok) throw new Error(`HTTP ${response.status}`);
  const titles = new TextCollector(5000), prices = new TextCollector(5000);
  const links = new AttrCollector(["href", "data-href", "data-url", "data-product-url"], 5000);
  const images = new AttrCollector(["data-src", "data-lazy-src", "data-original", "src", "srcset"], 5000);
  const base = selectors.container.trim();
  let rw = new HTMLRewriter()
    .on(scopedSelector(base, selectors.title), titles).on(scopedSelector(base, selectors.price), prices)
    .on(scopedSelector(base, selectors.link), links).on(scopedSelector(base, selectors.image), images);
  await drain(rw.transform(response));
  const count = Math.max(titles.values.length, links.values.length, prices.values.length);
  const products: Product[] = [];
  for (let i = 0; i < count; i++) {
    const title = normalizeText(titles.values[i] || ""); if (!title) continue;
    const link = absoluteUrl(links.values[i] || "", pageUrl);
    let imgRaw = images.values[i] || ""; if (imgRaw.includes(",")) imgRaw = imgRaw.split(",")[0]; if (/\s+\d+[wx]$/.test(imgRaw)) imgRaw = imgRaw.replace(/\s+\d+[wx]$/, "");
    const image = absoluteUrl(imgRaw, pageUrl); const priceText = prices.values[i] || "";
    products.push({ key: productKey(link, title), title, price: numberFromText(priceText), priceText, url: link, image, images: image ? [image] : [], sourcePage: pageUrl, scrapedAt: Date.now() });
  }
  return products;
}
async function extractDetails(product: Product, selectors: SelectorMap, env: Env): Promise<Product> {
  if (!product.url) return product;
  const fields: Array<[keyof Product, string | undefined, "text" | "attr"]> = [
    ["shortDesc", selectors.shortDesc, "text"], ["longDesc", selectors.longDesc, "text"], ["sku", selectors.sku, "text"],
    ["brand", selectors.brand, "text"], ["category", selectors.category, "text"], ["stock", selectors.stock, "text"], ["weight", selectors.weight, "text"]
  ];
  const active = fields.filter(x => x[1]); if (!active.length) return product;
  const response = await safeFetch(product.url, env); let rw = new HTMLRewriter(); const collectors: TextCollector[] = [];
  for (const [, selector] of active) { const c = new TextCollector(2); collectors.push(c); rw = rw.on(selector!, c); }
  await drain(rw.transform(response));
  active.forEach(([key], i) => { const v = normalizeText(collectors[i].values.join(" ")); if (!v) return; if (key === "stock" || key === "weight") (product as any)[key] = numberFromText(v); else (product as any)[key] = v; });
  return product;
}
function pageUrl(profile: Profile, page: number): string {
  const u = new URL(profile.url); if (page === 1 || profile.pagination === "none" || profile.pagination === "next") return u.href;
  if (profile.pagination === "path_page") { u.pathname = u.pathname.replace(/\/page\/\d+\/?$/i, "").replace(/\/$/, "") + `/page/${page}/`; return u.href; }
  u.searchParams.set(profile.paginationValue || "page", String(page)); return u.href;
}
function transformProduct(p: Product, profile: Profile): Product {
  p.title = normalizeText(p.title + (profile.titleSuffix || "")); const value = profile.priceValue || 0;
  if (profile.priceMode === "add") p.price += value; else if (profile.priceMode === "percent") p.price *= 1 + value / 100; else if (profile.priceMode === "multiply") p.price *= value;
  if (profile.roundPrice) p.price = Math.ceil(p.price / profile.roundPrice) * profile.roundPrice;
  p.price = Math.round(p.price); return p;
}
function newJob(profileId: string): Job { const now = Date.now(); return { id: crypto.randomUUID(), profileId, status: "queued", phase: "waiting", total: 0, processed: 0, added: 0, updated: 0, failed: 0, startedAt: now, updatedAt: now, log: [] }; }
function jobLog(job: Job, message: string, level = "info"): void { job.log.push({ at: Date.now(), level, message }); if (job.log.length > 100) job.log = job.log.slice(-100); job.updatedAt = Date.now(); }
async function saveJob(env: Env, job: Job): Promise<void> { await Promise.all([putJson(env, `job:${job.id}`, job, 604800), putJson(env, "jobs:latest", job, 604800), ...(["queued", "running"].includes(job.status) ? [putJson(env, `job-active:${job.profileId}`, job, 86400)] : [])]); }
async function stopped(env: Env, job: Job): Promise<boolean> { const fresh = await getJson<Job>(env, `job:${job.id}`); return Boolean(fresh?.stop); }

async function runScrape(profile: Profile, job: Job, env: Env, syncAfter: boolean): Promise<void> {
  try {
    job.status = "running"; job.phase = "list"; jobLog(job, `شروع استخراج «${profile.name}»`); await saveJob(env, job);
    const found = new Map<string, Product>();
    for (let page = 1; page <= profile.pages; page++) {
      if (await stopped(env, job)) { job.status = "stopped"; break; }
      const u = pageUrl(profile, page); jobLog(job, `صفحه ${page}: ${u}`);
      const products = await extractProducts(u, profile.selectors, env);
      if (!products.length) { jobLog(job, "محصولی در صفحه پیدا نشد", "warning"); break; }
      for (const p of products) {
        const transformed = transformProduct(p, profile);
        if (!profile.minPrice || transformed.price >= profile.minPrice) found.set(transformed.key, transformed);
      }
      job.total = found.size; job.processed += products.length; await saveJob(env, job);
      // HTMLRewriter cannot click JavaScript-driven “next” controls. Such a
      // profile still extracts its first page instead of repeatedly fetching it.
      if (profile.pagination === "next") { jobLog(job, "صفحه‌بندی next فقط صفحهٔ نخست را در Worker پردازش می‌کند", "warning"); break; }
    }
    if (job.status !== "stopped") {
      job.phase = "details"; const list = [...found.values()];
      for (let i = 0; i < list.length; i += 4) {
        if (await stopped(env, job)) { job.status = "stopped"; break; }
        await Promise.all(list.slice(i, i + 4).map(async p => { try { await extractDetails(p, profile.selectors, env); } catch (e) { job.failed++; jobLog(job, `${p.title}: ${errorMessage(e)}`, "error"); } }));
      }
      if (job.status !== "stopped") {
        job.phase = "save"; const old = new Map((await allProducts(env, profile.id)).map(p => [p.key, p]));
        for (const p of list) { if (old.has(p.key)) job.updated++; else job.added++; await putJson(env, productStorageKey(profile.id, p.key), p); }
        await putJson(env, `product-index:${profile.id}`, list.map(p => p.key));
        if (syncAfter || profile.syncWoo || profile.syncBasalam) {
          job.phase = "sync"; await syncProducts(list, profile, syncAfter ? "both" : profile.syncWoo && profile.syncBasalam ? "both" : profile.syncWoo ? "woo" : "basalam", env);
        }
        job.status = "done"; jobLog(job, `${list.length} محصول ذخیره شد`);
      }
    }
  } catch (e) { job.status = "failed"; job.error = errorMessage(e); jobLog(job, job.error, "error"); }
  job.finishedAt = Date.now(); job.updatedAt = Date.now(); await saveJob(env, job); await deleteState(env, `job-active:${profile.id}`);
}
function productStorageKey(profileId: string, key: string): string { return `product:${safeId(profileId)}:${safeId(key)}`; }
async function allProducts(env: Env, profileId: string): Promise<Product[]> {
  const keys = (await getJson<string[]>(env, `product-index:${profileId}`)) || []; const out: Product[] = [];
  for (let i = 0; i < keys.length; i += 50) { const rows = await Promise.all(keys.slice(i, i + 50).map(k => getJson<Product>(env, productStorageKey(profileId, k)))); out.push(...rows.filter(Boolean) as Product[]); }
  return out;
}
async function productPage(env: Env, profileId: string, limit: number, cursor?: string): Promise<{ products: Product[]; cursor: string | null; total: number }> {
  const keys = (await getJson<string[]>(env, `product-index:${profileId}`)) || []; const start = cursor ? Math.max(0, Number(atob(cursor)) || 0) : 0; const slice = keys.slice(start, start + limit);
  const products = (await Promise.all(slice.map(k => getJson<Product>(env, productStorageKey(profileId, k))))).filter(Boolean) as Product[];
  return { products, cursor: start + limit < keys.length ? btoa(String(start + limit)) : null, total: keys.length };
}
async function deleteProducts(env: Env, profileId: string): Promise<void> { const keys = (await getJson<string[]>(env, `product-index:${profileId}`)) || []; for (let i = 0; i < keys.length; i += 50) await Promise.all(keys.slice(i, i + 50).map(k => deleteState(env, productStorageKey(profileId, k)))); await deleteState(env, `product-index:${profileId}`); }

async function connectionsLoad(env: Env): Promise<Connections> { return (await getJson<Connections>(env, "connections")) || {}; }
function effectiveConnections(c: Connections, env: Env): Connections { return { woo: { url: env.WOO_URL || c.woo?.url || "", key: env.WOO_KEY || c.woo?.key || "", secret: env.WOO_SECRET || c.woo?.secret || "" }, basalam: { token: env.BASALAM_TOKEN || c.basalam?.token || "", vendorId: env.BASALAM_VENDOR_ID || c.basalam?.vendorId || "", api: env.BASALAM_API || c.basalam?.api || "https://openapi.basalam.com/v1" } }; }
function mask(s: string): string { return s ? `${s.slice(0, 3)}••••${s.slice(-3)}` : ""; }
function maskConnections(c: Connections): object { return { woo: { url: c.woo?.url || "", key: mask(c.woo?.key || ""), secret: mask(c.woo?.secret || "") }, basalam: { token: mask(c.basalam?.token || ""), vendorId: c.basalam?.vendorId || "", api: c.basalam?.api || "" } }; }
function mergeConnections(old: Connections, fresh: Partial<Connections>): Connections { const keep = (v: string | undefined, previous = "") => !v || v.includes("••••") ? previous : v; return { woo: { url: fresh.woo?.url || old.woo?.url || "", key: keep(fresh.woo?.key, old.woo?.key), secret: keep(fresh.woo?.secret, old.woo?.secret) }, basalam: { token: keep(fresh.basalam?.token, old.basalam?.token), vendorId: fresh.basalam?.vendorId || old.basalam?.vendorId || "", api: fresh.basalam?.api || old.basalam?.api || "https://openapi.basalam.com/v1" } }; }
async function testWoo(env: Env): Promise<object> {
  const c = effectiveConnections(await connectionsLoad(env), env).woo!;
  if (!c.url || !c.key || !c.secret) return { ok: false, error: "اتصال ووکامرس کامل نیست" };
  const endpoint = c.url.replace(/\/$/, "") + "/wp-json/wc/v3/system_status";
  const response = await safeFetch(endpoint, env, { headers: { authorization: `Basic ${btoa(`${c.key}:${c.secret}`)}`, accept: "application/json" }, maxBytes: 2_000_000 });
  return { ok: response.ok, code: response.status, body: await response.json().catch(() => null) };
}
async function testBasalam(env: Env): Promise<object> {
  const c = effectiveConnections(await connectionsLoad(env), env).basalam!;
  if (!c.token) return { ok: false, error: "توکن باسلام وارد نشده است" };
  const response = await safeFetch(`${c.api.replace(/\/$/, "")}/categories`, env, { headers: { authorization: `Bearer ${c.token}`, accept: "application/json" }, maxBytes: 3_000_000 });
  return { ok: response.ok, code: response.status, body: await response.json().catch(() => null) };
}
async function wooCategories(env: Env): Promise<object> {
  const c = effectiveConnections(await connectionsLoad(env), env).woo!;
  if (!c.url || !c.key || !c.secret) return { ok: false, error: "اتصال ووکامرس کامل نیست" };
  const endpoint = c.url.replace(/\/$/, "") + "/wp-json/wc/v3/products/categories?per_page=100";
  const response = await safeFetch(endpoint, env, { headers: { authorization: `Basic ${btoa(`${c.key}:${c.secret}`)}`, accept: "application/json" }, maxBytes: 4_000_000 });
  const body = await response.json().catch(() => null); return { ok: response.ok, code: response.status, categories: body, body };
}
async function basalamCategories(env: Env): Promise<object> {
  const c = effectiveConnections(await connectionsLoad(env), env).basalam!;
  if (!c.token) return { ok: false, error: "توکن باسلام وارد نشده است" };
  const response = await safeFetch(`${c.api.replace(/\/$/, "")}/categories`, env, { headers: { authorization: `Bearer ${c.token}`, accept: "application/json" }, maxBytes: 4_000_000 });
  const body = await response.json().catch(() => null); return { ok: response.ok, code: response.status, categories: body, body };
}

async function syncProducts(products: Product[], profile: Profile, target: string, env: Env): Promise<object> {
  const c = effectiveConnections(await connectionsLoad(env), env); const result: Record<string, unknown> = {};
  if (target === "woo" || target === "both") result.woo = await syncWoo(products, profile, c.woo!, env);
  if (target === "basalam" || target === "both") result.basalam = await syncBasalam(products, profile, c.basalam!, env);
  return result;
}
async function syncWoo(products: Product[], profile: Profile, c: NonNullable<Connections["woo"]>, env: Env): Promise<object> {
  if (!c.url || !c.key || !c.secret) return { ok: false, error: "اتصال ووکامرس کامل نیست" };
  let created = 0, updated = 0, failed = 0; const errors: string[] = [];
  for (const p of products) {
    try {
      const sku = p.sku || `s4-${profile.id}-${p.key}`.slice(0, 100); const base = c.url.replace(/\/$/, "") + "/wp-json/wc/v3/products";
      const auth = `Basic ${btoa(`${c.key}:${c.secret}`)}`; const search = await safeFetch(`${base}?sku=${encodeURIComponent(sku)}`, env, { headers: { authorization: auth, accept: "application/json" } });
      const existing = search.ok ? await search.json() as any[] : []; const payload: any = { name: p.title, sku, type: "simple", regular_price: String(p.price), description: p.longDesc || "", short_description: p.shortDesc || "", manage_stock: p.stock !== undefined, stock_quantity: p.stock, images: p.images.map(src => ({ src })) };
      if (profile.wooCategoryId) payload.categories = [{ id: profile.wooCategoryId }]; if (p.weight) payload.weight = String(p.weight);
      const id = existing[0]?.id; const response = await safeFetch(id ? `${base}/${id}` : base, env, { method: "POST", headers: { authorization: auth, "content-type": "application/json", accept: "application/json" }, body: JSON.stringify(payload), maxBytes: 2_000_000 });
      if (!response.ok) throw new Error(`WooCommerce HTTP ${response.status}: ${(await response.text()).slice(0, 300)}`); id ? updated++ : created++;
    } catch (e) { failed++; if (errors.length < 20) errors.push(`${p.title}: ${errorMessage(e)}`); }
  }
  return { ok: failed === 0, created, updated, failed, errors };
}
async function syncBasalam(products: Product[], profile: Profile, c: NonNullable<Connections["basalam"]>, env: Env): Promise<object> {
  if (!c.token || !c.vendorId) return { ok: false, error: "اتصال باسلام کامل نیست" };
  let created = 0, failed = 0; const errors: string[] = []; const endpoint = `${c.api.replace(/\/$/, "")}/vendors/${encodeURIComponent(c.vendorId)}/products`;
  for (const p of products) {
    try {
      const payload: any = { name: p.title, price: p.price, stock: p.stock ?? 10, description: p.longDesc || p.shortDesc || "", photo: p.image || undefined, category_id: profile.basalamCategoryId || undefined, weight: p.weight || 500, preparation_days: 3 };
      const response = await safeFetch(endpoint, env, { method: "POST", headers: { authorization: `Bearer ${c.token}`, "content-type": "application/json", accept: "application/json" }, body: JSON.stringify(payload), maxBytes: 2_000_000 });
      if (!response.ok) throw new Error(`Basalam HTTP ${response.status}: ${(await response.text()).slice(0, 300)}`); created++;
    } catch (e) { failed++; if (errors.length < 20) errors.push(`${p.title}: ${errorMessage(e)}`); }
  }
  return { ok: failed === 0, created, failed, errors };
}

async function suggestSelectors(url: string, env: Env): Promise<object> {
  const candidates = ["li.product", "div.product", ".product-card", ".product-item", "article.product", ".woocommerce-LoopProduct-link", "[itemtype*='Product']"];
  const response = await safeFetch(url, env); const counts = candidates.map(() => ({ count: 0 })); let rw = new HTMLRewriter();
  candidates.forEach((s, i) => { rw = rw.on(s, { element() { counts[i].count++; } }); }); await drain(rw.transform(response));
  return { ok: true, containers: candidates.map((selector, i) => ({ selector, count: counts[i].count })).filter(x => x.count > 1).sort((a, b) => b.count - a.count), defaults: DEFAULT_SELECTORS };
}
async function imageProxy(raw: string, env: Env): Promise<Response> {
  const url = requiredUrl(raw); const r = await safeFetch(url, env, { maxBytes: 10_000_000 }); const type = r.headers.get("content-type") || "";
  if (!r.ok || !type.startsWith("image/")) return json({ ok: false, error: "پاسخ تصویر معتبر نیست" }, 400);
  return new Response(r.body, { status: 200, headers: { "content-type": type, "cache-control": "public, max-age=86400", "x-content-type-options": "nosniff" } });
}
async function runScheduled(env: Env, at: number): Promise<void> {
  const profiles = (await profilesLoad(env)).filter(p => p.enabled); for (const profile of profiles) {
    const active = await getJson<Job>(env, `job-active:${profile.id}`); if (active && ["queued", "running"].includes(active.status)) continue;
    const job = newJob(profile.id); job.startedAt = at; await saveJob(env, job); await runScrape(profile, job, env, true);
  }
}
function toCsv(products: Product[]): string {
  const fields: Array<keyof Product> = ["key", "title", "price", "url", "image", "sku", "brand", "stock", "weight", "category", "shortDesc", "longDesc"];
  const esc = (v: unknown) => `"${String(v ?? "").replace(/"/g, '""')}"`; return "\ufeff" + fields.join(",") + "\n" + products.map(p => fields.map(f => esc(p[f])).join(",")).join("\n");
}
function importPhpProfiles(raw: any): Profile[] {
  const source = typeof raw === "string" ? JSON.parse(raw) : raw; if (!source || typeof source !== "object") throw new Error("profiles نامعتبر است");
  return Object.entries(source).map(([key, p]: [string, any]) => normalizeProfile({ ...p, id: key, pagination: p.pagType || p.pagination, paginationValue: p.pagVal || p.paginationValue, priceValue: p.priceVal ?? p.priceValue, selectors: p.selectors || DEFAULT_SELECTORS }));
}

const DASHBOARD = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scraper 4 Worker</title><style>
:root{color-scheme:dark;--bg:#07111f;--card:#101d30;--line:#263b56;--text:#e7eef8;--muted:#91a4bd;--blue:#38bdf8;--green:#34d399;--red:#fb7185}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 20% 0,#102b48,var(--bg) 36%);font:14px Tahoma,Arial;color:var(--text)}main{max-width:1100px;margin:auto;padding:24px}.head{display:flex;align-items:center;justify-content:space-between;gap:12px}h1{font-size:25px;margin:0}h1 b{color:var(--blue)}.badge{background:#12304a;border:1px solid #1e597d;border-radius:20px;padding:7px 12px;color:#8bdcff}.card{background:rgba(16,29,48,.95);border:1px solid var(--line);border-radius:15px;padding:18px;margin-top:16px;box-shadow:0 14px 40px #0003}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:11px}.five{grid-template-columns:2fr 1fr 1fr 1fr 1fr}label{color:var(--muted);font-size:12px;display:block;margin-bottom:5px}input,select,textarea,button{width:100%;border:1px solid #314a67;border-radius:9px;padding:10px;background:#091525;color:var(--text);font:inherit}button{cursor:pointer;background:#075985;border-color:#0ea5e9;font-weight:bold}button:hover{filter:brightness(1.2)}button.red{background:#881337;border-color:#e11d48}button.green{background:#065f46;border-color:#10b981}.actions{display:flex;gap:7px}.actions button{width:auto}.profiles{display:grid;gap:9px}.profile{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px;background:#091525;border:1px solid #203954;border-radius:10px}.profile small{display:block;color:var(--muted);direction:ltr;text-align:right;margin-top:5px}.log{white-space:pre-wrap;direction:rtl;background:#050c16;border-radius:10px;padding:12px;min-height:75px;color:#b8c8db}.ok{color:var(--green)}.err{color:var(--red)}@media(max-width:700px){.grid,.five{grid-template-columns:1fr}.profile,.head{align-items:stretch;flex-direction:column}.actions{flex-wrap:wrap}}
</style></head><body><main><div class="head"><h1>اسکرپر <b>۴</b> — Cloudflare Worker</h1><span class="badge">TypeScript · KV · v${VERSION}</span></div>
<div class="card"><div class="grid"><div><label>توکن مدیریت (اگر ADMIN_TOKEN تنظیم شده)</label><input id="token" type="password" placeholder="Bearer token"></div><div style="align-self:end"><button onclick="loadAll()">اتصال و بارگذاری</button></div></div></div>
<div class="card"><h2>پروفایل استخراج</h2><div class="grid"><div><label>نام</label><input id="name" placeholder="فروشگاه مبدأ"></div><div><label>URL</label><input id="url" dir="ltr" placeholder="https://example.com/shop?page=1"></div></div><div class="grid five" style="margin-top:10px"><div><label>سلکتور محصول</label><input id="container" dir="ltr" value="li.product"></div><div><label>عنوان</label><input id="title" dir="ltr" value="h2"></div><div><label>قیمت</label><input id="price" dir="ltr" value=".price"></div><div><label>لینک</label><input id="link" dir="ltr" value="a[href]"></div><div><label>تصویر</label><input id="image" dir="ltr" value="img"></div></div><div class="grid" style="margin-top:10px"><div><label>تعداد صفحه</label><input id="pages" type="number" value="1" min="1" max="100"></div><div><label>صفحه‌بندی</label><select id="pagination"><option value="query_page">پارامتر page</option><option value="path_page">/page/2/</option><option value="none">بدون صفحه‌بندی</option></select></div></div><button class="green" style="margin-top:12px" onclick="saveProfile()">ذخیره پروفایل</button></div>
<div class="card"><div class="head"><h2>پروفایل‌ها</h2><button style="width:auto" onclick="loadProfiles()">تازه‌سازی</button></div><div id="profiles" class="profiles"></div></div>
<div class="card"><h2>وضعیت</h2><div id="status" class="log">آماده</div></div></main><script>
const $=id=>document.getElementById(id);let timer=null;function headers(){const t=$('token').value;localStorage.s4token=t;return {'content-type':'application/json',...(t?{authorization:'Bearer '+t}:{})}}async function api(path,opt={}){const r=await fetch(path,{...opt,headers:{...headers(),...(opt.headers||{})}});const d=await r.json();if(!r.ok)throw Error(d.error||r.status);return d}function out(s,err=false){$('status').className='log '+(err?'err':'ok');$('status').textContent=typeof s==='string'?s:JSON.stringify(s,null,2)}async function loadProfiles(){try{const d=await api('/api/profiles');$('profiles').innerHTML=d.profiles.map(p=>'<div class="profile"><div><b>'+esc(p.name)+'</b><small>'+esc(p.url)+'</small></div><div class="actions"><button onclick="run(\''+p.id+'\')">استخراج</button><button class="green" onclick="sync(\''+p.id+'\')">همگام‌سازی</button><button class="red" onclick="delp(\''+p.id+'\')">حذف</button></div></div>').join('')||'<span>هنوز پروفایلی نیست.</span>'}catch(e){out(e.message,true)}}async function saveProfile(){try{const body={name:$('name').value,url:$('url').value,pages:+$('pages').value,pagination:$('pagination').value,selectors:{container:$('container').value,title:$('title').value,price:$('price').value,link:$('link').value,image:$('image').value}};await api('/api/profiles',{method:'POST',body:JSON.stringify(body)});out('پروفایل ذخیره شد');loadProfiles()}catch(e){out(e.message,true)}}async function run(id){try{const d=await api('/api/scrape',{method:'POST',body:JSON.stringify({profileId:id})});out(d.job);watch(d.job.id)}catch(e){out(e.message,true)}}function watch(id){clearInterval(timer);timer=setInterval(async()=>{try{const d=await api('/api/jobs/'+id);out(d.job);if(!['queued','running'].includes(d.job.status))clearInterval(timer)}catch(e){clearInterval(timer);out(e.message,true)}},1800)}async function sync(id){try{out('در حال همگام‌سازی…');out((await api('/api/sync',{method:'POST',body:JSON.stringify({profileId:id,target:'both'})})).result)}catch(e){out(e.message,true)}}async function delp(id){if(!confirm('حذف شود؟'))return;try{await api('/api/profiles/'+id,{method:'DELETE'});loadProfiles()}catch(e){out(e.message,true)}}function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}async function loadAll(){$('token').value=$('token').value||localStorage.s4token||'';await loadProfiles();try{out(await api('/api/status'))}catch(e){out(e.message,true)}}$('token').value=localStorage.s4token||'';loadAll();
</script></body></html>`;
