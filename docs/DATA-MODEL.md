# Data Model

## `{prefix}wine_nutrition`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | no | PK |
| `sku` | VARCHAR(191) | no | UNIQUE. Case-insensitive under the default `_ci` collation. |
| `energy_kj`, `energy_kcal` | DECIMAL(10,2) | yes | per 100 ml |
| `fat`, `saturated_fat`, `carbohydrates`, `sugars`, `protein`, `salt` | DECIMAL(10,2) | yes | g per 100 ml. NULL = not provided, 0 = zero. |
| `qualifiers` | VARCHAR(255) | yes | JSON `{column: op}` with op `<`, `>`, `≤`, `≥` for values entered as bounds ("< 3.2"). Numeric column keeps the number. Added in DB 1.1.0 (dbDelta). |
| `ingredients` | LONGTEXT | yes | Limited inline HTML (`strong b em i`) |
| `allergens` | LONGTEXT | yes | Plain text, comma-separated |
| `qr_url` | VARCHAR(2048) | yes | http/https |
| `custom_fields` | LONGTEXT (JSON) | yes | `{"polyphenols":"210 mg","fiber":"0.2 g"}`; keys = custom field keys; sorted; empty values removed |
| `source` | VARCHAR(255) | yes | `admin` or `import:<file>` |
| `updated_by` | BIGINT UNSIGNED | yes | WP user ID of last change (per shop) |
| `updated_at` | DATETIME | yes | GMT, last real change. KEY. |
| `created_at` | DATETIME | yes | GMT |

## `{prefix}wine_nutrition_log`

`id`, `created_at` (GMT), `user_id` (0 = system/cron), `action` (`import|export|create|update|delete|render|settings|error`), `level` (`info|warning|error`), `sku`, `run_id` (groups an import), `details`.

## Options

| Option | Content |
|---|---|
| `wnm_db_version` | Installed schema version |
| `wnm_enabled_fields` | Enabled standard groups: `energy, fat, saturated_fat, carbohydrates, sugars, protein, salt` |
| `wnm_custom_fields` | `[{key, label, enabled}]` |
| `wnm_labels` | Base-language frontend labels |
| `wnm_settings` | Display/behaviour settings |
| `wnm_labels_hash` | WPML registration fingerprint |

## Status definitions

- **Nutrition complete**: record exists and every column of every enabled standard field is NOT NULL.
- **Complete**: nutrition complete + ingredients non-empty.
- **Has QR**: `qr_url` non-empty.

## Relationship to products

```
wine_nutrition.sku  ──(1:N)──  wc_product_meta_lookup.sku  ──  wp_posts.ID (post_type=product, every WPML language)
```

No foreign keys; a record may exist without a product (stored for later) and a product may exist without a record.

## Rendered markup contract

```html
<!-- WINE_NUTRITION_START -->
<div class="wnm-nutrition" style="…">
  <h3 class="wnm-nutrition__title">Nutrition Information <span>(per 100 ml)</span></h3>
  <table class="wnm-nutrition__table" aria-label="…"><tbody>
    <tr><th scope="row">Energy</th><td>322 kJ / 77 kcal</td></tr> …
  </tbody></table>
  <p class="wnm-nutrition__ingredients"><strong>Ingredients:</strong> grapes, preservative: <strong>sulphites</strong></p>
  <p class="wnm-nutrition__allergens"><strong>Allergens:</strong> sulphites</p>
  <p class="wnm-nutrition__qr"><a …><img src="…/uploads/wine-nutrition-qr/<md5(url)>.svg" alt=""></a><a href="…">Scan QR code</a></p>
</div>
<!-- WINE_NUTRITION_END -->
```

Markers are matched tolerantly (`<!--\s*WINE_NUTRITION_START\s*-->`). Duplicated blocks are collapsed to one. CSS classes are stable for theme overrides.
