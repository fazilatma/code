import { build } from 'esbuild';
import { rm, mkdir } from 'node:fs/promises';

await rm('render-dist', { recursive: true, force: true });
await mkdir('render-dist', { recursive: true });

await build({
  entryPoints: {
    server: 'render-src/server.ts',
    worker: 'render-src/worker.ts',
    cron: 'render-src/cron.ts'
  },
  outdir: 'render-dist',
  bundle: true,
  platform: 'node',
  format: 'esm',
  target: 'node20',
  packages: 'external',
  sourcemap: true,
  minify: false,
  logLevel: 'info'
});
