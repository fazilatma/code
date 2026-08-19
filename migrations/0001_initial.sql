-- Scraper 4 canonical D1 schema
CREATE TABLE IF NOT EXISTS profiles (
  id TEXT PRIMARY KEY,
  data TEXT NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  interval_minutes INTEGER NOT NULL DEFAULT 0,
  last_run_at TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
CREATE TABLE IF NOT EXISTS products (
  profile_id TEXT NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  source_key TEXT NOT NULL,
  data TEXT NOT NULL,
  title TEXT NOT NULL,
  price INTEGER NOT NULL DEFAULT 0,
  source_url TEXT NOT NULL DEFAULT '',
  remote_woo_id INTEGER,
  remote_basalam_id INTEGER,
  active INTEGER NOT NULL DEFAULT 1,
  missing_since TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  PRIMARY KEY(profile_id, source_key)
);
CREATE INDEX IF NOT EXISTS products_profile_updated_idx ON products(profile_id, updated_at DESC);
CREATE INDEX IF NOT EXISTS products_title_idx ON products(profile_id, title);
CREATE TABLE IF NOT EXISTS jobs (
  id TEXT PRIMARY KEY,
  profile_id TEXT NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK(kind IN ('scrape','sync')),
  target TEXT NOT NULL DEFAULT 'none',
  status TEXT NOT NULL DEFAULT 'queued',
  phase TEXT NOT NULL DEFAULT 'waiting',
  total INTEGER NOT NULL DEFAULT 0,
  processed INTEGER NOT NULL DEFAULT 0,
  added INTEGER NOT NULL DEFAULT 0,
  updated INTEGER NOT NULL DEFAULT 0,
  failed INTEGER NOT NULL DEFAULT 0,
  stop_requested INTEGER NOT NULL DEFAULT 0,
  error TEXT,
  log TEXT NOT NULL DEFAULT '[]',
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  started_at TEXT,
  finished_at TEXT,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
CREATE INDEX IF NOT EXISTS jobs_queue_idx ON jobs(status, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS jobs_one_active_idx ON jobs(profile_id,kind) WHERE status IN ('queued','running');
CREATE TABLE IF NOT EXISTS destination_map (
  profile_id TEXT NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  source_key TEXT NOT NULL,
  target TEXT NOT NULL,
  account_key TEXT NOT NULL DEFAULT 'default',
  remote_id INTEGER NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  PRIMARY KEY(profile_id, source_key, target, account_key)
);
CREATE TABLE IF NOT EXISTS category_learning (
  phrase TEXT NOT NULL,
  category_id INTEGER NOT NULL,
  category_name TEXT NOT NULL DEFAULT '',
  hits INTEGER NOT NULL DEFAULT 1,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  PRIMARY KEY(phrase, category_id)
);
CREATE TABLE IF NOT EXISTS autoreply_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  chat_id INTEGER,
  customer TEXT NOT NULL DEFAULT '',
  input_text TEXT NOT NULL,
  output_text TEXT NOT NULL,
  source TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
CREATE TABLE IF NOT EXISTS app_state (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
