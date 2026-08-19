import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import worker from '../scraper4.worker.js';

const ctx={waitUntil() {},passThroughOnException() {}};
const db={prepare:sql=>({sql,first:async()=>({name:'profiles'})}),batch:async()=>[]};
const request=(path,init)=>worker.fetch(new Request(`https://worker.test${path}`,init),{DB:db,ADMIN_TOKEN:'test-secret',WORKER_VERSION:'test'},ctx);

test('health endpoint exposes Worker runtime without secrets',async()=>{
  const response=await request('/health');
  assert.equal(response.status,200);
  const body=await response.json();
  assert.equal(body.ok,true);
  assert.equal(body.runtime,'cloudflare-workers');
  assert.equal(body.databaseReady,true);
  assert.equal(body.authenticated,true);
  assert.equal(JSON.stringify(body).includes('test-secret'),false);
});

test('dashboard assets are served with security headers',async()=>{
  const page=await request('/');
  assert.equal(page.status,200);
  assert.match(await page.text(),/اسکرپر ووکامرس و باسلام/);
  assert.match(page.headers.get('content-security-policy')||'',/default-src 'self'/);
  const script=await request('/dashboard.js');
  assert.equal(script.status,200);
  assert.match(script.headers.get('content-type')||'',/javascript/);
});

test('API requires constant-time bearer authentication',async()=>{
  const missing=await worker.fetch(new Request('https://worker.test/api/parity'),{DB:db,ADMIN_TOKEN:'test-secret'},ctx);
  assert.equal(missing.status,401);
  const wrong=await worker.fetch(new Request('https://worker.test/api/parity',{headers:{authorization:'Bearer wrong'}}),{DB:db,ADMIN_TOKEN:'test-secret'},ctx);
  assert.equal(wrong.status,401);
  const valid=await request('/api/parity',{headers:{authorization:'Bearer test-secret'}});
  assert.equal(valid.status,200);
  const body=await valid.json();
  assert.equal(body.total,57);
  assert.equal(new Set(body.capabilities.map(item=>item.id)).size,57);
});

test('production bundle has no Node-only runtime imports',async()=>{
  const bundle=await readFile(new URL('../scraper4.worker.js',import.meta.url),'utf8');
  assert.doesNotMatch(bundle,/from\s+["'](?:node:|pg|undici|cheerio)/);
  assert.doesNotMatch(bundle,/\brequire\s*\(/);
  assert.match(bundle,/queue\(batch/);
  assert.match(bundle,/scheduled\(/);
});

test('D1 migration and runtime schema remain synchronized',async()=>{
  const source=await readFile(new URL('../worker-src/schema.ts',import.meta.url),'utf8');
  const migration=await readFile(new URL('../migrations/0001_initial.sql',import.meta.url),'utf8');
  const schema=source.slice(source.indexOf('`')+1,source.lastIndexOf('`')).trim();
  assert.equal(migration.replace(/^--[^\n]*\n/,'').trim(),schema);
  for(const table of ['profiles','products','jobs','destination_map','category_learning','autoreply_log','app_state'])assert.match(migration,new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));
});

test('Cloudflare resources are automatically provisioned during deploy',async()=>{
  const config=await readFile(new URL('../wrangler.toml',import.meta.url),'utf8');
  const packageJson=JSON.parse(await readFile(new URL('../package.json',import.meta.url),'utf8'));
  const deployScript=await readFile(new URL('../scripts/deploy-cloudflare.mjs',import.meta.url),'utf8');

  assert.match(config,/name\s*=\s*"scraper4-cloudflare"/);
  assert.match(config,/\[\[d1_databases\]\][\s\S]*?binding\s*=\s*"DB"[\s\S]*?database_name\s*=\s*"scraper4-cloudflare-db"/);
  assert.doesNotMatch(config,/\bdatabase_id\s*=/);
  assert.match(config,/\[\[r2_buckets\]\]\s*\nbinding\s*=\s*"BACKUPS"\s*\nbucket_name\s*=\s*"scraper4-cloudflare-backups"/);
  assert.match(config,/\[\[queues\.producers\]\]\s*\nbinding\s*=\s*"JOBS"\s*\nqueue\s*=\s*"scraper4-cloudflare-jobs"/);
  assert.match(config,/\[\[queues\.producers\]\]\s*\nbinding\s*=\s*"JOBS_DLQ"\s*\nqueue\s*=\s*"scraper4-cloudflare-jobs-dlq"/);
  assert.match(config,/queue\s*=\s*"scraper4-cloudflare-jobs"/);
  assert.match(config,/dead_letter_queue\s*=\s*"scraper4-cloudflare-jobs-dlq"/);
  assert.equal(packageJson.scripts['worker:deploy'],'node scripts/deploy-cloudflare.mjs');
  assert.match(deployScript,/deploy[\s\S]*experimental-provision[\s\S]*d1[\s\S]*migrations[\s\S]*apply[\s\S]*DB[\s\S]*remote/);
  assert.match(deployScript,/10042[\s\S]*R2 Object Storage[\s\S]*do not create a bucket manually[\s\S]*Retry deployment/);
});
