-- DEZHAWO real product photos, for an EXISTING store.
-- Select the store database in phpMyAdmin after taking a private backup.
-- MySQL 5.7+ / MariaDB 10.4+. Safe to run again; no products or stock are removed.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @dezhawo_images_upgrade = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'image_url'),
  'DO 0',
  'ALTER TABLE products ADD COLUMN image_url VARCHAR(255) NULL AFTER art'
);
PREPARE dezhawo_images_upgrade_statement FROM @dezhawo_images_upgrade;
EXECUTE dezhawo_images_upgrade_statement;
DEALLOCATE PREPARE dezhawo_images_upgrade_statement;
SET @dezhawo_images_upgrade = NULL;
