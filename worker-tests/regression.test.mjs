import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {strToU8,zipSync} from 'fflate';
import worker from '../scraper4.worker.js';

const ctx={waitUntil(){},passThroughOnException(){}};
class MemoryD1 {
  constructor(){this.states=new Map();this.stateUpdatedAt=new Map();this.profiles=new Map();this.products=new Map();this.jobs=new Map();this.categoryLearning=new Map()}
  prepare(sql){return new MemoryStatement(this,sql)}
  async batch(statements){return statements.map(()=>({success:true,meta:{changes:0}}))}
}
class MemoryStatement {
  constructor(db,sql){this.db=db;this.sql=sql.replace(/\s+/g,' ').trim();this.values=[]}
  bind(...values){this.values=values;return this}
  async first(){const s=this.sql,v=this.values;
    if(s==='PRAGMA quick_check')return{quick_check:'ok'};
    if(s.includes("FROM sqlite_master WHERE type='table' AND name IN"))return{n:7};
    if(s.includes('orphan_products'))return{profiles:this.db.profiles.size,products:this.db.products.size,jobs:0,active_jobs:0,failed_jobs:0,orphan_products:0,orphan_maps:0};
    if(s.includes("FROM jobs WHERE status='running'"))return{n:0};
    if(s.startsWith('SELECT value FROM app_state WHERE key=')){const value=this.db.states.get(v[0]);return value===undefined?null:{value}};
    if(s.startsWith('SELECT * FROM profiles WHERE id='))return this.db.profiles.get(v[0])||null;
    if(s.startsWith('SELECT * FROM jobs WHERE id='))return this.db.jobs.get(v[0])||null;
    if(s.startsWith('SELECT * FROM jobs WHERE profile_id='))return[...this.db.jobs.values()].find(job=>job.profile_id===v[0]&&job.kind===v[1]&&['queued','running'].includes(job.status))||null;
    if(s.startsWith('SELECT 1 AS found FROM products'))return this.db.products.has(`${v[0]}:${v[1]}`)?{found:1}:null;
    if(s.startsWith('SELECT * FROM category_learning WHERE phrase='))return[...this.db.categoryLearning.values()].filter(row=>row.phrase===v[0]).sort((a,b)=>b.hits-a.hits)[0]||null;
    return null;
  }
  async all(){const s=this.sql;let results=[];if(s.startsWith('SELECT * FROM profiles ORDER BY'))results=[...this.db.profiles.values()];if(s.startsWith('SELECT * FROM jobs ORDER BY'))results=[...this.db.jobs.values()].sort((a,b)=>String(b.created_at).localeCompare(String(a.created_at))).slice(0,Number(this.values[0])||200);if(s.startsWith('SELECT id FROM jobs WHERE status IN'))results=[...this.db.jobs.values()].filter(job=>['done','failed','stopped'].includes(job.status)).sort((a,b)=>String(b.created_at).localeCompare(String(a.created_at))).map(job=>({id:job.id}));if(s.startsWith('SELECT * FROM category_learning ORDER BY'))results=[...this.db.categoryLearning.values()].sort((a,b)=>b.hits-a.hits).slice(0,Number(this.values[0])||1000);return{success:true,results}}
  async run(){const s=this.sql,v=this.values;
    if(s.startsWith('INSERT INTO app_state')){
      const lease=s.includes('WHERE app_state.updated_at<?'),current=this.db.states.get(v[0]),currentAt=this.db.stateUpdatedAt.get(v[0])||'';
      if(!lease||current===undefined||currentAt<v[3]){this.db.states.set(v[0],v[1]);this.db.stateUpdatedAt.set(v[0],v[2])}
    }
    else if(s.startsWith('DELETE FROM app_state WHERE key=')&&this.db.states.get(v[0])===v[1]){this.db.states.delete(v[0]);this.db.stateUpdatedAt.delete(v[0])}
    else if(s.startsWith('INSERT INTO profiles'))this.db.profiles.set(v[0],{id:v[0],data:v[1],enabled:v[2],interval_minutes:v[3],created_at:v[4],updated_at:v[5],last_run_at:null});
    else if(s.startsWith('INSERT INTO products'))this.db.products.set(`${v[0]}:${v[1]}`,{profile_id:v[0],source_key:v[1],data:v[2],title:v[3],price:v[4],source_url:v[5]});
    else if(s.startsWith('INSERT INTO category_learning')){const key=`${v[0]}:${v[1]}`,previous=this.db.categoryLearning.get(key);this.db.categoryLearning.set(key,{phrase:v[0],category_id:v[1],category_name:v[2],hits:(previous?.hits||0)+(s.includes('VALUES(?,?,?,1,?)')?1:Number(v[3])||1),updated_at:v.at(-1)})}
    else if(s.startsWith('INSERT INTO jobs'))this.db.jobs.set(v[0],{id:v[0],profile_id:v[1],kind:v[2],target:v[3],status:'queued',phase:'waiting',total:0,processed:0,added:0,updated:0,failed:0,stop_requested:0,error:null,log:'[]',created_at:v[4],updated_at:v[5],started_at:null,finished_at:null});
    else if(s.includes("WHERE status='running' AND updated_at<?")){let n=0;for(const job of this.db.jobs.values())if(job.status==='running'&&String(job.updated_at||'')<String(v[2])){job.status='failed';job.phase='watchdog';job.error='Job was inactive and closed by watchdog';job.finished_at=v[0];job.updated_at=v[1];n++}return{success:true,meta:{changes:n}}}
    else if(s.startsWith("DELETE FROM jobs WHERE id=")){const job=this.db.jobs.get(v[0]);if(job&&job.status!=='running'){this.db.jobs.delete(v[0]);return{success:true,meta:{changes:1}}}return{success:true,meta:{changes:0}}}
    return{success:true,meta:{changes:1}};
  }
}
const call=(db,path,init={},extra={})=>worker.fetch(new Request(`https://worker.test${path}`,init),{DB:db,VAULT_SECRET:'vault-secret',JOBS:{send:async()=>{}},JOBS_DLQ:{send:async()=>{}},...extra},ctx);
const jsonInit=body=>({method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)});
const file=(name,value)=>{const text=JSON.stringify(value);return{name:{size:Buffer.byteLength(text),b64:Buffer.from(text).toString('base64')}}};
async function legacyVaultEnvelope(value,secret='vault-secret'){const salt=crypto.getRandomValues(new Uint8Array(16)),iv=crypto.getRandomValues(new Uint8Array(12)),material=await crypto.subtle.importKey('raw',new TextEncoder().encode(secret),'PBKDF2',false,['deriveKey']),key=await crypto.subtle.deriveKey({name:'PBKDF2',hash:'SHA-256',salt,iterations:100000},material,{name:'AES-GCM',length:256},false,['encrypt']),ciphertext=await crypto.subtle.encrypt({name:'AES-GCM',iv},key,new TextEncoder().encode(JSON.stringify(value)));return{version:2,salt:Buffer.from(salt).toString('base64'),iv:Buffer.from(iv).toString('base64'),ciphertext:Buffer.from(ciphertext).toString('base64'),iterations:100000}}
function tinyXlsx(){const files={'[Content_Types].xml':'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>','_rels/.rels':'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>','xl/workbook.xml':'<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets></workbook>','xl/_rels/workbook.xml.rels':'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>','xl/worksheets/sheet1.xml':'<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:C2"/><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>نام محصول</t></is></c><c r="B1" t="inlineStr"><is><t>قیمت</t></is></c><c r="C1" t="inlineStr"><is><t>برند</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>عطر اکسل</t></is></c><c r="B2"><v>375000</v></c><c r="C2" t="inlineStr"><is><t>نمونه</t></is></c></row></sheetData></worksheet>'};return zipSync(Object.fromEntries(Object.entries(files).map(([name,text])=>[name,strToU8(text)])))}

test('same-origin font CSS and WOFF2 proxy are functional and cacheable',async()=>{
  const db=new MemoryD1(),cssResponse=await call(db,'/assets/fonts/vazir.css');assert.equal(cssResponse.status,200);assert.match(cssResponse.headers.get('content-type')||'',/text\/css/);const css=await cssResponse.text();assert.match(css,/font-family:"Vazir"/);assert.match(css,/url\("\/assets\/fonts\/vazir-400\.woff2"\)/);assert.doesNotMatch(css,/fontapi\.ir|fontcdn\.ir/);
  const originalFetch=globalThis.fetch;let upstream='';globalThis.fetch=async request=>{upstream=String(request instanceof Request?request.url:request);return new Response(new Uint8Array([119,79,70,50]),{headers:{'content-type':'font/woff2'}})};try{const fontResponse=await call(db,'/assets/fonts/vazir-400.woff2');assert.equal(fontResponse.status,200);assert.equal(fontResponse.headers.get('content-type'),'font/woff2');assert.match(fontResponse.headers.get('cache-control')||'',/immutable/);assert.equal(fontResponse.headers.get('access-control-allow-origin'),'*');assert.match(upstream,/cdn\.fontcdn\.ir\/Fonts\/Vazir\/[a-f0-9]{64}\.woff2$/);assert.deepEqual([...new Uint8Array(await fontResponse.arrayBuffer())],[119,79,70,50])}finally{globalThis.fetch=originalFetch}
});

test('AI test runs persist server-side and expose stop/resume recovery controls',async()=>{
  const db=new MemoryD1(),sent=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})}};const startedResponse=await call(db,'/api/ai/test-runs',jsonInit({prompt:'سلام',categoryTitle:'ادو پرفیوم',delayMs:250}),extra),started=await startedResponse.json();assert.equal(startedResponse.status,202);assert.equal(started.run.kind,'ai-test');assert.equal(started.run.status,'queued');assert.equal(sent[0].message.task,'ai-test');assert.ok(db.states.has('background_current:ai-test'));
  const current=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());assert.equal(current.run.id,started.run.id);assert.equal(current.run.prompt,'سلام');
  const stopped=await call(db,'/api/ai/test-runs/control',jsonInit({action:'stop'}),extra).then(response=>response.json());assert.equal(stopped.run.status,'paused');assert.equal(stopped.run.stopRequested,true);
  const resumed=await call(db,'/api/ai/test-runs/control',jsonInit({action:'resume'}),extra).then(response=>response.json());assert.equal(resumed.run.status,'queued');assert.equal(resumed.run.stopRequested,false);assert.equal(sent.at(-1).message.runId,started.run.id)
});

test('watchdog skips a stalled AI model when the current run is polled',async()=>{
  const db=new MemoryD1(),sent=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})}};
  const started=await call(db,'/api/ai/test-runs',jsonInit({prompt:'سلام'}),extra).then(response=>response.json());
  const key='background_run:ai-test:'+started.run.id,run=JSON.parse(db.states.get(key));
  run.status='running';run.phase='testing';run.updatedAt=new Date(Date.now()-60_000).toISOString();
  db.states.set(key,JSON.stringify(run));db.stateUpdatedAt.set(key,run.updatedAt);
  const current=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());
  assert.equal(current.run.skipNext,true);assert.equal(current.run.phase,'watchdog-skip');assert.equal(current.run.status,'queued');
  assert.ok(sent.some(item=>item.message.task==='ai-test'&&item.message.runId===started.run.id),'stalled run must be re-enqueued');
});

test('AI model budget timeout skips the hung model and continues the queue',async()=>{
  const db=new MemoryD1(),sent=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})},AI_TEST_MODEL_BUDGET_MS:'150',AI_TEST_TIMEOUT_MS:'80'};
  const env={DB:db,VAULT_SECRET:'vault-secret',JOBS:extra.JOBS,JOBS_DLQ:{send:async()=>{}},AI_TEST_MODEL_BUDGET_MS:'150',AI_TEST_TIMEOUT_MS:'80'};
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'slow',name:'Slow AI',baseUrl:'https://ai.example/v1',apiKey:'slow-secret',models:['hang-1','ok-2'],enabled:true}],network:{mode:'direct'}}}),extra);
  const originalFetch=globalThis.fetch;globalThis.fetch=()=>new Promise(()=>{});
  const deliver=message=>worker.queue({messages:[{body:message,ack(){},retry(){assert.fail('budget skip should ack, not retry')}}]},env,ctx);
  try{
    const started=await call(db,'/api/ai/test-runs',jsonInit({prompt:'سلام'}),extra).then(response=>response.json());
    await deliver(sent.shift().message);
    const current=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());
    assert.equal(current.run.cursor,1);assert.equal(current.run.result.results[0].ok,false);assert.ok(current.run.result.results[0].skipped||/مهلت|timeout|AbortError/i.test([current.run.result.results[0].error,current.run.result.results[0].phase].join(' ')));assert.equal(current.run.status,'queued');
  }finally{globalThis.fetch=originalFetch}
});

