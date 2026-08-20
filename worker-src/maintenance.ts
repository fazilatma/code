import { loadConnections } from './connections.js';
import { getState, learnCategory, maintenanceRows, setDestinationId, setRemoteId, setState } from './db.js';
import { safeFetch } from './network.js';
import { basicAuth } from './utils.js';
import type { ConnectionVault } from './vault.js';

const norm=(v:string)=>String(v||'').toLowerCase().replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u200f\u200e]/g,' ').replace(/\s+/g,' ').replace(/\s*[\[(](?:کد|code|sku)?\s*[:：]?\s*\d+[\])]]\s*$/i,'').trim();
type Target='woo'|'basalam';
type Shop={name:string;token:string;vendorId:string;pricePercent:number;primary:boolean};
export type Remote={id:number;name:string;title:string;sku:string;images:string[];image:string;status:string;statusLabel:string;price:number;priceRaw:number;stock:number|null;category:string;categoryId:number|null;shopId:string;shopName:string;rejectionReason:string;shortDescription:string;description:string;raw:any};
type CatalogQuery={page?:number;perPage?:number;q?:string;status?:string;shopId?:string;counts?:boolean};
type ProductRef={id:number;shopId:string};
export type DestinationCategory={id:number;name:string;path:string;parentId:number|null;depth:number;leaf:boolean};

export async function recon(target:Target,profileId=''){
  const local=await maintenanceRows(profileId),remote=await remoteProducts(target),byId=new Map(remote.map(x=>[x.id,x])),bySku=new Map(remote.filter(x=>x.sku).map(x=>[x.sku,x])),byName=new Map(remote.map(x=>[norm(x.name),x])),used=new Set<number>(),items:any[]=[];
  for(const row of local){const mapped=target==='woo'?Number(row.remote_woo_id||0):Number(row.remote_basalam_id||0),sku=row.data?.sku||`s4-${row.profile_id}-${row.source_key}`.slice(0,100);const match=byId.get(mapped)||bySku.get(sku)||byName.get(norm(row.title));if(match)used.add(match.id);items.push({profileId:row.profile_id,sourceKey:row.source_key,title:row.title,active:row.active,remoteId:match?.id||null,matchedBy:match?(match.id===mapped?'id':match.sku===sku?'sku':'title'):'none',remoteTitle:match?.name||''})}
  const result={target,at:new Date().toISOString(),local:local.length,remote:remote.length,matched:items.filter(x=>x.remoteId).length,missingRemote:items.filter(x=>!x.remoteId&&x.active).length,retired:items.filter(x=>!x.active).length,extraRemote:remote.filter(x=>!used.has(x.id)).map(x=>({id:x.id,title:x.name,status:x.status})),items};await setState(`recon_${target}`,result);return result;
}
export async function rebuildMap(target:Target,profileId=''){const report=await recon(target,profileId);let mapped=0;for(const item of report.items)if(item.remoteId){await setDestinationId(item.profileId,item.sourceKey,target,'default',item.remoteId);await setRemoteId(item.profileId,item.sourceKey,target,item.remoteId);mapped++}return{ok:true,target,mapped,unmatched:report.items.length-mapped}}
export async function retire(target:Target,profileId:string,action:string,apply=false){const rows=(await maintenanceRows(profileId)).filter(x=>!x.active),preview=rows.map(x=>({profileId:x.profile_id,sourceKey:x.source_key,title:x.title,remoteId:target==='woo'?x.remote_woo_id:x.remote_basalam_id,missingSince:x.missing_since,action}));if(!apply||action==='report')return{ok:true,dryRun:true,count:preview.length,items:preview};let changed=0,failed:any[]=[];for(const item of preview){if(!item.remoteId)continue;try{if(target==='woo')await wooUpdate(item.remoteId,action==='trash'?{status:'trash'}:{status:action==='draft'?'draft':'private'});else await basalamUpdate(item.remoteId,{status:action==='trash'?4184:3790});changed++}catch(error){failed.push({title:item.title,error:msg(error)})}}return{ok:failed.length===0,dryRun:false,changed,failed}}

