# Frontend output in Bricks (since 1.2.0)

## Two outputs, same markup

| Output | Works with plugin deactivated/deleted | Use |
|---|---|---|
| Product meta `wnm_nutrition_html` | **Yes** | Recommended for Bricks templates |
| Shortcode `[wine_nutrition]` | No (prints raw text) | Ad-hoc placement, per-placement attributes |
| Description block (optional, Settings) | Yes | Themes without a builder |

On every save/import/delete/settings change, the plugin renders the compact HTML and stores it on each product (each WPML translation gets its own language labels). The HTML is self-contained: inline styles only, all of which pass `wp_kses_post`, QR image as a static SVG file in uploads.

## Live vs stored

Plugin active: reading `wnm_nutrition_html` returns the current rendering (filter on `get_post_metadata`), and the stored value is rewritten if it differs. Plugin deactivated: Bricks reads the last stored value.

## Setup (single product template)

1. Under the "Additional information" attribute table, add a **Rich Text** element (or Basic Text) with content:
   ```
   {cf_wnm_nutrition_html}
   ```
2. Element › Conditions › Dynamic data `{cf_wnm_nutrition_html}` › `!=` › (empty) — hides the element for products without data. This uses only Bricks + core meta, so it keeps working without the plugin.
3. Remove any old `[wine_nutrition]` Shortcode element.

After updating to 1.2.0 the snapshot is generated for all stored SKUs in the background (WooCommerce › Status › Scheduled Actions, group `wine-nutrition-manager`). Settings › Regenerate all does the same manually.

## Settings that affect the compact output

Compact layout (table / inline), "Show allergens line", "Show e-label text link", "Show QR image next to the e-label link", QR size, EU rounding, allergen emphasis, labels (WPML String Translation). Changing them re-renders all products automatically.

## Styling

Inline styles read CSS variables, so override from Bricks (page/template CSS or the element's custom CSS) without `!important`:

```css
.wnm-compact {
  --wnm-padding-left: 30px;   /* default 30px */
  --wnm-label-width: 200px;   /* default 40% */
  --wnm-gap: .15em;           /* row padding */
  --wnm-sub-indent: .9em;     /* "of which" rows */
}
```

Other properties are inline, so override them with `!important` (e.g. `.wnm-compact__title { font-size: 1.1em !important; }`).

Classes: `.wnm-compact` (+ `--table` / `--inline`), `__title`, `__per`, `__table`, `__line`, `__item`, `__label`, `__text`, `__elabel`, `__link`, `__qr`, `__label-text`.

## Shortcode (plugin active only)

`[wine_nutrition sku="" id="" layout="table|inline" title="yes|no" tag="h2–h6|p|div" ingredients="yes|no" allergens="yes|no" qr="both|image|link|no" class=""]` — defaults come from Settings. Returns '' when there is no record.

## Hooks

- `wnm_snapshot_html` (filter) – `$html, $product_id, $record` before saving the meta
- `wnm_shortcode_html` (filter) – `$html, $record, $atts`
- `wnm_product_rendered` (action) – after meta and/or description changed; purge page cache here
- `wnm_has_nutrition( $product_id = 0 ): int` – plugin active only

## Caveats

- If Bricks escapes the field instead of rendering HTML in your version, use a Rich Text element (not Heading/Text Link). Test once on staging, including with the plugin deactivated.
- The meta key has no underscore prefix so Bricks lists it under custom fields. Don't edit it manually — it's overwritten on the next render.