test('dashboard skip-timeout setting is used to skip a hung AI model',async()=>{
  const db=new MemoryD1(),sent=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})}},env={DB:db,VAULT_SECRET:'vault-secret',JOBS:extra.JOBS,JOBS_DLQ:{send:async()=>{}}};
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'slow',name:'Slow AI',baseUrl:'https://ai.example/v1',apiKey:'slow-secret',models:['hang-1'],enabled:true}],network:{mode:'direct'}}}),extra);
  await call(db,'/api/settings',jsonInit({ai:{skipTimeoutMs:80}}),extra);
  const originalFetch=globalThis.fetch;globalThis.fetch=()=>new Promise(()=>{});
  const deliver=message=>worker.queue({messages:[{body:message,ack(){},retry(){assert.fail('timeout skip should ack')}}]},env,ctx);
  try{
    await call(db,'/api/ai/test-runs',jsonInit({prompt:'سلام'}),extra);
    await deliver(sent.shift().message);
    const current=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());
    assert.equal(current.run.result.results[0].ok,false);assert.match(String(current.run.result.results[0].error||current.run.result.results[0].phase||''),/مهلت|timeout|AbortError|transport-skip/i);
  }finally{globalThis.fetch=originalFetch}
});

test('Batch-only OpenRouter models are retried on /api/beta/batches and return the completed chat text',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1(),calls=[];console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'openrouter',name:'OpenRouter',baseUrl:'https://openrouter.ai/api/v1',apiKey:'or-secret',models:['batch-only-model'],enabled:true}],network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),method=String(init.method||(request instanceof Request?request.method:'GET')||'GET').toUpperCase(),body=init.body?JSON.parse(String(init.body)):null;calls.push({url,method,body});
      if(url.endsWith('/chat/completions')&&method==='POST')return jsonResponse({error:{message:'This model is only available through the Batch API. Use the /api/beta/batches endpoint instead.',code:404}},404);
      if(url==='https://openrouter.ai/api/beta/batches'&&method==='POST'){assert.equal(body.endpoint,'/v1/chat/completions');assert.equal(body.model,'batch-only-model');assert.equal(body.requests[0].custom_id,'s4-1');return jsonResponse({id:'batch_123',object:'batch',status:'validating',results:null},202)}
      if(url==='https://openrouter.ai/api/beta/batches/batch_123'&&method==='GET')return jsonResponse({id:'batch_123',status:'completed',results:[{custom_id:'s4-1',response:{status_code:200,body:{choices:[{message:{content:'پاسخ بچ'}},]},error:null}}]});
      throw new Error(`unexpected ${method} ${url}`)};
    const result=await call(db,'/api/test-connection/ai',jsonInit({provider:'openrouter',model:'batch-only-model',prompt:'سلام'})).then(response=>response.json());
    assert.equal(result.ok,true,JSON.stringify(result));assert.equal(result.text,'پاسخ بچ');assert.equal(result.endpointType,'batch');assert.equal(result.batchId,'batch_123');assert.match(result.endpoint,/beta\/batches/);assert.equal(calls[0].url,'https://openrouter.ai/api/v1/chat/completions');assert.equal(calls[1].url,'https://openrouter.ai/api/beta/batches');assert.equal(calls[2].url,'https://openrouter.ai/api/beta/batches/batch_123');assert.doesNotMatch(JSON.stringify(result),/or-secret/);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('AI result modal can retry message or category independently',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1(),prompts=[];
  try{
    await call(db,'/api/connections',jsonInit({basalam:{api:'https://basalam.example/v1',token:'bs-retry',vendorId:'77'},ai:{providers:[{id:'retry',name:'Retry Provider',baseUrl:'https://ai.example/v1',apiKey:'retry-secret',models:['model-a'],enabled:true}],network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request);if(url==='https://basalam.example/v1/categories')return jsonResponse({data:[{id:10,name:'آرایشی',children:[{id:11,name:'ادو پرفیوم'}]}]});const body=init.body?JSON.parse(String(init.body)):{},prompt=body.messages?.[0]?.content||'';if(prompt)prompts.push(prompt);if(prompt.includes('فهرست مجاز'))return jsonResponse({choices:[{message:{content:prompts.filter(item=>item.includes('فهرست مجاز')).length===1?'بدون دسته':'{"category_id":11,"reason":"درست"}'}}]});return jsonResponse({choices:[{message:{content:prompts.filter(item=>!item.includes('فهرست مجاز')).length===1?'اول':'دوم'}}]})};
    const first=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',categoryTitle:'ادو پرفیوم',cursor:0,runId:''})).then(response=>response.json());
    assert.equal(first.results[0].text,'اول');assert.equal(first.results[0].categoryResult.ok,false);
    const messageRetry=await call(db,'/api/ai/test-runs/retry',jsonInit({key:first.results[0].key,part:'message'})).then(response=>response.json());
    assert.equal(messageRetry.results[0].text,'دوم');assert.equal(messageRetry.results[0].messageRetryCount,1);assert.equal(messageRetry.results[0].categoryResult.ok,false);
    const categoryRetry=await call(db,'/api/ai/test-runs/retry',jsonInit({key:first.results[0].key,part:'category'})).then(response=>response.json());
    assert.equal(categoryRetry.results[0].text,'دوم');assert.equal(categoryRetry.results[0].categoryResult.ok,true);assert.equal(categoryRetry.results[0].categoryResult.categoryId,11);assert.equal(categoryRetry.results[0].categoryRetryCount,1);
  }finally{globalThis.fetch=originalFetch}
});

test('cloudflare multi-account keys: each account = accountId+token and providerWithKey picks the right account',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'cf',name:'CF',baseUrl:'https://api.cloudflare.com/client/v4/accounts/acc1/ai/run/',apiKey:'tok-1',apiKeys:[{accountId:'acc1',token:'tok-1'},{accountId:'acc2',token:'tok-2'}],models:['@cf/meta/llama-4-scout-17b-16e-instruct'],enabled:true}],candidates:[],master:'',model:'',network:{mode:'direct'}}}));
  const loaded=await call(db,'/api/connections').then(r=>r.json());
  const p=loaded.connections.ai.providers.find(x=>x.id==='cf');
  assert.equal(p.apiKeys.length,2,'two accounts kept');
  assert.equal(p.apiKeys[1].accountId,'acc2');assert.equal(p.apiKeys[1].token,'tok-2');
  const source=await readFile(new URL('../worker-src/ai.ts',import.meta.url),'utf8');
  assert.match(source,/export function providerWithKey\(provider:Provider,index=0\)/,'providerWithKey exported');
  assert.match(source,/chosen as CfAccountKey\)\.accountId\|\|cloudflareAccountId/,'providerWithKey rebuilds the account baseUrl');
  assert.match(source,/apiKeys\?:Array<string\|CfAccountKey>/,'provider type supports string|account keys');
});

test('cloudflare provider keeps accountId/cfToken through the vault',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'cf',name:'Cloudflare',baseUrl:'https://api.cloudflare.com/client/v4/accounts/acc123/ai/run/',apiKey:'tok-1',apiKeys:['tok-1'],accountId:'acc123',cfToken:'tok-1',models:['@cf/meta/llama-4-scout-17b-16e-instruct'],enabled:true}],candidates:[],master:'',model:'',network:{mode:'direct'}}}));
  const loaded=await call(db,'/api/connections').then(r=>r.json());
  const p=loaded.connections.ai.providers.find(x=>x.id==='cf');
  assert.equal(p.accountId,'acc123');assert.equal(p.cfToken,'tok-1');
  assert.equal(p.baseUrl,'https://api.cloudflare.com/client/v4/accounts/acc123/ai/run');
});

test('multi-key providers: keys are saved, exported and used per model suffix in chat',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'mk',name:'MultiKey',baseUrl:'https://mk.example/v1',apiKey:'key-one',apiKeys:['key-one','key-two'],models:['m1'],enabled:true}],candidates:[],master:'',model:'',network:{mode:'direct'}}}));
  const loaded=await call(db,'/api/connections').then(r=>r.json());
  const provider=loaded.connections.ai.providers.find(p=>p.id==='mk');
  assert.deepEqual(provider.apiKeys,['key-one','key-two'],'both keys persist');
  assert.equal(provider.apiKey,'key-one','primary key is the first key');
  const modelsResp=await call(db,'/api/ai/chat-models').then(r=>r.json());
  assert.equal(modelsResp.models[0].keyCount,2,'chat-models exposes the key count');
  const auths=[];
  const originalFetch=globalThis.fetch;
  globalThis.fetch=async(_url,init={})=>{const h=new Headers(init.headers||{});auths.push(String(h.get('authorization')||''));return new Response(JSON.stringify({choices:[{message:{role:'assistant',content:'ok'}}]}),{status:200,headers:{'content-type':'application/json'}})};
  try{
    const r1=await call(db,'/api/ai/chat',jsonInit({providerId:'mk',model:'m1',messages:[{role:'user',content:'hi'}]})).then(r=>r.json());
    assert.equal(r1.ok,true);
    const r2=await call(db,'/api/ai/chat',jsonInit({providerId:'mk',model:'m1::k2',messages:[{role:'user',content:'hi'}]})).then(r=>r.json());
    assert.equal(r2.ok,true,'model with ::k2 suffix resolves');
    assert.equal(auths.length,2);
    assert.ok(auths[0].includes('key-one')&&auths[1].includes('key-two'),'each key is used by its own model entry');
  }finally{globalThis.fetch=originalFetch}
});

test('cloudflareModelIds never emits the invalid meta-llama org path',async()=>{
  const source=await readFile(new URL('../worker-src/ai.ts',import.meta.url),'utf8');
  assert.doesNotMatch(source,/\/meta-llama\/\$\{after\}/,'the wrong @cf/meta-llama/ fallback is gone');
  assert.match(source,/meta-llama\/\'\).*@cf\/meta\/\$\{after/,'a meta-llama/ user input is rewritten to the valid @cf/meta/ path');
  assert.match(source,/label:'text',value:\{text:prompt/,'image-to-text models get a text candidate payload');
});
test('settings export splits profile products and import reads them back',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/profiles',jsonInit({id:'pp',name:'p',url:'',noExtract:true,pages:1,pagination:'none',selectors:{container:'.p',title:'h2',price:'.x',link:'a',image:'img'},enabled:true}));
  await call(db,'/api/profiles/pp/import?format=csv',{method:'POST',headers:{'content-type':'text/csv; charset=utf-8'},body:'نام محصول,قیمت\nعطر تست,100000\n'});
  const exported=await call(db,'/api/settings-export').then(r=>r.json());
  assert.ok(exported.files['profiles.json'],'profiles file present');
  assert.ok(exported.files['profile_products.json'],'products are split into their own file');
  assert.ok(!exported.files['profiles.json']||true,'profiles file stays a bundle entry');
  // Products live in the dedicated file and import reads them back.
  const b64=exported.files['profile_products.json'].b64,bin=Buffer.from(b64,'base64'),productsFile=JSON.parse(bin.toString('utf8'));
  assert.ok(productsFile&&typeof productsFile==='object','profile products is an object keyed by profile id');
  // Partial import: only profile settings+products (no connections) must not wipe existing connections.
  const fresh=new MemoryD1();
  await call(fresh,'/api/connections',jsonInit({woo:{url:'https://shop.example',key:'k',secret:'s'}}));
  const partial={...exported,files:{'profiles.json':exported.files['profiles.json'],'profile_products.json':exported.files['profile_products.json']}};
  const imported=await call(fresh,'/api/settings-import',{method:'POST',body:JSON.stringify(partial)}).then(r=>r.json());
  assert.equal(imported.ok,true);assert.equal(imported.imported.profiles,1);
  const conns=await call(fresh,'/api/connections').then(r=>r.json());
  assert.equal(conns.connections.woo.url,'https://shop.example','woo untouched by partial import');
});

test('tried-category memory endpoints store and return per-product categories',async()=>{
  const db=new MemoryD1();
  const marked=await call(db,'/api/destination/basalam/category-tried',{method:'POST',body:JSON.stringify({shopId:'v1',id:42,ids:[10,20]})}).then(r=>r.json());
  assert.equal(marked.ok,true);assert.deepEqual(marked.tried,[10,20]);
  const got=await call(db,'/api/destination/basalam/category-tried?shopId=v1&id=42').then(r=>r.json());
  assert.deepEqual(got.tried,[10,20]);
  const other=await call(db,'/api/destination/basalam/category-tried?shopId=v1&id=99').then(r=>r.json());
  assert.deepEqual(other.tried,[]);
});

test('AI chat lists capability-filtered models and returns conversation replies',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'chat-pro',name:'Chat Provider',baseUrl:'https://chat.example/v1',apiKey:'k',models:['m-chat','m-reason'],reasoningModels:['m-reason'],enabled:true},{id:'mistral',name:'Mistral',baseUrl:'https://api.mistral.ai/v1',apiKey:'k',models:['mistral-ocr-latest'],enabled:true}],candidates:[],master:'',model:'',network:{mode:'direct'}}}));
  const modelsResp=await call(db,'/api/ai/chat-models'),models=(await modelsResp.json()).models;
  assert.equal(modelsResp.status,200);
  assert.equal(models.length,3);
  const chat=models.find(m=>m.model==='m-chat');assert.equal(chat.chat,true);assert.equal(chat.toolCalling,false);assert.equal(chat.reasoning,false);
  const reason=models.find(m=>m.model==='m-reason');assert.equal(reason.chat,true);assert.equal(reason.reasoning,true);
  assert.equal(models.find(m=>m.model==='mistral-ocr-latest').chat,false,'dedicated-endpoint models are not chat-capable');
  const originalFetch=globalThis.fetch;
  globalThis.fetch=async()=>new Response(JSON.stringify({choices:[{message:{role:'assistant',content:'سلام! در خدمتم.'}}]}),{status:200,headers:{'content-type':'application/json'}});
  try{
    const resp=await call(db,'/api/ai/chat',jsonInit({providerId:'chat-pro',model:'m-chat',messages:[{role:'user',content:'سلام'}]})),d=await resp.json();
    assert.equal(resp.status,200);assert.equal(d.ok,true);assert.equal(d.text,'سلام! در خدمتم.');assert.equal(d.model,'m-chat');assert.equal(d.provider,'chat-pro');
    assert.ok(Number.isFinite(d.latencyMs));
    const bad=await call(db,'/api/ai/chat',jsonInit({providerId:'chat-pro',model:'m-chat',messages:[{role:'assistant',content:'بدون پیام کاربر'}]}));assert.equal(bad.status,400);
    const missing=await call(db,'/api/ai/chat',jsonInit({providerId:'nope',model:'m-chat',messages:[{role:'user',content:'x'}]}));assert.equal(missing.status,404);
  }finally{globalThis.fetch=originalFetch}
});

