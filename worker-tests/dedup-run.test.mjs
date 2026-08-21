import assert from 'node:assert/strict';
import {mkdtemp} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import test from 'node:test';
import {build} from 'esbuild';

/**
 * End-to-end state machine test for the server-side dedup run: listing pages, grouping,
 * chunked removal and safe stop all happen through processBackgroundMessage exactly like
 * Queue deliveries in production. Destination/network modules are stubbed in memory.
 */
const harness=globalThis.__dedupHarness={pages:[],statusChanges:[],states:new Map(),stateUpdatedAt:new Map()};

const stubs={
  './maintenance.js':`const h=globalThis.__dedupHarness;
    export async function destinationCatalog(target,query){const page=h.pages[(query.page||1)-1]||{products:[]};return{ok:true,target,products:page.products,totalPages:h.pages.length||1,total:0}}
    export async function destinationChangeStatus(target,id,status,shopId=''){h.statusChanges.push({target,id,status:String(status),shopId});return{ok:true,id,status}}
    export async function destinationCategories(){return{items:[],cached:true,updatedAt:''}}
    export async function applyBasalamCategory(){return{ok:true}}`,
  './connections.js':`export async function loadConnections(){return{woo:{url:'https://shop.example',key:'k',secret:'s'},basalam:{token:'t',vendorId:'10',api:'https://core.basalam.com/v3',shops:[]},ai:{providers:[],candidates:[],master:'',model:''}}}`,
  './db.js':`const h=globalThis.__dedupHarness;
    export async function getState(key,fallback){return h.states.has(key)?JSON.parse(h.states.get(key)):fallback}
    export async function setState(key,value){h.states.set(key,JSON.stringify(value));h.stateUpdatedAt.set(key,new Date().toISOString())}
    export async function deleteState(key){h.states.delete(key);h.stateUpdatedAt.delete(key)}`,
  './env.js':`const h=globalThis.__dedupHarness;
    class Stmt{constructor(sql){this.sql=sql.replace(/\\s+/g,' ').trim();this.values=[]}
      bind(...values){this.values=values;return this}
      async first(){if(this.sql.startsWith('SELECT value FROM app_state WHERE key=')){const v=h.states.get(this.values[0]);return v===undefined?null:{value:v}}return null}
      async run(){const v=this.values;
        if(this.sql.startsWith('INSERT INTO app_state')){const lease=this.sql.includes('WHERE app_state.updated_at<?'),cur=h.states.get(v[0]),curAt=h.stateUpdatedAt.get(v[0])||'';if(!lease||cur===undefined||curAt<v[3]){h.states.set(v[0],v[1]);h.stateUpdatedAt.set(v[0],v[2])}return{success:true}}
        if(this.sql.startsWith('DELETE FROM app_state WHERE key=? AND value=?')){if(h.states.get(v[0])===v[1]){h.states.delete(v[0]);h.stateUpdatedAt.delete(v[0])}return{success:true}}
        return{success:true}}}
    export function getEnv(){return{DB:{prepare:sql=>new Stmt(sql)},JOBS:null,AI_TEST_TIMEOUT_MS:'',AI_TEST_MODEL_BUDGET_MS:''}}
    export function configureEnv(){}`,
  './ai.js':`export async function getLastAiTestResults(){return null}
    export function isChatCompatibleAiModel(){return true}
    export function isRetryableAiResult(){return false}
    export function nextAiTestBatch(){return{batch:[]}}
    export async function suggestCategoryWithModel(){return{ok:false}}
    export async function testModelBatch(){return{done:true,results:[],batchResults:[],total:0,nextCursor:0}}`,
};
const stubPlugin={name:'dedup-stubs',setup(pluginBuild){
  pluginBuild.onResolve({filter:/^\.\/(maintenance|connections|db|env|ai)\.js$/},args=>({path:args.path,namespace:'dedup-stub'}));
  pluginBuild.onLoad({filter:/.*/,namespace:'dedup-stub'},args=>({contents:stubs[args.path],loader:'js'}));
}};

const temporary=await mkdtemp(join(tmpdir(),'scraper4-dedup-run-'));
await build({entryPoints:{background:new URL('../worker-src/background.ts',import.meta.url).pathname},bundle:true,format:'esm',platform:'browser',target:'es2022',outdir:temporary,entryNames:'[name]',outExtension:{'.js':'.mjs'},plugins:[stubPlugin]});
const background=await import(pathToFileURL(join(temporary,'background.mjs')));

const product=(id,name,price,date,status='publish',shopId='default')=>({id,shopId,name,title:name,price,sku:'',status,raw:{date_created:date}});
/** Captures the inline drain promise like ctx.waitUntil would, so tests can await it deterministically. */
const waitUntil=promise=>{harness.pending=promise};
async function settle(){if(harness.pending){await harness.pending;harness.pending=null}}
const readRun=runId=>JSON.parse(harness.states.get('background_run:dedup:'+runId));
async function pump(runId,limit=100){
  await settle();
  for(let i=0;i<limit;i++){
    const run=readRun(runId);
    if(['done','failed','paused'].includes(run.status))return run;
    await background.processBackgroundMessage({task:'dedup',runId});
  }
  throw new Error('dedup run did not finish within the pump budget');
}

