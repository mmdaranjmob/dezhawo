-- DEZHAWO payment methods upgrade for an EXISTING store.
-- Export a private backup first. Select the existing store DB in phpMyAdmin.
-- MySQL 5.7+ / MariaDB 10.4+. Safe to run again; preserves all existing data.
-- No gateway credentials or usable bank-account details are included.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @dezhawo_payment_upgrade = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_attempts' AND COLUMN_NAME = 'provider'),
  'DO 0',
  'ALTER TABLE payment_attempts ADD COLUMN provider VARCHAR(30) NOT NULL DEFAULT ''zarinpal'' AFTER order_id'
);
PREPARE dezhawo_payment_upgrade_statement FROM @dezhawo_payment_upgrade;
EXECUTE dezhawo_payment_upgrade_statement;
DEALLOCATE PREPARE dezhawo_payment_upgrade_statement;

SET @dezhawo_payment_upgrade = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_attempts' AND COLUMN_NAME = 'metadata_json'),
  'DO 0',
  'ALTER TABLE payment_attempts ADD COLUMN metadata_json TEXT NULL AFTER reference_id'
);
PREPARE dezhawo_payment_upgrade_statement FROM @dezhawo_payment_upgrade;
EXECUTE dezhawo_payment_upgrade_statement;
DEALLOCATE PREPARE dezhawo_payment_upgrade_statement;

INSERT IGNORE INTO settings (name,value,updated_at) VALUES
('payment_options','{"zarinpalEnabled":true,"digipayEnabled":false,"bankTransferEnabled":false,"bankCard":"","bankIban":"","bankHolder":"","bankName":"","bankInstructions":""}',UTC_TIMESTAMP());

SET @dezhawo_payment_upgrade = NULL;