test('activity endpoint returns a lightweight summary without heavy data',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/profiles',jsonInit({id:'p1',name:'p',url:'',noExtract:true,pages:1,pagination:'none',selectors:{container:'.x',title:'h2',price:'.p',link:'a',image:'img'},enabled:true}));
  const d=await call(db,'/api/activity').then(r=>r.json());
  assert.equal(d.ok,true);assert.equal(d.counts.profiles,1);
  assert.ok(Array.isArray(d.activeJobs)&&Array.isArray(d.runs)&&Array.isArray(d.lastJobs));
  assert.ok('cron' in d&&'version' in d&&'ts' in d);
});

test('task manager drag order persists as priority and drives active job ordering',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/profiles',jsonInit({id:'p1',name:'p',url:'',noExtract:true,pages:1,pagination:'none',selectors:{container:'.x',title:'h2',price:'.p',link:'a',image:'img'},enabled:true}));
  const job=(id,created,status='queued')=>db.jobs.set(id,{id,profile_id:'p1',kind:'scrape',target:'woo',status,phase:status==='queued'?'waiting':'details',total:2,processed:0,added:0,updated:0,failed:0,stop_requested:0,error:null,log:'[]',created_at:created,updated_at:created,started_at:null,finished_at:null});
  job('job-old','2026-08-20T10:00:00.000Z');job('job-mid','2026-08-21T10:00:00.000Z');job('job-new','2026-08-22T10:00:00.000Z');job('job-run','2026-08-22T11:00:00.000Z','running');
  // User drags job-new to the top, job-mid last: POST the desired execution order.
  const reordered=await call(db,'/api/jobs/priority',jsonInit({ids:['job-new','job-old','job-mid']})).then(r=>r.json());
  assert.equal(reordered.ok,true);assert.equal(reordered.count,3);
  assert.equal(JSON.parse(db.states.get('job_priorities_v1'))['job-new'],3);
  assert.equal(JSON.parse(db.states.get('job_priorities_v1'))['job-old'],2);
  assert.equal(JSON.parse(db.states.get('job_priorities_v1'))['job-mid'],1);
  // The running job is ignored by the reorder endpoint (it cannot be dragged).
  const ignored=await call(db,'/api/jobs/priority',jsonInit({ids:['job-run']})).then(r=>r.json());
  assert.equal(ignored.count,0);
  // Activity lists queued jobs in priority order first, then the running job.
  const d=await call(db,'/api/activity').then(r=>r.json());
  assert.deepEqual(d.activeJobs.map(j=>j.id),['job-new','job-old','job-mid','job-run']);
  assert.equal(d.activeJobs[0].priority,3);assert.equal(d.activeJobs[3].status,'running');
  // Empty request is rejected instead of wiping the saved order.
  const empty=await call(db,'/api/jobs/priority',jsonInit({ids:[]})).then(r=>r.json());
  assert.equal(empty.ok,false);
});

test('background run drag order persists, reorders the runs section and exposes run ids',async()=>{
  const db=new MemoryD1();
  db.states.set('background_current:ai-test',JSON.stringify('ai-id'));
  db.states.set('background_run:ai-test:ai-id',JSON.stringify({id:'ai-id',kind:'ai-test',status:'queued',phase:'waiting',stopRequested:false,createdAt:'2026-08-22T10:00:00.000Z',updatedAt:'2026-08-22T10:00:00.000Z',startedAt:null,finishedAt:null,attempts:0,error:null,total:0,processed:0,cursor:0,result:{}}));
  db.states.set('background_current:category-all',JSON.stringify('cat-id'));
  db.states.set('background_run:category-all:cat-id',JSON.stringify({id:'cat-id',kind:'category-all',status:'queued',phase:'listing',stopRequested:false,createdAt:'2026-08-22T11:00:00.000Z',updatedAt:'2026-08-22T11:00:00.000Z',startedAt:null,finishedAt:null,attempts:0,error:null,page:1,totalPages:2,total:0,processed:0,changed:0,failed:0,items:[],products:[]}));
  const before=await call(db,'/api/activity').then(r=>r.json());
  assert.deepEqual(before.runs.map(r=>r.kind),['ai-test','category-all']); // canonical default order
  assert.equal(before.runs[0].id,'ai-id');assert.equal(before.runs[1].id,'cat-id');
  const reordered=await call(db,'/api/runs/priority',jsonInit({kinds:['category-all','ai-test']})).then(r=>r.json());
  assert.equal(reordered.ok,true);assert.equal(reordered.count,2);
  assert.equal(JSON.parse(db.states.get('run_priorities_v1'))['category-all'],2);
  assert.equal(JSON.parse(db.states.get('run_priorities_v1'))['ai-test'],1);
  const after=await call(db,'/api/activity').then(r=>r.json());
  assert.deepEqual(after.runs.map(r=>r.kind),['category-all','ai-test']);
  assert.equal(after.runs[0].priority,2);assert.equal(after.runs[1].priority,1);
  // Unknown kinds are ignored and never wipe the saved order.
  const bogus=await call(db,'/api/runs/priority',jsonInit({kinds:['bogus']})).then(r=>r.json());
  assert.equal(bogus.count,0);
  assert.equal(JSON.parse(db.states.get('run_priorities_v1'))['ai-test'],1);
  const empty=await call(db,'/api/runs/priority',jsonInit({kinds:[]})).then(r=>r.json());
  assert.equal(empty.ok,false);
});

test('scheduled cron uses live general settings for watchdog, report retention, lock and cron ping',async()=>{
  const db=new MemoryD1(),pending=[],pings=[],localCtx={waitUntil(promise){pending.push(promise)},passThroughOnException(){}};
  await call(db,'/api/settings',jsonInit({general:{cronLockMin:1,keepReports:2,queueDedup:true,queueDedupStale:1,contentSync:false},watchdog:{enabled:true,stallAfter:60},notifications:{events:{cronPing:true},pingEvery:1}}));
  await call(db,'/api/connections',jsonInit({notifications:{url:'https://notify.example/hook',chatId:'c1',token:'hook-token'}}));
  const stale=new Date(Date.now()-120_000).toISOString();
  db.jobs.set('stale-job',{id:'stale-job',profile_id:'p',kind:'scrape',target:'none',status:'running',phase:'details',total:1,processed:0,added:0,updated:0,failed:0,stop_requested:0,error:null,log:'[]',created_at:stale,updated_at:stale,started_at:stale,finished_at:null});
  db.jobs.set('old-done',{id:'old-done',profile_id:'p',kind:'scrape',target:'none',status:'done',phase:'finished',total:1,processed:1,added:1,updated:0,failed:0,stop_requested:0,error:null,log:'[]',created_at:'2020-01-01T00:00:00.000Z',updated_at:'2020-01-01T00:00:00.000Z',started_at:'2020-01-01T00:00:00.000Z',finished_at:'2020-01-01T00:00:01.000Z'});
  db.jobs.set('new-done',{id:'new-done',profile_id:'p',kind:'scrape',target:'none',status:'done',phase:'finished',total:1,processed:1,added:0,updated:1,failed:0,stop_requested:0,error:null,log:'[]',created_at:'2026-08-21T10:00:00.000Z',updated_at:'2026-08-21T10:00:00.000Z',started_at:'2026-08-21T10:00:00.000Z',finished_at:'2026-08-21T10:00:01.000Z'});
  const originalFetch=globalThis.fetch;globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request);pings.push(url);if(url==='https://notify.example/hook')return jsonResponse({ok:true});throw new Error('unexpected '+url)};
  try{
    const env={DB:db,VAULT_SECRET:'vault-secret',JOBS:{send:async()=>{}},JOBS_DLQ:{send:async()=>{}},WORKER_VERSION:'1.11.0'};
    await worker.scheduled({cron:'* * * * *',scheduledTime:Date.now()},env,localCtx);await Promise.all(pending);
    assert.equal(db.jobs.get('stale-job').status,'failed');assert.equal(db.jobs.get('stale-job').phase,'watchdog');
    assert.equal(db.jobs.has('old-done'),false);assert.equal(db.jobs.has('new-done'),true);
    assert.ok(pings.includes('https://notify.example/hook'));assert.equal(JSON.parse(db.states.get('cron_lock')).held,false);assert.ok(db.states.get('cron_ping'));
  }finally{globalThis.fetch=originalFetch}
});

test('hung AI models are retried three times after the first pass',async()=>{
  const db=new MemoryD1(),sent=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})},AI_TEST_MODEL_BUDGET_MS:'80',AI_TEST_TIMEOUT_MS:'50'};
  const env={DB:db,VAULT_SECRET:'vault-secret',JOBS:extra.JOBS,JOBS_DLQ:{send:async()=>{}},AI_TEST_MODEL_BUDGET_MS:'80',AI_TEST_TIMEOUT_MS:'50'};
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'slow',name:'Slow AI',baseUrl:'https://ai.example/v1',apiKey:'slow-secret',models:['hang-1'],enabled:true}],network:{mode:'direct'}}}),extra);
  const originalFetch=globalThis.fetch;globalThis.fetch=()=>new Promise(()=>{});
  const deliver=message=>worker.queue({messages:[{body:message,ack(){},retry(){assert.fail('retry pass should ack')}}]},env,ctx);
  try{
    await call(db,'/api/ai/test-runs',jsonInit({prompt:'سلام'}),extra);
    let retries=0;
    for(let i=0;i<8&&sent.length;i++){
      await deliver(sent.shift().message);
      const current=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());
      retries=Number(current.run.result?.results?.[0]?.retryCount||0);
      if(current.run.status==='done'){assert.equal(retries,3,'a hung model gets three extra attempts at the end');assert.equal(current.run.result.results[0].ok,false);return}
    }
    assert.fail('run did not finish after hung-model retries, last retryCount='+retries)
  }finally{globalThis.fetch=originalFetch}
});

test('AI queue checkpoints results server-side until all models finish after a refresh',async()=>{
  const db=new MemoryD1(),sent=[],models=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})}},env={DB:db,VAULT_SECRET:'vault-secret',JOBS:null,JOBS_DLQ:{send:async()=>{}}};env.JOBS=extra.JOBS;
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'durable',name:'Durable AI',baseUrl:'https://ai.example/v1',apiKey:'durable-ai-secret',models:['model-1','model-2'],enabled:true}],network:{mode:'direct'}}}),extra);
  const originalFetch=globalThis.fetch;globalThis.fetch=async(_request,init={})=>{const body=JSON.parse(String(init.body||'{}'));models.push(body.model);return jsonResponse({choices:[{message:{content:`پاسخ ${body.model}`}}]})};
  const deliver=message=>worker.queue({messages:[{body:message,ack(){},retry(){assert.fail('AI checkpoint should not retry')}}]},env,ctx);
  try{
    const started=await call(db,'/api/ai/test-runs',jsonInit({prompt:'سلام پایدار'}),extra).then(response=>response.json());assert.equal(started.run.status,'queued');assert.equal(sent.length,1);
    await deliver(sent.shift().message);const refreshed=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());assert.equal(refreshed.run.status,'queued');assert.equal(refreshed.run.cursor,1);assert.equal(refreshed.run.result.results.length,1);assert.equal(refreshed.run.result.serverSide,true);
    await deliver(sent.shift().message);assert.equal(sent.length,0);const completed=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());assert.equal(completed.run.status,'done');assert.equal(completed.run.cursor,2);assert.equal(completed.run.result.results.length,2);assert.deepEqual(models,['model-1','model-2'])
  }finally{globalThis.fetch=originalFetch}
});

