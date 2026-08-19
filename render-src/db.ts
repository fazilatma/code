import pg from 'pg';
import { config } from './config.js';
import type { Job, Product, Profile } from './types.js';

const { Pool } = pg;
export const pool = new Pool({
  connectionString: config.databaseUrl,
  ssl: config.databaseUrl.includes('localhost') ? false : { rejectUnauthorized: false },
  max: Math.max(2, Number(process.env.DB_POOL_SIZE || 10)),
  idleTimeoutMillis: 30_000
});

export async function migrate(): Promise<void> {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS profiles (
      id text PRIMARY KEY,
      data jsonb NOT NULL,
      enabled boolean NOT NULL DEFAULT true,
      interval_minutes integer NOT NULL DEFAULT 0,
      last_run_at timestamptz,
      created_at timestamptz NOT NULL DEFAULT now(),
      updated_at timestamptz NOT NULL DEFAULT now()
    );
    CREATE TABLE IF NOT EXISTS products (
      profile_id text NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
      source_key text NOT NULL,
      data jsonb NOT NULL,
      title text NOT NULL,
      price bigint NOT NULL DEFAULT 0,
      source_url text NOT NULL DEFAULT '',
      remote_woo_id bigint,
      remote_basalam_id bigint,
      created_at timestamptz NOT NULL DEFAULT now(),
      updated_at timestamptz NOT NULL DEFAULT now(),
      PRIMARY KEY(profile_id, source_key)
    );
    ALTER TABLE products ADD COLUMN IF NOT EXISTS active boolean NOT NULL DEFAULT true;
    ALTER TABLE products ADD COLUMN IF NOT EXISTS missing_since timestamptz;
    CREATE INDEX IF NOT EXISTS products_profile_updated_idx ON products(profile_id, updated_at DESC);
    CREATE INDEX IF NOT EXISTS products_title_idx ON products USING gin(to_tsvector('simple', title));
    CREATE TABLE IF NOT EXISTS jobs (
      id uuid PRIMARY KEY,
      profile_id text NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
      kind text NOT NULL CHECK (kind IN ('scrape','sync')),
      target text NOT NULL DEFAULT 'none',
      status text NOT NULL DEFAULT 'queued',
      phase text NOT NULL DEFAULT 'waiting',
      total integer NOT NULL DEFAULT 0,
      processed integer NOT NULL DEFAULT 0,
      added integer NOT NULL DEFAULT 0,
      updated integer NOT NULL DEFAULT 0,
      failed integer NOT NULL DEFAULT 0,
      stop_requested boolean NOT NULL DEFAULT false,
      error text,
      log jsonb NOT NULL DEFAULT '[]'::jsonb,
      created_at timestamptz NOT NULL DEFAULT now(),
      started_at timestamptz,
      finished_at timestamptz,
      updated_at timestamptz NOT NULL DEFAULT now()
    );
    CREATE INDEX IF NOT EXISTS jobs_queue_idx ON jobs(status, created_at);
    CREATE TABLE IF NOT EXISTS destination_map (
      profile_id text NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
      source_key text NOT NULL,
      target text NOT NULL,
      account_key text NOT NULL DEFAULT 'default',
      remote_id bigint NOT NULL,
      updated_at timestamptz NOT NULL DEFAULT now(),
      PRIMARY KEY(profile_id,source_key,target,account_key)
    );
    CREATE TABLE IF NOT EXISTS category_learning (
      phrase text NOT NULL, category_id bigint NOT NULL, category_name text NOT NULL DEFAULT '', hits integer NOT NULL DEFAULT 1, updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(phrase,category_id)
    );
    CREATE TABLE IF NOT EXISTS autoreply_log (
      id bigserial PRIMARY KEY, chat_id bigint, customer text NOT NULL DEFAULT '', input_text text NOT NULL, output_text text NOT NULL, source text NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
    );
    CREATE TABLE IF NOT EXISTS app_state (
      key text PRIMARY KEY,
      value jsonb NOT NULL,
      updated_at timestamptz NOT NULL DEFAULT now()
    );
  `);
}

function profileFromRow(row: any): Profile {
  return { ...row.data, lastRunAt: row.last_run_at?.toISOString?.() || row.last_run_at || null, createdAt: row.created_at.toISOString(), updatedAt: row.updated_at.toISOString() };
}

export async function listProfiles(): Promise<Profile[]> {
  const { rows } = await pool.query('SELECT * FROM profiles ORDER BY updated_at DESC');
  return rows.map(profileFromRow);
}

export async function getProfile(id: string): Promise<Profile | null> {
  const { rows } = await pool.query('SELECT * FROM profiles WHERE id=$1', [id]);
  return rows[0] ? profileFromRow(rows[0]) : null;
}

export async function saveProfile(profile: Profile): Promise<Profile> {
  const { rows } = await pool.query(`
    INSERT INTO profiles(id,data,enabled,interval_minutes,created_at,updated_at)
    VALUES($1,$2,$3,$4,now(),now())
    ON CONFLICT(id) DO UPDATE SET data=EXCLUDED.data,enabled=EXCLUDED.enabled,
      interval_minutes=EXCLUDED.interval_minutes,updated_at=now()
    RETURNING *`, [profile.id, JSON.stringify(profile), profile.enabled, profile.intervalMinutes]);
  return profileFromRow(rows[0]);
}

export async function deleteProfile(id: string): Promise<boolean> {
  const result = await pool.query('DELETE FROM profiles WHERE id=$1', [id]);
  return Boolean(result.rowCount);
}

export async function createJob(profileId: string, kind: Job['kind'], target: Job['target']): Promise<Job> {
  const id = crypto.randomUUID();
  const { rows } = await pool.query(`INSERT INTO jobs(id,profile_id,kind,target) VALUES($1,$2,$3,$4) RETURNING *`, [id, profileId, kind, target]);
  return jobFromRow(rows[0]);
}

export async function retryJob(id:string):Promise<Job|null>{const {rows}=await pool.query(`UPDATE jobs SET status='queued',phase='waiting',stop_requested=false,error=NULL,started_at=NULL,finished_at=NULL,processed=0,added=0,updated=0,failed=0,updated_at=now() WHERE id=$1 AND status IN ('failed','stopped','done') RETURNING *`,[id]);return rows[0]?jobFromRow(rows[0]):null}
export async function deleteJob(id:string):Promise<boolean>{const result=await pool.query(`DELETE FROM jobs WHERE id=$1 AND status NOT IN ('running')`,[id]);return Boolean(result.rowCount)}
export async function clearFinishedJobs():Promise<number>{const result=await pool.query(`DELETE FROM jobs WHERE status IN ('done','failed','stopped')`);return result.rowCount||0}

export async function getJob(id: string): Promise<Job | null> {
  const { rows } = await pool.query('SELECT * FROM jobs WHERE id=$1', [id]);
  return rows[0] ? jobFromRow(rows[0]) : null;
}

export async function listJobs(limit = 50): Promise<Job[]> {
  const { rows } = await pool.query('SELECT * FROM jobs ORDER BY created_at DESC LIMIT $1', [limit]);
  return rows.map(jobFromRow);
}

export async function claimJob(): Promise<Job | null> {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    const { rows } = await client.query(`SELECT id FROM jobs WHERE status='queued' ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1`);
    if (!rows[0]) { await client.query('COMMIT'); return null; }
    const result = await client.query(`UPDATE jobs SET status='running',phase='starting',started_at=now(),updated_at=now() WHERE id=$1 RETURNING *`, [rows[0].id]);
    await client.query('COMMIT');
    return jobFromRow(result.rows[0]);
  } catch (error) { await client.query('ROLLBACK'); throw error; }
  finally { client.release(); }
}

export async function updateJob(id: string, patch: Partial<Job>): Promise<void> {
  const allowed: Record<string, string> = { status: 'status', phase: 'phase', total: 'total', processed: 'processed', added: 'added', updated: 'updated', failed: 'failed', stopRequested: 'stop_requested', error: 'error', log: 'log', finishedAt: 'finished_at' };
  const entries = Object.entries(patch).filter(([key]) => allowed[key]);
  if (!entries.length) return;
  const sets = entries.map(([key], i) => `${allowed[key]}=$${i + 2}`);
  const values = entries.map(([key, value]) => key === 'log' ? JSON.stringify(value) : value);
  await pool.query(`UPDATE jobs SET ${sets.join(',')},updated_at=now() WHERE id=$1`, [id, ...values]);
}

export async function stopRequested(id: string): Promise<boolean> {
  const { rows } = await pool.query('SELECT stop_requested FROM jobs WHERE id=$1', [id]);
  return Boolean(rows[0]?.stop_requested);
}

export async function upsertProduct(profileId: string, product: Product): Promise<'added' | 'updated'> {
  const { rows } = await pool.query('SELECT 1 FROM products WHERE profile_id=$1 AND source_key=$2', [profileId, product.sourceKey]);
  await pool.query(`INSERT INTO products(profile_id,source_key,data,title,price,source_url) VALUES($1,$2,$3,$4,$5,$6)
    ON CONFLICT(profile_id,source_key) DO UPDATE SET data=EXCLUDED.data,title=EXCLUDED.title,price=EXCLUDED.price,source_url=EXCLUDED.source_url,active=true,missing_since=NULL,updated_at=now()`,
    [profileId, product.sourceKey, JSON.stringify(product), product.title, product.price, product.url]);
  return rows[0] ? 'updated' : 'added';
}

export async function listProducts(profileId: string, limit = 100, offset = 0, q = ''): Promise<{ products: Product[]; total: number }> {
  const params: unknown[] = [profileId]; let where = 'profile_id=$1';
  if (q) { params.push(`%${q}%`); where += ` AND title ILIKE $${params.length}`; }
  const count = await pool.query(`SELECT count(*)::int total FROM products WHERE ${where}`, params);
  params.push(limit, offset);
  const { rows } = await pool.query(`SELECT data FROM products WHERE ${where} ORDER BY updated_at DESC LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
  return { products: rows.map(row => row.data), total: count.rows[0].total };
}

