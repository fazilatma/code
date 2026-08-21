/**
 * Pure helpers for server-side duplicate detection on destination shops (WooCommerce / Basalam).
 * The heavy work (paged listing + chunked removal) lives in background.ts; everything here is
 * deterministic and unit-tested so the keep-criterion and suffix-format rules stay verifiable.
 */
export type DedupKeep = 'newest' | 'oldest' | 'cheapest' | 'expensive';
export type DedupCandidate = { id: number; shopId: string; name: string; price: number; date: string; status: string; sku: string };
export type DedupGroup = { key: string; title: string; keep: DedupCandidate; remove: DedupCandidate[] };

export const DEFAULT_SUFFIX_FORMATS = ['(کد:x)', '#x'];
export const DEDUP_KEEPS: DedupKeep[] = ['newest', 'oldest', 'cheapest', 'expensive'];

const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹', AR_DIGITS = '٠١٢٣٤٥٦٧٨٩';
/** Persian/Arabic digits in product titles must count as the numeric part of a code suffix. */
export function normalizeDigits(value: string): string {
  return String(value || '').replace(/[۰-۹٠-٩]/g, ch => {
    const fa = FA_DIGITS.indexOf(ch); if (fa >= 0) return String(fa);
    const ar = AR_DIGITS.indexOf(ch); return ar >= 0 ? String(ar) : ch;
  });
}

export function normalizeDedupKeep(value: unknown): DedupKeep {
  const raw = String(value || '').trim().toLowerCase();
  return (DEDUP_KEEPS as string[]).includes(raw) ? raw as DedupKeep : 'newest';
}

/**
 * User-editable suffix formats, e.g. "(کد:x)، #x" — comma/newline separated; `x` marks a
 * multi-digit number. Formats without an `x` placeholder are ignored to avoid stripping
 * arbitrary words from titles.
 */
export function parseSuffixFormats(input: unknown): string[] {
  const raw = Array.isArray(input) ? input.map(value => String(value)) : String(input ?? '').split(/[,،|\n]+/);
  const formats = raw.map(value => value.trim()).filter(value => value && /[xX]/.test(value)).slice(0, 8);
  return formats.length ? formats : [...DEFAULT_SUFFIX_FORMATS];
}

const escapeRegex = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Compiles each format into an end-of-title matcher; `x` accepts a 1–10 digit number. */
export function suffixPatterns(formats: string[]): RegExp[] {
  return parseSuffixFormats(formats).map(format => {
    const body = escapeRegex(format).replace(/[xX]+/g, '\\d{1,10}').replace(/\s+/g, '\\s*');
    return new RegExp('(?:\\s|[-–—_·.])*' + body + '\\s*$', 'i');
  });
}

/** Strips every configured code suffix (repeatedly, so "نام (کد:2) (کد:15)" also collapses). */
export function stripDedupSuffix(name: string, patterns: RegExp[]): string {
  let out = normalizeDigits(String(name || ''));
  for (let guard = 0; guard < 5; guard++) {
    let changed = false;
    for (const pattern of patterns) { const next = out.replace(pattern, ''); if (next !== out) { out = next; changed = true; } }
    if (!changed) break;
  }
  return out;
}

/** Canonical duplicate key: suffix-free, Persian-normalized, whitespace-collapsed, per shop. */
export function dedupKey(name: string, shopId: string, patterns: RegExp[]): string {
  const base = stripDedupSuffix(name, patterns).toLowerCase()
    .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
    .replace(/[\u200c\u200f\u200e]/g, ' ').replace(/\s+/g, ' ').trim();
  return base ? `${shopId || 'default'}::${base}` : '';
}

const timestamp = (row: DedupCandidate): number | null => {
  const value = Date.parse(String(row.date || ''));
  return Number.isFinite(value) ? value : null;
};

/**
 * Ordering per keep criterion; the FIRST item after sorting is the survivor. When creation dates
 * are missing (some Basalam payloads), a larger id counts as newer. Price ties keep the newer item.
 */
export function compareForKeep(a: DedupCandidate, b: DedupCandidate, keep: DedupKeep): number {
  const at = timestamp(a), bt = timestamp(b);
  switch (keep) {
    case 'oldest': { if (at !== null && bt !== null && at !== bt) return at - bt; return a.id - b.id; }
    case 'cheapest': { if (a.price !== b.price) return a.price - b.price; return b.id - a.id; }
    case 'expensive': { if (a.price !== b.price) return b.price - a.price; return b.id - a.id; }
    default: { if (at !== null && bt !== null && at !== bt) return bt - at; return b.id - a.id; }
  }
}

/** Groups same-named products (per shop) and picks the survivor by the keep criterion. */
export function buildDedupGroups(products: DedupCandidate[], keep: DedupKeep, formats: string[]): DedupGroup[] {
  const patterns = suffixPatterns(formats), map = new Map<string, DedupCandidate[]>();
  for (const product of products) {
    const key = dedupKey(product.name, product.shopId, patterns);
    if (!key) continue;
    const rows = map.get(key) || []; rows.push(product); map.set(key, rows);
  }
  const groups: DedupGroup[] = [];
  for (const [key, rows] of map) {
    if (rows.length < 2) continue;
    const sorted = [...rows].sort((a, b) => compareForKeep(a, b, keep));
    groups.push({ key, title: sorted[0].name, keep: sorted[0], remove: sorted.slice(1) });
  }
  return groups.sort((a, b) => b.remove.length - a.remove.length || a.title.localeCompare(b.title, 'fa'));
}
