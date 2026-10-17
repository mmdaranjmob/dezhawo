-- DEZHAWO additional product photos, for an EXISTING store.
-- The owner can also use the in-site database upgrade button.
-- MySQL 5.7+ / MariaDB 10.4+. Safe to repeat; preserves products and inventory.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @dezhawo_gallery_upgrade = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'gallery_json'),
  'DO 0',
  'ALTER TABLE products ADD COLUMN gallery_json TEXT NULL AFTER image_url'
);
PREPARE dezhawo_gallery_upgrade_statement FROM @dezhawo_gallery_upgrade;
EXECUTE dezhawo_gallery_upgrade_statement;
DEALLOCATE PREPARE dezhawo_gallery_upgrade_statement;
SET @dezhawo_gallery_upgrade = NULL;
