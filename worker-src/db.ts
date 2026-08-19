import { getEnv, type D1Database, type D1PreparedStatement } from './env.js';
import { SCHEMA } from './schema.js';
import type { Job, Product, Profile } from './types.js';

const ready = new WeakMap<object, Promise<void>>();
const now = () => new Date().toISOString();
const json = <T>(value: unknown, fallback: T): T => {
  if (value === null || value === undefined) return fallback;
  if (typeof value !== 'string') return value as T;
  try { return JSON.parse(value) as T; } catch { return fallback; }
};
const clean = (values: unknown[]) => values.map(value => value === undefined ? null : typeof value === 'boolean' ? Number(value) : value);
function statement(sql: string, values: unknown[] = []): D1PreparedStatement { return getEnv().DB.prepare(sql).bind(...clean(values)); }
async function rows<T = any>(sql: string, values: unknown[] = []): Promise<T[]> {
  const result = await statement(sql, values).all<T>();
  if (!result.success) throw new Error(result.error || 'D1 query failed');
  return result.results || [];
}
async function run(sql: string, values: unknown[] = []): Promise<number> {
  const result = await statement(sql, values).run();
  if (!result.success) throw new Error(result.error || 'D1 query failed');
  return Number(result.meta?.changes || 0);
}

export async function ensureSchema(db: D1Database = getEnv().DB): Promise<void> {
  const key = db as unknown as object;
  let promise = ready.get(key);
  if (!promise) {
    // Every statement in SCHEMA is idempotent. Running the full bootstrap once per
    // isolate also repairs a partially initialized D1 database; checking for only
    // one table could otherwise leave the remaining schema missing forever.
    promise = (async () => {
      const statements=SCHEMA.split(';').map(sql=>sql.trim()).filter(Boolean).map(sql=>db.prepare(sql));
      await db.batch(statements);
    })().catch(error => { ready.delete(key); throw error; });
    ready.set(key, promise);
  }
  await promise;
}

function profileFromRow(row: any): Profile {
  const data = json<Profile>(row.data, {} as Profile);
  return { ...data, enabled: Boolean(row.enabled), intervalMinutes: Number(row.interval_minutes || 0), lastRunAt: row.last_run_at || null,
    createdAt: row.created_at || data.createdAt, updatedAt: row.updated_at || data.updatedAt };
}
function jobFromRow(row: any): Job {
  return { id:String(row.id),profileId:String(row.profile_id),kind:row.kind,target:row.target,status:row.status,phase:String(row.phase),
    total:Number(row.total||0),processed:Number(row.processed||0),added:Number(row.added||0),updated:Number(row.updated||0),failed:Number(row.failed||0),
    stopRequested:Boolean(row.stop_requested),error:row.error == null ? null : String(row.error),log:json(row.log,[]),createdAt:String(row.created_at),
    startedAt:row.started_at ? String(row.started_at) : null,finishedAt:row.finished_at ? String(row.finished_at) : null,updatedAt:String(row.updated_at) };
}
function productFromRow(row: any): Product { return json<Product>(row.data, {} as Product); }

export async function listProfiles(): Promise<Profile[]> { return (await rows('SELECT * FROM profiles ORDER BY updated_at DESC')).map(profileFromRow); }
export async function getProfile(id: string): Promise<Profile | null> { const row = await statement('SELECT * FROM profiles WHERE id=?',[id]).first(); return row ? profileFromRow(row) : null; }
export async function saveProfile(profile: Profile): Promise<Profile> {
  const previous = await getProfile(profile.id); const timestamp = now();
  const value = { ...profile, createdAt: previous?.createdAt || profile.createdAt || timestamp, updatedAt: timestamp };
  await run(`INSERT INTO profiles(id,data,enabled,interval_minutes,created_at,updated_at) VALUES(?,?,?,?,?,?)
    ON CONFLICT(id) DO UPDATE SET data=excluded.data,enabled=excluded.enabled,interval_minutes=excluded.interval_minutes,updated_at=excluded.updated_at`,
    [value.id,JSON.stringify(value),value.enabled,value.intervalMinutes,value.createdAt,value.updatedAt]);
  return (await getProfile(value.id))!;
}
export async function deleteProfile(id: string): Promise<boolean> {
  const db=getEnv().DB; await db.batch([
    db.prepare("DELETE FROM app_state WHERE key IN (SELECT 'job_checkpoint:'||id FROM jobs WHERE profile_id=?)").bind(id),
    db.prepare('DELETE FROM destination_map WHERE profile_id=?').bind(id),db.prepare('DELETE FROM products WHERE profile_id=?').bind(id),
    db.prepare('DELETE FROM jobs WHERE profile_id=?').bind(id),db.prepare('DELETE FROM profiles WHERE id=?').bind(id)
  ]); return true;
}

