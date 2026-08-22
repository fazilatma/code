import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import worker from '../scraper4.worker.js';

const ctx={waitUntil() {},passThroughOnException() {}};
const db={prepare:sql=>({sql,first:async()=>({name:'profiles'})}),batch:async()=>[]};
const request=(path,init)=>worker.fetch(new Request(`https://worker.test${path}`,init),{DB:db,VAULT_SECRET:'vault-secret',WORKER_VERSION:'test'},ctx);

test('health endpoint exposes Worker runtime without secrets',async()=>{
  const response=await request('/health');
  assert.equal(response.status,200);
  const body=await response.json();
  assert.equal(body.ok,true);
  assert.equal(body.runtime,'cloudflare-workers');
  assert.equal(body.databaseReady,true);
  assert.equal(body.authenticationRequired,false);
  assert.equal(JSON.stringify(body).includes('vault-secret'),false);
});

test('dashboard assets are served with security headers',async()=>{
  const page=await request('/');
  assert.equal(page.status,200);
  const html=await page.text();
  assert.match(html,/اسکرپر ووکامرس و باسلام/);
  assert.doesNotMatch(html,/id="token"|ADMIN_TOKEN/);
  assert.match(page.headers.get('content-security-policy')||'',/default-src 'self'/);
  const script=await request('/dashboard.js');
  assert.equal(script.status,200);
  assert.match(script.headers.get('content-type')||'',/javascript/);
  assert.doesNotMatch(await script.text(),/authorization\s*:\s*['"]Bearer|s4rt|connectBtn/);
});

test('API is directly accessible without an admin token',async()=>{
  const withoutToken=await worker.fetch(new Request('https://worker.test/api/parity'),{DB:db,VAULT_SECRET:'vault-secret'},ctx);
  assert.equal(withoutToken.status,200);
  const arbitraryHeader=await worker.fetch(new Request('https://worker.test/api/parity',{headers:{authorization:'Bearer ignored'}}),{DB:db,ADMIN_TOKEN:'different-secret',VAULT_SECRET:'vault-secret'},ctx);
  assert.equal(arbitraryHeader.status,200);
  const body=await withoutToken.json();
  assert.equal(body.total,57);
  assert.equal(new Set(body.capabilities.map(item=>item.id)).size,57);
});

test('production bundle has no Node-only runtime imports',async()=>{
  const bundle=await readFile(new URL('../scraper4.worker.js',import.meta.url),'utf8');
  assert.doesNotMatch(bundle,/from\s+["'](?:node:|pg|undici|cheerio)/);
  assert.doesNotMatch(bundle,/\brequire\s*\(/);
  assert.doesNotMatch(bundle,/wrangler secret put/);
  assert.match(bundle,/Variables and Secrets/);
  assert.match(bundle,/queue\(batch/);
  assert.match(bundle,/scheduled\(/);
});

test('D1 migration and runtime schema remain synchronized',async()=>{
  const source=await readFile(new URL('../worker-src/schema.ts',import.meta.url),'utf8');
  const {readdir}=await import('node:fs/promises');
  const files=(await readdir(new URL('../migrations',import.meta.url))).filter(name=>/^\d+.*\.sql$/.test(name)).sort();
  assert.ok(files.length>=2,'every schema addition ships as a new numbered migration');
  const migration=(await Promise.all(files.map(name=>readFile(new URL(`../migrations/${name}`,import.meta.url),'utf8')))).join('\n');
  const schema=source.slice(source.indexOf('`')+1,source.lastIndexOf('`')).trim();
  assert.equal(migration.replace(/^--[^\n]*\n/gm,'').replace(/\n{2,}/g,'\n').trim(),schema);
  for(const table of ['profiles','products','jobs','destination_map','category_learning','autoreply_log','app_state','agent_prompts','agent_runs'])assert.match(migration,new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\b`));
});

test('Cloudflare resources are automatically provisioned during deploy',async()=>{
  const config=await readFile(new URL('../wrangler.toml',import.meta.url),'utf8');
  const packageJson=JSON.parse(await readFile(new URL('../package.json',import.meta.url),'utf8'));
  const deployScript=await readFile(new URL('../scripts/deploy-cloudflare.mjs',import.meta.url),'utf8');

  assert.match(config,/name\s*=\s*"scraper4-cloudflare"/);
  assert.match(config,/\[\[d1_databases\]\][\s\S]*?binding\s*=\s*"DB"[\s\S]*?database_name\s*=\s*"scraper4-cloudflare-db"/);
  assert.doesNotMatch(config,/\bdatabase_id\s*=/);
  assert.doesNotMatch(config,/\[\[r2_buckets\]\]/);
  assert.match(config,/\[\[queues\.producers\]\]\s*\nbinding\s*=\s*"JOBS"\s*\nqueue\s*=\s*"scraper4-cloudflare-jobs"/);
  assert.match(config,/\[\[queues\.producers\]\]\s*\nbinding\s*=\s*"JOBS_DLQ"\s*\nqueue\s*=\s*"scraper4-cloudflare-jobs-dlq"/);
  assert.match(config,/queue\s*=\s*"scraper4-cloudflare-jobs"/);
  assert.match(config,/dead_letter_queue\s*=\s*"scraper4-cloudflare-jobs-dlq"/);
  assert.match(config,/crons\s*=\s*\[\s*"\* \* \* \* \*"\s*\]/);
  assert.match(config,/WORKER_VERSION\s*=\s*"1.18.1"/);
  assert.equal(packageJson.scripts['worker:deploy'],'node scripts/deploy-cloudflare.mjs');
  assert.match(deployScript,/R2-free mode[\s\S]*deploy[\s\S]*experimental-provision[\s\S]*d1[\s\S]*migrations[\s\S]*apply[\s\S]*DB[\s\S]*remote/);
  assert.doesNotMatch(deployScript,/10042|enable R2/i);
});
