import { loadConnections } from './connections.js';
import { findLearnedCategory, getDestinationId, getRemoteId, setDestinationId, setRemoteId } from './db.js';
import { safeFetch } from './network.js';
import { basicAuth } from './utils.js';
import type { Product, Profile, VariationGroup } from './types.js';

export async function syncWoo(product:Product,profile:Profile):Promise<'created'|'updated'> {
  const c=(await loadConnections()).woo;
  if(!c.url||!c.key||!c.secret)throw new Error('تنظیمات ووکامرس کامل نیست');
  const base=c.url.replace(/\/$/,'')+'/wp-json/wc/v3/products',auth=basicAuth(c.key,c.secret);
  let id=await getRemoteId(profile.id,product.sourceKey,'woo');
  const sku=product.sku||`s4-${profile.id}-${product.sourceKey}`.slice(0,100);
  if(!id){
    const search=await safeFetch(`${base}?sku=${encodeURIComponent(sku)}`,{headers:{authorization:auth,accept:'application/json'}},2_000_000);
    if(search.ok){const found=await search.json() as any[];id=found[0]?.id?Number(found[0].id):null}
  }
  const groups=(product.variationGroups||[]).filter(group=>group.name&&group.values?.length);
  const payload:any={name:product.title,sku,type:groups.length?'variable':'simple',regular_price:String(product.price),description:product.longDesc||'',short_description:product.shortDesc||''};
  if(product.images.length)payload.images=product.images.map(src=>({src}));
  if(product.stock!==undefined)Object.assign(payload,{manage_stock:true,stock_quantity:product.stock});
  if(product.weight)payload.weight=String(product.weight);
  if(groups.length)payload.attributes=groups.map(group=>({name:group.name,visible:true,variation:true,options:group.values.slice(0,100)}));
  const category=profile.wooCategoryId||c.categoryId;if(category)payload.categories=[{id:category}];
  const response=await safeFetch(id?`${base}/${id}`:base,{method:'POST',headers:{authorization:auth,'content-type':'application/json',accept:'application/json'},body:JSON.stringify(payload)},3_000_000),body=await response.json().catch(()=>({})) as any;
  if(!response.ok)throw new Error(`WooCommerce HTTP ${response.status}: ${body.message||JSON.stringify(body).slice(0,300)}`);
  const remoteId=Number(body.id||id);
  if(remoteId){
    await setRemoteId(profile.id,product.sourceKey,'woo',remoteId);
    await setDestinationId(profile.id,product.sourceKey,'woo','default',remoteId);
    if(groups.length)await syncWooVariations(base,remoteId,sku,groups,product,auth);
  }
  return id?'updated':'created';
}

async function syncWooVariations(base:string,parentId:number,parentSku:string,groups:VariationGroup[],product:Product,auth:string):Promise<void>{
  const combinations=cartesian(groups).slice(0,100);
  for(let index=0;index<combinations.length;index++){
    const options=combinations[index],sku=`${parentSku}-v${index+1}`.slice(0,100);
    const search=await safeFetch(`${base}/${parentId}/variations?sku=${encodeURIComponent(sku)}&per_page=1`,{headers:{authorization:auth,accept:'application/json'}},1_000_000);
    const found=search.ok?await search.json().catch(()=>[]) as any[]:[],existing=Number(found[0]?.id)||0;
    const keyedPrices=options.map(option=>product.variationPrices?.[option.value]).filter((price):price is number=>Number(price)>0);
    const payload:any={sku,regular_price:String(keyedPrices[0]||product.price),attributes:options.map(({name,option})=>({name,option}))};
    if(product.stock!==undefined)Object.assign(payload,{manage_stock:true,stock_quantity:product.stock});
    if(product.image)payload.image={src:product.image};
    const result=await safeFetch(existing?`${base}/${parentId}/variations/${existing}`:`${base}/${parentId}/variations`,{method:'POST',headers:{authorization:auth,'content-type':'application/json',accept:'application/json'},body:JSON.stringify(payload)},2_000_000);
    if(!result.ok){const error=await result.json().catch(()=>({})) as any;throw new Error(`WooCommerce variation HTTP ${result.status}: ${error.message||JSON.stringify(error).slice(0,300)}`)}
  }
}

function cartesian(groups:VariationGroup[]):Array<Array<{name:string;option:string;value:string}>>{
  let rows:Array<Array<{name:string;option:string;value:string}>>=[[]];
  for(const group of groups)rows=rows.flatMap(row=>group.values.slice(0,100).map(value=>[...row,{name:group.name,option:value,value}])).slice(0,100);
  return rows;
}

export async function syncBasalam(product:Product,profile:Profile):Promise<Array<{shop:string;action:'created'|'updated';id:number}>>{
  const c=(await loadConnections()).basalam;
  if(!c.token||!c.vendorId)throw new Error('تنظیمات باسلام کامل نیست');
  const learned=c.autoCategory?await findLearnedCategory(product.title):null;
  const categories=[profile.basalamCategoryId,learned?.categoryId,c.categoryId,...(profile.basalamFallbackCategoryIds||[]),...c.fallbackCategoryIds].map(Number).filter((id,index,all)=>id>0&&all.indexOf(id)===index);
  const accounts=[{name:'پیش‌فرض',token:c.token,vendorId:c.vendorId,pricePercent:0},...c.shops.filter(s=>s.token&&s.vendorId)],results:Array<{shop:string;action:'created'|'updated';id:number}>=[];
  for(const account of accounts){
    const accountKey=String(account.vendorId),legacy=account===accounts[0]?await getRemoteId(profile.id,product.sourceKey,'basalam'):null;
    let existing=await getDestinationId(profile.id,product.sourceKey,'basalam',accountKey)||legacy;
    const base=`${c.api}/vendors/${encodeURIComponent(account.vendorId)}/products`,price=Math.round(product.price*(1+(account.pricePercent||0)/100));
    const payload:any={name:product.title,price,stock:product.stock??c.stock,description:(product.longDesc||product.shortDesc||'')+(product.variations?.length?`\n\nتنوع‌ها: ${product.variations.join('، ')}`:''),photo:product.image||undefined,category_id:categories[0]||undefined,weight:product.weight||c.weight,package_weight:c.packageWeight,preparation_days:c.preparationDays};
    let response:Response|undefined,body:any={};
    const attempts=categories.length?categories:[undefined];
    for(const categoryId of attempts){
      payload.category_id=categoryId;
      response=await safeFetch(existing?`${base}/${existing}`:base,{method:existing?'PATCH':'POST',headers:{authorization:`Bearer ${account.token}`,'content-type':'application/json',accept:'application/json'},body:JSON.stringify(payload)},3_000_000);
      body=await response.json().catch(()=>({}));
      if(response.ok)break;
    }
    if(!response?.ok)throw new Error(`Basalam ${account.name} HTTP ${response?.status||0}: ${body.message||JSON.stringify(body).slice(0,300)}`);
    const remoteId=Number(body.id||body.product?.id||existing);
    if(remoteId){await setDestinationId(profile.id,product.sourceKey,'basalam',accountKey,remoteId);if(account===accounts[0])await setRemoteId(profile.id,product.sourceKey,'basalam',remoteId)}
    results.push({shop:account.name,action:existing?'updated':'created',id:remoteId});
  }
  return results;
}