/** Legacy profile-based operation remains available; the comprehensive editor sends remote ids. */
export async function bulkEdit(target:Target,input:any,apply=false){
  if(Array.isArray(input?.ids))return destinationBulkEdit(target,input,apply);
  const rows=(await maintenanceRows(String(input.profileId||''))).filter(x=>x.active).filter(x=>!input.query||norm(x.title).includes(norm(input.query))).slice(0,Math.min(1000,Number(input.limit)||200)),items=rows.map(row=>{let title=String(row.title);if(input.prefix)title=String(input.prefix)+title;if(input.suffix)title+=String(input.suffix);let price=Number(row.price);if(Number(input.pricePercent))price=Math.round(price*(1+Number(input.pricePercent)/100));return{row,title,price,remoteId:target==='woo'?row.remote_woo_id:row.remote_basalam_id}});
  if(!apply)return{ok:true,dryRun:true,count:items.length,items:items.slice(0,100).map(x=>({title:x.row.title,newTitle:x.title,oldPrice:x.row.price,newPrice:x.price,remoteId:x.remoteId}))};
  let changed=0,failed:any[]=[];for(const item of items){if(!item.remoteId)continue;try{const payload:any={name:item.title};if(item.price)target==='woo'?payload.regular_price=String(item.price):payload.primary_price=item.price*10;if(input.stock!==''&&input.stock!=null)target==='woo'?Object.assign(payload,{manage_stock:true,stock_quantity:Number(input.stock)}):payload.stock=Number(input.stock);if(target==='woo')await wooUpdate(item.remoteId,payload);else await basalamUpdate(item.remoteId,payload);changed++}catch(error){failed.push({title:item.row.title,error:msg(error)})}}return{ok:failed.length===0,dryRun:false,changed,failed};
}

export async function photoFix(profileId:string,apply=false){const rows=(await maintenanceRows(profileId)).filter(x=>x.active&&x.remote_woo_id&&x.data?.image),remote=await remoteProducts('woo'),byId=new Map(remote.map(x=>[x.id,x])),items=rows.filter(x=>!(byId.get(Number(x.remote_woo_id))?.images||[]).length).map(x=>({id:Number(x.remote_woo_id),title:x.title,image:x.data.image}));if(!apply)return{ok:true,dryRun:true,count:items.length,items:items.slice(0,200)};let changed=0,failed:any[]=[];for(const item of items)try{await wooUpdate(item.id,{images:[{src:item.image}]});changed++}catch(error){failed.push({title:item.title,error:msg(error)})}return{ok:failed.length===0,dryRun:false,changed,failed}}

export async function destinationCatalog(target:Target,query:CatalogQuery={}){
  const page=clamp(query.page,1,10_000,1),perPage=clamp(query.perPage,10,100,25),q=String(query.q||'').trim(),status=String(query.status||'all'),shopId=String(query.shopId||'all');
  if(target==='woo'){
    const result=await wooCatalog({page,perPage,q,status}),counts=query.counts?await wooStatusCounts():undefined;
    return{ok:true,target,page,perPage,q,status,shopId:'default',shops:[{id:'default',name:'فروشگاه ووکامرس'}],...result,...(counts?{counts}:{}),priceUnit:'تومان'};
  }
  const result=await basalamCatalog({page,perPage,q,status,shopId}),counts=query.counts?await basalamStatusCounts(shopId):undefined;
  return{ok:true,target,page,perPage,q,status,shopId,shops:(await basalamShops()).map(shop=>({id:shop.vendorId,name:shop.name,primary:shop.primary})),...result,...(counts?{counts}:{}),priceUnit:'تومان',remotePriceUnit:'ریال',archiveInsteadOfDelete:true};
}
export async function destinationCategories(refresh=false):Promise<{items:DestinationCategory[];cached:boolean;updatedAt:string}>{
  const cacheKey='basalam_categories_v1',cached=await getState<any>(cacheKey,null),maxAge=24*60*60*1000;
  if(!refresh&&Array.isArray(cached?.items)&&cached.items.length&&Date.now()-Date.parse(String(cached.updatedAt||0))<maxAge)return{items:cached.items,cached:true,updatedAt:String(cached.updatedAt)};
  const connection=(await loadConnections()).basalam;if(!connection.token)throw Error('توکن باسلام خالی است.');const shop:Shop={name:'غرفه پیش‌فرض',token:connection.token,vendorId:String(connection.vendorId||''),pricePercent:0,primary:true},api=connection.api;
  const result=await basalamFetch(shop,`${String(api).replace(/\/$/,'')}/categories`),roots=categoryRoots(result.body),items:DestinationCategory[]=[];
  flattenCategoryTree(roots,items,[],0,null);
  if(!items.length)throw Error('فهرست دسته‌بندی باسلام خالی است. اتصال و پاسخ API را بررسی کنید.');
  const record={items:dedupeCategories(items),updatedAt:new Date().toISOString()};await setState(cacheKey,record);return{...record,cached:false};
}
export async function destinationProduct(target:Target,id:number,shopId=''){if(!Number.isInteger(id)||id<=0)throw Error('شناسه محصول نامعتبر است.');return target==='woo'?wooGet(id):basalamGet(id,shopId)}
export async function listDestinationProducts(target:Target):Promise<Remote[]>{return target==='woo'?wooProducts():basalamProducts()}
export async function destinationOverview(target:Target){const items=await listDestinationProducts(target),statuses:Record<string,number>={};for(const item of items)statuses[item.status]=(statuses[item.status]||0)+1;return{target,total:items.length,statuses,withoutImage:items.filter(x=>!x.images.length).length,withoutSku:items.filter(x=>!x.sku).length}}
export async function findDestinationDuplicates(target:Target){const items=await listDestinationProducts(target),groups=new Map<string,Remote[]>();for(const item of items){const key=norm(item.name);if(!key)continue;const rows=groups.get(key)||[];rows.push(item);groups.set(key,rows)}return[...groups.entries()].filter(([,rows])=>rows.length>1).map(([title,rows])=>({title,count:rows.length,items:rows.map(x=>({id:x.id,name:x.name,status:x.status,sku:x.sku,shopId:x.shopId}))}))}

