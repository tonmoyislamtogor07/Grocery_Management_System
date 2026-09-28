-- ==========================================================================
-- Sufian Store — Grocery Management System (GMS)
-- SAMPLE DATA ONLY (import schema.sql first). Database Systems 2026-II.
-- Every table has >= 3 records. Stock values are consistent with the
-- purchase (+) and sale (-) movements below; sale #3 is a partial-payment
-- (due) bill.  Usage:  USE gms_db;  SOURCE sample_data.sql;
-- Passwords: admin/admin123, manager/manager123, cashier/cashier123.
-- ==========================================================================

INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`) VALUES
('admin', '$2y$10$c4kvCW8Jv5TU3aRo5h24hefCCOU8Dd/o72DsM6YliTGpuvMs5.RW2', 'Store Owner', 'admin'),
('manager', '$2y$10$wZjq.N9HUmu11MRxWpegk.7f4/lUEDaJliBW7RftzL.Sniv.NXM62', 'Store Manager', 'manager'),
('cashier', '$2y$10$bwdS2urRYfTnLAXgDWGcpuX3eomYxM2g6C7gumP2lCjOwyYUsdsCK', 'Store Cashier', 'staff');

INSERT INTO `categories` (`category_id`, `category_name`, `description`) VALUES
(1, 'Rice & Grains', 'Rice, lentils, flour and breakfast cereals'),
(2, 'Cooking Oil', 'Soybean, mustard and sunflower oils'),
(3, 'Snacks & Biscuits', 'Biscuits, chanachur, noodles and dry snacks'),
(4, 'Dairy & Eggs', 'Milk, eggs, butter and dairy packs'),
(5, 'Soap & Toiletries', 'Soap, detergent, shampoo and hygiene items');

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `phone`, `address`) VALUES
(1, 'Khulna Wholesale Traders', 'Harun Sheikh', '01711223344', 'Boro Bazar, Khulna'),
(2, 'Green Dairy Distribution', 'Nasir Uddin', '01822334455', 'Gollamari, Khulna'),
(3, 'ACI Consumer Distribution', 'Sales Officer', '01933445566', 'Khulna Sadar');

INSERT INTO `customers` (`customer_id`, `customer_name`, `phone`, `address`) VALUES
(1, 'Karim Mia', '01555112233', 'Islamnagar, Gollamari'),
(2, 'Rahima Begum', '01666223344', 'Gollamari, Khulna'),
(3, 'Abdul Malek', '01777889900', 'Boro Bazar, Khulna');

-- Final stock = opening + purchases - sales (see movements below).
INSERT INTO `products` (`product_id`, `product_name`, `category_id`, `supplier_id`, `purchase_price`, `selling_price`, `stock_quantity`, `minimum_stock`, `expiry_date`) VALUES
(1, 'Miniket Rice (25kg)', 1, 1, 1750.00, 1850.00, 14, 5, '2027-01-15'),
(2, 'Rupchanda Soybean Oil (5L)', 2, 1, 880.00, 940.00, 3, 6, '2027-06-01'),
(3, 'Olympic Energy Biscuit', 3, 3, 12.00, 15.00, 120, 30, '2026-09-20'),
(4, 'Fresh Milk (1L Pack)', 4, 2, 85.00, 95.00, 26, 10, '2026-09-10'),
(5, 'Lux Soap (100g)', 5, 3, 38.00, 45.00, 60, 15, '2028-01-01'),
(6, 'Nazirshail Rice (25kg)', 1, 1, 1550.00, 1650.00, 4, 5, '2027-02-10'),
(7, 'ACI Pure Salt (1kg)', 1, 3, 32.00, 40.00, 72, 20, '2028-05-01');

INSERT INTO `purchases` (`purchase_id`, `supplier_id`, `total_amount`, `purchase_date`) VALUES
(1, 1, 29900.00, '2026-08-20 10:00:00'),
(2, 2, 1700.00, '2026-08-25 11:00:00'),
(3, 3, 1720.00, '2026-09-01 10:30:00');

INSERT INTO `purchase_details` (`purchase_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES
(1, 1, 10, 1750.00, 17500.00),
(1, 6, 8, 1550.00, 12400.00),
(2, 4, 20, 85.00, 1700.00),
(3, 7, 30, 32.00, 960.00),
(3, 5, 20, 38.00, 760.00);

INSERT INTO `sales` (`sale_id`, `customer_id`, `total_amount`, `discount`, `final_amount`, `cash_received`, `sale_date`) VALUES
(1, 1, 165.00, 0.00, 165.00, 200.00, '2026-09-04 12:00:00'),
(2, NULL, 230.00, 0.00, 230.00, 250.00, '2026-09-05 13:00:00'),
(3, 3, 6870.00, 0.00, 6870.00, 5000.00, '2026-09-06 18:30:00');

INSERT INTO `sale_details` (`sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`, `unit_cost`) VALUES
(1, 3, 5, 15.00, 75.00, 12.00),
(1, 5, 2, 45.00, 90.00, 38.00),
(2, 4, 2, 95.00, 190.00, 85.00),
(2, 7, 1, 40.00, 40.00, 32.00),
(3, 4, 2, 95.00, 190.00, 85.00),
(3, 7, 2, 40.00, 80.00, 32.00),
(3, 6, 4, 1650.00, 6600.00, 1550.00);

INSERT INTO `expenses` (`expense_id`, `expense_type`, `description`, `amount`, `expense_date`) VALUES
(1, 'Rent', 'Monthly shop rent', 12000.00, '2026-09-01'),
(2, 'Electricity', 'August electricity bill', 2400.00, '2026-09-02'),
(3, 'Transport', 'Goods transport from wholesaler', 800.00, '2026-09-03');

INSERT INTO `stock_logs` (`product_id`, `change_type`, `quantity_changed`) VALUES
(1, 'purchase', 10),
(6, 'purchase', 8),
(4, 'purchase', 20),
(7, 'purchase', 30),
(5, 'purchase', 20),
(3, 'sale', -5),
(5, 'sale', -2),
(4, 'sale', -2),
(7, 'sale', -1),
(4, 'sale', -2),
(7, 'sale', -2),
(6, 'sale', -4);
