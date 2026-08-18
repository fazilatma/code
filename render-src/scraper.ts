import * as cheerio from 'cheerio';
import { createHash } from 'node:crypto';
import { safeText } from './network.js';
import type { Product, Profile, Selectors } from './types.js';

const normalize = (value: string) => value.replace(/[\u200c\u200d\u200e\u200f\ufeff]/g, ' ').replace(/\s+/g, ' ').trim();
const absolute = (value: string, base: string) => { try { const url = new URL(value, base); return ['http:','https:'].includes(url.protocol) ? url.href : ''; } catch { return ''; } };

export function numberFromText(value: string): number {
  const en = value.replace(/[۰-۹]/g, d => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[٠-٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
  const groups = en.match(/\d[\d,٬.\s]*/g) || [];
  return groups.length ? Math.max(...groups.map(item => Number(item.replace(/\D/g, '')) || 0)) : 0;
}

function sourceKey(url: string, title: string): string { return createHash('sha256').update(url || title).digest('hex').slice(0, 32); }
function firstText($root: cheerio.Cheerio<any>, selector: string): string { return normalize($root.find(selector).first().text()); }
function firstAttr($root: cheerio.Cheerio<any>, selector: string, attrs: string[]): string {
  const node = $root.find(selector).first();
  for (const attr of attrs) { const value = node.attr(attr); if (value && value !== '#') return value; }
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

export async function scrapeList(url: string, selectors: Selectors): Promise<Product[]> {
  const { text, url: finalUrl } = await safeText(url);
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

export async function scrapeDetails(product: Product, selectors: Selectors): Promise<Product> {
  if (!product.url) return product;
  const { text, url } = await safeText(product.url); const $ = cheerio.load(text); const body = $.root();
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
  return product;
}

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
  product.title = normalize(product.title + profile.titleSuffix); const value = profile.priceValue;
  if (profile.priceMode === 'add') product.price += value;
  if (profile.priceMode === 'percent') product.price *= 1 + value / 100;
  if (profile.priceMode === 'multiply') product.price *= value;
  if (profile.roundPrice > 0) product.price = Math.ceil(product.price / profile.roundPrice) * profile.roundPrice;
  product.price = Math.round(product.price); return product;
}

export async function mapLimit<T>(items: T[], limit: number, fn: (item: T, index: number) => Promise<void>): Promise<void> {
  let next = 0;
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (true) { const index = next++; if (index >= items.length) return; await fn(items[index], index); }
  }));
}

export async function testSelector(url: string, selector: string, type = 'text'): Promise<{ count: number; values: string[] }> {
  const { text, url: final } = await safeText(url, 4_000_000); const $ = cheerio.load(text); const values: string[] = [];
  $(selector).slice(0, 20).each((_i, el) => { const node = $(el); let value = type === 'link' ? absolute(node.attr('href') || '', final) : type === 'image' ? absolute(node.attr('src') || node.attr('data-src') || '', final) : normalize(node.text()); if (value) values.push(value.slice(0, 1000)); });
  return { count: $(selector).length, values };
}
