import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const read=path=>readFile(path,'utf8');

test('PHP hamburger inventory contains exactly 57 unique operations',async()=>{const source=await read('render-src/parity.ts'),ids=[...source.matchAll(/\{id:'([^']+)'/g)].map(x=>x[1]);assert.equal(ids.length,57);assert.equal(new Set(ids).size,57)});
test('every operational capability declares an endpoint or implementation note',async()=>{const source=await read('render-src/parity.ts'),rows=[...source.matchAll(/\{id:'([^']+)'[^\n]+status:'operational'([^\n]+)\}/g)];assert.ok(rows.length>40);for(const row of rows)assert.match(row[2],/endpoint:|note:|\}/)});
test('dashboard JavaScript parses and required element references exist',async()=>{const source=await read('render-src/dashboard.ts'),match=source.match(/export const DASHBOARD_JS = String\.raw`\n([\s\S]*?)\n`;/);assert.ok(match);new vm.Script(match[1]);const refs=[...match[1].matchAll(/\$\('([^']+)'\)/g)].map(x=>x[1]);for(const id of new Set(refs)){const dynamic=id.match(/^(?:sel|result|wrap)-(.+)$/);assert.ok(source.includes(`id=\\"${id}\\"`)||source.includes(`id="${id}"`)||(dynamic&&source.includes(`['${dynamic[1]}'`)),`missing #${id}`)}});
test('destructive maintenance endpoints require explicit confirmation',async()=>{const source=await read('render-src/server.ts');assert.match(source,/body\.confirm==='APPLY'/);assert.match(source,/\.confirm!=='SEND'/)});
test('source tree does not contain committed live credential assignments',async()=>{const files=['render-src/vault.ts','render-src/config.ts','render-src/dashboard.ts','render-src/server.ts'];for(const file of files){const source=await read(file);assert.doesNotMatch(source,/(?:apiKey|token|secret)\s*[:=]\s*['"][A-Za-z0-9_-]{20,}['"]/i,file)}});
test('all required Render subsystems are represented',async()=>{const source=await read('RENDER-PARITY.md');for(const term of ['خزانه','مغایرت','ویرایش گروهی','یادگیری دسته‌بندی','پاسخ خودکار','گزارش شبانه'])assert.ok(source.includes(term),term)});
test('application version is synchronized with package.json',async()=>{const source=await read('render-src/version.ts'),pkg=JSON.parse(await read('package.json')),version=source.match(/APP_VERSION = '([^']+)'/)?.[1];assert.equal(version,pkg.version);assert.match(source,/APP_BUILD_DATE/)});
test('legacy scraper4.php compatibility route is authenticated and fails explicitly',async()=>{const source=await read('render-src/server.ts');assert.match(source,/app\.use\('\/scraper4\.php'/);assert.match(source,/app\.all\('\/scraper4\.php',legacyHandler\)/);assert.match(source,/Legacy endpoint not ported/);for(const key of ['profiles','cron_run','backup_export','bsl_products','ai_test_all','ar_run','recon','photo_fix','suffix_report','gallery_suggest','image_proxy'])assert.ok(source.includes(`has('${key}')`),key)});
