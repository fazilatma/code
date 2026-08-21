import { loadConnections } from './connections.js';
import { findLearnedCategory, getDestinationId, getRemoteId, setDestinationId, setRemoteId } from './db.js';
import { safeFetch } from './network.js';
import type { Product, Profile } from './types.js';

export async function syncWoo(product: Product, profile: Profile): Promise<'created'|'updated'> {
  const c = (await loadConnections()).woo; if (!c.url || !c.key || !c.secret) throw new Error('تنظیمات ووکامرس در منوی همبرگری کامل نیست');
  const base = c.url.replace(/\/$/, '') + '/wp-json/wc/v3/products';
  const auth = `Basic ${Buffer.from(`${c.key}:${c.secret}`).toString('base64')}`;
  let id = await getRemoteId(profile.id, product.sourceKey, 'woo');
  const sku = product.sku || `s4-${profile.id}-${product.sourceKey}`.slice(0, 100);
  if (!id) {
    const search = await safeFetch(`${base}?sku=${encodeURIComponent(sku)}`, { headers: { authorization: auth, accept: 'application/json' } }, 2_000_000);
    if (search.ok) { const rows = await search.json() as any[]; id = rows[0]?.id ? Number(rows[0].id) : null; }
  }
  const variable=Boolean(product.variationGroups?.length&&product.variations?.length);const payload:any={name:product.title,sku,type:variable?'variable':'simple',description:product.longDesc||'',short_description:product.shortDesc||'',images:product.images.map(src=>({src}))};if(variable)payload.attributes=product.variationGroups!.map(group=>({name:group.name,visible:true,variation:true,options:group.options}));else payload.regular_price=String(product.price);
  if (product.stock !== undefined) Object.assign(payload, { manage_stock: true, stock_quantity: product.stock });
  if (product.weight) payload.weight = String(product.weight);
  const wooCategory=profile.wooCategoryId||c.categoryId;if(wooCategory) payload.categories = [{ id: wooCategory }];
  const response = await safeFetch(id ? `${base}/${id}` : base, { method: 'POST', headers: { authorization: auth, 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify(payload) }, 3_000_000);
  const body = await response.json().catch(() => ({})) as any;
  if (!response.ok) throw new Error(`WooCommerce HTTP ${response.status}: ${body.message || JSON.stringify(body).slice(0,300)}`);
  const remoteId=Number(body.id||id);if(remoteId){await setRemoteId(profile.id,product.sourceKey,'woo',remoteId);if(variable)await syncWooVariations(base,remoteId,product,auth)}
  return id?'updated':'created';
}

async function syncWooVariations(base:string,productId:number,product:Product,auth:string){const endpoint=`${base}/${productId}/variations`,response=await safeFetch(`${endpoint}?per_page=100`,{headers:{authorization:auth,accept:'application/json'}},5_000_000),existing=response.ok?await response.json() as any[]:[],signature=(attrs:any[])=>attrs.map(x=>`${String(x.name||x.slug).toLowerCase()}=${String(x.option).toLowerCase()}`).sort().join('|'),bySku=new Map(existing.filter(x=>x.sku).map(x=>[String(x.sku),x])),byAttrs=new Map(existing.map(x=>[signature(x.attributes||[]),x]));for(const variation of product.variations||[]){const attributes=Object.entries(variation.attributes||{}).map(([name,option])=>({name,option})),found=(variation.sku&&bySku.get(variation.sku))||byAttrs.get(signature(attributes)),payload:any={regular_price:String(variation.price||product.priceMin||product.price),attributes};if(variation.sku)payload.sku=variation.sku;if(variation.stock!==undefined)Object.assign(payload,{manage_stock:true,stock_quantity:variation.stock});if(variation.image)payload.image={src:variation.image};const result=await safeFetch(found?`${endpoint}/${found.id}`:endpoint,{method:'POST',headers:{authorization:auth,'content-type':'application/json'},body:JSON.stringify(payload)},3_000_000);if(!result.ok)throw Error(`Woo variation HTTP ${result.status}: ${(await result.text()).slice(0,300)}`)}}

export async function syncBasalam(product: Product, profile: Profile): Promise<Array<{shop:string;action:'created'|'updated';id:number}>> {
  const c=(await loadConnections()).basalam;if(!c.token||!c.vendorId)throw Error('تنظیمات باسلام در منوی همبرگری کامل نیست');
  const learned=c.autoCategory?await findLearnedCategory(product.title):null,categoryId=profile.basalamCategoryId||learned?.categoryId||c.categoryId||undefined,variationText=(product.variationGroups||[]).map(group=>`${group.name}: ${group.options.join('، ')}`).join('\n'),description=[product.longDesc||product.shortDesc||'',variationText?`\nتنوع‌ها:\n${variationText}`:''].join('').trim();
  const accounts=[{name:'پیش‌فرض',token:c.token,vendorId:c.vendorId,pricePercent:0},...c.shops.filter(s=>s.token&&s.vendorId)];const results:Array<{shop:string;action:'created'|'updated';id:number}>=[];
  for(const account of accounts){const accountKey=String(account.vendorId),legacy=account===accounts[0]?await getRemoteId(profile.id,product.sourceKey,'basalam'):null;let existing=await getDestinationId(profile.id,product.sourceKey,'basalam',accountKey)||legacy;const base=`${c.api}/vendors/${encodeURIComponent(account.vendorId)}/products`,price=Math.round(product.price*(1+(account.pricePercent||0)/100));const payload:any={name:product.title,price,stock:product.stock??c.stock,description,photo:product.image||undefined,category_id:categoryId,weight:product.weight||c.weight,package_weight:c.packageWeight,preparation_days:c.preparationDays};const response=await safeFetch(existing?`${base}/${existing}`:base,{method:existing?'PATCH':'POST',headers:{authorization:`Bearer ${account.token}`,'content-type':'application/json',accept:'application/json'},body:JSON.stringify(payload)},3_000_000),body=await response.json().catch(()=>({})) as any;if(!response.ok)throw Error(`Basalam ${account.name} HTTP ${response.status}: ${body.message||JSON.stringify(body).slice(0,300)}`);const remoteId=Number(body.id||body.product?.id||existing);if(remoteId){await setDestinationId(profile.id,product.sourceKey,'basalam',accountKey,remoteId);if(account===accounts[0])await setRemoteId(profile.id,product.sourceKey,'basalam',remoteId)}results.push({shop:account.name,action:existing?'updated':'created',id:remoteId});}
  return results;
}