test('server-side dedup run lists pages, groups by suffix-free name and removes losers in chunks',async()=>{
  harness.states.clear();harness.stateUpdatedAt.clear();harness.statusChanges.length=0;
  harness.pages=[
    {products:[product(1,'عطر گل محمدی (کد:1)',90,'2026-01-01T00:00:00Z'),product(2,'عطر گل محمدی #22',70,'2026-02-01T00:00:00Z'),product(3,'کیف چرم',50,'2026-01-05T00:00:00Z'),product(9,'محصول در زباله‌دان',10,'2026-01-01T00:00:00Z','trash')]},
    {products:[product(4,'عطر گل محمدی',80,'2026-03-01T00:00:00Z'),product(5,'کیف چرم (کد:۴۵)',40,'2026-04-01T00:00:00Z'),product(6,'شمع دست‌ساز',30,'2026-04-02T00:00:00Z')]},
  ];
  const started=await background.startDedupRun('woo',{keep:'cheapest',suffixFormats:'(کد:x)، #x',apply:true},waitUntil);
  assert.equal(started.existing,false);
  const runId=started.run.id;
  await pump(runId);
  const run=await background.getPublicBackgroundRun('dedup');
  assert.equal(run.status,'done');assert.equal(run.phase,'finished');
  assert.equal(run.scanned,6,'trashed products are excluded from the scan');
  assert.equal(run.groupsFound,2);assert.equal(run.duplicates,3);
  assert.equal(run.removed,3);assert.equal(run.failed,0);
  // cheapest wins: perfume -> id 2 (70) survives, bag -> id 5 (40) survives.
  const removedIds=harness.statusChanges.map(x=>x.id).sort((a,b)=>a-b);
  assert.deepEqual(removedIds,[1,3,4]);
  assert.ok(harness.statusChanges.every(x=>x.target==='woo'&&x.status==='trash'),'WooCommerce losers go to the reversible trash status');
  const groupTitles=run.groups.map(g=>g.keep.id).sort((a,b)=>a-b);
  assert.deepEqual(groupTitles,[2,5]);
});

test('preview mode reports duplicates without touching the destination and basalam archives with 4184',async()=>{
  await background.resetBackgroundRun('dedup');
  harness.statusChanges.length=0;
  harness.pages=[{products:[product(11,'شال نخی',100,'2026-01-01T00:00:00Z','2976','700'),product(12,'شال نخی (کد:9)',120,'2026-05-01T00:00:00Z','2976','700'),product(13,'شال نخی',100,'2026-02-01T00:00:00Z','2976','701')]}];
  const preview=await background.startDedupRun('basalam',{keep:'newest',apply:false},waitUntil);
  await pump(preview.run.id);
  let run=await background.getPublicBackgroundRun('dedup');
  assert.equal(run.status,'done');
  assert.equal(run.duplicates,1,'same-named products in different shops are not merged');
  assert.equal(run.removed,0);assert.deepEqual(harness.statusChanges,[],'preview must not change the destination');
  assert.equal(run.groups[0].keep.id,12,'newest survives');
  // Now apply for real: the loser is archived with the official Basalam status.
  await background.resetBackgroundRun('dedup');
  const applied=await background.startDedupRun('basalam',{keep:'newest',apply:true},waitUntil);
  await pump(applied.run.id);
  run=await background.getPublicBackgroundRun('dedup');
  assert.equal(run.removed,1);
  assert.deepEqual(harness.statusChanges,[{target:'basalam',id:11,status:'4184',shopId:'700'}]);
});

test('stop pauses at a checkpoint and resume continues from the same cursor',async()=>{
  await background.resetBackgroundRun('dedup');
  harness.statusChanges.length=0;
  // 30 same-named copies: the inline drain (5 passes = listing + grouping + 3 removal chunks of 4)
  // cannot finish, leaving a mid-run checkpoint to stop at.
  harness.pages=[{products:Array.from({length:30},(_,i)=>product(100+i,'محصول تکراری #'+(i+1),50+i,'2026-01-0'+((i%8)+1)+'T00:00:00Z'))}];
  const started=await background.startDedupRun('woo',{keep:'oldest',apply:true},waitUntil);
  const runId=started.run.id;
  await settle();
  assert.equal(harness.statusChanges.length,12,'each removal chunk archives at most 4 duplicates');
  assert.equal(readRun(runId).status,'queued');
  await background.controlBackgroundRun('dedup','stop');
  const paused=await background.getPublicBackgroundRun('dedup');
  assert.equal(paused.status,'paused');
  assert.equal((await background.processBackgroundMessage({task:'dedup',runId})).outcome,'ignored','paused runs ignore late deliveries');
  assert.equal(harness.statusChanges.length,12,'no removal happens while paused');
  await background.controlBackgroundRun('dedup','resume',waitUntil);
  const run=await pump(runId);
  assert.equal(run.status,'done');
  assert.equal(run.removed,29,'29 of 30 same-named copies are removed, the oldest stays');
  assert.equal(harness.statusChanges.length,29);
  assert.ok(!harness.statusChanges.some(x=>x.id===100),'the oldest copy (smallest id on equal dates) survives');
});