export async function allProducts(profileId: string): Promise<Product[]> {
  const { rows } = await pool.query('SELECT data FROM products WHERE profile_id=$1 ORDER BY updated_at', [profileId]);
  return rows.map(row => row.data);
}
export async function getProduct(profileId:string,sourceKey:string):Promise<Product|null>{const {rows}=await pool.query('SELECT data FROM products WHERE profile_id=$1 AND source_key=$2',[profileId,sourceKey]);return rows[0]?.data||null}

export async function markMissingProducts(profileId:string,seenKeys:string[]):Promise<number>{if(!seenKeys.length)return 0;const result=await pool.query(`UPDATE products SET active=false,missing_since=COALESCE(missing_since,now()),updated_at=now() WHERE profile_id=$1 AND active=true AND NOT(source_key=ANY($2::text[]))`,[profileId,seenKeys]);return result.rowCount||0}
export async function maintenanceRows(profileId=''):Promise<any[]>{const {rows}=await pool.query(`SELECT p.profile_id,p.source_key,p.data,p.title,p.price,p.source_url,p.remote_woo_id,p.remote_basalam_id,p.active,p.missing_since,COALESCE(json_agg(dm) FILTER(WHERE dm.remote_id IS NOT NULL),'[]') maps FROM products p LEFT JOIN destination_map dm ON dm.profile_id=p.profile_id AND dm.source_key=p.source_key WHERE ($1='' OR p.profile_id=$1) GROUP BY p.profile_id,p.source_key ORDER BY p.updated_at DESC`,[profileId]);return rows}

