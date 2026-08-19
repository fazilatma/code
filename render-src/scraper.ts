import * as cheerio from 'cheerio';
import { createHash } from 'node:crypto';
import { sourceText } from './source-network.js';
import type { Product, Profile, Selectors } from './types.js';

const normalize = (value: string) => value.replace(/[\u200c\u200d\u200e\u200f\ufeff]/g, ' ').replace(/\s+/g, ' ').trim();
const absolute = (value: string, base: string) => { try { const url = new URL(value, base); return ['http:','https:'].includes(url.protocol) ? url.href : ''; } catch { return ''; } };

export function numberFromText(value: string): number {
  const en = value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
  const groups = en.match(/\d[\d,٬.\s]*/g) || [];
  return groups.length ? Math.max(...groups.map(item => Number(item.replace(/\D/g, '')) || 0)) : 0;
}

function sourceKey(url: string, title: string): string { return createHash('sha256').update(url || title).digest('hex').slice(0, 32); }
function selectWithin($root:cheerio.Cheerio<any>,selector:string){try{return $root.is(selector)?$root.first():$root.find(selector).first()}catch{return $root.find(selector).first()}}
function firstText($root: cheerio.Cheerio<any>, selector: string): string { return normalize(selectWithin($root,selector).text()); }
function firstAttr($root: cheerio.Cheerio<any>, selector: string, attrs: string[]): string {
  let node=selectWithin($root,selector);
  for(const candidate of [node,node.find('a,img').first(),$root.closest('a')])for(const attr of attrs){const value=candidate.attr(attr);if(value&&value!=='#')return value}
  return '';
}

export function pageUrl(profile: Profile, page: number): string {
  const url = new URL(profile.url);
  if (page <= 1 || profile.pagination === 'none') return url.href;
  if (profile.pagination === 'path_page') {
    url.pathname = url.pathname.replace(/\/page\/\d+\/?$/i, '').replace(/\/$/, '') + `/page/${page}/`;
    return url.href;
  }
  url.searchParams.set(profile.paginationValue || 'page', String(page));
  return url.href;
}

export async function scrapeList(url: string, selectors: Selectors, forceDirect=false): Promise<Product[]> {
  const { text, url: finalUrl } = await sourceText(url,8_000_000,forceDirect);
  return parseProductHtml(text,finalUrl,selectors);
}
export function parseProductHtml(text:string,finalUrl:string,selectors:Selectors):Product[]{
  const $ = cheerio.load(text); const products: Product[] = [];
  $(selectors.container).each((_index, element) => {
    const root = $(element); const title = firstText(root, selectors.title); if (!title) return;
    const priceText = firstText(root, selectors.price);
    const link = absolute(firstAttr(root, selectors.link, ['href','data-href','data-url','data-product-url']), finalUrl);
    let imageValue = firstAttr(root, selectors.image, ['data-src','data-lazy-src','data-original','src']);
    if (!imageValue) imageValue = (firstAttr(root, selectors.image, ['srcset']).split(',')[0] || '').trim().split(/\s+/)[0];
    const image = absolute(imageValue, finalUrl);
    products.push({ sourceKey: sourceKey(link, title), title, price: numberFromText(priceText), priceText, url: link, image,
      images: image ? [image] : [], sourcePage: finalUrl, scrapedAt: new Date().toISOString() });
  });
  return products;
}

export async function scrapeDetails(product: Product, selectors: Selectors, forceDirect=false): Promise<Product> {
  if (!product.url) return product;
  const { text, url } = await sourceText(product.url,8_000_000,forceDirect); const $ = cheerio.load(text); const body = $.root();
  const textField = (selector?: string) => selector ? normalize(body.find(selector).first().text()) : '';
  product.shortDesc = textField(selectors.shortDesc) || product.shortDesc;
  product.longDesc = selectors.longDesc ? sanitizeHtml(body.find(selectors.longDesc).first().html() || '', url) : product.longDesc;
  product.sku = textField(selectors.sku) || product.sku;
  product.brand = textField(selectors.brand) || product.brand;
  product.category = textField(selectors.category) || product.category;
  const stock = textField(selectors.stock); if (stock) product.stock = numberFromText(stock);
  const weight = textField(selectors.weight); if (weight) product.weight = numberFromText(weight);
  if (selectors.gallery) {
    const images = new Set(product.images);
    body.find(selectors.gallery).each((_i, el) => {
      const node = $(el); const raw = node.attr('data-src') || node.attr('data-large_image') || node.attr('href') || node.attr('src') || '';
      const image = absolute(raw, url); if (image) images.add(image);
    });
    product.images = [...images].slice(0, 30); product.image ||= product.images[0] || '';
  }
  const variationData=parseVariations($,selectors.variations,url);if(variationData.groups.length)product.variationGroups=variationData.groups;if(variationData.variations.length){product.variations=variationData.variations;const prices=variationData.variations.map(x=>Number(x.price)||0).filter(Boolean);if(prices.length){product.priceMin=Math.min(...prices);product.priceMax=Math.max(...prices);if(!product.price)product.price=product.priceMin}}
  return product;
}