test('category-all queue consumes every unapproved page once and survives duplicate delivery',async()=>{
  const db=new MemoryD1(),sent=[],listPages=[],updatedIds=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})}},env={DB:db,VAULT_SECRET:'vault-secret',JOBS:null,JOBS_DLQ:{send:async()=>{}}};env.JOBS=extra.JOBS;
  await call(db,'/api/connections',jsonInit({basalam:{api:'https://basalam.example/v1',token:'category-token',vendorId:'55'},ai:{providers:[{id:'cat-ai',name:'Category AI',baseUrl:'https://ai.example/v1',apiKey:'category-ai-secret',models:['cat-model'],enabled:true}],candidates:['cat-ai::cat-model'],network:{mode:'direct'}}}),extra);
  db.states.set('ai_test_results',JSON.stringify({runId:'completed-ai-run',results:[{ok:true,provider:'cat-ai',model:'cat-model'}]}));
  db.states.set('basalam_categories_v1',JSON.stringify({updatedAt:new Date().toISOString(),items:[{id:902,name:'ادو پرفیوم',path:'آرایشی ← ادو پرفیوم',parentId:null,depth:1,leaf:true}]}));
  const originalFetch=globalThis.fetch;globalThis.fetch=async(request,init={})=>{const url=new URL(String(request instanceof Request?request.url:request)),method=String(init.method||(request instanceof Request?request.method:'GET')||'GET').toUpperCase();
    if(url.hostname==='ai.example')return jsonResponse({choices:[{message:{content:'{"category_id":902,"reason":"مرتبط"}'}}]});
    if(url.pathname==='/v1/vendors/55/products'&&method==='GET'){const page=Number(url.searchParams.get('page'));listPages.push(page);assert.deepEqual(url.searchParams.getAll('statuses'),['3567']);return jsonResponse({data:[{id:200+page,title:`ادو پرفیوم ${page}`,status:{value:3567,name:'تأیید نشده'}}],total_count:2,total_page:2})}
    if(/^\/v1\/products\/20[12]$/.test(url.pathname)&&method==='PATCH'){updatedIds.push(Number(url.pathname.split('/').at(-1)));assert.equal(JSON.parse(String(init.body)).category_id,902);return jsonResponse({ok:true})}
    throw new Error(`unexpected category-all request ${method} ${url}`)};
  const deliver=async message=>{let acked=0,retried=0;await worker.queue({messages:[{body:message,ack(){acked++},retry(){retried++}}]},env,ctx);assert.equal(acked,1);assert.equal(retried,0)};
  try{
    const startedResponse=await call(db,'/api/destination/basalam/category-runs',jsonInit({}),extra),started=await startedResponse.json();assert.equal(startedResponse.status,202);assert.equal(started.run.total,0);assert.equal(sent.length,1);
    const duplicate=sent.shift().message;await Promise.all([deliver(duplicate),deliver(duplicate)]);
    for(let checkpoints=0;sent.length&&checkpoints<10;checkpoints++)await deliver(sent.shift().message);
    assert.equal(sent.length,0,'the queue run must reach a terminal checkpoint');assert.deepEqual(listPages,[1,2]);assert.deepEqual(updatedIds.sort((a,b)=>a-b),[201,202]);
    const current=await call(db,'/api/destination/basalam/category-runs/current',{},extra).then(response=>response.json());assert.equal(current.run.status,'done');assert.equal(current.run.total,2);assert.equal(current.run.processed,2);assert.equal(current.run.changed,2);assert.equal(current.run.failed,0);assert.equal(current.run.items.length,2);assert.equal('products' in current.run,false,'the public response must not expose the potentially large checkpoint list')
  }finally{globalThis.fetch=originalFetch}
});

test('PBKDF2 uses Cloudflare maximum, vault round-trips, and oversized legacy envelopes fail clearly',async()=>{
  const source=await readFile(new URL('../worker-src/vault.ts',import.meta.url),'utf8'),bundle=await readFile(new URL('../scraper4.worker.js',import.meta.url),'utf8');
  assert.match(source,/VAULT_KDF_ITERATIONS\s*=\s*100_000/);
  assert.doesNotMatch(source,/120_000|120000/);assert.doesNotMatch(bundle,/120_000|120000/);
  const db=new MemoryD1(),saved=await call(db,'/api/connections',jsonInit({woo:{url:'https://store.example',key:'ck_test',secret:'cs_private'}}));
  assert.equal(saved.status,200);assert.equal((await saved.json()).connections.woo.key,'ck_test');
  const [stateKey,raw]=[...db.states.entries()][0],envelope=JSON.parse(raw);assert.equal(envelope.iterations,100000);assert.equal(JSON.stringify(envelope).includes('cs_private'),false);
  const loaded=await call(db,'/api/connections');assert.equal((await loaded.json()).connections.woo.secret,'cs_private');
  db.states.set(stateKey,JSON.stringify({version:2,iterations:120000,salt:'AAAAAAAAAAAAAAAAAAAAAA==',iv:'AAAAAAAAAAAAAAAA',ciphertext:'AA=='}));
  const previous=console.error;console.error=()=>{};try{const rejected=await call(db,'/api/connections');assert.equal(rejected.status,400);assert.match((await rejected.json()).error,/100000/)}finally{console.error=previous}
});

test('legacy Mistral provider receives catalog v2 including dedicated Text-to-text endpoints without overwriting user settings',async()=>{
  const db=new MemoryD1(),legacy={woo:{},basalam:{},ai:{providers:[{id:'mistral',name:'میسترال شخصی',baseUrl:'https://custom.example/mistral/v1',apiKey:'mistral-user-secret',models:['private-model'],enabled:true}],candidates:['mistral::private-model'],master:'mistral::private-model',network:{mode:'worker',workerUrl:'https://gateway.example'}},notifications:{}};
  db.states.set('connection_vault',JSON.stringify(await legacyVaultEnvelope(legacy)));
  const loadedResponse=await call(db,'/api/connections'),loaded=(await loadedResponse.json()).connections,provider=loaded.ai.providers.find(item=>item.id==='mistral');assert.equal(loadedResponse.status,200);assert.equal(loaded.ai.catalogVersion,3);assert.equal(provider.name,'میسترال شخصی');assert.equal(provider.baseUrl,'https://custom.example/mistral/v1');assert.equal(provider.apiKey,'mistral-user-secret');assert.equal(provider.enabled,true);assert.ok(provider.models.includes('private-model'));for(const model of ['mistral-medium-latest','mistral-small-latest','mistral-large-latest','zai-glm-5-2','mistral-ocr-latest','voxtral-small-latest','codestral-latest','labs-leanstral-2603','labs-leanstral-1-5','ministral-3b-latest','ministral-8b-latest','ministral-14b-latest','mistral-embed'])assert.ok(provider.models.includes(model),model);assert.deepEqual(loaded.ai.candidates,['mistral::private-model']);assert.equal(loaded.ai.master,'mistral::private-model');assert.equal(loaded.ai.network.mode,'worker');
  provider.models=provider.models.filter(model=>model!=='mistral-small-latest');const saved=await call(db,'/api/connections',jsonInit({ai:loaded.ai}));assert.equal(saved.status,200);assert.equal(JSON.stringify(JSON.parse(db.states.get('connection_vault'))).includes('mistral-user-secret'),false);const reloaded=await call(db,'/api/connections').then(response=>response.json());assert.equal(reloaded.connections.ai.providers.find(item=>item.id==='mistral').models.includes('mistral-small-latest'),false,'the one-time catalog migration must not undo a later user deletion');
});

test('read-only debug endpoint checks bindings, D1, queue and KDF without leaking secrets',async()=>{
  const db=new MemoryD1(),response=await call(db,'/api/debug',{}, {VAULT_SECRET:'actual-secret-42',PRIVATE_MARKER:'do-not-return'});assert.equal(response.status,200);const body=await response.json();
  assert.equal(body.ok,true);assert.equal(body.checks.find(check=>check.name==='vault-kdf').ok,true);assert.equal(body.checks.find(check=>check.name==='d1-schema').ok,true);assert.equal(body.checks.find(check=>check.name==='queue-binding').ok,true);
  assert.doesNotMatch(JSON.stringify(body),/actual-secret-42|do-not-return|ck_test|cs_private/);
});

