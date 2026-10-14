-- OPTIONAL: import only after schema.sql into a test/empty store.
-- All sample products and variants are DISABLED, price=0, stock=0.
-- Review descriptions, actual prices, costs and stock before enabling anything.
-- Do not re-import: IDs are globally unique and intentional duplicate errors protect data.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
START TRANSACTION;

INSERT INTO products (id, title, category, badge, description, art, price, cost, enabled) VALUES
('sample_case_iphone13', 'قاب آیفون ۱۳', 'case', '', 'نمونهٔ غیرفعال: مشخصات و قیمت واقعی این کالا را پیش از انتشار ثبت کنید.', 'case', 0, 0, 0),
('sample_glass_iphone13', 'گلس آیفون ۱۳', 'glass', '', 'نمونهٔ غیرفعال: نوع پوشش و سازگاری واقعی کالا را ثبت کنید.', 'glass', 0, 0, 0),
('sample_cable_usbc', 'کابل USB-C', 'cable', '', 'نمونهٔ غیرفعال: طول کابل، توان و نوع درگاه را ثبت کنید.', 'cable', 0, 0, 0),
('sample_charger_usbc', 'شارژر USB-C', 'charger', '', 'نمونهٔ غیرفعال: برند و توان واقعی شارژر را ثبت کنید.', 'charger', 0, 0, 0),
('sample_audio', 'هندزفری', 'audio', '', 'نمونهٔ غیرفعال: برند و مشخصات واقعی هندزفری را ثبت کنید.', 'audio', 0, 0, 0),
('sample_telegram', 'تلگرام پریمیوم', 'digital', '', 'نمونهٔ غیرفعال: اشتراک هدیه با فعال‌سازی دستی؛ قیمت، زمان تحویل و شرایط تأمین را پیش از انتشار مشخص کنید. رمز یا کد ورود تلگرام مشتری را دریافت نکنید.', 'telegram', 0, 0, 0);

INSERT INTO product_variants (id, product_id, name, color, stock, price, enabled) VALUES
('sample_v_case13_black', 'sample_case_iphone13', 'مشکی', '#23252a', 0, NULL, 0),
('sample_v_case13_white', 'sample_case_iphone13', 'سفید', '#f0efe9', 0, NULL, 0),
('sample_v_glass13_clear', 'sample_glass_iphone13', 'شفاف', '#dceaf0', 0, NULL, 0),
('sample_v_cable_black', 'sample_cable_usbc', 'مشکی', '#23252a', 0, NULL, 0),
('sample_v_charger_white', 'sample_charger_usbc', 'سفید', '#f0efe9', 0, NULL, 0),
('sample_v_audio_black', 'sample_audio', 'مشکی', '#23252a', 0, NULL, 0),
('sample_v_telegram_3m', 'sample_telegram', '۳ ماهه', '#739cde', 0, NULL, 0),
('sample_v_telegram_6m', 'sample_telegram', '۶ ماهه', '#6d83c6', 0, NULL, 0),
('sample_v_telegram_12m', 'sample_telegram', '۱۲ ماهه', '#7c6eae', 0, NULL, 0);

COMMIT;
