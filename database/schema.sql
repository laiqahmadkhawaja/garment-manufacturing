-- Garment Manufacturing & Production Management System
-- Relational Database Schema

CREATE DATABASE IF NOT EXISTS `garment_erp` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `garment_erp`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Users & Roles
-- --------------------------------------------------------
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

CREATE TABLE `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `display_name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(80) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `module` VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `role_permissions` (
    `role_id` INT NOT NULL,
    `permission_id` INT NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role_id` INT NOT NULL,
    `phone` VARCHAR(30) NULL,
    `email` VARCHAR(100) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Factory Master Data
-- --------------------------------------------------------
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `units`;
CREATE TABLE `units` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `code` VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `warehouses`;
CREATE TABLE `warehouses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `location` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `colors`;
CREATE TABLE `colors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `hex_code` VARCHAR(10) NULL DEFAULT '#cccccc'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `sizes`;
CREATE TABLE `sizes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `code` VARCHAR(20) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Products & Designs
-- --------------------------------------------------------
DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE `product_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `workflow_templates`;
CREATE TABLE `workflow_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `product_type` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `category_id` INT NULL,
    `product_type` VARCHAR(60) NOT NULL,
    `picture` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `attributes_json` TEXT NULL,
    `default_workflow_template_id` INT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `product_categories`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`default_workflow_template_id`) REFERENCES `workflow_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `designs`;
CREATE TABLE `designs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `design_number` VARCHAR(60) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `product_id` INT NOT NULL,
    `picture` VARCHAR(255) NULL,
    `specifications` TEXT NULL,
    `workflow_template_id` INT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_prod_design` (`product_id`, `design_number`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`workflow_template_id`) REFERENCES `workflow_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `design_elements`;
CREATE TABLE `design_elements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `design_id` INT NOT NULL,
    `element_name` VARCHAR(100) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `notes` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Operations & Rates
-- --------------------------------------------------------
DROP TABLE IF EXISTS `operations`;
CREATE TABLE `operations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(40) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `department_id` INT NULL,
    `sequence` INT NOT NULL DEFAULT 10,
    `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
    `default_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `rate_unit` VARCHAR(30) NOT NULL DEFAULT 'Per piece',
    `quantity_tracking` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `workflow_template_steps`;
CREATE TABLE `workflow_template_steps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT NOT NULL,
    `operation_id` INT NOT NULL,
    `sequence` INT NOT NULL DEFAULT 1,
    `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (`template_id`) REFERENCES `workflow_templates`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Workers & Labour
-- --------------------------------------------------------
DROP TABLE IF EXISTS `workers`;
CREATE TABLE `workers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(40) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `phone` VARCHAR(40) NULL,
    `address` TEXT NULL,
    `cnic` VARCHAR(30) NULL,
    `worker_type` VARCHAR(50) NOT NULL DEFAULT 'Piece Rate',
    `department_id` INT NULL,
    `default_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `joining_date` DATE NULL,
    `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `worker_operations`;
CREATE TABLE `worker_operations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `worker_id` INT NOT NULL,
    `operation_id` INT NOT NULL,
    UNIQUE KEY `uq_worker_op` (`worker_id`, `operation_id`),
    FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `operation_rates`;
CREATE TABLE `operation_rates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `operation_id` INT NOT NULL,
    `worker_id` INT NULL,
    `product_id` INT NULL,
    `design_id` INT NULL,
    `size_id` INT NULL,
    `rate` DECIMAL(12,2) NOT NULL,
    `effective_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Lots & Production Tracking