export async function createJob(profileId: string, kind: Job['kind'], target: Job['target']): Promise<Job> {
  const active = await statement("SELECT * FROM jobs WHERE profile_id=? AND kind=? AND status IN ('queued','running') ORDER BY created_at LIMIT 1",[profileId,kind]).first();
  if (active) return jobFromRow(active);
  const id=crypto.randomUUID(),timestamp=now();
  try { await run('INSERT INTO jobs(id,profile_id,kind,target,created_at,updated_at) VALUES(?,?,?,?,?,?)',[id,profileId,kind,target,timestamp,timestamp]); }
  catch (error) {
    const concurrent=await statement("SELECT * FROM jobs WHERE profile_id=? AND kind=? AND status IN ('queued','running') ORDER BY created_at LIMIT 1",[profileId,kind]).first();
    if (concurrent) return jobFromRow(concurrent); throw error;
  }
  return (await getJob(id))!;
}
export async function getJob(id:string):Promise<Job|null>{const row=await statement('SELECT * FROM jobs WHERE id=?',[id]).first();return row?jobFromRow(row):null;}
export async function listJobs(limit=50):Promise<Job[]>{return(await rows('SELECT * FROM jobs ORDER BY created_at DESC LIMIT ?',[Math.max(1,limit)])).map(jobFromRow);}
export async function claimJob(id?:string):Promise<Job|null>{
  const candidate=id?await statement("SELECT id FROM jobs WHERE id=? AND status='queued'",[id]).first<{id:string}>():await statement("SELECT id FROM jobs WHERE status='queued' ORDER BY created_at LIMIT 1").first<{id:string}>();
  if(!candidate)return null;const timestamp=now();const changed=await run("UPDATE jobs SET status='running',phase='starting',started_at=?,updated_at=? WHERE id=? AND status='queued'",[timestamp,timestamp,candidate.id]);
  return changed?getJob(candidate.id):null;
}
export async function updateJob(id:string,patch:Partial<Job>):Promise<void>{
  const allowed:Record<string,string>={status:'status',phase:'phase',total:'total',processed:'processed',added:'added',updated:'updated',failed:'failed',stopRequested:'stop_requested',error:'error',log:'log',finishedAt:'finished_at'};
  const entries=Object.entries(patch).filter(([key])=>allowed[key]);if(!entries.length)return;
  const sets=entries.map(([key])=>`${allowed[key]}=?`),values=entries.map(([key,value])=>key==='log'?JSON.stringify(value):value);
  await run(`UPDATE jobs SET ${sets.join(',')},updated_at=? WHERE id=?`,[...values,now(),id]);
}
export async function stopRequested(id:string):Promise<boolean>{const row=await statement('SELECT stop_requested FROM jobs WHERE id=?',[id]).first<{stop_requested:number}>();return Boolean(row?.stop_requested);}
export async function retryJob(id:string):Promise<Job|null>{const timestamp=now(),changed=await run(`UPDATE jobs SET status='queued',phase='waiting',stop_requested=0,error=NULL,started_at=NULL,finished_at=NULL,processed=0,added=0,updated=0,failed=0,updated_at=? WHERE id=? AND status IN ('failed','stopped','done')`,[timestamp,id]);return changed?getJob(id):null;}
export async function deleteJob(id:string):Promise<boolean>{const deleted=await run("DELETE FROM jobs WHERE id=? AND status!='running'",[id]);if(deleted)await deleteState(`job_checkpoint:${id}`);return Boolean(deleted);}
export async function clearFinishedJobs():Promise<number>{await run("DELETE FROM app_state WHERE key IN (SELECT 'job_checkpoint:'||id FROM jobs WHERE status IN ('done','failed','stopped'))");return run("DELETE FROM jobs WHERE status IN ('done','failed','stopped')");}