export async function setRemoteId(profileId: string, sourceKey: string, target: 'woo'|'basalam', id: number): Promise<void> {
  const column = target === 'woo' ? 'remote_woo_id' : 'remote_basalam_id';
  await pool.query(`UPDATE products SET ${column}=$3,updated_at=now() WHERE profile_id=$1 AND source_key=$2`, [profileId, sourceKey, id]);
}

export async function getRemoteId(profileId: string, sourceKey: string, target: 'woo'|'basalam'): Promise<number | null> {
  const column = target === 'woo' ? 'remote_woo_id' : 'remote_basalam_id';
  const { rows } = await pool.query(`SELECT ${column} id FROM products WHERE profile_id=$1 AND source_key=$2`, [profileId, sourceKey]);
  return rows[0]?.id ? Number(rows[0].id) : null;
}

export async function getDestinationId(profileId:string,sourceKey:string,target:string,accountKey='default'):Promise<number|null>{const {rows}=await pool.query('SELECT remote_id FROM destination_map WHERE profile_id=$1 AND source_key=$2 AND target=$3 AND account_key=$4',[profileId,sourceKey,target,accountKey]);return rows[0]?.remote_id?Number(rows[0].remote_id):null}
export async function setDestinationId(profileId:string,sourceKey:string,target:string,accountKey:string,remoteId:number):Promise<void>{await pool.query(`INSERT INTO destination_map(profile_id,source_key,target,account_key,remote_id) VALUES($1,$2,$3,$4,$5) ON CONFLICT(profile_id,source_key,target,account_key) DO UPDATE SET remote_id=EXCLUDED.remote_id,updated_at=now()`,[profileId,sourceKey,target,accountKey,remoteId])}

