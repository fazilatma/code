# Render TypeScript changelog

## 2.17.0 — 2026-08-19

- Added image URL/content validation with source-network routing.
- Added OpenGraph, gallery, lazy image, srcset and JSON-LD media discovery.
- Added profile-wide missing/broken image preview and guarded recovery.
- Added persisted media health status and connected recovery to WooCommerce photo repair.
- Added PHP-compatible `fetch_missing_stream` mapping.

## 2.16.0 — 2026-08-19

- Fixed visual selectors being over-specific (`nth-of-type`/unique ID) and reporting one match.
- Added field-aware repeated-selector generation and container-scoped match counts.
- Added automatic transition from list fields to the real sample product page for detail fields.
- Reorganized the visual studio title and controls into separate rows.
- Added required/optional selector progress, field navigator, filtering and browser-like regression tests.

## 2.15.0 — 2026-08-19

- Fixed selector sub-tabs being hidden or deactivated when AI sub-tabs were clicked.
- Fixed auto-advance requesting a next-page selector when pagination is not next-selector mode.
- Added organized field navigation chips, required/optional completion counts and detail filtering.
- Added per-field visual picking, clear-detail controls and improved mobile visual-studio layout.
- Added live selector previews, stable semantic selectors, sibling/keyboard navigation and link-preserving navigation.

## 2.14.0 — 2026-08-19

- Rebuilt visual selection as an advanced responsive selector studio.
- Added desktop/tablet/mobile viewport controls, field auto-advance, history undo/redo and completion tracking.
- Added parent/child/previous/next DOM navigation, keyboard controls and selected-link navigation.
- Improved stable selector generation using IDs, semantic attributes, filtered classes and uniqueness checks.
- Preserved original links securely in the proxied page and added live parent-side previews.

## 2.13.0 — 2026-08-19

- Added selected-product sync queues for WooCommerce, Basalam or both.
- Added persistent job payloads and queued-key merging under profile dedup locks.
- Added local product title/price/stock/image editing and guarded archival.
- Added product-card selection, select-all and batch-send controls.
- Added legacy queue-add/save-products mappings.

## 2.12.0 — 2026-08-19

- Added advisory-lock queue deduplication per profile and job kind.
- Added persistent pause/resume controls and queue overview.
- Added bounded exponential retries for transient network/429/5xx failures only.
- Added attempts, maximum attempts and delayed availability timestamps.
- Added detail-phase heartbeats and retry/queue state in the dashboard.

## 2.11.0 — 2026-08-19

- Added a dedicated professional reports tab with profile/time filters and CSV export.
- Rebuilt nightly digest from the transactional product-change ledger.
- Added per-profile add/update/remove summaries and field-level price samples.
- Added automatic report retention by age and maximum row count.
- Added report APIs for filtered history and cleanup.

## 2.10.0 — 2026-08-19

- Added a transactional product change ledger for add/update/remove events.
- Added field-level diffs while ignoring non-business timestamps.
- Fixed extraction counters so unchanged products are no longer reported as updated.
- Added detailed per-job reports, recent report summaries and Legacy `extract_report` mapping.
- Included change history in full backups and restore.

## 2.9.0 — 2026-08-19

- Added all PHP pagination modes: query page/custom, path pattern, full URL pattern and next selector.
- Added real next-link discovery with relative URL resolution and loop protection.
- Added visual selection for the next-page control and integrated pagination checks into the selector workbench.
- Added deterministic pagination regression tests.

## 2.8.0 — 2026-08-19

- Added WooCommerce structured variation extraction from `data-product_variations`.
- Added fallback variation groups from select options and JSON-LD offers.
- Added variation price ranges, stock, SKU, image and attributes.
- Added native WooCommerce variable product/attribute/variation synchronization.
- Added Basalam variation summaries and CSV/XLSX/settings migration support.

## 2.7.0 — 2026-08-19

- Added source-site Direct, DoH, manual IP, HTTP/HTTPS Proxy and Worker routing.
- Added automatic direct fallback, per-request timeout and encrypted proxy credentials.
- Routed list/detail scraping, selector tests, gallery suggestions and visual proxy through the source transport.
- Added per-profile indirect-connection control and multi-path diagnostics.
- Added source-network migration in PHP-compatible settings bundles.

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
