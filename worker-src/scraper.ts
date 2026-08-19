import { loadConnections } from './connections.js';
import { safeText, safeTextViaWorker } from './network.js';
import { escapeHtml, sha256 } from './utils.js';
import type { Product, Profile, Selectors, VariationGroup } from './types.js';

type SelectorMap=Partial<Selectors>;

interface RewriterResponse { text():Promise<string> }
declare class HTMLRewriter {
  on(selector:string,handler:any):HTMLRewriter;
  transform(response:Response):RewriterResponse;
}

type HtmlElement={
  tagName?:string;
  getAttribute(name:string):string|null;
  setAttribute(name:string,value:string):void;
  removeAttribute(name:string):void;
  before(value:string,options?:{html?:boolean}):void;
  after(value:string,options?:{html?:boolean}):void;
  remove():void;
  onEndTag(callback:()=>void):void;
  attributes:Iterable<[string,string]>;
};
type TextChunk={text:string;lastInTextNode?:boolean};

type FieldName='title'|'price'|'link'|'image'|'sku';
type RankedValue={value:string;rank:number};
type Card={values:Partial<Record<FieldName,RankedValue>>};
type DetailResult={
  shortDesc:string;longDesc:string;sku:string;brand:string;stock:string;weight:string;category:string;tags:string;mainImage:string;
  images:string[];variations:string[];variationGroups:VariationGroup[];variationPrices:Record<string,number>;
};