export async function markProfileRun(id: string): Promise<void> { await pool.query('UPDATE profiles SET last_run_at=now() WHERE id=$1', [id]); }

export async function learnCategory(title:string,categoryId:number,categoryName='',maxWords=5):Promise<number>{const words=normalizeLearning(title).split(' ').filter(Boolean).slice(0,Math.max(1,Math.min(5,maxWords)));let saved=0;for(let n=1;n<=words.length;n++){const phrase=words.slice(0,n).join(' ');await pool.query(`INSERT INTO category_learning(phrase,category_id,category_name,hits,updated_at) VALUES($1,$2,$3,1,now()) ON CONFLICT(phrase,category_id) DO UPDATE SET hits=category_learning.hits+1,category_name=EXCLUDED.category_name,updated_at=now()`,[phrase,categoryId,categoryName]);saved++}return saved}
export async function findLearnedCategory(title:string,maxWords=5):Promise<{categoryId:number;categoryName:string;phrase:string;hits:number}|null>{const words=normalizeLearning(title).split(' ').filter(Boolean).slice(0,Math.max(1,Math.min(5,maxWords)));for(let n=words.length;n>=1;n--){const phrase=words.slice(0,n).join(' '),{rows}=await pool.query(`SELECT category_id,category_name,phrase,hits FROM category_learning WHERE phrase=$1 ORDER BY hits DESC,updated_at DESC LIMIT 1`,[phrase]);if(rows[0])return{categoryId:Number(rows[0].category_id),categoryName:rows[0].category_name,phrase:rows[0].phrase,hits:rows[0].hits}}return null}
export async function importCategoryLearning(raw:any):Promise<number>{const items=Array.isArray(raw)?raw:Object.entries(raw||{}).map(([phrase,value]:any)=>({phrase,...(typeof value==='object'?value:{category_id:value})}));let count=0;for(const item of items){const phrase=normalizeLearning(String(item.phrase||item.key||'')),categoryId=Number(item.category_id||item.categoryId||item.cat_id||item.id);if(!phrase||!categoryId)continue;await pool.query(`INSERT INTO category_learning(phrase,category_id,category_name,hits,updated_at) VALUES($1,$2,$3,$4,now()) ON CONFLICT(phrase,category_id) DO UPDATE SET category_name=EXCLUDED.category_name,hits=GREATEST(category_learning.hits,EXCLUDED.hits),updated_at=now()`,[phrase,categoryId,String(item.category_name||item.categoryName||item.cat_name||item.name||''),Math.max(1,Number(item.hits||item.count||1))]);count++}return count}
export async function listCategoryLearning(limit=1000):Promise<any[]>{const {rows}=await pool.query('SELECT phrase,category_id,category_name,hits,updated_at FROM category_learning ORDER BY hits DESC,updated_at DESC LIMIT $1',[limit]);return rows}
export async function addAutoreplyLog(row:{chatId:number;customer:string;input:string;output:string;source:string}):Promise<void>{await pool.query('INSERT INTO autoreply_log(chat_id,customer,input_text,output_text,source) VALUES($1,$2,$3,$4,$5)',[row.chatId,row.customer,row.input,row.output,row.source])}
export async function importAutoreplyLog(raw:any):Promise<number>{if(!Array.isArray(raw))return 0;let count=0;for(const row of raw.slice(-5000)){const created=row.created_at?new Date(row.created_at):row.at?new Date(Number(row.at)*1000):null;await pool.query('INSERT INTO autoreply_log(chat_id,customer,input_text,output_text,source,created_at) VALUES($1,$2,$3,$4,$5,COALESCE($6,now()))',[Number(row.chat_id||0)||null,String(row.customer||row.who||''),String(row.input_text||row.in||''),String(row.output_text||row.out||''),String(row.source||row.rule||''),created]);count++}return count}
export async function listAutoreplyLog(limit=100):Promise<any[]>{const {rows}=await pool.query('SELECT * FROM autoreply_log ORDER BY created_at DESC LIMIT $1',[limit]);return rows}
function normalizeLearning(value:string){return value.toLowerCase().replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200f\u200e]/g,' ').replace(/[^\p{L}\p{N}\s]/gu,' ').replace(/\s+/g,' ').trim()}