export async function destinationUpdate(target:Target,id:number,input:any,apply=false,shopId=''){
  const current=await destinationProduct(target,id,shopId),payload=directPayload(target,input,current);
  if(!Object.keys(payload).length)return{ok:true,dryRun:!apply,id,shopId:current.shopId,changed:false,current,changes:{}};
  if(!apply)return{ok:true,dryRun:true,id,shopId:current.shopId,changed:true,current,changes:payload,summary:'پیش‌نمایش است؛ چیزی روی مقصد تغییر نکرد.'};
  const raw=target==='woo'?await wooUpdate(id,payload):await basalamUpdate(id,payload,current.shopId);let learningRecords=0;if(target==='basalam'&&Number(payload.category_id)>0)learningRecords=await learnCategory(current.title,Number(payload.category_id),String(input.categoryName||current.category||`#${payload.category_id}`));return{ok:true,dryRun:false,id,shopId:current.shopId,changed:true,product:normalizeRemote(target,unwrapProduct(raw),current.shopId,current.shopName),learningRecords};
}
export async function destinationChangeStatus(target:Target,id:number,status:string,shopId=''){const allowed=target==='woo'?['publish','draft','private','pending','trash']:['2976','3790','3567','3568','4184'];if(!allowed.includes(String(status)))throw Error('وضعیت انتخاب‌شده معتبر نیست.');if(target==='woo')await wooUpdate(id,{status});else await basalamUpdate(id,{status:Number(status)},shopId);return{ok:true,id,status,shopId:shopId||'default'}}
export async function destinationDelete(target:Target,id:number,force=false,shopId=''){
  if(target==='woo'){const c=(await loadConnections()).woo,auth=basicAuth(c.key,c.secret),result=await fetchJson(`${wooBase(c)}/${id}?force=${force?'true':'false'}`,{method:'DELETE',headers:{authorization:auth}});return{ok:true,id,deleted:true,force,product:result.body}}
  // Basalam has no permanent DELETE endpoint. The reversible PHP-parity action is archive status 4184.
  await basalamUpdate(id,{status:4184},shopId);return{ok:true,id,deleted:false,archived:true,status:4184,shopId:shopId||'default',message:'باسلام حذف دائمی ندارد؛ محصول با وضعیت ۴۱۸۴ بایگانی شد.'};
}

