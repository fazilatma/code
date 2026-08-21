export const config = {
  port: Math.max(1, Number(process.env.PORT || 3000)),
  host: '0.0.0.0',
  databaseUrl: process.env.DATABASE_URL || '',
  adminToken: process.env.ADMIN_TOKEN || '',
  runWorkerInWeb: process.env.RUN_WORKER_IN_WEB !== 'false',
  workerPollMs: Math.max(500, Number(process.env.WORKER_POLL_MS || 2000)),
  requestTimeoutMs: Math.max(5_000, Number(process.env.REQUEST_TIMEOUT_MS || 30_000)),
  userAgent: process.env.USER_AGENT || 'Mozilla/5.0 (compatible; Scraper4Render/1.0)',
  woo: {
    url: process.env.WOO_URL || '',
    key: process.env.WOO_KEY || '',
    secret: process.env.WOO_SECRET || ''
  },
  basalam: {
    token: process.env.BASALAM_TOKEN || '',
    vendorId: process.env.BASALAM_VENDOR_ID || '',
    api: (process.env.BASALAM_API || 'https://openapi.basalam.com/v1').replace(/\/$/, '')
  }
};

export function assertConfig(): void {
  if (!config.databaseUrl) throw new Error('DATABASE_URL is required');
  if (!config.adminToken) console.warn('WARNING: ADMIN_TOKEN is empty; the dashboard and API are public.');
}
