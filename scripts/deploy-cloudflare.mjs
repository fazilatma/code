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
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    const output = [];
    const mirror = (stream, target) => stream.on('data', chunk => {
      output.push(Buffer.from(chunk));
      target.write(chunk);
    });
    mirror(child.stdout, process.stdout);
    mirror(child.stderr, process.stderr);
    child.once('error', reject);
    child.once('exit', (code, signal) => {
      if (code === 0) resolve();
      else {
        const error = new Error(`wrangler ${args.join(' ')} failed${signal ? ` with signal ${signal}` : ` with exit code ${code}`}`);
        error.output = Buffer.concat(output).toString('utf8');
        reject(error);
      }
    });
  });
}

console.log('\n[1/2] Deploying Worker and automatically provisioning D1, R2, JOBS, and JOBS_DLQ...');
try {
  await run(['deploy', '--experimental-provision', '--experimental-auto-create']);
} catch (error) {
  if (/\b10042\b|please enable R2/i.test(error.output ?? '')) {
    throw new Error(
      'R2 Object Storage is not enabled for this Cloudflare account. In the Cloudflare Dashboard, open R2 Object Storage and enable the service, but do not create a bucket manually. Then use Retry deployment; Wrangler will create and bind scraper4-cloudflare-backups automatically.',
      { cause: error },
    );
  }
  throw error;
}

console.log('\n[2/2] Applying pending D1 migrations to the provisioned DB binding...');
await run(['d1', 'migrations', 'apply', 'DB', '--remote']);

console.log('\nCloudflare deployment and database migrations completed successfully.');