export async function destinationBulkEdit(target:Target,input:any,apply=false){
  const refs=normalizeRefs(input.ids,input.shopId);if(!refs.length)throw Error('محصولی انتخاب نشده است.');
  if(refs.length>20)throw Error('در Cloudflare Workers برای رعایت سقف درخواست‌های خارجی، هر نوبت ویرایش حداکثر ۲۰ محصول است. انتخاب را به چند نوبت تقسیم کنید.');
  const ops=input.ops&&typeof input.ops==='object'?input.ops:input,assignments=normalizeCategoryAssignments(ops.categoryAssignments),items:any[]=[],updates:Array<{ref:ProductRef;payload:any;row:any}>=[],failures:any[]=[];
  for(const ref of refs)try{
    const current=await destinationProduct(target,ref.id,ref.shopId),assignment=assignments.get(`${ref.shopId||current.shopId}:${ref.id}`)||assignments.get(`:${ref.id}`),effective=assignment?{...ops,categoryId:assignment.categoryId}:ops,built=bulkPayload(target,effective,current),row={id:ref.id,shopId:current.shopId,title:current.title,oldPrice:current.price,...built.summary,...(assignment?{categoryName:assignment.categoryName,categorySource:assignment.source}: {})};
    if(ops.delete){row.action=target==='basalam'?'بایگانی با وضعیت ۴۱۸۴':'حذف'+(ops.force?' همیشگی':' به زباله‌دان');updates.push({ref:{...ref,shopId:current.shopId},payload:target==='basalam'?{status:4184}:{delete:true,force:Boolean(ops.force)},row})}
    else if(Object.keys(built.payload).length){row.action='ویرایش';updates.push({ref:{...ref,shopId:current.shopId},payload:built.payload,row})}else row.action='بدون تغییر';
    items.push(row);
  }catch(error){const row={id:ref.id,shopId:ref.shopId,error:msg(error)};items.push(row);failures.push(row)}
  if(!apply)return{ok:failures.length===0,dryRun:true,target,total:refs.length,changed:updates.filter(x=>!x.payload.delete).length,deleted:updates.filter(x=>x.payload.delete||x.payload.status===4184&&ops.delete).length,skipped:refs.length-updates.length-failures.length,failed:failures.length,items,limit:20,summary:'پیش‌نمایش کامل شد؛ هیچ تغییری روی مقصد اعمال نشد.'};
  const applied=await applyBulk(target,updates),failed=[...failures,...applied.failed],failedKeys=new Set(applied.failed.map(row=>`${row.shopId}:${row.id}`));for(const failure of applied.failed){const row=items.find(item=>item.id===failure.id&&item.shopId===failure.shopId);if(row)row.error=failure.error}
  let learningRecords=0;if(target==='basalam')for(const item of updates)if(Number(item.payload.category_id)>0&&!failedKeys.has(`${item.ref.shopId}:${item.ref.id}`))learningRecords+=await learnCategory(item.row.title,Number(item.payload.category_id),String(item.row.categoryName||`#${item.payload.category_id}`));
  return{ok:failed.length===0,dryRun:false,target,total:refs.length,changed:applied.changed,deleted:applied.deleted,skipped:refs.length-updates.length-failures.length,failed:failed.length,items,limit:20,archiveInsteadOfDelete:target==='basalam',learningRecords};
}

async function applyBulk(target:Target,updates:Array<{ref:ProductRef;payload:any;row:any}>){
  let changed=0,deleted=0;const failed:any[]=[];
  if(target==='basalam'){
    const groups=new Map<string,typeof updates>();for(const item of updates){const rows=groups.get(item.ref.shopId)||[];rows.push(item);groups.set(item.ref.shopId,rows)}
    for(const [shopId,rows] of groups)try{await basalamBatchUpdate(shopId,rows.map(item=>({id:item.ref.id,...item.payload})));for(const item of rows)item.payload.status===4184&&item.row.action?.includes('بایگانی')?deleted++:changed++}catch{
      for(const item of rows)try{await basalamUpdate(item.ref.id,item.payload,shopId);item.payload.status===4184&&item.row.action?.includes('بایگانی')?deleted++:changed++}catch(error){failed.push({id:item.ref.id,shopId,error:msg(error)})}
    }
    return{changed,deleted,failed};
  }
  for(const item of updates)try{if(item.payload.delete){await destinationDelete('woo',item.ref.id,item.payload.force);deleted++}else{await wooUpdate(item.ref.id,item.payload);changed++}}catch(error){failed.push({id:item.ref.id,shopId:'default',error:msg(error)})}return{changed,deleted,failed};
}

