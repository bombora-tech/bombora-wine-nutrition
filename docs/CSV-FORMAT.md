# CSV Format

## Columns

| Column | Type | Notes |
|---|---|---|
| `sku` | text, **required** | Matched case-insensitively against WooCommerce SKUs. Max 191 chars. |
| `energy_kj`, `energy_kcal` | number | Only when the *Energy* field is enabled. |
| `fat`, `saturated_fat`, `carbohydrates`, `sugars`, `protein`, `salt` | number (g / 100 ml) | Only enabled fields are imported/exported. Optional prefix `<`, `>`, `<=`, `>=`, `≤`, `≥` (e.g. `<3.2`, `< 0,5`); exported as `<3.2`. Applies to energy columns too. |
| `ingredients` | text | `<strong>`, `<b>`, `<em>`, `<i>` allowed; everything else stripped. |
| `allergens` | text | Comma-separated, used for automatic emphasis. Ignored on import and omitted from export when disabled in Settings › Standard fields (same for `ingredients` and `qr_url`). |
| `qr_url` | URL | `http`/`https` only. |
| `cf_<key>` | text (≤255) | One column per enabled custom field, e.g. `cf_polyphenols`. |
| `custom_fields` | JSON | Import only, optional. `{"polyphenols":"210 mg"}` or `[{"label":"Polyphenols","value":"210 mg"}]` (labels are matched to defined fields). A `cf_` column wins over JSON for the same field. |
| `updated_at` | datetime | Export only (site timezone). Ignored on import. |

Header names are case-insensitive. Column order is free. Unknown columns and columns of disabled fields are listed in the preview and ignored. Custom fields must be defined in Settings before import.

## Values

- Numbers: `3.5`, `3,5`, ` 3.5 ` are accepted; negative or non-numeric values reject the row. Stored with 2 decimals.
- Empty cell: value unchanged (default) or cleared (import option).
- Duplicate SKUs in one file: rows are applied in order, the last one wins.
- Export protects against spreadsheet formula injection: text starting with `= + - @` is prefixed with `'`. The prefix is removed again on import.

## Format

- Delimiter auto-detected on import: `,` `;` or TAB. Quotes: `"` (RFC 4180, `""` inside quoted values).
- Encodings: UTF-8 (with or without BOM), Windows-1250, Windows-1252.
- Max size 20 MB (filter `wnm_import_max_bytes`).

## Example

```csv
sku,energy_kj,energy_kcal,fat,saturated_fat,carbohydrates,sugars,protein,salt,ingredients,allergens,qr_url,cf_polyphenols
KHAREBA-SAPERAVI-750,322,77,0,0,2.6,0.8,0.1,0.01,"grapes, preservative: sulphites",sulphites,https://elabel.example.com/kh-saperavi,210 mg
```
