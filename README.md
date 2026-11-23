# Wine Nutrition Manager for WooCommerce

Centralized, SKU-based management of wine nutrition declarations, ingredients, allergens and e-label QR links. One record per SKU, shared by every language and transferable between shops via CSV. Output via the `[wine_nutrition]` shortcode (default, for Bricks/builder templates) and/or a self-contained HTML block written into the product description (stays visible if the plugin is disabled). See `docs/BRICKS.md`.

| | |
|---|---|
| Version | 1.1.0 (DB schema 1.0.0) |
| Requires | WordPress 6.0+, WooCommerce 8.0+ (HPOS compatible), PHP 8.1+ with `mbstring` |
| Optional | WPML + String Translation, `iconv` (Windows-1250/1252 CSV import) |
| Bundled | chillerlan/php-qrcode 5.0.3 (MIT/Apache-2.0), chillerlan/php-settings-container 3.2.1 (MIT) |

## Installation

1. Upload the `wine-nutrition-manager` folder to `wp-content/plugins/` (or upload the ZIP in **Plugins › Add New › Upload**).
2. Activate. Activation creates `{prefix}wine_nutrition` and `{prefix}wine_nutrition_log` with `dbDelta()`; existing tables and data are never overwritten.
3. **WooCommerce › Status › Tools › Regenerate product lookup tables** – run once if the shop was migrated/imported. SKU matching uses `wc_product_meta_lookup`.
4. **WooCommerce › Wine Nutrition › Settings** – choose fields, enter frontend labels in the default site language, save.
5. With WPML: translate the labels in **WPML › String Translation**, context `Wine Nutrition Manager`.
6. Import the first CSV (**Import / Export** tab) or edit records in the **Dashboard**.

Repeat on every shop. To sync shops: export on the master shop → import on the others (use "Changed since" for incremental syncs).

## Documentation

| File | Content |
|---|---|
| `docs/BRICKS.md` | Bricks setup (product meta snapshot), shortcode attributes, CSS variables |
| `docs/USER-GUIDE.md` | Day-to-day use: dashboard, inline editing, import/export, settings |
| `docs/CSV-FORMAT.md` | Column reference, empty-cell semantics, encodings, examples |
| `docs/TECHNICAL.md` | Architecture, request flows, hooks & filters, performance, upgrade/migration strategy |
| `docs/DATA-MODEL.md` | Tables, JSON format, status definitions, rendered markup contract |
| `docs/WPML.md` | WPML integration |
| `docs/SECURITY.md` | Security review summary |
| `sql/schema.sql` | Reference DDL |
| `sql/sample-import.csv` | Minimal import example |

## Changelog

- **1.6.0** – Ingredients and allergens translatable via WPML String Translation (context "Wine Nutrition Manager - Ingredients", deduplicated by text, source = default language). Existing records registered by the one-time background re-render after update. See docs/WPML.md.
- **1.5.3** – Fix: dashboard editor didn't show `<`/`>` after page reload (qualifiers not decoded in the list query).
- **1.5.2** – While the plugin is active, `{cf_wnm_nutrition_html}` is rendered live (settings/data changes show immediately, no dependency on background jobs); a stale stored snapshot is rewritten on first view.
- **1.5.1** – Setting "Negligible amounts" under EU rounding: show as `0 g` (default) or `< 0,5 g` / `< 0,1 g` / `< 0,01 g`.
- **1.5.0** – Numeric values accept `<`, `>`, `<=`, `>=` (`≤`, `≥`) prefixes in the editor and CSV (e.g. `< 3.2`). Stored in new column `qualifiers` (DB 1.1.0, added automatically). Shown as entered on the frontend (no EU rounding for bounds); plausibility warnings skip bounded values.
- **1.4.1** – Fix: dashboard stuck on "Loading…" when QR or Ingredients field is disabled (JS bound a listener to a filter that isn't rendered).
- **1.4.0** – Ingredients, Allergens and QR / e-label URL are toggleable in Settings › Standard fields (like nutrition fields). Disabled: hidden in editor, dashboard columns/filters/stats, ignored in CSV import/export, not rendered; stored values kept. "Complete" status ignores Ingredients when disabled.
- **1.3.0** – Settings: "Show e-label text link" (off = linked QR image only, with accessible alt text) and "Use the Allergens field" (off = hidden in editor, ignored in CSV import/export, not rendered; stored values kept). Shortcode `qr` attribute: `both|image|link|no`.
- **1.2.0** – Compact HTML snapshot stored in product meta `wnm_nutrition_html` on every render (works in Bricks via `{cf_wnm_nutrition_html}` with the plugin deactivated/deleted). QR image shown in compact output per the "Show QR image" setting. Compact markup uses only `wp_kses_post`-safe inline CSS with CSS-variable hooks. Settings: compact layout, allergens line; description block simplified to on/off. One-time background re-render after update. `wpml-config.xml` excludes the meta from WPML copying.
- **1.1.0** – `[wine_nutrition]` shortcode (table/inline layouts), output mode setting (shortcode / description / both; default shortcode), `wnm_has_nutrition()` for Bricks conditions, new label `elabel`, 30px default left padding. Data is never removed on uninstall unless `WNM_DELETE_DATA_ON_UNINSTALL` is defined (setting checkbox removed).
- **1.0.0** – Initial release.
