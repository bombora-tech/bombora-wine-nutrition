# Security Review Summary

Scope: all PHP entry points, SQL, file handling, output. Reference: OWASP Top 10, WordPress Plugin Security handbook.

| Area | Control | Location |
|---|---|---|
| Access control | Every AJAX and admin-post handler checks `current_user_can( Plugin::capability() )` (default `manage_woocommerce`) before work. No `wp_ajax_nopriv_*` handlers exist; no REST routes; no frontend endpoints. | `AjaxController::guard()`, `FormHandler::authorize()`, `AdminPage::render()` |
| CSRF | AJAX: `check_ajax_referer('wnm_admin')`. Forms/downloads: `wp_nonce_field()` / `wp_nonce_url()` + `check_admin_referer()` with a distinct action per form. | same |
| SQL injection | All values via `$wpdb->prepare()` or `$wpdb->insert/update/delete` with formats. Dynamic identifiers (column names, ORDER BY, direction) come only from internal whitelists (`FieldRegistry::NUMERIC_COLUMNS`, `match` statements). `LIKE` input escaped with `$wpdb->esc_like()`. | `NutritionRepository`, `Logger` |
| Input validation | Numbers: parsed, non-negative, bounded, rounded. Text: `sanitize_text_field` / `sanitize_textarea_field`. Ingredients: `wp_kses` with `strong b em i` only. URLs: `esc_url_raw` + `wp_http_validate_url`, http/https only (blocks `javascript:`/`data:`). Keys: `sanitize_key`. IDs: `absint`. | `RecordSanitizer`, `FormHandler`, `AjaxController` |
| Output escaping | Admin: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`; dashboard rows built with `textContent` (no `innerHTML` with data). Frontend block: every value escaped at render time; ingredients re-filtered with `wp_kses`. | `AdminPage`, `admin.js`, `BlockRenderer` |
| Stored XSS via description | Block is generated only from sanitized + escaped values; the direct DB write bypasses kses deliberately because the markup is ours and fully escaped. | `ProductSync::render_product()` |
| File upload | `is_uploaded_file`, size limit, extension whitelist, `wp_check_filetype_and_ext`, `finfo` MIME whitelist, NUL-byte check, UTF-8 validation, required `sku` header. Stored outside public paths' predictability: random 32-char name in `uploads/wnm-private/` with deny-all `.htaccess` + `index.php`; deleted after use; stale files purged daily. Token format validated (`^[A-Za-z0-9]{32}$`) and state bound to the uploading user. | `CsvImporter` |
| Path traversal | No user-controlled paths; file names are generated. | `CsvImporter`, `QrService` |
| CSV/formula injection | Export and log download prefix `= + - @ TAB CR` with `'`. | `CsvExporter::guard()` |
| Open redirect | `wp_safe_redirect` to fixed admin URLs. | `FormHandler::back()` |
| Information disclosure | DB errors logged, generic message returned to the client. Exports require capability + nonce. `X-Content-Type-Options: nosniff` on downloads. | `AjaxController`, `CsvExporter` |
| DoS | Pagination caps (≤200 rows/page), batch sizes, upload limit, Action Scheduler for bulk renders. | various |
| Supply chain | One vendored library (chillerlan/php-qrcode 5.0.3, MIT/Apache-2.0), decoder module removed (unused, reduces surface). | `vendor/` |

## Residual risks / recommendations

- On **nginx**, `.htaccess` is ignored. The upload directory is protected by an unguessable 32-character name and a lifetime of minutes; add `location ~* /wnm-private/ { deny all; }` for defence in depth.
- Users with `manage_woocommerce` can write limited HTML (`strong/b/em/i`) into descriptions – same trust level as editing product descriptions directly.
- The QR SVG is generated server-side from the URL; no user SVG is ever accepted.
- No critical findings identified in code review. Recommended before production: run PHPCS (`WordPress` + `PHPCompatibilityWP` rulesets) and test on a staging copy with the real catalogue.
