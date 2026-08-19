import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import worker from '../scraper4.worker.js';

const ctx={waitUntil(){},passThroughOnException(){}};
class MemoryD1 {
  constructor(){this.states=new Map();this.profiles=new Map();this.products=new Map()}
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
    if(s.startsWith('SELECT 1 AS found FROM products'))return this.db.products.has(`${v[0]}:${v[1]}`)?{found:1}:null;
    return null;
  }
  async all(){const s=this.sql;let results=[];if(s.startsWith('SELECT * FROM profiles ORDER BY'))results=[...this.db.profiles.values()];return{success:true,results}}
  async run(){const s=this.sql,v=this.values;
    if(s.startsWith('INSERT INTO app_state'))this.db.states.set(v[0],v[1]);
    else if(s.startsWith('INSERT INTO profiles'))this.db.profiles.set(v[0],{id:v[0],data:v[1],enabled:v[2],interval_minutes:v[3],created_at:v[4],updated_at:v[5],last_run_at:null});
    else if(s.startsWith('INSERT INTO products'))this.db.products.set(`${v[0]}:${v[1]}`,{profile_id:v[0],source_key:v[1],data:v[2],title:v[3],price:v[4],source_url:v[5]});
    return{success:true,meta:{changes:1}};
  }
}
const call=(db,path,init={},extra={})=>worker.fetch(new Request(`https://worker.test${path}`,init),{DB:db,VAULT_SECRET:'vault-secret',JOBS:{send:async()=>{}},JOBS_DLQ:{send:async()=>{}},...extra},ctx);
const jsonInit=body=>({method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)});
const file=(name,value)=>{const text=JSON.stringify(value);return{name:{size:Buffer.byteLength(text),b64:Buffer.from(text).toString('base64')}}};

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