function directPayload(target:Target,input:any,current:Remote):any{
  const payload:any={};
  if(input.title!==undefined&&String(input.title).trim()&&String(input.title).trim()!==current.title)payload[target==='woo'?'name':'name']=String(input.title).trim().slice(0,target==='woo'?300:120);
  if(input.price!==undefined&&input.price!==''&&Number.isFinite(Number(input.price))){const price=Math.max(0,Math.round(Number(input.price)));if(price!==current.price)payload[target==='woo'?'regular_price':'primary_price']=target==='woo'?String(price):price*10}
  if(input.stock!==undefined&&input.stock!==''&&Number.isFinite(Number(input.stock))){const stock=Math.max(0,Math.round(Number(input.stock)));target==='woo'?Object.assign(payload,{manage_stock:true,stock_quantity:stock,stock_status:stock>0?'instock':'outofstock'}):payload.stock=stock}
  if(input.status!==undefined&&String(input.status))Object.assign(payload,statusPayload(target,String(input.status)));
  if(input.shortDescription!==undefined)payload[target==='woo'?'short_description':'brief']=String(input.shortDescription).slice(0,target==='woo'?20_000:250);
  if(input.description!==undefined)payload.description=String(input.description).slice(0,100_000);
  if(input.sku!==undefined&&target==='woo')payload.sku=String(input.sku).slice(0,100);
  if(input.categoryId!==undefined&&Number(input.categoryId)>0)target==='woo'?payload.categories=[{id:Number(input.categoryId)}]:payload.category_id=Number(input.categoryId);
  if(target==='basalam')for(const key of ['preparation_days','weight','package_weight'])if(input[key]!==undefined&&Number(input[key])>=0)payload[key]=Number(input[key]);
  return payload;
}
function bulkPayload(target:Target,ops:any,current:Remote){const payload:any={},summary:any={};
  if(ops.price&&typeof ops.price==='object'){const next=applyPrice(String(ops.price.op||''),String(ops.price.val??''),current.price);if(next!==null&&next!==current.price){payload[target==='woo'?'regular_price':'primary_price']=target==='woo'?String(next):next*10;summary.newPrice=next;summary.pricePercent=current.price?Math.round((next-current.price)/current.price*1000)/10:0}}
  if(ops.stock!==undefined&&ops.stock!==''){const stock=Math.max(0,Math.round(Number(ops.stock)||0));target==='woo'?Object.assign(payload,{manage_stock:true,stock_quantity:stock,stock_status:stock>0?'instock':'outofstock'}):payload.stock=stock;summary.stock=stock}
  if(ops.status)Object.assign(payload,statusPayload(target,String(ops.status)));
  if(ops.description!==undefined||ops.desc!==undefined)payload.description=String(ops.description??ops.desc).slice(0,100_000);
  if(ops.shortDescription!==undefined||ops.short_desc!==undefined)payload[target==='woo'?'short_description':'brief']=String(ops.shortDescription??ops.short_desc).slice(0,target==='woo'?20_000:250);
  if(ops.categoryId!==undefined&&Number(ops.categoryId)>0){const categoryId=Math.round(Number(ops.categoryId));target==='woo'?payload.categories=[{id:categoryId}]:payload.category_id=categoryId;summary.newCategoryId=categoryId}
  const title=(String(ops.titlePrefix??ops.title_prefix??'')+current.title+String(ops.titleSuffix??ops.title_suffix??'')).trim();if(title&&title!==current.title){payload.name=title.slice(0,target==='woo'?300:120);summary.newTitle=title}
  return{payload,summary};
}
function statusPayload(target:Target,status:string){if(target==='woo'){if(!['publish','draft','private','pending','trash'].includes(status))throw Error('وضعیت ووکامرس نامعتبر است.');return{status}}const number=Number(status);if(![2976,3790,3567,3568,4184].includes(number))throw Error('وضعیت باسلام نامعتبر است.');return{status:number}}
function applyPrice(op:string,value:string,current:number):number|null{if(!['set','inc','dec'].includes(op))return null;const percent=value.trim().endsWith('%'),amount=Number(value.replace('%','').replace(/,/g,''));if(!Number.isFinite(amount))return null;let next=op==='set'?amount:op==='inc'?current+(percent?current*amount/100:amount):current-(percent?current*amount/100:amount);return Math.max(0,Math.round(next))}

async function remoteProducts(target:Target):Promise<Remote[]>{return listDestinationProducts(target)}
async function wooProducts(){const out:Remote[]=[];for(let page=1;page<=100;page++){const data=await wooCatalog({page,perPage:100,q:'',status:'all'});out.push(...data.products);if(page>=data.totalPages)break}return out}
async function basalamProducts(){const out:Remote[]=[];for(const shop of await basalamShops())for(let page=1;page<=100;page++){const data=await basalamCatalog({page,perPage:100,q:'',status:'all',shopId:shop.vendorId});out.push(...data.products);if(page>=data.totalPages)break}return out}

