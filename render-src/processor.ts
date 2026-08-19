import { allProducts, claimJob, getProfile, markMissingProducts, markProfileRun, stopRequested, updateJob, upsertProduct } from './db.js';
import { mapLimit, pageUrl, scrapeDetails, scrapeList, transformProduct } from './scraper.js';
import { syncBasalam, syncWoo } from './sync.js';
import type { Job, Product } from './types.js';

let stopping = false;
export function requestWorkerStop(): void { stopping = true; }

function append(job: Job, message: string, level = 'info'): void {
  job.log.push({ at: new Date().toISOString(), level, message });
  if (job.log.length > 200) job.log = job.log.slice(-200);
}
async function save(job: Job): Promise<void> { await updateJob(job.id, { status: job.status, phase: job.phase, total: job.total, processed: job.processed, added: job.added, updated: job.updated, failed: job.failed, error: job.error, log: job.log, finishedAt: job.finishedAt }); }

export async function processOneJob(): Promise<boolean> {
  const job = await claimJob(); if (!job) return false;
  try {
    const profile = await getProfile(job.profileId); if (!profile) throw new Error('Profile not found');
    append(job, `شروع ${job.kind === 'scrape' ? 'استخراج' : 'همگام‌سازی'} «${profile.name}»`);
    if (job.kind === 'scrape') {
      job.phase = 'list'; await save(job); const found = new Map<string, Product>();
      for (let page = 1; page <= profile.pages; page++) {
        if (await stopRequested(job.id)) { job.status = 'stopped'; break; }
        const url = pageUrl(profile, page); append(job, `صفحه ${page}: ${url}`);
        const list = await scrapeList(url, profile.selectors, !profile.netIndirect); if (!list.length) { append(job, 'محصولی پیدا نشد', 'warning'); break; }
        for (const raw of list) { const p = transformProduct(raw, profile); if (!profile.minPrice || p.price >= profile.minPrice) found.set(p.sourceKey, p); }
        job.total = found.size; job.processed += list.length; await save(job);
      }
      if (job.status !== 'stopped') {
        job.phase = 'details'; const products = [...found.values()]; await save(job);
        await mapLimit(products, Math.max(1, Number(process.env.DETAIL_CONCURRENCY || 4)), async product => {
          if (await stopRequested(job.id)) return;
          try { await scrapeDetails(product, profile.selectors, !profile.netIndirect); }
          catch (error) { job.failed++; append(job, `${product.title}: ${message(error)}`, 'error'); }
        });
        job.phase = 'save'; await save(job);
        for (const product of products) { const result = await upsertProduct(profile.id, product); result === 'added' ? job.added++ : job.updated++; }
        const retired=await markMissingProducts(profile.id,products.map(p=>p.sourceKey));if(retired)append(job,`${retired} محصول دیگر در مبدأ دیده نشد`,'warning');
        await markProfileRun(profile.id);
        if (job.target !== 'none') await runSync(job, profile, products);
      }
    } else {
      await runSync(job, profile, await allProducts(profile.id));
    }
    if (job.status !== 'stopped') job.status = 'done';
    append(job, job.status === 'done' ? 'عملیات با موفقیت تمام شد' : 'عملیات متوقف شد');
  } catch (error) { job.status = 'failed'; job.error = message(error); append(job, job.error, 'error'); }
  job.finishedAt = new Date().toISOString(); job.phase = 'finished'; await save(job); return true;
}

async function runSync(job: Job, profile: Awaited<ReturnType<typeof getProfile>> & {}, products: Product[]): Promise<void> {
  job.phase = 'sync'; job.total = products.length; job.processed = 0; await save(job);
  for (const product of products) {
    if (await stopRequested(job.id)) { job.status = 'stopped'; return; }
    try {
      if (job.target === 'woo' || job.target === 'both') await syncWoo(product, profile);
      if (job.target === 'basalam' || job.target === 'both') await syncBasalam(product, profile);
    } catch (error) { job.failed++; append(job, `${product.title}: ${message(error)}`, 'error'); }
    job.processed++; if (job.processed % 5 === 0) await save(job);
  }
}

export async function workerLoop(pollMs: number): Promise<void> {
  console.log(`Scraper worker started; poll=${pollMs}ms`);
  while (!stopping) {
    try { if (!await processOneJob()) await sleep(pollMs); }
    catch (error) { console.error('Worker loop error', error); await sleep(Math.max(2000, pollMs)); }
  }
  console.log('Scraper worker stopped');
}
const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));
const message = (error: unknown) => error instanceof Error ? error.message : String(error);