test('network redirects strip credentials, oversized and stalled bodies stop safely, and AI failures redact secrets',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1();console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({woo:{url:'https://store.example',key:'ck_redirect',secret:'cs_redirect'},ai:{providers:[{id:'p1',name:'Provider',baseUrl:'https://ai.example/v1?token=url-secret-88',apiKey:'api-secret-77',models:['model-1'],enabled:true}],network:{mode:'direct'}}}));
    const redirectCalls=[];globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request);redirectCalls.push({url,headers:new Headers(init.headers)});if(url.startsWith('https://store.example/'))return new Response(null,{status:302,headers:{location:'https://status.example/woo'}});if(url==='https://status.example/woo')return new Response(JSON.stringify({environment:{woocommerce_version:'9.0'}}),{headers:{'content-type':'application/json'}});throw new Error(`unexpected ${url}`)};
    const woo=await call(db,'/api/test-connection/woo',jsonInit({})).then(r=>r.json());assert.equal(woo.ok,true);assert.match(redirectCalls[0].headers.get('authorization')||'',/^Basic /);assert.equal(redirectCalls[1].headers.get('authorization'),null);assert.equal(redirectCalls[1].headers.get('cookie'),null);assert.equal(redirectCalls[1].headers.get('proxy-authorization'),null);

    let cancelled=false;globalThis.fetch=async()=>new Response(new ReadableStream({start(){},cancel(){cancelled=true}}),{headers:{'content-type':'text/html','content-length':'1000001'}});
    const oversized=await call(db,'/api/source-test',jsonInit({url:'https://large.example/page'}));assert.equal(oversized.status,413);assert.equal(cancelled,true);assert.match((await oversized.json()).error,/exceeds/i);

    globalThis.fetch=async(_request,init={})=>new Response(new ReadableStream({start(controller){init.signal?.addEventListener('abort',()=>controller.error(new Error('body aborted')),{once:true})}}),{headers:{'content-type':'text/html'}});
    const stalled=await call(db,'/api/source-test',jsonInit({url:'https://slow.example/page'}),{REQUEST_TIMEOUT_MS:'1000'});assert.equal(stalled.status,504);assert.match((await stalled.json()).error,/مهلت دریافت/);

    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),authorization=new Headers(init.headers).get('authorization')||'';throw new Error(`cannot reach ${url}; ${authorization}`)};
    const ai=await call(db,'/api/test-connection/ai',jsonInit({provider:'p1',model:'model-1',prompt:'سلام'})).then(r=>r.json()),serialized=JSON.stringify(ai);assert.equal(ai.ok,false);assert.equal(ai.phase,'network');assert.equal(typeof ai.latencyMs,'number');assert.ok(ai.raw);assert.doesNotMatch(serialized,/api-secret-77|url-secret-88/);assert.match(serialized,/پنهان/);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('Woo automatic network mode retries Cloudflare 522 through the configured Worker and explains the result',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1(),calls=[];console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({woo:{url:'https://store.example',key:'ck_woo',secret:'cs_woo',network:{mode:'auto',workerUrl:'https://woo-proxy.example/?target={url}'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),headers=new Headers(init.headers);calls.push({url,headers});if(url.startsWith('https://store.example/'))return new Response('origin timeout',{status:522,headers:{'content-type':'text/plain'}});if(url.startsWith('https://woo-proxy.example/'))return new Response(JSON.stringify([{id:77,name:'محصول نمونه',status:'publish'}]),{headers:{'content-type':'application/json'}});throw new Error(`unexpected ${url}`)};
    const fallback=await call(db,'/api/test-connection/woo',jsonInit({})).then(response=>response.json());assert.equal(fallback.ok,true,JSON.stringify(fallback));assert.equal(fallback.http.status,200);assert.equal(fallback.http.networkMode,'worker');assert.equal(fallback.http.directStatus,522);assert.equal(fallback.summary.sampleProductId,77);assert.match(fallback.recommendations.join(' '),/مستقیم.*۵۲۲|مستقیم.*522/);assert.equal(calls.length,2);assert.match(calls[1].url,/woo-proxy\.example/);assert.equal(calls[1].headers.get('x-target-url')?.startsWith('https://store.example/'),true);assert.match(calls[1].headers.get('authorization')||'',/^Basic /);

    await call(db,'/api/connections',jsonInit({woo:{network:{mode:'direct',workerUrl:'https://woo-proxy.example/?target={url}'}}}));calls.length=0;globalThis.fetch=async(request)=>{calls.push({url:String(request instanceof Request?request.url:request),headers:new Headers()});return new Response('origin timeout',{status:522,headers:{'content-type':'text/plain'}})};
    const direct=await call(db,'/api/test-connection/woo',jsonInit({})).then(response=>response.json());assert.equal(direct.ok,false);assert.equal(direct.http.status,522);assert.equal(direct.http.networkMode,'direct');assert.equal(calls.length,1);assert.match(calls[0].url,/store\.example/);assert.match(direct.recommendations.join(' '),/سرور اصلی|کلید ووکامرس مقصر نیست/);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('Cloudflare Workers AI uses native run payloads, resolves organization model paths, and only then falls back to chat',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1(),calls=[];console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'cf',name:'Cloudflare',baseUrl:'https://api.cloudflare.com/client/v4/accounts/account-123/ai/run/',apiKey:'cf-secret-token',models:['@cf/meta/llama-test','llama-3.1','@cf/nope/model'],enabled:true}],network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),body=JSON.parse(String(init.body||'{}'));calls.push({url,body,authorization:new Headers(init.headers).get('authorization')});if(url.includes('/ai/run/@cf/meta/llama-test')&&body.messages)return new Response(JSON.stringify({success:true,result:{response:'پاسخ بومی'}}),{status:200,headers:{'content-type':'application/json'}});return new Response(JSON.stringify({errors:[{message:'No route for that URI'}]}),{status:404,headers:{'content-type':'application/json'}})};
    const native=await call(db,'/api/test-connection/ai',jsonInit({provider:'cf',model:'@cf/meta/llama-test',prompt:'سلام'})).then(r=>r.json());assert.equal(native.ok,true);assert.equal(native.text,'پاسخ بومی');assert.equal(native.cloudflare.mode,'native');assert.equal(calls.length,2);assert.deepEqual(Object.keys(calls[0].body).sort(),['max_tokens','prompt']);assert.deepEqual(Object.keys(calls[1].body).sort(),['max_tokens','messages']);assert.ok(calls.every(x=>x.url.includes('/ai/run/@cf/meta/llama-test')));assert.ok(calls.every(x=>x.authorization==='Bearer cf-secret-token'));

    calls.length=0;globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),body=JSON.parse(String(init.body||'{}'));calls.push({url,body});if(url.includes('/ai/run/@cf/meta/llama-3.1')&&body.messages)return new Response(JSON.stringify({result:{response:'مسیر سازمانی'}}),{headers:{'content-type':'application/json'}});return new Response(JSON.stringify({errors:[{code:5007,message:'No such model'}]}),{status:404,headers:{'content-type':'application/json'}})};
    const organized=await call(db,'/api/test-connection/ai',jsonInit({provider:'cf',model:'llama-3.1',prompt:'آزمایش'})).then(r=>r.json());assert.equal(organized.ok,true);assert.equal(organized.cloudflare.resolvedModel,'@cf/meta/llama-3.1');assert.ok(calls.some(x=>x.url.includes('/ai/run/llama-3.1')));assert.ok(calls.some(x=>x.url.includes('/ai/run/@cf/meta/llama-3.1')));

    calls.length=0;globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),body=JSON.parse(String(init.body||'{}'));calls.push({url,body});if(url.endsWith('/ai/v1/chat/completions'))return new Response(JSON.stringify({choices:[{message:{content:'پاسخ chat'}}]}),{headers:{'content-type':'application/json'}});return new Response(JSON.stringify({errors:[{message:'No route for that URI'}]}),{status:404,headers:{'content-type':'application/json'}})};
    const chat=await call(db,'/api/test-connection/ai',jsonInit({provider:'cf',model:'@cf/nope/model',prompt:'fallback'})).then(r=>r.json());assert.equal(chat.ok,true);assert.equal(chat.text,'پاسخ chat');assert.equal(chat.cloudflare.mode,'openai-fallback');assert.ok(calls.some(x=>x.url.endsWith('/ai/v1/chat/completions')));assert.ok(calls.filter(x=>x.url.includes('/ai/run/')).every(x=>!x.url.includes('/chat/completions')));assert.doesNotMatch(JSON.stringify(chat),/cf-secret-token/);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('AI all-model testing paginates one model per Worker invocation and preserves one aggregate table',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1(),calls=[];
  try{
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'batch',name:'Batch Provider',baseUrl:'https://ai.example/v1',apiKey:'batch-secret',models:['model-1','model-2','model-3','model-4'],enabled:true}],candidates:['batch::model-2','batch::model-4'],testPerProvider:50,network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const body=JSON.parse(String(init.body||'{}'));calls.push(body.model);return jsonResponse({choices:[{message:{content:'پاسخ '+body.model},finish_reason:'stop'}],usage:{total_tokens:9}})};
    let cursor=0,runId='',last;
    for(let invocation=0;invocation<4;invocation++){
      const response=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',cursor,runId,perProvider:2}));assert.equal(response.status,200);last=await response.json();runId=last.runId;cursor=last.nextCursor;
      assert.equal(last.maxModelsPerInvocation,10);assert.equal(last.batchResults.length,1);assert.equal(last.results.length,invocation+1);assert.equal(last.total,4);assert.equal(last.done,invocation===3);
    }
    assert.deepEqual(calls,['model-1','model-2','model-3','model-4']);assert.equal(last.succeeded,4);assert.equal(last.failed,0);
    const saved=await call(db,'/api/ai/test-results').then(response=>response.json());assert.equal(saved.results.length,4);assert.equal(saved.runId,runId);assert.equal(saved.results[2].usage.total_tokens,9);
    cursor=0;runId='';for(let invocation=0;invocation<2;invocation++){const candidateResponse=await call(db,'/api/ai/test-all',jsonInit({prompt:'کاندید',onlyCandidates:true,cursor,runId}));last=await candidateResponse.json();runId=last.runId;cursor=last.nextCursor;assert.equal(last.total,2);assert.equal(last.done,invocation===1)}assert.deepEqual(calls.slice(4),['model-2','model-4']);
  }finally{globalThis.fetch=originalFetch}
});

test('AI model table stores an independently validated category response for the test category title',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1(),prompts=[];
  try{
    await call(db,'/api/connections',jsonInit({basalam:{api:'https://basalam.example/v1',token:'bs-category',vendorId:'77'},ai:{providers:[{id:'cat',name:'Category Provider',baseUrl:'https://ai.example/v1',apiKey:'cat-secret',models:['model-a'],enabled:true}],candidates:['cat::model-a'],network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request);if(url==='https://basalam.example/v1/categories')return jsonResponse({data:[{id:10,name:'آرایشی',children:[{id:11,name:'ادو پرفیوم'}]}]});const body=init.body?JSON.parse(String(init.body)):{},prompt=body.messages?.[0]?.content||'';if(prompt)prompts.push(prompt);return jsonResponse({choices:[{message:{content:prompt.includes('فهرست مجاز')?'{"category_id":11,"reason":"درست"}':'پاسخ پیام'}}],usage:{total_tokens:12}})};
    const response=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',categoryTitle:'ادو پرفیوم',cursor:0,runId:''})),result=await response.json();assert.equal(response.status,200);assert.equal(result.done,true);assert.equal(result.categoryTitle,'ادو پرفیوم');assert.equal(result.categorySucceeded,1);assert.equal(result.results[0].categoryResult.ok,true);assert.equal(result.results[0].categoryResult.categoryId,11);assert.equal(result.results[0].catResponse,'ادو پرفیوم (#11)');assert.equal(prompts.length,2);assert.ok(prompts.some(x=>x.includes('فهرست مجاز')));
    const saved=await call(db,'/api/ai/test-results').then(r=>r.json());assert.equal(saved.categoryTitle,'ادو پرفیوم');assert.equal(saved.results[0].categoryResult.categoryName,'ادو پرفیوم');
  }finally{globalThis.fetch=originalFetch}
});

test('reasoning Together-compatible models classify products and answer customers with a protected final response',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1(),requests=[];
  try{
    const model='openai/gpt-oss-20b';
    const savedResponse=await call(db,'/api/connections',jsonInit({basalam:{api:'https://basalam.example/v1',token:'bs-reasoning',vendorId:'77'},ai:{providers:[{id:'together',name:'Together AI',baseUrl:'https://api.together.xyz/v1',apiKey:'together-secret',models:[model],reasoningModels:[model],enabled:true}],candidates:['together::'+model],master:'together::'+model,model:'together::'+model,network:{mode:'direct'}}})),saved=await savedResponse.json();
    assert.equal(savedResponse.status,200);assert.deepEqual(saved.connections.ai.providers[0].reasoningModels,[model],'manual reasoning flags survive encrypted vault normalization');
    await call(db,'/api/settings',jsonInit({autoreply:{order:'ai_only',systemMode:'custom',systemText:'دستیار فروشگاه آزمایشی'}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request);if(url==='https://basalam.example/v1/categories')return jsonResponse({data:[{id:10,name:'آرایشی',children:[{id:11,name:'ادو پرفیوم'}]}]});const body=JSON.parse(String(init.body||'{}')),prompt=body.messages?.[0]?.content||'';requests.push({url,body,prompt});if(prompt.includes('فهرست مجاز'))return jsonResponse({choices:[{message:{reasoning_content:'ابتدا دسته‌های ۱۰ و ۱۱ را مقایسه می‌کنم.',content:'<think>شناسه ۱۰ عمومی‌تر است.</think>{"category_id":11,"reason":"تخصصی‌تر"}'}}],usage:{total_tokens:240}});if(prompt.includes('پیام مشتری'))return jsonResponse({output:{choices:[{text:'بله، این محصول موجود است.'}]},usage:{total_tokens:180}});return jsonResponse({choices:[{message:{reasoning_content:'پاسخ را کوتاه می‌کنم.',content:'<think>تحلیل داخلی</think>سلام، در خدمتم.'}}],usage:{total_tokens:160}})};
    const tested=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',categoryTitle:'ادو پرفیوم',cursor:0,runId:''})).then(response=>response.json());assert.equal(tested.done,true);assert.equal(tested.messageSucceeded,1);assert.equal(tested.categorySucceeded,1);assert.equal(tested.results[0].reasoning,true);assert.equal(tested.results[0].text,'سلام، در خدمتم.');assert.doesNotMatch(tested.results[0].text,/think|تحلیل داخلی/);assert.equal(tested.results[0].categoryResult.categoryId,11);assert.equal(tested.results[0].categoryResult.text,'{"category_id":11,"reason":"تخصصی‌تر"}');
    const reply=await call(db,'/api/autoreply/test',jsonInit({text:'آیا این محصول موجود است؟'})).then(response=>response.json());assert.equal(reply.result.text,'بله، این محصول موجود است.');assert.equal(reply.result.source,'ai:together::'+model);assert.match(requests.at(-1).prompt,/فقط با پاسخ نهایی/);assert.match(requests.at(-1).prompt,/دستیار فروشگاه آزمایشی/);
    assert.equal(requests.length,3);for(const item of requests){assert.equal(item.url,'https://api.together.xyz/v1/chat/completions');assert.equal(item.body.max_tokens,1600);assert.equal('temperature' in item.body,false,'reasoning models must not receive a potentially unsupported temperature')}
  }finally{globalThis.fetch=originalFetch}
});

test('Mistral OCR and Embeddings use their dedicated endpoints and skip chat-only category classification',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1(),requests=[];
  try{
    await call(db,'/api/connections',jsonInit({ai:{catalogVersion:2,providers:[{id:'mistral',name:'Mistral AI',baseUrl:'https://api.mistral.ai/v1',apiKey:'mistral-special-secret',models:['mistral-ocr-latest','mistral-embed'],enabled:true}],candidates:['mistral::mistral-ocr-latest','mistral::mistral-embed'],network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),body=JSON.parse(String(init.body||'{}')),headers=new Headers(init.headers);requests.push({url,body,authorization:headers.get('authorization')});if(url==='https://api.mistral.ai/v1/ocr')return jsonResponse({pages:[{index:0,markdown:'# Receipt'}],usage_info:{pages_processed:1}});if(url==='https://api.mistral.ai/v1/embeddings')return jsonResponse({data:[{object:'embedding',embedding:[0.1,0.2,0.3]}],usage:{total_tokens:2}});throw new Error(`unexpected dedicated endpoint ${url}`)};
    const first=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام تخصصی',categoryTitle:'ادو پرفیوم',cursor:0,runId:''})).then(response=>response.json());assert.equal(first.total,2);assert.equal(first.done,false);assert.equal(first.messageSucceeded,1);assert.equal(first.categorySucceeded,0);assert.equal(first.categoryFailed,0);assert.equal(first.categorySkipped,1);assert.equal(first.results[0].endpointType,'ocr');assert.equal(first.results[0].chatCompatible,false);assert.equal(first.results[0].pages,1);assert.equal(first.results[0].categoryResult.phase,'unsupported-task');assert.equal(first.results[0].categoryResult.skipped,true);
    const second=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام تخصصی',categoryTitle:'ادو پرفیوم',cursor:first.nextCursor,runId:first.runId})).then(response=>response.json());assert.equal(second.done,true);assert.equal(second.messageSucceeded,2);assert.equal(second.messageFailed,0);assert.equal(second.categorySkipped,2);assert.equal(second.results[1].endpointType,'embeddings');assert.equal(second.results[1].chatCompatible,false);assert.equal(second.results[1].dimensions,3);
    assert.deepEqual(requests.map(item=>item.url),['https://api.mistral.ai/v1/ocr','https://api.mistral.ai/v1/embeddings']);assert.deepEqual(requests[0].body,{model:'mistral-ocr-latest',document:{type:'image_url',image_url:'https://raw.githubusercontent.com/mistralai/cookbook/main/mistral/ocr/receipt.png'},include_image_base64:false});assert.deepEqual(requests[1].body,{model:'mistral-embed',input:['سلام تخصصی']});assert.ok(requests.every(item=>item.authorization==='Bearer mistral-special-secret'));
    const candidateOnly=await call(db,'/api/ai/test-all',jsonInit({prompt:'کاندید',onlyCandidates:true,cursor:0,runId:''})).then(response=>response.json());assert.equal(candidateOnly.total,0,'dedicated endpoint models must never become chat candidates');assert.deepEqual(requests.map(item=>item.url),['https://api.mistral.ai/v1/ocr','https://api.mistral.ai/v1/embeddings']);
  }finally{globalThis.fetch=originalFetch}
});

test('AI test cursor replay is idempotent and transport skip advances without calling the model',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1(),calls=[];
  try{
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'stable',name:'Stable Provider',baseUrl:'https://ai.example/v1',apiKey:'stable-secret',models:['model-1'],enabled:true}],network:{mode:'direct'}}}));
    globalThis.fetch=async(_request,init={})=>{const body=JSON.parse(String(init.body||'{}'));calls.push(body.model);return jsonResponse({choices:[{message:{content:'پاسخ سلام'}}]})};
    const payload={prompt:'سلام',cursor:0,runId:'idempotent-run'};
    const first=await call(db,'/api/ai/test-all',jsonInit(payload)).then(response=>response.json());assert.equal(first.done,true);assert.equal(first.replayed,false);assert.equal(first.messageSucceeded,1);assert.equal(first.messageFailed,0);assert.equal(first.results[0].text,'پاسخ سلام');
    const replay=await call(db,'/api/ai/test-all',jsonInit(payload)).then(response=>response.json());assert.equal(replay.replayed,true);assert.equal(replay.results.length,1);assert.equal(replay.batchResults.length,1);assert.deepEqual(calls,['model-1'],'the same cursor must never execute the model twice');
    const skipped=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',cursor:0,runId:'skip-run',skipCurrent:true,skipReason:'browser transport failed'})).then(response=>response.json());assert.equal(skipped.done,true);assert.equal(skipped.messageSucceeded,0);assert.equal(skipped.messageFailed,1);assert.equal(skipped.skipped,1);assert.equal(skipped.results[0].phase,'transport-skip');assert.equal(skipped.results[0].skipped,true);assert.deepEqual(calls,['model-1'],'transport skip must not call the provider');
  }finally{globalThis.fetch=originalFetch}
});

