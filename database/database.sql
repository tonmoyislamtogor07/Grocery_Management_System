-- ==========================================================================
-- Sufian Store — Grocery Management System (GMS)
-- MySQL database: gms_db
-- Import: phpMyAdmin > create DB "gms_db" > Import this file
--     or: mysql -u root -p -e "CREATE DATABASE gms_db" && mysql -u root -p gms_db < database.sql
-- Default login: admin / admin123
-- ==========================================================================

CREATE DATABASE IF NOT EXISTS gms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gms_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS v_product_live;
DROP TABLE IF EXISTS sale_payment;
DROP TABLE IF EXISTS sale_lot;
DROP TABLE IF EXISTS stock_lot;
DROP TABLE IF EXISTS sale_detail;
DROP TABLE IF EXISTS sale;
DROP TABLE IF EXISTS purchase_detail;
DROP TABLE IF EXISTS purchase;
DROP TABLE IF EXISTS product_supplier;
DROP TABLE IF EXISTS product;
DROP TABLE IF EXISTS customer;
DROP TABLE IF EXISTS supplier;
DROP TABLE IF EXISTS category;
DROP TABLE IF EXISTS expense;
DROP TABLE IF EXISTS app_user;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `app_user` (
  `user_id` int PRIMARY KEY AUTO_INCREMENT,
  `username` varchar(100) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `role` enum('admin','manager','staff') NOT NULL DEFAULT 'admin',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `category` (
  `category_id` int PRIMARY KEY AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL UNIQUE,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `supplier` (
  `supplier_id` int PRIMARY KEY AUTO_INCREMENT,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `customer` (
  `customer_id` int PRIMARY KEY AUTO_INCREMENT,
  `customer_name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text,
  `area` varchar(100) DEFAULT NULL COMMENT 'Locality for area-wise sale (optional)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

-- product holds ONLY independent facts. Stock, average cost and expiry are
-- derived from stock_lot (see v_product_live) — never stored here.
CREATE TABLE `product` (
  `product_id` int PRIMARY KEY AUTO_INCREMENT,
  `product_name` varchar(200) NOT NULL UNIQUE,
  `category_id` int DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL UNIQUE,
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`selling_price` >= 0),
  `minimum_stock` int NOT NULL DEFAULT 5 CHECK (`minimum_stock` >= 0),
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_products_name` (`product_name`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `category` (`category_id`) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Many-to-many: one product can come from multiple supplier (Option B).
-- A purchase itself still has ONE supplier; product_supplier is the
-- allowed-sources list. Auto-linked on purchase (INSERT IGNORE).
CREATE TABLE `product_supplier` (
  `product_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  PRIMARY KEY (`product_id`, `supplier_id`),
  CONSTRAINT `fk_psup_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_psup_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`supplier_id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `purchase` (
  `purchase_id` int PRIMARY KEY AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `purchase_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`supplier_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- One purchase line = one fact (bill snapshot). Line total and expiry live
-- in stock_lot; the bill total is SUM(quantity*unit_price) at read time.
CREATE TABLE `purchase_detail` (
  `purchase_detail_id` int PRIMARY KEY AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL CHECK (`quantity` > 0),
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`unit_price` >= 0),
  KEY `idx_pdetails_purchase` (`purchase_id`),
  CONSTRAINT `fk_pdetails_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchase` (`purchase_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pdetails_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Real batch inventory (FIFO/FEFO): every inbound movement is a lot with its
-- own supplier, cost and expiry. product.stock_quantity = SUM(lots.qty_left).
-- One purchase line = one lot, so the same product may repeat in one bill
-- (different expiry) — hence no UNIQUE(purchase_id, product_id) here.
-- stock_lot keeps ONLY lot facts. The supplier of a purchase lot is read
-- via purchase_id -> purchase.supplier_id (no copy stored here).
CREATE TABLE `stock_lot` (
  `lot_id` int PRIMARY KEY AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `purchase_id` int DEFAULT NULL,
  `purchase_detail_id` int DEFAULT NULL,
  `qty_bought` int NOT NULL CHECK (`qty_bought` > 0),
  `qty_left` int NOT NULL CHECK (`qty_left` >= 0),
  `unit_cost` decimal(10,2) NOT NULL CHECK (`unit_cost` >= 0),
  `expiry_date` date DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_lots_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_lots_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchase` (`purchase_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lots_pdetail` FOREIGN KEY (`purchase_detail_id`) REFERENCES `purchase_detail` (`purchase_detail_id`) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Which lots were consumed by each sale line (audit + exact COGS).
-- (Created after sale_detail below, which it references.)

-- sale keeps ONLY independent facts. Totals are derived:
-- total = SUM(sale_detail.quantity*unit_price), final = total - discount,
-- paid = SUM(sale_payment.amount) (the first cash IS a payment row).
CREATE TABLE `sale` (
  `sale_id` int PRIMARY KEY AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`discount` >= 0),
  `sale_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Due collections: every cash movement on a bill, INCLUDING the first cash
-- taken at sale time. paid = SUM(amount); due = final - paid.
CREATE TABLE `sale_payment` (
  `payment_id` int PRIMARY KEY AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL CHECK (`amount` > 0),
  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_spay_sale` FOREIGN KEY (`sale_id`) REFERENCES `sale` (`sale_id`) ON DELETE CASCADE ON UPDATE CASCADE
);

-- One sale line = one fact. Line total and line cost are derived
-- (sale_lot -> stock_lot) at read time.
CREATE TABLE `sale_detail` (
  `sale_detail_id` int PRIMARY KEY AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL CHECK (`quantity` > 0),
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`unit_price` >= 0),
  UNIQUE KEY `uq_sale_product` (`sale_id`, `product_id`),
  CONSTRAINT `fk_sdetails_sale` FOREIGN KEY (`sale_id`) REFERENCES `sale` (`sale_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sdetails_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Which lots were consumed by each sale line (audit + exact COGS).
CREATE TABLE `sale_lot` (
  `sale_detail_id` int NOT NULL,
  `lot_id` int NOT NULL,
  `quantity` int NOT NULL CHECK (`quantity` > 0),
  PRIMARY KEY (`sale_detail_id`, `lot_id`),
  CONSTRAINT `fk_sl_sdetail` FOREIGN KEY (`sale_detail_id`) REFERENCES `sale_detail` (`sale_detail_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sl_lot` FOREIGN KEY (`lot_id`) REFERENCES `stock_lot` (`lot_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE `expense` (
  `expense_id` int PRIMARY KEY AUTO_INCREMENT,
  `expense_type` varchar(100) DEFAULT 'General',
  `description` text,
  `amount` decimal(12,2) NOT NULL CHECK (`amount` > 0),
  `expense_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

-- Derived product state (stock, average cost, earliest live expiry).
-- Read-only: everything here is computed from stock_lot, nothing stored.
CREATE VIEW `v_product_live` AS
SELECT p.product_id,
  COALESCE(SUM(l.qty_left), 0) AS stock,
  CASE WHEN COALESCE(SUM(l.qty_left), 0) > 0
    THEN ROUND(SUM(l.qty_left * l.unit_cost) / SUM(l.qty_left), 2)
    ELSE 0 END AS avg_cost,
  MIN(CASE WHEN l.qty_left > 0 THEN l.expiry_date END) AS expiry
FROM product p LEFT JOIN stock_lot l ON l.product_id = p.product_id
GROUP BY p.product_id;

-- ---------------- Seed data ----------------

INSERT INTO `app_user` (`username`, `password_hash`, `full_name`, `role`) VALUES
('admin', '$2y$10$c4kvCW8Jv5TU3aRo5h24hefCCOU8Dd/o72DsM6YliTGpuvMs5.RW2', 'Store Owner', 'admin'),
('manager', '$2y$10$wZjq.N9HUmu11MRxWpegk.7f4/lUEDaJliBW7RftzL.Sniv.NXM62', 'Store Manager', 'manager'),
('cashier', '$2y$10$bwdS2urRYfTnLAXgDWGcpuX3eomYxM2g6C7gumP2lCjOwyYUsdsCK', 'Store Cashier', 'staff');

INSERT INTO `category` (`category_id`, `category_name`, `description`) VALUES
(1, 'Rice & Grains', NULL),
(2, 'Cooking Oil', NULL),
(3, 'Snacks & Biscuits', NULL),
(4, 'Dairy & Eggs', NULL),
(5, 'Soap & Toiletries', NULL);

INSERT INTO `supplier` (`supplier_id`, `supplier_name`, `phone`, `address`) VALUES
(1, 'Khulna Wholesale Traders', '01711223344', 'Boro Bazar, Khulna'),
(2, 'Green Dairy Distribution', '01822334455', 'Gollamari, Khulna'),
(3, 'ACI Consumer Distribution', '01933445566', 'Khulna Sadar');

INSERT INTO `customer` (`customer_id`, `customer_name`, `phone`) VALUES
(1, 'Karim Mia', '01555112233'),
(2, 'Rahima Begum', '01666223344');

INSERT INTO `product` (`product_id`, `product_name`, `category_id`, `selling_price`, `minimum_stock`) VALUES
(1, 'Miniket Rice (25kg)', 1, 1850.00, 5),
(2, 'Rupchanda Soybean Oil (5L)', 2, 940.00, 6),
(3, 'Olympic Energy Biscuit', 3, 15.00, 30),
(4, 'Fresh Milk (1L Pack)', 4, 95.00, 10),
(5, 'Lux Soap (100g)', 5, 45.00, 15),
(6, 'Nazirshail Rice (25kg)', 1, 1650.00, 5),
(7, 'ACI Pure Salt (1kg)', 1, 40.00, 20);

-- Allowed sources migrated from the old product.supplier_id column.
INSERT INTO `product_supplier` (`product_id`, `supplier_id`) VALUES
(1, 1),
(2, 1),
(3, 3),
(4, 2),
(5, 3),
(6, 1),
(7, 3);

INSERT INTO `purchase` (`purchase_id`, `supplier_id`, `purchase_date`) VALUES
(1, 1, '2026-08-20 10:00:00');

INSERT INTO `purchase_detail` (`purchase_id`, `product_id`, `quantity`, `unit_price`) VALUES
(1, 1, 10, 1750.00),
(1, 6, 8, 1550.00);

-- Seed lots: qty_left = current stock; qty_bought includes what seed sale consumed.
-- received_at predates real bills so FEFO order stays sensible.
INSERT INTO `stock_lot` (`product_id`, `purchase_id`, `qty_bought`, `qty_left`, `unit_cost`, `expiry_date`, `received_at`) VALUES
(1, NULL, 14, 14, 1750.00, '2027-01-15', '2026-08-01 09:00:00'),
(2, NULL, 3, 3, 880.00, '2027-06-01', '2026-08-01 09:00:00'),
(3, NULL, 125, 120, 12.00, '2026-09-20', '2026-08-01 09:00:00'),
(4, NULL, 10, 8, 85.00, '2026-09-10', '2026-08-01 09:00:00'),
(5, NULL, 62, 60, 38.00, '2028-01-01', '2026-08-01 09:00:00'),
(7, NULL, 46, 45, 32.00, '2028-05-01', '2026-08-01 09:00:00');

INSERT INTO `sale` (`sale_id`, `customer_id`, `discount`, `sale_date`) VALUES
(1, 1, 0.00, '2026-09-04 12:00:00'),
(2, NULL, 0.00, '2026-09-05 13:00:00');

INSERT INTO `sale_detail` (`sale_detail_id`, `sale_id`, `product_id`, `quantity`, `unit_price`) VALUES
(1, 1, 3, 5, 15.00),
(2, 1, 5, 2, 45.00),
(3, 2, 4, 2, 95.00),
(4, 2, 7, 1, 40.00);

-- Which seed lots the seed sale consumed (lots above are already reduced).
INSERT INTO `sale_lot` (`sale_detail_id`, `lot_id`, `quantity`) VALUES
(1, 3, 5),
(2, 5, 2),
(3, 4, 2),
(4, 6, 1);

-- Cash taken at sale time IS a payment row (paid = SUM(amount)).
-- Amounts never exceed their bill totals (the API rejects overpayment).
INSERT INTO `sale_payment` (`sale_id`, `amount`, `paid_at`) VALUES
(1, 100.00, '2026-09-04 12:00:00'),
(2, 230.00, '2026-09-05 13:00:00');

INSERT INTO `expense` (`expense_id`, `expense_type`, `description`, `amount`, `expense_date`) VALUES
(1, 'Rent', 'Monthly shop rent', 12000.00, '2026-09-01'),
(2, 'Electricity', 'August electricity bill', 2400.00, '2026-09-02'),
(3, 'Transport', 'Goods transport from wholesaler', 800.00, '2026-09-03');
