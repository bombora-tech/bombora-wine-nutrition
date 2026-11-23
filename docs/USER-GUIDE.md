# User Guide

Menu: **WooCommerce › Wine Nutrition** (requires the `manage_woocommerce` capability).

## Dashboard

**Summary cards** – products with SKU, completed, missing nutrition, missing ingredients, missing QR, products without SKU (cannot be managed until they get one), and records whose SKU has no product in this shop (e.g. stored for a product that will be added later). Cached for 10 minutes and refreshed on every change.

"Completed" = all enabled standard nutrition fields filled **and** ingredients filled. QR is tracked separately because it is optional when the full information is shown on the product page.

**Table** – one row per product (with WPML: one row per translation group, i.e. the original). Search matches SKU and product name. Filters: category (includes sub-categories), status (complete / incomplete / no record), QR, ingredients. Click SKU, Product or Last updated to sort.

**Inline editing** – click **Edit**, fill the fields, **Save**. On save:
- the record is created/updated (empty field = value cleared),
- the nutrition block is rewritten immediately in the description of every product with that SKU, in every WPML language,
- plausibility warnings are shown (kJ/kcal ratio, sugars > carbohydrates, saturates > fat) but do not block saving.

Decimal comma and dot are both accepted. **Delete record** removes the record and the block from all descriptions.

### Ingredients and allergens
EU rules require allergens to be emphasised within the ingredient list. Either mark them yourself (`<strong>sulphites</strong>`) or list them in **Allergens** (comma-separated) – with "Automatically bold allergens" enabled, matching words in the ingredient list are bolded on render. Manual `<strong>`/`<b>` markup disables the automatic emphasis for that record.

## Frontend

The block is written into the product description between `<!-- WINE_NUTRITION_START -->` and `<!-- WINE_NUTRITION_END -->`, appended at the end the first time. You may move the whole marker block to another position in the description – later updates replace it in place. Do not edit content between the markers; it is overwritten. If only one marker is present (accidental deletion), the plugin refuses to touch that product and logs an error – fix the description and save the product.

Values are shown per 100 ml with the site language's decimal separator. With "EU rounding" on: energy to whole kJ/kcal; fat/carbohydrates/sugars/protein ≥10 g to 1 g, otherwise 0.1 g, <0.5 g shown as 0; saturates <0.1 g as 0; salt ≥1 g to 0.1 g, otherwise 0.01 g, ≤0.0125 g as 0. Stored values are never changed by rounding.

The QR image (SVG in `uploads/wine-nutrition-qr/`) links to the winery e-label URL, followed by a text link. Both keep working when the plugin is disabled.

Page caches: if product pages are cached, purge after large imports (or hook the `wnm_product_rendered` action – see TECHNICAL.md).

## Import / Export

**Export** – all records, or records changed since a date. Choose comma or semicolon (Excel CZ/DE opens semicolon files directly) and BOM (Excel needs it to detect UTF-8). Only nutrition data is exported, never products.

**Import**
1. Upload the CSV and select its encoding (Excel "CSV" on Czech/Slovak Windows = Windows-1250).
2. Review the preview: records in file, matching/missing products, new vs. existing records, rows with errors, ignored/unknown columns.
3. Options:
   - *Store records for SKUs without product* – useful for the master database; the block is rendered automatically when a product with that SKU is created or saved later.
   - *Empty cells clear stored values* – off by default (empty = keep).
4. **Import** runs in batches of 200 rows with a progress bar; **Cancel** deletes the uploaded file without changes.
5. Report: created / updated / unchanged / skipped / errors, plus a downloadable log for that run. Descriptions are updated in the background via Action Scheduler (**WooCommerce › Status › Scheduled Actions**, group `wine-nutrition-manager`).

Rows that do not change any value are counted as *unchanged* and do not touch `updated_at`, so "Changed since" exports contain only real changes.

## Settings

- **Standard fields** – disabled fields disappear from editor, CSV and frontend; stored values are kept and reappear when re-enabled.
- **Custom fields** – label + key (auto-generated, fixed after creation, CSV column `cf_<key>`). Values are free text, e.g. `2.1 g`.
- **Frontend labels** – base-language texts for the block.
- **Display & behaviour** – EU rounding, allergen emphasis, QR image + size, re-render on product save, log retention.

### Data retention

Deactivating or deleting the plugin keeps the nutrition table, logs, settings, labels, custom field definitions and QR images. Reinstall/reactivate and all records are back in the dashboard, editable. Permanent removal on delete only with `define( 'WNM_DELETE_DATA_ON_UNINSTALL', true );` in `wp-config.php`.
- **Regenerate all** – re-renders every description in the background. Triggered automatically when you save a setting that changes the output.

## Logs

Filter by action, level, SKU or import run ID; download as CSV. Retention is configurable (default 180 days).
