import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const wrangler = fileURLToPath(new URL('../node_modules/wrangler/bin/wrangler.js', import.meta.url));
const env = { ...process.env, CI: 'true' };
const requiredWorkerName = 'scraper4-cloudflare';
const ciWorkerName = process.env.WRANGLER_CI_OVERRIDE_NAME;

if (ciWorkerName && ciWorkerName !== requiredWorkerName) {
  throw new Error(`Workers Builds project name must be "${requiredWorkerName}" (received "${ciWorkerName}"). The automatically provisioned Queue consumer names depend on it.`);
}

function run(args) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [wrangler, ...args], {
      cwd: fileURLToPath(new URL('..', import.meta.url)),
      env,
      stdio: 'inherit',
    });
    child.once('error', reject);
    child.once('exit', (code, signal) => {
      if (code === 0) resolve();
      else reject(new Error(`wrangler ${args.join(' ')} failed${signal ? ` with signal ${signal}` : ` with exit code ${code}`}`));
    });
  });
}

console.log('\n[1/2] Deploying Worker and automatically provisioning D1, R2, JOBS, and JOBS_DLQ...');
await run(['deploy', '--experimental-provision', '--experimental-auto-create']);

console.log('\n[2/2] Applying pending D1 migrations to the provisioned DB binding...');
await run(['d1', 'migrations', 'apply', 'DB', '--remote']);

console.log('\nCloudflare deployment and database migrations completed successfully.');