export async function upsertProduct(profileId:string,product:Product):Promise<'added'|'updated'>{
  const existing=await statement('SELECT 1 AS found FROM products WHERE profile_id=? AND source_key=?',[profileId,product.sourceKey]).first();const timestamp=now();
  await run(`INSERT INTO products(profile_id,source_key,data,title,price,source_url,active,missing_since,created_at,updated_at) VALUES(?,?,?,?,?,?,1,NULL,?,?)
    ON CONFLICT(profile_id,source_key) DO UPDATE SET data=excluded.data,title=excluded.title,price=excluded.price,source_url=excluded.source_url,active=1,missing_since=NULL,updated_at=excluded.updated_at`,
    [profileId,product.sourceKey,JSON.stringify(product),product.title,product.price,product.url,timestamp,timestamp]);return existing?'updated':'added';
}
export async function listProducts(profileId:string,limit=100,offset=0,q=''):Promise<{products:Product[];total:number}>{
  const filter=q?' AND title LIKE ? ESCAPE \'\\\'':'',pattern=`%${q.replace(/[\\%_]/g,'\\$&')}%`,params=q?[profileId,pattern]:[profileId];
  const count=await statement(`SELECT count(*) AS total FROM products WHERE profile_id=?${filter}`,params).first<{total:number}>();
  const result=await rows(`SELECT data FROM products WHERE profile_id=?${filter} ORDER BY updated_at DESC LIMIT ? OFFSET ?`,[...params,limit,offset]);return{products:result.map(productFromRow),total:Number(count?.total||0)};
}
export async function allProducts(profileId:string):Promise<Product[]>{return(await rows('SELECT data FROM products WHERE profile_id=? ORDER BY updated_at',[profileId])).map(productFromRow);}
export async function getProduct(profileId:string,sourceKey:string):Promise<Product|null>{const row=await statement('SELECT data FROM products WHERE profile_id=? AND source_key=?',[profileId,sourceKey]).first();return row?productFromRow(row):null;}
export async function markMissingProducts(profileId:string,seenKeys:string[]):Promise<number>{
  if(!seenKeys.length)return 0;const seen=new Set(seenKeys),existing=await rows<{source_key:string}>('SELECT source_key FROM products WHERE profile_id=? AND active=1',[profileId]),missing=existing.filter(x=>!seen.has(x.source_key));
  if(!missing.length)return 0;const timestamp=now(),db=getEnv().DB;for(let i=0;i<missing.length;i+=50)await db.batch(missing.slice(i,i+50).map(x=>db.prepare('UPDATE products SET active=0,missing_since=COALESCE(missing_since,?),updated_at=? WHERE profile_id=? AND source_key=?').bind(timestamp,timestamp,profileId,x.source_key)));return missing.length;
}
export async function maintenanceRows(profileId=''):Promise<any[]>{
  const productRows=await rows<any>(`SELECT * FROM products ${profileId?'WHERE profile_id=?':''} ORDER BY updated_at DESC`,profileId?[profileId]:[]),maps=await rows<any>(`SELECT * FROM destination_map ${profileId?'WHERE profile_id=?':''}`,profileId?[profileId]:[]),by=new Map<string,any[]>();
  for(const map of maps){const key=`${map.profile_id}\u0000${map.source_key}`,items=by.get(key)||[];items.push(map);by.set(key,items)}
  return productRows.map(row=>({...row,data:json(row.data,{}),active:Boolean(row.active),maps:by.get(`${row.profile_id}\u0000${row.source_key}`)||[]}));
}
export async function setRemoteId(profileId:string,sourceKey:string,target:'woo'|'basalam',id:number):Promise<void>{const column=target==='woo'?'remote_woo_id':'remote_basalam_id';await run(`UPDATE products SET ${column}=?,updated_at=? WHERE profile_id=? AND source_key=?`,[id,now(),profileId,sourceKey]);}
export async function getRemoteId(profileId:string,sourceKey:string,target:'woo'|'basalam'):Promise<number|null>{const column=target==='woo'?'remote_woo_id':'remote_basalam_id',row=await statement(`SELECT ${column} AS id FROM products WHERE profile_id=? AND source_key=?`,[profileId,sourceKey]).first<{id:number|null}>();return row?.id?Number(row.id):null;}
export async function getDestinationId(profileId:string,sourceKey:string,target:string,accountKey='default'):Promise<number|null>{const row=await statement('SELECT remote_id FROM destination_map WHERE profile_id=? AND source_key=? AND target=? AND account_key=?',[profileId,sourceKey,target,accountKey]).first<{remote_id:number}>();return row?.remote_id?Number(row.remote_id):null;}
export async function setDestinationId(profileId:string,sourceKey:string,target:string,accountKey:string,remoteId:number):Promise<void>{await run(`INSERT INTO destination_map(profile_id,source_key,target,account_key,remote_id,updated_at) VALUES(?,?,?,?,?,?) ON CONFLICT(profile_id,source_key,target,account_key) DO UPDATE SET remote_id=excluded.remote_id,updated_at=excluded.updated_at`,[profileId,sourceKey,target,accountKey,remoteId,now()]);}
export async function markProfileRun(id:string):Promise<void>{await run('UPDATE profiles SET last_run_at=?,updated_at=? WHERE id=?',[now(),now(),id]);}

