-- ==========================================================================
-- Sufian Store — Grocery Management System (GMS)
-- SCHEMA ONLY (no data). Database Systems 2026-II, Group 05.
--
-- Base: attached database_schema.sql (ER diagram entities), plus two
-- implementation extensions used by the UI:
--   1) sales.cash_received  — POS records partial payment; due = final - cash
--   2) sale_details.unit_cost — purchase-price snapshot at sale time, so COGS
--      history never changes when a product's cost changes later
--
-- Usage:  CREATE DATABASE gms_db;  USE gms_db;  SOURCE schema.sql;
--         then import sample_data.sql for test records.
-- ==========================================================================

CREATE TABLE `users` (
  `user_id` int PRIMARY KEY AUTO_INCREMENT,
  `username` varchar(100) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `role` enum('admin','manager','staff') NOT NULL DEFAULT 'admin',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `categories` (
  `category_id` int PRIMARY KEY AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL UNIQUE,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `suppliers` (
  `supplier_id` int PRIMARY KEY AUTO_INCREMENT,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `customers` (
  `customer_id` int PRIMARY KEY AUTO_INCREMENT,
  `customer_name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `products` (
  `product_id` int PRIMARY KEY AUTO_INCREMENT,
  `product_name` varchar(200) NOT NULL,
  `category_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL UNIQUE,
  `purchase_price` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`purchase_price` >= 0),
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`selling_price` >= 0),
  `stock_quantity` int NOT NULL DEFAULT 0 CHECK (`stock_quantity` >= 0),
  `minimum_stock` int NOT NULL DEFAULT 5 CHECK (`minimum_stock` >= 0),
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_products_name` (`product_name`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_products_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `purchases` (
  `purchase_id` int PRIMARY KEY AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 CHECK (`total_amount` >= 0),
  `purchase_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE `purchase_details` (
  `purchase_detail_id` int PRIMARY KEY AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL CHECK (`quantity` > 0),
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`unit_price` >= 0),
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00 CHECK (`subtotal` >= 0),
  UNIQUE KEY `uq_purchase_product` (`purchase_id`, `product_id`),
  CONSTRAINT `fk_pdetails_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`purchase_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pdetails_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE `sales` (
  `sale_id` int PRIMARY KEY AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 CHECK (`total_amount` >= 0),
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`discount` >= 0),
  `final_amount` decimal(12,2) NOT NULL DEFAULT 0.00 CHECK (`final_amount` >= 0),
  `cash_received` decimal(12,2) NOT NULL DEFAULT 0.00 CHECK (`cash_received` >= 0),
  `sale_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `sale_details` (
  `sale_detail_id` int PRIMARY KEY AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` int NOT NULL CHECK (`quantity` > 0),
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 CHECK (`unit_price` >= 0),
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00 CHECK (`subtotal` >= 0),
  `unit_cost` decimal(10,2) DEFAULT NULL CHECK (`unit_cost` >= 0),
  UNIQUE KEY `uq_sale_product` (`sale_id`, `product_id`),
  CONSTRAINT `fk_sdetails_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sdetails_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE `expenses` (
  `expense_id` int PRIMARY KEY AUTO_INCREMENT,
  `expense_type` varchar(100) DEFAULT 'General',
  `description` text,
  `amount` decimal(12,2) NOT NULL CHECK (`amount` > 0),
  `expense_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `stock_logs` (
  `log_id` int PRIMARY KEY AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `change_type` enum('opening','purchase','sale','adjust') NOT NULL DEFAULT 'adjust',
  `quantity_changed` int NOT NULL CHECK (`quantity_changed` <> 0),
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_stocklogs_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE RESTRICT ON UPDATE CASCADE
);
