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

class TestHTMLRewriter {
  constructor(){this.handlers=[]}on(selector,handler){this.handlers.push({selector,handler});return this}
  transform(response){return{text:async()=>{const html=await response.text();for(const {selector,handler} of this.handlers)for(const node of selectNodes(html,selector)){const callbacks=[],element={getAttribute:name=>node.attrs[name]??null,onEndTag:callback=>callbacks.push(callback),attributes:Object.entries(node.attrs),removeAttribute(){},setAttribute(){},before(){},after(){},remove(){}};handler.element?.(element);handler.text?.({text:node.text});for(const callback of callbacks)callback()}return html}}}
}
function selectNodes(html,selector){const last=selector.trim().split(/\s+/).at(-1),nodes=[];for(const match of html.matchAll(/<([a-z0-9-]+)([^>]*)>/gi)){const tag=match[1].toLowerCase(),attrs={};for(const attr of match[2].matchAll(/([:\w-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g))attrs[attr[1]]=attr[2]??attr[3]??attr[4]??'';if(!matches(tag,attrs,last))continue;const tail=html.slice(match.index+match[0].length),end=tail.search(new RegExp(`<\\/${tag}\\s*>`,'i')),inner=end>=0?tail.slice(0,end):'';nodes.push({attrs,text:inner.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim()})}return nodes}
function matches(tag,attrs,selector){const tagName=selector.match(/^[a-z][\w-]*/i)?.[0]?.toLowerCase();if(tagName&&tagName!==tag)return false;const id=selector.match(/#([\w-]+)/)?.[1];if(id&&attrs.id!==id)return false;for(const cls of [...selector.matchAll(/\.([\w-]+)/g)].map(x=>x[1]))if(!String(attrs.class||'').split(/\s+/).includes(cls))return false;for(const part of selector.matchAll(/\[([:\w-]+)(?:([*^$]?=)["']?([^\]"']*)["']?)?\]/g)){const [,name,op,value]=part;if(!(name in attrs))return false;if(op==='='&&attrs[name]!==value)return false;if(op==='*='&&!attrs[name].includes(value))return false}return true}
