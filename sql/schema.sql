-- Wine Nutrition Manager – reference schema (DB version 1.0.0)
-- The plugin creates/updates these tables itself via dbDelta() on activation and on version change.
-- Replace `wp_` with your $table_prefix. Charset/collation must match wp_wc_product_meta_lookup
-- (the plugin uses $wpdb->get_charset_collate(), same as WooCommerce).

CREATE TABLE IF NOT EXISTS wp_wine_nutrition (
  id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  sku           VARCHAR(191)  NOT NULL,
  energy_kj     DECIMAL(10,2) DEFAULT NULL,
  energy_kcal   DECIMAL(10,2) DEFAULT NULL,
  fat           DECIMAL(10,2) DEFAULT NULL,
  saturated_fat DECIMAL(10,2) DEFAULT NULL,
  carbohydrates DECIMAL(10,2) DEFAULT NULL,
  sugars        DECIMAL(10,2) DEFAULT NULL,
  protein       DECIMAL(10,2) DEFAULT NULL,
  salt          DECIMAL(10,2) DEFAULT NULL,
  qualifiers    VARCHAR(255) DEFAULT NULL,  -- JSON {column: "<"|">"|"≤"|"≥"}, since DB 1.1.0
  ingredients   LONGTEXT NULL,
  allergens     LONGTEXT NULL,
  qr_url        VARCHAR(2048) DEFAULT NULL,
  custom_fields LONGTEXT NULL,          -- JSON object {"field_key":"value"}
  source        VARCHAR(255) DEFAULT NULL, -- 'admin' | 'import:<file name>'
  updated_by    BIGINT(20) UNSIGNED DEFAULT NULL,
  updated_at    DATETIME DEFAULT NULL,  -- GMT; changes only when data actually changes
  created_at    DATETIME DEFAULT NULL,  -- GMT
  PRIMARY KEY (id),
  UNIQUE KEY sku (sku),
  KEY updated_at (updated_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS wp_wine_nutrition_log (
  id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,        -- GMT
  user_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  action     VARCHAR(32)  NOT NULL,    -- import|export|create|update|delete|render|settings|error
  level      VARCHAR(16)  NOT NULL DEFAULT 'info',
  sku        VARCHAR(191) NOT NULL DEFAULT '',
  run_id     VARCHAR(64)  NOT NULL DEFAULT '',  -- groups all rows of one import
  details    TEXT NULL,
  PRIMARY KEY (id),
  KEY created_at (created_at),
  KEY action (action),
  KEY sku (sku),
  KEY run_id (run_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
