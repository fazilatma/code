import { loadConnections } from './connections.js';
import { getRemoteId, setRemoteId } from './db.js';
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
  const payload: any = { name: product.title, sku, type: 'simple', regular_price: String(product.price), description: product.longDesc || '',
    short_description: product.shortDesc || '', images: product.images.map(src => ({ src })) };
  if (product.stock !== undefined) Object.assign(payload, { manage_stock: true, stock_quantity: product.stock });
  if (product.weight) payload.weight = String(product.weight);
  const wooCategory=profile.wooCategoryId||c.categoryId;if(wooCategory) payload.categories = [{ id: wooCategory }];
  const response = await safeFetch(id ? `${base}/${id}` : base, { method: 'POST', headers: { authorization: auth, 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify(payload) }, 3_000_000);
  const body = await response.json().catch(() => ({})) as any;
  if (!response.ok) throw new Error(`WooCommerce HTTP ${response.status}: ${body.message || JSON.stringify(body).slice(0,300)}`);
  const remoteId = Number(body.id || id); if (remoteId) await setRemoteId(profile.id, product.sourceKey, 'woo', remoteId);
  return id ? 'updated' : 'created';
}

export async function syncBasalam(product: Product, profile: Profile): Promise<'created'|'updated'> {
  const c = (await loadConnections()).basalam; if (!c.token || !c.vendorId) throw new Error('تنظیمات باسلام در منوی همبرگری کامل نیست');
  const existing = await getRemoteId(profile.id, product.sourceKey, 'basalam');
  const base = `${c.api}/vendors/${encodeURIComponent(c.vendorId)}/products`;
  const payload: any = { name: product.title, price: product.price, stock: product.stock ?? c.stock, description: product.longDesc || product.shortDesc || '',
    photo: product.image || undefined, category_id: profile.basalamCategoryId || c.categoryId || undefined, weight: product.weight || c.weight, package_weight:c.packageWeight, preparation_days:c.preparationDays };
  const endpoint = existing ? `${base}/${existing}` : base;
  const response = await safeFetch(endpoint, { method: existing ? 'PATCH' : 'POST', headers: { authorization: `Bearer ${c.token}`, 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify(payload) }, 3_000_000);
  const body = await response.json().catch(() => ({})) as any;
  if (!response.ok) throw new Error(`Basalam HTTP ${response.status}: ${body.message || JSON.stringify(body).slice(0,300)}`);
  const remoteId = Number(body.id || body.product?.id || existing); if (remoteId) await setRemoteId(profile.id, product.sourceKey, 'basalam', remoteId);
  return existing ? 'updated' : 'created';
}