const normalizeLearning=(value:string)=>value.toLowerCase().replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200f\u200e]/g,' ').replace(/[^\p{L}\p{N}\s]/gu,' ').replace(/\s+/g,' ').trim();
export async function learnCategory(title:string,categoryId:number,categoryName='',maxWords=5):Promise<number>{const words=normalizeLearning(title).split(' ').filter(Boolean).slice(0,Math.max(1,Math.min(5,maxWords)));let saved=0;for(let n=1;n<=words.length;n++){const phrase=words.slice(0,n).join(' ');await run(`INSERT INTO category_learning(phrase,category_id,category_name,hits,updated_at) VALUES(?,?,?,1,?) ON CONFLICT(phrase,category_id) DO UPDATE SET hits=category_learning.hits+1,category_name=excluded.category_name,updated_at=excluded.updated_at`,[phrase,categoryId,categoryName,now()]);saved++}return saved;}
export async function findLearnedCategory(title:string,maxWords=5):Promise<{categoryId:number;categoryName:string;phrase:string;hits:number}|null>{const words=normalizeLearning(title).split(' ').filter(Boolean).slice(0,Math.max(1,Math.min(5,maxWords)));for(let n=words.length;n>=1;n--){const phrase=words.slice(0,n).join(' '),row=await statement('SELECT * FROM category_learning WHERE phrase=? ORDER BY hits DESC,updated_at DESC LIMIT 1',[phrase]).first<any>();if(row)return{categoryId:Number(row.category_id),categoryName:String(row.category_name),phrase:String(row.phrase),hits:Number(row.hits)}}return null;}
export async function importCategoryLearning(raw:any):Promise<number>{const items=Array.isArray(raw)?raw:Object.entries(raw||{}).map(([phrase,value]:any)=>({phrase,...(typeof value==='object'?value:{category_id:value})}));let count=0;for(const item of items){const phrase=normalizeLearning(String(item.phrase||item.key||'')),categoryId=Number(item.category_id||item.categoryId||item.cat_id||item.id);if(!phrase||!categoryId)continue;await run(`INSERT INTO category_learning(phrase,category_id,category_name,hits,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(phrase,category_id) DO UPDATE SET category_name=excluded.category_name,hits=MAX(category_learning.hits,excluded.hits),updated_at=excluded.updated_at`,[phrase,categoryId,String(item.category_name||item.categoryName||item.cat_name||item.name||''),Math.max(1,Number(item.hits||item.count||1)),now()]);count++}return count;}
export async function listCategoryLearning(limit=1000):Promise<any[]>{return rows('SELECT * FROM category_learning ORDER BY hits DESC,updated_at DESC LIMIT ?',[limit]);}
export async function addAutoreplyLog(row:{chatId:number;customer:string;input:string;output:string;source:string}):Promise<void>{await run('INSERT INTO autoreply_log(chat_id,customer,input_text,output_text,source) VALUES(?,?,?,?,?)',[row.chatId||null,row.customer,row.input,row.output,row.source]);}
export async function importAutoreplyLog(raw:any):Promise<number>{if(!Array.isArray(raw))return 0;let count=0;for(const row of raw.slice(-5000)){await run('INSERT INTO autoreply_log(chat_id,customer,input_text,output_text,source,created_at) VALUES(?,?,?,?,?,?)',[Number(row.chat_id||0)||null,String(row.customer||row.who||''),String(row.input_text||row.in||''),String(row.output_text||row.out||''),String(row.source||row.rule||''),row.created_at||row.at?new Date(row.created_at||Number(row.at)*1000).toISOString():now()]);count++}return count;}
export async function listAutoreplyLog(limit=100):Promise<any[]>{return rows('SELECT * FROM autoreply_log ORDER BY created_at DESC LIMIT ?',[limit]);}

