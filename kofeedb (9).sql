-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 16, 2026 at 03:10 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kofeedb`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_in_photo` varchar(255) DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `time_out_photo` varchar(255) DEFAULT NULL,
  `status` enum('present','late','absent','on_leave','half_day') NOT NULL DEFAULT 'present',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `attendance_date`, `time_in`, `time_in_photo`, `time_out`, `time_out_photo`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(2, 2, '2026-08-29', '05:07:40', 'uploads/attendance/emp2_2026-08-29_in_1788037660.jpg', '05:08:13', 'uploads/attendance/emp2_2026-08-29_out_1788037693.jpg', 'present', NULL, '2026-08-29 21:07:40', '2026-08-29 21:08:13'),
(3, 3, '2026-08-30', '08:40:21', 'uploads/attendance/emp3_2026-08-30_in_1788050421.jpg', NULL, NULL, 'present', NULL, '2026-08-30 00:40:21', '2026-08-30 00:40:21'),
(4, 2, '2026-09-01', '11:21:00', NULL, '12:12:00', NULL, 'absent', 'Nag-ml kagabi', '2026-09-01 01:42:55', '2026-09-01 01:42:55'),
(5, 4, '2026-09-07', '23:25:58', 'uploads/attendance/emp4_2026-09-07_in_1788794758.jpg', NULL, NULL, 'present', NULL, '2026-09-07 15:25:58', '2026-09-07 15:25:58'),
(7, 4, '2026-09-13', '20:00:25', 'uploads/attendance/emp4_2026-09-13_in_1789300825.jpg', NULL, NULL, 'present', NULL, '2026-09-13 12:00:25', '2026-09-13 12:00:25'),
(8, 2, '2026-09-13', '20:07:18', 'uploads/attendance/emp2_2026-09-13_in_1789301238.jpg', NULL, NULL, 'present', NULL, '2026-09-13 12:07:18', '2026-09-13 12:07:18'),
(11, 4, '2026-09-14', '03:39:18', 'uploads/attendance/emp4_2026-09-14_in_1789414758.jpg', NULL, NULL, 'present', NULL, '2026-09-14 19:39:18', '2026-09-14 19:39:18'),
(12, 4, '2026-09-15', '13:59:11', 'uploads/attendance/emp4_2026-09-15_in_1789451951.jpg', NULL, NULL, 'present', NULL, '2026-09-15 05:59:11', '2026-09-15 05:59:11');

-- --------------------------------------------------------

--
-- Table structure for table `bids`
--

CREATE TABLE `bids` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `quoted_total` decimal(12,2) NOT NULL,
  `lead_time_days` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `status` enum('submitted','shortlisted','selected','rejected') NOT NULL DEFAULT 'submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bids`
--

INSERT INTO `bids` (`id`, `rfq_id`, `supplier_id`, `quoted_total`, `lead_time_days`, `notes`, `status`, `submitted_at`) VALUES
(1, 1, 1, 2232.00, 2, '', 'selected', '2026-08-24 19:47:08'),
(3, 4, 1, 0.01, 1, '', 'selected', '2026-08-25 07:15:26'),
(4, 5, 1, 2333.01, 1, '', 'selected', '2026-08-31 19:54:43'),
(6, 3, 1, 112001.00, 2, '', 'selected', '2026-08-31 20:19:04'),
(7, 6, 1, 3233.00, 2, 'dad', 'selected', '2026-08-31 20:51:03'),
(8, 7, 1, 2456.00, 1, '', 'selected', '2026-08-31 22:13:30'),
(9, 8, 1, 1000.00, 3, 'received', 'selected', '2026-09-01 02:22:04'),
(14, 9, 1, 20.00, 3, 'paki-bilis', 'selected', '2026-09-01 02:31:14'),
(15, 10, 1, 9999999999.99, 5, 'paki-bilis', 'selected', '2026-09-01 03:28:34'),
(17, 11, 1, 1.00, 1, 'ASAP needed', 'selected', '2026-09-01 03:47:26'),
(19, 12, 1, 1.00, 1, 'ASAP', 'selected', '2026-09-01 04:12:06'),
(20, 13, 1, 1.00, 1, '', 'selected', '2026-09-07 15:34:47'),
(21, 14, 1, 500.00, 1, '', 'selected', '2026-09-10 19:16:58'),
(22, 15, 1, 150.00, 1, '', 'selected', '2026-09-14 19:24:49');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(12) NOT NULL,
  `category_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `category_name`) VALUES
(1, 'Ice Coffee'),
(2, 'Hot Coffee'),
(3, 'Milk Tea'),
(4, 'Fruit Tea');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `employee_code` varchar(50) NOT NULL,
  `firstname` varchar(50) NOT NULL,
  `lastname` varchar(50) NOT NULL,
  `position` varchar(100) NOT NULL,
  `department` varchar(50) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `employment_type` varchar(30) DEFAULT NULL,
  `base_salary` decimal(10,2) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `user_id`, `employee_code`, `firstname`, `lastname`, `position`, `department`, `contact_number`, `email`, `hire_date`, `employment_type`, `base_salary`, `status`, `created_at`) VALUES
(1, 2, 'emp-002', 'Khylle', 'Roque', 'Cashier', 'hr', '0923248990', 'khyllechester.roque07@gmail.com', '2000-09-09', 'Full-time', 0.00, 'active', '2026-08-01 17:10:32'),
(2, 1, '#2322', 'Admin', 'User', 'Administrator', 'admin', '0923248990', 'admin@kofeecafe.local', '2026-08-30', 'Full-time', 20000.00, 'active', '2026-08-29 21:02:21'),
(3, 4, '2323', 'Hr', 'Test', 'Hr', 'hr', '2190319241', 'hr@gmail.com', '2026-08-30', 'Full-time', 23233.00, 'active', '2026-08-30 00:38:17'),
(4, 8, '1021', 'Crew', 'Test', 'Crew', 'crew', '098489184', 'crew@gmail.com', '2026-09-01', 'Contract', 20000.00, 'active', '2026-08-31 19:16:53'),
(5, 7, '2314', 'finance', 'testing', 'Financer', 'finance', '0923248990', 'finance@gmail.com', '2026-09-01', 'Full-time', 22353.00, 'active', '2026-08-31 19:18:44'),
(6, 6, '2141651', 'manager', 'test', 'Manager', 'manager', '98409818945', 'manager@gmail.com', '2026-09-01', 'Full-time', 25434.00, 'active', '2026-08-31 19:19:38'),
(8, 10, '2859', 'ops', 'test', 'Operations', 'ops', '87685568', 'ops@gmail.com', '2026-09-01', 'Full-time', 524363.00, 'active', '2026-08-31 19:33:41'),
(9, 11, '124125', 'Procurment', 'Testing', 'Officer', 'admin', '57978976', 'procurement@gmail.com', '2026-09-01', 'Full-time', 51473.00, 'active', '2026-08-31 19:34:39'),
(10, 13, '5235', 'Receiving', 'Testing', 'Warehouse', 'admin', '3646734', 'receiving@gmail.com', '2026-09-01', 'Full-time', 53443.99, 'active', '2026-08-31 19:36:38');

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipts`
--

CREATE TABLE `goods_receipts` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `received_by` int(11) NOT NULL,
  `status` enum('pending','partial','complete','discrepancy') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `goods_receipts`
--

INSERT INTO `goods_receipts` (`id`, `po_id`, `received_by`, `status`, `notes`, `received_at`) VALUES
(1, 5, 1, 'complete', ' | Resolution: Resolve', '2026-08-31 20:31:07'),
(2, 5, 1, 'complete', '', '2026-08-31 20:31:19'),
(3, 7, 1, 'complete', '', '2026-08-31 22:18:49'),
(4, 6, 1, 'complete', 'Thank you very much!', '2026-09-01 02:20:28'),
(5, 8, 1, 'complete', '', '2026-09-01 02:50:55'),
(6, 9, 13, 'complete', 'Thank you po!', '2026-09-01 03:34:15'),
(7, 10, 6, 'complete', '', '2026-09-01 03:44:05'),
(8, 11, 13, 'partial', 'Nice | Resolution: 1', '2026-09-01 03:50:19'),
(9, 11, 13, 'partial', ' | Resolution: replacement', '2026-09-01 03:50:42'),
(10, 12, 13, 'complete', '', '2026-09-01 04:13:31'),
(11, 13, 13, 'partial', ' | Resolution: replacement', '2026-09-07 15:35:53'),
(12, 13, 13, 'partial', ' | Resolution: replacement', '2026-09-07 15:36:05'),
(13, 13, 13, 'complete', '', '2026-09-07 15:36:58'),
(14, 14, 13, 'complete', '', '2026-09-10 19:18:06'),
(15, 15, 13, 'complete', '', '2026-09-14 19:25:36');

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipt_items`
--

CREATE TABLE `goods_receipt_items` (
  `id` int(11) NOT NULL,
  `grn_id` int(11) NOT NULL,
  `requisition_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `ordered_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `received_qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `item_condition` enum('good','damaged','rejected') NOT NULL DEFAULT 'good',
  `discrepancy_notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `goods_receipt_items`
--

INSERT INTO `goods_receipt_items` (`id`, `grn_id`, `requisition_item_id`, `item_name`, `unit`, `ordered_qty`, `received_qty`, `item_condition`, `discrepancy_notes`) VALUES
(1, 1, 5, 'plastic cups', 'bundle', 56.00, 56.00, 'damaged', NULL),
(2, 5, 11, 'Arabica Beans', 'g', 20.00, 20.00, 'good', NULL),
(3, 6, 12, 'Excelsa Beans', 'g', 10.00, 10.00, 'good', 'Thank you!'),
(4, 8, 13, 'Arabica Beans', 'g', 500.00, 1.00, 'good', NULL),
(5, 9, 13, 'Arabica Beans', 'g', 500.00, 499.00, 'good', NULL),
(6, 10, 14, 'Excelsa Beans', 'g', 500.00, 500.00, 'good', 'thank you'),
(7, 11, 15, 'Robusta Beans', 'g', 100000.00, 1.00, 'good', NULL),
(8, 12, 15, 'Robusta Beans', 'g', 100000.00, 99999.00, 'good', NULL),
(9, 14, 16, 'Ice', '500', 500.00, 500.00, 'good', NULL),
(10, 15, 17, 'Espresso', 'ml', 150.00, 150.00, 'good', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `hr_requests`
--

CREATE TABLE `hr_requests` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `request_type` varchar(100) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hr_requests`
--

INSERT INTO `hr_requests` (`id`, `employee_id`, `request_type`, `details`, `status`, `reviewed_by`, `reviewed_at`, `created_at`) VALUES
(2, 2, 'Inventory Adjustment', 'Ingredient ID: 6; Ingredient: Koya Dsd; Qty: 22; Type: Inventory Adjustment; Reason: Stock set request pending approval', 'rejected', 1, '2026-09-01 05:52:49', '2026-09-01 02:09:36'),
(3, 2, 'Inventory Adjustment', 'Ingredient ID: 6; Ingredient: Fresj; Qty: 0; Type: Inventory Adjustment; Reason: Stock set request pending approval', 'rejected', 4, '2026-09-01 09:21:27', '2026-09-01 06:05:42'),
(4, 2, 'Inventory Adjustment', 'Ingredient ID: 6; Ingredient: Fresj; Qty: 0; Type: Inventory Adjustment; Reason: Stock set to 0 - approved', 'rejected', 4, '2026-09-01 09:21:25', '2026-09-01 06:06:47'),
(5, 2, 'Inventory Adjustment', 'Ingredient ID: 6; Ingredient: Fresj; Qty: 0; Type: Inventory Adjustment; Reason: Stock set to 0 - approved', 'rejected', 4, '2026-09-01 09:21:27', '2026-09-01 06:06:52'),
(6, 2, 'Inventory Adjustment', 'Ingredient ID: 5; Ingredient: Salted Caramel; Qty: 2000; Type: Inventory Adjustment; Reason: Stock set to 2000 - approved', 'rejected', 4, '2026-09-01 09:21:28', '2026-09-01 06:07:13'),
(7, 3, 'Inventory Restock', 'Ingredient ID: 1; Ingredient: Libica Beans; Qty: 52; Type: Inventory Restock; Reason: Restock request pending approval', 'completed', 1, '2026-09-13 18:04:33', '2026-09-01 09:27:51'),
(8, 8, 'Inventory Adjustment', 'Ingredient ID: 6; Ingredient: Arabica Beans; Qty: 1000; Type: Inventory Adjustment; Reason: Stock set to 1000 - approved', 'completed', 1, '2026-09-13 18:04:34', '2026-09-01 10:41:36'),
(9, 4, 'Inventory Adjustment', 'Ingredient ID: 13; Ingredient: Condensed Milk; Qty: 10000; Type: Inventory Adjustment; Reason: Stock set to 10000 - approved', 'completed', 1, '2026-09-13 18:04:34', '2026-09-11 03:26:22');