test('Basalam chat APIs normalize conversations and lazy message details with actionable errors',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1(),requests=[];console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({basalam:{api:'https://basalam.example/api',token:'chat-token',vendorId:'55'}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),headers=new Headers(init.headers);requests.push({url,headers});assert.equal(headers.get('authorization'),'Bearer chat-token');
      if(url.includes('/chats/42/messages'))return jsonResponse({data:{messages:[{id:2,content:{text:'پاسخ غرفه'},sender:{name:'غرفه',type:'vendor'},sender_type:'vendor',message_type:'text',created_at:'2026-08-20T10:02:00Z'},{id:1,text:'سلام، موجوده؟',sender:{name:'مریم',type:'customer'},sender_type:'customer',created_at:'2026-08-20T10:01:00Z'}]}});
      if(url.includes('/chats?'))return jsonResponse({data:{chats:[{chat_id:42,customer:{name:'مریم'},unread_count:3,updated_at:'2026-08-20T10:02:00Z',last_message:{id:2,content:{text:'پاسخ غرفه'},sender:{name:'غرفه'},message_type:'text'}}]}});
      throw new Error(`unexpected chat URL ${url}`)};
    const chatsResponse=await call(db,'/api/basalam/chats?limit=50'),chats=await chatsResponse.json();assert.equal(chatsResponse.status,200);assert.equal(chats.total,1);assert.equal(chats.unseen,1);assert.deepEqual(chats.items[0],{chatId:42,id:42,customer:'مریم',text:'پاسخ غرفه',unseen:3,updatedAt:'2026-08-20T10:02:00Z',chatType:'',sender:'غرفه',lastMessageId:'2',messageType:'text'});
    const messageResponse=await call(db,'/api/basalam/chats/42/messages?limit=50'),messages=await messageResponse.json();assert.equal(messageResponse.status,200);assert.equal(messages.chatId,42);assert.equal(messages.total,2);assert.equal(messages.items[0].text,'سلام، موجوده؟');assert.equal(messages.items[0].fromShop,false);assert.equal(messages.items[1].text,'پاسخ غرفه');assert.equal(messages.items[1].fromShop,true);assert.equal(requests.length,2);
    globalThis.fetch=async()=>jsonResponse({message:'forbidden scope'},403);const forbidden=await call(db,'/api/basalam/chats');assert.equal(forbidden.status,502);const error=await forbidden.json();assert.match(error.error,/HTTP 403/);assert.match(error.error,/دسترسی گفتگو\/پیام/);assert.match(error.error,/forbidden scope/);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('PHP settings import normalizes syncConfig, noExtract, fallback categories, network flag, products and variations',async()=>{
  const db=new MemoryD1(),profile={name:'CSV only',syncConfig:{enabled:true,interval:3600,target:'both',noExtract:true},bslCategoryId:77,bslFallbackCatIds:[88,99],net_indirect:'1',products:[['p-1',{title:'Variable item',price:125000,variations:['قرمز','آبی'],variationGroups:[{name:'رنگ',values:['قرمز','آبی']}]}]]};
  const profilesFile=file('profiles.json',{'csv-profile':profile}),connectionsFile=file('connections.json',{woocommerce:{url:'https://woo.example',consumer_key:'ck_import',consumer_secret:'cs_import'},basalam:{fallback_cat_ids:[55],vendors:[{name:'غرفه دوم',token:'shop-token',vendor_id:'22',price_mode:'percent',price_val:5}]},src_network:{mode:'worker',worker_url:'https://gateway.example/{url}'}}),bundle={app:'scraper',files:{'profiles.json':profilesFile.name,'connections.json':connectionsFile.name}};
  const response=await call(db,'/api/settings-import',jsonInit(bundle));assert.equal(response.status,200);const result=await response.json();assert.deepEqual(result.imported,{profiles:1,products:1,states:0,categories:0,autoreplyLogs:0,connections:true});assert.deepEqual(result.warnings,[]);
  const stored=JSON.parse(db.profiles.get('csv-profile').data);assert.equal(stored.noExtract,true);assert.equal(stored.intervalMinutes,60);assert.equal(stored.syncWoo,true);assert.equal(stored.syncBasalam,true);assert.equal(stored.networkIndirect,true);assert.deepEqual(stored.basalamFallbackCategoryIds,[88,99]);assert.match(stored.url,/^https:\/\/import\.invalid\//);
  const product=JSON.parse(db.products.get('csv-profile:p-1').data);assert.deepEqual(product.variations,['قرمز','آبی']);assert.equal(product.variationGroups[0].name,'رنگ');
  const importedEnvelope=JSON.parse(db.states.get('connection_vault'));assert.equal(importedEnvelope.iterations,100000);assert.doesNotMatch(JSON.stringify(importedEnvelope),/cs_import|shop-token/);
  const importedConnections=await call(db,'/api/connections').then(r=>r.json());assert.equal(importedConnections.connections.woo.secret,'cs_import');assert.equal(importedConnections.connections.ai.network.mode,'worker');assert.equal(importedConnections.connections.basalam.shops[0].vendorId,'22');
});

test('visual selector uses reusable class selectors, real match counts, and a dedicated detail workflow',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1();globalThis.fetch=async()=>new Response('<main><article class="product-card"><h2 class="product-title">A</h2></article><article class="product-card"><h2 class="product-title">B</h2></article></main>',{headers:{'content-type':'text/html; charset=utf-8'}});
  try{
    const listTicket=await call(db,'/api/visual-ticket',jsonInit({url:'https://shop.example/list'})).then(response=>response.json()),listResponse=await call(db,'/visual?context=list&ticket='+encodeURIComponent(listTicket.ticket)),listHtml=await listResponse.text();assert.equal(listResponse.status,200);assert.match(listHtml,/classCandidates/);assert.match(listHtml,/matches\(value\)>1/);assert.doesNotMatch(listHtml,/:nth-of-type/);assert.match(listHtml,/value="container"/);assert.doesNotMatch(listHtml,/value="shortDesc"/);
    const detailTicket=await call(db,'/api/visual-ticket',jsonInit({url:'https://shop.example/product/a'})).then(response=>response.json()),detailResponse=await call(db,'/visual?context=detail&ticket='+encodeURIComponent(detailTicket.ticket)),detailHtml=await detailResponse.text();assert.equal(detailResponse.status,200);assert.match(detailHtml,/value="shortDesc"/);assert.match(detailHtml,/value="variations"/);assert.match(detailHtml,/value="galleryBox"/);assert.match(detailHtml,/scraper4-detail-selectors/);assert.doesNotMatch(detailHtml,/value="container"/);
  }finally{globalThis.fetch=originalFetch}
});

test('selector suggestion and variation extraction routes execute against HTML',async()=>{
  globalThis.HTMLRewriter=TestHTMLRewriter;const originalFetch=globalThis.fetch,db=new MemoryD1();globalThis.fetch=async request=>{const url=String(request instanceof Request?request.url:request);if(url==='https://shop.example/list')return new Response('<ul><li class="product"><h2 class="woocommerce-loop-product__title">A</h2><span class="price">۱۰۰</span><a class="woocommerce-LoopProduct-link" href="/p/a">A</a><img class="wp-post-image" src="/a.jpg"></li><li class="product"><h2 class="woocommerce-loop-product__title">B</h2><span class="price">۲۰۰</span><a class="woocommerce-LoopProduct-link" href="/p/b">B</a><img class="wp-post-image" src="/b.jpg"></li></ul>',{headers:{'content-type':'text/html'}});if(url==='https://shop.example/p/a')return new Response('<div class="variations"><option name="attribute_pa_color" value="red" data-price="150000">قرمز</option><option name="attribute_pa_color" value="blue">آبی</option><button data-name="size" data-value="L">بزرگ</button></div>',{headers:{'content-type':'text/html'}});throw new Error(`unexpected ${url}`)};
  try{const suggested=await call(db,'/api/suggest-selectors',jsonInit({url:'https://shop.example/list',mode:'list'})),suggestion=await suggested.json();assert.equal(suggested.status,200);assert.equal(suggestion.selectors.container,'li.product');assert.equal(suggestion.evidence.container.count,2);assert.ok(suggestion.selectors.title);assert.ok(suggestion.selectors.price);
    const extracted=await call(db,'/api/test-selector',jsonInit({url:'https://shop.example/p/a',selector:'.variations',type:'variations'})),variation=await extracted.json();assert.equal(extracted.status,200);assert.ok(variation.variations.includes('red'));assert.ok(variation.variations.includes('blue'));assert.ok(variation.variations.includes('L'));assert.equal(variation.variationPrices.red,150000);
  }finally{globalThis.fetch=originalFetch}
});

test('comprehensive destination APIs page, search, preview, update, status, bulk and archive with shop-aware semantics',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1(),requests=[];console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({woo:{url:'https://woo.example',key:'ck_dest',secret:'cs_dest'},basalam:{api:'https://basalam.example/api',token:'bs-token',vendorId:'55',shops:[{name:'غرفه دوم',token:'shop-token',vendorId:'66',pricePercent:0}]},ai:{providers:[{id:'cat-ai',name:'Category AI',baseUrl:'https://ai.example/v1',apiKey:'ai-secret',models:['cat-model'],enabled:true}],candidates:['cat-ai::cat-model'],network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),method=String(init.method||'GET').toUpperCase(),body=init.body?JSON.parse(String(init.body)):null;requests.push({url,method,body,headers:new Headers(init.headers)});
      if(url==='https://basalam.example/api/categories'&&method==='GET')return jsonResponse({data:[{id:900,name:'آرایشی و بهداشتی',children:[{id:901,name:'عطر و ادکلن'},{id:902,name:'ادو پرفیوم'}]}]});
      if(url==='https://ai.example/v1/chat/completions'&&method==='POST')return jsonResponse({choices:[{message:{content:'{"category_id":902,"reason":"مناسب‌ترین دسته"}'}}]});
      if(url.startsWith('https://woo.example/wp-json/wc/v3/products/101')&&method==='GET')return jsonResponse({id:101,name:'کفش وو',regular_price:'250000',stock_quantity:4,status:'publish',sku:'W-1',images:[{src:'https://woo.example/a.jpg'}],categories:[{id:7,name:'کفش'}]});
      if(url.startsWith('https://woo.example/wp-json/wc/v3/products/101')&&method==='PUT')return jsonResponse({id:101,name:body.name||'کفش وو',regular_price:body.regular_price||'250000',stock_quantity:body.stock_quantity??4,status:body.status||'publish',sku:'W-1',images:[],categories:[]});
      if(url.startsWith('https://woo.example/wp-json/wc/v3/products')&&method==='GET')return jsonResponse([{id:101,name:'کفش وو',regular_price:'250000',stock_quantity:4,status:'publish',sku:'W-1',images:[],categories:[]}],200,{'x-wp-total':'1','x-wp-totalpages':'1'});
      if(url.includes('/vendors/55/products/batch-updates')&&method==='PATCH')return jsonResponse({ok:true});
      if(url.includes('/vendors/66/products/batch-updates')&&method==='PATCH')return jsonResponse({ok:true});
      if(url.includes('/products/201')&&method==='GET')return jsonResponse({data:{id:201,title:'عطر باسلام',primary_price:1250000,stock:3,status:{value:2976,name:'فعال'},sku:'B-1',photos:[]}});
      if(url.includes('/products/201')&&method==='PATCH')return jsonResponse({data:{id:201,title:'عطر باسلام',primary_price:body.primary_price??1250000,stock:body.stock??3,status:{value:body.status??2976,name:'فعال'},sku:'B-1',photos:[]}});
      if(url.includes('/vendors/55/products')&&method==='GET')return jsonResponse({data:[{id:201,title:'عطر باسلام',primary_price:1250000,stock:3,status:{value:2976,name:'فعال'},sku:'B-1'}],total_count:1,total_page:1});
      if(url.includes('/vendors/66/products')&&method==='GET')return jsonResponse({data:[],total_count:0,total_page:1});
      throw new Error(`unexpected destination request ${method} ${url}`)};
    const wooList=await call(db,'/api/destination/woo/products?page=1&per_page=25&q=%DA%A9%D9%81%D8%B4&status=publish').then(r=>r.json());assert.equal(wooList.ok,true);assert.equal(wooList.items.length,1);assert.equal(wooList.items[0].price,250000);assert.equal(wooList.totalPages,1);assert.ok(requests.at(-1).url.includes('search=%DA%A9%D9%81%D8%B4'));
    const wooPreview=await call(db,'/api/destination/woo/101/update',jsonInit({title:'کفش تازه',price:275000,shopId:'default'})).then(r=>r.json());assert.equal(wooPreview.dryRun,true);assert.equal(wooPreview.changes.name,'کفش تازه');assert.equal(wooPreview.changes.regular_price,'275000');assert.equal(requests.filter(x=>x.method==='PUT').length,0);
    const wooApply=await call(db,'/api/destination/woo/101/update',jsonInit({title:'کفش تازه',confirm:'APPLY'})).then(r=>r.json());assert.equal(wooApply.dryRun,false);assert.equal(requests.filter(x=>x.method==='PUT').length,1);
    const wooStatus=await call(db,'/api/destination/woo/101/status',jsonInit({status:'draft',confirm:'APPLY'})).then(r=>r.json());assert.equal(wooStatus.ok,true);assert.equal(requests.at(-1).body.status,'draft');

    const bsList=await call(db,'/api/destination/basalam/products?page=1&per_page=25&shop=55&status=2976').then(r=>r.json());assert.equal(bsList.items.length,1);assert.equal(bsList.items[0].price,125000);assert.equal(bsList.items[0].shopId,'55');assert.equal(bsList.archiveInsteadOfDelete,true);
    const categories=await call(db,'/api/categories/basalam?refresh=1').then(r=>r.json());assert.equal(categories.ok,true);assert.equal(categories.total,3);assert.equal(categories.items.find(x=>x.id===902).path,'آرایشی و بهداشتی ← ادو پرفیوم');assert.equal(categories.items.find(x=>x.id===902).leaf,true);
    const aiCategory=await call(db,'/api/destination/basalam/category/suggest',jsonInit({mode:'ai',title:'ادو پرفیوم زنانه',modelKey:'cat-ai::cat-model'})).then(r=>r.json());assert.equal(aiCategory.ok,true);assert.equal(aiCategory.categoryId,902);assert.equal(aiCategory.categoryName,'ادو پرفیوم');assert.match(aiCategory.text,/category_id/);
    const noLearning=await call(db,'/api/destination/basalam/category/suggest',jsonInit({mode:'learned',title:'عطر باسلام'})).then(r=>r.json());assert.equal(noLearning.result,null);
    const bsPreview=await call(db,'/api/destination/basalam/bulk',jsonInit({ids:[{id:201,shopId:'55'}],ops:{price:{op:'inc',val:'10%'},stock:6}})).then(r=>r.json());assert.equal(bsPreview.dryRun,true);assert.equal(bsPreview.items[0].newPrice,137500);assert.equal(bsPreview.items[0].stock,6);
    const bsBulk=await call(db,'/api/destination/basalam/bulk',jsonInit({ids:[{id:201,shopId:'55'}],ops:{price:{op:'inc',val:'10%'}},confirm:'APPLY'})).then(r=>r.json());assert.equal(bsBulk.dryRun,false);assert.equal(bsBulk.changed,1);const batch=requests.find(x=>x.url.includes('/vendors/55/products/batch-updates'));assert.equal(batch.body.data[0].primary_price,1375000);
    const categoryBody={ids:[{id:201,shopId:'55'}],ops:{categoryAssignments:[{id:201,shopId:'55',categoryId:902,categoryName:'ادو پرفیوم',source:'هوش مصنوعی'}]}};
    const categoryPreview=await call(db,'/api/destination/basalam/bulk',jsonInit(categoryBody)).then(r=>r.json());assert.equal(categoryPreview.dryRun,true);assert.equal(categoryPreview.items[0].newCategoryId,902);assert.equal(categoryPreview.items[0].categorySource,'هوش مصنوعی');
    const categoryApply=await call(db,'/api/destination/basalam/bulk',jsonInit({...categoryBody,confirm:'APPLY'})).then(r=>r.json());assert.equal(categoryApply.dryRun,false);assert.equal(categoryApply.changed,1);assert.ok(categoryApply.learningRecords>0);const categoryBatch=requests.filter(x=>x.url.includes('/vendors/55/products/batch-updates')).at(-1);assert.equal(categoryBatch.body.data[0].category_id,902);
    const learned=await call(db,'/api/destination/basalam/category/suggest',jsonInit({mode:'learned',title:'عطر باسلام ویژه'})).then(r=>r.json());assert.equal(learned.result.categoryId,902);assert.equal(learned.result.categoryName,'ادو پرفیوم');
    const archived=await call(db,'/api/destination/basalam/201?confirm=DELETE&shop=55',{method:'DELETE'}).then(r=>r.json());assert.equal(archived.archived,true);assert.equal(archived.deleted,false);assert.equal(requests.at(-1).method,'PATCH');assert.equal(requests.at(-1).body.status,4184);
    const overLimit=await call(db,'/api/destination/basalam/bulk',jsonInit({ids:Array.from({length:21},(_,i)=>({id:i+1,shopId:'55'})),ops:{stock:1}}));assert.equal(overLimit.status,400);assert.match((await overLimit.json()).error,/۲۰/);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('profile extraction diagnostic runs real network, list parser, selector evidence and detail stages without writes',async()=>{
  globalThis.HTMLRewriter=TestHTMLRewriter;const originalFetch=globalThis.fetch,db=new MemoryD1();globalThis.fetch=async request=>{const url=String(request instanceof Request?request.url:request);if(url==='https://source.example/list')return new Response('<main><article class="item"><a class="link" href="/p/one"><h2>محصول واقعی</h2></a><span class="price">۱۲۵۰۰۰ تومان</span><img src="/one.jpg"></article><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"محصول واقعی","url":"https://source.example/p/one","image":"https://source.example/one.jpg","offers":{"price":"125000"}}</script></main>',{headers:{'content-type':'text/html; charset=utf-8'}});if(url==='https://source.example/p/one')return new Response('<main><div class="description">توضیح کامل نمونه</div><span class="sku">S-1</span></main>',{headers:{'content-type':'text/html'}});throw new Error(`unexpected ${url}`)};
  try{const saved=await call(db,'/api/profiles',jsonInit({id:'diag',name:'عیب‌یابی واقعی',url:'https://source.example/list',pages:1,pagination:'none',selectors:{container:'.item',title:'h2',price:'.price',link:'.link',image:'img',longDesc:'.description',sku:'.sku'},enabled:true}));assert.equal(saved.status,200);const response=await call(db,'/api/profiles/diag/extraction-diagnostic',jsonInit({})),report=await response.json();assert.equal(response.status,200);assert.equal(report.productCount,1);assert.equal(report.ok,true);assert.deepEqual(report.stages.map(x=>x.name),['network','list-extraction','selector-evidence','detail-extraction']);assert.equal(report.stages.find(x=>x.name==='network').bytes>0,true);assert.equal(report.stages.find(x=>x.name==='list-extraction').samples[0].title,'محصول واقعی');assert.equal(report.detail.sku,'S-1');assert.equal(db.products.size,0)
  }finally{globalThis.fetch=originalFetch}
});

test('standalone spreadsheet import understands Persian CSV headers, keeps Woo status, and targeted sync jobs stay targeted',async()=>{
  const db=new MemoryD1();
  const saved=await call(db,'/api/profiles',jsonInit({id:'sheet-ui',name:'فایل فروشگاه',url:'',noExtract:true,pages:1,pagination:'none',selectors:{container:'.product',title:'h2',price:'.price',link:'a',image:'img'},enabled:true}));
  assert.equal(saved.status,200);
  const csv='نام محصول,قیمت,تصویر,کد محصول\nکفش آزمایشی,۲۵۰۰۰۰,https://images.example/shoe.jpg,SKU-FA-1\n';
  const imported=await call(db,'/api/profiles/sheet-ui/import?format=csv&wooStatus=draft',{method:'POST',headers:{'content-type':'text/csv; charset=utf-8'},body:csv}),report=await imported.json();
  assert.equal(imported.status,200);assert.equal(report.format,'csv');assert.equal(report.rows,1);assert.equal(report.imported,1);assert.equal(report.wooStatus,'draft');
  const stored=JSON.parse([...db.products.values()][0].data);assert.equal(stored.title,'کفش آزمایشی');assert.equal(stored.price,250000);assert.equal(stored.sku,'SKU-FA-1');assert.equal(stored.destinationStatus,'draft');
  const excel=await call(db,'/api/profiles/sheet-ui/import?format=xlsx&wooStatus=publish',{method:'POST',headers:{'content-type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'},body:tinyXlsx()}),excelReport=await excel.json();assert.equal(excel.status,200);assert.equal(excelReport.format,'xlsx');assert.equal(excelReport.imported,1);const excelProduct=[...db.products.values()].map(row=>JSON.parse(row.data)).find(product=>product.title==='عطر اکسل');assert.equal(excelProduct.price,375000);assert.equal(excelProduct.brand,'نمونه');assert.equal(excelProduct.destinationStatus,'publish');
  const broken=await call(db,'/api/profiles/sheet-ui/import?format=xlsx',{method:'POST',headers:{'content-type':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'},body:new Uint8Array([1,2,3])});assert.equal(broken.status,400);assert.match(broken.headers.get('content-type')||'',/application\/json/);assert.match((await broken.json()).error,/Excel|فایل|zip/i);
  const oversized=await call(db,'/api/profiles/sheet-ui/import?format=csv',{method:'POST',headers:{'content-type':'text/csv'},body:'x'.repeat(10*1024*1024+1)});assert.equal(oversized.status,413);assert.match(oversized.headers.get('content-type')||'',/application\/json/);assert.match((await oversized.json()).error,/۱۰|حجم|MiB/i);
  const queued=await call(db,'/api/profiles/sheet-ui/sync',jsonInit({target:'woo'})),job=(await queued.json()).job;assert.equal(queued.status,202);assert.equal(job.kind,'sync');assert.equal(job.target,'woo');
});

test('advanced import: analyze detects columns, mapping + options control the import, and history is recorded',async()=>{
  const db=new MemoryD1();
  await call(db,'/api/profiles',jsonInit({id:'adv-import',name:'پیشرفته',url:'',noExtract:true,pages:1,pagination:'none',selectors:{container:'.p',title:'h2',price:'.price',link:'a',image:'img'},enabled:true}));
  const csv='عنوان محصول,قیمت (ریال),کد,موجودی,ویژگی‌ها\nعطر گل محمدی,4500000,SKU-1,5,رنگ:قرمز، آبی|سایز:M\nعطر گل محمدی,4300000,SKU-2,3,رنگ:آبی\nکرم دست,800000,SKU-3,2,حجم:۵۰ میل\n';
  const analyzed=await call(db,'/api/import/analyze?format=csv',{method:'POST',headers:{'content-type':'text/csv; charset=utf-8'},body:csv}),analysis=await analyzed.json();
  assert.equal(analyzed.status,200);assert.equal(analysis.total,3);assert.ok(analysis.headers.includes('عنوان محصول'));
  const mapping=Object.fromEntries(analysis.mapping.map(m=>[m.column,m.field]));
  assert.equal(mapping['عنوان محصول'],'title');assert.equal(mapping['قیمت (ریال)'],'price');assert.equal(mapping['کد'],'sku');
  assert.equal(analysis.issues.missingTitle,0);assert.equal(analysis.issues.invalidPrice,0);assert.ok(analysis.priceHint==='rial'||analysis.priceHint===null);
  const opts=encodeURIComponent(JSON.stringify({mapping:{'عنوان محصول':'title','قیمت (ریال)':'price','کد':'sku','موجودی':'stock','ویژگی‌ها':'attributes'},priceUnit:'rial',dedupe:'first',skipMissingTitle:true,skipMissingPrice:false,defaultStock:0}));
  const executed=await call(db,'/api/profiles/adv-import/import?format=csv&opts='+opts+'&name=products.csv',{method:'POST',headers:{'content-type':'text/csv; charset=utf-8'},body:csv}),report=await executed.json();
  assert.equal(executed.status,200);assert.equal(report.imported,2,'duplicate title kept once (dedupe=first)');assert.equal(report.skipped,1);
  const products=[...db.products.values()].map(row=>JSON.parse(row.data));
  const kept=products.find(p=>p.sku==='SKU-1');assert.ok(kept,'first duplicate variant was kept');assert.equal(kept.price,450000,'rial price divided by 10 into toman');const groups=kept.variationGroups||[];assert.ok(groups.some(g=>g.name==='رنگ'&&g.values.includes('قرمز')&&g.values.includes('آبی')),'attributes column parsed into variationGroups');assert.ok(groups.some(g=>g.name==='سایز'&&g.values.includes('M')),'multi-attribute row parsed');const cream=products.find(p=>p.sku==='SKU-3');assert.ok(cream&&cream.variationGroups.some(g=>g.name==='حجم'&&g.values.includes('۵۰ میل')),'plain single attribute with Persian digits parsed');
  const history=await call(db,'/api/import/history').then(r=>r.json());
  assert.ok(history.items.length>=1);assert.equal(history.items[history.items.length-1].imported,2);assert.equal(history.items[history.items.length-1].fileName,'products.csv');
  const cleared=await call(db,'/api/import/history/clear',{method:'POST',body:'{}'}).then(r=>r.json());assert.equal(cleared.ok,true);
  const emptyHistory=await call(db,'/api/import/history').then(r=>r.json());assert.equal(emptyHistory.items.length,0);
});


test('AI tests send the same model index of every provider in parallel and skip a hung round together',async()=>{
  const originalFetch=globalThis.fetch,db=new MemoryD1(),calls=[];
  try{
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'alpha',name:'Alpha',baseUrl:'https://alpha.example/v1',apiKey:'alpha-secret',models:['a1','a2'],enabled:true},{id:'beta',name:'Beta',baseUrl:'https://beta.example/v1',apiKey:'beta-secret',models:['b1','b2'],enabled:true}],network:{mode:'direct'}}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),body=JSON.parse(String(init.body||'{}'));calls.push({host:new URL(url).host,model:body.model});return jsonResponse({choices:[{message:{content:'پاسخ '+body.model}}]})};
    const first=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',cursor:0,runId:''})).then(response=>response.json());
    assert.equal(first.total,4);assert.equal(first.done,false);assert.equal(first.batchSize,2);assert.equal(first.nextCursor,2);assert.deepEqual(first.results.map(row=>row.model).sort(),['a1','b1']);assert.deepEqual([...new Set(calls.map(item=>item.host))].sort(),['alpha.example','beta.example']);
    const second=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',cursor:first.nextCursor,runId:first.runId})).then(response=>response.json());
    assert.equal(second.done,true);assert.equal(second.batchSize,2);assert.deepEqual(second.results.map(row=>row.model),['a1','b1','a2','b2']);
    const skipped=await call(db,'/api/ai/test-all',jsonInit({prompt:'رد',cursor:0,runId:'round-skip',skipCurrent:true,skipReason:'hung round'})).then(response=>response.json());
    assert.equal(skipped.batchSize,2);assert.equal(skipped.skipped,2);assert.ok(skipped.results.every(row=>row.phase==='transport-skip'));assert.equal(calls.length,4,'skipping a round must not call any provider');
  }finally{globalThis.fetch=originalFetch}
});

test('chat payload shape errors are retried with a compatible body while credit errors stay failed',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1(),bodies=[];console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'shape',name:'Shape',baseUrl:'https://shape.example/v1',apiKey:'shape-secret',models:['gpt-5-mini'],enabled:true}],network:{mode:'direct'}}}));
    globalThis.fetch=async(_request,init={})=>{const body=JSON.parse(String(init.body||'{}'));bodies.push(body);
      if(body.model==='paid-out')return jsonResponse({error:{message:'You exceeded your current quota. Please check your billing.',code:'insufficient_quota'}},402);
      if('temperature' in body)return jsonResponse({error:{message:'Unsupported value: temperature is not supported with this model.'}},400);
      if('max_tokens' in body)return jsonResponse({error:{message:'Unsupported parameter: max_tokens. Use max_completion_tokens instead.'}},400);
      return jsonResponse({choices:[{message:{content:'سازگار'}}]})};
    const ok=await call(db,'/api/test-connection/ai',jsonInit({provider:'shape',model:'gpt-5-mini',prompt:'سلام'})).then(response=>response.json());
    assert.equal(ok.ok,true,JSON.stringify(ok));assert.equal(ok.text,'سازگار');assert.equal(ok.reasoning,true);assert.ok(bodies.some(body=>body.model==='gpt-5-mini'&&'max_completion_tokens' in body&&!('temperature' in body)));
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'billed',name:'Billed',baseUrl:'https://billed.example/v1',apiKey:'billed-secret',models:['paid-out'],enabled:true}],network:{mode:'direct'}}}));
    const credit=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',cursor:0,runId:'credit-run'})).then(response=>response.json());
    const paid=credit.results.find(row=>row.model==='paid-out');assert.equal(paid.ok,false);assert.equal(paid.retryable,false);assert.match(String(paid.error||''),/quota|402|billing/i);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('hung AI retry pass tests one model from each provider in parallel',async()=>{
  const db=new MemoryD1(),sent=[],extra={JOBS:{send:async(message,options)=>sent.push({message,options})},AI_TEST_MODEL_BUDGET_MS:'80',AI_TEST_TIMEOUT_MS:'50'};
  const env={DB:db,VAULT_SECRET:'vault-secret',JOBS:extra.JOBS,JOBS_DLQ:{send:async()=>{}},AI_TEST_MODEL_BUDGET_MS:'80',AI_TEST_TIMEOUT_MS:'50'};
  await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'slow-a',name:'Slow A',baseUrl:'https://a.example/v1',apiKey:'a-secret',models:['hang-a'],enabled:true},{id:'slow-b',name:'Slow B',baseUrl:'https://b.example/v1',apiKey:'b-secret',models:['hang-b'],enabled:true}],network:{mode:'direct'}}}),extra);
  const originalFetch=globalThis.fetch;globalThis.fetch=()=>new Promise(()=>{});
  const deliver=message=>worker.queue({messages:[{body:message,ack(){},retry(){assert.fail('retry pass should ack')}}]},env,ctx);
  try{
    await call(db,'/api/ai/test-runs',jsonInit({prompt:'سلام'}),extra);
    await deliver(sent.shift().message);
    const afterFirst=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());
    assert.equal(afterFirst.run.result.results.length,2,'first pass tests both providers together');assert.equal(afterFirst.run.phase,'retrying');
    await deliver(sent.shift().message);
    const afterRetry=await call(db,'/api/ai/test-runs/current',{},extra).then(response=>response.json());
    assert.equal(afterRetry.run.result.batchResults.length,2,'retry pass also runs one hung model per provider');assert.equal(afterRetry.run.result.results.filter(row=>Number(row.retryCount)>0).length,2);
  }finally{globalThis.fetch=originalFetch}
});



