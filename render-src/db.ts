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
    ON CONFLICT(profile_id,source_key) DO UPDATE SET data=EXCLUDED.data,title=EXCLUDED.title,price=EXCLUDED.price,source_url=EXCLUDED.source_url,updated_at=now()`,
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

export async function setRemoteId(profileId: string, sourceKey: string, target: 'woo'|'basalam', id: number): Promise<void> {
  const column = target === 'woo' ? 'remote_woo_id' : 'remote_basalam_id';
  await pool.query(`UPDATE products SET ${column}=$3,updated_at=now() WHERE profile_id=$1 AND source_key=$2`, [profileId, sourceKey, id]);
}

export async function getRemoteId(profileId: string, sourceKey: string, target: 'woo'|'basalam'): Promise<number | null> {
  const column = target === 'woo' ? 'remote_woo_id' : 'remote_basalam_id';
  const { rows } = await pool.query(`SELECT ${column} id FROM products WHERE profile_id=$1 AND source_key=$2`, [profileId, sourceKey]);
  return rows[0]?.id ? Number(rows[0].id) : null;
}

export async function markProfileRun(id: string): Promise<void> { await pool.query('UPDATE profiles SET last_run_at=now() WHERE id=$1', [id]); }

export async function getState<T>(key: string, fallback: T): Promise<T> {
  const { rows } = await pool.query('SELECT value FROM app_state WHERE key=$1', [key]);
  return rows[0]?.value ?? fallback;
}

export async function setState(key: string, value: unknown): Promise<void> {
  await pool.query(`INSERT INTO app_state(key,value,updated_at) VALUES($1,$2,now()) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,updated_at=now()`, [key, JSON.stringify(value)]);
}

export async function createBackup(): Promise<Record<string, unknown>> {
  const [profiles, products, jobs, states] = await Promise.all([
    pool.query('SELECT * FROM profiles ORDER BY created_at'), pool.query('SELECT * FROM products ORDER BY profile_id,created_at'),
    pool.query('SELECT * FROM jobs ORDER BY created_at DESC LIMIT 1000'), pool.query('SELECT * FROM app_state ORDER BY key')
  ]);
  return { app: 'scraper4-render', version: 1, createdAt: new Date().toISOString(), profiles: profiles.rows, products: products.rows, jobs: jobs.rows, states: states.rows };
}

export async function restoreBackup(bundle: any): Promise<{ profiles: number; products: number; states: number }> {
  if (!bundle || bundle.app !== 'scraper4-render' || bundle.version !== 1) throw new Error('Invalid Scraper 4 Render backup');
  const client = await pool.connect(); let pCount=0, productCount=0, stateCount=0;
  try {
    await client.query('BEGIN');
    for (const row of bundle.profiles || []) { await client.query(`INSERT INTO profiles(id,data,enabled,interval_minutes,last_run_at,created_at,updated_at) VALUES($1,$2,$3,$4,$5,COALESCE($6,now()),now()) ON CONFLICT(id) DO UPDATE SET data=EXCLUDED.data,enabled=EXCLUDED.enabled,interval_minutes=EXCLUDED.interval_minutes,last_run_at=EXCLUDED.last_run_at,updated_at=now()`, [row.id,row.data,row.enabled,row.interval_minutes,row.last_run_at,row.created_at]); pCount++; }
    for (const row of bundle.products || []) { await client.query(`INSERT INTO products(profile_id,source_key,data,title,price,source_url,remote_woo_id,remote_basalam_id,created_at,updated_at) VALUES($1,$2,$3,$4,$5,$6,$7,$8,COALESCE($9,now()),now()) ON CONFLICT(profile_id,source_key) DO UPDATE SET data=EXCLUDED.data,title=EXCLUDED.title,price=EXCLUDED.price,source_url=EXCLUDED.source_url,remote_woo_id=EXCLUDED.remote_woo_id,remote_basalam_id=EXCLUDED.remote_basalam_id,updated_at=now()`, [row.profile_id,row.source_key,row.data,row.title,row.price,row.source_url,row.remote_woo_id,row.remote_basalam_id,row.created_at]); productCount++; }
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
