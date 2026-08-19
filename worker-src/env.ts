import type { JobMessage } from './types.js';

export interface D1PreparedStatement {
  bind(...values: unknown[]): D1PreparedStatement;
  first<T = Record<string, unknown>>(column?: string): Promise<T | null>;
  all<T = Record<string, unknown>>(): Promise<{ success: boolean; results: T[]; error?: string }>;
  run<T = Record<string, unknown>>(): Promise<{ success: boolean; results?: T[]; meta: { changes?: number; last_row_id?: number }; error?: string }>;
}
export interface D1Database {
  prepare(sql: string): D1PreparedStatement;
  batch<T = unknown>(statements: D1PreparedStatement[]): Promise<T[]>;
  exec(sql: string): Promise<{ count: number; duration: number }>;
}
export interface QueueProducer<T> { send(message: T, options?: { delaySeconds?: number }): Promise<void>; }
export interface R2ObjectBody { body: ReadableStream; text(): Promise<string>; }
export interface R2Bucket {
  put(key: string, value: string | ArrayBuffer | ReadableStream, options?: { httpMetadata?: { contentType?: string }; customMetadata?: Record<string,string> }): Promise<unknown>;
  get(key: string): Promise<R2ObjectBody | null>;
}

export type Env = {
  DB: D1Database;
  JOBS?: QueueProducer<JobMessage>;
  /** Provisioning-only producer binding that guarantees the dead-letter queue exists. */
  JOBS_DLQ?: QueueProducer<JobMessage>;
  BACKUPS?: R2Bucket;
  ADMIN_TOKEN?: string;
  VAULT_SECRET?: string;
  ALLOW_INSECURE?: string;
  REQUEST_TIMEOUT_MS?: string;
  DETAIL_CONCURRENCY?: string;
  JOB_CHUNK_SIZE?: string;
  MAX_RESPONSE_BYTES?: string;
  WOO_URL?: string;
  WOO_KEY?: string;
  WOO_SECRET?: string;
  BASALAM_TOKEN?: string;
  BASALAM_VENDOR_ID?: string;
  BASALAM_API?: string;
  WORKER_VERSION?: string;
};

let current: Env | undefined;
export function configureEnv(env: Env): void { current = env; }
export function getEnv(): Env {
  if (!current?.DB) throw new Error('D1 binding DB is not configured');
  return current;
}