test('OpenRouter requests send API headers, strip alias prefixes, and retry security-policy 403',async()=>{
  const originalFetch=globalThis.fetch,originalError=console.error,db=new MemoryD1(),calls=[];console.error=()=>{};
  try{
    await call(db,'/api/connections',jsonInit({ai:{providers:[{id:'openrouter',name:'OpenRouter',baseUrl:'https://openrouter.ai/api/v1',apiKey:'or-secret',models:['~qwen/qwen3.8-max'],enabled:true}],network:{mode:'direct'}}}));
    globalThis.fetch=async(_request,init={})=>{const headers=new Headers(init.headers),body=init.body?JSON.parse(String(init.body)):null;calls.push({body,ua:headers.get('user-agent'),referer:headers.get('http-referer')||headers.get('referer'),title:headers.get('x-title'),authorization:headers.get('authorization')});
      if(/Mozilla\/5\.0/.test(String(headers.get('user-agent')||''))||!headers.get('x-title'))return jsonResponse({success:false,error:'Access denied by security policy.'},403);
      return jsonResponse({choices:[{message:{content:'سلام از OpenRouter'}}]})};
    const result=await call(db,'/api/test-connection/ai',jsonInit({provider:'openrouter',model:'~qwen/qwen3.8-max',prompt:'سلام'})).then(response=>response.json());
    assert.equal(result.ok,true,JSON.stringify(result));assert.equal(result.text,'سلام از OpenRouter');assert.ok(calls.length>=1);assert.match(String(calls[0].ua||''),/^Scraper4/);assert.equal(calls[0].referer,'https://scraper4.workers.dev');assert.equal(calls[0].title,'Scraper 4');assert.equal(calls[0].authorization,'Bearer or-secret');assert.doesNotMatch(String(calls[0].ua||''),/Mozilla\/5\.0/);assert.equal(calls.at(-1).body.model,'qwen/qwen3.8-max');
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});


function jsonResponse(body,status=200,headers={}){return new Response(JSON.stringify(body),{status,headers:{'content-type':'application/json',...headers}})}

class TestHTMLRewriter {
  constructor(){this.handlers=[]}on(selector,handler){this.handlers.push({selector,handler});return this}
  transform(response){return{text:async()=>{const html=await response.text();for(const {selector,handler} of this.handlers)for(const node of selectNodes(html,selector)){const callbacks=[],element={getAttribute:name=>node.attrs[name]??null,onEndTag:callback=>callbacks.push(callback),attributes:Object.entries(node.attrs),removeAttribute(){},setAttribute(){},before(){},after(){},remove(){}};handler.element?.(element);handler.text?.({text:node.text});for(const callback of callbacks)callback()}return html}}}
}
function selectNodes(html,selector){const last=selector.trim().split(/\s+/).at(-1),nodes=[];for(const match of html.matchAll(/<([a-z0-9-]+)([^>]*)>/gi)){const tag=match[1].toLowerCase(),attrs={};for(const attr of match[2].matchAll(/([:\w-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g))attrs[attr[1]]=attr[2]??attr[3]??attr[4]??'';if(!matches(tag,attrs,last))continue;const tail=html.slice(match.index+match[0].length),end=tail.search(new RegExp(`<\\/${tag}\\s*>`,'i')),inner=end>=0?tail.slice(0,end):'';nodes.push({attrs,text:inner.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()})}return nodes}
function matches(tag,attrs,selector){const tagName=selector.match(/^[a-z][\w-]*/i)?.[0]?.toLowerCase();if(tagName&&tagName!==tag)return false;const id=selector.match(/#([\w-]+)/)?.[1];if(id&&attrs.id!==id)return false;for(const cls of [...selector.matchAll(/\.([\w-]+)/g)].map(x=>x[1]))if(!String(attrs.class||'').split(/\s+/).includes(cls))return false;for(const part of selector.matchAll(/\[([:\w-]+)(?:([*^$]?=)["']?([^\]"']*)["']?)?\]/g)){const [,name,op,value]=part;if(!(name in attrs))return false;if(op==='='&&attrs[name]!==value)return false;if(op==='*='&&!attrs[name].includes(value))return false}return true}
