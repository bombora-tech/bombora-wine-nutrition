# Technical Documentation

## Structure

```
wine-nutrition-manager/
├── bombora-wine-nutrition.php   Bootstrap: constants, autoloader, activation hooks, HPOS declaration
├── uninstall.php                Keeps all data; drops only with WNM_DELETE_DATA_ON_UNINSTALL
├── src/
│   ├── Autoloader.php           PSR-4 (WineNutrition\ → src/, chillerlan\ → vendor/)
│   ├── Plugin.php               Composition root; constructor injection, hook wiring
│   ├── Admin/
│   │   ├── AdminPage.php        Menu, tabs (Dashboard, Import/Export, Settings, Logs), asset loading
│   │   ├── AjaxController.php   wp_ajax_wnm_{list,save,delete,stats,import_batch}
│   │   └── FormHandler.php      admin_post_wnm_{save_settings,import_upload,import_cancel,export,download_log,regenerate}
│   ├── Database/
│   │   ├── Schema.php           dbDelta DDL, version option, migrations
│   │   └── NutritionRepository.php  CRUD, diffing, dashboard query, stats, streaming iterators
│   ├── Export/CsvExporter.php   Streaming CSV export
│   ├── Frontend/BlockRenderer.php  HTML block, marker merge, EU rounding, allergen emphasis
│   ├── Import/CsvImporter.php   Upload validation, analysis, batched processing
│   ├── Logging/Logger.php       Audit log table, queries, pruning
│   ├── Services/
│   │   ├── FieldRegistry.php    Field definitions, enabled state, labels, settings
│   │   ├── RecordSanitizer.php  Validation/sanitization for all write paths
│   │   ├── ProductSync.php      Writes blocks into product descriptions; Action Scheduler queue
│   │   └── QrService.php        Static SVG QR generation in uploads
│   └── WPML/LabelTranslator.php String registration/translation, post language
├── assets/                      admin.js (vanilla), admin.css
├── vendor/chillerlan/           QR library (decoder removed)
├── sql/                         Reference DDL, sample CSV
└── docs/
```

## Key design decisions

| Decision | Reason |
|---|---|
| SKU is the key; product IDs never stored | IDs differ per shop. Product lookup via `wc_product_meta_lookup` (indexed SKU) instead of postmeta. |
| `sku` is `VARCHAR(191)` (spec: 255) | utf8mb4 unique index limit on older MySQL/MariaDB (767 bytes). WooCommerce's own lookup column is 100. |
| Block written into `post_content` via `$wpdb->update` | No `save_post` recursion, no revisions, no kses filtering of our pre-escaped markup in cron context, no interference with other plugins' save hooks. Followed by `clean_post_cache()` + `wc_delete_product_transients()`. |
| Inline styles only | The block must look right with the plugin disabled; no plugin CSS is enqueued on the frontend. |
| QR as SVG file in uploads | Survives deactivation; no runtime generation; works without GD. `wp_kses` would strip inline SVG. |
| One render per WPML translation | Each translation has its own description, so each gets labels in its language. Values come from the single shared record. |
| Custom fields as JSON object keyed by field key (spec: label/value array) | Stable keys survive label edits and make WPML translation per field possible. The spec's array format is still accepted on import. |
| Separate CSV columns `cf_<key>` | Editable in Excel; JSON in a cell is not. |
| `updated_at` only changes on real changes | Makes incremental "changed since" exports meaningful across shops. |
| Empty CSV cell = unchanged (default) | Partial files (e.g. only `sku,qr_url`) cannot wipe data accidentally. |

## Flows

**Inline save**: JS → `wnm_save` (nonce + capability) → `RecordSanitizer::sanitize(empty_clears=true)` → `Repository::save()` (diff; `unchanged` exits early) → `ProductSync::render_sku()` synchronously (all product IDs with the SKU) → log.

**Import**: `admin_post_wnm_import_upload` validates the file and stores it as `uploads/wnm-private/<32-char token>.csv` (deny-all `.htaccess`), analyses it (single streaming pass + chunked `IN()` queries of 500 SKUs), and stores state in a per-user transient (24 h). The preview page then calls `wnm_import_batch` repeatedly; each call seeks to the saved byte offset, processes 200 rows, saves state, and queues changed SKUs on Action Scheduler (25 SKUs per action). The file is deleted on completion/cancel; abandoned files are removed by the daily maintenance cron.

**Product save**: `woocommerce_update_product` / `woocommerce_new_product` (priority 50) re-renders the block for that product if a record exists for its SKU. This covers new WPML translations, newly created products and SKU changes. Content is never removed on this path.

**Settings change**: `FieldRegistry::render_fingerprint()` is compared before/after save; if it changed, all records are queued for re-render.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wnm_capability` | filter | Capability for all admin actions (default `manage_woocommerce`) |
| `wnm_block_html` | filter | Modify rendered block HTML (without markers) `( $html, $record, $lang )` |
| `wnm_decimal_separator` | filter | `( $sep, $lang )` |
| `wnm_import_max_bytes` | filter | Upload limit (default 20 MB) |
| `wnm_product_rendered` | action | `( $product_id, $record\|null )` after a description was rewritten – use for page-cache purges |
| `wnm_render_skus` | action (AS) | Background render callback |
| `wnm_daily_maintenance` | action (cron) | Log pruning, temp file cleanup |

Example cache purge (WP Rocket):
```php
add_action( 'wnm_product_rendered', fn( $id ) => function_exists( 'rocket_clean_post' ) && rocket_clean_post( $id ) );
```

## Performance

Tested design targets: 5 000+ products, 10 000+ records.
- Dashboard: one paginated SQL query (posts ⋈ lookup ⋈ nutrition [⋈ icl_translations]) + `COUNT(*)`; term cache primed for the page only. All filters are server-side SQL.
- Stats: one aggregate query, 10-minute transient, invalidated on writes.
- Import: streaming read, constant memory; SKU lookups in chunks of 500; renders deferred to Action Scheduler.
- Export / log download: keyset pagination (1 000 rows), streamed to `php://output`.
- Indexes: `UNIQUE(sku)`, `KEY(updated_at)`; log: `created_at`, `action`, `sku`, `run_id`.
- No frontend queries: product pages read the pre-rendered description.

## Upgrade / migration strategy

- `WNM_DB_VERSION` is compared with option `wnm_db_version` on every load (`Schema::maybe_upgrade()`), so updates via FTP/Composer/auto-update are handled without reactivation.
- Schema additions: edit the `CREATE TABLE` statement in `Schema::install()` and bump `WNM_DB_VERSION`. `dbDelta()` adds columns/indexes and never drops anything.
- Data transforms/renames: add `'x.y.z' => callable` to `Schema::migrations()`; each runs once when upgrading across that version.
- Rendered markup changes: bump the plugin version and click **Regenerate all** (or call `Plugin::instance()->sync->queue_all()` from a migration).
- Rollback: the tables are additive; an older plugin version ignores unknown columns.

## Known limitations

- Multisite: activate per site (each site has its own tables). Network-activate is not handled.
- Variations: nutrition is managed on the parent product SKU; variation SKUs are ignored.
- Products must have a SKU and a current lookup-table row.
- If another plugin bundles a different chillerlan/php-qrcode version, the first loaded wins; QR generation fails gracefully (link only) on incompatibility.