async function wooCatalog(query:{page:number;perPage:number;q:string;status:string}){
  const c=(await loadConnections()).woo;if(!c.url||!c.key||!c.secret)throw Error('اتصال ووکامرس کامل نیست');const auth=basicAuth(c.key,c.secret);
  if(/^\d+$/.test(query.q)){try{const product=await wooGet(Number(query.q));return{products:[product],total:1,totalPages:1,foundBy:'id'}}catch{/* continue with server search */}}
  const url=new URL(wooBase(c));url.searchParams.set('page',String(query.page));url.searchParams.set('per_page',String(query.perPage));url.searchParams.set('status',wooListStatus(query.status));if(query.q)url.searchParams.set('search',query.q);
  const result=await fetchJson(url.toString(),{headers:{authorization:auth,accept:'application/json'}}),rows=Array.isArray(result.body)?result.body:[];
  return{products:rows.map(row=>normalizeRemote('woo',row,'default','فروشگاه ووکامرس')),total:Number(result.response.headers.get('x-wp-total')||rows.length),totalPages:Math.max(1,Number(result.response.headers.get('x-wp-totalpages')||1)),foundBy:query.q?'search':'list'};
}
async function wooStatusCounts(){const statuses=['all','publish','draft','pending','private','trash'],entries=await Promise.all(statuses.map(async status=>{try{const result=await wooCatalog({page:1,perPage:10,q:'',status});return[status,result.total] as const}catch{return[status,0] as const}}));return Object.fromEntries(entries)}
async function wooGet(id:number){const c=(await loadConnections()).woo,auth=basicAuth(c.key,c.secret),result=await fetchJson(`${wooBase(c)}/${id}`,{headers:{authorization:auth,accept:'application/json'}});return normalizeRemote('woo',unwrapProduct(result.body),'default','فروشگاه ووکامرس')}
async function wooUpdate(id:number,payload:any){const c=(await loadConnections()).woo,auth=basicAuth(c.key,c.secret),result=await fetchJson(`${wooBase(c)}/${id}`,{method:'PUT',headers:{authorization:auth,'content-type':'application/json',accept:'application/json'},body:JSON.stringify(payload)});return result.body}
function wooBase(c:ConnectionVault['woo']){if(!c.url||!c.key||!c.secret)throw Error('اتصال ووکامرس کامل نیست');return c.url.replace(/\/$/,'')+'/wp-json/wc/v3/products'}
function wooListStatus(status:string){return ['publish','draft','pending','private','trash'].includes(status)?status:'any'}