-- --------------------------------------------------------
DROP TABLE IF EXISTS `lots`;
CREATE TABLE `lots` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lot_number` VARCHAR(50) NOT NULL UNIQUE,
    `lot_date` DATE NOT NULL,
    `product_id` INT NOT NULL,
    `design_id` INT NULL,
    `supervisor_name` VARCHAR(100) NULL,
    `expected_date` DATE NULL,
    `instructions` TEXT NULL,
    `total_pieces` INT NOT NULL DEFAULT 0,
    `total_colors` INT NOT NULL DEFAULT 0,
    `total_sizes` INT NOT NULL DEFAULT 0,
    `status` ENUM('Draft', 'In Production', 'Production Completed', 'Partially Packed', 'Ready Stock', 'Partially Dispatched', 'Dispatched', 'Closed') NOT NULL DEFAULT 'Draft',
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `lot_variants`;
CREATE TABLE `lot_variants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lot_id` INT NOT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_lot_var` (`lot_id`, `color_id`, `size_id`),
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `lot_workflow_steps`;
CREATE TABLE `lot_workflow_steps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lot_id` INT NOT NULL,
    `operation_id` INT NOT NULL,
    `sequence` INT NOT NULL DEFAULT 1,
    `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('Pending', 'In Progress', 'Completed') NOT NULL DEFAULT 'Pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_lot_step` (`lot_id`, `operation_id`),
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `production_entries`;
CREATE TABLE `production_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entry_number` VARCHAR(50) NOT NULL UNIQUE,
    `entry_date` DATE NOT NULL,
    `lot_id` INT NOT NULL,
    `operation_id` INT NOT NULL,
    `worker_id` INT NOT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `qty_received` INT NOT NULL DEFAULT 0,
    `good_qty` INT NOT NULL DEFAULT 0,
    `rejected_qty` INT NOT NULL DEFAULT 0,
    `rework_qty` INT NOT NULL DEFAULT 0,
    `rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `labour_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`),
    FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`),
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `production_rejections`;
CREATE TABLE `production_rejections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `production_entry_id` INT NULL,
    `lot_id` INT NOT NULL,
    `operation_id` INT NOT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `reason` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`production_entry_id`) REFERENCES `production_entries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`),
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `production_rework`;
CREATE TABLE `production_rework` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `production_entry_id` INT NULL,
    `lot_id` INT NOT NULL,
    `operation_id` INT NOT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `notes` VARCHAR(255) NULL,
    `status` ENUM('Pending', 'In Progress', 'Resolved') NOT NULL DEFAULT 'Pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`production_entry_id`) REFERENCES `production_entries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`),
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `lot_status_history`;
CREATE TABLE `lot_status_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `lot_id` INT NOT NULL,
    `old_status` VARCHAR(50) NULL,
    `new_status` VARCHAR(50) NOT NULL,
    `remarks` TEXT NULL,
    `changed_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Labour Accounts & Financial Ledgers
-- --------------------------------------------------------
DROP TABLE IF EXISTS `payment_methods`;
CREATE TABLE `payment_methods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(60) NOT NULL UNIQUE,
    `code` VARCHAR(30) NOT NULL UNIQUE,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `cash_bank_accounts`;
CREATE TABLE `cash_bank_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `account_name` VARCHAR(100) NOT NULL,
    `account_number` VARCHAR(50) NULL,
    `bank_name` VARCHAR(100) NULL,
    `current_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `worker_ledger`;
CREATE TABLE `worker_ledger` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `worker_id` INT NOT NULL,
    `transaction_date` DATE NOT NULL,
    `transaction_type` ENUM('Labour', 'Payment', 'Advance', 'Opening Balance', 'Reversal') NOT NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` INT NULL,
    `lot_id` INT NULL,
    `operation_id` INT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `running_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `description` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`operation_id`) REFERENCES `operations`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `worker_payments`;
CREATE TABLE `worker_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payment_number` VARCHAR(50) NOT NULL UNIQUE,
    `worker_id` INT NOT NULL,
    `payment_date` DATE NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `payment_type` ENUM('Payment', 'Advance') NOT NULL DEFAULT 'Payment',
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
    `account_id` INT NULL,
    `reference_no` VARCHAR(60) NULL,
    `notes` TEXT NULL,
    `is_reversed` TINYINT(1) NOT NULL DEFAULT 0,
    `reversed_at` DATETIME NULL,
    `reversed_by` INT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`worker_id`) REFERENCES `workers`(`id`),
    FOREIGN KEY (`account_id`) REFERENCES `cash_bank_accounts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`reversed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Raw Materials