const DEFAULT_CONTAINER='.product, li.product, article.product, .product-item, .product-card, [data-product-id], [itemtype*="Product"]';
const FALLBACKS:Record<FieldName,string>={
  title:'.woocommerce-loop-product__title, .product-title, .product-name, [itemprop="name"], h1, h2, h3',
  price:'.price ins, .sale-price, [itemprop="price"], .price, .amount, [data-price]',
  link:'a.woocommerce-LoopProduct-link, a.product-link, a[href*="/product/"], a[href*="/products/"], a[href]',
  image:'img.wp-post-image, img.product-image, [itemprop="image"], picture img, img, source',
  sku:'[data-sku], [itemprop="sku"], .sku'
};
const DETAIL_KEYS=['shortDesc','sku','category','tags','weight','stock','brand'] as const;
const IMAGE_ATTRS=['data-zoom-image','data-large_image','data-large-image','data-full','data-src','data-lazy-src','data-original','src','content','href'];
const LINK_ATTRS=['data-href','href','data-url','data-link','data-product-url','data-product-link','content'];
function onclickUrl(element:HtmlElement):string{return element.getAttribute('onclick')?.match(/(?:window\.)?location(?:\.href)?\s*=\s*['"]([^'"]+)['"]/i)?.[1]||''}
const TITLE_ATTRS=['data-title','title','aria-label','content'];
const PRICE_ATTRS=['data-price','data-regular-price','data-sale-price','content','value'];
const SKU_ATTRS=['data-sku','data-product-sku','content','value'];
// Cloudflare's streaming parser rejects Element.onEndTag() for HTML void
// elements (for example img/input/source) with "Parser error: No end tag".
// Attribute-only fields never need an end callback, and text handlers must skip
// it for tags which cannot contain text.
const VOID_TAGS=new Set(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr']);
function hasEndTag(element:HtmlElement):boolean{return !VOID_TAGS.has(String(element.tagName||'').toLowerCase())}
async function sourceKey(value:string):Promise<string>{return (await sha256(value)).slice(0,32)}
async function sourceText(url:string,indirect=false,maxBytes=8_000_000){
  if(!indirect)return safeText(url,maxBytes);
  const network=(await loadConnections()).ai.network;
  if(network.mode!=='worker')throw new Error('اتصال غیرمستقیم مبدأ در Cloudflare فقط با روش Worker URL پشتیبانی می‌شود.');
  return safeTextViaWorker(url,network.workerUrl,maxBytes);
}
function toAbsoluteUrl(value:string,base:string):string{try{return new URL(value,base).href}catch{return ''}}

const TRACKING_PARAMS=/^(utm_.+|fbclid|gclid|yclid|mc_cid|mc_eid|ref|ref_.*|source)$/i;

function selectorParts(selector?:string):string[]{
  const out:string[]=[],value=String(selector||'');let part='',round=0,square=0,quote='';
  for(const char of value){
    if(quote){part+=char;if(char===quote)quote='';continue}
    if(char==='"'||char==="'"){quote=char;part+=char}else if(char==='('){round++;part+=char}else if(char===')'){round=Math.max(0,round-1);part+=char}else if(char==='['){square++;part+=char}else if(char===']'){square=Math.max(0,square-1);part+=char}else if(char===','&&!round&&!square){if(part.trim())out.push(part.trim());part=''}else part+=char;
  }
  if(part.trim())out.push(part.trim());return out;
}
function multilineSelectorParts(selector?:string):string[]{return String(selector||'').split(/[\r\n|]+/).flatMap(part=>selectorParts(part)).filter(Boolean)}
function safeOn(rewriter:HTMLRewriter,selector:string,handler:any):boolean{
  try{rewriter.on(selector,handler);return true}catch{return false}
}
function cleanText(value:string):string{
  return normalizeDigits(String(value||'')).replace(/[\u200c\u200e\u200f\u202a-\u202e]/g,' ').replace(/\s+/g,' ').trim();
}
function normalizeDigits(value:string):string{
  return String(value||'').replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[٠-٩]/g,d=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
}
function firstAttribute(element:HtmlElement,names:string[]):string{
  for(const name of names){const value=element.getAttribute(name);if(value&&value.trim())return value.trim()}
  return '';
}
function srcsetValue(value:string):string{
  const items=String(value||'').split(',').map(part=>part.trim()).filter(Boolean).map(part=>{
    const match=part.match(/^(\S+)(?:\s+(\d+(?:\.\d+)?)(w|x))?$/i);
    return {url:match?.[1]||part.split(/\s+/)[0],score:Number(match?.[2]||1)*(match?.[3]?.toLowerCase()==='x'?10000:1)};
  }).filter(item=>item.url);
  return items.sort((a,b)=>b.score-a.score)[0]?.url||'';
}
function elementValue(field:FieldName,element:HtmlElement,text=''):string{
  if(field==='link')return firstAttribute(element,LINK_ATTRS)||onclickUrl(element);
  if(field==='image')return firstAttribute(element,IMAGE_ATTRS)||srcsetValue(element.getAttribute('data-srcset')||element.getAttribute('srcset')||'');
  if(field==='title')return cleanText(text)||firstAttribute(element,TITLE_ATTRS);
  if(field==='price')return cleanText(text)||firstAttribute(element,PRICE_ATTRS);
  if(field==='sku')return cleanText(text)||firstAttribute(element,SKU_ATTRS);
  return cleanText(text);
}
function canonicalUrl(value:string,baseUrl:string,stripAllQuery=false):string{
  const raw=String(value||'').trim();if(!raw||/^(?:#|javascript:|mailto:|tel:|data:|blob:)/i.test(raw))return '';
  const absolute=toAbsoluteUrl(raw.replace(/&amp;/gi,'&'),baseUrl);
  if(!absolute||!/^(https?):/i.test(absolute))return '';
  try{
    const url=new URL(absolute);url.hash='';
    if(stripAllQuery)url.search='';
    else for(const key of [...url.searchParams.keys()])if(TRACKING_PARAMS.test(key))url.searchParams.delete(key);
    url.pathname=url.pathname.replace(/\/{2,}/g,'/');
    return url.toString().replace(/\/$/,'');
  }catch{return absolute}
}
function imageUrl(value:string,baseUrl:string):string{
  const raw=String(value||'').trim();
  if(!raw||/^(data:|blob:|javascript:|#)/i.test(raw)||/(?:placeholder|spacer|transparent|loading)(?:[-_.]|$)/i.test(raw))return '';
  const absolute=toAbsoluteUrl(raw.replace(/&amp;/gi,'&'),baseUrl);
  return /^(https?):/i.test(absolute)?absolute:'';
}
function galleryKey(url:string):string{return url.replace(/-\d{2,4}x\d{2,4}(?=\.[a-z]{3,5}(?:[?#]|$))/i,'').replace(/[?#].*$/,'')}
function addGalleryImage(images:string[],raw:string,baseUrl:string,max=30):void{const url=imageUrl(raw,baseUrl);if(url&&images.length<Math.max(1,Math.min(30,max))&&!images.some(existing=>galleryKey(existing)===galleryKey(url)))images.push(url)}
function linkScore(value:string):number{
  if(!value||/^(javascript:|mailto:|tel:|#)/i.test(value))return -1000;
  let score=0;
  if(/\/products?\//i.test(value))score+=40;
  if(/[?&](?:add-to-cart|remove_item)=|\/cart\/?|wishlist|compare/i.test(value))score-=200;
  return score;
}
function setCardValue(card:Card,field:FieldName,value:string,rank:number,baseUrl:string):void{
  let clean=String(value||'').trim();
  if(field==='link'){clean=canonicalUrl(clean,baseUrl);rank+=linkScore(clean)}
  else if(field==='image')clean=imageUrl(clean,baseUrl);
  else clean=cleanText(clean);
  if(!clean)return;
  const previous=card.values[field];
  if(!previous||rank>previous.rank)card.values[field]={value:clean,rank};
}

class CardHandler {
  stack:Card[]=[];
  constructor(private output:Card[],private baseUrl:string){}
  element(element:HtmlElement):void{
    const card:Card={values:{}};this.stack.push(card);
    setCardValue(card,'link',firstAttribute(element,LINK_ATTRS),15,this.baseUrl);
    setCardValue(card,'image',firstAttribute(element,IMAGE_ATTRS)||srcsetValue(element.getAttribute('srcset')||''),15,this.baseUrl);
    setCardValue(card,'title',firstAttribute(element,TITLE_ATTRS),15,this.baseUrl);
    setCardValue(card,'price',firstAttribute(element,PRICE_ATTRS),15,this.baseUrl);
    setCardValue(card,'sku',firstAttribute(element,SKU_ATTRS),15,this.baseUrl);
    if(!hasEndTag(element)){this.stack.pop();this.output.push(card);return}
    element.onEndTag(()=>{const ended=this.stack.pop();if(ended)this.output.push(ended)});
  }
  current():Card|undefined{return this.stack[this.stack.length-1]}
}
class CardFieldHandler {
  private captures:Array<{card:Card;text:string;element:HtmlElement}>=[];
  constructor(private cards:CardHandler,private field:FieldName,private rank:number,private baseUrl:string){}
  element(element:HtmlElement):void{
    const card=this.cards.current();if(!card||this.captures.some(capture=>capture.card===card))return;
    const immediate=elementValue(this.field,element);
    if(immediate)setCardValue(card,this.field,immediate,this.rank+2,this.baseUrl);
    if(this.field==='link'||this.field==='image'||!hasEndTag(element))return;
    const capture={card,text:'',element};this.captures.push(capture);
    element.onEndTag(()=>{
      setCardValue(card,this.field,elementValue(this.field,element,capture.text),this.rank,this.baseUrl);
      const index=this.captures.indexOf(capture);if(index>=0)this.captures.splice(index,1);
    });
  }
  text(chunk:TextChunk):void{for(const capture of this.captures)capture.text+=chunk.text}
}

export function numberFromText(value:string):number{
  const normalized=normalizeDigits(value).replace(/[٬،]/g,',').replace(/\u00a0/g,' ');
  const matches=normalized.match(/\d[\d\s,._]{0,30}\d|\d/g)||[];
  const numbers=matches.map(raw=>{
    let token=raw.trim().replace(/\s/g,'');
    if(/^\d+[.,]\d{1,2}$/.test(token)&&!/[٬،]/.test(raw))return Number(token.replace(',','.'));
    token=token.replace(/[^\d]/g,'');return Number(token||0);
  }).filter(n=>Number.isFinite(n)&&n>=0);
  return numbers.length?Math.max(...numbers):0;
}

async function parseJsonLdProducts(html:string,baseUrl:string):Promise<Product[]>{
  const products:Product[]=[];
  for(const match of html.matchAll(/<script\b[^>]*type=["']application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)){
    try{
      const data=JSON.parse(match[1].replace(/^\s*<!--|-->\s*$/g,''));
      const walk=(node:unknown,insideVariant=false):void=>{
        if(!node||typeof node!=='object')return;
        if(Array.isArray(node)){node.forEach(item=>walk(item,insideVariant));return}
        const item=node as Record<string,any>,types=Array.isArray(item['@type'])?item['@type']:[item['@type']];
        if(!insideVariant&&types.some(type=>String(type||'').toLowerCase()==='product')){
          const offers=Array.isArray(item.offers)?item.offers[0]:item.offers||{};
          const image=Array.isArray(item.image)?item.image[0]:(typeof item.image==='object'?item.image?.url:item.image),imageValue=imageUrl(String(image||''),baseUrl);
          const title=cleanText(item.name||'');const url=canonicalUrl(item.url||item['@id']||'',baseUrl),availability=String(offers.availability||'');
          if(title||url)products.push({sourceKey:'',title,price:numberFromText(String(offers.price||offers.lowPrice||'')),priceText:cleanText(String(offers.price||offers.lowPrice||'')),url,image:imageValue,images:imageValue?[imageValue]:[],sku:cleanText(String(item.sku||item.mpn||'')),brand:cleanText(String(typeof item.brand==='object'?item.brand?.name:item.brand||'')),shortDesc:cleanText(String(item.description||'')),longDesc:'',stock:/outofstock|soldout|discontinued/i.test(availability)?0:undefined,weight:undefined,category:cleanText(String(item.category||'')),tags:cleanText(Array.isArray(item.keywords)?item.keywords.join(', '):String(item.keywords||'')),variations:[],variationGroups:[],variationPrices:{},sourcePage:baseUrl,scrapedAt:new Date().toISOString()})
        }
        for(const[key,value]of Object.entries(item))walk(value,insideVariant||key==='hasVariant'||key==='isVariantOf');
      };walk(data);
    }catch{/* malformed structured data must not abort the page */}
  }
  return products;
}

export async function parseCards(html:string,baseUrl:string,selectors:SelectorMap):Promise<Product[]>{
  const cards:Card[]=[];const cardHandler=new CardHandler(cards,baseUrl);const rewriter=new HTMLRewriter();
  const containers=selectorParts(selectors.container||DEFAULT_CONTAINER);
  let validContainer=false;for(const selector of containers)validContainer=safeOn(rewriter,selector,cardHandler)||validContainer;
  if(!validContainer)throw new Error('سلکتور ظرف محصول نامعتبر است.');
  for(const field of ['title','price','link','image','sku'] as FieldName[]){
    const configured=selectorParts(selectors[field]);
    for(const selector of configured){
      safeOn(rewriter,selector,new CardFieldHandler(cardHandler,field,100,baseUrl));
      if(field==='image')for(const suffix of ['img','source','a'])safeOn(rewriter,`${selector} ${suffix}`,new CardFieldHandler(cardHandler,field,99,baseUrl));
      if(field==='link')for(const suffix of ['a[href]','[data-href]','[data-url]','[data-link]','[data-product-url]','[data-product-link]','[onclick]'])safeOn(rewriter,`${selector} ${suffix}`,new CardFieldHandler(cardHandler,field,99,baseUrl));
    }
    for(const selector of selectorParts(FALLBACKS[field]))safeOn(rewriter,selector,new CardFieldHandler(cardHandler,field,10,baseUrl));
  }
  try{await rewriter.transform(new Response(html,{headers:{'content-type':'text/html; charset=UTF-8'}})).text()}catch(error){throw new Error(`پردازش HTML فهرست شکست خورد: ${error instanceof Error?error.message:String(error)}`)}
  const output:Product[]=[];const seen=new Set<string>();
  for(const card of cards){
    let title=card.values.title?.value||'',url=card.values.link?.value||'',image=card.values.image?.value||'';
    const priceText=card.values.price?.value||'',sku=card.values.sku?.value||'';
    if(!title&&url){try{title=decodeURIComponent(new URL(url).pathname.split('/').filter(Boolean).pop()||'').replace(/[-_]+/g,' ')}catch{/* ignored */}}
    if(!title&&!url)continue;
    const identity=url?canonicalUrl(url,baseUrl,true):`${title}|${priceText}`;
    const key=await sourceKey(identity);if(seen.has(key))continue;seen.add(key);
    output.push({sourceKey:key,title,price:numberFromText(priceText),priceText,url,image,images:image?[image]:[],sku,shortDesc:'',longDesc:'',brand:'',stock:undefined,weight:undefined,category:'',variations:[],variationGroups:[],variationPrices:{},sourcePage:baseUrl,scrapedAt:new Date().toISOString()});
  }
  // Structured data is not only an all-or-nothing fallback: many storefronts render
  // cards with one missing field while keeping the complete product in JSON-LD.
  const structured=await parseJsonLdProducts(html,baseUrl),byKey=new Map<string,Product>(),byUrl=new Map<string,Product|null>(),bySku=new Map<string,Product|null>(),byTitle=new Map<string,Product|null>();
  const addUnique=(map:Map<string,Product|null>,key:string,product:Product)=>{if(key)map.set(key,map.has(key)?null:product)};
  for(const product of structured){
    const identity=product.url?canonicalUrl(product.url,baseUrl,true):`${product.title}|${product.priceText}`;product.sourceKey=await sourceKey(identity);byKey.set(product.sourceKey,product);
    addUnique(byUrl,canonicalUrl(product.url,baseUrl,true),product);addUnique(bySku,cleanText(product.sku||'').toLowerCase(),product);addUnique(byTitle,cleanText(product.title).toLowerCase(),product);
  }
  for(let index=0;index<output.length;index++){
    const product=output[index],urlKey=canonicalUrl(product.url,baseUrl,true),skuKey=cleanText(product.sku||'').toLowerCase(),titleKey=cleanText(product.title).toLowerCase();
    const fallback=byKey.get(product.sourceKey)||byUrl.get(urlKey)||bySku.get(skuKey)||byTitle.get(titleKey)||null;if(!fallback)continue;
    const merged:Product={...fallback,...product,title:product.title||fallback.title,price:product.price>0?product.price:fallback.price,priceText:product.priceText||fallback.priceText,url:product.url||fallback.url,image:product.image||fallback.image,images:product.images.length?product.images:fallback.images,shortDesc:product.shortDesc||fallback.shortDesc,longDesc:product.longDesc||fallback.longDesc,sku:product.sku||fallback.sku,brand:product.brand||fallback.brand,stock:product.stock??fallback.stock,weight:product.weight??fallback.weight,category:product.category||fallback.category,tags:product.tags||fallback.tags,variations:product.variations?.length?product.variations:fallback.variations,variationGroups:product.variationGroups?.length?product.variationGroups:fallback.variationGroups,variationPrices:Object.keys(product.variationPrices||{}).length?product.variationPrices:fallback.variationPrices};
    const mergedIdentity=merged.url?canonicalUrl(merged.url,baseUrl,true):`${merged.title}|${merged.priceText}`;merged.sourceKey=await sourceKey(mergedIdentity);output[index]=merged;byKey.delete(fallback.sourceKey);
  }
  const final:Product[]=[],finalSeen=new Set<string>();for(const product of [...output,...byKey.values()])if(!finalSeen.has(product.sourceKey)){finalSeen.add(product.sourceKey);final.push(product)}return final;
}

const DETAIL_ATTRS:Record<string,string[]>={shortDesc:['data-description','data-summary','content','title','aria-label'],sku:SKU_ATTRS,category:['data-category','data-category-name','content','title'],tags:['data-tags','data-keywords','content'],weight:['data-weight','data-product-weight','content','value'],stock:['data-stock','data-quantity','data-stock-quantity','content','value'],brand:['data-brand','data-brand-name','content','title']};
class ScalarHandler {
  private captures:Array<{text:string;element:HtmlElement}>=[];
  constructor(private key:string,private values:Map<string,string>){}
  element(element:HtmlElement):void{
    if(this.values.get(this.key)||this.captures.length)return;
    const immediate=firstAttribute(element,DETAIL_ATTRS[this.key]||['data-value','content','value']);
    if(immediate)this.values.set(this.key,cleanText(immediate));
    if(!hasEndTag(element))return;
    const capture={text:'',element};this.captures.push(capture);
    element.onEndTag(()=>{
      if(!this.values.get(this.key)){const value=cleanText(capture.text);if(value)this.values.set(this.key,value)}
      const index=this.captures.indexOf(capture);if(index>=0)this.captures.splice(index,1);
    });
  }
  text(chunk:TextChunk):void{for(const capture of this.captures)capture.text+=chunk.text}
}
class DetailImageHandler {
  constructor(private result:DetailResult,private baseUrl:string){}
  element(element:HtmlElement):void{if(this.result.mainImage)return;const value=firstAttribute(element,IMAGE_ATTRS)||srcsetValue(element.getAttribute('data-srcset')||element.getAttribute('srcset')||'');this.result.mainImage=imageUrl(value,this.baseUrl)}
}
class GalleryHandler {
  constructor(private images:string[],private baseUrl:string,private max=30){}
  element(element:HtmlElement):void{
    const candidates=[...IMAGE_ATTRS.map(attr=>element.getAttribute(attr)||''),element.getAttribute('href')||'',element.getAttribute('content')||'',srcsetValue(element.getAttribute('data-srcset')||''),srcsetValue(element.getAttribute('srcset')||'')];
    for(const candidate of candidates)addGalleryImage(this.images,candidate,this.baseUrl,this.max)
  }
}
class LongDescriptionHandler {
  constructor(private marker:string){}
  element(element:HtmlElement):void{element.before(`<!--${this.marker}:START-->`,{html:true});element.after(`<!--${this.marker}:END-->`,{html:true})}
}
class SanitizeHandler {
  element(element:HtmlElement):void{
    for(const [name] of Array.from(element.attributes))if(/^on/i.test(name)||name.toLowerCase()==='srcdoc')element.removeAttribute(name);
    for(const name of ['href','src','data-src']){const value=element.getAttribute(name);if(value&&/^\s*(?:javascript|data\s*:\s*text\/html)/i.test(value))element.removeAttribute(name)}
  }
}
class RemoveHandler {element(element:HtmlElement):void{element.remove()}}

function variationName(element:HtmlElement):string{return cleanText(firstAttribute(element,['data-attribute_name','data-attribute-name','data-name','name','data-label','aria-label']))}
class VariationContext {
  stack:string[]=[];
  current():string{return this.stack[this.stack.length-1]||''}
}
class VariationScopeHandler {
  constructor(private context:VariationContext){}
  element(element:HtmlElement):void{if(!hasEndTag(element))return;const name=variationName(element);this.context.stack.push(name);element.onEndTag(()=>this.context.stack.pop())}
}
function mergeVariation(result:DetailResult,element:HtmlElement,text:string,baseUrl:string,inheritedName=''):void{
  const attrs=Object.fromEntries(Array.from(element.attributes));
  let json:Record<string,any>={};for(const key of ['data-product_variation','data-variation','data-product-variation']){try{if(attrs[key])json=JSON.parse(attrs[key])}catch{/* ignored */}}
  const name=variationName(element)||cleanText(String(json.attribute_name||json.name||''))||inheritedName;
  const explicitValue=firstAttribute(element,['data-value','value','data-variation','data-slug'])||String(json.variation||json.value||'');
  const tag=String(element.tagName||'').toLowerCase();
  if(!name&&!explicitValue&&!['option','button','input'].includes(tag))return;
  const value=cleanText(explicitValue||text);
  if(!value||/^(انتخاب|choose|select|لطفا)/i.test(value))return;
  const label=cleanText(text);for(const item of [value,label])if(item&&item.length<=180&&!result.variations.includes(item))result.variations.push(item);
  if(name){let group=result.variationGroups.find(group=>group.name===name);if(!group){group={name,values:[]};result.variationGroups.push(group)}if(!group.values.includes(value))group.values.push(value)}
  const price=numberFromText(String(json.display_price||json.price||firstAttribute(element,['data-display_price','data-display-price','data-price','data-regular-price','data-sale-price'])||text));
  if(price>0){result.variationPrices[value]=price;if(label)result.variationPrices[label]=price}
  const variationImage=String(json.image?.full_src||json.image?.src||json.image||firstAttribute(element,IMAGE_ATTRS)||'');addGalleryImage(result.images,variationImage,baseUrl);
}
class VariationHandler {
  private captures:Array<{element:HtmlElement;text:string}>=[];
  constructor(private result:DetailResult,private baseUrl:string,private context:VariationContext){}
  element(element:HtmlElement):void{
    if(!hasEndTag(element)){mergeVariation(this.result,element,'',this.baseUrl,this.context.current());return}
    const capture={element,text:''};this.captures.push(capture);
    element.onEndTag(()=>{mergeVariation(this.result,element,capture.text,this.baseUrl,this.context.current());const index=this.captures.indexOf(capture);if(index>=0)this.captures.splice(index,1)});
  }
  text(chunk:TextChunk):void{for(const capture of this.captures)capture.text+=chunk.text}
}
function extractMarkedFragment(html:string,marker:string):string{
  const start=`<!--${marker}:START-->`,end=`<!--${marker}:END-->`,from=html.indexOf(start);if(from<0)return '';
  const to=html.indexOf(end,from+start.length);return to<0?'':html.slice(from+start.length,to).trim();
}
function stripUnsafeHtml(html:string):string{return html.replace(/<(script|style|iframe|object|embed|form)\b[^>]*>[\s\S]*?<\/\1\s*>/gi,'').replace(/<(script|style|iframe|object|embed|form)\b[^>]*\/?\s*>/gi,'').replace(/\s+on\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi,'').replace(/\s+(href|src|srcdoc)\s*=\s*(["'])\s*(?:javascript|data\s*:\s*text\/html)[\s\S]*?\2/gi,'')}

export async function parseDetailPage(html:string,baseUrl:string,selectors:SelectorMap):Promise<DetailResult>{
  const result:DetailResult={shortDesc:'',longDesc:'',sku:'',brand:'',stock:'',weight:'',category:'',tags:'',mainImage:'',images:[],variations:[],variationGroups:[],variationPrices:{}};
  const values=new Map<string,string>(),rewriter=new HTMLRewriter();
  for(const key of DETAIL_KEYS)for(const selector of selectorParts(selectors[key]))safeOn(rewriter,selector,new ScalarHandler(key,values));
  const marker=`SCRAPER4_${Math.random().toString(36).slice(2)}`;
  for(const selector of selectorParts(selectors.longDesc)){
    safeOn(rewriter,selector,new LongDescriptionHandler(marker));
    for(const suffix of ['script','style','iframe','object','embed','form'])safeOn(rewriter,`${selector} ${suffix}`,new RemoveHandler());
    safeOn(rewriter,`${selector} *`,new SanitizeHandler());
  }
  const detailImage=new DetailImageHandler(result,baseUrl);
  for(const selector of selectorParts(selectors.detailImage)){safeOn(rewriter,selector,detailImage);for(const suffix of ['img','source','a[href]','[data-src]','[data-large_image]','[data-zoom-image]'])safeOn(rewriter,`${selector} ${suffix}`,detailImage)}
  const galleryMax=Math.max(1,Math.min(30,Math.trunc(Number(selectors.galleryMax)||30)));
  const galleryImages:string[]=[],gallery=new GalleryHandler(galleryImages,baseUrl,galleryMax);
  for(const selector of multilineSelectorParts(selectors.gallery)){
    safeOn(rewriter,selector,gallery);
    for(const suffix of ['img','source','a','meta','[data-src]','[data-zoom-image]'])safeOn(rewriter,`${selector} ${suffix}`,gallery);
  }
  const variationContext=new VariationContext();
  for(const selector of multilineSelectorParts(selectors.variations)){
    safeOn(rewriter,`${selector} select`,new VariationScopeHandler(variationContext));
    safeOn(rewriter,selector,new VariationHandler(result,baseUrl,variationContext));
    for(const suffix of ['option','button','input','[data-value]','[data-variation]','[data-product_variation]'])safeOn(rewriter,`${selector} ${suffix}`,new VariationHandler(result,baseUrl,variationContext));
  }
  let transformed='';try{transformed=await rewriter.transform(new Response(html,{headers:{'content-type':'text/html; charset=UTF-8'}})).text()}catch(error){throw new Error(`پردازش HTML جزئیات شکست خورد: ${error instanceof Error?error.message:String(error)}`)}
  for(const key of DETAIL_KEYS)result[key]=values.get(key)||'';
  const includeGallery=multilineSelectorParts(selectors.gallery).length>0;
  result.longDesc=stripUnsafeHtml(extractMarkedFragment(transformed,marker));if(includeGallery)for(const image of result.images)addGalleryImage(galleryImages,image,baseUrl,galleryMax);result.images=galleryImages;
  applyJsonLdDetail(html,baseUrl,result,galleryMax,includeGallery);
  if(selectors.gallerySkipFirst&&result.images.length)result.images=result.images.slice(1);
  result.variations=[...new Set(result.variations.map(cleanText).filter(Boolean))];
  result.variationGroups=result.variationGroups.filter(group=>group.name&&group.values.length).map(group=>({...group,values:[...new Set(group.values.map(cleanText).filter(Boolean))]}));
  return result;
}
function applyJsonLdDetail(html:string,baseUrl:string,result:DetailResult,galleryMax=30,includeGallery=true):void{
  const imageValue=(raw:any)=>String(typeof raw==='object'?(raw?.url||raw?.contentUrl||raw?.['@id']||''):raw||'');
  const addVariant=(variant:any)=>{
    if(!variant||typeof variant!=='object')return;const groups:Array<[string,string]>=[];
    for(const key of ['color','size','material','pattern']){const value=cleanText(String(variant[key]||''));if(value)groups.push([key,value])}
    const properties=Array.isArray(variant.additionalProperty)?variant.additionalProperty:[variant.additionalProperty];for(const property of properties)if(property&&typeof property==='object'){const name=cleanText(String(property.name||property.propertyID||'ویژگی')),value=cleanText(String(property.value||property.valueReference?.name||''));if(value)groups.push([name,value])}
    if(!groups.length&&variant.isVariantOf){const value=cleanText(String(variant.name||''));if(value)groups.push(['تنوع',value])}
    const offer=Array.isArray(variant.offers)?variant.offers[0]:variant.offers||{},price=numberFromText(String(offer.price||offer.lowPrice||offer.highPrice||''));
    for(const[name,value]of groups){if(!result.variations.includes(value))result.variations.push(value);let group=result.variationGroups.find(item=>item.name===name);if(!group){group={name,values:[]};result.variationGroups.push(group)}if(!group.values.includes(value))group.values.push(value);if(price>0)result.variationPrices[value]=price}
    if(includeGallery){const images=Array.isArray(variant.image)?variant.image:[variant.image];for(const raw of images)addGalleryImage(result.images,imageValue(raw),baseUrl,galleryMax)}
  };
  for(const match of html.matchAll(/<script\b[^>]*type=["']application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi))try{
    const root=JSON.parse(match[1].replace(/^\s*<!--|-->\s*$/g,'')),queue:any[]=[root];while(queue.length){const node=queue.shift();if(!node||typeof node!=='object')continue;if(Array.isArray(node)){queue.push(...node);continue}queue.push(...Object.values(node).filter(value=>value&&typeof value==='object'));
      const types=(Array.isArray(node['@type'])?node['@type']:[node['@type']]).map((type:unknown)=>String(type||'').toLowerCase());if(!types.includes('product')&&!types.includes('productgroup'))continue;
      if(types.includes('product')){if(!result.sku)result.sku=cleanText(String(node.sku||node.mpn||''));if(!result.brand)result.brand=cleanText(String(typeof node.brand==='object'?node.brand?.name:node.brand||''));if(!result.category)result.category=cleanText(String(node.category||''));if(!result.tags)result.tags=cleanText(Array.isArray(node.keywords)?node.keywords.join(', '):String(node.keywords||''));if(!result.shortDesc)result.shortDesc=cleanText(String(node.description||''));if(!result.weight)result.weight=cleanText(String(typeof node.weight==='object'?(node.weight.value||node.weight.valueReference?.value||''):node.weight||''));const offer=Array.isArray(node.offers)?node.offers[0]:node.offers||{},availability=String(offer.availability||'');if(!result.stock&&/outofstock|soldout|discontinued/i.test(availability))result.stock='0';
        const images=Array.isArray(node.image)?node.image:[node.image];if(!result.mainImage)for(const raw of images){const candidate=imageUrl(imageValue(raw),baseUrl);if(candidate){result.mainImage=candidate;break}}if(includeGallery)for(const raw of images)addGalleryImage(result.images,imageValue(raw),baseUrl,galleryMax);if(node.isVariantOf)addVariant(node)}
      const variants=Array.isArray(node.hasVariant)?node.hasVariant:[node.hasVariant];for(const variant of variants)addVariant(variant)
    }
  }catch{/* malformed structured data is only a fallback and must not abort extraction */}
}
function hasDetailSelectors(selectors:SelectorMap):boolean{return ([...DETAIL_KEYS,'longDesc','detailImage','gallery','variations'] as Array<keyof Selectors>).some(key=>String(selectors[key]||'').trim().length>0)}

export async function scrapeDetails(product:Product,selectors:Selectors,indirect=false,maxBytes=4_000_000):Promise<Product>{
  if(!product.url||!hasDetailSelectors(selectors))return product;
  const {text}=await sourceText(product.url,indirect,maxBytes);
  const detail=await parseDetailPage(text,product.url,selectors);
  const mainImage=detail.mainImage||product.image||'',images=[...new Set([mainImage,...detail.images].filter(Boolean))];
  return {...product,shortDesc:detail.shortDesc||product.shortDesc,longDesc:detail.longDesc||product.longDesc,sku:detail.sku||product.sku,brand:detail.brand||product.brand,stock:detail.stock?numberFromText(detail.stock):product.stock,weight:detail.weight?numberFromText(detail.weight):product.weight,category:detail.category||product.category,tags:detail.tags||product.tags,images,image:mainImage||images[0]||product.image,variations:detail.variations.length?detail.variations:(product.variations||[]),variationGroups:detail.variationGroups.length?detail.variationGroups:(product.variationGroups||[]),variationPrices:Object.keys(detail.variationPrices).length?detail.variationPrices:(product.variationPrices||{})};
}

export async function extractVariations(html:string,baseUrl:string,selector:string):Promise<Pick<Product,'variations'|'variationGroups'|'variationPrices'|'images'>>{
  const parsed=await parseDetailPage(html,baseUrl,{variations:selector});return {variations:parsed.variations,variationGroups:parsed.variationGroups,variationPrices:parsed.variationPrices,images:parsed.images};
}

export async function extractSelectorValues(html:string,baseUrl:string,selector:string,type:'text'|'link'|'image'|'html'|'variations'):Promise<string[]>{
  if(type==='variations'){const result=await extractVariations(html,baseUrl,selector);return result.variations||[]}
  const values:string[]=[];
  class ValueHandler {
    private captures:Array<{element:HtmlElement;text:string}>=[];
    element(element:HtmlElement):void{
      if(type==='link'){const value=canonicalUrl(firstAttribute(element,LINK_ATTRS)||onclickUrl(element),baseUrl);if(value)values.push(value);return}
      if(type==='image'){const value=imageUrl(firstAttribute(element,IMAGE_ATTRS)||srcsetValue(element.getAttribute('srcset')||''),baseUrl);if(value)values.push(value);return}
      if(!hasEndTag(element)){const value=firstAttribute(element,[...TITLE_ATTRS,...PRICE_ATTRS,...SKU_ATTRS]);if(value)values.push(cleanText(value));return}
      const capture={element,text:''};this.captures.push(capture);element.onEndTag(()=>{const value=cleanText(capture.text)||firstAttribute(element,[...TITLE_ATTRS,...PRICE_ATTRS,...SKU_ATTRS]);if(value)values.push(value);const i=this.captures.indexOf(capture);if(i>=0)this.captures.splice(i,1)})
    }
    text(chunk:TextChunk):void{for(const capture of this.captures)capture.text+=chunk.text}
  }
  const rewriter=new HTMLRewriter(),handler=new ValueHandler();let valid=false;for(const part of selectorParts(selector))valid=safeOn(rewriter,part,handler)||valid;if(!valid)throw new Error('سلکتور نامعتبر است.');await rewriter.transform(new Response(html)).text();return [...new Set(values)].slice(0,100);
}

class NextLinkHandler {
  url='';
  constructor(private baseUrl:string){}
  element(element:HtmlElement):void{if(!this.url)this.url=canonicalUrl(firstAttribute(element,LINK_ATTRS),this.baseUrl)}
}
export async function scrapeListPage(url:string,selectors:Selectors,nextSelector='',indirect=false):Promise<{products:Product[];nextUrl:string;url:string}>{
  const page=await sourceText(url,indirect),next=new NextLinkHandler(page.url);
  if(nextSelector){const rewriter=new HTMLRewriter();for(const selector of selectorParts(nextSelector))safeOn(rewriter,selector,next);await rewriter.transform(new Response(page.text)).text()}
  const products=await parseCards(page.text,page.url,selectors);
  return {products,nextUrl:next.url,url:page.url};
}
export async function scrapeList(url:string,selectors:Selectors,indirect=false):Promise<Product[]>{return (await scrapeListPage(url,selectors,'',indirect)).products}

/** Runs the same network, list parser and detail parser used by real jobs, but never writes or syncs products. */
export async function diagnoseExtraction(profile:Profile,urlOverride=''){
  const started=Date.now(),url=String(urlOverride||profile.url||'').trim(),stages:any[]=[],recommendations:string[]=[];
  const add=(name:string,ok:boolean,summary:string,details:any={})=>stages.push({name,ok,summary,...details});
  if(!url){add('configuration',false,'آدرس مبدأ خالی است.');return{ok:false,profileId:profile.id,url,stages,recommendations:['آدرس صفحهٔ فهرست محصولات را در پروفایل وارد کنید.']}}
  let page:{text:string;url:string;contentType:string};
  try{
    page=await sourceText(url,Boolean(profile.networkIndirect));
    const bytes=new TextEncoder().encode(page.text).byteLength,title=cleanText(page.text.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i)?.[1]?.replace(/<[^>]+>/g,' ')||'');
    add('network',true,`صفحه با ${bytes.toLocaleString('fa-IR')} بایت دریافت شد.`,{requestedUrl:url,finalUrl:page.url,contentType:page.contentType,bytes,title,indirect:Boolean(profile.networkIndirect)});
  }catch(error){const text=error instanceof Error?error.message:String(error);add('network',false,text,{requestedUrl:url,indirect:Boolean(profile.networkIndirect)});recommendations.push(/ضدربات|چالش/.test(text)?'سایت صفحهٔ ضدربات برگردانده است؛ دسترسی Worker را در مبدأ مجاز کنید یا Worker واسط معتبر تنظیم کنید.':'آدرس، دسترسی عمومی سایت و تنظیمات روش اتصال مبدأ را بررسی کنید.');return{ok:false,profileId:profile.id,url,startedAt:new Date(Date.now()-(Date.now()-started)).toISOString(),durationMs:Date.now()-started,stages,recommendations}}
  let products:Product[]=[];
  try{
    products=await parseCards(page.text,page.url,profile.selectors);
    const complete={title:products.filter(x=>x.title).length,price:products.filter(x=>x.price>0).length,link:products.filter(x=>x.url).length,image:products.filter(x=>x.image).length,sku:products.filter(x=>x.sku).length};
    add('list-extraction',products.length>0,products.length?`${products.length.toLocaleString('fa-IR')} محصول با pipeline واقعی استخراج شد.`:'هیچ محصولی از HTML/JSON-LD استخراج نشد.',{count:products.length,complete,selectors:profile.selectors,samples:products.slice(0,5).map(x=>({title:x.title,price:x.price,priceText:x.priceText,url:x.url,image:x.image,sku:x.sku}))});
  }catch(error){add('list-extraction',false,error instanceof Error?error.message:String(error),{selectors:profile.selectors})}
  const evidence:Record<string,unknown>={};
  for(const field of ['container','title','price','link','image'] as const){const selector=String(profile.selectors[field]||'').trim();if(!selector){evidence[field]={ok:false,count:0,error:'سلکتور خالی است'};continue}try{const type=field==='link'?'link':field==='image'?'image':'text',values=await extractSelectorValues(page.text,page.url,selector,type);evidence[field]={ok:values.length>0,count:values.length,sample:values.slice(0,3)}}catch(error){evidence[field]={ok:false,count:0,error:error instanceof Error?error.message:String(error)}}}
  const evidenceOk=['container','title'].every(key=>(evidence[key] as any)?.ok);
  add('selector-evidence',evidenceOk,evidenceOk?'سلکتورهای پایه روی پاسخ واقعی نشانه دارند.':'یک یا چند سلکتور پایه روی پاسخ واقعی نتیجه نداد.',{evidence});
  let detail:any=null;
  const candidate=products.find(product=>product.url);
  if(candidate&&hasDetailSelectors(profile.selectors))try{const extracted=await scrapeDetails(candidate,profile.selectors,Boolean(profile.networkIndirect));detail={url:candidate.url,title:extracted.title,shortDesc:extracted.shortDesc,descriptionCharacters:String(extracted.longDesc||'').length,sku:extracted.sku,brand:extracted.brand,stock:extracted.stock,weight:extracted.weight,category:extracted.category,tags:extracted.tags,image:extracted.image,galleryCount:extracted.images.length,variations:extracted.variations?.slice(0,20)};add('detail-extraction',true,'صفحهٔ جزئیات نمونه با pipeline واقعی پردازش شد.',{sample:detail})}catch(error){add('detail-extraction',false,error instanceof Error?error.message:String(error),{url:candidate.url})}
  else add('detail-extraction',true,candidate?'برای این پروفایل سلکتور جزئیات تنظیم نشده است.':'محصول دارای لینک برای تست جزئیات پیدا نشد.',{skipped:true});
  if(!products.length)recommendations.push('سلکتور ظرف محصول را با HTML واقعی اصلاح کنید؛ پیشنهاد خودکار را اجرا و سپس دوباره همین عیب‌یاب را بزنید.');
  else{if(!products.some(x=>x.price>0))recommendations.push('محصول پیدا شده ولی قیمت صفر است؛ سلکتور قیمت و واحد/متن قیمت را بررسی کنید.');if(!products.some(x=>x.url))recommendations.push('لینک محصول پیدا نشده است؛ سلکتور لینک باید به عنصر a یا ویژگی href/data-url برسد.');if(!products.some(x=>x.image))recommendations.push('تصویر پیدا نشده است؛ data-src، srcset یا سلکتور تصویر را بررسی کنید.')}
  const failed=stages.filter(stage=>!stage.ok);return{ok:products.length>0&&failed.length===0,profileId:profile.id,url,finalUrl:page.url,startedAt:new Date(Date.now()-(Date.now()-started)).toISOString(),durationMs:Date.now()-started,productCount:products.length,stages,recommendations,detail};
}

export function transformProduct(product:Product,profile:Profile):Product{
  product.title=cleanText(product.title+profile.titleSuffix).slice(0,300);const value=profile.priceValue;
  if(profile.priceMode==='add')product.price+=value;if(profile.priceMode==='percent')product.price*=1+value/100;if(profile.priceMode==='multiply')product.price*=value;
  if(profile.roundPrice>0)product.price=Math.ceil(product.price/profile.roundPrice)*profile.roundPrice;product.price=Math.max(0,Math.round(product.price));return product;
}
export function pageUrl(profile:Profile,page:number):string{
  const url=new URL(profile.url);if(page<=1||profile.pagination==='none'||profile.pagination==='next_selector')return url.href;
  if(profile.pagination==='full_pattern')return profile.paginationValue.split('{page}').join(String(page));
  if(profile.pagination==='path_page'||profile.pagination==='path_pattern'){
    const pattern=profile.pagination==='path_page'?'/page/{page}/':(profile.paginationValue||'/page/{page}/');
    const basePath=url.pathname.replace(/\/page\/\d+\/?$/i,'').replace(/\/$/,'');
    return url.origin+basePath+pattern.split('{page}').join(String(page));
  }
  url.hash='';url.searchParams.set(profile.pagination==='query_custom'?(profile.paginationValue||'paged'):'page',String(page));return url.href;
}
export async function mapLimit<T>(items:T[],limit:number,fn:(item:T,index:number)=>Promise<void>):Promise<void>{
  let next=0;await Promise.all(Array.from({length:Math.min(Math.max(1,limit),items.length)},async()=>{while(true){const index=next++;if(index>=items.length)return;await fn(items[index],index)}}));
}
export async function testSelector(url:string,selector:string,type='text'):Promise<{count:number;values:string[]}>{
  const page=await safeText(url,4_000_000),values=await extractSelectorValues(page.text,page.url,selector,type==='link'?'link':type==='image'?'image':'text');return {count:values.length,values:values.slice(0,20)};
}
export async function testVariations(url:string,selector:string){const page=await safeText(url,4_000_000);return {url:page.url,...await extractVariations(page.text,page.url,selector)}}
export async function testGallery(url:string,selector:string,max=30,skipFirst=false){const page=await safeText(url,4_000_000),detail=await parseDetailPage(page.text,page.url,{gallery:selector,galleryMax:max,gallerySkipFirst:skipFirst});return{url:page.url,count:detail.images.length,values:detail.images}}
const SUGGESTION_CANDIDATES:Record<string,{type?:'text'|'link'|'image';selectors:string[]}>= {
  container:{selectors:['li.product','article.product','.products .product','.product-card','.product-item','[data-product-id]']},title:{selectors:['.woocommerce-loop-product__title','.product-title','.card-title','h2','h3','[itemprop="name"]']},price:{selectors:['.price ins','.sale-price','.price','[itemprop="price"]','.amount']},link:{type:'link',selectors:['a.woocommerce-LoopProduct-link','a.product-link','a[href*="/product/"]','a[href]']},image:{type:'image',selectors:['img.wp-post-image','img.product-image','picture img','img']},shortDesc:{selectors:['.woocommerce-product-details__short-description','.short-description','[itemprop="description"]']},longDesc:{selectors:['#tab-description','.woocommerce-Tabs-panel--description','.product-description','.description']},sku:{selectors:['.sku','[itemprop="sku"]','[data-sku]']},brand:{selectors:['.brand','[itemprop="brand"]','.product-brand']},stock:{selectors:['.stock','[itemprop="availability"]','.inventory']},weight:{selectors:['.product_weight','.weight','[data-weight]']},category:{selectors:['.posted_in','.product_meta .category','.breadcrumb']},tags:{selectors:['.tagged_as','.product_meta .tags','[rel="tag"]']},detailImage:{type:'image',selectors:['.woocommerce-product-gallery__image img','.product-main-image img','img.wp-post-image','[itemprop="image"]']},gallery:{type:'image',selectors:['.woocommerce-product-gallery img','.product-gallery img','[data-gallery] img','.gallery img']},variations:{selectors:['.variations','.variations_form','[data-product_variations]','.product-options']}
};
export async function suggestSelectors(url:string,mode:'list'|'detail'|'all'='all'){
  const page=await safeText(url,4_000_000),wanted=mode==='list'?['container','title','price','link','image']:mode==='detail'?['shortDesc','longDesc','sku','category','tags','weight','stock','brand','detailImage','gallery','variations']:Object.keys(SUGGESTION_CANDIDATES),selectors:Record<string,string>={},evidence:Record<string,unknown>={};
  for(const field of wanted){const config=SUGGESTION_CANDIDATES[field];for(const candidate of config.selectors)try{const values=await extractSelectorValues(page.text,page.url,candidate,config.type||'text');const count=values.length,minimum=field==='container'?2:1;if(count>=minimum){selectors[field]=candidate;evidence[field]={count,sample:values[0]||''};break}}catch{/* try the next known selector */}}
  return {url:page.url,mode,selectors,evidence};
}
export function safeLongDescription(value:string):string{return value||`<p>${escapeHtml(value)}</p>`}
