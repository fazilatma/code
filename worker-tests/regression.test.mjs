import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {strToU8,zipSync} from 'fflate';
import worker from '../scraper4.worker.js';

const ctx={waitUntil(){},passThroughOnException(){}};
class MemoryD1 {
  constructor(){this.states=new Map();this.profiles=new Map();this.products=new Map();this.jobs=new Map();this.categoryLearning=new Map()}
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
  async all(){const s=this.sql;let results=[];if(s.startsWith('SELECT * FROM profiles ORDER BY'))results=[...this.db.profiles.values()];if(s.startsWith('SELECT * FROM category_learning ORDER BY'))results=[...this.db.categoryLearning.values()].sort((a,b)=>b.hits-a.hits).slice(0,Number(this.values[0])||1000);return{success:true,results}}
  async run(){const s=this.sql,v=this.values;
    if(s.startsWith('INSERT INTO app_state'))this.db.states.set(v[0],v[1]);
    else if(s.startsWith('INSERT INTO profiles'))this.db.profiles.set(v[0],{id:v[0],data:v[1],enabled:v[2],interval_minutes:v[3],created_at:v[4],updated_at:v[5],last_run_at:null});
    else if(s.startsWith('INSERT INTO products'))this.db.products.set(`${v[0]}:${v[1]}`,{profile_id:v[0],source_key:v[1],data:v[2],title:v[3],price:v[4],source_url:v[5]});
    else if(s.startsWith('INSERT INTO category_learning')){const key=`${v[0]}:${v[1]}`,previous=this.db.categoryLearning.get(key);this.db.categoryLearning.set(key,{phrase:v[0],category_id:v[1],category_name:v[2],hits:(previous?.hits||0)+(s.includes('VALUES(?,?,?,1,?)')?1:Number(v[3])||1),updated_at:v.at(-1)})}
    else if(s.startsWith('INSERT INTO jobs'))this.db.jobs.set(v[0],{id:v[0],profile_id:v[1],kind:v[2],target:v[3],status:'queued',phase:'waiting',total:0,processed:0,added:0,updated:0,failed:0,stop_requested:0,error:null,log:'[]',created_at:v[4],updated_at:v[5],started_at:null,finished_at:null});
    return{success:true,meta:{changes:1}};
  }
}
const call=(db,path,init={},extra={})=>worker.fetch(new Request(`https://worker.test${path}`,init),{DB:db,VAULT_SECRET:'vault-secret',JOBS:{send:async()=>{}},JOBS_DLQ:{send:async()=>{}},...extra},ctx);
const jsonInit=body=>({method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)});
const file=(name,value)=>{const text=JSON.stringify(value);return{name:{size:Buffer.byteLength(text),b64:Buffer.from(text).toString('base64')}}};
function tinyXlsx(){const files={'[Content_Types].xml':'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>','_rels/.rels':'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>','xl/workbook.xml':'<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets></workbook>','xl/_rels/workbook.xml.rels':'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>','xl/worksheets/sheet1.xml':'<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:C2"/><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>نام محصول</t></is></c><c r="B1" t="inlineStr"><is><t>قیمت</t></is></c><c r="C1" t="inlineStr"><is><t>برند</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>عطر اکسل</t></is></c><c r="B2"><v>375000</v></c><c r="C2" t="inlineStr"><is><t>نمونه</t></is></c></row></sheetData></worksheet>'};return zipSync(Object.fromEntries(Object.entries(files).map(([name,text])=>[name,strToU8(text)])))}

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
      assert.equal(last.maxModelsPerInvocation,1);assert.equal(last.batchResults.length,1);assert.equal(last.results.length,invocation+1);assert.equal(last.total,4);assert.equal(last.done,invocation===3);
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
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request);if(url==='https://basalam.example/v1/categories')return jsonResponse({data:[{id:10,name:'آرایشی',children:[{id:11,name:'ادو پرفیوم'}]}]});const body=JSON.parse(String(init.body||'{}')),prompt=body.messages?.[0]?.content||'';prompts.push(prompt);return jsonResponse({choices:[{message:{content:prompt.includes('فهرست مجاز')?'{"category_id":11,"reason":"درست"}':'پاسخ پیام'}}],usage:{total_tokens:12}})};
    const response=await call(db,'/api/ai/test-all',jsonInit({prompt:'سلام',categoryTitle:'ادو پرفیوم',cursor:0,runId:''})),result=await response.json();assert.equal(response.status,200);assert.equal(result.done,true);assert.equal(result.categoryTitle,'ادو پرفیوم');assert.equal(result.categorySucceeded,1);assert.equal(result.results[0].categoryResult.ok,true);assert.equal(result.results[0].categoryResult.categoryId,11);assert.equal(result.results[0].catResponse,'ادو پرفیوم (#11)');assert.equal(prompts.length,2);assert.ok(prompts.some(x=>x.includes('فهرست مجاز')));
    const saved=await call(db,'/api/ai/test-results').then(r=>r.json());assert.equal(saved.categoryTitle,'ادو پرفیوم');assert.equal(saved.results[0].categoryResult.categoryName,'ادو پرفیوم');
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

function jsonResponse(body,status=200,headers={}){return new Response(JSON.stringify(body),{status,headers:{'content-type':'application/json',...headers}})}

class TestHTMLRewriter {
  constructor(){this.handlers=[]}on(selector,handler){this.handlers.push({selector,handler});return this}
  transform(response){return{text:async()=>{const html=await response.text();for(const {selector,handler} of this.handlers)for(const node of selectNodes(html,selector)){const callbacks=[],element={getAttribute:name=>node.attrs[name]??null,onEndTag:callback=>callbacks.push(callback),attributes:Object.entries(node.attrs),removeAttribute(){},setAttribute(){},before(){},after(){},remove(){}};handler.element?.(element);handler.text?.({text:node.text});for(const callback of callbacks)callback()}return html}}}
}
function selectNodes(html,selector){const last=selector.trim().split(/\s+/).at(-1),nodes=[];for(const match of html.matchAll(/<([a-z0-9-]+)([^>]*)>/gi)){const tag=match[1].toLowerCase(),attrs={};for(const attr of match[2].matchAll(/([:\w-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g))attrs[attr[1]]=attr[2]??attr[3]??attr[4]??'';if(!matches(tag,attrs,last))continue;const tail=html.slice(match.index+match[0].length),end=tail.search(new RegExp(`<\\/${tag}\\s*>`,'i')),inner=end>=0?tail.slice(0,end):'';nodes.push({attrs,text:inner.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()})}return nodes}
function matches(tag,attrs,selector){const tagName=selector.match(/^[a-z][\w-]*/i)?.[0]?.toLowerCase();if(tagName&&tagName!==tag)return false;const id=selector.match(/#([\w-]+)/)?.[1];if(id&&attrs.id!==id)return false;for(const cls of [...selector.matchAll(/\.([\w-]+)/g)].map(x=>x[1]))if(!String(attrs.class||'').split(/\s+/).includes(cls))return false;for(const part of selector.matchAll(/\[([:\w-]+)(?:([*^$]?=)["']?([^\]"']*)["']?)?\]/g)){const [,name,op,value]=part;if(!(name in attrs))return false;if(op==='='&&attrs[name]!==value)return false;if(op==='*='&&!attrs[name].includes(value))return false}return true}