-- --------------------------------------------------------

--
-- Table structure for table `ingredients`
--

CREATE TABLE `ingredients` (
  `id` int(11) NOT NULL,
  `cat_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `brand` varchar(150) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reorder_at` decimal(10,2) NOT NULL DEFAULT 5.00,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredients`
--

INSERT INTO `ingredients` (`id`, `cat_id`, `name`, `brand`, `unit`, `quantity`, `reorder_at`, `archived_at`, `created_at`, `updated_at`) VALUES
(1, 1, 'Libica Beans', 'Locals', 'g', 100.00, 5.00, NULL, '2026-08-01 18:26:47', '2026-09-11 03:26:43'),
(5, 1, 'Robusta Beans', 'Locals', 'g', 100.00, 10.00, NULL, '2026-08-05 05:59:04', '2026-09-11 03:26:43'),
(6, 1, 'Arabica Beans', 'Local', 'g', 1000.00, 0.00, NULL, '2026-08-07 14:45:37', '2026-09-13 18:04:25'),
(7, 1, 'Excelsa Beans', 'Local', 'g', 100.00, 5.00, NULL, '2026-09-01 06:10:19', '2026-09-11 03:26:43'),
(8, 6, 'Ice', 'Nestle', 'g', 96.00, 100.00, NULL, '2026-09-11 03:15:15', '2026-09-13 20:06:29'),
(9, 1, 'Espresso', NULL, 'ml', 246.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-15 03:25:36'),
(10, 1, 'Brewed Coffee', NULL, 'ml', 100.00, 2000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(11, 6, 'Water', NULL, 'ml', 100.00, 5000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(12, 2, 'Whole Milk', NULL, 'ml', 96.00, 2000.00, NULL, '2026-09-11 03:25:44', '2026-09-13 20:06:29'),
(13, 2, 'Condensed Milk', NULL, 'ml', 10000.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-13 18:04:26'),
(14, 2, 'Heavy Cream', NULL, 'ml', 100.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(15, 6, 'Whipped Cream', NULL, 'g', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(16, 3, 'Chocolate Sauce', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(17, 3, 'White Chocolate Sauce', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(18, 3, 'Caramel Syrup', NULL, 'ml', 96.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-13 20:06:29'),
(19, 3, 'Vanilla Syrup', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(20, 3, 'Irish Cream Syrup', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(21, 4, 'Matcha Powder', NULL, 'g', 100.00, 250.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(22, 6, 'Cocoa Powder', NULL, 'g', 100.00, 250.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(23, 6, 'Cinnamon Powder', NULL, 'g', 100.00, 100.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(24, 6, 'Brown Sugar', NULL, 'g', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(25, 6, 'Sugar', NULL, 'g', 100.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(26, 2, 'Ice Cream', NULL, 'g', 100.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43'),
(40, 1, 'Salted Caramel', 'ios', '12', 1.00, 123.00, NULL, '2026-09-11 04:01:48', '2026-09-11 04:01:48');

-- --------------------------------------------------------

--
-- Table structure for table `ingredient_categories`
--

CREATE TABLE `ingredient_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(10) NOT NULL DEFAULT '?'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredient_categories`
--

INSERT INTO `ingredient_categories` (`id`, `name`, `icon`) VALUES
(1, 'Coffee', '☕'),
(2, 'Milk', '🥛'),
(3, 'Syrups', '🧊'),
(4, 'Tea', '🍵'),
(5, 'Bakery', '🥐'),
(6, 'Other', '📦');

-- --------------------------------------------------------

--
-- Table structure for table `ingredient_usage_log`
--

CREATE TABLE `ingredient_usage_log` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `used_qty` decimal(10,2) NOT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredient_usage_log`
--

INSERT INTO `ingredient_usage_log` (`id`, `order_id`, `ingredient_id`, `used_qty`, `processed_by`, `created_at`) VALUES
(1, 6, 8, 1.00, 8, '2026-09-11 03:29:49'),
(2, 6, 9, 1.00, 8, '2026-09-11 03:29:49'),
(3, 6, 12, 1.00, 8, '2026-09-11 03:29:49'),
(4, 6, 18, 1.00, 8, '2026-09-11 03:29:49'),
(5, 7, 8, 1.00, 8, '2026-09-11 04:01:06'),
(6, 7, 9, 1.00, 8, '2026-09-11 04:01:06'),
(7, 7, 12, 1.00, 8, '2026-09-11 04:01:06'),
(8, 7, 18, 1.00, 8, '2026-09-11 04:01:06'),
(9, 8, 8, 1.00, 8, '2026-09-13 20:02:38'),
(10, 8, 9, 1.00, 8, '2026-09-13 20:02:38'),
(11, 8, 12, 1.00, 8, '2026-09-13 20:02:38'),
(12, 8, 18, 1.00, 8, '2026-09-13 20:02:38'),
(13, 11, 8, 1.00, 8, '2026-09-13 20:06:29'),
(14, 11, 9, 1.00, 8, '2026-09-13 20:06:29'),
(15, 11, 12, 1.00, 8, '2026-09-13 20:06:29'),
(16, 11, 18, 1.00, 8, '2026-09-13 20:06:29');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_requests`
--

CREATE TABLE `inventory_requests` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','completed','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `invoice_number` varchar(60) NOT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','matched','disputed','approved','paid','cancelled') NOT NULL DEFAULT 'pending',
  `match_notes` varchar(255) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `po_id`, `supplier_id`, `invoice_number`, `invoice_date`, `due_date`, `subtotal`, `tax_amount`, `total_amount`, `status`, `match_notes`, `uploaded_by`, `created_at`) VALUES
(1, 3, 1, '56256', '2026-09-01', '2026-09-01', 112000.00, 0.00, 112000.00, 'disputed', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 1, '2026-08-31 18:34:01'),
(2, 1, 1, '56256', NULL, NULL, 0.00, 233.00, 233.00, 'disputed', '\"Chocolate\" invoiced for 0.25 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱233.00 differs from PO total ₱2,232.00 by 89.6% (tolerance is 3.0%).', 1, '2026-08-31 20:14:53'),
(3, 4, 1, '56256', NULL, NULL, 2806.00, 2122.00, 4928.00, 'cancelled', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 12, '2026-08-31 20:26:11'),
(4, 5, 1, '2323', '2026-09-01', '2026-09-01', 11200.00, 2323.00, 13523.00, 'paid', '\"plastic cups\" invoiced for 56.00 but only 0 received. | Invoice total ₱13,523.00 differs from PO total ₱112,001.00 by 87.9% (tolerance is 3.0%). | Override: Ovver', 1, '2026-08-31 20:32:32'),
(5, 7, 1, '56256', '2026-09-01', '2026-09-04', 2454.00, 0.00, 2454.00, 'paid', '\"Arabica Beans\" invoiced for 23.00 but only 0 received. | \"Excelsa Beans\" invoiced for 33.00 but only 0 received. | Override: Taxes', 7, '2026-08-31 22:19:36'),
(6, 9, 1, '-1111', NULL, NULL, 50.00, 0.00, 50.00, 'pending', NULL, 7, '2026-09-01 03:35:16'),
(7, 9, 1, '1', '2026-09-01', '2026-09-05', 50.00, 12.00, 62.00, 'paid', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%). | Override: ad', 7, '2026-09-01 03:35:43'),
(8, 10, 1, '10', '2026-09-01', '2026-09-01', 250000.00, 30.00, 250030.00, 'paid', '\"Koya Dsd\" invoiced for 500.00 but only 0 received. | Invoice total ₱250,030.00 differs from PO total ₱1,000.00 by 24903.0% (tolerance is 3.0%). | Override: done', 7, '2026-09-01 03:52:30'),
(9, 12, 1, '10', '2026-09-01', '2026-09-01', 500.00, 0.00, 500.00, 'paid', 'Invoice total ₱500.00 differs from PO total ₱1.00 by 49900.0% (tolerance is 3.0%). | Override: approve', 7, '2026-09-01 04:14:18'),
(10, 13, 1, '13', '2026-09-07', '2026-09-07', 100000.00, 1000.00, 101000.00, 'paid', 'Invoice total ₱101,000.00 differs from PO total ₱1.00 by 10099900.0% (tolerance is 3.0%). | Override: required', 7, '2026-09-07 15:37:57'),
(11, 14, 1, '14', '2026-09-11', '2026-09-11', 500.00, 0.00, 500.00, 'paid', 'Matched clean: invoice ₱500.00 vs PO ₱500.00 (0.0% variance).', 7, '2026-09-10 19:18:44'),
(12, 15, 1, '15', '2026-09-15', '2026-09-15', 150.00, 0.00, 150.00, 'paid', 'Matched clean: invoice ₱150.00 vs PO ₱150.00 (0.0% variance).', 7, '2026-09-14 19:26:10');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `requisition_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`id`, `invoice_id`, `requisition_item_id`, `item_name`, `qty`, `unit_price`, `line_total`) VALUES
(1, 1, 5, 'plastic cups', 56.00, 2000.00, 112000.00),
(2, 2, 1, 'Chocolate', 0.25, 0.00, 0.00),
(3, 3, 7, 'Koya Dsd', 23.00, 122.00, 2806.00),
(4, 4, 5, 'plastic cups', 56.00, 200.00, 11200.00),
(5, 5, 9, 'Arabica Beans', 23.00, 45.00, 1035.00),
(6, 5, 10, 'Excelsa Beans', 33.00, 43.00, 1419.00),
(7, 6, 12, 'Excelsa Beans', 10.00, 5.00, 50.00),
(8, 7, 12, 'Excelsa Beans', 10.00, 5.00, 50.00),
(9, 8, 8, 'Koya Dsd', 500.00, 500.00, 250000.00),
(10, 9, 14, 'Excelsa Beans', 500.00, 1.00, 500.00),
(11, 10, 15, 'Robusta Beans', 100000.00, 1.00, 100000.00),
(12, 11, 16, 'Ice', 500.00, 1.00, 500.00),
(13, 12, 17, 'Espresso', 150.00, 1.00, 150.00);

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type` varchar(30) NOT NULL DEFAULT 'Vacation',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_count` int(11) NOT NULL DEFAULT 1,
  `reason` varchar(500) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_requests`
--

INSERT INTO `leave_requests` (`id`, `employee_id`, `leave_type`, `start_date`, `end_date`, `days_count`, `reason`, `status`, `reviewed_by`, `reviewed_at`, `created_at`) VALUES
(2, 2, 'Vacation', '2026-08-30', '2026-09-04', 6, '', 'rejected', 1, '2026-08-30 05:10:55', '2026-08-29 21:09:33');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `recipient_user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `recipient_user_id`, `type`, `title`, `message`, `link_url`, `is_read`, `created_at`) VALUES
(1, 7, 'invoice_created', 'Invoice 56256 logged for PO #3', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:01'),
(2, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:10'),
(3, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:17'),
(4, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:18'),
(5, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:20'),
(6, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:21'),
(7, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱2,333.01 on RFQ #5', 'rfq.php?id=5', 1, '2026-08-31 20:11:51'),
(8, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱2,333.01 on RFQ #5', 'rfq.php?id=5', 0, '2026-08-31 20:11:51'),
(9, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱2,333.01 on RFQ #5', 'rfq.php?id=5', 0, '2026-08-31 20:11:51'),
(10, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 20:14:22'),
(11, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 20:14:22'),
(12, 7, 'invoice_created', 'Invoice 56256 logged for PO #1', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:53'),
(13, 11, 'invoice_created', 'Invoice 56256 logged for PO #1', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:53'),
(14, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Chocolate\" invoiced for 0.25 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱233.00 differs from PO total ₱2,232.00 by 89.6% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:56'),
(15, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Chocolate\" invoiced for 0.25 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱233.00 differs from PO total ₱2,232.00 by 89.6% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:56'),
(16, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱112,001.00 on RFQ #3', 'rfq.php?id=3', 1, '2026-08-31 20:19:04'),
(17, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱112,001.00 on RFQ #3', 'rfq.php?id=3', 0, '2026-08-31 20:19:04'),
(18, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱112,001.00 on RFQ #3', 'rfq.php?id=3', 0, '2026-08-31 20:19:04'),
(19, 1, 'invoice_created', 'Invoice 56256 submitted for PO #4', 'Selecta submitted an invoice — ready for 3-way match.', 'three_way_match.php?invoice_id=3', 1, '2026-08-31 20:26:11'),
(20, 7, 'invoice_created', 'Invoice 56256 submitted for PO #4', 'Selecta submitted an invoice — ready for 3-way match.', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:11'),
(21, 11, 'invoice_created', 'Invoice 56256 submitted for PO #4', 'Selecta submitted an invoice — ready for 3-way match.', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:11'),
(22, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:36'),
(23, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:36'),
(24, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:43'),
(25, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:43'),
(26, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:45'),
(27, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:45'),
(28, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:11'),
(29, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:11'),
(30, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:14'),
(31, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:14'),
(32, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #5', 'goods_receipts.php?po_id=5', 1, '2026-08-31 20:29:53'),
(33, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #5', 'goods_receipts.php?po_id=5', 0, '2026-08-31 20:29:53'),
(34, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #5', 'goods_receipts.php?po_id=5', 0, '2026-08-31 20:29:53'),
(35, 13, 'grn_discrepancy', 'Delivery discrepancy on PO #5', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=5', 0, '2026-08-31 20:31:07'),
(36, 7, 'invoice_created', 'Invoice 2323 logged for PO #5', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:32'),
(37, 11, 'invoice_created', 'Invoice 2323 logged for PO #5', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:32'),
(38, 7, 'invoice_exception', '3-way match exception on Invoice 2323', '\"plastic cups\" invoiced for 56.00 but only 0 received. | Invoice total ₱13,523.00 differs from PO total ₱112,001.00 by 87.9% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:35'),
(39, 11, 'invoice_exception', '3-way match exception on Invoice 2323', '\"plastic cups\" invoiced for 56.00 but only 0 received. | Invoice total ₱13,523.00 differs from PO total ₱112,001.00 by 87.9% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:35'),
(40, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-08-31 20:50:43'),
(41, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱3,233.00 on RFQ #6', 'rfq.php?id=6', 1, '2026-08-31 20:51:03'),
(42, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱3,233.00 on RFQ #6', 'rfq.php?id=6', 0, '2026-08-31 20:51:03'),
(43, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱3,233.00 on RFQ #6', 'rfq.php?id=6', 0, '2026-08-31 20:51:03'),
(44, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Coffee Beans — ₱2,454.00', 'requisitions.php', 0, '2026-08-31 22:12:35'),
(45, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Coffee Beans — ₱2,454.00', 'requisitions.php', 0, '2026-08-31 22:12:35'),
(46, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Coffee Beans — ₱2,454.00', 'requisitions.php', 0, '2026-08-31 22:12:35'),
(47, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-08-31 22:12:59'),
(48, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱2,456.00 on RFQ #7', 'rfq.php?id=7', 1, '2026-08-31 22:13:30'),
(49, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱2,456.00 on RFQ #7', 'rfq.php?id=7', 0, '2026-08-31 22:13:30'),
(50, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱2,456.00 on RFQ #7', 'rfq.php?id=7', 0, '2026-08-31 22:13:30'),
(51, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #7', 'goods_receipts.php?po_id=7', 1, '2026-08-31 22:14:29'),
(52, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #7', 'goods_receipts.php?po_id=7', 0, '2026-08-31 22:14:29'),
(53, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #7', 'goods_receipts.php?po_id=7', 0, '2026-08-31 22:14:29'),
(54, 1, 'invoice_created', 'Invoice 56256 logged for PO #7', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=5', 1, '2026-08-31 22:19:36'),
(55, 11, 'invoice_created', 'Invoice 56256 logged for PO #7', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=5', 0, '2026-08-31 22:19:36'),
(56, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Arabica Beans\" invoiced for 23.00 but only 0 received. | \"Excelsa Beans\" invoiced for 33.00 but only 0 received.', 'three_way_match.php?invoice_id=5', 0, '2026-08-31 22:20:15'),
(57, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Arabica Beans\" invoiced for 23.00 but only 0 received. | \"Excelsa Beans\" invoiced for 33.00 but only 0 received.', 'three_way_match.php?invoice_id=5', 0, '2026-08-31 22:20:15'),
(58, 12, 'payment_advice', '💸 Payment sent', 'Payment of ₱2,454.00 for Invoice 56256 has been completed.', 'supplier_portal.php', 0, '2026-08-31 22:23:03'),
(59, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #6', 'goods_receipts.php?po_id=6', 1, '2026-09-01 02:16:21'),
(60, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #6', 'goods_receipts.php?po_id=6', 0, '2026-09-01 02:16:21'),
(61, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #6', 'goods_receipts.php?po_id=6', 0, '2026-09-01 02:16:21'),
(62, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 02:21:49'),
(63, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:25'),
(64, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:25'),
(65, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:25'),
(66, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:29'),
(67, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:29'),
(68, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:29'),
(69, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:36'),
(70, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:36'),
(71, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:36'),
(72, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:44'),
(73, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:44'),
(74, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:44'),
(75, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Iphone 17 pro — ₱400.00', 'requisitions.php', 0, '2026-09-01 02:26:53'),
(76, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Iphone 17 pro — ₱400.00', 'requisitions.php', 0, '2026-09-01 02:26:53'),
(77, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Iphone 17 pro — ₱400.00', 'requisitions.php', 0, '2026-09-01 02:26:53'),
(78, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 02:30:57'),
(79, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #8', 'goods_receipts.php?po_id=8', 1, '2026-09-01 02:35:15'),
(80, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #8', 'goods_receipts.php?po_id=8', 0, '2026-09-01 02:35:15'),
(81, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #8', 'goods_receipts.php?po_id=8', 0, '2026-09-01 02:35:15'),
(82, 1, 'requisition_filed', '📋 New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 1, '2026-09-01 03:25:21'),
(83, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 0, '2026-09-01 03:25:21'),
(84, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 0, '2026-09-01 03:25:21'),
(85, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 0, '2026-09-01 03:25:21'),
(86, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 03:28:01'),
(87, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 03:31:08'),
(88, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 03:31:08'),
(89, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 03:31:08'),
(90, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #9', 'goods_receipts.php?po_id=9', 1, '2026-09-01 03:33:44'),
(91, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #9', 'goods_receipts.php?po_id=9', 0, '2026-09-01 03:33:44'),
(92, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #9', 'goods_receipts.php?po_id=9', 0, '2026-09-01 03:33:44'),
(93, 1, 'invoice_created', 'Invoice -1111 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=6', 1, '2026-09-01 03:35:16'),
(94, 11, 'invoice_created', 'Invoice -1111 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=6', 0, '2026-09-01 03:35:16'),
(95, 1, 'invoice_created', 'Invoice 1 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:35:43'),
(96, 11, 'invoice_created', 'Invoice 1 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:35:43'),
(97, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:11'),
(98, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:11'),
(99, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:19'),
(100, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:19'),
(101, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:21'),
(102, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:21'),
(103, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:33'),
(104, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:33'),
(105, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:33'),
(106, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:33'),
(107, 12, 'payment_advice', '💸 Payment sent', 'Payment of ₱62.00 for Invoice 1 has been completed.', 'supplier_portal.php', 0, '2026-09-01 03:37:40'),
(108, 1, 'requisition_filed', '📋 New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 1, '2026-09-01 03:45:12'),
(109, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 0, '2026-09-01 03:45:12'),
(110, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 0, '2026-09-01 03:45:12'),
(111, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 0, '2026-09-01 03:45:12'),
(112, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 03:47:12'),
(113, 1, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1.00 on RFQ #11', 'rfq.php?id=11', 1, '2026-09-01 03:47:58'),
(114, 6, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1.00 on RFQ #11', 'rfq.php?id=11', 0, '2026-09-01 03:47:58'),
(115, 11, 'bid_submitted', '📤 New quote submitted', 'Selecta quoted ₱1.00 on RFQ #11', 'rfq.php?id=11', 0, '2026-09-01 03:47:58'),
(116, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #11', 'goods_receipts.php?po_id=11', 1, '2026-09-01 03:49:27'),
(117, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #11', 'goods_receipts.php?po_id=11', 0, '2026-09-01 03:49:27'),
(118, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #11', 'goods_receipts.php?po_id=11', 0, '2026-09-01 03:49:27'),
(119, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #11', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=11', 1, '2026-09-01 03:50:19'),
(120, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #11', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=11', 1, '2026-09-01 03:50:42'),
(121, 1, 'invoice_created', 'Invoice 10 logged for PO #10', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=8', 1, '2026-09-01 03:52:30'),
(122, 11, 'invoice_created', 'Invoice 10 logged for PO #10', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=8', 0, '2026-09-01 03:52:30'),
(123, 1, 'invoice_exception', '3-way match exception on Invoice 10', '\"Koya Dsd\" invoiced for 500.00 but only 0 received. | Invoice total ₱250,030.00 differs from PO total ₱1,000.00 by 24903.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=8', 1, '2026-09-01 03:52:38'),
(124, 11, 'invoice_exception', '3-way match exception on Invoice 10', '\"Koya Dsd\" invoiced for 500.00 but only 0 received. | Invoice total ₱250,030.00 differs from PO total ₱1,000.00 by 24903.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=8', 0, '2026-09-01 03:52:38'),
(125, 12, 'payment_advice', '💸 Payment sent', 'Payment of ₱250,030.00 for Invoice 10 has been completed.', 'supplier_portal.php', 0, '2026-09-01 03:53:54'),
(126, 1, 'requisition_filed', '📋 New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 1, '2026-09-01 04:09:17'),
(127, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 0, '2026-09-01 04:09:17'),
(128, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 0, '2026-09-01 04:09:17'),
(129, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 0, '2026-09-01 04:09:17'),
(130, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 04:11:57'),
(131, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #12', 'goods_receipts.php?po_id=12', 1, '2026-09-01 04:12:47'),
(132, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #12', 'goods_receipts.php?po_id=12', 0, '2026-09-01 04:12:47'),
(133, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #12', 'goods_receipts.php?po_id=12', 0, '2026-09-01 04:12:47'),
(134, 1, 'invoice_created', 'Invoice 10 logged for PO #12', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=9', 1, '2026-09-01 04:14:18'),
(135, 11, 'invoice_created', 'Invoice 10 logged for PO #12', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=9', 0, '2026-09-01 04:14:18'),
(136, 1, 'invoice_exception', '3-way match exception on Invoice 10', 'Invoice total ₱500.00 differs from PO total ₱1.00 by 49900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=9', 1, '2026-09-01 04:15:38'),
(137, 11, 'invoice_exception', '3-way match exception on Invoice 10', 'Invoice total ₱500.00 differs from PO total ₱1.00 by 49900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=9', 0, '2026-09-01 04:15:38'),
(138, 12, 'payment_advice', '💸 Payment sent', 'Payment of ₱500.00 for Invoice 10 has been completed.', 'supplier_portal.php', 0, '2026-09-01 04:16:19'),
(139, 1, 'requisition_filed', '📋 New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 1, '2026-09-07 15:26:55'),
(140, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 0, '2026-09-07 15:26:55'),
(141, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 0, '2026-09-07 15:26:55'),
(142, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 0, '2026-09-07 15:26:55'),
(143, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-07 15:34:42'),
(144, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #13', 'goods_receipts.php?po_id=13', 1, '2026-09-07 15:35:19'),
(145, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #13', 'goods_receipts.php?po_id=13', 0, '2026-09-07 15:35:19'),
(146, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #13', 'goods_receipts.php?po_id=13', 0, '2026-09-07 15:35:19'),
(147, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #13', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=13', 1, '2026-09-07 15:35:53'),
(148, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #13', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=13', 1, '2026-09-07 15:36:05'),
(149, 1, 'invoice_created', 'Invoice 13 logged for PO #13', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=10', 1, '2026-09-07 15:37:57'),
(150, 11, 'invoice_created', 'Invoice 13 logged for PO #13', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=10', 0, '2026-09-07 15:37:57'),
(151, 1, 'invoice_exception', '3-way match exception on Invoice 13', 'Invoice total ₱101,000.00 differs from PO total ₱1.00 by 10099900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=10', 1, '2026-09-07 15:41:50'),
(152, 11, 'invoice_exception', '3-way match exception on Invoice 13', 'Invoice total ₱101,000.00 differs from PO total ₱1.00 by 10099900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=10', 0, '2026-09-07 15:41:50'),
(153, 12, 'payment_advice', '💸 Payment sent', 'Payment of ₱101,000.00 for Invoice 13 has been completed.', 'supplier_portal.php', 0, '2026-09-07 15:43:18'),
(154, 1, 'requisition_filed', '📋 New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 1, '2026-09-10 19:15:53'),
(155, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 0, '2026-09-10 19:15:53'),
(156, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 0, '2026-09-10 19:15:53'),
(157, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 0, '2026-09-10 19:15:53'),
(158, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-10 19:16:41'),
(159, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #14', 'goods_receipts.php?po_id=14', 1, '2026-09-10 19:17:24'),
(160, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #14', 'goods_receipts.php?po_id=14', 0, '2026-09-10 19:17:24'),
(161, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #14', 'goods_receipts.php?po_id=14', 0, '2026-09-10 19:17:24'),
(162, 1, 'invoice_created', 'Invoice 14 logged for PO #14', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=11', 1, '2026-09-10 19:18:44'),
(163, 11, 'invoice_created', 'Invoice 14 logged for PO #14', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=11', 0, '2026-09-10 19:18:44'),
(164, 12, 'payment_advice', '💸 Payment sent', 'Payment of ₱500.00 for Invoice 14 has been completed.', 'supplier_portal.php', 0, '2026-09-10 19:19:11'),
(165, 1, 'requisition_filed', '📋 New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 1, '2026-09-14 19:23:49'),
(166, 6, 'requisition_filed', '📋 New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 0, '2026-09-14 19:23:49'),
(167, 7, 'requisition_filed', '📋 New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 0, '2026-09-14 19:23:49'),
(168, 11, 'requisition_filed', '📋 New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 0, '2026-09-14 19:23:49'),
(169, 12, 'rfq_invite', '📨 New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-14 19:24:39'),
(170, 1, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #15', 'goods_receipts.php?po_id=15', 1, '2026-09-14 19:25:12'),
(171, 6, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #15', 'goods_receipts.php?po_id=15', 0, '2026-09-14 19:25:12'),
(172, 13, 'po_shipped', '🚚 Order shipped', 'Selecta shipped PO #15', 'goods_receipts.php?po_id=15', 0, '2026-09-14 19:25:12'),
(173, 1, 'invoice_created', 'Invoice 15 logged for PO #15', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=12', 1, '2026-09-14 19:26:10'),
(174, 11, 'invoice_created', 'Invoice 15 logged for PO #15', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=12', 0, '2026-09-14 19:26:10'),
(175, 12, 'payment_advice', '💸 Payment sent', 'Payment of ₱150.00 for Invoice 15 has been completed.', 'supplier_portal.php', 0, '2026-09-14 19:26:42');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(12) NOT NULL,
  `user_id` int(50) NOT NULL,
  `total_amount` int(50) NOT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'dine-in',
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` date NOT NULL DEFAULT curdate(),
  `stock_deducted` tinyint(1) NOT NULL DEFAULT 0,
  `ingredients_deducted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `total_amount`, `payment_method`, `status`, `created_at`, `stock_deducted`, `ingredients_deducted_at`) VALUES
(1, 1, 246, 'Dine In', 'cancelled', '2026-08-04', 0, NULL),
(2, 1, 2324, 'Dine In', 'cancelled', '2026-08-14', 0, NULL),
(3, 1, 2324, 'Dine In', 'completed', '2026-09-01', 0, NULL),
(4, 8, 6800, 'Dine In', 'cancelled', '2026-09-11', 0, NULL),
(5, 8, 140, 'Dine In', 'cancelled', '2026-09-11', 0, NULL),
(6, 8, 140, 'Dine In', 'completed', '2026-09-11', 0, '2026-09-11 03:29:49'),
(7, 8, 140, 'Dine In', 'completed', '2026-09-11', 0, '2026-09-11 04:01:06'),
(8, 8, 275, 'Dine In', 'completed', '2026-09-13', 0, '2026-09-13 20:02:38'),
(9, 8, 450, 'Dine In', 'completed', '2026-09-13', 0, '2026-09-13 20:03:15'),
(10, 8, 2250, 'Dine In', 'completed', '2026-09-13', 0, '2026-09-13 20:04:43'),
(11, 8, 140, 'Dine In', 'completed', '2026-09-13', 0, '2026-09-13 20:06:29'),
(13, 10, 23, 'Dine In', 'cancelled', '2026-09-15', 0, NULL),
(15, 10, 23, 'PayMongo', 'cancelled', '2026-09-15', 0, NULL),
(16, 10, 46, 'PayMongo', 'cancelled', '2026-09-15', 0, NULL),
(17, 10, 23, 'PayMongo', 'completed', '2026-09-15', 0, '2026-09-15 03:13:41'),
(18, 8, 23, 'PayMongo', 'completed', '2026-09-15', 0, '2026-09-15 03:41:37'),
(19, 10, 23, 'Dine In', 'completed', '2026-09-15', 0, '2026-09-15 14:00:23'),
(20, 10, 23, 'PayMongo', 'completed', '2026-09-15', 0, '2026-09-15 05:04:30'),
(21, 8, 53, 'Dine In', 'completed', '2026-09-15', 0, '2026-09-15 14:00:22'),
(22, 10, 23, 'Dine In', 'completed', '2026-09-15', 0, '2026-09-15 14:44:13'),
(23, 10, 23, 'PayMongo', 'completed', '2026-09-15', 0, '2026-09-15 14:44:06');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(50) NOT NULL,
  `product_id` int(50) NOT NULL,
  `size` enum('small','large') NOT NULL DEFAULT 'small',
  `quantity` int(50) NOT NULL,
  `price` int(50) NOT NULL,
  `subtotal` int(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `size`, `quantity`, `price`, `subtotal`) VALUES
(15, 13, 7, 'small', 1, 23, 23),
(17, 15, 7, 'small', 1, 23, 23),
(18, 16, 7, 'small', 2, 23, 46),
(19, 17, 8, 'small', 1, 23, 23),
(20, 18, 8, 'small', 1, 23, 23),
(21, 19, 8, 'small', 1, 23, 23),
(22, 20, 8, 'small', 1, 23, 23),
(23, 21, 8, 'small', 1, 53, 53),
(24, 22, 8, 'small', 1, 23, 23),
(25, 23, 8, 'small', 1, 23, 23);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('bank_transfer','check','cash','online') NOT NULL DEFAULT 'bank_transfer',
  `reference_no` varchar(80) DEFAULT NULL,
  `status` enum('scheduled','completed','failed','cancelled') NOT NULL DEFAULT 'scheduled',
  `notes` varchar(255) DEFAULT NULL,
  `paid_by` int(11) NOT NULL,
  `scheduled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `invoice_id`, `po_id`, `amount`, `payment_method`, `reference_no`, `status`, `notes`, `paid_by`, `scheduled_at`, `completed_at`) VALUES
(1, 4, 5, 13523.00, 'online', NULL, 'completed', NULL, 1, '2026-08-31 20:34:21', '2026-09-01 04:35:12'),
(2, 5, 7, 2454.00, 'bank_transfer', NULL, 'completed', NULL, 1, '2026-08-31 22:22:59', '2026-09-01 06:23:03'),
(3, 7, 9, 62.00, 'cash', '12345667', 'completed', '67', 7, '2026-09-01 03:37:35', '2026-09-01 11:37:40'),
(4, 8, 10, 250030.00, 'check', '1234566723', 'completed', 'Thank you!', 7, '2026-09-01 03:53:53', '2026-09-01 11:53:54'),
(5, 9, 12, 500.00, 'cash', '1234566723', 'completed', 'This is the payment', 7, '2026-09-01 04:16:17', '2026-09-01 12:16:19'),
(6, 10, 13, 101000.00, 'cash', '1234566723', 'completed', '12131', 7, '2026-09-07 15:43:17', '2026-09-07 23:43:18'),
(7, 11, 14, 500.00, 'check', '1234566723', 'completed', NULL, 7, '2026-09-10 19:19:10', '2026-09-11 03:19:11'),
(8, 12, 15, 150.00, 'cash', '1234566723', 'completed', NULL, 7, '2026-09-14 19:26:41', '2026-09-15 03:26:42');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `perm_key` varchar(100) NOT NULL,
  `label` varchar(150) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`perm_key`, `label`, `category`, `description`) VALUES
('analytics.view', 'View Analytics', 'Reports', 'See sales performance and reports.'),
('attendance.view', 'Manage Attendance', 'HR', 'Manage Employees Attendances'),
('dashboard.view', 'View Dashboard', 'General', 'See the home dashboard and today\'s stats.'),
('employee_dashboard.view', 'View Employee Dashboard', 'General', 'A Dashboard for Everyemployee'),
('inventory.view', 'View Inventory', 'Inventory', 'See stock levels.'),
('leave.view', 'Manage Leave Request', 'HR', 'Manage Employees Leave Requests'),
('menu.delete', 'Delete Menu Items', 'Menu', 'Remove a menu item entirely.'),
('menu.edit', 'Edit Menu Items', 'Menu', 'Edit price, description, and availability of a menu item.'),
('menu.manage', 'Manage Menu', 'Menu', 'Open the menu management screen.'),
('orders.history', 'View Order History', 'Orders', 'See past completed transactions.'),
('orders.new', 'Take New Orders', 'Orders', 'Access the POS order screen.'),
('orders.pending', 'View Pending Orders', 'Orders', 'See orders that are still in progress.'),
('procurement.attachments.manage', 'Manage Procurement Attachments', 'Procurement', 'Upload and view supporting documents on procurement records'),
('procurement.audit.view', 'View Procurement Audit Log', 'Procurement', 'See the full procurement activity/audit trail'),
('procurement.bidding.review', 'Review Supplier Bids', 'Procurement', 'Evaluate submitted quotes on price, quality, delivery, risk'),
('procurement.budget.manage', 'Manage Procurement Budgets', 'Procurement', 'Allocate and adjust departmental procurement budgets per period'),
('procurement.close', 'Close & Rate Orders', 'Procurement', 'Close completed orders and rate supplier performance'),
('procurement.grn.discrepancy.manage', 'Resolve Delivery Discrepancies', 'Procurement', 'Review and act on short/over/damaged delivery discrepancies'),
('procurement.invoice.create', 'Log Supplier Invoices', 'Procurement', 'Record incoming supplier invoices against a Purchase Order'),
('procurement.invoice.match', 'Match Invoices', 'Procurement', 'Match Purchase Order, Goods Receipt, and Invoice (3-way match)'),
('procurement.negotiation', 'Negotiate Supplier Terms', 'Procurement', 'Contact suppliers and negotiate final commercial terms'),
('procurement.payment.process', 'Process Supplier Payments', 'Procurement', 'Schedule and execute payments to suppliers'),
('procurement.performance.rate', 'Rate Supplier Performance', 'Procurement', 'Score suppliers on quality, timeliness, price, and communication'),
('procurement.po.manage', 'Manage Purchase Orders', 'Procurement', 'Create, send, and approve Purchase Orders'),
('procurement.receiving', 'Record Goods Receipt', 'Procurement', 'Confirm delivery and log received quantities (GRN)'),
('procurement.reports.view', 'View Procurement Reports', 'Procurement', 'Access procurement reports and export data'),
('procurement.requisition.create', 'File Purchase Requisition', 'Procurement', 'Request new goods/services for their department'),
('procurement.requisition.review', 'Review Requisitions', 'Procurement', 'Check budget availability, approve or reject requisitions'),
('procurement.requisitions', 'Create/ Edit Requisitions', 'Procurement', 'See and Create Requisitions'),
('procurement.rfq.manage', 'Manage RFQs', 'Procurement', 'Create and send Requests for Quotation to suppliers'),
('procurement.supplier.portal', 'Supplier Portal Access', 'Procurement', 'Supplier-side access: view RFQ invites, submit bids, acknowledge POs'),
('procurement.suppliers.manage', 'Manage Suppliers', 'Procurement', 'Add, edit, or deactivate suppliers in the directory'),
('procurement.view', 'View Procurement', 'Procurement', 'See the procurement dashboard and requisition list'),
('requests.manage', 'Manage Hr Request', 'HR', 'Manage Request of Employees'),
('users.manage', 'Manage Users/Staff', 'HR', 'Add, edit, and manage staff accounts.');

-- --------------------------------------------------------

--
-- Table structure for table `procurement_attachments`
--

CREATE TABLE `procurement_attachments` (
  `id` int(11) NOT NULL,
  `entity_type` varchar(30) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `procurement_audit_log`
--

CREATE TABLE `procurement_audit_log` (
  `id` int(11) NOT NULL,
  `entity_type` varchar(30) NOT NULL,
  `entity_id` int(11) NOT NULL,
  `action` varchar(60) NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procurement_audit_log`
--

INSERT INTO `procurement_audit_log` (`id`, `entity_type`, `entity_id`, `action`, `performed_by`, `details`, `created_at`) VALUES
(1, 'invoice', 1, 'created', 1, 'PO #3 — 56256 — ₱112,000.00', '2026-08-31 18:34:01'),
(2, 'invoice', 1, '3way_match_run', 1, 'disputed — \"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', '2026-08-31 18:34:10'),
(3, 'invoice', 1, '3way_match_run', 1, 'disputed — \"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', '2026-08-31 18:34:17'),
(4, 'invoice', 1, '3way_match_run', 1, 'disputed — \"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', '2026-08-31 18:34:18'),
(5, 'invoice', 1, '3way_match_run', 1, 'disputed — \"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', '2026-08-31 18:34:20'),
(6, 'invoice', 1, '3way_match_run', 1, 'disputed — \"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', '2026-08-31 18:34:21'),
(7, 'bid', 5, 'quoted', 12, 'Selecta quoted ₱2,333.01', '2026-08-31 20:11:51'),
(8, 'invoice', 1, '3way_match_run', 1, 'disputed — \"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', '2026-08-31 20:14:22'),
(9, 'invoice', 2, 'created', 1, 'PO #1 — 56256 — ₱233.00', '2026-08-31 20:14:53'),
(10, 'invoice', 2, '3way_match_run', 1, 'disputed — \"Chocolate\" invoiced for 0.25 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱233.00 differs from PO total ₱2,232.00 by 89.6% (tolerance is 3.0%).', '2026-08-31 20:14:56'),
(11, 'bid', 3, 'quoted', 12, 'Selecta quoted ₱112,001.00', '2026-08-31 20:19:04'),
(12, 'invoice', 3, 'created', 12, 'Selecta submitted invoice 56256 for PO #4 — ₱4,928.00', '2026-08-31 20:26:11'),
(13, 'invoice', 3, '3way_match_run', 1, 'disputed — \"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', '2026-08-31 20:26:36'),
(14, 'invoice', 3, '3way_match_run', 1, 'disputed — \"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', '2026-08-31 20:26:43'),
(15, 'invoice', 3, '3way_match_run', 1, 'disputed — \"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', '2026-08-31 20:26:45'),
(16, 'invoice', 3, '3way_match_run', 1, 'disputed — \"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', '2026-08-31 20:27:11'),
(17, 'invoice', 3, '3way_match_run', 1, 'disputed — \"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', '2026-08-31 20:27:14'),
(18, 'po', 5, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-08-31 20:29:45'),
(19, 'po', 5, 'shipped', 12, 'Selecta marked the order shipped — Track', '2026-08-31 20:29:53'),
(20, 'grn', 1, 'recorded', 1, 'PO #5 — status: discrepancy', '2026-08-31 20:31:07'),
(21, 'grn', 2, 'recorded', 1, 'PO #5 — status: complete', '2026-08-31 20:31:19'),
(22, 'grn', 1, 'discrepancy_resolved', 1, 'Resolve', '2026-08-31 20:32:04'),
(23, 'invoice', 4, 'created', 1, 'PO #5 — 2323 — ₱13,523.00', '2026-08-31 20:32:32'),
(24, 'invoice', 4, '3way_match_run', 1, 'disputed — \"plastic cups\" invoiced for 56.00 but only 0 received. | Invoice total ₱13,523.00 differs from PO total ₱112,001.00 by 87.9% (tolerance is 3.0%).', '2026-08-31 20:32:35'),
(25, 'invoice', 4, 'force_approved', 1, 'Ovver', '2026-08-31 20:32:47'),
(26, 'payment', 1, 'scheduled', 1, 'Invoice 2323 — ₱13,523.00', '2026-08-31 20:34:21'),
(27, 'payment', 1, 'completed', 7, '₱13,523.00', '2026-08-31 20:35:12'),
(28, 'bid', 6, 'quoted', 12, 'Selecta quoted ₱3,233.00', '2026-08-31 20:51:03'),
(29, 'po', 6, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-08-31 20:52:05'),
(30, 'supplier', 1, 'performance_rated', 1, 'PO #5 — overall 5/5', '2026-08-31 21:54:11'),
(31, 'po', 5, 'closed', 1, 'Procurement cycle complete, overall rating 5/5', '2026-08-31 21:54:11'),
(32, 'bid', 7, 'quoted', 12, 'Selecta quoted ₱2,456.00', '2026-08-31 22:13:30'),
(33, 'po', 7, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-08-31 22:14:20'),
(34, 'po', 7, 'shipped', 12, 'Selecta marked the order shipped — 3323', '2026-08-31 22:14:29'),
(35, 'grn', 3, 'recorded', 1, 'PO #7 — status: complete', '2026-08-31 22:18:49'),
(36, 'invoice', 5, 'created', 7, 'PO #7 — 56256 — ₱2,454.00', '2026-08-31 22:19:36'),
(37, 'invoice', 5, '3way_match_run', 1, 'disputed — \"Arabica Beans\" invoiced for 23.00 but only 0 received. | \"Excelsa Beans\" invoiced for 33.00 but only 0 received.', '2026-08-31 22:20:15'),
(38, 'invoice', 5, 'force_approved', 1, 'Taxes', '2026-08-31 22:20:42'),
(39, 'payment', 2, 'scheduled', 1, 'Invoice 56256 — ₱2,454.00', '2026-08-31 22:22:59'),
(40, 'payment', 2, 'completed', 1, '₱2,454.00', '2026-08-31 22:23:03'),
(41, 'supplier', 1, 'performance_rated', 1, 'PO #7 — overall 5/5', '2026-08-31 22:23:19'),
(42, 'po', 7, 'closed', 1, 'Procurement cycle complete, overall rating 5/5', '2026-08-31 22:23:19'),
(43, 'invoice', 3, 'cancelled', 1, NULL, '2026-09-01 02:02:46'),
(44, 'po', 6, 'shipped', 12, 'Selecta marked the order shipped — LBC', '2026-09-01 02:16:21'),
(45, 'grn', 4, 'recorded', 1, 'PO #6 — status: complete', '2026-09-01 02:20:28'),
(46, 'bid', 8, 'quoted', 12, 'Selecta quoted ₱1,000.00', '2026-09-01 02:22:25'),
(47, 'bid', 8, 'quoted', 12, 'Selecta quoted ₱1,000.00', '2026-09-01 02:22:29'),
(48, 'bid', 8, 'quoted', 12, 'Selecta quoted ₱1,000.00', '2026-09-01 02:22:36'),
(49, 'bid', 8, 'quoted', 12, 'Selecta quoted ₱1,000.00', '2026-09-01 02:22:44'),
(50, 'po', 8, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-09-01 02:32:11'),
(51, 'po', 8, 'shipped', 12, 'Selecta marked the order shipped — LBC', '2026-09-01 02:35:15'),
(52, 'grn', 5, 'recorded', 1, 'PO #8 — status: complete', '2026-09-01 02:50:55'),
(53, 'requisition', 11, 'approved_over_budget', 7, 'Last na to ha', '2026-09-01 03:26:24'),
(54, 'bid', 8, 'quoted', 12, 'Selecta quoted ₱1,000.00', '2026-09-01 03:31:08'),
(55, 'po', 9, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-09-01 03:33:00'),
(56, 'po', 9, 'shipped', 12, 'Selecta marked the order shipped — LBC', '2026-09-01 03:33:44'),
(57, 'grn', 6, 'recorded', 13, 'PO #9 — status: complete', '2026-09-01 03:34:15'),
(58, 'invoice', 6, 'created', 7, 'PO #9 — -1111 — ₱50.00', '2026-09-01 03:35:16'),
(59, 'invoice', 7, 'created', 7, 'PO #9 — 1 — ₱62.00', '2026-09-01 03:35:43'),
(60, 'invoice', 7, '3way_match_run', 7, 'disputed — Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', '2026-09-01 03:36:11'),
(61, 'invoice', 7, '3way_match_run', 7, 'disputed — Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', '2026-09-01 03:36:19'),
(62, 'invoice', 7, '3way_match_run', 7, 'disputed — Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', '2026-09-01 03:36:21'),
(63, 'invoice', 7, '3way_match_run', 7, 'disputed — Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', '2026-09-01 03:36:33'),
(64, 'invoice', 7, '3way_match_run', 7, 'disputed — Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', '2026-09-01 03:36:33'),
(65, 'invoice', 7, 'force_approved', 7, 'ad', '2026-09-01 03:37:14'),
(66, 'payment', 3, 'scheduled', 7, 'Invoice 1 — ₱62.00', '2026-09-01 03:37:35'),
(67, 'payment', 3, 'completed', 7, '₱62.00', '2026-09-01 03:37:40'),
(68, 'supplier', 1, 'performance_rated', 6, 'PO #9 — overall 4.75/5', '2026-09-01 03:38:44'),
(69, 'po', 9, 'closed', 6, 'Procurement cycle complete, overall rating 4.75/5', '2026-09-01 03:38:44'),
(70, 'grn', 7, 'recorded', 6, 'PO #10 — status: complete', '2026-09-01 03:44:05'),
(71, 'requisition', 12, 'approved_over_budget', 7, 'It\'s fine', '2026-09-01 03:46:21'),
(72, 'bid', 11, 'quoted', 12, 'Selecta quoted ₱1.00', '2026-09-01 03:47:58'),
(73, 'po', 11, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-09-01 03:49:11'),
(74, 'po', 11, 'shipped', 12, 'Selecta marked the order shipped — LBC', '2026-09-01 03:49:27'),
(75, 'grn', 8, 'recorded', 13, 'PO #11 — status: discrepancy', '2026-09-01 03:50:19'),
(76, 'grn', 9, 'recorded', 13, 'PO #11 — status: discrepancy', '2026-09-01 03:50:42'),
(77, 'grn', 9, 'discrepancy_resolved', 13, 'replacement', '2026-09-01 03:51:02'),
(78, 'invoice', 8, 'created', 7, 'PO #10 — 10 — ₱250,030.00', '2026-09-01 03:52:30'),
(79, 'invoice', 8, '3way_match_run', 7, 'disputed — \"Koya Dsd\" invoiced for 500.00 but only 0 received. | Invoice total ₱250,030.00 differs from PO total ₱1,000.00 by 24903.0% (tolerance is 3.0%).', '2026-09-01 03:52:38'),
(80, 'invoice', 8, 'force_approved', 7, 'done', '2026-09-01 03:53:09'),
(81, 'payment', 4, 'scheduled', 7, 'Invoice 10 — ₱250,030.00', '2026-09-01 03:53:53'),
(82, 'payment', 4, 'completed', 7, '₱250,030.00', '2026-09-01 03:53:54'),
(83, 'supplier', 1, 'performance_rated', 6, 'PO #10 — overall 5/5', '2026-09-01 03:54:58'),
(84, 'po', 10, 'closed', 6, 'Procurement cycle complete, overall rating 5/5', '2026-09-01 03:54:58'),
(85, 'requisition', 13, 'approved_over_budget', 7, 'Approved', '2026-09-01 04:11:24'),
(86, 'po', 12, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-09-01 04:12:34'),
(87, 'po', 12, 'shipped', 12, 'Selecta marked the order shipped — LBC', '2026-09-01 04:12:47'),
(88, 'grn', 10, 'recorded', 13, 'PO #12 — status: complete', '2026-09-01 04:13:31'),
(89, 'invoice', 9, 'created', 7, 'PO #12 — 10 — ₱500.00', '2026-09-01 04:14:18'),
(90, 'invoice', 9, '3way_match_run', 7, 'disputed — Invoice total ₱500.00 differs from PO total ₱1.00 by 49900.0% (tolerance is 3.0%).', '2026-09-01 04:15:38'),
(91, 'invoice', 9, 'force_approved', 7, 'approve', '2026-09-01 04:15:42'),
(92, 'payment', 5, 'scheduled', 7, 'Invoice 10 — ₱500.00', '2026-09-01 04:16:17'),
(93, 'payment', 5, 'completed', 7, '₱500.00', '2026-09-01 04:16:19'),
(94, 'supplier', 1, 'performance_rated', 6, 'PO #12 — overall 5/5', '2026-09-01 04:16:46'),
(95, 'po', 12, 'closed', 6, 'Procurement cycle complete, overall rating 5/5', '2026-09-01 04:16:46'),
(96, 'po', 13, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-09-07 15:35:11'),
(97, 'po', 13, 'shipped', 12, 'Selecta marked the order shipped — LBCZ', '2026-09-07 15:35:19'),
(98, 'grn', 11, 'recorded', 13, 'PO #13 — status: discrepancy', '2026-09-07 15:35:53'),
(99, 'grn', 12, 'recorded', 13, 'PO #13 — status: discrepancy', '2026-09-07 15:36:05'),
(100, 'grn', 12, 'discrepancy_resolved', 13, 'replacement', '2026-09-07 15:36:15'),
(101, 'grn', 11, 'discrepancy_resolved', 13, 'replacement', '2026-09-07 15:36:19'),
(102, 'grn', 13, 'recorded', 13, 'PO #13 — status: complete', '2026-09-07 15:36:59'),
(103, 'grn', 8, 'discrepancy_resolved', 13, '1', '2026-09-07 15:37:07'),
(104, 'invoice', 10, 'created', 7, 'PO #13 — 13 — ₱101,000.00', '2026-09-07 15:37:57'),
(105, 'invoice', 10, '3way_match_run', 7, 'disputed — Invoice total ₱101,000.00 differs from PO total ₱1.00 by 10099900.0% (tolerance is 3.0%).', '2026-09-07 15:41:50'),
(106, 'invoice', 10, 'force_approved', 7, 'required', '2026-09-07 15:41:57'),
(107, 'payment', 6, 'scheduled', 7, 'Invoice 13 — ₱101,000.00', '2026-09-07 15:43:17'),
(108, 'payment', 6, 'completed', 7, '₱101,000.00', '2026-09-07 15:43:18'),
(109, 'supplier', 1, 'performance_rated', 6, 'PO #13 — overall 5/5', '2026-09-07 15:43:36'),
(110, 'po', 13, 'closed', 6, 'Procurement cycle complete, overall rating 5/5', '2026-09-07 15:43:36'),
(111, 'po', 14, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-09-10 19:17:15'),
(112, 'po', 14, 'shipped', 12, 'Selecta marked the order shipped — LBCZ', '2026-09-10 19:17:24'),
(113, 'grn', 14, 'recorded', 13, 'PO #14 — status: complete', '2026-09-10 19:18:06'),
(114, 'invoice', 11, 'created', 7, 'PO #14 — 14 — ₱500.00', '2026-09-10 19:18:44'),
(115, 'invoice', 11, '3way_match_run', 7, 'matched — Matched clean: invoice ₱500.00 vs PO ₱500.00 (0.0% variance).', '2026-09-10 19:18:50'),
(116, 'invoice', 11, 'approved', 7, NULL, '2026-09-10 19:18:52'),
(117, 'payment', 7, 'scheduled', 7, 'Invoice 14 — ₱500.00', '2026-09-10 19:19:10'),
(118, 'payment', 7, 'completed', 7, '₱500.00', '2026-09-10 19:19:11'),
(119, 'supplier', 1, 'performance_rated', 6, 'PO #14 — overall 5/5', '2026-09-10 19:19:50'),
(120, 'po', 14, 'closed', 6, 'Procurement cycle complete, overall rating 5/5', '2026-09-10 19:19:50'),
(121, 'po', 15, 'acknowledged', 12, 'Selecta acknowledged the order', '2026-09-14 19:25:05'),
(122, 'po', 15, 'shipped', 12, 'Selecta marked the order shipped — LBC', '2026-09-14 19:25:12'),
(123, 'grn', 15, 'recorded', 13, 'PO #15 — status: complete', '2026-09-14 19:25:36'),
(124, 'invoice', 12, 'created', 7, 'PO #15 — 15 — ₱150.00', '2026-09-14 19:26:10'),
(125, 'invoice', 12, '3way_match_run', 7, 'matched — Matched clean: invoice ₱150.00 vs PO ₱150.00 (0.0% variance).', '2026-09-14 19:26:18'),
(126, 'invoice', 12, 'approved', 7, NULL, '2026-09-14 19:26:19'),
(127, 'payment', 8, 'scheduled', 7, 'Invoice 15 — ₱150.00', '2026-09-14 19:26:41'),
(128, 'payment', 8, 'completed', 7, '₱150.00', '2026-09-14 19:26:42'),
(129, 'supplier', 1, 'performance_rated', 6, 'PO #15 — overall 5/5', '2026-09-14 19:27:32'),
(130, 'po', 15, 'closed', 6, 'Procurement cycle complete, overall rating 5/5', '2026-09-14 19:27:32');

-- --------------------------------------------------------

--
-- Table structure for table `procurement_budgets`
--

CREATE TABLE `procurement_budgets` (
  `id` int(11) NOT NULL,
  `department` varchar(60) NOT NULL,
  `period_label` varchar(30) NOT NULL,
  `allocated_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `used_amount` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procurement_budgets`
--

INSERT INTO `procurement_budgets` (`id`, `department`, `period_label`, `allocated_amount`, `used_amount`) VALUES
(1, 'manager', '2026-Q3', 50000.00, 0.00),
(2, 'crew', '2026-Q3', 10000000.00, 100650.00),
(3, 'finance', '2026-Q3', 10000.00, 0.00),
(6, 'admin', '2026-Q3', 0.00, 0.00),
(7, 'hr', '2026-Q3', 0.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `price_small` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_large` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price` int(12) NOT NULL,
  `stock` int(12) NOT NULL DEFAULT 0,
  `category_id` int(12) NOT NULL,
  `created_at` date NOT NULL DEFAULT curdate(),
  `updated_at` date NOT NULL DEFAULT curdate(),
  `image_path` varchar(255) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `description`, `price_small`, `price_large`, `price`, `stock`, `category_id`, `created_at`, `updated_at`, `image_path`, `is_deleted`) VALUES
(4, 'Caramel Macchiato', '', 140.00, 160.00, 0, 0, 1, '2026-09-11', '2026-09-11', NULL, 1),
(5, 'WinnerMelon', 'shshhshs', 45.00, 67.00, 0, 0, 3, '2026-09-13', '2026-09-13', NULL, 1),
(7, 'WinnerMelon', 'ad', 23.00, 42.00, 0, 0, 2, '2026-09-15', '2026-09-15', NULL, 1),
(8, 'WinnerMelon', 'asda', 23.00, 53.00, 0, 1, 4, '2026-09-15', '2026-09-15', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `product_ingredients`
--

CREATE TABLE `product_ingredients` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` enum('small','large') NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `qty_used` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_ingredients`
--

INSERT INTO `product_ingredients` (`id`, `product_id`, `size`, `ingredient_id`, `qty_used`) VALUES
(1, 4, 'small', 8, 1.00),
(2, 4, 'large', 8, 1.00),
(3, 4, 'small', 9, 1.00),
(4, 4, 'large', 9, 1.00),
(5, 4, 'small', 12, 1.00),
(6, 4, 'large', 12, 1.00),
(7, 4, 'small', 18, 1.00),
(8, 4, 'large', 18, 1.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `requisition_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `status` enum('draft','sent','acknowledged','delivered','closed','cancelled') NOT NULL DEFAULT 'draft',
  `negotiation_notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expected_delivery_date` date DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `shipping_notes` varchar(255) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `supplier_rating` tinyint(4) DEFAULT NULL,
  `invoice_number` varchar(60) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `rfq_id`, `supplier_id`, `requisition_id`, `total_amount`, `status`, `negotiation_notes`, `created_by`, `created_at`, `expected_delivery_date`, `delivered_at`, `acknowledged_at`, `shipped_at`, `shipping_notes`, `closed_at`, `supplier_rating`, `invoice_number`, `paid_at`) VALUES
(1, 1, 1, 1, 2232.00, 'closed', 'Order Close', 1, '2026-08-24 19:57:59', NULL, '2026-08-25 03:58:26', NULL, NULL, NULL, '2026-08-25 04:00:10', 3, '#0001', '2026-08-25 04:00:05'),
(3, 4, 1, 5, 0.01, 'closed', 'xs', 1, '2026-08-25 07:15:58', NULL, '2026-08-25 15:16:05', NULL, NULL, NULL, '2026-08-25 15:16:34', 5, '#0001', '2026-08-25 15:16:21'),
(4, 5, 1, 7, 2333.01, 'delivered', '', 1, '2026-08-31 20:13:06', NULL, '2026-09-01 04:13:49', NULL, NULL, NULL, NULL, NULL, '56256', '2026-09-01 04:16:47'),
(5, 3, 1, 5, 112001.00, 'closed', '', 1, '2026-08-31 20:20:11', NULL, '2026-09-01 04:31:19', '2026-09-01 04:29:45', '2026-09-01 04:29:53', 'Track', '2026-09-01 05:54:11', 5, '56256', '2026-09-01 04:35:26'),
(6, 6, 1, 6, 3233.00, 'delivered', '', 11, '2026-08-31 20:51:27', NULL, '2026-09-01 10:20:28', '2026-09-01 04:52:05', '2026-09-01 10:16:21', 'LBC', NULL, NULL, NULL, NULL),
(7, 7, 1, 9, 2456.00, 'closed', '', 11, '2026-08-31 22:14:04', NULL, '2026-09-01 06:18:49', '2026-09-01 06:14:20', '2026-09-01 06:14:29', '3323', '2026-09-01 06:23:19', 5, NULL, '2026-09-01 06:23:03'),
(8, 9, 1, 10, 20.00, 'delivered', 'okiii', 1, '2026-09-01 02:31:39', NULL, '2026-09-01 10:50:55', '2026-09-01 10:32:11', '2026-09-01 10:35:15', 'LBC', NULL, NULL, NULL, NULL),
(9, 10, 1, 11, 9999999999.99, 'closed', 'Done', 11, '2026-09-01 03:29:15', NULL, '2026-09-01 11:34:15', '2026-09-01 11:33:00', '2026-09-01 11:33:44', 'LBC', '2026-09-01 11:38:44', 5, NULL, '2026-09-01 11:37:40'),
(10, 8, 1, 8, 1000.00, 'closed', 'thank you!', 6, '2026-09-01 03:43:55', NULL, '2026-09-01 11:44:05', NULL, NULL, NULL, '2026-09-01 11:54:58', 5, NULL, '2026-09-01 11:53:54'),
(11, 11, 1, 12, 1.00, 'acknowledged', 'Agree', 11, '2026-09-01 03:48:44', NULL, NULL, '2026-09-01 11:49:11', '2026-09-01 11:49:27', 'LBC', NULL, NULL, NULL, NULL),
(12, 12, 1, 13, 1.00, 'closed', 'Agree', 11, '2026-09-01 04:12:17', NULL, '2026-09-01 12:13:31', '2026-09-01 12:12:34', '2026-09-01 12:12:47', 'LBC', '2026-09-01 12:16:46', 5, NULL, '2026-09-01 12:16:19'),
(13, 13, 1, 14, 1.00, 'closed', 'g', 11, '2026-09-07 15:34:58', NULL, '2026-09-07 23:36:59', '2026-09-07 23:35:11', '2026-09-07 23:35:19', 'LBCZ', '2026-09-07 23:43:36', 5, NULL, '2026-09-07 23:43:18'),
(14, 14, 1, 15, 500.00, 'closed', '', 11, '2026-09-10 19:17:02', NULL, '2026-09-11 03:18:06', '2026-09-11 03:17:15', '2026-09-11 03:17:24', 'LBCZ', '2026-09-11 03:19:50', 5, NULL, '2026-09-11 03:19:11'),
(15, 15, 1, 16, 150.00, 'closed', '', 11, '2026-09-14 19:24:52', NULL, '2026-09-15 03:25:36', '2026-09-15 03:25:05', '2026-09-15 03:25:12', 'LBC', '2026-09-15 03:27:32', 5, NULL, '2026-09-15 03:26:42');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requisitions`
--

CREATE TABLE `purchase_requisitions` (
  `id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `department` varchar(60) NOT NULL,
  `title` varchar(150) NOT NULL,
  `notes` text DEFAULT NULL,
  `estimated_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','rejected','sourcing','awarded','closed') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_requisitions`
--

INSERT INTO `purchase_requisitions` (`id`, `requested_by`, `department`, `title`, `notes`, `estimated_total`, `status`, `reviewed_by`, `reviewed_at`, `review_notes`, `created_at`) VALUES
(1, 1, 'manager', 'Inventory', 'Low Stock', 0.00, 'awarded', 1, '2026-08-25 03:39:42', '', '2026-08-23 20:34:31'),
(3, 1, 'hr', 'Food', '', 160.00, 'approved', 1, '2026-08-25 04:27:55', '', '2026-08-24 20:21:46'),
(4, 1, 'crew', 'Testing 2', 'sada', 529.00, 'approved', 1, '2026-08-25 13:40:52', '', '2026-08-25 05:40:36'),
(5, 1, 'manager', 'restock', '', 11200.00, 'closed', 1, '2026-08-25 15:14:46', '', '2026-08-25 07:11:50'),
(6, 1, 'crew', 'adasd', '', 53429.00, 'awarded', 1, '2026-09-01 02:15:16', '', '2026-08-31 18:12:39'),
(7, 1, 'crew', 'Testomg', '', 46000.00, 'awarded', 1, '2026-09-01 02:15:11', '', '2026-08-31 18:14:05'),
(8, 1, 'crew', 'asda', 'sada', 15565641.00, 'closed', 1, '2026-09-01 04:18:04', '', '2026-08-31 19:11:46'),
(9, 1, 'manager', 'Coffee Beans', '', 2454.00, 'closed', 1, '2026-09-01 06:12:55', '', '2026-08-31 22:12:35'),
(10, 1, 'manager', 'Iphone 17 pro', 'For documentations', 400.00, 'awarded', 1, '2026-09-01 10:30:01', 'okay noted', '2026-09-01 02:26:53'),
(11, 8, 'crew', 'Kahit ano basta', 'Pls', 50.00, 'closed', 7, '2026-09-01 11:26:24', 'okay lang yan', '2026-09-01 03:25:21'),
(12, 8, 'crew', 'Increase of stock', 'Badly needed, ASAP', 500.00, 'awarded', 7, '2026-09-01 11:46:21', 'Okay lang asap daw e', '2026-09-01 03:45:12'),
(13, 8, 'crew', 'Stock increase', '', 500.00, 'closed', 7, '2026-09-01 12:11:24', '', '2026-09-01 04:09:17'),
(14, 8, 'crew', 'Iphone 15', 'Please', 100000.00, 'closed', 11, '2026-09-07 23:28:41', 'Approved', '2026-09-07 15:26:55'),
(15, 8, 'crew', 'Ice request', '', 500.00, 'closed', 7, '2026-09-11 03:16:09', '', '2026-09-10 19:15:53'),
(16, 8, 'crew', 'Espresso Request', 'Pls be still', 150.00, 'closed', 7, '2026-09-15 03:24:09', '', '2026-09-14 19:23:49');

-- --------------------------------------------------------

--
-- Table structure for table `requisition_items`
--

CREATE TABLE `requisition_items` (
  `id` int(11) NOT NULL,
  `requisition_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `est_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requisition_items`
--

INSERT INTO `requisition_items` (`id`, `requisition_id`, `item_name`, `quantity`, `unit`, `est_unit_price`) VALUES
(1, 1, 'Chocolate', 0.25, 'liters', 0.00),
(3, 3, 'Strawberry', 32.00, 'pcs', 5.00),
(4, 4, 'Chocolate', 23.00, 'pcs', 23.00),
(5, 5, 'plastic cups', 56.00, 'bundle', 200.00),
(6, 6, 'trtr', 23.00, 'j', 2323.00),
(7, 7, 'Koya Dsd', 23.00, '12', 2000.00),
(8, 8, 'Koya Dsd', 23.00, '12', 676767.00),
(9, 9, 'Arabica Beans', 23.00, 'g', 45.00),
(10, 9, 'Excelsa Beans', 33.00, 'g', 43.00),
(11, 10, 'Arabica Beans', 20.00, 'g', 20.00),
(12, 11, 'Excelsa Beans', 10.00, 'g', 5.00),
(13, 12, 'Arabica Beans', 500.00, 'g', 1.00),
(14, 13, 'Excelsa Beans', 500.00, 'g', 1.00),
(15, 14, 'Robusta Beans', 100000.00, 'g', 1.00),
(16, 15, 'Ice', 500.00, '500', 1.00),
(17, 16, 'Espresso', 150.00, 'ml', 1.00);

-- --------------------------------------------------------

--
-- Table structure for table `restock_log`
--

CREATE TABLE `restock_log` (
  `id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `added_qty` decimal(10,2) NOT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restock_log`
--

INSERT INTO `restock_log` (`id`, `ingredient_id`, `added_qty`, `processed_by`, `created_at`) VALUES
(1, 1, 0.00, 1, '2026-08-02 03:36:28'),
(2, 1, 1.30, 1, '2026-08-02 03:36:34'),
(3, 6, 0.00, 1, '2026-08-25 13:24:21'),
(4, 6, 22.00, 1, '2026-08-25 13:24:27'),
(5, 1, 52.00, NULL, '2026-09-01 09:28:04'),
(6, 6, 20.00, 1, '2026-09-01 10:50:55'),
(7, 7, 10.00, 13, '2026-09-01 11:34:15'),
(8, 6, 1.00, 13, '2026-09-01 11:50:19'),
(9, 6, 499.00, 13, '2026-09-01 11:50:42'),
(10, 7, 500.00, 13, '2026-09-01 12:13:31'),
(11, 5, 1.00, 13, '2026-09-07 23:35:53'),
(12, 5, 99999.00, 13, '2026-09-07 23:36:05'),
(13, 8, 500.00, 13, '2026-09-11 03:18:06'),
(14, 6, 1000.00, NULL, '2026-09-13 18:04:25'),
(15, 13, 10000.00, NULL, '2026-09-13 18:04:26'),
(16, 9, 150.00, 13, '2026-09-15 03:25:36');

-- --------------------------------------------------------

--
-- Table structure for table `rfqs`
--

CREATE TABLE `rfqs` (
  `id` int(11) NOT NULL,
  `requisition_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `status` enum('open','closed','awarded') NOT NULL DEFAULT 'open',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rfqs`
--

INSERT INTO `rfqs` (`id`, `requisition_id`, `created_by`, `status`, `due_date`, `created_at`) VALUES
(1, 1, 1, 'awarded', '2026-08-29', '2026-08-24 19:46:50'),
(3, 5, 1, 'awarded', '2026-08-12', '2026-08-25 07:15:00'),
(4, 5, 1, 'awarded', '2026-08-12', '2026-08-25 07:15:17'),
(5, 7, 1, 'awarded', '2026-09-01', '2026-08-31 19:54:27'),
(6, 6, 1, 'awarded', '2026-09-03', '2026-08-31 20:50:43'),
(7, 9, 1, 'awarded', NULL, '2026-08-31 22:12:59'),
(8, 8, 1, 'awarded', '2026-09-01', '2026-09-01 02:21:49'),
(9, 10, 1, 'awarded', '2026-09-01', '2026-09-01 02:30:57'),
(10, 11, 11, 'awarded', '2026-09-05', '2026-09-01 03:28:01'),
(11, 12, 11, 'awarded', '2026-09-01', '2026-09-01 03:47:12'),
(12, 13, 11, 'awarded', '2026-09-01', '2026-09-01 04:11:57'),
(13, 14, 11, 'awarded', '2026-09-07', '2026-09-07 15:34:42'),
(14, 15, 11, 'awarded', '2026-09-11', '2026-09-10 19:16:41'),
(15, 16, 11, 'awarded', '2026-09-15', '2026-09-14 19:24:39');

-- --------------------------------------------------------

--
-- Table structure for table `rfq_invites`
--

CREATE TABLE `rfq_invites` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rfq_invites`
--

INSERT INTO `rfq_invites` (`id`, `rfq_id`, `supplier_id`) VALUES
(1, 1, 1),
(3, 3, 1),
(4, 4, 1),
(5, 5, 1),
(6, 6, 1),
(7, 7, 1),
(8, 8, 1),
(9, 9, 1),
(10, 10, 1),
(11, 11, 1),
(12, 12, 1),
(13, 13, 1),
(14, 14, 1),
(15, 15, 1);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_key`, `label`, `is_system`) VALUES
('admin', 'Admin', 1),
('crew', 'Crew', 1),
('finance', 'Finance', 1),
('hr', 'HR', 1),
('manager', 'Manager', 1),
('ops', 'Ops', 0),
('procurement', 'Procurement Officer', 0),
('supplier', 'Supplier', 0),
('warehouse', 'Warehouse / Receiving Officer', 0);

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role` varchar(50) NOT NULL,
  `perm_key` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role`, `perm_key`) VALUES
('crew', 'employee_dashboard.view'),
('crew', 'inventory.view'),
('crew', 'orders.history'),
('crew', 'orders.new'),
('crew', 'orders.pending'),
('crew', 'procurement.requisition.create'),
('crew', 'procurement.view'),
('finance', 'analytics.view'),
('finance', 'procurement.audit.view'),
('finance', 'procurement.budget.manage'),
('finance', 'procurement.invoice.create'),
('finance', 'procurement.invoice.match'),
('finance', 'procurement.payment.process'),
('finance', 'procurement.po.manage'),
('finance', 'procurement.requisition.review'),
('finance', 'procurement.view'),
('hr', 'attendance.view'),
('hr', 'leave.view'),
('hr', 'requests.manage'),
('manager', 'analytics.view'),
('manager', 'dashboard.view'),
('manager', 'employee_dashboard.view'),
('manager', 'inventory.view'),
('manager', 'menu.delete'),
('manager', 'menu.edit'),
('manager', 'menu.manage'),
('manager', 'orders.history'),
('manager', 'orders.new'),
('manager', 'orders.pending'),
('manager', 'procurement.audit.view'),
('manager', 'procurement.bidding.review'),
('manager', 'procurement.budget.manage'),
('manager', 'procurement.close'),
('manager', 'procurement.negotiation'),
('manager', 'procurement.performance.rate'),
('manager', 'procurement.po.manage'),
('manager', 'procurement.receiving'),
('manager', 'procurement.reports.view'),
('manager', 'procurement.requisition.create'),
('manager', 'procurement.requisition.review'),
('manager', 'procurement.rfq.manage'),
('manager', 'procurement.suppliers.manage'),
('manager', 'procurement.view'),
('ops', 'menu.delete'),
('ops', 'menu.edit'),
('ops', 'menu.manage'),
('ops', 'orders.history'),
('ops', 'orders.new'),
('ops', 'orders.pending'),
('procurement', 'procurement.attachments.manage'),
('procurement', 'procurement.bidding.review'),
('procurement', 'procurement.close'),
('procurement', 'procurement.invoice.match'),
('procurement', 'procurement.negotiation'),
('procurement', 'procurement.performance.rate'),
('procurement', 'procurement.po.manage'),
('procurement', 'procurement.reports.view'),
('procurement', 'procurement.requisition.review'),
('procurement', 'procurement.requisitions'),
('procurement', 'procurement.rfq.manage'),
('procurement', 'procurement.suppliers.manage'),
('procurement', 'procurement.view'),
('supplier', 'procurement.supplier.portal'),
('warehouse', 'inventory.view'),
('warehouse', 'procurement.attachments.manage'),
('warehouse', 'procurement.grn.discrepancy.manage'),
('warehouse', 'procurement.receiving'),
('warehouse', 'procurement.view');

-- --------------------------------------------------------

--
-- Table structure for table `sales_history`
--

CREATE TABLE `sales_history` (
  `id` int(50) NOT NULL,
  `order_id` int(50) NOT NULL,
  `processed_id` int(50) NOT NULL,
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `rating_avg` decimal(3,2) DEFAULT NULL,
  `rating_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `user_id`, `name`, `contact_person`, `email`, `phone`, `address`, `status`, `rating_avg`, `rating_count`, `created_at`) VALUES
(1, 12, 'Selecta', 'Anton', 'selecta@gmail.com', '998223213', 'Selecta Street', 'active', 4.77, 10, '2026-08-24 19:40:40');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_performance_ratings`
--

CREATE TABLE `supplier_performance_ratings` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `rated_by` int(11) NOT NULL,
  `quality_score` tinyint(1) NOT NULL,
  `timeliness_score` tinyint(1) NOT NULL,
  `price_score` tinyint(1) NOT NULL,
  `communication_score` tinyint(1) NOT NULL,
  `comments` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_performance_ratings`
--

INSERT INTO `supplier_performance_ratings` (`id`, `po_id`, `supplier_id`, `rated_by`, `quality_score`, `timeliness_score`, `price_score`, `communication_score`, `comments`, `created_at`) VALUES
(1, 5, 1, 1, 5, 5, 5, 5, NULL, '2026-08-31 21:54:11'),
(2, 7, 1, 1, 5, 5, 5, 5, NULL, '2026-08-31 22:23:19'),
(3, 9, 1, 6, 5, 5, 4, 5, 'Sheeshhh', '2026-09-01 03:38:44'),
(4, 10, 1, 6, 5, 5, 5, 5, 'Nice nice nice', '2026-09-01 03:54:58'),
(5, 12, 1, 6, 5, 5, 5, 5, 'Maganda', '2026-09-01 04:16:46'),
(6, 13, 1, 6, 5, 5, 5, 5, NULL, '2026-09-07 15:43:36'),
(7, 14, 1, 6, 5, 5, 5, 5, 'hindi tunaw', '2026-09-10 19:19:50'),
(8, 15, 1, 6, 5, 5, 5, 5, 'Kumpleto daw sabi ni warehouse', '2026-09-14 19:27:32');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(50) NOT NULL,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL DEFAULT '',
  `password` varchar(255) NOT NULL,
  `firstname` varchar(50) DEFAULT NULL,
  `lastname` varchar(50) DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'crew',
  `status` enum('active','blocked','on_hold') NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `firstname`, `lastname`, `role`, `status`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@kofeecafe.local', '$2b$12$qOp8gghDWgZARl6QgPjokOym9pdos0eYGuukmuKjwftvDNoYiLsce', 'Admin', 'User', 'admin', 'active', '2026-09-15 17:14:11', '2026-08-01 05:54:54', '2026-09-15 09:14:11'),
(2, 'khylle', 'khyllechester.roque07@gmail.com', '$2y$12$U8ZvNAybT/pUPREDy9GBSOKOYEovRl80bFylviTRSkg3/pMNXsZHu', 'Khylle', 'Roque', 'hr', 'active', '2026-08-08 05:24:24', '2026-08-01 17:10:32', '2026-08-07 21:24:24'),
(4, 'hr', 'hr@gmail.com', '$2y$12$GgAf5abpEeCCMShTPyNBx.fFkkldjYtSqXBC2fGO3QeOCZq2E.RC6', 'Hr', 'Test', 'hr', 'active', '2026-09-15 04:53:03', '2026-08-03 02:44:30', '2026-09-14 20:53:03'),
(6, 'manager', 'manager@gmail.com', '$2y$12$KZjpe74FXfwp1apOhfVTWuEpWZR8NDpwtSa3hMgdzWfL6u68DGuJa', 'manager', 'test', 'manager', 'active', '2026-09-15 03:26:50', '2026-08-07 16:26:32', '2026-09-14 19:26:50'),
(7, 'finance', 'finance@gmail.com', '$2y$12$grKPN2YaboFgVuNdjss.x.m35lXZVvhc.oXtndynSFIVfAfADUrVe', 'finance', 'testing', 'finance', 'active', '2026-09-15 03:25:54', '2026-08-25 07:19:47', '2026-09-14 19:25:54'),
(8, 'crew', 'crew@gmail.com', '$2y$12$sDuD5IqUP1dYowMEEOZpMeb9gsmvqqaCMBPJeQe5FGGOtQ4exgGmO', 'Crew', 'Test', 'crew', 'active', '2026-09-15 14:34:27', '2026-08-31 19:16:53', '2026-09-15 06:34:27'),
(10, 'ops', 'ops@gmail.com', '$2y$12$b/Biet0SDoRiUe85NikfUOxVpkN2MC.PKrGUoe1u/UB07lXiIRcwK', 'ops', 'test', 'ops', 'active', '2026-09-15 14:34:47', '2026-08-31 19:33:41', '2026-09-15 06:34:47'),
(11, 'procurement', 'procurement@gmail.com', '$2y$12$QN9qrF8k8f/ACcuSk2hGfuXQ4Sd/TmyRur2tYTbxDrnORFR/URxpG', 'Procurment', 'Testing', 'procurement', 'active', '2026-09-15 03:24:22', '2026-08-31 19:34:39', '2026-09-14 19:24:22'),
(12, 'supplier', 'supplier@gmail.com', '$2y$12$qEoXF2nSXx1hCzuiFKnMn.4GA4.vcUTUl4EqRS1Dr//ezjKkf3tOO', 'Supplier', 'Testing', 'supplier', 'active', '2026-09-15 03:25:00', '2026-08-31 19:35:35', '2026-09-14 19:25:00'),
(13, 'warehouse', 'receiving@gmail.com', '$2y$12$U.fk3.cm1Eef/i.RIDjkou5EeDI1cQpWTPDqzm3UcwjJAR.uzw56u', 'Receiving', 'Testing', 'warehouse', 'active', '2026-09-15 03:25:20', '2026-08-31 19:36:38', '2026-09-14 19:25:20'),
(16, 'crew2', 'crew2@gmail.com', '$2y$12$wNQeq7F7GKW/CL3/JISV3em4Rdqqc.iItnmsOYlny3DgZgcJzdOUq', 'testing', 'Test', 'crew', 'active', '2026-09-13 19:54:59', '2026-08-31 21:06:16', '2026-09-13 11:54:59');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `user_id`, `role`) VALUES
(195, 1, 'admin'),
(30, 2, 'hr'),
(211, 4, 'hr'),
(226, 6, 'manager'),
(220, 7, 'finance'),
(205, 8, 'crew'),
(239, 10, 'ops'),
(242, 11, 'procurement'),
(246, 12, 'supplier'),
(249, 13, 'warehouse'),
(261, 16, 'crew');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_employee_date` (`employee_id`,`attendance_date`);

--
-- Indexes for table `bids`
--
ALTER TABLE `bids`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_rfq_supplier_bid` (`rfq_id`,`supplier_id`),
  ADD KEY `fk_bid_supplier` (`supplier_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD KEY `fk_employees_user` (`user_id`);

--
-- Indexes for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_grn_po` (`po_id`),
  ADD KEY `idx_grn_received_by` (`received_by`);

--
-- Indexes for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gri_grn` (`grn_id`),
  ADD KEY `idx_gri_req_item` (`requisition_item_id`);

--
-- Indexes for table `hr_requests`
--
ALTER TABLE `hr_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_hrreq_employee` (`employee_id`),
  ADD KEY `fk_hrreq_reviewer` (`reviewed_by`),
  ADD KEY `idx_hrreq_status` (`status`);

--
-- Indexes for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ingredients_cat` (`cat_id`),
  ADD KEY `idx_ingredients_archived` (`archived_at`);

--
-- Indexes for table `ingredient_categories`
--
ALTER TABLE `ingredient_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cat_name` (`name`);

--
-- Indexes for table `ingredient_usage_log`
--
ALTER TABLE `ingredient_usage_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_order_ingredient` (`order_id`,`ingredient_id`),
  ADD KEY `fk_usage_ingredient` (`ingredient_id`),
  ADD KEY `fk_usage_user` (`processed_by`);

--
-- Indexes for table `inventory_requests`
--
ALTER TABLE `inventory_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_inventory_item` (`item_id`),
  ADD KEY `fk_inventory_requested_by` (`requested_by`),
  ADD KEY `fk_inventory_approved_by` (`approved_by`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoices_po` (`po_id`),
  ADD KEY `idx_invoices_supplier` (`supplier_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ii_invoice` (`invoice_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_leave_employee` (`employee_id`),
  ADD KEY `fk_leave_reviewer` (`reviewed_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_recipient` (`recipient_user_id`,`is_read`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_orders_user` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_order_items_order` (`order_id`),
  ADD KEY `fk_order_items_product` (`product_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`),
  ADD KEY `idx_payments_po` (`po_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`perm_key`);

--
-- Indexes for table `procurement_attachments`
--
ALTER TABLE `procurement_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attach_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `procurement_audit_log`
--
ALTER TABLE `procurement_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_audit_performed_by` (`performed_by`);

--
-- Indexes for table `procurement_budgets`
--
ALTER TABLE `procurement_budgets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_dept_period` (`department`,`period_label`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_products_category` (`category_id`);

--
-- Indexes for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_recipe_line` (`product_id`,`size`,`ingredient_id`),
  ADD KEY `fk_pi_ingredient` (`ingredient_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_po_rfq` (`rfq_id`),
  ADD KEY `fk_po_supplier` (`supplier_id`),
  ADD KEY `fk_po_req` (`requisition_id`),
  ADD KEY `fk_po_user` (`created_by`);

--
-- Indexes for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pr_user` (`requested_by`),
  ADD KEY `fk_pr_reviewer` (`reviewed_by`);

--
-- Indexes for table `requisition_items`
--
ALTER TABLE `requisition_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ri_req` (`requisition_id`);

--
-- Indexes for table `restock_log`
--
ALTER TABLE `restock_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_restock_ingredient` (`ingredient_id`),
  ADD KEY `fk_restock_user` (`processed_by`);

--
-- Indexes for table `rfqs`
--
ALTER TABLE `rfqs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rfq_req` (`requisition_id`),
  ADD KEY `fk_rfq_user` (`created_by`);

--
-- Indexes for table `rfq_invites`
--
ALTER TABLE `rfq_invites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_rfq_supplier` (`rfq_id`,`supplier_id`),
  ADD KEY `fk_rfqi_supplier` (`supplier_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_key`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role`,`perm_key`),
  ADD UNIQUE KEY `uniq_role_perm` (`role`,`perm_key`),
  ADD KEY `fk_rp_perm` (`perm_key`);

--
-- Indexes for table `sales_history`
--
ALTER TABLE `sales_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sales_history_order` (`order_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_suppliers_user_id` (`user_id`);

--
-- Indexes for table `supplier_performance_ratings`
--
ALTER TABLE `supplier_performance_ratings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_spr_supplier` (`supplier_id`),
  ADD KEY `idx_spr_po` (`po_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_role` (`user_id`,`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `bids`
--
ALTER TABLE `bids`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `hr_requests`
--
ALTER TABLE `hr_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `ingredient_categories`
--
ALTER TABLE `ingredient_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `ingredient_usage_log`
--
ALTER TABLE `ingredient_usage_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=176;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `procurement_attachments`
--
ALTER TABLE `procurement_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `procurement_audit_log`
--
ALTER TABLE `procurement_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=131;

--
-- AUTO_INCREMENT for table `procurement_budgets`
--
ALTER TABLE `procurement_budgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(50) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `requisition_items`
--
ALTER TABLE `requisition_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `restock_log`
--
ALTER TABLE `restock_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `rfqs`
--
ALTER TABLE `rfqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `rfq_invites`
--
ALTER TABLE `rfq_invites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `sales_history`
--
ALTER TABLE `sales_history`
  MODIFY `id` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `supplier_performance_ratings`
--
ALTER TABLE `supplier_performance_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(50) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=271;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bids`
--
ALTER TABLE `bids`
  ADD CONSTRAINT `fk_bid_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bid_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `hr_requests`
--
ALTER TABLE `hr_requests`
  ADD CONSTRAINT `fk_hrreq_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hrreq_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ingredient_usage_log`
--
ALTER TABLE `ingredient_usage_log`
  ADD CONSTRAINT `fk_usage_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_usage_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_usage_user` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `fk_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_leave_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Constraints for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  ADD CONSTRAINT `fk_pi_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  ADD CONSTRAINT `fk_pi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_req` FOREIGN KEY (`requisition_id`) REFERENCES `purchase_requisitions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_po_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  ADD CONSTRAINT `fk_pr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `requisition_items`
--
ALTER TABLE `requisition_items`
  ADD CONSTRAINT `fk_ri_req` FOREIGN KEY (`requisition_id`) REFERENCES `purchase_requisitions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rfqs`
--
ALTER TABLE `rfqs`
  ADD CONSTRAINT `fk_rfq_req` FOREIGN KEY (`requisition_id`) REFERENCES `purchase_requisitions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rfq_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rfq_invites`
--
ALTER TABLE `rfq_invites`
  ADD CONSTRAINT `fk_rfqi_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rfqi_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_perm` FOREIGN KEY (`perm_key`) REFERENCES `permissions` (`perm_key`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role`) REFERENCES `roles` (`role_key`) ON DELETE CASCADE;

--
-- Constraints for table `sales_history`
--
ALTER TABLE `sales_history`
  ADD CONSTRAINT `fk_sales_history_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
