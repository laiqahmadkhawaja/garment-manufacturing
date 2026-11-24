-- MySQL dump 10.13  Distrib 8.4.7, for Win64 (x86_64)
--
-- Host: localhost    Database: garment_erp
-- ------------------------------------------------------
-- Server version	5.5.5-10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `account_transactions`
--

DROP TABLE IF EXISTS `account_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('Deposit','Withdrawal','Transfer In','Transfer Out','Customer Payment','Supplier Payment','Labour Payment','Expense','Opening Balance','Adjustment') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `running_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `related_account_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_acc_date` (`account_id`,`transaction_date`),
  KEY `related_account_id` (`related_account_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `account_transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `cash_bank_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `account_transactions_ibfk_2` FOREIGN KEY (`related_account_id`) REFERENCES `cash_bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `account_transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_transactions`
--

LOCK TABLES `account_transactions` WRITE;
/*!40000 ALTER TABLE `account_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `account_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bom_headers`
--

DROP TABLE IF EXISTS `bom_headers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bom_headers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `design_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `wastage_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  CONSTRAINT `bom_headers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bom_headers_ibfk_2` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bom_headers`
--

LOCK TABLES `bom_headers` WRITE;
/*!40000 ALTER TABLE `bom_headers` DISABLE KEYS */;
/*!40000 ALTER TABLE `bom_headers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bom_items`
--

DROP TABLE IF EXISTS `bom_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bom_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bom_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity_per_piece` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `bom_id` (`bom_id`),
  KEY `material_id` (`material_id`),
  CONSTRAINT `bom_items_ibfk_1` FOREIGN KEY (`bom_id`) REFERENCES `bom_headers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bom_items_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bom_items`
--

LOCK TABLES `bom_items` WRITE;
/*!40000 ALTER TABLE `bom_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `bom_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_bank_accounts`
--

DROP TABLE IF EXISTS `cash_bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(100) NOT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `current_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_bank_accounts`
--

LOCK TABLES `cash_bank_accounts` WRITE;
/*!40000 ALTER TABLE `cash_bank_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_bank_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `colors`
--

DROP TABLE IF EXISTS `colors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `colors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `color_code` varchar(50) DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `hex_code` varchar(10) DEFAULT '#cccccc',
  `yarn_stock_kg` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `colors`
--

LOCK TABLES `colors` WRITE;
/*!40000 ALTER TABLE `colors` DISABLE KEYS */;
INSERT INTO `colors` VALUES (1,NULL,'Black','#1a1a1a',0.00),(2,NULL,'Navy Blue','#001f3f',0.00),(3,NULL,'Charcoal Grey','#333333',0.00),(4,NULL,'Maroon','#800000',0.00),(5,NULL,'Off-White / Beige','#f5f5dc',0.00),(6,NULL,'Olive Green','#556b2f',0.00);
/*!40000 ALTER TABLE `colors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_ledger`
--

DROP TABLE IF EXISTS `customer_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('Dispatch','Payment','Return','Opening Balance') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `running_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `customer_ledger_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_ledger_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_ledger`
--

LOCK TABLES `customer_ledger` WRITE;
/*!40000 ALTER TABLE `customer_ledger` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_ledger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit_limit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'DEP-OP','Operator','Knitting Machine Operators (front, back, sleeves, trims)',1),(2,'DEP-PPRESS','Palay Press','Knitted panel / palay steaming and iron pressing',1),(3,'DEP-QC','Quality Check','Raw knitwear piece checking and defect auditing',1),(4,'DEP-CUT','Cutting','Precision cutting of knitted panels, collars, sleeves',1),(5,'DEP-TAIL','Tailor','Stitching, tailoring, pockets, gala, labels, and zippers',1),(6,'DEP-SAFTY','Safty','Safety overlock stitching and panel reinforcement',1),(7,'DEP-TANKAY','Tankay','Manual and machine tankay attachment',1),(8,'DEP-KAJ','Kaj','Buttonholes / Kaj creation',1),(9,'DEP-BTN','Button','Button attachment and fastening',1),(10,'DEP-ACC','Accessories','Moti, Kadhari, Dori, Fur, and embellishments',1),(11,'DEP-MPRESS','Mall Press','Finished garment pressing and ironing',1),(12,'DEP-CHK','Checking','Final quality checking and inspection',1),(13,'DEP-PACK','Packing','Folding, polybagging, tagging and carton packing',1),(14,'DEP-PRESS','PRESS','General Press and Finish Department',1);
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `design_elements`
--

DROP TABLE IF EXISTS `design_elements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `design_elements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `design_id` int(11) NOT NULL,
  `element_name` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `design_id` (`design_id`),
  CONSTRAINT `design_elements_ibfk_1` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `design_elements`
--

LOCK TABLES `design_elements` WRITE;
/*!40000 ALTER TABLE `design_elements` DISABLE KEYS */;
/*!40000 ALTER TABLE `design_elements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `designs`
--

DROP TABLE IF EXISTS `designs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `designs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `design_number` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `sketch_image` varchar(255) DEFAULT NULL,
  `machine_type` varchar(60) DEFAULT 'CMS 430',
  `measurements_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`measurements_json`)),
  `product_id` int(11) NOT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `specifications` text DEFAULT NULL,
  `workflow_template_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_design` (`product_id`,`design_number`),
  KEY `workflow_template_id` (`workflow_template_id`),
  CONSTRAINT `designs_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `designs_ibfk_2` FOREIGN KEY (`workflow_template_id`) REFERENCES `workflow_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `designs`
--

LOCK TABLES `designs` WRITE;
/*!40000 ALTER TABLE `designs` DISABLE KEYS */;
/*!40000 ALTER TABLE `designs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatch_items`
--

DROP TABLE IF EXISTS `dispatch_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatch_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dispatch_id` int(11) NOT NULL,
  `lot_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `design_id` int(11) DEFAULT NULL,
  `color_id` int(11) NOT NULL,
  `size_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `dispatch_id` (`dispatch_id`),
  KEY `lot_id` (`lot_id`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  CONSTRAINT `dispatch_items_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispatch_items_ibfk_2` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`),
  CONSTRAINT `dispatch_items_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `dispatch_items_ibfk_4` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dispatch_items_ibfk_5` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `dispatch_items_ibfk_6` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatch_items`
--

LOCK TABLES `dispatch_items` WRITE;
/*!40000 ALTER TABLE `dispatch_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `dispatch_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatch_return_items`
--

DROP TABLE IF EXISTS `dispatch_return_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatch_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `lot_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `design_id` int(11) DEFAULT NULL,
  `color_id` int(11) NOT NULL,
  `size_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `return_id` (`return_id`),
  KEY `lot_id` (`lot_id`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  CONSTRAINT `dispatch_return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `dispatch_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dispatch_return_items_ibfk_2` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`),
  CONSTRAINT `dispatch_return_items_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `dispatch_return_items_ibfk_4` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dispatch_return_items_ibfk_5` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `dispatch_return_items_ibfk_6` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatch_return_items`
--

LOCK TABLES `dispatch_return_items` WRITE;
/*!40000 ALTER TABLE `dispatch_return_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `dispatch_return_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatch_returns`
--

DROP TABLE IF EXISTS `dispatch_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatch_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) NOT NULL,
  `return_date` date NOT NULL,
  `dispatch_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `return_type` enum('Saleable','Damaged','Rework') NOT NULL DEFAULT 'Saleable',
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `dispatch_id` (`dispatch_id`),
  KEY `customer_id` (`customer_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `dispatch_returns_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `dispatches` (`id`),
  CONSTRAINT `dispatch_returns_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `dispatch_returns_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatch_returns`
--

LOCK TABLES `dispatch_returns` WRITE;
/*!40000 ALTER TABLE `dispatch_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `dispatch_returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispatches`
--

DROP TABLE IF EXISTS `dispatches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispatches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dispatch_number` varchar(50) NOT NULL,
  `dispatch_date` date NOT NULL,
  `customer_id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `challan_no` varchar(60) DEFAULT NULL,
  `vehicle_details` varchar(120) DEFAULT NULL,
  `total_pieces` int(11) NOT NULL DEFAULT 0,
  `total_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Credit','Partial','Paid') NOT NULL DEFAULT 'Credit',
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `account_id` int(11) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dispatch_number` (`dispatch_number`),
  KEY `customer_id` (`customer_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_dispatch_account` (`account_id`),
  CONSTRAINT `dispatches_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `dispatches_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `dispatches_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dispatch_account` FOREIGN KEY (`account_id`) REFERENCES `cash_bank_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispatches`
--

LOCK TABLES `dispatches` WRITE;
/*!40000 ALTER TABLE `dispatches` DISABLE KEYS */;
/*!40000 ALTER TABLE `dispatches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `category_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'Cash',
  `account_id` int(11) DEFAULT NULL,
  `reference_no` varchar(60) DEFAULT NULL,
  `lot_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `account_id` (`account_id`),
  KEY `lot_id` (`lot_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `cash_bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_ibfk_3` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finished_goods_stock`
--

DROP TABLE IF EXISTS `finished_goods_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finished_goods_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `design_id` int(11) DEFAULT NULL,
  `color_id` int(11) NOT NULL,
  `size_id` int(11) NOT NULL,
  `lot_id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `dispatched_stock` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fg_item` (`product_id`,`design_id`,`color_id`,`size_id`,`lot_id`,`warehouse_id`),
  KEY `design_id` (`design_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  KEY `lot_id` (`lot_id`),
  KEY `warehouse_id` (`warehouse_id`),
  CONSTRAINT `finished_goods_stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `finished_goods_stock_ibfk_2` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finished_goods_stock_ibfk_3` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `finished_goods_stock_ibfk_4` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`),
  CONSTRAINT `finished_goods_stock_ibfk_5` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finished_goods_stock_ibfk_6` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finished_goods_stock`
--

LOCK TABLES `finished_goods_stock` WRITE;
/*!40000 ALTER TABLE `finished_goods_stock` DISABLE KEYS */;
/*!40000 ALTER TABLE `finished_goods_stock` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `garment_types`
--

DROP TABLE IF EXISTS `garment_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `garment_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `code` varchar(30) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `garment_types`
--

LOCK TABLES `garment_types` WRITE;
/*!40000 ALTER TABLE `garment_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `garment_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_transactions`
--

DROP TABLE IF EXISTS `inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `item_type` enum('Material','Finished Goods') NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `design_id` int(11) DEFAULT NULL,
  `color_id` int(11) DEFAULT NULL,
  `size_id` int(11) DEFAULT NULL,
  `lot_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `transaction_type` varchar(50) NOT NULL,
  `quantity_in` decimal(12,2) NOT NULL DEFAULT 0.00,
  `quantity_out` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `material_id` (`material_id`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  KEY `lot_id` (`lot_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_transactions_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_transactions_ibfk_3` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_transactions_ibfk_4` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_transactions_ibfk_5` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_transactions_ibfk_6` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_transactions_ibfk_7` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `inventory_transactions_ibfk_8` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transactions`
--

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lot_operator_element_entries`
--

DROP TABLE IF EXISTS `lot_operator_element_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lot_operator_element_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lot_id` int(11) NOT NULL,
  `element_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `operator1_id` int(11) DEFAULT NULL,
  `operator1_pieces` int(11) DEFAULT 0,
  `operator2_id` int(11) DEFAULT NULL,
  `operator2_pieces` int(11) DEFAULT 0,
  `total_pieces` int(11) GENERATED ALWAYS AS (`operator1_pieces` + `operator2_pieces`) STORED,
  `cut_pieces` int(11) NOT NULL DEFAULT 0,
  `required_pieces` int(11) NOT NULL,
  `minutes_spent` decimal(8,2) DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lot_id` (`lot_id`),
  KEY `element_id` (`element_id`),
  CONSTRAINT `lot_operator_element_entries_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lot_operator_element_entries_ibfk_2` FOREIGN KEY (`element_id`) REFERENCES `product_elements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lot_operator_element_entries`
--

LOCK TABLES `lot_operator_element_entries` WRITE;
/*!40000 ALTER TABLE `lot_operator_element_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `lot_operator_element_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lot_status_history`
--

DROP TABLE IF EXISTS `lot_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lot_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lot_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `remarks` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lot_id` (`lot_id`),
  KEY `changed_by` (`changed_by`),
  CONSTRAINT `lot_status_history_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lot_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lot_status_history`
--

LOCK TABLES `lot_status_history` WRITE;
/*!40000 ALTER TABLE `lot_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `lot_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lot_variants`
--

DROP TABLE IF EXISTS `lot_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lot_variants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lot_id` int(11) NOT NULL,
  `color_id` int(11) NOT NULL,
  `color_code` varchar(50) DEFAULT NULL,
  `size_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lot_var` (`lot_id`,`color_id`,`size_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  CONSTRAINT `lot_variants_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lot_variants_ibfk_2` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `lot_variants_ibfk_3` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lot_variants`
--

LOCK TABLES `lot_variants` WRITE;
/*!40000 ALTER TABLE `lot_variants` DISABLE KEYS */;
INSERT INTO `lot_variants` VALUES (1,1,1,NULL,2,12),(2,1,2,NULL,2,12),(3,2,3,NULL,2,12),(4,2,4,NULL,2,12),(5,3,5,NULL,5,12),(6,3,1,NULL,5,12);
/*!40000 ALTER TABLE `lot_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lot_workflow_steps`
--

DROP TABLE IF EXISTS `lot_workflow_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lot_workflow_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lot_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `sequence` int(11) NOT NULL DEFAULT 1,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lot_step` (`lot_id`,`operation_id`),
  KEY `operation_id` (`operation_id`),
  CONSTRAINT `lot_workflow_steps_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lot_workflow_steps_ibfk_2` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lot_workflow_steps`
--

LOCK TABLES `lot_workflow_steps` WRITE;
/*!40000 ALTER TABLE `lot_workflow_steps` DISABLE KEYS */;
INSERT INTO `lot_workflow_steps` VALUES (1,1,60,1,1,'Pending','2026-11-12 17:20:58'),(2,1,13,2,1,'Pending','2026-11-12 17:20:58'),(3,1,16,3,1,'Pending','2026-11-12 17:20:58'),(4,1,8,4,1,'Pending','2026-11-12 17:20:58'),(5,1,57,5,1,'Pending','2026-11-12 17:20:58'),(6,1,22,6,1,'Pending','2026-11-12 17:20:58'),(7,1,17,7,1,'Pending','2026-11-12 17:20:58'),(8,1,59,8,1,'Pending','2026-11-12 17:20:58'),(9,1,14,9,1,'Pending','2026-11-12 17:20:58'),(10,1,61,10,1,'Pending','2026-11-12 17:20:58'),(11,1,11,11,1,'Pending','2026-11-12 17:20:58'),(12,2,60,1,1,'Pending','2026-11-12 17:20:58'),(13,2,13,2,1,'Pending','2026-11-12 17:20:58'),(14,2,16,3,1,'Pending','2026-11-12 17:20:58'),(15,2,8,4,1,'Pending','2026-11-12 17:20:58'),(16,2,34,5,1,'Pending','2026-11-12 17:20:58'),(17,2,22,6,1,'Pending','2026-11-12 17:20:58'),(18,2,4,7,1,'Pending','2026-11-12 17:20:58'),(19,2,17,8,1,'Pending','2026-11-12 17:20:58'),(20,2,59,9,1,'Pending','2026-11-12 17:20:58'),(21,2,10,10,1,'Pending','2026-11-12 17:20:58'),(22,2,5,11,1,'Pending','2026-11-12 17:20:58'),(23,2,14,12,1,'Pending','2026-11-12 17:20:58'),(24,2,61,13,1,'Pending','2026-11-12 17:20:58'),(25,2,11,14,1,'Pending','2026-11-12 17:20:58'),(26,3,60,1,1,'Pending','2026-11-12 17:20:58'),(27,3,13,2,1,'Pending','2026-11-12 17:20:58'),(28,3,16,3,1,'Pending','2026-11-12 17:20:58'),(29,3,8,4,1,'Pending','2026-11-12 17:20:58'),(30,3,23,5,1,'Pending','2026-11-12 17:20:58'),(31,3,22,6,1,'Pending','2026-11-12 17:20:58'),(32,3,18,7,1,'Pending','2026-11-12 17:20:58'),(33,3,17,8,1,'Pending','2026-11-12 17:20:58'),(34,3,59,9,1,'Pending','2026-11-12 17:20:58'),(35,3,1,10,1,'Pending','2026-11-12 17:20:58'),(36,3,14,11,1,'Pending','2026-11-12 17:20:58'),(37,3,61,12,1,'Pending','2026-11-12 17:20:58'),(38,3,11,13,1,'Pending','2026-11-12 17:20:58');
/*!40000 ALTER TABLE `lot_workflow_steps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lots`
--

DROP TABLE IF EXISTS `lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lot_number` varchar(50) NOT NULL,
  `lot_date` date NOT NULL,
  `product_id` int(11) NOT NULL,
  `design_number` varchar(100) DEFAULT NULL,
  `design_id` int(11) DEFAULT NULL,
  `supervisor_name` varchar(100) DEFAULT NULL,
  `shift_type` enum('Single Shift','Double Shift') DEFAULT 'Single Shift',
  `machine_name` varchar(100) DEFAULT 'CMS 430',
  `operator_id` int(11) DEFAULT NULL,
  `night_operator_id` int(11) DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `total_pieces` int(11) NOT NULL DEFAULT 0,
  `total_colors` int(11) NOT NULL DEFAULT 0,
  `total_sizes` int(11) NOT NULL DEFAULT 0,
  `size_mode` enum('Single Size','Multi Size') DEFAULT 'Single Size',
  `single_size_id` int(11) DEFAULT NULL,
  `has_anatomy_variants` tinyint(1) DEFAULT 0,
  `anatomy_breakdown` longtext DEFAULT NULL,
  `measurements_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`measurements_json`)),
  `sketch_image` varchar(255) DEFAULT NULL,
  `status` enum('Draft','In Production','Production Completed','Partially Packed','Ready Stock','Partially Dispatched','Dispatched','Closed') NOT NULL DEFAULT 'Draft',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `lot_number` (`lot_number`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  KEY `operator_id` (`operator_id`),
  KEY `night_operator_id` (`night_operator_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `lots_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `lots_ibfk_2` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lots_ibfk_3` FOREIGN KEY (`operator_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lots_ibfk_4` FOREIGN KEY (`night_operator_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lots_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lots`
--

LOCK TABLES `lots` WRITE;
/*!40000 ALTER TABLE `lots` DISABLE KEYS */;
INSERT INTO `lots` VALUES (1,'LOT-2026-001','2026-11-12',1,NULL,NULL,'System Administrator','Double Shift','Shima Seiki 1',1,2,NULL,NULL,24,2,1,'Single Size',2,1,'{\"with_sleeves\":12,\"sleeveless\":12,\"notes\":\"12pc with sleeves (4 palay each = 48) + 12pc without sleeves (2 palay each = 24). Total 72 palay.\"}',NULL,NULL,'In Production',1,'2026-11-12 17:20:58','2026-11-12 17:20:58'),(2,'LOT-2026-002','2026-11-12',2,NULL,NULL,'System Administrator','Double Shift','Shima Seiki 1',1,3,NULL,NULL,24,2,1,'Single Size',2,1,'{\"with_sleeves\":12,\"sleeveless\":12,\"notes\":\"Coati Ladies: 12 with sleeves + 12 sleeveless\"}',NULL,NULL,'In Production',1,'2026-11-12 17:20:58','2026-11-12 17:20:58'),(3,'LOT-2026-003','2026-11-12',4,NULL,NULL,'System Administrator','Double Shift','Shima Seiki 1',1,3,NULL,NULL,24,2,1,'Single Size',2,1,'{\"with_sleeves\":12,\"sleeveless\":12,\"notes\":\"12 Shawl Bazo (12+24 = 36 palay) + 12 Shawl Sendo (12 palay) = 48 palay\"}',NULL,NULL,'In Production',1,'2026-11-12 17:20:58','2026-11-12 17:20:58');
/*!40000 ALTER TABLE `lots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_issue_items`
--

DROP TABLE IF EXISTS `material_issue_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_issue_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `total_cost` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `issue_id` (`issue_id`),
  KEY `material_id` (`material_id`),
  CONSTRAINT `material_issue_items_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `material_issues` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_issue_items_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_issue_items`
--

LOCK TABLES `material_issue_items` WRITE;
/*!40000 ALTER TABLE `material_issue_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_issue_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_issues`
--

DROP TABLE IF EXISTS `material_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `issue_number` varchar(50) NOT NULL,
  `issue_date` date NOT NULL,
  `lot_id` int(11) NOT NULL,
  `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `issue_number` (`issue_number`),
  KEY `lot_id` (`lot_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `material_issues_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_issues_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_issues`
--

LOCK TABLES `material_issues` WRITE;
/*!40000 ALTER TABLE `material_issues` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_issues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_purchase_items`
--

DROP TABLE IF EXISTS `material_purchase_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `color_id` int(11) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `total_price` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `material_id` (`material_id`),
  KEY `fk_mpi_color` (`color_id`),
  CONSTRAINT `fk_mpi_color` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `material_purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `material_purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_purchase_items_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_purchase_items`
--

LOCK TABLES `material_purchase_items` WRITE;
/*!40000 ALTER TABLE `material_purchase_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_purchase_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_purchases`
--

DROP TABLE IF EXISTS `material_purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_number` varchar(50) NOT NULL,
  `purchase_date` date NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Paid','Partial','Credit') NOT NULL DEFAULT 'Credit',
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `invoice_no` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_number` (`purchase_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `material_purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `material_purchases_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_purchases`
--

LOCK TABLES `material_purchases` WRITE;
/*!40000 ALTER TABLE `material_purchases` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_purchases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_return_items`
--

DROP TABLE IF EXISTS `material_return_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `total_cost` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `return_id` (`return_id`),
  KEY `material_id` (`material_id`),
  CONSTRAINT `material_return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `material_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_return_items_ibfk_2` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_return_items`
--

LOCK TABLES `material_return_items` WRITE;
/*!40000 ALTER TABLE `material_return_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_return_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_returns`
--

DROP TABLE IF EXISTS `material_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `material_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) NOT NULL,
  `return_date` date NOT NULL,
  `lot_id` int(11) NOT NULL,
  `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `lot_id` (`lot_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `material_returns_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `material_returns_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_returns`
--

LOCK TABLES `material_returns` WRITE;
/*!40000 ALTER TABLE `material_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `materials`
--

DROP TABLE IF EXISTS `materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(60) NOT NULL,
  `color_id` int(11) DEFAULT NULL,
  `unit_id` int(11) NOT NULL,
  `min_stock` decimal(12,2) NOT NULL DEFAULT 0.00,
  `opening_stock` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `current_stock` decimal(12,2) NOT NULL DEFAULT 0.00,
  `supplier_id` int(11) DEFAULT NULL,
  `storage_location` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `unit_id` (`unit_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `fk_mat_color` (`color_id`),
  CONSTRAINT `fk_mat_color` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `materials_ibfk_1` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `materials_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `materials`
--

LOCK TABLES `materials` WRITE;
/*!40000 ALTER TABLE `materials` DISABLE KEYS */;
/*!40000 ALTER TABLE `materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `alert_type` enum('info','warning','danger','success') NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operation_rates`
--

DROP TABLE IF EXISTS `operation_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operation_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operation_id` int(11) NOT NULL,
  `worker_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `design_id` int(11) DEFAULT NULL,
  `size_id` int(11) DEFAULT NULL,
  `rate` decimal(12,2) NOT NULL,
  `effective_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `operation_id` (`operation_id`),
  KEY `worker_id` (`worker_id`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  KEY `size_id` (`size_id`),
  CONSTRAINT `operation_rates_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operation_rates_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operation_rates_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operation_rates_ibfk_4` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operation_rates_ibfk_5` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operation_rates`
--

LOCK TABLES `operation_rates` WRITE;
/*!40000 ALTER TABLE `operation_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `operation_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `operations`
--

DROP TABLE IF EXISTS `operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `code` varchar(40) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `sequence` int(11) NOT NULL DEFAULT 10,
  `estimated_minutes` decimal(8,2) NOT NULL DEFAULT 0.00,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `default_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate_unit` varchar(30) NOT NULL DEFAULT 'Per piece',
  `quantity_tracking` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `parent_id` (`parent_id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `operations_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `operations_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `operations`
--

LOCK TABLES `operations` WRITE;
/*!40000 ALTER TABLE `operations` DISABLE KEYS */;
INSERT INTO `operations` VALUES (1,NULL,'OP-DORI','Dori-Shawl','shawl dori',10,10,2.00,1,12.00,'Per piece',1,1),(2,NULL,'OP-KADHARI','Kadhari','Kadhari',10,11,15.00,1,110.00,'Per piece',1,1),(3,NULL,'OP-MOTIFANCY','MotiFancy','Moti Fancy',10,12,12.00,1,80.00,'Per piece',1,1),(4,NULL,'OP-MOTI','Moti','Moti',10,13,5.00,1,25.00,'Per piece',1,1),(5,NULL,'OP-BTN5','Button5','Button 84 rate',9,14,2.00,1,7.00,'Per piece',1,1),(6,NULL,'OP-BTN6','Button6','Button 84 rate',9,15,2.20,1,8.33,'Per piece',1,1),(7,NULL,'OP-BTNSHAWL','ButtonShawl','Button Shawl 96',9,16,2.00,1,8.00,'Per piece',1,1),(8,NULL,'OP-KATAI','Katai','Katai',4,17,3.50,1,12.00,'Per piece',1,1),(9,NULL,'OP-KATAIB','Katai bazo','Katai bazo only',4,18,1.00,1,3.00,'Per piece',1,1),(10,NULL,'OP-KAJ','KAJ','KAJ 80 Darjan',8,19,1.80,1,6.67,'Per piece',1,1),(11,NULL,'OP-P','P','Packing Only',13,20,1.50,1,5.00,'Per piece',1,1),(12,NULL,'OP-PC','P+C','Packing and Checking',13,21,2.50,1,8.00,'Per piece',1,1),(13,NULL,'OP-PPRESS','PALAY Press','PALAY Press',2,22,2.00,1,10.00,'Per pala',1,1),(14,NULL,'OP-MPRESS','MallPress','Mall Press',11,23,3.00,1,15.00,'Per piece',1,1),(15,NULL,'OP-MURAMTIYA','Muramtiya','Mall Muramt',3,24,2.00,1,8.00,'Per piece',1,1),(16,NULL,'OP-QC','QC','Quality Check piece audit',3,25,2.00,1,8.00,'Per piece',1,1),(17,NULL,'OP-SAFTY','Safty','Safty',6,26,2.50,1,10.00,'Per piece',1,1),(18,NULL,'OP-LBL','Label','Cut Shawl Label , Label',5,27,1.00,1,3.00,'Per piece',1,1),(19,NULL,'OP-LM','LM','Link Moda Thoka Label',5,28,2.50,1,9.00,'Per piece',1,1),(20,NULL,'OP-KL','KL','Kiyari Label',5,29,3.00,1,12.00,'Per piece',1,1),(21,NULL,'OP-CSL','CSL','Cut Shawl Label',5,30,3.50,1,14.00,'Per piece',1,1),(22,NULL,'OP-MODAY','moday','Moday',5,31,4.00,1,15.00,'Per piece',1,1),(23,NULL,'OP-SSL','SSL','Shawl Salai Label',5,32,4.00,1,16.00,'Per piece',1,1),(24,NULL,'OP-OP','OP','Open Pocket',5,33,4.00,1,16.00,'Per piece',1,1),(25,NULL,'OP-KLS','KLS','Kiyari Label - thokay wali',5,34,5.00,1,20.00,'Per piece',1,1),(26,NULL,'OP-OPD','OPD','Open Pocket Design',5,35,5.00,1,20.00,'Per piece',1,1),(27,NULL,'OP-GLADIES','GolgalaLadies','Gol Gala Ladies',5,36,5.00,1,21.00,'Per piece',1,1),(28,NULL,'OP-SULTANI','Sultani','Open Pocket + Label + Thoka',5,37,6.00,1,23.00,'Per piece',1,1),(29,NULL,'OP-OPS','OPS','Open Pocket Design Single',5,38,6.00,1,23.00,'Per piece',1,1),(30,NULL,'OP-GOLPCK','GolPocket','Gol Pocket',5,39,6.00,1,23.00,'Per piece',1,1),(31,NULL,'OP-THELAPCK','ThelaPocket','Thela Pocket',5,40,6.00,1,24.00,'Per piece',1,1),(32,NULL,'OP-GML','GML','Gol Moda Label',5,41,6.50,1,25.00,'Per piece',1,1),(33,NULL,'OP-EGG','EGG','Golgala Export',5,42,9.00,1,35.00,'Per piece',1,1),(34,NULL,'OP-COATIOP','CoatiOP','Coati Open Pocket',5,43,9.50,1,38.00,'Per piece',1,1),(35,NULL,'OP-COATIOPD','CoatiOPD','Coati Open Pocket Design',5,44,10.50,1,42.00,'Per piece',1,1),(36,NULL,'OP-COATIGOLP','CoatiGolP','Coati Gol Pocket Design',5,45,10.50,1,42.00,'Per piece',1,1),(37,NULL,'OP-COATI','coati','Pocket Jati Slik Gala V + Gol + Label',5,46,11.00,1,45.00,'Per piece',1,1),(38,NULL,'OP-ALINE','Aline','Aline Kanda Pocket Label Salai',5,47,12.50,1,50.00,'Per piece',1,1),(39,NULL,'OP-FURSHAWL','FurShawl','Shawl fur',5,48,15.00,1,60.00,'Per piece',1,1),(40,NULL,'OP-FULZIP','Fulzip','Pocket Gents Zip Golgala Label Tanka',5,49,16.00,1,65.00,'Per piece',1,1),(41,NULL,'OP-SHAWLMURAM','ShawlMuramay','Shawl Muramt',5,50,2.50,1,10.00,'Per piece',1,1),(42,NULL,'OP-GERSI','gersi','Mithu Gersi',5,51,4.00,1,16.00,'Per piece',1,1),(43,NULL,'OP-SENDO','sendo','Mithu Sendo',5,52,8.00,1,32.00,'Per piece',1,1),(44,NULL,'OP-FLATLOCK','Flatlock','front tape flat lock',5,53,2.50,1,10.00,'Per piece',1,1),(45,NULL,'OP-VASTER','VAster','V- Aster Back',5,54,6.00,1,25.00,'Per piece',1,1),(46,NULL,'OP-FULZIPGERSI','FulzipGersi','without aster fulzip bazo',5,55,30.00,1,120.00,'Per piece',1,1),(47,NULL,'OP-FULZIPSENDO','FulzipSendo','without aster fulzip sendo',5,56,35.00,1,150.00,'Per piece',1,1),(48,NULL,'OP-ASTERG','AsterGersi','aster fulzip bazo',5,57,50.00,1,220.00,'Per piece',1,1),(49,NULL,'OP-ASTERS','AsterSendo','aster fulzip sendo',5,58,60.00,1,250.00,'Per piece',1,1),(50,NULL,'OP-SHIRT','Shirt','Round Neck + Side Salai',5,59,10.00,1,43.00,'Per piece',1,1),(51,NULL,'OP-KALAR','Kalar','Fur wala Kalar',5,60,15.00,1,60.00,'Per piece',1,1),(52,NULL,'OP-FPOCKET','Fpocket','Fur wali pocket',5,61,7.50,1,30.00,'Per piece',1,1),(53,NULL,'OP-KALARPCK','KalarPocket','Fur Kalar & Pocket',5,62,22.50,1,90.00,'Per piece',1,1),(54,NULL,'OP-TROUSER2','Trouser2','Trouser variant 38',5,63,9.50,1,38.00,'Per piece',1,1),(55,NULL,'OP-TROUSER1','Trouser1','Lastic + Blet + Asan',5,64,8.00,1,33.00,'Per piece',1,1),(56,NULL,'OP-TROUSER','Trouser','Lastic + Blet (28) + Side Salai (22) + Asan 5',5,65,14.00,1,55.00,'Per piece',1,1),(57,NULL,'OP-VGENTS','VGents','V Gents Neck',5,66,0.50,1,1.00,'Per piece',1,1),(58,NULL,'OP-MODAYGENTS','ModayGents','Moday Gents',5,67,1.00,1,2.00,'Per piece',1,1),(59,NULL,'OP-TANKAY','Tankay','Tankay 60 darjan',7,68,1.50,1,5.00,'Per piece',1,1),(60,NULL,'OP-OPERATOR','Operator Knitting','Knitting panels (Front, Back, Bazo, V-Tape)',1,1,10.00,1,2.50,'Per minute',1,1),(61,NULL,'OP-CHECKING','Checking','Final Checking',12,70,1.50,1,5.00,'Per piece',1,1);
/*!40000 ALTER TABLE `operations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `packing_entries`
--

DROP TABLE IF EXISTS `packing_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `packing_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `packing_number` varchar(50) NOT NULL,
  `packing_date` date NOT NULL,
  `lot_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `design_id` int(11) DEFAULT NULL,
  `color_id` int(11) NOT NULL,
  `size_id` int(11) NOT NULL,
  `packed_qty` int(11) NOT NULL,
  `packing_worker_id` int(11) DEFAULT NULL,
  `packing_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `labour_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `package_type` varchar(50) DEFAULT 'Box',
  `package_no` varchar(60) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `packing_number` (`packing_number`),
  KEY `lot_id` (`lot_id`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  KEY `packing_worker_id` (`packing_worker_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `packing_entries_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `packing_entries_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `packing_entries_ibfk_3` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `packing_entries_ibfk_4` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `packing_entries_ibfk_5` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`),
  CONSTRAINT `packing_entries_ibfk_6` FOREIGN KEY (`packing_worker_id`) REFERENCES `workers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `packing_entries_ibfk_7` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `packing_entries_ibfk_8` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `packing_entries`
--

LOCK TABLES `packing_entries` WRITE;
/*!40000 ALTER TABLE `packing_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `packing_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(60) NOT NULL,
  `code` varchar(30) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_methods`
--

LOCK TABLES `payment_methods` WRITE;
/*!40000 ALTER TABLE `payment_methods` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `name` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_categories`
--

DROP TABLE IF EXISTS `product_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_categories`
--

LOCK TABLES `product_categories` WRITE;
/*!40000 ALTER TABLE `product_categories` DISABLE KEYS */;
INSERT INTO `product_categories` VALUES (1,'KNIT','Knitwear Sweaters','Woolen and acrylic knitwear sweaters and coats',1),(2,'SHAWL','Shawls & Wraps','Cut shawls, bazo shawls, and fur shawls',1),(3,'TROUSER','Trousers & Bottoms','Knitted and stitched trousers',1);
/*!40000 ALTER TABLE `product_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_elements`
--

DROP TABLE IF EXISTS `product_elements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_elements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `element_name` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `multiplier` int(11) NOT NULL DEFAULT 1,
  `tracks_at_operator` tinyint(1) DEFAULT 1,
  `default_sam_minutes` decimal(6,2) DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_elements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_elements`
--

LOCK TABLES `product_elements` WRITE;
/*!40000 ALTER TABLE `product_elements` DISABLE KEYS */;
INSERT INTO `product_elements` VALUES (1,1,'Front Panel (سامنے کا پلہ)',1,1,1,3.00,NULL,10,'2026-11-12 17:20:57'),(2,1,'Back Panel (پچھلا پلہ)',1,1,1,3.00,NULL,20,'2026-11-12 17:20:57'),(3,1,'Bazo / Sleeves (بازو پلہ)',2,2,1,4.00,NULL,30,'2026-11-12 17:20:57'),(4,1,'VTape / V-Gala (وی ٹیپ)',1,1,1,1.00,NULL,40,'2026-11-12 17:20:57'),(5,1,'Moday Tape (موڈھے ٹیپ - بغیر بازو)',1,1,1,1.00,NULL,50,'2026-11-12 17:20:57'),(6,2,'Front Panel (سامنے کا پلہ)',1,1,1,3.50,NULL,10,'2026-11-12 17:20:57'),(7,2,'Back Panel (پچھلا پلہ)',1,1,1,3.50,NULL,20,'2026-11-12 17:20:57'),(8,2,'Bazo / Sleeves (بازو پلہ)',2,2,1,4.00,NULL,30,'2026-11-12 17:20:57'),(9,2,'Pocket (پاکٹ)',2,2,1,1.50,NULL,40,'2026-11-12 17:20:57'),(10,2,'Gol Gala (گول گلا)',1,1,1,1.50,NULL,50,'2026-11-12 17:20:57'),(11,2,'Moday Tape (موڈھے ٹیپ)',1,1,1,1.00,NULL,60,'2026-11-12 17:20:57'),(12,3,'Front Panel (سامنے کا پلہ)',1,1,1,3.50,NULL,10,'2026-11-12 17:20:57'),(13,3,'Back Panel (پچھلا پلہ)',1,1,1,3.50,NULL,20,'2026-11-12 17:20:57'),(14,3,'Bazo / Sleeves (بازو پلہ)',2,2,1,4.00,NULL,30,'2026-11-12 17:20:57'),(15,3,'Bon / Pocket (بون / پاکٹ)',2,2,1,2.00,NULL,40,'2026-11-12 17:20:57'),(16,3,'Gol Gala (گول گلا)',1,1,1,1.50,NULL,50,'2026-11-12 17:20:57'),(17,4,'Shawl Panel (شال پلہ)',1,1,1,4.00,NULL,10,'2026-11-12 17:20:57'),(18,4,'Bazo / Sleeves (بازو پلہ)',2,2,1,4.00,NULL,20,'2026-11-12 17:20:57'),(19,4,'Moday Tape (موڈھے ٹیپ)',1,1,1,1.00,NULL,30,'2026-11-12 17:20:57'),(20,5,'Shawl A Panel (شال اے پلہ)',1,1,1,3.00,NULL,10,'2026-11-12 17:20:57'),(21,5,'Shawl B Panel (شال بی پلہ)',1,1,1,3.00,NULL,20,'2026-11-12 17:20:57'),(22,5,'Bazo / Sleeves (بازو پلہ)',2,2,1,4.00,NULL,30,'2026-11-12 17:20:57'),(23,6,'Front A Panel (سامنے اے پلہ)',1,1,1,3.00,NULL,10,'2026-11-12 17:20:57'),(24,6,'Front B Panel (سامنے بی پلہ)',1,1,1,3.00,NULL,20,'2026-11-12 17:20:57'),(25,6,'Back Panel (پچھلا پلہ)',1,1,1,3.00,NULL,30,'2026-11-12 17:20:57'),(26,6,'Bazo / Sleeves (بازو پلہ)',2,2,1,4.00,NULL,40,'2026-11-12 17:20:57');
/*!40000 ALTER TABLE `product_elements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `production_entries`
--

DROP TABLE IF EXISTS `production_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `production_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_number` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `lot_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `shift` enum('Day Shift','Night Shift','General') DEFAULT 'General',
  `color_id` int(11) DEFAULT NULL,
  `size_id` int(11) DEFAULT NULL,
  `qty_received` int(11) NOT NULL DEFAULT 0,
  `good_qty` int(11) NOT NULL DEFAULT 0,
  `rejected_qty` int(11) NOT NULL DEFAULT 0,
  `rework_qty` int(11) NOT NULL DEFAULT 0,
  `rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `minutes_per_piece` decimal(8,2) NOT NULL DEFAULT 0.00,
  `worker_minute_rate` decimal(8,2) NOT NULL DEFAULT 0.00,
  `total_minutes` decimal(12,2) NOT NULL DEFAULT 0.00,
  `labour_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_number` (`entry_number`),
  KEY `lot_id` (`lot_id`),
  KEY `operation_id` (`operation_id`),
  KEY `worker_id` (`worker_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `production_entries_ibfk_1` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `production_entries_ibfk_2` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`),
  CONSTRAINT `production_entries_ibfk_3` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`),
  CONSTRAINT `production_entries_ibfk_4` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `production_entries_ibfk_5` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`),
  CONSTRAINT `production_entries_ibfk_6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `production_entries`
--

LOCK TABLES `production_entries` WRITE;
/*!40000 ALTER TABLE `production_entries` DISABLE KEYS */;
INSERT INTO `production_entries` VALUES (1,'PE-2026-001','2026-11-12',1,60,1,'Day Shift',1,2,12,12,0,0,12.00,4.80,2.50,57.60,144.00,'Adnan Day: 12 with sleeves (front 12, back 12, bazo 24, vtape 12)',1,'2026-11-12 17:20:58'),(2,'PE-2026-002','2026-11-12',1,60,2,'Night Shift',2,2,12,12,0,0,10.00,4.00,2.50,48.00,120.00,'Molvi Night: 12 without sleeves (front 12, back 12, moday tape 12)',1,'2026-11-12 17:20:58'),(3,'PE-2026-003','2026-11-12',1,13,4,'General',1,2,24,24,0,0,10.00,2.00,2.50,48.00,720.00,'LALA: 12+12+24 (48 palay sleeves) + 12+12 (24 palay sendo) = 72 palay press @ Rs. 10/pala',1,'2026-11-12 17:20:58'),(4,'PE-2026-004','2026-11-12',1,16,5,'General',1,2,24,24,0,0,8.00,2.00,2.50,48.00,192.00,'Umair: Initial QC 24 pcs passed',1,'2026-11-12 17:20:58'),(5,'PE-2026-005','2026-11-12',1,8,6,'General',1,2,24,24,0,0,12.00,3.50,2.50,84.00,288.00,'Ali Raza: Katai 24 pcs panels',1,'2026-11-12 17:20:58'),(6,'PE-2026-006','2026-11-12',1,57,7,'General',1,2,24,24,0,0,1.00,0.50,2.50,12.00,24.00,'Asad: V-Gala tailoring',1,'2026-11-12 17:20:58'),(7,'PE-2026-007','2026-11-12',1,22,8,'General',2,2,12,12,0,0,15.00,4.00,2.50,48.00,180.00,'Irfan: Moday tailoring 12 sleeveless pcs',1,'2026-11-12 17:20:58'),(8,'PE-2026-008','2026-11-12',1,17,10,'General',1,2,24,24,0,0,10.00,2.50,2.50,60.00,240.00,'Bilal: Safety overlock on 24 pcs',1,'2026-11-12 17:20:58'),(9,'PE-2026-009','2026-11-12',1,59,11,'General',1,2,24,24,0,0,5.00,1.50,2.50,36.00,120.00,'Latif: Tankay 24 pcs',1,'2026-11-12 17:20:58'),(10,'PE-2026-010','2026-11-12',1,14,13,'General',1,2,24,24,0,0,15.00,3.00,2.50,72.00,360.00,'Lala: Finished garment Mall Press 24 pcs',1,'2026-11-12 17:20:58'),(11,'PE-2026-011','2026-11-12',1,61,14,'General',1,2,24,24,0,0,5.00,1.50,2.50,36.00,120.00,'Osama: Final Checking 24 pcs',1,'2026-11-12 17:20:58'),(12,'PE-2026-012','2026-11-12',1,11,14,'General',1,2,24,24,0,0,5.00,1.50,2.50,36.00,120.00,'Osama: Packing 24 pcs polybags',1,'2026-11-12 17:20:58'),(13,'PE-2026-020','2026-11-12',2,60,1,'Day Shift',3,2,12,12,0,0,12.00,4.80,2.50,57.60,144.00,'Adnan: Coati 12 pcs with sleeves',1,'2026-11-12 17:20:58'),(14,'PE-2026-021','2026-11-12',2,60,3,'Night Shift',4,2,12,12,0,0,10.00,4.00,2.50,48.00,120.00,'Hashim: Coati 12 pcs sleeveless',1,'2026-11-12 17:20:58'),(15,'PE-2026-022','2026-11-12',2,13,4,'General',3,2,24,24,0,0,10.00,2.00,2.50,48.00,720.00,'LALA: 72 palay press Coati @ Rs. 10/pala',1,'2026-11-12 17:20:58'),(16,'PE-2026-023','2026-11-12',2,34,7,'General',3,2,24,24,0,0,38.00,9.50,2.50,228.00,912.00,'Asad: Coati Open Pocket 24 pcs @ Rs. 38',1,'2026-11-12 17:20:58'),(17,'PE-2026-024','2026-11-12',2,5,12,'General',3,2,24,24,0,0,7.00,2.00,2.50,48.00,168.00,'Humza: Button5 attachment 24 pcs',1,'2026-11-12 17:20:58'),(18,'PE-2026-030','2026-11-12',3,60,1,'Day Shift',5,2,12,12,0,0,10.00,4.00,2.50,48.00,120.00,'Adnan: Shawl 12 + Bazo 24 panels',1,'2026-11-12 17:20:58'),(19,'PE-2026-031','2026-11-12',3,13,4,'General',5,2,24,24,0,0,10.00,2.00,2.50,48.00,480.00,'LALA: 12+24 (36 bazo) + 12 (12 sendo) = 48 palay press @ Rs. 10/pala',1,'2026-11-12 17:20:58'),(20,'PE-2026-032','2026-11-12',3,23,7,'General',5,2,12,12,0,0,16.00,4.00,2.50,48.00,192.00,'Asad: Shawl Salai 12 pcs',1,'2026-11-12 17:20:58'),(21,'PE-2026-033','2026-11-12',3,1,17,'General',5,2,24,24,0,0,12.00,2.00,2.50,48.00,288.00,'Saleem: Dori Lagwai 24 shawls @ Rs. 12',1,'2026-11-12 17:20:58');
/*!40000 ALTER TABLE `production_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `production_rejections`
--

DROP TABLE IF EXISTS `production_rejections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `production_rejections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `production_entry_id` int(11) DEFAULT NULL,
  `lot_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `color_id` int(11) DEFAULT NULL,
  `size_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `production_entry_id` (`production_entry_id`),
  KEY `lot_id` (`lot_id`),
  KEY `operation_id` (`operation_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  CONSTRAINT `production_rejections_ibfk_1` FOREIGN KEY (`production_entry_id`) REFERENCES `production_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `production_rejections_ibfk_2` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `production_rejections_ibfk_3` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`),
  CONSTRAINT `production_rejections_ibfk_4` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `production_rejections_ibfk_5` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `production_rejections`
--

LOCK TABLES `production_rejections` WRITE;
/*!40000 ALTER TABLE `production_rejections` DISABLE KEYS */;
/*!40000 ALTER TABLE `production_rejections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `production_rework`
--

DROP TABLE IF EXISTS `production_rework`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `production_rework` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `production_entry_id` int(11) DEFAULT NULL,
  `lot_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `color_id` int(11) DEFAULT NULL,
  `size_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `status` enum('Pending','In Progress','Resolved') NOT NULL DEFAULT 'Pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `production_entry_id` (`production_entry_id`),
  KEY `lot_id` (`lot_id`),
  KEY `operation_id` (`operation_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  CONSTRAINT `production_rework_ibfk_1` FOREIGN KEY (`production_entry_id`) REFERENCES `production_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `production_rework_ibfk_2` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `production_rework_ibfk_3` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`),
  CONSTRAINT `production_rework_ibfk_4` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`),
  CONSTRAINT `production_rework_ibfk_5` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `production_rework`
--

LOCK TABLES `production_rework` WRITE;
/*!40000 ALTER TABLE `production_rework` DISABLE KEYS */;
/*!40000 ALTER TABLE `production_rework` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `product_type` varchar(60) NOT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `attributes_json` text DEFAULT NULL,
  `default_workflow_template_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `category_id` (`category_id`),
  KEY `default_workflow_template_id` (`default_workflow_template_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`default_workflow_template_id`) REFERENCES `workflow_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'PROD-GERSI','Gersi Gents (جرسی جینٹس)',1,'Sweater',NULL,'Gents knitted gersi sweater, available with sleeves and sleeveless (sendo) variants',NULL,NULL,1,'2026-11-12 17:20:57'),(2,'PROD-COATI','Coati Ladies (کوٹی لیڈیز)',1,'Cardigan / Coat',NULL,'Ladies coati open pocket with sleeves and sleeveless variants',NULL,NULL,1,'2026-11-12 17:20:57'),(3,'PROD-FULZIP','Gent Fulzip (جینٹس فل زپ)',1,'Zipper Jacket',NULL,'Gents Full Zip Sweater with pocket and golgala',NULL,NULL,1,'2026-11-12 17:20:57'),(4,'PROD-SHAWLB','Shawl Bazo (شال بازو)',2,'Shawl',NULL,'Knitted Shawl with sleeves (Shawl Bazo) or sleeveless (Shawl Sendo)',NULL,NULL,1,'2026-11-12 17:20:57'),(5,'PROD-CUTSHAWL','Cut Shawl LK (کٹ شال ایل کے)',2,'Shawl',NULL,'Cut Shawl with ShawlA, ShawlB and Bazo panels',NULL,NULL,1,'2026-11-12 17:20:57'),(6,'PROD-8GMAMA','8g Mama Shape (8 جی ماما شیپ)',1,'Sweater',NULL,'8g Mama Shape Sweater with Front A/B, Back and Bazo',NULL,NULL,1,'2026-11-12 17:20:57');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','System Administrator','Full system access and control'),(2,'production_manager','Production Manager','Oversees lots, jobs, and cutting'),(3,'supervisor','Floor Supervisor','Floor level production logging'),(4,'accountant','Accountant','Manages ledgers, payments and expenses');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sizes`
--

DROP TABLE IF EXISTS `sizes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sizes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `code` varchar(20) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sizes`
--

LOCK TABLES `sizes` WRITE;
/*!40000 ALTER TABLE `sizes` DISABLE KEYS */;
INSERT INTO `sizes` VALUES (1,'Small (S)','S',1),(2,'Medium (M)','M',2),(3,'Large (L)','L',3),(4,'Extra Large (XL)','XL',4),(5,'Free Size (FS)','FS',5);
/*!40000 ALTER TABLE `sizes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_adjustments`
--

DROP TABLE IF EXISTS `stock_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_number` varchar(50) NOT NULL,
  `adjustment_date` date NOT NULL,
  `item_type` enum('Material','Finished Goods') NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `design_id` int(11) DEFAULT NULL,
  `color_id` int(11) DEFAULT NULL,
  `size_id` int(11) DEFAULT NULL,
  `lot_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `adjustment_type` enum('Add','Deduct') NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `reason` text NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `adjustment_number` (`adjustment_number`),
  KEY `material_id` (`material_id`),
  KEY `product_id` (`product_id`),
  KEY `design_id` (`design_id`),
  KEY `color_id` (`color_id`),
  KEY `size_id` (`size_id`),
  KEY `lot_id` (`lot_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `stock_adjustments_ibfk_1` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_ibfk_3` FOREIGN KEY (`design_id`) REFERENCES `designs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_ibfk_4` FOREIGN KEY (`color_id`) REFERENCES `colors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_ibfk_5` FOREIGN KEY (`size_id`) REFERENCES `sizes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_ibfk_6` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_adjustments_ibfk_7` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `stock_adjustments_ibfk_8` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_adjustments`
--

LOCK TABLES `stock_adjustments` WRITE;
/*!40000 ALTER TABLE `stock_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_ledger`
--

DROP TABLE IF EXISTS `supplier_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('Purchase','Payment','Return','Opening Balance') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `running_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_supp_date` (`supplier_id`,`transaction_date`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `supplier_ledger_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_ledger_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_ledger`
--

LOCK TABLES `supplier_ledger` WRITE;
/*!40000 ALTER TABLE `supplier_ledger` DISABLE KEYS */;
/*!40000 ALTER TABLE `supplier_ledger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(50) NOT NULL DEFAULT 'General',
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'company_name','Ali Garment Manufacturing Ltd.','Company'),(2,'company_phone','+92 300 1234567','Company'),(3,'company_email','info@aligarments.com','Company'),(4,'company_address','Industrial Area, Sector 4-B, Karachi / Faisalabad, Pakistan','Company'),(5,'currency_symbol','Rs.','General'),(6,'tax_number','NTN: 1234567-8','Company'),(7,'low_stock_threshold','10','Inventory'),(8,'allow_negative_stock','0','Inventory'),(9,'lot_prefix','LOT-','Production'),(10,'dispatch_prefix','DIS-','Dispatch'),(11,'payment_prefix','PAY-','Finance');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `units`
--

DROP TABLE IF EXISTS `units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `code` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `units`
--

LOCK TABLES `units` WRITE;
/*!40000 ALTER TABLE `units` DISABLE KEYS */;
INSERT INTO `units` VALUES (1,'Kilogram','KG'),(2,'Gram','GM'),(3,'Meters','MTR'),(4,'Yard','YD'),(5,'Pieces','PCS'),(6,'Roll','ROLL'),(7,'Dozen','DZN'),(8,'Packet','PKT'),(9,'Box','BOX'),(13,'Pound','LBS'),(15,'Yards','YDS'),(16,'Cones','CONE'),(17,'Pala / Panels','PALA'),(18,'Minutes','MIN');
/*!40000 ALTER TABLE `units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role_id` int(11) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','$2y$10$pTASKgruKqam4.mQQKHz7epHEM6utBaZQe8u7f6wMmyntqBxMSyoC','System Administrator',1,'0300-1234567','admin@garmentfactory.com',1,'2026-11-04 22:23:36','2026-11-05 13:19:00');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouse_transfers`
--

DROP TABLE IF EXISTS `warehouse_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouse_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_number` varchar(50) NOT NULL,
  `transfer_date` date NOT NULL,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `lot_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `design_id` int(11) DEFAULT NULL,
  `color_id` int(11) NOT NULL,
  `size_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_number` (`transfer_number`),
  KEY `from_warehouse_id` (`from_warehouse_id`),
  KEY `to_warehouse_id` (`to_warehouse_id`),
  KEY `lot_id` (`lot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouse_transfers`
--

LOCK TABLES `warehouse_transfers` WRITE;
/*!40000 ALTER TABLE `warehouse_transfers` DISABLE KEYS */;
/*!40000 ALTER TABLE `warehouse_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `warehouses`
--

LOCK TABLES `warehouses` WRITE;
/*!40000 ALTER TABLE `warehouses` DISABLE KEYS */;
/*!40000 ALTER TABLE `warehouses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `worker_element_rates`
--

DROP TABLE IF EXISTS `worker_element_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `worker_element_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `worker_id` int(11) NOT NULL,
  `element_id` int(11) NOT NULL,
  `custom_sam_minutes` decimal(6,2) DEFAULT NULL,
  `custom_piece_rate` decimal(10,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_element` (`worker_id`,`element_id`),
  KEY `worker_id` (`worker_id`),
  KEY `element_id` (`element_id`),
  CONSTRAINT `fk_wer_element` FOREIGN KEY (`element_id`) REFERENCES `product_elements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wer_worker` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `worker_element_rates`
--

LOCK TABLES `worker_element_rates` WRITE;
/*!40000 ALTER TABLE `worker_element_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `worker_element_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `worker_ledger`
--

DROP TABLE IF EXISTS `worker_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `worker_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `worker_id` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('Labour','Payment','Advance','Opening Balance','Reversal') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `lot_id` int(11) DEFAULT NULL,
  `operation_id` int(11) DEFAULT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `running_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `worker_id` (`worker_id`),
  KEY `lot_id` (`lot_id`),
  KEY `operation_id` (`operation_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `worker_ledger_ibfk_1` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `worker_ledger_ibfk_2` FOREIGN KEY (`lot_id`) REFERENCES `lots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `worker_ledger_ibfk_3` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `worker_ledger_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `worker_ledger`
--

LOCK TABLES `worker_ledger` WRITE;
/*!40000 ALTER TABLE `worker_ledger` DISABLE KEYS */;
/*!40000 ALTER TABLE `worker_ledger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `worker_operations`
--

DROP TABLE IF EXISTS `worker_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `worker_operations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `worker_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_worker_op` (`worker_id`,`operation_id`),
  KEY `operation_id` (`operation_id`),
  CONSTRAINT `worker_operations_ibfk_1` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `worker_operations_ibfk_2` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `worker_operations`
--

LOCK TABLES `worker_operations` WRITE;
/*!40000 ALTER TABLE `worker_operations` DISABLE KEYS */;
INSERT INTO `worker_operations` VALUES (1,1,60),(2,2,60),(3,3,60),(4,4,13),(5,5,16),(7,6,8),(8,6,9),(9,7,18),(10,7,19),(11,7,20),(12,7,21),(13,7,22),(14,7,23),(15,7,24),(16,7,25),(17,7,26),(18,7,37),(19,7,40),(20,7,42),(21,7,43),(22,7,57),(23,7,58),(24,8,18),(25,8,19),(26,8,20),(27,8,21),(28,8,22),(29,8,23),(30,8,24),(31,8,25),(32,8,26),(33,8,37),(34,8,40),(35,8,42),(36,8,43),(37,8,57),(38,8,58),(39,9,18),(40,9,19),(41,9,20),(42,9,21),(43,9,22),(44,9,23),(45,9,24),(46,9,25),(47,9,26),(48,9,37),(49,9,40),(50,9,42),(51,9,43),(52,9,57),(53,9,58),(54,10,17),(56,11,10),(55,11,59),(57,12,5),(58,12,6),(59,12,7),(60,13,14),(61,14,11),(62,14,12),(6,14,61),(63,15,4),(64,16,2),(65,17,1);
/*!40000 ALTER TABLE `worker_operations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `worker_payments`
--

DROP TABLE IF EXISTS `worker_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `worker_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(50) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_type` enum('Payment','Advance') NOT NULL DEFAULT 'Payment',
  `payment_method` varchar(50) NOT NULL DEFAULT 'Cash',
  `account_id` int(11) DEFAULT NULL,
  `reference_no` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_reversed` tinyint(1) NOT NULL DEFAULT 0,
  `reversed_at` datetime DEFAULT NULL,
  `reversed_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_number` (`payment_number`),
  KEY `worker_id` (`worker_id`),
  KEY `account_id` (`account_id`),
  KEY `created_by` (`created_by`),
  KEY `reversed_by` (`reversed_by`),
  CONSTRAINT `worker_payments_ibfk_1` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`),
  CONSTRAINT `worker_payments_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `cash_bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `worker_payments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `worker_payments_ibfk_4` FOREIGN KEY (`reversed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `worker_payments`
--

LOCK TABLES `worker_payments` WRITE;
/*!40000 ALTER TABLE `worker_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `worker_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workers`
--

DROP TABLE IF EXISTS `workers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `cnic` varchar(30) DEFAULT NULL,
  `worker_type` varchar(50) NOT NULL DEFAULT 'Piece Rate',
  `rate_basis` enum('element_wise','whole_sweater') NOT NULL DEFAULT 'whole_sweater',
  `department_id` int(11) DEFAULT NULL,
  `default_rate` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate_per_minute` decimal(8,2) NOT NULL DEFAULT 2.50,
  `joining_date` date DEFAULT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `workers_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workers`
--

LOCK TABLES `workers` WRITE;
/*!40000 ALTER TABLE `workers` DISABLE KEYS */;
INSERT INTO `workers` VALUES (1,'WRK-ADNAN','Adnan','0301-1111001',NULL,NULL,'Minute Rate','element_wise',1,0.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(2,'WRK-MOLVI','Molvi','0301-1111002',NULL,NULL,'Minute Rate','element_wise',1,0.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(3,'WRK-HASHIM','Hashim','0301-1111003',NULL,NULL,'Minute Rate','element_wise',1,0.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(4,'WRK-LALA','LALA','0301-1111004',NULL,NULL,'Piece Rate','whole_sweater',2,10.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(5,'WRK-UMAIR','Umair','0301-1111005',NULL,NULL,'Piece Rate','whole_sweater',3,8.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(6,'WRK-ALIRAZA','Ali Raza','0301-1111006',NULL,NULL,'Piece Rate','whole_sweater',4,12.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(7,'WRK-ASAD','Asad','0301-1111007',NULL,NULL,'Piece Rate','whole_sweater',5,16.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(8,'WRK-IRFAN','Irfan','0301-1111008',NULL,NULL,'Piece Rate','whole_sweater',5,15.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(9,'WRK-MITHU','Mithu','0301-1111009',NULL,NULL,'Piece Rate','whole_sweater',5,16.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(10,'WRK-BILAL','Bilal','0301-1111010',NULL,NULL,'Piece Rate','whole_sweater',6,10.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(11,'WRK-LATIF','Latif','0301-1111011',NULL,NULL,'Piece Rate','whole_sweater',7,5.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(12,'WRK-HUMZA','Humza','0301-1111012',NULL,NULL,'Piece Rate','whole_sweater',9,7.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(13,'WRK-LALAMP','Lala (Mall Press)','0301-1111013',NULL,NULL,'Piece Rate','whole_sweater',11,15.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(14,'WRK-OSAMA','Osama','0301-1111014',NULL,NULL,'Piece Rate','whole_sweater',13,5.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(15,'WRK-ALI','Ali','0301-1111015',NULL,NULL,'Piece Rate','whole_sweater',10,25.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(16,'WRK-BUTT','Butt','0301-1111016',NULL,NULL,'Piece Rate','whole_sweater',10,110.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57'),(17,'WRK-SALEEM','Saleem','0301-1111017',NULL,NULL,'Piece Rate','whole_sweater',10,12.00,2.50,'2026-11-12',0.00,NULL,1,'2026-11-12 17:20:57');
/*!40000 ALTER TABLE `workers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workflow_template_steps`
--

DROP TABLE IF EXISTS `workflow_template_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_template_steps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `operation_id` int(11) NOT NULL,
  `sequence` int(11) NOT NULL DEFAULT 1,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `operation_id` (`operation_id`),
  CONSTRAINT `workflow_template_steps_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `workflow_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workflow_template_steps_ibfk_2` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workflow_template_steps`
--

LOCK TABLES `workflow_template_steps` WRITE;
/*!40000 ALTER TABLE `workflow_template_steps` DISABLE KEYS */;
INSERT INTO `workflow_template_steps` VALUES (1,1,60,1,1),(2,1,13,2,1),(3,1,16,3,1),(4,1,8,4,1),(5,1,57,5,1),(6,1,22,6,1),(7,1,17,7,1),(8,1,59,8,1),(9,1,14,9,1),(10,1,61,10,1),(11,1,11,11,1),(12,2,60,1,1),(13,2,13,2,1),(14,2,16,3,1),(15,2,8,4,1),(16,2,34,5,1),(17,2,22,6,1),(18,2,17,7,1),(19,2,59,8,1),(20,2,10,9,1),(21,2,5,10,1),(22,2,14,11,1),(23,2,61,12,1),(24,2,11,13,1),(25,3,60,1,1),(26,3,13,2,1),(27,3,16,3,1),(28,3,8,4,1),(29,3,24,5,1),(30,3,40,6,1),(31,3,27,7,1),(32,3,18,8,1),(33,3,17,9,1),(34,3,59,10,1),(35,3,5,11,1),(36,3,14,12,1),(37,3,61,13,1),(38,3,11,14,1),(39,4,60,1,1),(40,4,13,2,1),(41,4,16,3,1),(42,4,8,4,1),(43,4,23,5,1),(44,4,22,6,1),(45,4,18,7,1),(46,4,17,8,1),(47,4,59,9,1),(48,4,1,10,1),(49,4,14,11,1),(50,4,61,12,1),(51,4,11,13,1);
/*!40000 ALTER TABLE `workflow_template_steps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workflow_templates`
--

DROP TABLE IF EXISTS `workflow_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `product_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workflow_templates`
--

LOCK TABLES `workflow_templates` WRITE;
/*!40000 ALTER TABLE `workflow_templates` DISABLE KEYS */;
INSERT INTO `workflow_templates` VALUES (1,'Gersi Gents Standard Workflow','Sweater','Standard workflow for Gersi Gents Standard Workflow',1),(2,'Coati Ladies Workflow','Cardigan / Coat','Standard workflow for Coati Ladies Workflow',1),(3,'Gent Fulzip Workflow','Zipper Jacket','Standard workflow for Gent Fulzip Workflow',1),(4,'Shawl Bazo Workflow','Shawl','Standard workflow for Shawl Bazo Workflow',1);
/*!40000 ALTER TABLE `workflow_templates` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-12 17:24:04
