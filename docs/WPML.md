# WPML Integration

**Principle:** one nutrition record per SKU, shared by all languages. Only labels are translated.

## How it works

1. Labels (Settings › Frontend labels) and custom field labels are registered with `wpml_register_single_string` in context **`Wine Nutrition Manager`** (names `label_<key>`, `custom_<key>`). Registration runs on `admin_init` only when the texts change (hash in `wnm_labels_hash`).
2. Translate them in **WPML › String Translation**. Any language added to WPML later works automatically.
3. WooCommerce Multilingual keeps the same SKU on all translations, so `wc_product_meta_lookup` returns every translated product for a SKU.
4. When rendering, the plugin resolves each product's language (`wpml_post_language_details`) and translates labels with `wpml_translate_single_string( $value, 'Wine Nutrition Manager', $name, $lang )`. Each translation's description receives a block in its own language with the shared values.
5. Number format follows the language: decimal comma for cs, de, fr, nl, ru, sk…; dot for en (filter `wnm_decimal_separator`).
6. The dashboard lists only originals (`icl_translations.source_language_code IS NULL`), so each wine appears once.

## Workflow

- Enter labels in the **default** site language in Settings (for zGruzie.cz: Czech, e.g. *Výživové údaje*, *na 100 ml*, *Energetická hodnota*, *Tuky*, *z toho nasycené mastné kyseliny*, *Sacharidy*, *z toho cukry*, *Bílkoviny*, *Sůl*, *Složení*, *Alergeny*).
- Translate in String Translation.
- Click **Settings › Regenerate all** after translating strings (string translations are not a plugin setting, so they do not trigger an automatic re-render).
- New product translations get the block automatically on save ("Re-render on product save").

## Notes

- If WCML is configured to copy the description from the original when translating, the copied (original-language) block is replaced with the correct language on the next save of the translation, or by **Regenerate all**.
- Without WPML, labels are used as entered and numbers follow the site locale.

## Translating ingredients and allergens (since 1.6.0)

Entered values stay one record per SKU (source = site default language). Translations come from WPML String Translation.

- Context: `Wine Nutrition Manager - Ingredients`
- Name: `ingredients_<md5 of text>` / `allergens_<md5 of text>`. Identical text on many SKUs = one string, translated once.
- Source language: WPML default language (`wpml_default_language`).
- Registered when a record is saved in the dashboard (immediately) and whenever products are rendered in the background (import, Regenerate all, plugin update).
- Untranslated → original text is shown.
- Manual `<strong>` in translations is kept (allowed tags: strong, b, em, i).
- Changing the source text creates a new string (new hash); the old one stays in String Translation and can be deleted there.
- Frontend reads translations live while the plugin is active; the stored snapshot refreshes on the next view. With the description block enabled, run Settings › Regenerate all after translating.
- Toggle: Settings › Display & behaviour › "Translate ingredients and allergens via WPML String Translation".