export function parseVariations($:cheerio.CheerioAPI,selector?:string,baseUrl=''){const groups=new Map<string,Set<string>>(),variations:NonNullable<Product['variations']>=[];const form=selector?$(selector).first():$('form.variations_form').first(),raw=form.attr('data-product_variations')||$('form.variations_form').attr('data-product_variations');if(raw&&raw!=='false')try{const rows=JSON.parse(raw);if(Array.isArray(rows))for(const row of rows){const attributes:Record<string,string>={};for(const [key,value] of Object.entries(row.attributes||{})){const name=key.replace(/^attribute_/,'').replace(/^pa_/,'').replace(/[-_]+/g,' '),option=String(value||'').trim();if(option){attributes[name]=option;if(!groups.has(name))groups.set(name,new Set());groups.get(name)!.add(option)}}const image=absolute(row.image?.full_src||row.image?.src||'',baseUrl);variations.push({sku:String(row.sku||''),price:Number(row.display_price??row.display_regular_price??0)||undefined,stock:row.max_qty==null?row.is_in_stock===false?0:undefined:Number(row.max_qty),image,attributes})}}catch{}$('table.variations select,form.variations_form select').each((_i,el)=>{const node=$(el),name=(node.attr('data-attribute_name')||node.attr('name')||'ویژگی').replace(/^attribute_/,'').replace(/^pa_/,'').replace(/[-_]+/g,' ');if(!groups.has(name))groups.set(name,new Set());node.find('option').each((_j,opt)=>{const value=normalize($(opt).text());if(value&&!/انتخاب|choose/i.test(value))groups.get(name)!.add(value)})});if(!variations.length){$('script[type="application/ld+json"]').each((_i,el)=>{try{const data=JSON.parse($(el).text()),items=Array.isArray(data)?data:[data];for(const item of items){const offers=item.offers;if(Array.isArray(offers))for(const offer of offers)variations.push({sku:String(offer.sku||''),price:Number(offer.price||0)||undefined,stock:/OutOfStock/i.test(String(offer.availability))?0:undefined,attributes:{}});else if(offers?.lowPrice&&offers?.highPrice){variations.push({price:Number(offers.lowPrice),attributes:{}});if(Number(offers.highPrice)!==Number(offers.lowPrice))variations.push({price:Number(offers.highPrice),attributes:{}})}}}catch{}})}return{groups:[...groups].map(([name,options])=>({name,options:[...options]})).filter(x=>x.options.length),variations}}

function sanitizeHtml(html: string, base: string): string {
  const $ = cheerio.load(`<div id="s4">${html}</div>`); const root = $('#s4');
  root.find('script,style,iframe,object,embed,form,input,button').remove();
  root.find('*').each((_i, el) => {
    const node = $(el); for (const name of Object.keys(el.attribs || {})) {
      if (/^on/i.test(name) || ['srcdoc','style'].includes(name.toLowerCase())) node.removeAttr(name);
    }
    for (const name of ['href','src']) { const value = node.attr(name); if (value) { const resolved = absolute(value, base); resolved ? node.attr(name, resolved) : node.removeAttr(name); } }
  });
  return root.html() || '';
}

export function transformProduct(product: Product, profile: Profile): Product {
  product.title=normalize(product.title+profile.titleSuffix);const convert=(price:number)=>{let value=price;if(profile.priceMode==='add')value+=profile.priceValue;if(profile.priceMode==='percent')value*=1+profile.priceValue/100;if(profile.priceMode==='multiply')value*=profile.priceValue;if(profile.roundPrice>0)value=Math.ceil(value/profile.roundPrice)*profile.roundPrice;return Math.round(value)};product.price=convert(product.price);if(product.variations?.length){for(const variation of product.variations)if(variation.price)variation.price=convert(variation.price);const prices=product.variations.map(x=>x.price||0).filter(Boolean);if(prices.length){product.priceMin=Math.min(...prices);product.priceMax=Math.max(...prices)}}return product;
}

export async function mapLimit<T>(items: T[], limit: number, fn: (item: T, index: number) => Promise<void>): Promise<void> {
  let next = 0;
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (true) { const index = next++; if (index >= items.length) return; await fn(items[index], index); }
  }));
}