async function basalamCatalog(query:{page:number;perPage:number;q:string;status:string;shopId:string}){
  const shops=selectShops(await basalamShops(),query.shopId);if(!shops.length)throw Error('غرفهٔ باسلام پیدا نشد.');
  if(/^\d+$/.test(query.q)){for(const shop of shops)try{const product=await basalamGet(Number(query.q),shop.vendorId);return{products:[product],total:1,totalPages:1,foundBy:'id'}}catch{/* next shop */}}
  const products:Remote[]=[];let total=0,totalPages=1,successful=0;for(const shop of shops){const url=new URL(`${(await loadConnections()).basalam.api}/vendors/${encodeURIComponent(shop.vendorId)}/products`);url.searchParams.set('page',String(query.page));url.searchParams.set('per_page',String(query.perPage));for(const value of basalamStatuses(query.status))url.searchParams.append('statuses',value);if(query.q)url.searchParams.set('title',query.q);try{const result=await basalamFetch(shop,url.toString()),rows=rowsFrom(result.body);products.push(...rows.map(row=>normalizeRemote('basalam',row,shop.vendorId,shop.name)));total+=Number(result.body?.total_count??result.body?.meta?.total??rows.length);totalPages=Math.max(totalPages,Number(result.body?.total_page??result.body?.meta?.last_page??1));successful++}catch(error){if(shops.length===1)throw error}}
  if(!successful)throw Error('دریافت فهرست محصولات از هیچ غرفه‌ای موفق نبود.');return{products,total,totalPages,foundBy:query.q?'title':'list'};
}
async function basalamStatusCounts(shopId:string){const statuses=['all','2976','3790','3567','3568','4184'],entries=await Promise.all(statuses.map(async status=>{try{const result=await basalamCatalog({page:1,perPage:10,q:'',status,shopId});return[status,result.total] as const}catch{return[status,0] as const}}));return Object.fromEntries(entries)}
async function basalamGet(id:number,shopId=''){const shops=selectShops(await basalamShops(),shopId||'all');let last:unknown;for(const shop of shops){for(const endpoint of [`${(await loadConnections()).basalam.api}/products/${id}`,`${(await loadConnections()).basalam.api}/vendors/${encodeURIComponent(shop.vendorId)}/products/${id}`])try{const result=await basalamFetch(shop,endpoint),raw=unwrapProduct(result.body);if(Number(raw?.id||0)>0)return normalizeRemote('basalam',raw,shop.vendorId,shop.name)}catch(error){last=error}}throw last instanceof Error?last:Error(`محصول باسلام #${id} پیدا نشد.`)}
async function basalamUpdate(id:number,payload:any,shopId=''){const shops=selectShops(await basalamShops(),shopId||'all');if(!shops.length)throw Error('غرفهٔ باسلام پیدا نشد.');let last:unknown;for(const shop of shops){for(const endpoint of [`${(await loadConnections()).basalam.api}/products/${id}`,`${(await loadConnections()).basalam.api}/vendors/${encodeURIComponent(shop.vendorId)}/products/${id}`])try{return(await basalamFetch(shop,endpoint,{method:'PATCH',headers:{'content-type':'application/json'},body:JSON.stringify(payload)})).body}catch(error){last=error;if(!(error instanceof DestinationHttpError&&error.status===404))throw error}}throw last instanceof Error?last:Error(`ویرایش محصول باسلام #${id} ناموفق بود.`)}
async function basalamBatchUpdate(shopId:string,items:any[]){const shop=(await basalamShops()).find(item=>item.vendorId===shopId);if(!shop)throw Error('غرفهٔ باسلام پیدا نشد.');return(await basalamFetch(shop,`${(await loadConnections()).basalam.api}/vendors/${encodeURIComponent(shop.vendorId)}/products/batch-updates`,{method:'PATCH',headers:{'content-type':'application/json'},body:JSON.stringify({data:items})})).body}
async function basalamFetch(shop:Shop,url:string,init:RequestInit={}){return fetchJson(url,{...init,headers:{authorization:`Bearer ${shop.token}`,accept:'application/json',...init.headers}})}
async function basalamShops():Promise<Shop[]>{const c=(await loadConnections()).basalam;if(!c.token||!c.vendorId)throw Error('اتصال باسلام کامل نیست');const rows:Shop[]=[{name:'غرفه پیش‌فرض',token:c.token,vendorId:String(c.vendorId),pricePercent:0,primary:true},...c.shops.filter(shop=>shop.token&&shop.vendorId).map(shop=>({...shop,vendorId:String(shop.vendorId),primary:false}))],seen=new Set<string>();return rows.filter(row=>row.vendorId&&!seen.has(row.vendorId)&&(seen.add(row.vendorId),true))}
function selectShops(shops:Shop[],shopId:string){return !shopId||shopId==='all'||shopId==='0'?shops:shops.filter(shop=>shop.vendorId===String(shopId))}
function basalamStatuses(status:string){const map:Record<string,string[]>={all:['2976','3790','3567','3568','4184','2977','2978','3248','4221'],active:['2976'],inactive:['3790'],not_approved:['3567'],pending:['3568'],archived:['4184']};return map[status]||([2976,3790,3567,3568,4184].includes(Number(status))?[String(status)]:map.all)}
function categoryRoots(body:any):any[]{const candidates=[body?.data?.categories,body?.data,body?.categories,body?.results,body?.items,body];for(const value of candidates)if(Array.isArray(value))return value;return[]}
function categoryChildren(row:any):any[]{for(const value of [row?.children,row?.childs,row?.subcategories,row?.categories,row?.data?.children])if(Array.isArray(value))return value;return[]}
function flattenCategoryTree(rows:any[],out:DestinationCategory[],parents:string[],depth:number,parentId:number|null){for(const row of rows){const id=Number(row?.id??row?.category_id??row?.value),name=String(row?.name??row?.title??row?.label??'').trim();if(!Number.isInteger(id)||id<=0||!name)continue;const children=categoryChildren(row),path=[...parents,name];out.push({id,name,path:path.join(' ← '),parentId,depth,leaf:children.length===0});if(children.length)flattenCategoryTree(children,out,path,depth+1,id)}}
function dedupeCategories(rows:DestinationCategory[]){const seen=new Set<number>();return rows.filter(row=>!seen.has(row.id)&&(seen.add(row.id),true))}