-- --------------------------------------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(40) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(40) NULL,
    `email` VARCHAR(100) NULL,
    `address` TEXT NULL,
    `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `materials`;
CREATE TABLE `materials` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(40) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `category` VARCHAR(60) NOT NULL,
    `unit_id` INT NOT NULL,
    `min_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `opening_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `cost_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `current_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `supplier_id` INT NULL,
    `storage_location` VARCHAR(100) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`),
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `material_purchases`;
CREATE TABLE `material_purchases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_number` VARCHAR(50) NOT NULL UNIQUE,
    `purchase_date` DATE NOT NULL,
    `supplier_id` INT NOT NULL,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `invoice_no` VARCHAR(60) NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `material_purchase_items`;
CREATE TABLE `material_purchase_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `total_price` DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (`purchase_id`) REFERENCES `material_purchases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `material_issues`;
CREATE TABLE `material_issues` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `issue_number` VARCHAR(50) NOT NULL UNIQUE,
    `issue_date` DATE NOT NULL,
    `lot_id` INT NOT NULL,
    `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `material_issue_items`;
CREATE TABLE `material_issue_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `issue_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `total_cost` DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (`issue_id`) REFERENCES `material_issues`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `material_returns`;
CREATE TABLE `material_returns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_number` VARCHAR(50) NOT NULL UNIQUE,
    `return_date` DATE NOT NULL,
    `lot_id` INT NOT NULL,
    `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `material_return_items`;
CREATE TABLE `material_return_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `total_cost` DECIMAL(14,2) NOT NULL,
    FOREIGN KEY (`return_id`) REFERENCES `material_returns`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `bom_headers`;
CREATE TABLE `bom_headers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `design_id` INT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `bom_items`;
CREATE TABLE `bom_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bom_id` INT NOT NULL,
    `material_id` INT NOT NULL,
    `quantity_per_piece` DECIMAL(12,4) NOT NULL,
    FOREIGN KEY (`bom_id`) REFERENCES `bom_headers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Packing & Finished Goods Stock
-- --------------------------------------------------------
DROP TABLE IF EXISTS `packing_entries`;
CREATE TABLE `packing_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `packing_number` VARCHAR(50) NOT NULL UNIQUE,
    `packing_date` DATE NOT NULL,
    `lot_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `design_id` INT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `packed_qty` INT NOT NULL,
    `packing_worker_id` INT NULL,
    `packing_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `labour_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `package_type` VARCHAR(50) NULL DEFAULT 'Box',
    `package_no` VARCHAR(60) NULL,
    `remarks` TEXT NULL,
    `warehouse_id` INT NOT NULL DEFAULT 1,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`),
    FOREIGN KEY (`packing_worker_id`) REFERENCES `workers`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `finished_goods_stock`;
CREATE TABLE `finished_goods_stock` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `design_id` INT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `lot_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `current_stock` INT NOT NULL DEFAULT 0,
    `dispatched_stock` INT NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_fg_item` (`product_id`, `design_id`, `color_id`, `size_id`, `lot_id`, `warehouse_id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`),
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Customers & Dispatches
-- --------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(40) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(40) NULL,
    `email` VARCHAR(100) NULL,
    `address` TEXT NULL,
    `city` VARCHAR(80) NULL,
    `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit_limit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dispatches`;