export async function getState<T>(key: string, fallback: T): Promise<T> {
  const { rows } = await pool.query('SELECT value FROM app_state WHERE key=$1', [key]);
  return rows[0]?.value ?? fallback;
}

export async function setState(key: string, value: unknown): Promise<void> {
  await pool.query(`INSERT INTO app_state(key,value,updated_at) VALUES($1,$2,now()) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,updated_at=now()`, [key, JSON.stringify(value)]);
}

export async function createBackup(): Promise<Record<string, unknown>> {
  const [profiles,products,jobs,states,maps,learning,autoreply] = await Promise.all([
    pool.query('SELECT * FROM profiles ORDER BY created_at'),pool.query('SELECT * FROM products ORDER BY profile_id,created_at'),pool.query('SELECT * FROM jobs ORDER BY created_at DESC LIMIT 1000'),pool.query('SELECT * FROM app_state ORDER BY key'),pool.query('SELECT * FROM destination_map ORDER BY profile_id,target,account_key'),pool.query('SELECT * FROM category_learning ORDER BY hits DESC'),pool.query('SELECT * FROM autoreply_log ORDER BY created_at DESC LIMIT 5000')
  ]);
  return {app:'scraper4-render',version:1,createdAt:new Date().toISOString(),profiles:profiles.rows,products:products.rows,jobs:jobs.rows,states:states.rows,destinationMap:maps.rows,categoryLearning:learning.rows,autoreplyLog:autoreply.rows};
}