function normalizeRemote(target:Target,x:any,shopId:string,shopName:string):Remote{
  const revision=x?.revision?.data||{},rawStatus=x?.status??revision.status??'',status=typeof rawStatus==='object'?String(rawStatus.value??rawStatus.id??''):String(rawStatus||''),statusLabel=typeof rawStatus==='object'?String(rawStatus.name||rawStatus.description||status):target==='basalam'?({'2976':'فعال','3790':'غیرفعال','3567':'تأیید نشده','3568':'در انتظار تأیید','4184':'بایگانی'}[status]||status):status;
  const rawImages=target==='woo'?(x?.images||[]):(x?.photos||revision.photos||x?.images||(x?.photo||revision.photo?[x?.photo||revision.photo]:[])),images=(Array.isArray(rawImages)?rawImages:[]).map(imageValue).filter(Boolean),priceRaw=Number(x?.primary_price??revision.primary_price??x?.price??x?.regular_price??0)||0,category=x?.categories?.[0]||revision.category||x?.category||{},reasons=[rawStatus?.description,...(x?.revision?.rejection_reasons||[]).flatMap((item:any)=>[item?.name,item?.description])].filter(Boolean).join(' | ');
  const title=String(x?.name||x?.title||revision.title||'');return{id:Number(x?.id)||0,name:title,title,sku:String(x?.sku||revision.sku||''),images,image:images[0]||'',status,statusLabel,price:target==='basalam'?Math.round(priceRaw/10):priceRaw,priceRaw,stock:numberOrNull(x?.stock_quantity??x?.inventory??revision.inventory??x?.stock),category:String(category?.name||category?.title||x?.category_name||''),categoryId:Number(category?.id||x?.category_id)||null,shopId,shopName,rejectionReason:reasons,shortDescription:String(x?.short_description||x?.brief||revision.brief||''),description:String(x?.description||revision.description||''),raw:x};
}
function imageValue(value:any):string{if(typeof value==='string')return value;if(!value||typeof value!=='object')return'';return String(value.src||value.original||value.lg||value.md||value.sm||value.xs||value.url||'')}
function rowsFrom(body:any):any[]{const rows=body?.data??body?.products??body?.results??body?.items??body;return Array.isArray(rows)?rows:[]}
function unwrapProduct(body:any):any{return body?.data?.product??body?.data??body?.product??body??{}}
function numberOrNull(value:any):number|null{return value===null||value===undefined||value===''?null:(Number.isFinite(Number(value))?Number(value):null)}
function normalizeRefs(ids:any[],shopId=''):ProductRef[]{const seen=new Set<string>(),rows:ProductRef[]=[];for(const value of ids||[]){const id=Number(typeof value==='object'?value.id:value),shop=String(typeof value==='object'?(value.shopId||value.shop_id||shopId):shopId||'');if(!Number.isInteger(id)||id<=0)continue;const key=`${shop}:${id}`;if(!seen.has(key)){seen.add(key);rows.push({id,shopId:shop})}}return rows}
function normalizeCategoryAssignments(value:any){const rows=Array.isArray(value)?value:[],map=new Map<string,{categoryId:number;categoryName:string;source:string}>();for(const row of rows){const id=Number(row?.id),categoryId=Number(row?.categoryId??row?.category_id),shopId=String(row?.shopId??row?.shop_id??'');if(Number.isInteger(id)&&id>0&&Number.isInteger(categoryId)&&categoryId>0)map.set(`${shopId}:${id}`,{categoryId,categoryName:String(row?.categoryName??row?.category_name??''),source:String(row?.source??'')})}return map}
function clamp(value:any,min:number,max:number,fallback:number){const n=Number(value);return Number.isFinite(n)?Math.max(min,Math.min(max,Math.round(n))):fallback}

class DestinationHttpError extends Error{constructor(public status:number,public body:any,url:string){super(`HTTP ${status} از ${new URL(url).hostname}: ${String(body?.message||body?.error||JSON.stringify(body)).slice(0,300)}`)}}
async function fetchJson(url:string,init:RequestInit={}){const response=await safeFetch(url,init,10_000_000),text=await response.text();let body:any;try{body=text?JSON.parse(text):{}}catch{body={message:text.slice(0,500)}}if(!response.ok)throw new DestinationHttpError(response.status,body,url);return{response,body}}
const msg=(e:unknown)=>e instanceof Error?e.message:String(e);