CREATE TABLE `dispatches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dispatch_number` VARCHAR(50) NOT NULL UNIQUE,
    `dispatch_date` DATE NOT NULL,
    `customer_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `challan_no` VARCHAR(60) NULL,
    `vehicle_details` VARCHAR(120) NULL,
    `total_pieces` INT NOT NULL DEFAULT 0,
    `total_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dispatch_items`;
CREATE TABLE `dispatch_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dispatch_id` INT NOT NULL,
    `lot_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `design_id` INT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dispatch_returns`;
CREATE TABLE `dispatch_returns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_number` VARCHAR(50) NOT NULL UNIQUE,
    `return_date` DATE NOT NULL,
    `dispatch_id` INT NOT NULL,
    `customer_id` INT NOT NULL,
    `return_type` ENUM('Saleable', 'Damaged', 'Rework') NOT NULL DEFAULT 'Saleable',
    `remarks` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches`(`id`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dispatch_return_items`;
CREATE TABLE `dispatch_return_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_id` INT NOT NULL,
    `lot_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `design_id` INT NULL,
    `color_id` INT NOT NULL,
    `size_id` INT NOT NULL,
    `quantity` INT NOT NULL,
    `unit_rate` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (`return_id`) REFERENCES `dispatch_returns`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`),
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `customer_ledger`;
CREATE TABLE `customer_ledger` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `transaction_date` DATE NOT NULL,
    `transaction_type` ENUM('Dispatch', 'Payment', 'Return', 'Opening Balance') NOT NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` INT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `running_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `description` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Central Inventory Transactions
-- --------------------------------------------------------
DROP TABLE IF EXISTS `inventory_transactions`;
CREATE TABLE `inventory_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_date` DATE NOT NULL,
    `item_type` ENUM('Material', 'Finished Goods') NOT NULL,
    `material_id` INT NULL,
    `product_id` INT NULL,
    `design_id` INT NULL,
    `color_id` INT NULL,
    `size_id` INT NULL,
    `lot_id` INT NULL,
    `warehouse_id` INT NOT NULL,
    `transaction_type` ENUM('Purchase', 'Material Issue', 'Material Return', 'Packing', 'Dispatch', 'Dispatch Return', 'Adjustment') NOT NULL,
    `quantity_in` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `quantity_out` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` INT NULL,
    `remarks` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `stock_adjustments`;
CREATE TABLE `stock_adjustments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `adjustment_number` VARCHAR(50) NOT NULL UNIQUE,
    `adjustment_date` DATE NOT NULL,
    `item_type` ENUM('Material', 'Finished Goods') NOT NULL,
    `material_id` INT NULL,
    `product_id` INT NULL,
    `design_id` INT NULL,
    `color_id` INT NULL,
    `size_id` INT NULL,
    `lot_id` INT NULL,
    `warehouse_id` INT NOT NULL,
    `adjustment_type` ENUM('Add', 'Deduct') NOT NULL,
    `quantity` DECIMAL(12,2) NOT NULL,
    `reason` TEXT NOT NULL,
    `approved_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`material_id`) REFERENCES `materials`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`color_id`) REFERENCES `colors`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`size_id`) REFERENCES `sizes`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`),
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- General Expenses & System Configuration
-- --------------------------------------------------------
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `expense_date` DATE NOT NULL,
    `category_id` INT NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
    `account_id` INT NULL,
    `reference_no` VARCHAR(60) NULL,
    `lot_id` INT NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`),
    FOREIGN KEY (`account_id`) REFERENCES `cash_bank_accounts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`lot_id`) REFERENCES `lots`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'General'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(80) NOT NULL,
    `entity_type` VARCHAR(80) NOT NULL,
    `entity_id` INT NULL,
    `old_values` TEXT NULL,
    `new_values` TEXT NULL,
    `ip_address` VARCHAR(50) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `role_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `link` VARCHAR(255) NULL,
    `alert_type` ENUM('info', 'warning', 'danger', 'success') NOT NULL DEFAULT 'info',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Initial Master Seed Data
-- --------------------------------------------------------

-- Roles
INSERT INTO `roles` (`id`, `name`, `display_name`, `description`) VALUES
(1, 'super_admin', 'Super Admin', 'Full system access and master configuration'),
(2, 'factory_manager', 'Factory Manager', 'Oversees factory lots, production, and approvals'),
(3, 'production_supervisor', 'Production Supervisor', 'Manages lots, assigns workers, production entry'),
(4, 'cutting_master', 'Cutting Master', 'Manages cutting entries and shortages'),
(5, 'operator', 'Department Operator', 'Work entry for assigned operations'),
(6, 'packing_staff', 'Packing Staff', 'Packing entries and finished stock handoff'),
(7, 'store_keeper', 'Store Keeper', 'Raw material purchases, issues and returns'),
(8, 'accountant', 'Accountant', 'Worker payments, accounts, expenses and financial reports'),
(9, 'dispatch_staff', 'Dispatch Staff', 'Dispatches and delivery challans'),
(10, 'viewer', 'Viewer', 'Read-only access to dashboards and reports');