test('PHP settings import normalizes syncConfig, noExtract, fallback categories, network flag, products and variations',async()=>{
  const db=new MemoryD1(),profile={name:'CSV only',syncConfig:{enabled:true,interval:3600,target:'both',noExtract:true},bslCategoryId:77,bslFallbackCatIds:[88,99],net_indirect:'1',products:[['p-1',{title:'Variable item',price:125000,variations:['قرمز','آبی'],variationGroups:[{name:'رنگ',values:['قرمز','آبی']}]}]]};
  const profilesFile=file('profiles.json',{'csv-profile':profile}),connectionsFile=file('connections.json',{woocommerce:{url:'https://woo.example',consumer_key:'ck_import',consumer_secret:'cs_import'},basalam:{fallback_cat_ids:[55],vendors:[{name:'غرفه دوم',token:'shop-token',vendor_id:'22',price_mode:'percent',price_val:5}]},src_network:{mode:'worker',worker_url:'https://gateway.example/{url}'}}),bundle={app:'scraper',files:{'profiles.json':profilesFile.name,'connections.json':connectionsFile.name}};
  const response=await call(db,'/api/settings-import',jsonInit(bundle));assert.equal(response.status,200);const result=await response.json();assert.deepEqual(result.imported,{profiles:1,products:1,states:0,categories:0,autoreplyLogs:0,connections:true});assert.deepEqual(result.warnings,[]);
  const stored=JSON.parse(db.profiles.get('csv-profile').data);assert.equal(stored.noExtract,true);assert.equal(stored.intervalMinutes,60);assert.equal(stored.syncWoo,true);assert.equal(stored.syncBasalam,true);assert.equal(stored.networkIndirect,true);assert.deepEqual(stored.basalamFallbackCategoryIds,[88,99]);assert.match(stored.url,/^https:\/\/import\.invalid\//);
  const product=JSON.parse(db.products.get('csv-profile:p-1').data);assert.deepEqual(product.variations,['قرمز','آبی']);assert.equal(product.variationGroups[0].name,'رنگ');
  const importedEnvelope=JSON.parse(db.states.get('connection_vault'));assert.equal(importedEnvelope.iterations,100000);assert.doesNotMatch(JSON.stringify(importedEnvelope),/cs_import|shop-token/);
  const importedConnections=await call(db,'/api/connections').then(r=>r.json());assert.equal(importedConnections.connections.woo.secret,'cs_import');assert.equal(importedConnections.connections.ai.network.mode,'worker');assert.equal(importedConnections.connections.basalam.shops[0].vendorId,'22');
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
    await call(db,'/api/connections',jsonInit({woo:{url:'https://woo.example',key:'ck_dest',secret:'cs_dest'},basalam:{api:'https://basalam.example/api',token:'bs-token',vendorId:'55',shops:[{name:'غرفه دوم',token:'shop-token',vendorId:'66',pricePercent:0}]}}));
    globalThis.fetch=async(request,init={})=>{const url=String(request instanceof Request?request.url:request),method=String(init.method||'GET').toUpperCase(),body=init.body?JSON.parse(String(init.body)):null;requests.push({url,method,body,headers:new Headers(init.headers)});
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
    const bsPreview=await call(db,'/api/destination/basalam/bulk',jsonInit({ids:[{id:201,shopId:'55'}],ops:{price:{op:'inc',val:'10%'},stock:6}})).then(r=>r.json());assert.equal(bsPreview.dryRun,true);assert.equal(bsPreview.items[0].newPrice,137500);assert.equal(bsPreview.items[0].stock,6);
    const bsBulk=await call(db,'/api/destination/basalam/bulk',jsonInit({ids:[{id:201,shopId:'55'}],ops:{price:{op:'inc',val:'10%'}},confirm:'APPLY'})).then(r=>r.json());assert.equal(bsBulk.dryRun,false);assert.equal(bsBulk.changed,1);const batch=requests.find(x=>x.url.includes('/vendors/55/products/batch-updates'));assert.equal(batch.body.data[0].primary_price,1375000);
    const archived=await call(db,'/api/destination/basalam/201?confirm=DELETE&shop=55',{method:'DELETE'}).then(r=>r.json());assert.equal(archived.archived,true);assert.equal(archived.deleted,false);assert.equal(requests.at(-1).method,'PATCH');assert.equal(requests.at(-1).body.status,4184);
    const overLimit=await call(db,'/api/destination/basalam/bulk',jsonInit({ids:Array.from({length:21},(_,i)=>({id:i+1,shopId:'55'})),ops:{stock:1}}));assert.equal(overLimit.status,400);assert.match((await overLimit.json()).error,/۲۰/);
  }finally{globalThis.fetch=originalFetch;console.error=originalError}
});

test('profile extraction diagnostic runs real network, list parser, selector evidence and detail stages without writes',async()=>{
  globalThis.HTMLRewriter=TestHTMLRewriter;const originalFetch=globalThis.fetch,db=new MemoryD1();globalThis.fetch=async request=>{const url=String(request instanceof Request?request.url:request);if(url==='https://source.example/list')return new Response('<main><article class="item"><a class="link" href="/p/one"><h2>محصول واقعی</h2></a><span class="price">۱۲۵۰۰۰ تومان</span><img src="/one.jpg"></article><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"محصول واقعی","url":"https://source.example/p/one","image":"https://source.example/one.jpg","offers":{"price":"125000"}}</script></main>',{headers:{'content-type':'text/html; charset=utf-8'}});if(url==='https://source.example/p/one')return new Response('<main><div class="description">توضیح کامل نمونه</div><span class="sku">S-1</span></main>',{headers:{'content-type':'text/html'}});throw new Error(`unexpected ${url}`)};
  try{const saved=await call(db,'/api/profiles',jsonInit({id:'diag',name:'عیب‌یابی واقعی',url:'https://source.example/list',pages:1,pagination:'none',selectors:{container:'.item',title:'h2',price:'.price',link:'.link',image:'img',longDesc:'.description',sku:'.sku'},enabled:true}));assert.equal(saved.status,200);const response=await call(db,'/api/profiles/diag/extraction-diagnostic',jsonInit({})),report=await response.json();assert.equal(response.status,200);assert.equal(report.productCount,1);assert.equal(report.ok,true);assert.deepEqual(report.stages.map(x=>x.name),['network','list-extraction','selector-evidence','detail-extraction']);assert.equal(report.stages.find(x=>x.name==='network').bytes>0,true);assert.equal(report.stages.find(x=>x.name==='list-extraction').samples[0].title,'محصول واقعی');assert.equal(report.detail.sku,'S-1');assert.equal(db.products.size,0)
  }finally{globalThis.fetch=originalFetch}
});

function jsonResponse(body,status=200,headers={}){return new Response(JSON.stringify(body),{status,headers:{'content-type':'application/json',...headers}})}

class TestHTMLRewriter {
  constructor(){this.handlers=[]}on(selector,handler){this.handlers.push({selector,handler});return this}
  transform(response){return{text:async()=>{const html=await response.text();for(const {selector,handler} of this.handlers)for(const node of selectNodes(html,selector)){const callbacks=[],element={getAttribute:name=>node.attrs[name]??null,onEndTag:callback=>callbacks.push(callback),attributes:Object.entries(node.attrs),removeAttribute(){},setAttribute(){},before(){},after(){},remove(){}};handler.element?.(element);handler.text?.({text:node.text});for(const callback of callbacks)callback()}return html}}}
}
function selectNodes(html,selector){const last=selector.trim().split(/\s+/).at(-1),nodes=[];for(const match of html.matchAll(/<([a-z0-9-]+)([^>]*)>/gi)){const tag=match[1].toLowerCase(),attrs={};for(const attr of match[2].matchAll(/([:\w-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g))attrs[attr[1]]=attr[2]??attr[3]??attr[4]??'';if(!matches(tag,attrs,last))continue;const tail=html.slice(match.index+match[0].length),end=tail.search(new RegExp(`<\\/${tag}\\s*>`,'i')),inner=end>=0?tail.slice(0,end):'';nodes.push({attrs,text:inner.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()})}return nodes}
function matches(tag,attrs,selector){const tagName=selector.match(/^[a-z][\w-]*/i)?.[0]?.toLowerCase();if(tagName&&tagName!==tag)return false;const id=selector.match(/#([\w-]+)/)?.[1];if(id&&attrs.id!==id)return false;for(const cls of [...selector.matchAll(/\.([\w-]+)/g)].map(x=>x[1]))if(!String(attrs.class||'').split(/\s+/).includes(cls))return false;for(const part of selector.matchAll(/\[([:\w-]+)(?:([*^$]?=)["']?([^\]"']*)["']?)?\]/g)){const [,name,op,value]=part;if(!(name in attrs))return false;if(op==='='&&attrs[name]!==value)return false;if(op==='*='&&!attrs[name].includes(value))return false}return true}