export async function getState<T>(key:string,fallback:T):Promise<T>{const row=await statement('SELECT value FROM app_state WHERE key=?',[key]).first<{value:string}>();return row?json(row.value,fallback):fallback;}
export async function setState(key:string,value:unknown):Promise<void>{await run(`INSERT INTO app_state(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at`,[key,JSON.stringify(value),now()]);}
export async function deleteState(key:string):Promise<void>{await run('DELETE FROM app_state WHERE key=?',[key]);}

export async function createBackup():Promise<Record<string,unknown>>{const [profiles,products,jobs,states,destinationMap,categoryLearning,autoreplyLog]=await Promise.all([rows('SELECT * FROM profiles ORDER BY created_at'),rows('SELECT * FROM products ORDER BY profile_id,created_at'),rows('SELECT * FROM jobs ORDER BY created_at DESC LIMIT 1000'),rows('SELECT * FROM app_state ORDER BY key'),rows('SELECT * FROM destination_map ORDER BY profile_id,target,account_key'),rows('SELECT * FROM category_learning ORDER BY hits DESC'),rows('SELECT * FROM autoreply_log ORDER BY created_at DESC LIMIT 5000')]);return{app:'scraper4-cloudflare',version:1,createdAt:now(),profiles,products,jobs,states,destinationMap,categoryLearning,autoreplyLog};}
export async function restoreBackup(bundle:any):Promise<{profiles:number;products:number;states:number}>{
  if(!bundle||!['scraper4-cloudflare','scraper4-render'].includes(bundle.app)||bundle.version!==1)throw new Error('Invalid Scraper 4 backup');let profiles=0,products=0,states=0;
  for(const row of bundle.profiles||[]){const data=json<any>(row.data,row.data||{});await saveProfile({...data,id:row.id,enabled:Boolean(row.enabled),intervalMinutes:Number(row.interval_minutes||0),lastRunAt:row.last_run_at||null,createdAt:row.created_at||now(),updatedAt:now()});profiles++;}
  for(const row of bundle.products||[]){const product=json<Product>(row.data,row.data);await upsertProduct(row.profile_id,product);if(row.remote_woo_id)await setRemoteId(row.profile_id,row.source_key,'woo',Number(row.remote_woo_id));if(row.remote_basalam_id)await setRemoteId(row.profile_id,row.source_key,'basalam',Number(row.remote_basalam_id));products++;}
  for(const row of bundle.destinationMap||[])await setDestinationId(row.profile_id,row.source_key,row.target,row.account_key,Number(row.remote_id));
  await importCategoryLearning(bundle.categoryLearning||[]);await importAutoreplyLog(bundle.autoreplyLog||[]);
  for(const row of bundle.states||[]){await setState(row.key,json(row.value,row.value));states++;}return{profiles,products,states};
}
export async function profileStats():Promise<any[]>{const profiles=await listProfiles(),result:any[]=[];for(const profile of profiles){const row=await statement('SELECT count(*) products,count(remote_woo_id) woo_mapped,count(remote_basalam_id) basalam_mapped,max(updated_at) last_product_at FROM products WHERE profile_id=?',[profile.id]).first<any>();result.push({id:profile.id,name:profile.name,...row})}return result;}
export async function reapStalledJobs(minutes=30):Promise<number>{const cutoff=new Date(Date.now()-Math.max(5,minutes)*60_000).toISOString();return run(`UPDATE jobs SET status='failed',phase='watchdog',error='Job was inactive and closed by watchdog',finished_at=?,updated_at=? WHERE status='running' AND updated_at<?`,[now(),now(),cutoff]);}
export async function enqueueDueProfiles():Promise<Job[]>{const timestamp=Date.now(),profiles=await listProfiles(),jobs:Job[]=[];for(const profile of profiles){if(!profile.enabled||!profile.intervalMinutes)continue;const due=!profile.lastRunAt||timestamp-new Date(profile.lastRunAt).getTime()>=profile.intervalMinutes*60_000;if(!due)continue;const job=await createJob(profile.id,profile.noExtract?'sync':'scrape',profile.syncWoo&&profile.syncBasalam?'both':profile.syncWoo?'woo':profile.syncBasalam?'basalam':'none');jobs.push(job);await markProfileRun(profile.id)}return jobs;}
