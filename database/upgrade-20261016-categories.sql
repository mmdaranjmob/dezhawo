-- DEZHAWO hierarchical categories, for an EXISTING store.
-- Select the store database in phpMyAdmin before running this file.
-- MySQL 5.7+ / MariaDB 10.4+. Safe to run again; existing catalog/stock is preserved.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  parent_id VARCHAR(64) NULL,
  product_type VARCHAR(20) NOT NULL DEFAULT 'physical',
  legacy_key VARCHAR(20) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_legacy (legacy_key),
  KEY idx_categories_parent_order (parent_id, sort_order),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed only during the first completed upgrade. A later rerun must preserve
-- category roots which the merchant deliberately deleted after installation.
INSERT IGNORE INTO categories (id,name,parent_id,product_type,legacy_key,enabled,sort_order)
SELECT seed.id,seed.name,NULL,seed.product_type,seed.id,1,seed.sort_order
FROM (
  SELECT 'case' AS id,'قاب' AS name,'physical' AS product_type,0 AS sort_order
  UNION ALL SELECT 'glass','گلس','physical',1
  UNION ALL SELECT 'cable','کابل','physical',2
  UNION ALL SELECT 'charger','شارژر','physical',3
  UNION ALL SELECT 'audio','هندزفری','physical',4
  UNION ALL SELECT 'digital','اشتراک دیجیتال','digital',5
) AS seed
WHERE NOT EXISTS(SELECT 1 FROM settings WHERE name='categories_initialized' AND value='true');

SET @dezhawo_category_column = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='category_id'),
  'DO 0',
  'ALTER TABLE products ADD COLUMN category_id VARCHAR(64) NULL AFTER category'
);
PREPARE dezhawo_category_statement FROM @dezhawo_category_column;
EXECUTE dezhawo_category_statement;
DEALLOCATE PREPARE dezhawo_category_statement;

UPDATE products p JOIN categories c ON c.legacy_key=p.category
SET p.category_id=c.id WHERE p.category_id IS NULL;

SET @dezhawo_category_index = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME='idx_products_category_id'),
  'DO 0',
  'ALTER TABLE products ADD KEY idx_products_category_id (category_id)'
);
PREPARE dezhawo_category_statement FROM @dezhawo_category_index;
EXECUTE dezhawo_category_statement;
DEALLOCATE PREPARE dezhawo_category_statement;

SET @dezhawo_category_constraint = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='products' AND CONSTRAINT_NAME='fk_products_category'),
  'DO 0',
  'ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT'
);
PREPARE dezhawo_category_statement FROM @dezhawo_category_constraint;
EXECUTE dezhawo_category_statement;
DEALLOCATE PREPARE dezhawo_category_statement;

INSERT IGNORE INTO role_permissions (role_id,permission)
SELECT id,'categories.view' FROM roles WHERE id='owner';
INSERT IGNORE INTO role_permissions (role_id,permission)
SELECT id,'categories.edit' FROM roles WHERE id='owner';

SET @dezhawo_category_column=NULL;
SET @dezhawo_category_index=NULL;
SET @dezhawo_category_constraint=NULL;

-- Written only after the schema, product mapping and owner grants succeeded.
INSERT INTO settings (name,value,updated_at)
VALUES ('categories_initialized','true',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=UTC_TIMESTAMP();
