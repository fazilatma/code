export type Selectors = {
  container: string;
  title: string;
  price: string;
  link: string;
  image: string;
  shortDesc?: string;
  longDesc?: string;
  sku?: string;
  brand?: string;
  stock?: string;
  weight?: string;
  category?: string;
  gallery?: string;
};

export type Profile = {
  id: string;
  name: string;
  url: string;
  enabled: boolean;
  pages: number;
  pagination: 'query_page' | 'path_page' | 'none';
  paginationValue: string;
  selectors: Selectors;
  titleSuffix: string;
  priceMode: 'none' | 'add' | 'percent' | 'multiply';
  priceValue: number;
  roundPrice: number;
  minPrice: number;
  wooCategoryId: number;
  basalamCategoryId: number;
  syncWoo: boolean;
  syncBasalam: boolean;
  intervalMinutes: number;
  lastRunAt: string | null;
  createdAt: string;
  updatedAt: string;
};

export type Product = {
  sourceKey: string;
  title: string;
  price: number;
  priceText: string;
  url: string;
  image: string;
  images: string[];
  shortDesc?: string;
  longDesc?: string;
  sku?: string;
  brand?: string;
  stock?: number;
  weight?: number;
  category?: string;
  sourcePage: string;
  scrapedAt: string;
};

export type Job = {
  id: string;
  profileId: string;
  kind: 'scrape' | 'sync';
  target: 'none' | 'woo' | 'basalam' | 'both';
  status: 'queued' | 'running' | 'done' | 'failed' | 'stopped';
  phase: string;
  total: number;
  processed: number;
  added: number;
  updated: number;
  failed: number;
  stopRequested: boolean;
  error: string | null;
  log: Array<{ at: string; level: string; message: string }>;
  createdAt: string;
  startedAt: string | null;
  finishedAt: string | null;
  updatedAt: string;
};

export const DEFAULT_SELECTORS: Selectors = {
  container: 'li.product',
  title: 'h2, h3, .woocommerce-loop-product__title',
  price: '.price, .amount',
  link: 'a[href]',
  image: 'img'
};
