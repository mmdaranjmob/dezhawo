-- DEZHAWO initial schema: import into a NEW EMPTY database using phpMyAdmin.
-- MySQL 5.7+ / MariaDB 10.4+, InnoDB. No users or sample products are created.
-- This file is not an upgrade script. Do not re-import into an existing store.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE roles (
  id VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  protected TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id VARCHAR(64) NOT NULL,
  permission VARCHAR(64) NOT NULL,
  PRIMARY KEY (role_id, permission),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(11) NOT NULL,
  -- Reserved for compatibility with older installations; login uses mobile OTP.
  password_hash VARCHAR(255) NULL,
  role_id VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
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

CREATE TABLE products (
  id VARCHAR(64) NOT NULL,
  title VARCHAR(160) NOT NULL,
  category VARCHAR(20) NOT NULL,
  category_id VARCHAR(64) NULL,
  badge VARCHAR(50) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  art VARCHAR(20) NOT NULL DEFAULT 'case',
  image_url VARCHAR(255) NULL,
  price BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost BIGINT UNSIGNED NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_enabled_category (enabled, category),
  KEY idx_products_category_id (category_id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_variants (
  id VARCHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  name VARCHAR(80) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#333333',
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  price BIGINT UNSIGNED NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_variants_product (product_id, enabled),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shipping_methods (
  id VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  fee BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pay_on_delivery TINYINT(1) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  name VARCHAR(100) NOT NULL,
  value LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
  cart_key CHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  variant_id VARCHAR(64) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cart_key, variant_id),
  KEY idx_cart_product (product_id),
  KEY idx_cart_variant (variant_id),
  CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservations (
  id VARCHAR(64) NOT NULL,
  cart_key CHAR(64) NOT NULL,
  user_id VARCHAR(64) NULL,
  order_id VARCHAR(64) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reservation_cart (cart_key, status, expires_at),
  KEY idx_reservation_expiry (status, expires_at),
  KEY idx_reservation_user (user_id),
  KEY idx_reservation_order (order_id),
  CONSTRAINT fk_reservation_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservation_items (
  reservation_id VARCHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  variant_id VARCHAR(64) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price BIGINT UNSIGNED NOT NULL,
  unit_cost BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (reservation_id, variant_id),
  KEY idx_reservation_items_variant (variant_id),
  KEY idx_reservation_items_product (product_id),
  CONSTRAINT fk_reservation_items_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id VARCHAR(64) NOT NULL,
  number VARCHAR(40) NOT NULL,
  type VARCHAR(10) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  customer_name VARCHAR(100) NOT NULL,
  customer_phone VARCHAR(11) NULL,
  telegram_username VARCHAR(33) NULL,
  address_json TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment',
  payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
  subtotal BIGINT UNSIGNED NOT NULL,
  shipping_fee BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total BIGINT UNSIGNED NOT NULL,
  shipping_id VARCHAR(64) NULL,
  shipping_name VARCHAR(100) NOT NULL DEFAULT '',
  pay_on_delivery TINYINT(1) NOT NULL DEFAULT 0,
  payment VARCHAR(30) NOT NULL,
  tracking_code VARCHAR(100) NOT NULL DEFAULT '',
  stock_returned TINYINT(1) NOT NULL DEFAULT 0,
  reservation_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_number (number),
  KEY idx_orders_user_time (user_id, created_at),
  KEY idx_orders_status_time (status, created_at),
  KEY idx_orders_shipping (shipping_id),
  KEY idx_orders_reservation (reservation_id),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_shipping FOREIGN KEY (shipping_id) REFERENCES shipping_methods (id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product/variant names and prices are snapshots: preserve history after deletion.
CREATE TABLE order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id VARCHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  variant_id VARCHAR(64) NOT NULL,
  title VARCHAR(160) NOT NULL,
  variant_name VARCHAR(80) NOT NULL,
  category VARCHAR(20) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price BIGINT UNSIGNED NOT NULL,
  unit_cost BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_variant (variant_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id VARCHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  variant_id VARCHAR(64) NOT NULL,
  product_title VARCHAR(160) NOT NULL,
  variant_name VARCHAR(80) NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(300) NOT NULL,
  user_id VARCHAR(64) NULL,
  user_name VARCHAR(100) NOT NULL,
  order_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_movements_variant_time (variant_id, created_at),
  KEY idx_movements_created (created_at),
  KEY idx_movements_order (order_id),
  KEY idx_movements_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets (
  id VARCHAR(64) NOT NULL,
  number VARCHAR(40) NOT NULL,
  subject VARCHAR(150) NOT NULL,
  order_id VARCHAR(64) NULL,
  user_id VARCHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_number (number),
  KEY idx_tickets_user_status (user_id, status, updated_at),
  KEY idx_tickets_status (status, updated_at),
  KEY idx_tickets_order (order_id),
  CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_messages (
  id VARCHAR(64) NOT NULL,
  ticket_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  user_name VARCHAR(100) NOT NULL,
  text TEXT NOT NULL,
  staff TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_ticket_messages_ticket (ticket_id, created_at),
  KEY idx_ticket_messages_user (user_id),
  CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_challenges (
  id VARCHAR(64) NOT NULL,
  phone VARCHAR(11) NOT NULL,
  purpose VARCHAR(20) NOT NULL DEFAULT 'login',
  code_hash VARCHAR(255) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_phone_time (phone, purpose, created_at),
  KEY idx_otp_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  bucket CHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket),
  KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_attempts (
  id VARCHAR(64) NOT NULL,
  order_id VARCHAR(64) NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'zarinpal',
  authority VARCHAR(100) NULL,
  amount_rial BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'requesting',
  reference_id VARCHAR(100) NULL,
  metadata_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_authority (authority),
  KEY idx_payment_order (order_id),
  KEY idx_payment_status_time (status, created_at),
  CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NULL,
  user_name VARCHAR(100) NOT NULL,
  action VARCHAR(100) NOT NULL,
  target_id VARCHAR(64) NOT NULL,
  details_json TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_target (target_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (id, name, protected) VALUES
('owner', 'مدیر اصلی', 1),
('cashier', 'فروشنده حضوری', 0),
('support', 'پشتیبانی', 0),
('customer', 'مشتری', 1);

INSERT INTO role_permissions (role_id, permission) VALUES
('owner', 'products.view'), ('owner', 'products.edit'), ('owner', 'products.delete'),
('owner', 'categories.view'), ('owner', 'categories.edit'),
('owner', 'inventory.view'), ('owner', 'inventory.edit'), ('owner', 'pos.create'),
('owner', 'orders.view'), ('owner', 'orders.edit'), ('owner', 'finance.view'),
('owner', 'tickets.view'), ('owner', 'tickets.reply'), ('owner', 'shipping.edit'),
('owner', 'users.edit'), ('owner', 'roles.edit'), ('owner', 'appearance.edit'),
('owner', 'enamad.edit'), ('owner', 'settings.edit'),
('cashier', 'products.view'), ('cashier', 'inventory.view'), ('cashier', 'pos.create'), ('cashier', 'orders.view'),
('support', 'orders.view'), ('support', 'tickets.view'), ('support', 'tickets.reply');

INSERT INTO categories (id,name,parent_id,product_type,legacy_key,enabled,sort_order) VALUES
('case','قاب',NULL,'physical','case',1,0),
('glass','گلس',NULL,'physical','glass',1,1),
('cable','کابل',NULL,'physical','cable',1,2),
('charger','شارژر',NULL,'physical','charger',1,3),
('audio','هندزفری',NULL,'physical','audio',1,4),
('digital','اشتراک دیجیتال',NULL,'digital','digital',1,5);

-- Placeholder rates must be reviewed against actual store agreements before launch.
INSERT INTO shipping_methods (id, name, description, fee, pay_on_delivery, enabled) VALUES
('tipax', 'تیپاکس', 'روش ارسال پیشنهادی؛ هزینه و زمان تحویل را پیش از فروش تنظیم کنید.', 0, 1, 0),
('chapar', 'چاپار', 'روش ارسال پیشنهادی؛ هزینه و زمان تحویل را پیش از فروش تنظیم کنید.', 0, 1, 0);

INSERT INTO settings (name, value) VALUES
('categories_initialized', 'true'),
('brand', '"DEZHAWO"'),
('tagline', '"دژاوو؛ انتخابی که می‌ماند."'),
('notice', '"انتخاب‌های کوچک، برای روزهای بهتر"'),
('phone', '""'),
('address', '""'),
('instagram', '""'),
('headerLinks', '[{"label":"لوازم جانبی","target":"#catalog"},{"label":"دنیای دیجیتال","target":"#digital"},{"label":"داستان دژاوو","target":"#story"},{"label":"پشتیبانی","target":"#support"}]'),
('footerText', '"دژاوو؛ لوازم جانبی برای هر روز، انتخابی برای ماندن."'),
('footerStoreTitle', '"دنیای دژاوو"'),
('footerHelpTitle', '"کنار تو هستیم"'),
('footerLinks', '[{"label":"لوازم جانبی موبایل","target":"#catalog","group":"store"},{"label":"اشتراک‌های دیجیتال","target":"#digital","group":"store"},{"label":"دربارهٔ دژاوو","target":"#story","group":"store"},{"label":"علاقه‌مندی‌های من","target":"#favorites","group":"store"},{"label":"حساب کاربری و سفارش‌ها","target":"#account","group":"help"},{"label":"ثبت و پیگیری تیکت","target":"#support","group":"help"},{"label":"روش‌های ارسال","target":"#shipping-info","group":"help"},{"label":"راهنمای خرید و مرجوعی","target":"#policies","group":"help"}]'),
('enamadUrl', '""'),
('enamadEnabled', 'false'),
('payment_options', '{"zarinpalEnabled":true,"digipayEnabled":false,"bankTransferEnabled":false,"bankCard":"","bankIban":"","bankHolder":"","bankName":"","bankInstructions":""}');
