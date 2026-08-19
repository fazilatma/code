# Render TypeScript changelog

## 2.1.0 — 2026-08-19

- Rebuilt the AI hamburger section as five PHP-style tabs: providers, tests, models, candidates/master and network.
- Added JSON provider file/paste import, provider toggles and active provider/model selection.
- Added background AI model testing with persisted progress, stop, resume/status and prior-result filtering.
- Added OpenAI-compatible, Ollama and Cloudflare-style response handling.
- Added candidate category/reply comparison, voting, leaderboard and master selection.
- Added Direct, DoH, manual DNS, Proxy and Worker network diagnostics.

## 2.0.0 — 2026-08-19

- Added authenticated `/scraper4.php` compatibility endpoint for legacy query and form clients.
- Mapped legacy profile, queue, cron, backup, Basalam, AI, autoreply, digest, recon, photo, suffix, selector and image-proxy operations.
- Legacy operations not yet mapped now return explicit HTTP 501 instead of silently succeeding.
- Preserved bearer/API-token protection and database readiness checks for compatibility calls.

## 1.9.0 — 2026-08-19

- Added native XLSX import/export with styled worksheets.
- Added fixture-based parser regression tests for Persian prices, relative URLs and stable product keys.
- Expanded versioned parity work for legacy import workflows.

## 1.8.0 — 2026-08-19

- Added full-source PHP/TypeScript parity audit (`npm run parity:audit`).
- Added live SSE job progress with polling fallback.
- Added WooCommerce deduplication preview/apply.
- Added Basalam rejected-product report and category correction.
- Added destination suffix reports.
- Added destination product listing, overview, duplicate scan, status changes and guarded deletion.
- Added category endpoints for WooCommerce and Basalam.
- Added CSV product import/export.
- Added queue retry/delete/clear controls.
- Added gallery selector suggestions.
- Added per-product destination sync endpoints.
- Added runtime self-test and machine-readable parity report.
- Added centralized application version metadata and version synchronization test.

## 1.7.0 — 2026-08-19

- Added final hamburger-menu inventory and runtime self-test foundation.

## 1.6.0 and earlier

- Implemented Render-native profiles, scraping, queues, encrypted connection vault, AI providers, notifications, multi-shop Basalam, destination maintenance, category learning, autoreply, digest, PHP settings migration and visual selector picking.
