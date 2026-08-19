import { readFile } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const wrangler = fileURLToPath(new URL('../node_modules/wrangler/bin/wrangler.js', import.meta.url));
const env = { ...process.env, CI: 'true' };
const requiredWorkerName = 'scraper4-cloudflare';
const ciWorkerName = process.env.WRANGLER_CI_OVERRIDE_NAME;
const packageVersion = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8')).version;

if (ciWorkerName && ciWorkerName !== requiredWorkerName) {
  throw new Error(`Workers Builds project name must be "${requiredWorkerName}" (received "${ciWorkerName}"). The automatically provisioned Queue consumer names depend on it.`);
}

function run(args, { capture = false } = {}) {
  return new Promise((resolve, reject) => {
    let output = '';
    const child = spawn(process.execPath, [wrangler, ...args], {
      cwd: root,
      env,
      stdio: capture ? ['ignore', 'pipe', 'pipe'] : 'inherit',
    });
    if (capture) {
      child.stdout.on('data', chunk => { const text = chunk.toString(); output += text; process.stdout.write(text); });
      child.stderr.on('data', chunk => { const text = chunk.toString(); output += text; process.stderr.write(text); });
    }
    child.once('error', reject);
    child.once('exit', (code, signal) => {
      if (code === 0) resolve(output);
      else reject(new Error(`wrangler ${args.join(' ')} failed${signal ? ` with signal ${signal}` : ` with exit code ${code}`}`));
    });
  });
}

function productionUrlFrom(output) {
  const plain = output.replace(/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\))/g, '');
  const urls = plain.match(/https:\/\/[a-z0-9.-]+\.workers\.dev(?:\/[^\s]*)?/gi) || [];
  const url = urls.find(value => new URL(value).hostname.startsWith(`${requiredWorkerName}.`));
  return url ? new URL(url).origin : '';
}

const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

async function smokeRequest(baseUrl, path, validate) {
  let lastError;
  for (let attempt = 1; attempt <= 5; attempt++) {
    try {
      const response = await fetch(`${baseUrl}${path}`, {
        headers: { accept: 'application/json', 'cache-control': 'no-cache' },
        signal: AbortSignal.timeout(20_000),
      });
      const text = await response.text();
      const body = JSON.parse(text);
      if (!response.ok) throw new Error(`HTTP ${response.status}: ${text.slice(0, 300)}`);
      validate(body);
      console.log(`[smoke] ${path} -> HTTP ${response.status}`);
      return body;
    } catch (error) {
      lastError = error;
      if (attempt < 5) await wait(attempt * 2_000);
    }
  }
  throw new Error(`Production smoke test failed for ${path}: ${lastError instanceof Error ? lastError.message : String(lastError)}`);
}

async function smokeProduction(baseUrl) {
  console.log(`\n[3/3] Running production smoke tests against ${baseUrl}...`);
  const health = await smokeRequest(baseUrl, '/health', body => {
    if (body?.ok !== true || body?.databaseReady !== true || body?.workerInWeb !== true || body?.version !== packageVersion) {
      throw new Error(`unexpected health payload: ${JSON.stringify(body)}`);
    }
  });
  const version = await smokeRequest(baseUrl, '/api/version', body => {
    if (body?.ok !== true || body?.version !== packageVersion || body?.runtime !== 'cloudflare-workers') {
      throw new Error(`unexpected version payload: ${JSON.stringify(body)}`);
    }
  });
  const debug = await smokeRequest(baseUrl, '/api/debug', body => {
    if (typeof body?.ok !== 'boolean' || !Array.isArray(body?.checks) || !body.checks.some(check => check?.name === 'd1-schema')) {
      throw new Error(`unexpected debug payload: ${JSON.stringify(body)}`);
    }
  });
  console.log(`[smoke] production verified: version=${version.version}, databaseReady=${health.databaseReady}, diagnostics=${debug.ok ? 'healthy' : 'reported warnings/errors'}`);
}

console.log('\n[1/3] Deploying Worker and automatically provisioning D1, JOBS, and JOBS_DLQ (R2-free mode)...');
const deployOutput = await run(['deploy', '--experimental-provision', '--experimental-auto-create'], { capture: true });
const productionUrl = productionUrlFrom(deployOutput);
if (!productionUrl) throw new Error('Wrangler deployed the Worker but did not report its public workers.dev production URL; production smoke testing cannot continue.');

console.log('\n[2/3] Applying pending D1 migrations to the provisioned DB binding...');
await run(['d1', 'migrations', 'apply', 'DB', '--remote']);
await smokeProduction(productionUrl);

console.log('\nCloudflare deployment, database migrations, and production smoke tests completed successfully.');