export function validateSelectorConfig(selectors:Selectors){const $=cheerio.load('<main><div></div></main>'),errors:Record<string,string>={};for(const [key,selector] of Object.entries(selectors)){if(!selector)continue;try{$(selector)}catch(error){errors[key]=error instanceof Error?error.message:'سلکتور نامعتبر'}}return errors}
export async function selectorWorkbench(url:string,selectors:Selectors){const page=await sourceText(url,6_000_000),$=cheerio.load(page.text),errors:Record<string,string>={};for(const key of ['container','title','price','link','image'] as const)try{$(selectors[key])}catch(error){errors[key]=error instanceof Error?error.message:'سلکتور نامعتبر'}if(errors.container)return{url:page.url,containerCount:0,products:[],firstProductUrl:'',fields:{title:0,price:0,link:0,image:0},errors};let products:Product[]=[];try{products=parseProductHtml(page.text,page.url,selectors).slice(0,20)}catch(error){errors.parse=error instanceof Error?error.message:String(error)}return{url:page.url,containerCount:$(selectors.container).length,products,firstProductUrl:products.find(p=>p.url)?.url||'',fields:{title:products.filter(p=>p.title).length,price:products.filter(p=>p.price>0).length,link:products.filter(p=>p.url).length,image:products.filter(p=>p.image).length},errors}}
export async function suggestListSelectors(url:string){const {text}=await sourceText(url,5_000_000),$=cheerio.load(text),containers=['li.product','div.product','.product-card','.product-item','article.product','[itemtype*=Product]','.woocommerce-LoopProduct-link'],titles=['h2','h3','.title','.product-title','.woocommerce-loop-product__title','[itemprop=name]'],prices=['.price','.amount','[itemprop=price]','.product-price','ins .amount'],links=['a[href]','.product-link','[itemprop=url]'],images=['img','img[data-src]','.product-image','[itemprop=image]'];const count=(selector:string,root?:cheerio.Cheerio<any>)=>{try{return(root||$.root()).find(selector).length}catch{return 0}},bestContainer=containers.map(selector=>({selector,count:count(selector)})).filter(x=>x.count>1).sort((a,b)=>b.count-a.count),sample=bestContainer[0]?.selector?$(bestContainer[0].selector).first():$.root(),rank=(items:string[])=>items.map(selector=>({selector,count:count(selector,sample)})).filter(x=>x.count).sort((a,b)=>b.count-a.count);return{container:bestContainer,title:rank(titles),price:rank(prices),link:rank(links),image:rank(images)}}
export async function testDetailSelectors(url:string,selectors:Selectors){const {text,url:final}=await sourceText(url,6_000_000),$=cheerio.load(text),result:Record<string,{count:number;preview:string}>={};for(const key of ['shortDesc','longDesc','sku','brand','stock','weight','category','gallery','variations'] as const){const selector=selectors[key];if(!selector)continue;let nodes;try{nodes=$(selector)}catch{result[key]={count:0,preview:'سلکتور نامعتبر'};continue}const first=nodes.first(),preview=key==='gallery'?absolute(first.attr('data-large_image')||first.attr('data-src')||first.attr('src')||'',final):normalize(first.text()).slice(0,500);result[key]={count:nodes.length,preview}}return{url:final,fields:result}}

export async function suggestGallery(url:string):Promise<Array<{selector:string;count:number;sample:string[]}>>{const {text,url:final}=await sourceText(url,5_000_000),$=cheerio.load(text),candidates=['.woocommerce-product-gallery img','.product-gallery img','.gallery img','[class*=gallery] img','[class*=thumb] img','img[data-large_image]','img[data-src]','main img','article img'],results:Array<{selector:string;count:number;sample:string[]}>=[];for(const selector of candidates){const images=new Set<string>();$(selector).each((_i,el)=>{const node=$(el),raw=node.attr('data-large_image')||node.attr('data-src')||node.attr('src')||'',value=absolute(raw,final);if(value&&!value.startsWith('data:'))images.add(value)});if(images.size)results.push({selector,count:images.size,sample:[...images].slice(0,5)})}return results.sort((a,b)=>b.count-a.count)}

export async function testSelector(url: string, selector: string, type = 'text'): Promise<{ count: number; values: string[] }> {
  const { text, url: final } = await sourceText(url, 4_000_000); const $ = cheerio.load(text); const values: string[] = [];
  $(selector).slice(0, 20).each((_i, el) => { const node = $(el); let value = type === 'link' ? absolute(node.attr('href') || '', final) : type === 'image' ? absolute(node.attr('src') || node.attr('data-src') || '', final) : normalize(node.text()); if (value) values.push(value.slice(0, 1000)); });
  return { count: $(selector).length, values };
}
