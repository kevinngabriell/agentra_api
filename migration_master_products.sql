-- Master Products table
-- Defines all insurance product types per company, with default commission rates
-- and optional policy number prefix codes for auto-detection.

CREATE TABLE IF NOT EXISTS `master_products` (
  `product_id`      varchar(50)    NOT NULL,
  `company_id`      varchar(50)    NOT NULL,
  `product_code`    varchar(50)    NOT NULL COMMENT 'Unique code per company, used as product_type in policies (e.g. fire, aep, kecelakaan)',
  `product_name`    varchar(150)   NOT NULL COMMENT 'Display name e.g. Tanggung Gugat Pihak Ketiga',
  `commission_rate` decimal(5,2)   NOT NULL COMMENT 'Default commission % for this product (0-100)',
  `policy_prefixes` varchar(255)   DEFAULT NULL COMMENT 'Comma-separated policy number prefixes e.g. 01,08,88,61,62',
  `is_active`       tinyint(1)     NOT NULL DEFAULT 1,
  `created_by`      varchar(50)    NOT NULL,
  `created_at`      datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by`      varchar(50)    DEFAULT NULL,
  `updated_at`      datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `uq_master_product_code` (`company_id`, `product_code`),
  KEY `idx_master_products_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Seed data (replace 'your_company_id' with the actual company_id)
-- ─────────────────────────────────────────────────────────────────────────────

-- INSERT INTO `master_products` (product_id, company_id, product_code, product_name, commission_rate, policy_prefixes, created_by) VALUES
--   ('prod_001', 'your_company_id', 'kebakaran',   'Kebakaran',                       15.00, '01,08,88,61,62', 'system'),
--   ('prod_002', 'your_company_id', 'kendaraan',   'Kendaraan Bermotor',              25.00, '02',             'system'),
--   ('prod_003', 'your_company_id', 'aep',         'Tanggung Gugat Pihak Ketiga',     30.00, NULL,             'system'),
--   ('prod_004', 'your_company_id', 'kecelakaan',  'Kecelakaan Diri',                 20.00, NULL,             'system');
