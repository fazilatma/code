import { allProducts, getState, listAutoreplyLog, listCategoryLearning, listProfiles } from './db.js';
import { loadConnections } from './connections.js';

export type PhpSettingsBundle = {
  app: 'scraper'; version: string; created_at: number; created_at_h: string;
  host: string; kind: 'settings-export'; format: 'scraper4-php-compatible';
  files: Record<string,{size:number;b64:string}>; total_files:number; total_bytes:number;
};

const STATE_FILES: Record<string,string> = {
  'render_settings.json':'settings',
  'autoreply_rules.json':'autoreply_rules', 'autoreply_state.json':'autoreply_state',
  'remote_map.json':'remote_map',
  'ai_providers.json':'ai_providers', 'ai_candidates.json':'ai_candidates',
  'ai_votes.json':'ai_votes', 'sync_state.json':'sync_state',
  'notification_settings.json':'notification_settings', 'digest_state.json':'digest_state'
};

export async function createPhpSettingsBundle(host='render'): Promise<PhpSettingsBundle> {
  const files: Record<string,{size:number;b64:string}> = {};
  const profiles=await listProfiles(); const phpProfiles:Record<string,unknown>={};
  for(const profile of profiles){
    const products=(await allProducts(profile.id)).map(product=>[product.sourceKey,stripImages(product)]);
    phpProfiles[profile.id]={...profile,pagType:profile.pagination,pagVal:profile.paginationValue,priceVal:profile.priceValue,bslCategoryId:profile.basalamCategoryId,products,productsOrder:products.map(item=>item[0])};
  }
  addFile(files,'profiles.json',phpProfiles);
  const c=await loadConnections(true);
  addFile(files,'connections.json',{
    woocommerce:{url:c.woo.url,consumer_key:c.woo.key,consumer_secret:c.woo.secret,ck:c.woo.key,cs:c.woo.secret,category_id:c.woo.categoryId},
    basalam:{token:c.basalam.token,vendor_id:c.basalam.vendorId,api_base:c.basalam.api,preparation_days:c.basalam.preparationDays,weight:c.basalam.weight,package_weight:c.basalam.packageWeight,stock:c.basalam.stock,category_id:c.basalam.categoryId,auto_category:c.basalam.autoCategory,net_indirect:c.basalam.netIndirect,shops:c.basalam.shops},
    ai:{base_url:c.ai.baseUrl,api_key:c.ai.apiKey,model:c.ai.model,activeProvider:c.ai.activeProvider,providers:c.ai.providers,candidates:c.ai.candidates,master:c.ai.master,network:c.ai.network},
    notifications:c.notifications,
    github:{token:c.github.token,repo:c.github.repo,branch:c.github.branch,path:c.github.path}
  });
  addFile(files,'category_learning.json',await listCategoryLearning(10000));
  addFile(files,'autoreply_log.json',await listAutoreplyLog(5000));
  for(const [file,key] of Object.entries(STATE_FILES)){const value=await getState<unknown>(key,null);if(value!==null)addFile(files,file,value)}
  const total_bytes=Object.values(files).reduce((sum,item)=>sum+item.size,0);
  return {app:'scraper',version:'render-1.0',created_at:Math.floor(Date.now()/1000),created_at_h:new Date().toISOString(),host,kind:'settings-export',format:'scraper4-php-compatible',files,total_files:Object.keys(files).length,total_bytes};
}

export function decodePhpSettingsBundle(bundle:any): Record<string,unknown> {
  if(!bundle||typeof bundle!=='object'||!bundle.files||typeof bundle.files!=='object')throw new Error('فایل تنظیمات ساختار files سازگار با Scraper 4 ندارد.');
  const result:Record<string,unknown>={};
  for(const [name,meta] of Object.entries(bundle.files as Record<string,any>)){
    if(name!==name.split('/').pop()||!meta||typeof meta.b64!=='string')continue;
    const bytes=Buffer.from(meta.b64,'base64');if(bytes.length>30_000_000)throw new Error(`فایل ${name} بیش از حد بزرگ است.`);
    try{result[name]=JSON.parse(bytes.toString('utf8'))}catch{throw new Error(`محتوای ${name} JSON معتبر نیست.`)}
  }
  if(!Object.keys(result).length)throw new Error('هیچ فایل JSON قابل بازیابی در بسته نبود.');
  return result;
}

export function stateKeyForFile(file:string):string|undefined{return STATE_FILES[file]}

function addFile(files:Record<string,{size:number;b64:string}>,name:string,value:unknown){const text=JSON.stringify(value),bytes=Buffer.from(text,'utf8');files[name]={size:bytes.length,b64:bytes.toString('base64')}}
function stripImages<T>(value:T):T {const text=JSON.stringify(value).replace(/data:image\/[a-z0-9.+-]+;base64,[a-z0-9+/=]+/gi,'');return JSON.parse(text)}
