# Render TypeScript changelog

## 2.6.0 — 2026-08-19

- Added encrypted-vault GitHub repository/token/branch/path settings in the dashboard.
- Added direct PHP-compatible settings backup upload to GitHub Contents API.
- Added remote backup listing, download and guarded restore through the existing settings importer.
- Added scheduled GitHub backups with configurable intervals and persisted success/failure state.
- Added legacy `backup_run`, `backup_remote_list` and `backup_download` mappings.

## 2.5.0 — 2026-08-19

- Added a full-screen professional live AI test results dashboard.
- Added status/provider filters, full-text search, sorting, CSV export and provider summaries.
- Added success rate, pending, failure and average-latency analytics.
- Added progressive message/category responses and per-model error details.
- Added one-click model tests and per-candidate voting cards.
- Added stale background-test recovery and fixed only-untested progress accounting.

## 2.4.0 — 2026-08-19

- Added event-driven notifications for new orders, order status, chats, new products, source price/stock and Cron ping.
- Added persistent snapshot state, flood-safe first-run seeding and reminder throttling.
- Added unanswered-chat reminders with delay and maximum-repeat controls.
- Added preview/send and selected order/chat notification actions.
- Integrated notification sweep into the internal scheduler and Render Cron.

## 2.3.0 — 2026-08-19

- Added AI category recommendations using live Basalam leaf categories.
- Added lexical category prefiltering to keep prompts bounded and relevant.
- Added rejected Basalam product category preview and guarded batch apply.
- Added local uncategorized product recommendation batches.
- Added legacy aliases for `bsl_ai_category`, `bsl_fix_ai_cat_batch` and `bsl_master_fix`.

## 2.2.0 — 2026-08-19

- Rebuilt selector testing as a single-fetch scoped workbench.
- Added automatic list-selector suggestions and per-field validation errors.
- Added automatic first product URL discovery for detail selector testing.
- Added separate visual picking for list and product-detail pages.
- Added detail-selector batch tests and moved gallery suggestions to the product-detail workflow.
- Fixed selectors that target the container/root element itself.
- Fixed visual picker click handling where browser Event was incorrectly treated as detail mode.

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