-- Admin User: admin / admin123
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role_id`, `phone`, `email`, `is_active`) VALUES
(1, 'admin', '$2y$10$wN1vG64c.q3rB4qO103WqOGc8XgJ6eC3bK6Vv9M5l0g8fQ7w8B4eW', 'System Administrator', 1, '0300-1234567', 'admin@garmentfactory.com', 1);

-- System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
('company_name', 'Ali Garment Manufacturing Ltd.', 'Company'),
('company_phone', '+92 300 1234567', 'Company'),
('company_email', 'info@aligarments.com', 'Company'),
('company_address', 'Industrial Area, Sector 4-B, Karachi / Faisalabad, Pakistan', 'Company'),
('currency_symbol', 'Rs.', 'General'),
('tax_number', 'NTN: 1234567-8', 'Company'),
('low_stock_threshold', '10', 'Inventory'),
('allow_negative_stock', '0', 'Inventory'),
('lot_prefix', 'LOT-', 'Production'),
('dispatch_prefix', 'DIS-', 'Dispatch'),
('payment_prefix', 'PAY-', 'Finance');

-- Departments
INSERT INTO `departments` (`id`, `code`, `name`, `description`, `is_active`) VALUES
(1, 'DEP-CUT', 'Cutting Department', 'Fabric spreading, patterning and precision cutting', 1),
(2, 'DEP-TAIL', 'Tailoring / Assembly', 'Parts joining, front/back/collar shaping', 1),
(3, 'DEP-STITCH', 'Stitching Department', 'Full sewing and machine assembling', 1),
(4, 'DEP-SAFE', 'Safety & Overlock', 'Edge safety, overlocking and interlock sewing', 1),
(5, 'DEP-FINISH', 'Finishing & Buttoning', 'Buttons, buttonholes (kaj), trims, threads', 1),
(6, 'DEP-PRESS', 'Pressing & Ironing', 'Steam press, crease setting, ironing', 1),
(7, 'DEP-PACK', 'Packing & Quality Warehouse', 'Quality check, polybagging, boxing, barcoding', 1);

-- Operations
INSERT INTO `operations` (`id`, `code`, `name`, `department_id`, `sequence`, `is_mandatory`, `default_rate`, `rate_unit`, `is_active`) VALUES
(1, 'OP-CUT', 'Cutting', 1, 10, 1, 3.00, 'Per piece', 1),
(2, 'OP-TAIL', 'Tailoring', 2, 20, 1, 5.00, 'Per piece', 1),
(3, 'OP-SAFE', 'Safety (Overlock)', 4, 30, 1, 3.00, 'Per piece', 1),
(4, 'OP-STITCH', 'Stitching', 3, 40, 1, 12.00, 'Per piece', 1),
(5, 'OP-NECK', 'Neck Finishing', 3, 50, 0, 4.00, 'Per piece', 1),
(6, 'OP-BTN', 'Buttons', 5, 60, 0, 6.00, 'Per piece', 1),
(7, 'OP-KAJ', 'Kaj (Buttonhole)', 5, 70, 0, 4.00, 'Per piece', 1),
(8, 'OP-PRESS', 'Press / Ironing', 6, 80, 1, 3.00, 'Per piece', 1),
(9, 'OP-PACK', 'Packing', 7, 90, 1, 2.00, 'Per piece', 1);

-- Workflow Templates
INSERT INTO `workflow_templates` (`id`, `name`, `product_type`, `description`, `is_active`) VALUES
(1, 'Coat Standard Workflow', 'Coat', 'Full suit/coat workflow with cutting, tailoring, safety, stitching, buttons, kaj, press, packing', 1),
(2, 'V-Neck Sweater Workflow', 'Sweater', 'Knitted sweater workflow without buttons/kaj: cutting, stitching, neck finishing, safety, press, packing', 1),
(3, 'Jacket Standard Workflow', 'Jacket', 'Zip/button jacket workflow', 1);

-- Workflow Steps
INSERT INTO `workflow_template_steps` (`template_id`, `operation_id`, `sequence`, `is_mandatory`) VALUES
-- Coat Template (all standard ops)
(1, 1, 1, 1),
(1, 2, 2, 1),
(1, 4, 3, 1),
(1, 3, 4, 1),
(1, 6, 5, 1),
(1, 7, 6, 1),
(1, 8, 7, 1),
(1, 9, 8, 1),
-- Sweater Template (No buttons/kaj)
(2, 1, 1, 1),
(2, 4, 2, 1),
(2, 5, 3, 1),
(2, 3, 4, 1),
(2, 8, 5, 1),
(2, 9, 6, 1),
-- Jacket Template
(3, 1, 1, 1),
(3, 2, 2, 1),
(3, 4, 3, 1),
(3, 3, 4, 1),
(3, 8, 5, 1),
(3, 9, 6, 1);

-- Units
INSERT INTO `units` (`id`, `name`, `code`) VALUES
(1, 'Kilogram', 'KG'),
(2, 'Gram', 'GM'),
(3, 'Meter', 'MTR'),
(4, 'Yard', 'YD'),
(5, 'Piece', 'PCS'),
(6, 'Roll', 'ROLL'),
(7, 'Dozen', 'DZN'),
(8, 'Packet', 'PKT'),
(9, 'Box', 'BOX');

-- Warehouses
INSERT INTO `warehouses` (`id`, `code`, `name`, `location`, `is_active`) VALUES
(1, 'WH-MAIN', 'Main Factory Warehouse', 'Floor 1, Sector A', 1),
(2, 'WH-RAW', 'Raw Material Storage', 'Basement Store', 1),
(3, 'WH-FIN', 'Finished Goods Dispatch Warehouse', 'Ground Floor Loading Bay', 1);

-- Colors
INSERT INTO `colors` (`id`, `name`, `hex_code`) VALUES
(1, 'Black', '#1a1a1a'),
(2, 'Blue', '#2563eb'),
(3, 'White', '#ffffff'),
(4, 'Grey', '#6b7280'),
(5, 'Navy', '#0f172a'),
(6, 'Maroon', '#881337'),
(7, 'Beige', '#d4c5b9'),
(8, 'Olive Green', '#3f6212');

-- Sizes
INSERT INTO `sizes` (`id`, `name`, `code`, `sort_order`) VALUES
(1, 'Small', 'S', 1),
(2, 'Medium', 'M', 2),
(3, 'Large', 'L', 3),
(4, 'Extra Large', 'XL', 4),
(5, 'Double XL', '2XL', 5);

-- Product Categories
INSERT INTO `product_categories` (`id`, `code`, `name`, `description`, `is_active`) VALUES
(1, 'CAT-SW', 'Knitwear & Sweaters', 'Woolen and acrylic cardigans, pullovers, v-necks', 1),
(2, 'CAT-CT', 'Coats & Blazers', 'Formal, wool and casual winter coats', 1),
(3, 'CAT-JK', 'Jackets & Outerwear', 'Zipped and buttoned winter jackets', 1);

-- Products
INSERT INTO `products` (`id`, `code`, `name`, `category_id`, `product_type`, `picture`, `description`, `default_workflow_template_id`, `is_active`) VALUES
(1, 'PRD-SW-01', 'Ladies Sweater', 1, 'Sweater', '', 'Fine knit ladies v-neck and round neck winter sweater', 2, 1),
(2, 'PRD-CT-01', 'Mens Wool Coat', 2, 'Coat', '', 'Heavy wool tailored winter coat with pockets and buttons', 1, 1),
(3, 'PRD-JK-01', 'Puffer Jacket', 3, 'Jacket', '', 'Quilted polyester windbreaker jacket', 3, 1);

-- Designs
INSERT INTO `designs` (`id`, `design_number`, `name`, `product_id`, `picture`, `specifications`, `workflow_template_id`, `is_active`) VALUES
(1, '901', 'Ladies Cable V-Neck 901', 1, '', 'Ribbed cuffs and hem, premium finish', 2, 1),
(2, '902', 'Classic Executive Coat 902', 2, '', 'Double breasted, 6 horn buttons, deep pockets', 1, 1),
(3, '903', 'Urban Active Jacket 903', 3, '', 'Lightweight thermal fill, inner lining', 3, 1);

-- Design Elements (Structural Pattern Components)
INSERT INTO `design_elements` (`id`, `design_id`, `element_name`, `quantity`, `notes`, `sort_order`) VALUES
(1, 1, 'Front', 1, 'Cable knit pattern', 1),
(2, 1, 'Back', 1, 'Plain knit', 2),
(3, 1, 'Sleeves', 2, 'Ribbed cuffs', 3),
(4, 1, 'V-Neck Collar', 1, 'V-neck ribbed trim', 4),
(5, 1, 'Bottom Hem', 1, 'Elasticated rib', 5),
(6, 2, 'Front Panels', 2, 'Left & right overlap panels', 1),
(7, 2, 'Back', 1, 'Center back seam with vent', 2),
(8, 2, 'Sleeves', 2, 'Two-piece sleeve', 3),
(9, 2, 'Collar & Lapel', 1, 'Notched lapel', 4),
(10, 2, 'Pockets', 2, 'Flap side pockets', 5),
(11, 2, 'Inner Lining', 1, 'Silk poly lining', 6),
(12, 3, 'Front Panels', 2, 'Zipper opening', 1),
(13, 3, 'Back', 1, 'Quilted panel', 2),
(14, 3, 'Sleeves', 2, 'Elastic cuff bands', 3),
(15, 3, 'Side Pockets', 2, 'Zipper welt pockets', 4),
(16, 3, 'Stand Collar', 1, 'Padded collar', 5),
(17, 3, 'Detachable Hood', 1, 'Drawstring hood', 6);

-- Workers (Demo Artisans)
INSERT INTO `workers` (`id`, `code`, `name`, `phone`, `address`, `cnic`, `worker_type`, `department_id`, `default_rate`, `joining_date`, `opening_balance`, `is_active`) VALUES
(1, 'WRK-001', 'Latif Stitcher', '0301-1112233', 'Street 4, Factory Colony', '35201-1234567-1', 'Piece Rate', 3, 12.00, '2024-01-15', 2000.00, 1),
(2, 'WRK-002', 'Hashim Tailor', '0302-2223344', 'Sector 7, Labour Square', '35201-2345678-2', 'Piece Rate', 2, 5.00, '2024-02-01', 0.00, 1),
(3, 'WRK-003', 'Amir Overlock', '0303-3334455', 'Madina Town', '35201-3456789-3', 'Piece Rate', 4, 3.00, '2024-03-10', 0.00, 1),
(4, 'WRK-004', 'Asad Cutter', '0304-4445566', 'Ghaziabad, Faisalabad', '35201-4567890-4', 'Piece Rate', 1, 3.00, '2024-01-10', 0.00, 1),
(5, 'WRK-005', 'Moni Button & Kaj', '0305-5556677', 'Railway Colony', '35201-5678901-5', 'Piece Rate', 5, 6.00, '2024-04-05', 0.00, 1),
(6, 'WRK-006', 'Rashid Press Master', '0306-6667788', 'Factory Quarters', '35201-6789012-6', 'Piece Rate', 6, 3.00, '2024-02-20', 0.00, 1),
(7, 'WRK-007', 'Tariq Packer', '0307-7778899', 'Gulberg III', '35201-7890123-7', 'Piece Rate', 7, 2.00, '2024-05-01', 0.00, 1);

-- Worker Opening Balances in Ledger
INSERT INTO `worker_ledger` (`worker_id`, `transaction_date`, `transaction_type`, `reference_type`, `reference_id`, `lot_id`, `operation_id`, `debit`, `credit`, `running_balance`, `description`) VALUES
(1, '2026-01-01', 'Opening Balance', 'OPENING', 1, NULL, NULL, 0.00, 2000.00, 2000.00, 'Opening Balance payable to worker');

-- Worker Operations Assignments
INSERT INTO `worker_operations` (`worker_id`, `operation_id`) VALUES
(4, 1), -- Asad -> Cutting
(2, 2), -- Hashim -> Tailoring
(3, 3), -- Amir -> Safety
(1, 4), -- Latif -> Stitching
(5, 6), -- Moni -> Buttons
(5, 7), -- Moni -> Kaj
(6, 8), -- Rashid -> Press
(7, 9); -- Tariq -> Packing

-- Payment Methods
INSERT INTO `payment_methods` (`id`, `name`, `code`, `is_active`) VALUES
(1, 'Cash', 'CASH', 1),
(2, 'Bank Transfer', 'BANK', 1),
(3, 'JazzCash', 'JAZZ', 1),
(4, 'Easypaisa', 'EP', 1);

-- Cash / Bank Accounts
INSERT INTO `cash_bank_accounts` (`id`, `account_name`, `account_number`, `bank_name`, `current_balance`, `is_active`) VALUES
(1, 'Factory Cash Register', 'CASH-MAIN', 'In-House Cash Drawer', 150000.00, 1),
(2, 'Meezan Bank Current A/C', '0101010293848', 'Meezan Bank Ltd', 450000.00, 1),
(3, 'Habib Bank Ltd Factory A/C', '1234567890098', 'Habib Bank Limited', 280000.00, 1);

-- Suppliers
INSERT INTO `suppliers` (`id`, `code`, `name`, `contact_person`, `phone`, `email`, `address`, `opening_balance`, `is_active`) VALUES
(1, 'SUP-001', 'Al-Madina Textile Mills', 'Haji Farooq', '0300-9876543', 'info@almadinatex.com', 'Millat Road, Faisalabad', 0.00, 1),
(2, 'SUP-002', 'Kohinoor Yarn & Threads', 'Kashif Mehmood', '0321-4567890', 'sales@kohinooryarn.com', 'Circular Road, Lahore', 0.00, 1),
(3, 'SUP-003', 'Prime Buttons & Accessories', 'Zahid Ali', '0333-5678901', 'primebuttons@gmail.com', 'Bolton Market, Karachi', 0.00, 1);

-- Raw Materials
INSERT INTO `materials` (`id`, `code`, `name`, `category`, `unit_id`, `min_stock`, `opening_stock`, `cost_price`, `current_stock`, `supplier_id`, `storage_location`, `is_active`) VALUES
(1, 'MAT-YARN-01', '2/28 Woolen Acrylic Yarn', 'Yarn', 1, 50.00, 500.00, 1200.00, 500.00, 2, 'Rack Y-1', 1),
(2, 'MAT-FAB-01', 'Heavy Wool Blazer Fabric', 'Fabric', 3, 30.00, 300.00, 1500.00, 300.00, 1, 'Rack F-3', 1),
(3, 'MAT-THRD-01', 'Polyester Sewing Thread 40/2', 'Thread', 6, 20.00, 150.00, 85.00, 150.00, 2, 'Shelf T-2', 1),
(4, 'MAT-BTN-01', 'Horn Finish Blazer Buttons 24L', 'Buttons', 5, 200.00, 2000.00, 4.50, 2000.00, 3, 'Box B-12', 1),
(5, 'MAT-ZIP-01', 'Metal Jacket Zip 28 Inch', 'Zips', 5, 50.00, 400.00, 65.00, 400.00, 3, 'Box Z-05', 1),
(6, 'MAT-POLY-01', 'Standard Garment Polybag', 'Packaging Material', 5, 500.00, 5000.00, 3.20, 5000.00, 3, 'Store B', 1);

-- Customers / Parties
INSERT INTO `customers` (`id`, `code`, `name`, `contact_person`, `phone`, `email`, `address`, `city`, `opening_balance`, `credit_limit`, `is_active`) VALUES
(1, 'CUST-001', 'Royal Garments & Boutique', 'Malik Usman', '0300-8765432', 'royalgarments@yahoo.com', 'Anarkali Bazaar', 'Lahore', 0.00, 500000.00, 1),
(2, 'CUST-002', 'Khaas Collection Outlets', 'Bilal Ahmed', '0321-7654321', 'orders@khaascollection.pk', 'Tariq Road', 'Karachi', 0.00, 1000000.00, 1),
(3, 'CUST-003', 'Winter Elegance Wholesale', 'Chaudhry Akram', '0333-6543210', 'akram@winterelegance.com', 'Saddar', 'Rawalpindi', 0.00, 750000.00, 1);

-- Expense Categories
INSERT INTO `expense_categories` (`id`, `name`, `description`) VALUES
(1, 'Factory Electricity & Power', 'Monthly factory electricity and generator fuel'),
(2, 'Machine Maintenance & Needles', 'Sewing machine servicing, spare parts, needle replacement'),
(3, 'Thread & Trims Consumption', 'General auxiliary consumables'),
(4, 'Staff Tea & Refreshments', 'Daily worker refreshments and drinking water'),
(5, 'Rent & Warehouse Charges', 'Facility and storage rent');