export async function restoreBackup(bundle: any): Promise<{ profiles: number; products: number; states: number }> {
  if (!bundle || bundle.app !== 'scraper4-render' || bundle.version !== 1) throw new Error('Invalid Scraper 4 Render backup');
  const client = await pool.connect(); let pCount=0, productCount=0, stateCount=0;
  try {
    await client.query('BEGIN');
    for (const row of bundle.profiles || []) { await client.query(`INSERT INTO profiles(id,data,enabled,interval_minutes,last_run_at,created_at,updated_at) VALUES($1,$2,$3,$4,$5,COALESCE($6,now()),now()) ON CONFLICT(id) DO UPDATE SET data=EXCLUDED.data,enabled=EXCLUDED.enabled,interval_minutes=EXCLUDED.interval_minutes,last_run_at=EXCLUDED.last_run_at,updated_at=now()`, [row.id,row.data,row.enabled,row.interval_minutes,row.last_run_at,row.created_at]); pCount++; }
    for (const row of bundle.products || []) { await client.query(`INSERT INTO products(profile_id,source_key,data,title,price,source_url,remote_woo_id,remote_basalam_id,created_at,updated_at,active,missing_since) VALUES($1,$2,$3,$4,$5,$6,$7,$8,COALESCE($9,now()),now(),COALESCE($10,true),$11) ON CONFLICT(profile_id,source_key) DO UPDATE SET data=EXCLUDED.data,title=EXCLUDED.title,price=EXCLUDED.price,source_url=EXCLUDED.source_url,remote_woo_id=EXCLUDED.remote_woo_id,remote_basalam_id=EXCLUDED.remote_basalam_id,active=EXCLUDED.active,missing_since=EXCLUDED.missing_since,updated_at=now()`, [row.profile_id,row.source_key,row.data,row.title,row.price,row.source_url,row.remote_woo_id,row.remote_basalam_id,row.created_at,row.active,row.missing_since]); productCount++; }
    for (const row of bundle.destinationMap || []) { await client.query(`INSERT INTO destination_map(profile_id,source_key,target,account_key,remote_id,updated_at) VALUES($1,$2,$3,$4,$5,now()) ON CONFLICT(profile_id,source_key,target,account_key) DO UPDATE SET remote_id=EXCLUDED.remote_id,updated_at=now()`,[row.profile_id,row.source_key,row.target,row.account_key,row.remote_id]); }
    for(const row of bundle.categoryLearning||[]){await client.query(`INSERT INTO category_learning(phrase,category_id,category_name,hits,updated_at) VALUES($1,$2,$3,$4,now()) ON CONFLICT(phrase,category_id) DO UPDATE SET category_name=EXCLUDED.category_name,hits=EXCLUDED.hits,updated_at=now()`,[row.phrase,row.category_id,row.category_name,row.hits])}
    for(const row of bundle.autoreplyLog||[]){await client.query(`INSERT INTO autoreply_log(chat_id,customer,input_text,output_text,source,created_at) VALUES($1,$2,$3,$4,$5,COALESCE($6,now()))`,[row.chat_id,row.customer,row.input_text,row.output_text,row.source,row.created_at])}
    for (const row of bundle.states || []) { await client.query(`INSERT INTO app_state(key,value,updated_at) VALUES($1,$2,now()) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,updated_at=now()`, [row.key,row.value]); stateCount++; }
    await client.query('COMMIT'); return { profiles:pCount,products:productCount,states:stateCount };
  } catch(error) { await client.query('ROLLBACK'); throw error; } finally { client.release(); }
}

export async function profileStats(): Promise<any[]> {
  const { rows } = await pool.query(`SELECT p.id,p.data->>'name' name,count(pr.*)::int products,count(pr.remote_woo_id)::int woo_mapped,count(pr.remote_basalam_id)::int basalam_mapped,max(pr.updated_at) last_product_at FROM profiles p LEFT JOIN products pr ON pr.profile_id=p.id GROUP BY p.id,p.data ORDER BY name`);
  return rows;
}

export async function reapStalledJobs(minutes = 30): Promise<number> {
  const result = await pool.query(`UPDATE jobs SET status='failed',phase='watchdog',error='Job was inactive and closed by watchdog',finished_at=now(),updated_at=now() WHERE status='running' AND updated_at < now()-make_interval(mins=>$1)`, [Math.max(5,minutes)]);
  return result.rowCount || 0;
}

export async function enqueueDueProfiles(): Promise<number> {
  const { rows } = await pool.query(`SELECT id,data FROM profiles p WHERE enabled=true AND interval_minutes>0
    AND (last_run_at IS NULL OR last_run_at < now() - make_interval(mins => interval_minutes))
    AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.profile_id=p.id AND j.status IN ('queued','running'))`);
  for (const row of rows) {
    const p = row.data as Profile; await createJob(row.id, 'scrape', p.syncWoo && p.syncBasalam ? 'both' : p.syncWoo ? 'woo' : p.syncBasalam ? 'basalam' : 'none');
    await markProfileRun(row.id);
  }
  return rows.length;
}

function jobFromRow(row: any): Job {
  return { id: row.id, profileId: row.profile_id, kind: row.kind, target: row.target, status: row.status, phase: row.phase,
    total: row.total, processed: row.processed, added: row.added, updated: row.updated, failed: row.failed,
    stopRequested: row.stop_requested, error: row.error, log: row.log || [], createdAt: row.created_at.toISOString(),
    startedAt: row.started_at?.toISOString() || null, finishedAt: row.finished_at?.toISOString() || null, updatedAt: row.updated_at.toISOString() };
}
