-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 26, 2026 at 03:16 AM
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
  `break_start` time DEFAULT NULL,
  `break_end` time DEFAULT NULL,
  `status` enum('present','late','absent','on_leave','half_day') NOT NULL DEFAULT 'present',
  `notes` varchar(255) DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `is_holiday` tinyint(1) NOT NULL DEFAULT 0,
  `is_rest_day` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `attendance_date`, `time_in`, `time_in_photo`, `time_out`, `time_out_photo`, `break_start`, `break_end`, `status`, `notes`, `is_approved`, `approved_by`, `approved_at`, `is_holiday`, `is_rest_day`, `created_at`, `updated_at`) VALUES
(2, 2, '2026-08-29', '05:07:40', 'uploads/attendance/emp2_2026-08-29_in_1788037660.jpg', '05:08:13', 'uploads/attendance/emp2_2026-08-29_out_1788037693.jpg', NULL, NULL, 'present', NULL, 1, NULL, NULL, 0, 0, '2026-08-29 21:07:40', '2026-09-20 02:41:08'),
(3, 3, '2026-08-30', '08:40:21', 'uploads/attendance/emp3_2026-08-30_in_1788050421.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 1, NULL, NULL, 0, 0, '2026-08-30 00:40:21', '2026-09-20 02:41:08'),
(4, 2, '2026-09-01', '11:21:00', NULL, '12:12:00', NULL, NULL, NULL, 'absent', 'Nag-ml kagabi', 1, NULL, NULL, 0, 0, '2026-09-01 01:42:55', '2026-09-20 02:41:08'),
(5, 4, '2026-09-07', '23:25:58', 'uploads/attendance/emp4_2026-09-07_in_1788794758.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 1, NULL, NULL, 0, 0, '2026-09-07 15:25:58', '2026-09-20 02:41:08'),
(7, 4, '2026-09-13', '20:00:25', 'uploads/attendance/emp4_2026-09-13_in_1789300825.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 1, NULL, NULL, 0, 0, '2026-09-13 12:00:25', '2026-09-20 02:41:08'),
(8, 2, '2026-09-13', '20:07:18', 'uploads/attendance/emp2_2026-09-13_in_1789301238.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 1, NULL, NULL, 0, 0, '2026-09-13 12:07:18', '2026-09-20 02:41:08'),
(11, 4, '2026-09-14', '03:39:18', 'uploads/attendance/emp4_2026-09-14_in_1789414758.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 1, NULL, NULL, 0, 0, '2026-09-14 19:39:18', '2026-09-20 02:41:08'),
(12, 4, '2026-09-15', '13:59:11', 'uploads/attendance/emp4_2026-09-15_in_1789451951.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 1, NULL, NULL, 0, 0, '2026-09-15 05:59:11', '2026-09-20 02:41:08'),
(13, 10, '2026-09-19', '00:59:41', NULL, NULL, NULL, NULL, NULL, 'late', 'Quick clock-in from shift gate', 1, NULL, NULL, 0, 0, '2026-09-19 16:59:41', '2026-09-20 02:41:08'),
(14, 4, '2026-09-19', '01:00:09', NULL, NULL, NULL, NULL, NULL, 'late', 'Quick clock-in from shift gate', 1, NULL, NULL, 0, 0, '2026-09-19 17:00:09', '2026-09-20 02:41:08'),
(16, 11, '2026-09-20', '05:23:42', NULL, '05:23:42', NULL, NULL, NULL, 'late', 'Quick clock-in', 0, NULL, NULL, 0, 0, '2026-09-20 21:23:42', '2026-09-20 21:23:42'),
(17, 4, '2026-09-21', '05:27:17', 'uploads/attendance/emp4_2026-09-20_in_1789939637.jpg', '07:24:52', 'uploads/attendance/emp4_2026-09-21_out_1789946692.jpg', NULL, NULL, 'present', NULL, 0, NULL, NULL, 0, 0, '2026-09-20 21:27:17', '2026-09-20 23:24:52'),
(18, 4, '2026-09-20', '05:36:49', 'uploads/attendance/emp4_2026-09-20_in_1789940209.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 0, NULL, NULL, 0, 0, '2026-09-20 21:36:49', '2026-09-20 21:36:49'),
(19, 3, '2026-09-21', '07:23:34', 'uploads/attendance/emp3_2026-09-21_in_1789946614.jpg', NULL, NULL, NULL, NULL, 'present', NULL, 0, NULL, NULL, 0, 0, '2026-09-20 23:23:34', '2026-09-20 23:23:34');

-- --------------------------------------------------------

--
-- Table structure for table `auth_throttle`
--

CREATE TABLE `auth_throttle` (
  `id` int(11) NOT NULL,
  `identifier` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `succeeded` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auth_throttle`
--

INSERT INTO `auth_throttle` (`id`, `identifier`, `ip_address`, `attempted_at`, `succeeded`) VALUES
(2, 'crew', '::1', '2026-09-21 03:32:56', 1),
(3, 'admin', '::1', '2026-09-21 03:33:16', 1),
(4, 'crew', '::1', '2026-09-21 03:34:26', 1),
(5, 'admin', '::1', '2026-09-21 04:03:50', 1),
(6, 'crew', '::1', '2026-09-21 05:08:08', 1),
(7, 'admin', '::1', '2026-09-21 05:27:33', 1),
(8, 'crew', '::1', '2026-09-21 05:30:06', 1),
(9, 'admin', '::1', '2026-09-21 06:07:38', 1),
(11, 'khylle', '::1', '2026-09-21 06:24:57', 1),
(12, 'admin', '::1', '2026-09-21 06:25:05', 1),
(13, 'khylle', '::1', '2026-09-21 07:14:43', 1),
(14, 'admin', '::1', '2026-09-21 07:15:04', 1),
(15, 'khyllw', '::1', '2026-09-21 07:16:14', 0),
(16, 'khylle', '::1', '2026-09-21 07:16:21', 1),
(17, 'admin', '::1', '2026-09-21 07:16:29', 1),
(18, 'khylle', '::1', '2026-09-21 07:17:11', 1),
(19, 'crew', '::1', '2026-09-21 07:18:20', 1),
(20, 'hr', '::1', '2026-09-21 07:23:20', 1),
(21, 'admin', '::1', '2026-09-21 07:23:42', 1),
(23, 'khylle', '::1', '2026-09-21 07:23:58', 1),
(24, 'crew', '::1', '2026-09-21 07:24:12', 1),
(25, 'admin', '::1', '2026-09-21 07:24:19', 1),
(26, 'crew', '::1', '2026-09-21 07:24:40', 1),
(27, 'admin', '::1', '2026-09-21 12:14:26', 1),
(29, 'crew', '::1', '2026-09-21 12:24:29', 1),
(30, 'hr', '::1', '2026-09-21 12:24:54', 1),
(31, 'crew', '::1', '2026-09-21 12:29:37', 1),
(32, 'admin', '::1', '2026-09-21 12:33:00', 1),
(33, 'supplier3', '::1', '2026-09-21 13:19:06', 0),
(34, 'admin', '::1', '2026-09-21 13:19:21', 1),
(35, 'admin', '::1', '2026-09-22 13:38:27', 1),
(36, 'supplier', '::1', '2026-09-22 14:03:43', 1),
(37, 'admin', '::1', '2026-09-22 14:03:55', 1),
(38, 'supplier3', '::1', '2026-09-22 14:14:25', 0),
(39, 'supplier3', '::1', '2026-09-22 14:14:30', 0),
(40, 'admin', '::1', '2026-09-22 14:14:37', 1),
(41, 'supplier', '::1', '2026-09-22 14:18:59', 1),
(42, 'admin', '::1', '2026-09-22 14:19:17', 1),
(43, 'supplier1', '::1', '2026-09-22 14:20:20', 0),
(44, 'supplier1', '::1', '2026-09-22 14:20:24', 0),
(45, 'supplier', '::1', '2026-09-22 14:20:28', 1),
(46, 'admin', '::1', '2026-09-22 14:20:46', 1),
(47, 'admin', '::1', '2026-09-22 14:22:29', 1),
(48, 'supplier1', '::1', '2026-09-22 14:22:38', 0),
(49, 'supplier', '::1', '2026-09-22 14:22:43', 1),
(50, 'admin', '::1', '2026-09-22 14:24:54', 1),
(51, 'admin', '::1', '2026-09-22 14:29:00', 1),
(52, 'admin', '::1', '2026-09-22 14:29:45', 1),
(53, 'supplier', '::1', '2026-09-22 14:29:55', 1),
(54, 'admin', '::1', '2026-09-22 14:31:35', 1),
(55, 'admin', '::1', '2026-09-22 14:51:03', 1),
(56, 'procurement', '::1', '2026-09-22 14:51:13', 1),
(57, 'supplier1', '::1', '2026-09-22 14:55:16', 0),
(58, 'supplier', '::1', '2026-09-22 14:55:21', 1),
(59, 'admin', '::1', '2026-09-22 14:56:12', 1),
(60, 'supplier', '::1', '2026-09-23 06:14:19', 1),
(62, 'admin', '::1', '2026-09-23 06:14:57', 1),
(63, 'admin', '::1', '2026-09-23 06:16:49', 1),
(64, 'supplier', '::1', '2026-09-23 06:16:57', 1),
(65, 'admin', '::1', '2026-09-23 06:18:48', 1),
(66, 'supplier', '::1', '2026-09-23 06:28:00', 1),
(68, 'admin', '::1', '2026-09-23 06:35:22', 1),
(69, 'admin', '::1', '2026-09-25 15:52:03', 1),
(70, 'supplier1', '::1', '2026-09-25 18:25:15', 0),
(71, 'supplier', '::1', '2026-09-25 18:25:20', 1),
(72, 'admin', '::1', '2026-09-25 18:26:05', 1),
(73, 'supplier', '::1', '2026-09-25 19:06:05', 1),
(74, 'admin', '::1', '2026-09-25 19:07:17', 1),
(75, 'supplier1', '::1', '2026-09-25 22:26:21', 0),
(76, 'supplier', '::1', '2026-09-25 22:26:24', 1),
(77, 'admin', '::1', '2026-09-25 22:27:00', 1),
(78, 'admin', '::1', '2026-09-25 22:30:43', 1),
(79, 'supplier', '::1', '2026-09-25 22:32:42', 1),
(80, 'admin', '::1', '2026-09-25 22:33:21', 1),
(81, 'supplier', '::1', '2026-09-25 22:41:17', 1),
(82, 'admin', '::1', '2026-09-25 22:41:57', 1),
(83, 'supplier', '::1', '2026-09-25 22:42:47', 1),
(84, 'admin', '::1', '2026-09-25 22:43:05', 1),
(85, 'supplier', '::1', '2026-09-25 22:43:37', 1),
(86, 'admin', '::1', '2026-09-25 22:44:31', 1),
(87, 'admin', '::1', '2026-09-25 22:59:59', 1),
(88, 'supplier', '::1', '2026-09-25 23:00:09', 1),
(89, 'admin', '::1', '2026-09-25 23:11:35', 1),
(90, 'admin', '::1', '2026-09-25 23:15:50', 1),
(91, 'supplier', '::1', '2026-09-25 23:16:02', 1),
(92, 'admin', '::1', '2026-09-25 23:17:13', 1),
(93, 'supplier', '::1', '2026-09-25 23:58:03', 1),
(94, 'admin', '::1', '2026-09-25 23:59:38', 1),
(97, 'supplier', '::1', '2026-09-26 00:00:32', 1),
(98, 'admin', '::1', '2026-09-26 00:01:00', 1),
(100, 'supplier', '::1', '2026-09-26 00:32:51', 1),
(102, 'admin', '::1', '2026-09-26 00:33:20', 1),
(103, 'supplier', '::1', '2026-09-26 00:44:29', 1),
(104, 'admin', '::1', '2026-09-26 01:45:20', 1);

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
  `status` enum('submitted','under_review','shortlisted','selected','not_selected','withdrawn','rejected') DEFAULT 'submitted',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivery_date` date DEFAULT NULL,
  `notes_attachment` varchar(255) DEFAULT NULL,
  `selection_reason` text DEFAULT NULL,
  `finance_status` enum('pending','approved','rejected','not_required') DEFAULT 'pending',
  `finance_reviewed_by` int(11) DEFAULT NULL,
  `finance_reviewed_at` datetime DEFAULT NULL,
  `finance_review_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bids`
--

INSERT INTO `bids` (`id`, `rfq_id`, `supplier_id`, `quoted_total`, `lead_time_days`, `notes`, `status`, `submitted_at`, `delivery_date`, `notes_attachment`, `selection_reason`, `finance_status`, `finance_reviewed_by`, `finance_reviewed_at`, `finance_review_notes`) VALUES
(1, 1, 1, 2232.00, 2, '', 'selected', '2026-08-24 19:47:08', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(3, 4, 1, 0.01, 1, '', 'selected', '2026-08-25 07:15:26', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(4, 5, 1, 2333.01, 1, '', 'selected', '2026-08-31 19:54:43', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(6, 3, 1, 112001.00, 2, '', 'selected', '2026-08-31 20:19:04', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(7, 6, 1, 3233.00, 2, 'dad', 'selected', '2026-08-31 20:51:03', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(8, 7, 1, 2456.00, 1, '', 'selected', '2026-08-31 22:13:30', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(9, 8, 1, 1000.00, 3, 'received', 'selected', '2026-09-01 02:22:04', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(14, 9, 1, 20.00, 3, 'paki-bilis', 'selected', '2026-09-01 02:31:14', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(15, 10, 1, 9999999999.99, 5, 'paki-bilis', 'selected', '2026-09-01 03:28:34', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(17, 11, 1, 1.00, 1, 'ASAP needed', 'selected', '2026-09-01 03:47:26', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(19, 12, 1, 1.00, 1, 'ASAP', 'selected', '2026-09-01 04:12:06', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(20, 13, 1, 1.00, 1, '', 'selected', '2026-09-07 15:34:47', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(21, 14, 1, 500.00, 1, '', 'selected', '2026-09-10 19:16:58', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(22, 15, 1, 150.00, 1, '', 'selected', '2026-09-14 19:24:49', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(23, 16, 1, 2000.00, 0, '', 'submitted', '2026-09-22 22:24:02', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(29, 22, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 10:14:03', NULL, NULL, NULL, 'approved', 1, '2026-09-25 18:14:03', 'Budget and pricing verified within department limits'),
(30, 23, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 10:20:56', NULL, NULL, NULL, 'approved', 1, '2026-09-25 18:20:56', 'Budget and pricing verified within department limits'),
(31, 24, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 10:22:11', NULL, NULL, NULL, 'approved', 1, '2026-09-25 18:22:11', 'Budget and pricing verified within department limits'),
(32, 25, 1, 4800.00, 2, 'In stock ready to ship', 'withdrawn', '2026-09-25 10:48:25', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(33, 26, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 10:48:34', NULL, NULL, NULL, 'approved', 1, '2026-09-25 18:48:34', 'Budget and pricing verified within department limits'),
(34, 27, 1, 4800.00, 2, 'In stock ready to ship', 'withdrawn', '2026-09-25 11:04:50', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(35, 28, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 11:05:54', NULL, NULL, NULL, 'approved', 1, '2026-09-25 19:05:54', 'Budget and pricing verified within department limits'),
(36, 30, 1, 48000.00, 7, NULL, 'selected', '2026-09-25 14:13:42', NULL, NULL, NULL, 'approved', 1, '2026-09-25 22:13:42', 'Approved based on high demand and supplier warranty'),
(37, 31, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 14:13:48', NULL, NULL, NULL, 'approved', 1, '2026-09-25 22:13:48', 'Budget and pricing verified within department limits'),
(38, 32, 1, 401.00, 3, '', 'selected', '2026-09-25 14:24:07', NULL, NULL, 'Best Offer', 'approved', NULL, NULL, NULL),
(40, 29, 1, 1552.00, 0, '', 'submitted', '2026-09-25 14:26:51', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(41, 33, 1, 1000.00, 1, '', 'selected', '2026-09-25 14:33:10', NULL, NULL, 'Testing', 'approved', NULL, NULL, NULL),
(42, 34, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 14:39:48', NULL, NULL, NULL, 'approved', 1, '2026-09-25 22:39:48', 'Budget and pricing verified within department limits'),
(43, 35, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 14:57:49', NULL, NULL, NULL, 'approved', 1, '2026-09-25 22:57:49', 'Budget and pricing verified within department limits'),
(44, 36, 1, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 15:10:36', NULL, NULL, NULL, 'approved', 1, '2026-09-25 23:10:37', 'Budget and pricing verified within department limits'),
(45, 37, 2, 11500.00, 3, 'High elevation freshly roasted beans', 'selected', '2026-09-25 15:37:26', NULL, NULL, NULL, 'approved', 1, '2026-09-25 23:37:26', 'Budget and pricing verified within department limits'),
(46, 38, 2, 3700.00, 1, '', 'selected', '2026-09-25 16:32:32', NULL, NULL, 'Fastest Delivery', 'approved', NULL, NULL, NULL),
(48, 38, 1, 3638.00, 2, '', 'not_selected', '2026-09-25 16:33:07', NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(49, 39, 1, 10000.00, 3, '1425', 'selected', '2026-09-25 16:35:16', NULL, NULL, 'dsa', 'approved', 1, '2026-09-26 00:36:51', '');

-- --------------------------------------------------------

--
-- Table structure for table `bid_items`
--

CREATE TABLE `bid_items` (
  `id` int(11) NOT NULL,
  `bid_id` int(11) NOT NULL,
  `requisition_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `quoted_qty` decimal(10,2) NOT NULL,
  `unit` varchar(20) DEFAULT 'pcs',
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bid_items`
--

INSERT INTO `bid_items` (`id`, `bid_id`, `requisition_item_id`, `item_name`, `quoted_qty`, `unit`, `unit_price`, `line_total`) VALUES
(5, 29, 29, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(6, 30, 30, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(7, 31, 31, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(8, 33, 34, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(9, 35, 36, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(10, 36, 37, 'Commercial Espresso Unit', 1.00, 'unit', 48000.00, 48000.00),
(11, 37, 38, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(12, 42, 41, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(13, 43, 45, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(14, 44, 48, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(15, 45, 53, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00);

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
-- Table structure for table `delivery_notices`
--

CREATE TABLE `delivery_notices` (
  `id` int(11) NOT NULL,
  `notice_ref` varchar(50) NOT NULL,
  `po_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `carrier_name` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `shipped_date` date NOT NULL,
  `expected_arrival_date` date DEFAULT NULL,
  `fulfillment_status` enum('preparing','partially_shipped','shipped','delivered') DEFAULT 'shipped',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_notices`
--

INSERT INTO `delivery_notices` (`id`, `notice_ref`, `po_id`, `supplier_id`, `carrier_name`, `tracking_number`, `shipped_date`, `expected_arrival_date`, `fulfillment_status`, `notes`, `created_at`) VALUES
(5, 'ASN-TEST-1790331731', 21, 1, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 10:22:11'),
(6, 'ASN-TEST-1790333314', 22, 1, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 10:48:34'),
(7, 'ASN-TEST-1790334354', 23, 1, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 11:05:54'),
(8, 'ASN-TEST-1790345628', 24, 1, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 14:13:48'),
(9, 'ASN-TEST-1790347188', 25, 1, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 14:39:48'),
(10, 'ASN-TEST-1790348269', 29, 1, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 14:57:49'),
(11, 'ASN-TEST-1790349037', 33, 1, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 15:10:37'),
(12, 'KM-ASN-2026-TEST-1790350541', 35, 3, 'LBC Express Express Freight', 'TRK-ASN-107956', '2026-09-25', '2026-09-27', 'delivered', '3 bags safely sealed on pallet', '2026-09-25 15:35:41'),
(14, 'ASN-TEST-1790350646', 40, 2, 'LBC Express', 'TRK-987654321', '2026-09-25', '2026-09-27', 'delivered', '20kg Arabica beans dispatched in 2 bags', '2026-09-25 15:37:26'),
(15, 'KM-ASN-2026-0034', 34, 1, 'dasd', 'sad', '2026-09-25', NULL, 'shipped', NULL, '2026-09-25 15:58:47'),
(16, 'KM-ASN-2026-0034-2', 34, 1, 'JT', '213123', '2026-09-25', '2026-09-27', 'shipped', NULL, '2026-09-25 15:59:22');

-- --------------------------------------------------------

--
-- Table structure for table `delivery_notice_items`
--

CREATE TABLE `delivery_notice_items` (
  `id` int(11) NOT NULL,
  `delivery_notice_id` int(11) NOT NULL,
  `requisition_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `shipped_qty` decimal(10,2) NOT NULL,
  `unit` varchar(20) DEFAULT 'pcs'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_notice_items`
--

INSERT INTO `delivery_notice_items` (`id`, `delivery_notice_id`, `requisition_item_id`, `item_name`, `shipped_qty`, `unit`) VALUES
(1, 5, 31, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(2, 6, 34, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(3, 7, 36, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(4, 8, 38, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(5, 9, 41, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(6, 10, 45, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(7, 11, 48, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(8, 12, 49, 'Arabica Special Reserve', 30.00, 'kg'),
(10, 14, 53, 'Premium Arabica Whole Beans', 20.00, 'kg'),
(11, 15, 40, 'Premium Arabica Whole Beans', 10.00, 'kg'),
(12, 16, 40, 'Premium Arabica Whole Beans', 10.00, 'kg');

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
  `branch` varchar(80) DEFAULT 'Main',
  `contact_number` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `employment_type` varchar(30) DEFAULT NULL,
  `base_salary` decimal(10,2) DEFAULT NULL,
  `pay_type` enum('hourly','daily','monthly','commission') NOT NULL DEFAULT 'monthly',
  `pay_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tip_share` tinyint(1) NOT NULL DEFAULT 1,
  `payment_method` enum('cash','bank_transfer','payroll_card','ewallet') NOT NULL DEFAULT 'cash',
  `bank_name` varchar(80) DEFAULT NULL,
  `bank_account_last4` varchar(4) DEFAULT NULL,
  `tax_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `user_id`, `employee_code`, `firstname`, `lastname`, `position`, `department`, `branch`, `contact_number`, `email`, `hire_date`, `employment_type`, `base_salary`, `pay_type`, `pay_rate`, `commission_rate`, `tip_share`, `payment_method`, `bank_name`, `bank_account_last4`, `tax_exempt`, `status`, `created_at`) VALUES
(1, 2, 'emp-002', 'Khylle', 'Roque', 'Cashier', 'hr', 'Main', '0923248990', 'khyllechester.roque07@gmail.com', '2000-09-09', 'Full-time', 0.00, 'monthly', 0.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-01 17:10:32'),
(2, 1, '#2322', 'Admin', 'User', 'Administrator', 'admin', 'Main', '0923248990', 'admin@kofeecafe.local', '2026-08-30', 'Full-time', 20000.00, 'monthly', 20000.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-29 21:02:21'),
(3, 4, '2323', 'Hr', 'Test', 'Hr', 'hr', 'Main', '2190319241', 'hr@gmail.com', '2026-08-30', 'Full-time', 23233.00, 'monthly', 23233.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-30 00:38:17'),
(4, 8, '1021', 'Crew', 'Test', 'Crew', 'crew', 'Main', '098489184', 'crew@gmail.com', '2026-09-01', 'Contract', 20000.00, 'monthly', 20000.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-31 19:16:53'),
(5, 7, '2314', 'finance', 'testing', 'Financer', 'finance', 'Main', '0923248990', 'finance@gmail.com', '2026-09-01', 'Full-time', 22353.00, 'monthly', 22353.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-31 19:18:44'),
(6, 6, '2141651', 'manager', 'test', 'Manager', 'manager', 'Main', '98409818945', 'manager@gmail.com', '2026-09-01', 'Full-time', 25434.00, 'monthly', 25434.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-31 19:19:38'),
(8, 10, '2859', 'ops', 'test', 'Operations', 'ops', 'Main', '87685568', 'ops@gmail.com', '2026-09-01', 'Full-time', 524363.00, 'monthly', 524363.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-31 19:33:41'),
(9, 11, '124125', 'Procurment', 'Testing', 'Officer', 'admin', 'Main', '57978976', 'procurement@gmail.com', '2026-09-01', 'Full-time', 51473.00, 'monthly', 51473.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-31 19:34:39'),
(10, 13, '5235', 'Receiving', 'Testing', 'Warehouse', 'admin', 'Main', '3646734', 'receiving@gmail.com', '2026-09-01', 'Full-time', 53443.99, 'monthly', 53443.99, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-08-31 19:36:38'),
(11, 16, 'EMP-0016', 'testing', 'Test', 'Crew', 'Operations', 'Main', NULL, 'crew2@gmail.com', '2026-09-21', 'Full-time', NULL, 'monthly', 0.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-09-20 21:23:35'),
(13, 12, 'EMP-0012', 'Supplier', 'Testing', 'Suppliers', 'Operations', 'Main', NULL, 'supplier@gmail.com', '2026-09-22', 'Full-time', NULL, 'monthly', 0.00, 0.00, 1, 'cash', NULL, NULL, 0, 'active', '2026-09-22 06:29:55');

-- --------------------------------------------------------

--
-- Table structure for table `employee_loans`
--

CREATE TABLE `employee_loans` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `loan_type` varchar(60) NOT NULL DEFAULT 'Cash Advance',
  `principal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `per_period_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `start_date` date NOT NULL,
  `status` enum('pending_approval','active','completed','cancelled','declined') NOT NULL DEFAULT 'active',
  `notes` varchar(300) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `goods_receipts`
--

CREATE TABLE `goods_receipts` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `delivery_notice_id` int(11) DEFAULT NULL,
  `received_by` int(11) NOT NULL,
  `status` enum('pending','partial','complete','discrepancy') NOT NULL DEFAULT 'pending',
  `notes` varchar(255) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `goods_receipts`
--

INSERT INTO `goods_receipts` (`id`, `po_id`, `delivery_notice_id`, `received_by`, `status`, `notes`, `received_at`) VALUES
(1, 5, NULL, 1, 'complete', ' | Resolution: Resolve', '2026-08-31 20:31:07'),
(2, 5, NULL, 1, 'complete', '', '2026-08-31 20:31:19'),
(3, 7, NULL, 1, 'complete', '', '2026-08-31 22:18:49'),
(4, 6, NULL, 1, 'complete', 'Thank you very much!', '2026-09-01 02:20:28'),
(5, 8, NULL, 1, 'complete', '', '2026-09-01 02:50:55'),
(6, 9, NULL, 13, 'complete', 'Thank you po!', '2026-09-01 03:34:15'),
(7, 10, NULL, 6, 'complete', '', '2026-09-01 03:44:05'),
(8, 11, NULL, 13, 'partial', 'Nice | Resolution: 1', '2026-09-01 03:50:19'),
(9, 11, NULL, 13, 'complete', ' | Resolution: replacement', '2026-09-01 03:50:42'),
(10, 12, NULL, 13, 'complete', '', '2026-09-01 04:13:31'),
(11, 13, NULL, 13, 'partial', ' | Resolution: replacement', '2026-09-07 15:35:53'),
(12, 13, NULL, 13, 'partial', ' | Resolution: replacement', '2026-09-07 15:36:05'),
(13, 13, NULL, 13, 'complete', '', '2026-09-07 15:36:58'),
(14, 14, NULL, 13, 'complete', '', '2026-09-10 19:18:06'),
(15, 15, NULL, 13, 'complete', '', '2026-09-14 19:25:36'),
(19, 21, 5, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 10:22:11'),
(20, 22, 6, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 10:48:34'),
(21, 23, 7, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 11:05:54'),
(22, 24, 8, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 14:13:48'),
(23, 25, 9, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 14:39:48'),
(24, 29, 10, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 14:57:49'),
(25, 33, 11, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 15:10:37'),
(26, 34, NULL, 1, 'partial', ' | Resolution: asfgawd', '2026-09-25 15:18:11'),
(27, 34, NULL, 1, 'partial', ' | Resolution: asd', '2026-09-25 15:18:29'),
(28, 35, 12, 1, 'complete', 'Received all 30kg in great condition', '2026-09-25 15:35:41'),
(30, 40, 14, 1, 'complete', 'Goods received in excellent condition', '2026-09-25 15:37:26');

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
(10, 15, 17, 'Espresso', 'ml', 150.00, 150.00, 'good', NULL),
(14, 19, 31, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL),
(15, 20, 34, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL),
(16, 21, 36, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL),
(17, 22, 38, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL),
(18, 23, 41, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL),
(19, 24, 45, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL),
(20, 25, 48, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL),
(21, 26, 40, 'Premium Arabica Whole Beans', 'kg', 10.00, 9.00, 'good', NULL),
(22, 27, 40, 'Premium Arabica Whole Beans', 'kg', 10.00, 1.00, 'good', NULL),
(23, 28, 49, 'Arabica Special Reserve', 'kg', 30.00, 30.00, 'good', NULL),
(25, 30, 53, 'Premium Arabica Whole Beans', 'kg', 20.00, 20.00, 'good', NULL);

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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `default_supplier_id` int(11) DEFAULT NULL,
  `reorder_quantity` decimal(10,2) NOT NULL DEFAULT 10.00,
  `auto_reorder` tinyint(1) NOT NULL DEFAULT 0,
  `last_auto_reorder_at` datetime DEFAULT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `cost_unit` varchar(20) DEFAULT 'pcs'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredients`
--

INSERT INTO `ingredients` (`id`, `cat_id`, `name`, `brand`, `unit`, `quantity`, `reorder_at`, `archived_at`, `created_at`, `updated_at`, `default_supplier_id`, `reorder_quantity`, `auto_reorder`, `last_auto_reorder_at`, `unit_cost`, `cost_unit`) VALUES
(1, 1, 'Libica Beans', 'Locals', 'g', 100.00, 5.00, NULL, '2026-08-01 18:26:47', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(5, 1, 'Robusta Beans', 'Locals', 'g', 100.00, 10.00, NULL, '2026-08-05 05:59:04', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(6, 1, 'Arabica Beans', 'Local', 'g', 1000.00, 0.00, NULL, '2026-08-07 14:45:37', '2026-09-13 18:04:25', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(7, 1, 'Excelsa Beans', 'Local', 'g', 100.00, 5.00, NULL, '2026-09-01 06:10:19', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(8, 6, 'Ice', 'Nestle', 'g', 76.00, 100.00, NULL, '2026-09-11 03:15:15', '2026-09-21 13:08:05', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(9, 1, 'Espresso', NULL, 'ml', 246.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-21 03:31:53', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(10, 1, 'Brewed Coffee', NULL, 'ml', 100.00, 2000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(11, 6, 'Water', NULL, 'ml', 100.00, 5000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(12, 2, 'Whole Milk', NULL, 'ml', 96.00, 2000.00, NULL, '2026-09-11 03:25:44', '2026-09-13 20:06:29', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(13, 2, 'Condensed Milk', NULL, 'ml', 10000.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-13 18:04:26', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(14, 2, 'Heavy Cream', NULL, 'ml', 100.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(15, 6, 'Whipped Cream', NULL, 'g', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(16, 3, 'Chocolate Sauce', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(17, 3, 'White Chocolate Sauce', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(18, 3, 'Caramel Syrup', NULL, 'ml', 16.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-21 13:08:05', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(19, 3, 'Vanilla Syrup', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(20, 3, 'Irish Cream Syrup', NULL, 'ml', 100.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(21, 4, 'Matcha Powder', NULL, 'g', 100.00, 250.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(22, 6, 'Cocoa Powder', NULL, 'g', 100.00, 250.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(23, 6, 'Cinnamon Powder', NULL, 'g', 100.00, 100.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(24, 6, 'Brown Sugar', '', 'g', 0.00, 500.00, NULL, '2026-09-11 03:25:44', '2026-09-21 13:10:42', NULL, 0.00, 1, '2026-09-21 13:10:42', NULL, 'pcs'),
(25, 6, 'Sugar', NULL, 'g', 100.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(26, 2, 'Ice Cream', NULL, 'g', 100.00, 1000.00, NULL, '2026-09-11 03:25:44', '2026-09-11 03:26:43', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(40, 1, 'Salted Caramel', 'ios', '12', 1.00, 123.00, NULL, '2026-09-11 04:01:48', '2026-09-11 04:01:48', NULL, 10.00, 0, NULL, NULL, 'pcs'),
(41, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 35.00, 10.00, NULL, '2026-09-25 18:22:11', '2026-09-25 23:18:29', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(42, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 18:22:11', '2026-09-25 18:22:11', NULL, 10.00, 0, NULL, NULL, NULL),
(43, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 25.00, 10.00, NULL, '2026-09-25 18:48:34', '2026-09-25 18:48:34', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(44, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 18:48:35', '2026-09-25 18:48:35', NULL, 10.00, 0, NULL, NULL, NULL),
(45, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 25.00, 10.00, NULL, '2026-09-25 19:05:54', '2026-09-25 19:05:54', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(46, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 19:05:54', '2026-09-25 19:05:54', NULL, 10.00, 0, NULL, NULL, NULL),
(47, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 25.00, 10.00, NULL, '2026-09-25 22:13:48', '2026-09-25 22:13:48', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(48, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 22:13:48', '2026-09-25 22:13:48', NULL, 10.00, 0, NULL, NULL, NULL),
(49, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 25.00, 10.00, NULL, '2026-09-25 22:39:48', '2026-09-25 22:39:48', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(50, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 22:39:48', '2026-09-25 22:39:48', NULL, 10.00, 0, NULL, NULL, NULL),
(51, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 25.00, 10.00, NULL, '2026-09-25 22:57:49', '2026-09-25 22:57:49', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(52, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 22:57:49', '2026-09-25 22:57:49', NULL, 10.00, 0, NULL, NULL, NULL),
(53, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 25.00, 10.00, NULL, '2026-09-25 23:10:37', '2026-09-25 23:10:37', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(54, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 23:10:37', '2026-09-25 23:10:37', NULL, 10.00, 0, NULL, NULL, NULL),
(55, 1, 'Premium Arabica Whole Beans', NULL, 'kg', 25.00, 10.00, NULL, '2026-09-25 23:37:26', '2026-09-25 23:37:26', NULL, 20.00, 0, NULL, 575.00, 'kg'),
(56, 1, 'Unpriced Ingredient Item', NULL, 'kg', 1.00, 5.00, NULL, '2026-09-25 23:37:26', '2026-09-25 23:37:26', NULL, 10.00, 0, NULL, NULL, NULL),
(58, 5, 'TEST_ING_1790353489', 'TestBrand', 'kg', 3.00, 10.00, NULL, '2026-09-26 00:24:49', '2026-09-26 00:24:49', NULL, 25.00, 1, '2026-09-26 00:24:49', 145.50, 'kg');

-- --------------------------------------------------------

--
-- Table structure for table `ingredient_batches`
--

CREATE TABLE `ingredient_batches` (
  `id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `grn_id` int(11) DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `batch_ref` varchar(100) DEFAULT NULL,
  `qty_received` decimal(10,2) NOT NULL DEFAULT 0.00,
  `qty_remaining` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(20) NOT NULL DEFAULT 'pcs',
  `delivery_date` datetime NOT NULL DEFAULT current_timestamp(),
  `expiry_date` date DEFAULT NULL,
  `expiry_source` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `status` enum('active','expired','depleted','discarded') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredient_batches`
--

INSERT INTO `ingredient_batches` (`id`, `ingredient_id`, `grn_id`, `po_id`, `supplier_id`, `batch_ref`, `qty_received`, `qty_remaining`, `unit`, `delivery_date`, `expiry_date`, `expiry_source`, `status`, `notes`, `recorded_by`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, NULL, NULL, 'INIT-1', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(2, 5, NULL, NULL, NULL, 'INIT-5', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(3, 6, NULL, NULL, NULL, 'INIT-6', 1000.00, 1000.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(4, 7, NULL, NULL, NULL, 'INIT-7', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(5, 9, NULL, NULL, NULL, 'INIT-9', 246.00, 246.00, 'ml', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(6, 10, NULL, NULL, NULL, 'INIT-10', 100.00, 100.00, 'ml', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(7, 40, NULL, NULL, NULL, 'INIT-40', 1.00, 1.00, '12', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(8, 12, NULL, NULL, NULL, 'INIT-12', 96.00, 96.00, 'ml', '2026-09-20 00:57:56', '2026-09-27', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(9, 13, NULL, NULL, NULL, 'INIT-13', 10000.00, 10000.00, 'ml', '2026-09-20 00:57:56', '2026-09-27', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(10, 14, NULL, NULL, NULL, 'INIT-14', 100.00, 100.00, 'ml', '2026-09-20 00:57:56', '2026-09-27', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(11, 26, NULL, NULL, NULL, 'INIT-26', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-09-27', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(12, 16, NULL, NULL, NULL, 'INIT-16', 100.00, 100.00, 'ml', '2026-09-20 00:57:56', '2026-12-19', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(13, 17, NULL, NULL, NULL, 'INIT-17', 100.00, 100.00, 'ml', '2026-09-20 00:57:56', '2026-12-19', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(14, 18, NULL, NULL, NULL, 'INIT-18', 96.00, 96.00, 'ml', '2026-09-20 00:57:56', '2026-12-19', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(15, 19, NULL, NULL, NULL, 'INIT-19', 100.00, 100.00, 'ml', '2026-09-20 00:57:56', '2026-12-19', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(16, 20, NULL, NULL, NULL, 'INIT-20', 100.00, 100.00, 'ml', '2026-09-20 00:57:56', '2026-12-19', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(17, 21, NULL, NULL, NULL, 'INIT-21', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-11-19', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(18, 8, NULL, NULL, NULL, 'INIT-8', 96.00, 96.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(19, 11, NULL, NULL, NULL, 'INIT-11', 100.00, 100.00, 'ml', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(20, 15, NULL, NULL, NULL, 'INIT-15', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(21, 22, NULL, NULL, NULL, 'INIT-22', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(22, 23, NULL, NULL, NULL, 'INIT-23', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(23, 24, NULL, NULL, NULL, 'INIT-24', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(24, 25, NULL, NULL, NULL, 'INIT-25', 100.00, 100.00, 'g', '2026-09-20 00:57:56', '2026-10-20', 'auto', 'active', 'Opening balance migrated from inventory', NULL, '2026-09-20 00:57:56', '2026-09-20 00:57:56'),
(25, 41, 26, 34, 1, 'GRN-26-40', 9.00, 9.00, 'kg', '2026-09-25 23:18:11', '2026-10-25', 'auto', 'active', 'Received against PO #34', 1, '2026-09-25 23:18:11', '2026-09-25 23:18:11'),
(26, 41, 27, 34, 1, 'GRN-27-40', 1.00, 1.00, 'kg', '2026-09-25 23:18:29', '2026-10-25', 'auto', 'active', 'Received against PO #34', 1, '2026-09-25 23:18:29', '2026-09-25 23:18:29');

-- --------------------------------------------------------

--
-- Table structure for table `ingredient_categories`
--

CREATE TABLE `ingredient_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `icon` varchar(10) NOT NULL DEFAULT '?',
  `shelf_life_days` int(11) NOT NULL DEFAULT 30
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ingredient_categories`
--

INSERT INTO `ingredient_categories` (`id`, `name`, `icon`, `shelf_life_days`) VALUES
(1, 'Coffee', 'coffee', 30),
(2, 'Milk', 'milk', 7),
(3, 'Syrups', 'ice-coffee', 90),
(4, 'Tea', 'cup-tea', 60),
(5, 'Bakery', 'pastry', 5),
(6, 'Other', 'package', 30);

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
(16, 11, 18, 1.00, 8, '2026-09-13 20:06:29'),
(17, 37, 8, 11.25, 10, '2026-09-20 01:02:54'),
(18, 37, 18, 50.00, 10, '2026-09-20 01:02:54'),
(19, 49, 24, 100.00, 1, '2026-09-21 13:07:40'),
(20, 50, 8, 5.00, 1, '2026-09-21 13:08:13'),
(21, 50, 18, 20.00, 1, '2026-09-21 13:08:13'),
(22, 50, 24, 100.00, 1, '2026-09-21 13:08:13'),
(23, 51, 8, 5.00, 1, '2026-09-21 13:08:51'),
(24, 51, 18, 20.00, 1, '2026-09-21 13:08:51'),
(25, 51, 24, 100.00, 1, '2026-09-21 13:08:51');

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
  `status` enum('submitted','pending','under_review','needs_correction','matched','approved','scheduled','paid','disputed','rejected','cancelled') DEFAULT 'submitted',
  `match_notes` varchar(255) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `shipping_fee` decimal(12,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'PHP',
  `correction_notes` text DEFAULT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `version` int(11) DEFAULT 1,
  `parent_invoice_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `po_id`, `supplier_id`, `invoice_number`, `invoice_date`, `due_date`, `subtotal`, `tax_amount`, `total_amount`, `status`, `match_notes`, `uploaded_by`, `created_at`, `shipping_fee`, `discount_amount`, `currency`, `correction_notes`, `contract_id`, `attachment_path`, `version`, `parent_invoice_id`) VALUES
(1, 3, 1, '56256', '2026-09-01', '2026-09-01', 112000.00, 0.00, 112000.00, 'disputed', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 1, '2026-08-31 18:34:01', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(2, 1, 1, '56256', NULL, NULL, 0.00, 233.00, 233.00, 'disputed', '\"Chocolate\" invoiced for 0.25 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱233.00 differs from PO total ₱2,232.00 by 89.6% (tolerance is 3.0%).', 1, '2026-08-31 20:14:53', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(3, 4, 1, '56256', NULL, NULL, 2806.00, 2122.00, 4928.00, 'cancelled', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 12, '2026-08-31 20:26:11', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(4, 5, 1, '2323', '2026-09-01', '2026-09-01', 11200.00, 2323.00, 13523.00, 'paid', '\"plastic cups\" invoiced for 56.00 but only 0 received. | Invoice total ₱13,523.00 differs from PO total ₱112,001.00 by 87.9% (tolerance is 3.0%). | Override: Ovver', 1, '2026-08-31 20:32:32', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(5, 7, 1, '56256', '2026-09-01', '2026-09-04', 2454.00, 0.00, 2454.00, 'paid', '\"Arabica Beans\" invoiced for 23.00 but only 0 received. | \"Excelsa Beans\" invoiced for 33.00 but only 0 received. | Override: Taxes', 7, '2026-08-31 22:19:36', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(6, 9, 1, '-1111', NULL, NULL, 50.00, 0.00, 50.00, 'pending', NULL, 7, '2026-09-01 03:35:16', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(7, 9, 1, '1', '2026-09-01', '2026-09-05', 50.00, 12.00, 62.00, 'paid', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%). | Override: ad', 7, '2026-09-01 03:35:43', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(8, 10, 1, '10', '2026-09-01', '2026-09-01', 250000.00, 30.00, 250030.00, 'paid', '\"Koya Dsd\" invoiced for 500.00 but only 0 received. | Invoice total ₱250,030.00 differs from PO total ₱1,000.00 by 24903.0% (tolerance is 3.0%). | Override: done', 7, '2026-09-01 03:52:30', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(9, 12, 1, '10', '2026-09-01', '2026-09-01', 500.00, 0.00, 500.00, 'paid', 'Invoice total ₱500.00 differs from PO total ₱1.00 by 49900.0% (tolerance is 3.0%). | Override: approve', 7, '2026-09-01 04:14:18', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(10, 13, 1, '13', '2026-09-07', '2026-09-07', 100000.00, 1000.00, 101000.00, 'paid', 'Invoice total ₱101,000.00 differs from PO total ₱1.00 by 10099900.0% (tolerance is 3.0%). | Override: required', 7, '2026-09-07 15:37:57', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(11, 14, 1, '14', '2026-09-11', '2026-09-11', 500.00, 0.00, 500.00, 'paid', 'Matched clean: invoice ₱500.00 vs PO ₱500.00 (0.0% variance).', 7, '2026-09-10 19:18:44', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(12, 15, 1, '15', '2026-09-15', '2026-09-15', 150.00, 0.00, 150.00, 'paid', 'Matched clean: invoice ₱150.00 vs PO ₱150.00 (0.0% variance).', 7, '2026-09-14 19:26:10', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(13, 6, 1, 'ada', '2026-09-25', '2026-09-26', 53429.23, 0.00, 53429.23, 'pending', NULL, 1, '2026-09-25 08:31:29', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(17, 21, 1, 'INV-TEST-1790331731', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 10:22:11', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(18, 22, 1, 'INV-TEST-1790333314', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 10:48:34', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(19, 23, 1, 'INV-TEST-1790334354', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 11:05:54', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(20, 24, 1, 'INV-TEST-1790345628', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 14:13:48', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(21, 25, 1, 'INV-TEST-1790347188', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 14:39:48', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(22, 29, 1, 'INV-TEST-1790348269', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 14:57:49', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(23, 33, 1, 'INV-TEST-1790349037', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 15:10:37', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(24, 40, 2, 'INV-TEST-1790350646', '2026-09-25', '2026-10-25', 11500.00, 0.00, 11500.00, 'paid', 'Clean match verified', 1, '2026-09-25 15:37:26', 0.00, 0.00, 'PHP', 'Tax calculation missing TIN', NULL, NULL, 1, NULL),
(26, 35, 3, '56256', NULL, NULL, 15000.00, 0.00, 15000.00, 'submitted', NULL, 1, '2026-09-25 16:28:38', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL),
(30, 34, 1, 'dasda', '2026-09-26', '2026-09-26', 750.00, 0.00, 750.00, 'paid', 'Matched clean: invoice ₱750.00 vs PO ₱750.00 (0.0% variance).', 1, '2026-09-25 17:56:35', 0.00, 0.00, 'PHP', NULL, NULL, NULL, 1, NULL);

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
(13, 12, 17, 'Espresso', 150.00, 1.00, 150.00),
(14, 13, 6, 'trtr', 23.00, 2323.01, 53429.23),
(18, 17, 31, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(19, 18, 34, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(20, 19, 36, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(21, 20, 38, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(22, 21, 41, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(23, 22, 45, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(24, 23, 48, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(25, 24, 53, 'Premium Arabica Whole Beans', 20.00, 575.00, 11500.00),
(27, 26, 49, 'Arabica Special Reserve', 30.00, 500.00, 15000.00),
(28, 30, 40, 'Premium Arabica Whole Beans', 10.00, 75.00, 750.00);

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `id` int(11) NOT NULL,
  `application_code` varchar(50) NOT NULL,
  `job_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `city` varchar(150) NOT NULL,
  `experience` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `resume_filename` varchar(255) NOT NULL,
  `resume_path` varchar(255) NOT NULL,
  `additional_message` text DEFAULT NULL,
  `privacy_accepted` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('review','interview','decision','hired','rejected') NOT NULL DEFAULT 'review',
  `reviewer_notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `job_applications`
--

INSERT INTO `job_applications` (`id`, `application_code`, `job_id`, `first_name`, `last_name`, `email`, `phone`, `city`, `experience`, `start_date`, `resume_filename`, `resume_path`, `additional_message`, `privacy_accepted`, `status`, `reviewer_notes`, `ip_address`, `created_at`, `updated_at`) VALUES
(1, 'KM-2026-BAR-7179', 1, 'Maria', 'Santos', 'maria.santos@example.com', '09171234567', 'Manila', '1 - 2 years', '2026-10-01', 'sample_resume.pdf', 'uploads/resumes/resume_km_2026_bar_7179_1789831453.pdf', 'I love crafting latte art and providing friendly hospitality.', 1, 'review', NULL, '::1', '2026-09-19 23:24:13', '2026-09-19 23:24:13'),
(2, 'KM-2026-BAR-7821', 1, 'Aldrix', 'Maesa', 'aldrix.melchormoesa@ncst.edu.ph', '09933913808', 'Dasmarinas', 'No prior experience (Fresh grad / Student)', '2026-09-19', 'Sioco_Resume.pdf', 'uploads/resumes/resume_km_2026_bar_7821_1789831710.pdf', '', 1, 'rejected', '', '::1', '2026-09-19 23:28:30', '2026-09-19 23:33:41');

-- --------------------------------------------------------

--
-- Table structure for table `job_postings`
--

CREATE TABLE `job_postings` (
  `id` int(11) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `title` varchar(150) NOT NULL,
  `department` varchar(100) NOT NULL DEFAULT 'Coffee & Barista',
  `location` varchar(100) NOT NULL DEFAULT 'Manila',
  `job_type` varchar(50) NOT NULL DEFAULT 'Full-time',
  `tagline` varchar(255) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `about_role` text NOT NULL,
  `responsibilities` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `benefits` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `job_postings`
--

INSERT INTO `job_postings` (`id`, `slug`, `title`, `department`, `location`, `job_type`, `tagline`, `description`, `about_role`, `responsibilities`, `requirements`, `benefits`, `is_active`, `created_at`) VALUES
(1, 'barista', 'Barista', 'Coffee & Barista', 'Manila', 'Full-time', 'Craft quality beverages and create welcoming experiences for every guest.', 'Craft quality beverages and create welcoming experiences for every guest.', 'Craft quality beverages and create welcoming experiences for every guest.', 'Prepare espresso drinks, pour-overs, iced teas, and signature specialty coffee recipes.\nGreet each customer with warm hospitality, answer questions about flavor notes, and take orders accurately.\nMaintain a clean, organized, and sanitized workstation, espresso machine, and grinder area.\nManage cash and POS transactions efficiently while maintaining a friendly, positive café vibe.', 'Passion for coffee culture and customer service.\nPrevious specialty coffee or café experience is a plus, but motivated beginners are warmly welcome.\nStrong communication skills and high attention to detail.\nAbility to work flexible retail shifts including mornings, weekends, or holidays.', 'Competitive hourly wage with daily tips sharing.\nFree shift drinks and staff discount on merchandise & beans.\nHands-on barista certification and latte art training.\nClear pathway to Shift Lead and Café Supervisor roles.', 1, '2026-09-19 23:19:56'),
(2, 'store-supervisor', 'Store Supervisor', 'Store Operations', 'Quezon City', 'Full-time', 'Lead the store team and support smooth, consistent daily operations.', 'Lead the store team and support smooth, consistent daily operations.', 'Lead the store team and support smooth, consistent daily operations.', 'Oversee daily opening and closing store procedures and floor workflow.\nGuide and mentor baristas and counter crew to deliver exceptional service consistency.\nTrack ingredient inventories, spot stockouts, and coordinate stock requisitions.\nReconcile register cash drawers, manage daily shift handovers, and resolve customer feedback with grace.', 'Minimum 1-2 years experience in café, quick-service restaurant, or retail supervision.\nProven leadership abilities and reliable problem-solving skills under fast-paced peak hours.\nFamiliarity with POS operations, inventory management, and basic hygiene safety standards.\nPositive team-first attitude and passion for hospitality excellence.', 'Competitive monthly salary with supervisory performance incentives.\nPaid health benefits and leave credits.\nStaff meal allowance and unlimited specialty coffee on shift.\nDirect mentorship from operations management and growth opportunities.', 1, '2026-09-19 23:19:56'),
(3, 'kitchen-crew', 'Kitchen Crew', 'Kitchen & Food', 'Makati', 'Full-time', 'Prepare food with care and keep our kitchen organized and ready.', 'Prepare food with care and keep our kitchen organized and ready.', 'Prepare food with care and keep our kitchen organized and ready.', 'Prepare freshly baked pastries, artisanal sandwiches, and light savory bites according to recipes.\nEnsure all food preparation complies with stringent food safety and sanitation guidelines.\nMonitor ingredient freshness, label batch dates, and prevent kitchen waste.\nSupport dishwashing, prep station sanitizing, and receiving deliveries.', 'Experience in commercial food preparation or culinary arts studies preferred, but enthusiastic trainees are welcome.\nKnowledge of food hygiene and basic knife handling skills.\nPunctual, responsible, and capable of working in an energetic kitchen environment.\nValid health card / food handler certificate (or willingness to obtain one upon hire).', 'Competitive wage with shift meal provisions.\nHealth coverage and government statutory benefits.\nStructured culinary and baking training programs.\nFriendly and respectful collaborative kitchen environment.', 1, '2026-09-19 23:19:56'),
(4, 'cashier', 'Cashier', 'Store Operations', 'Manila', 'Full-time', 'Manage register transactions with warm and friendly hospitality.', 'Manage register transactions with warm and friendly hospitality.', 'Manage register transactions with warm and friendly hospitality.', 'Operate register\nWelcome customers', 'High school diploma\nFriendly attitude', 'Staff discounts\nDaily tips', 1, '2026-09-19 23:37:23');

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
-- Table structure for table `loan_repayments`
--

CREATE TABLE `loan_repayments` (
  `id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `payslip_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_on` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actor_id` int(11) DEFAULT NULL,
  `action_type` varchar(50) DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `recipient_user_id`, `type`, `title`, `message`, `link_url`, `is_read`, `created_at`, `actor_id`, `action_type`, `entity_type`, `entity_id`) VALUES
(1, 7, 'invoice_created', 'Invoice 56256 logged for PO #3', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:01', NULL, NULL, NULL, NULL),
(2, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:10', NULL, NULL, NULL, NULL),
(3, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:17', NULL, NULL, NULL, NULL),
(4, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:18', NULL, NULL, NULL, NULL),
(5, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:20', NULL, NULL, NULL, NULL),
(6, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 18:34:21', NULL, NULL, NULL, NULL),
(7, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,333.01 on RFQ #5', 'rfq.php?id=5', 1, '2026-08-31 20:11:51', NULL, NULL, NULL, NULL),
(8, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,333.01 on RFQ #5', 'rfq.php?id=5', 0, '2026-08-31 20:11:51', NULL, NULL, NULL, NULL),
(9, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,333.01 on RFQ #5', 'rfq.php?id=5', 0, '2026-08-31 20:11:51', NULL, NULL, NULL, NULL),
(10, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 20:14:22', NULL, NULL, NULL, NULL),
(11, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"plastic cups\" invoiced for 56.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱112,000.00 differs from PO total ₱0.01 by 1119999900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=1', 0, '2026-08-31 20:14:22', NULL, NULL, NULL, NULL),
(12, 7, 'invoice_created', 'Invoice 56256 logged for PO #1', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:53', NULL, NULL, NULL, NULL),
(13, 11, 'invoice_created', 'Invoice 56256 logged for PO #1', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:53', NULL, NULL, NULL, NULL),
(14, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Chocolate\" invoiced for 0.25 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱233.00 differs from PO total ₱2,232.00 by 89.6% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:56', NULL, NULL, NULL, NULL),
(15, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Chocolate\" invoiced for 0.25 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱233.00 differs from PO total ₱2,232.00 by 89.6% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=2', 0, '2026-08-31 20:14:56', NULL, NULL, NULL, NULL),
(16, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱112,001.00 on RFQ #3', 'rfq.php?id=3', 1, '2026-08-31 20:19:04', NULL, NULL, NULL, NULL),
(17, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱112,001.00 on RFQ #3', 'rfq.php?id=3', 0, '2026-08-31 20:19:04', NULL, NULL, NULL, NULL),
(18, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱112,001.00 on RFQ #3', 'rfq.php?id=3', 0, '2026-08-31 20:19:04', NULL, NULL, NULL, NULL),
(19, 1, 'invoice_created', 'Invoice 56256 submitted for PO #4', 'Selecta submitted an invoice — ready for 3-way match.', 'three_way_match.php?invoice_id=3', 1, '2026-08-31 20:26:11', NULL, NULL, NULL, NULL),
(20, 7, 'invoice_created', 'Invoice 56256 submitted for PO #4', 'Selecta submitted an invoice — ready for 3-way match.', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:11', NULL, NULL, NULL, NULL),
(21, 11, 'invoice_created', 'Invoice 56256 submitted for PO #4', 'Selecta submitted an invoice — ready for 3-way match.', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:11', NULL, NULL, NULL, NULL),
(22, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:36', NULL, NULL, NULL, NULL),
(23, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:36', NULL, NULL, NULL, NULL),
(24, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:43', NULL, NULL, NULL, NULL),
(25, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:43', NULL, NULL, NULL, NULL),
(26, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:45', NULL, NULL, NULL, NULL),
(27, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:26:45', NULL, NULL, NULL, NULL),
(28, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:11', NULL, NULL, NULL, NULL),
(29, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:11', NULL, NULL, NULL, NULL),
(30, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:14', NULL, NULL, NULL, NULL),
(31, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Koya Dsd\" invoiced for 23.00 but only 0 received. | No completed Goods Receipt found for this Purchase Order. | Invoice total ₱4,928.00 differs from PO total ₱2,333.01 by 111.2% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=3', 0, '2026-08-31 20:27:14', NULL, NULL, NULL, NULL),
(32, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #5', 'goods_receipts.php?po_id=5', 1, '2026-08-31 20:29:53', NULL, NULL, NULL, NULL),
(33, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #5', 'goods_receipts.php?po_id=5', 0, '2026-08-31 20:29:53', NULL, NULL, NULL, NULL),
(34, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #5', 'goods_receipts.php?po_id=5', 0, '2026-08-31 20:29:53', NULL, NULL, NULL, NULL),
(35, 13, 'grn_discrepancy', 'Delivery discrepancy on PO #5', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=5', 0, '2026-08-31 20:31:07', NULL, NULL, NULL, NULL),
(36, 7, 'invoice_created', 'Invoice 2323 logged for PO #5', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:32', NULL, NULL, NULL, NULL),
(37, 11, 'invoice_created', 'Invoice 2323 logged for PO #5', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:32', NULL, NULL, NULL, NULL),
(38, 7, 'invoice_exception', '3-way match exception on Invoice 2323', '\"plastic cups\" invoiced for 56.00 but only 0 received. | Invoice total ₱13,523.00 differs from PO total ₱112,001.00 by 87.9% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:35', NULL, NULL, NULL, NULL),
(39, 11, 'invoice_exception', '3-way match exception on Invoice 2323', '\"plastic cups\" invoiced for 56.00 but only 0 received. | Invoice total ₱13,523.00 differs from PO total ₱112,001.00 by 87.9% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=4', 0, '2026-08-31 20:32:35', NULL, NULL, NULL, NULL),
(40, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-08-31 20:50:43', NULL, NULL, NULL, NULL),
(41, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱3,233.00 on RFQ #6', 'rfq.php?id=6', 1, '2026-08-31 20:51:03', NULL, NULL, NULL, NULL),
(42, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱3,233.00 on RFQ #6', 'rfq.php?id=6', 0, '2026-08-31 20:51:03', NULL, NULL, NULL, NULL),
(43, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱3,233.00 on RFQ #6', 'rfq.php?id=6', 0, '2026-08-31 20:51:03', NULL, NULL, NULL, NULL),
(44, 6, 'requisition_filed', 'New requisition awaiting review', 'Coffee Beans — ₱2,454.00', 'requisitions.php', 0, '2026-08-31 22:12:35', NULL, NULL, NULL, NULL),
(45, 7, 'requisition_filed', 'New requisition awaiting review', 'Coffee Beans — ₱2,454.00', 'requisitions.php', 0, '2026-08-31 22:12:35', NULL, NULL, NULL, NULL),
(46, 11, 'requisition_filed', 'New requisition awaiting review', 'Coffee Beans — ₱2,454.00', 'requisitions.php', 0, '2026-08-31 22:12:35', NULL, NULL, NULL, NULL),
(47, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-08-31 22:12:59', NULL, NULL, NULL, NULL),
(48, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,456.00 on RFQ #7', 'rfq.php?id=7', 1, '2026-08-31 22:13:30', NULL, NULL, NULL, NULL),
(49, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,456.00 on RFQ #7', 'rfq.php?id=7', 0, '2026-08-31 22:13:30', NULL, NULL, NULL, NULL),
(50, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,456.00 on RFQ #7', 'rfq.php?id=7', 0, '2026-08-31 22:13:30', NULL, NULL, NULL, NULL),
(51, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #7', 'goods_receipts.php?po_id=7', 1, '2026-08-31 22:14:29', NULL, NULL, NULL, NULL),
(52, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #7', 'goods_receipts.php?po_id=7', 0, '2026-08-31 22:14:29', NULL, NULL, NULL, NULL),
(53, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #7', 'goods_receipts.php?po_id=7', 0, '2026-08-31 22:14:29', NULL, NULL, NULL, NULL),
(54, 1, 'invoice_created', 'Invoice 56256 logged for PO #7', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=5', 1, '2026-08-31 22:19:36', NULL, NULL, NULL, NULL),
(55, 11, 'invoice_created', 'Invoice 56256 logged for PO #7', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=5', 0, '2026-08-31 22:19:36', NULL, NULL, NULL, NULL),
(56, 7, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Arabica Beans\" invoiced for 23.00 but only 0 received. | \"Excelsa Beans\" invoiced for 33.00 but only 0 received.', 'three_way_match.php?invoice_id=5', 0, '2026-08-31 22:20:15', NULL, NULL, NULL, NULL),
(57, 11, 'invoice_exception', '3-way match exception on Invoice 56256', '\"Arabica Beans\" invoiced for 23.00 but only 0 received. | \"Excelsa Beans\" invoiced for 33.00 but only 0 received.', 'three_way_match.php?invoice_id=5', 0, '2026-08-31 22:20:15', NULL, NULL, NULL, NULL),
(58, 12, 'payment_advice', 'Payment sent', 'Payment of ₱2,454.00 for Invoice 56256 has been completed.', 'supplier_portal.php', 0, '2026-08-31 22:23:03', NULL, NULL, NULL, NULL),
(59, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #6', 'goods_receipts.php?po_id=6', 1, '2026-09-01 02:16:21', NULL, NULL, NULL, NULL),
(60, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #6', 'goods_receipts.php?po_id=6', 0, '2026-09-01 02:16:21', NULL, NULL, NULL, NULL),
(61, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #6', 'goods_receipts.php?po_id=6', 0, '2026-09-01 02:16:21', NULL, NULL, NULL, NULL),
(62, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 02:21:49', NULL, NULL, NULL, NULL),
(63, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:25', NULL, NULL, NULL, NULL),
(64, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:25', NULL, NULL, NULL, NULL),
(65, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:25', NULL, NULL, NULL, NULL),
(66, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:29', NULL, NULL, NULL, NULL),
(67, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:29', NULL, NULL, NULL, NULL),
(68, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:29', NULL, NULL, NULL, NULL),
(69, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:36', NULL, NULL, NULL, NULL),
(70, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:36', NULL, NULL, NULL, NULL),
(71, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:36', NULL, NULL, NULL, NULL),
(72, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 02:22:44', NULL, NULL, NULL, NULL),
(73, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:44', NULL, NULL, NULL, NULL),
(74, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 02:22:44', NULL, NULL, NULL, NULL),
(75, 6, 'requisition_filed', 'New requisition awaiting review', 'Iphone 17 pro — ₱400.00', 'requisitions.php', 0, '2026-09-01 02:26:53', NULL, NULL, NULL, NULL),
(76, 7, 'requisition_filed', 'New requisition awaiting review', 'Iphone 17 pro — ₱400.00', 'requisitions.php', 0, '2026-09-01 02:26:53', NULL, NULL, NULL, NULL),
(77, 11, 'requisition_filed', 'New requisition awaiting review', 'Iphone 17 pro — ₱400.00', 'requisitions.php', 0, '2026-09-01 02:26:53', NULL, NULL, NULL, NULL),
(78, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 02:30:57', NULL, NULL, NULL, NULL),
(79, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #8', 'goods_receipts.php?po_id=8', 1, '2026-09-01 02:35:15', NULL, NULL, NULL, NULL),
(80, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #8', 'goods_receipts.php?po_id=8', 0, '2026-09-01 02:35:15', NULL, NULL, NULL, NULL),
(81, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #8', 'goods_receipts.php?po_id=8', 0, '2026-09-01 02:35:15', NULL, NULL, NULL, NULL),
(82, 1, 'requisition_filed', 'New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 1, '2026-09-01 03:25:21', NULL, NULL, NULL, NULL),
(83, 6, 'requisition_filed', 'New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 0, '2026-09-01 03:25:21', NULL, NULL, NULL, NULL),
(84, 7, 'requisition_filed', 'New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 0, '2026-09-01 03:25:21', NULL, NULL, NULL, NULL),
(85, 11, 'requisition_filed', 'New requisition awaiting review', 'Kahit ano basta — ₱50.00', 'requisitions.php', 0, '2026-09-01 03:25:21', NULL, NULL, NULL, NULL),
(86, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 03:28:01', NULL, NULL, NULL, NULL),
(87, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 1, '2026-09-01 03:31:08', NULL, NULL, NULL, NULL),
(88, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 03:31:08', NULL, NULL, NULL, NULL),
(89, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #8', 'rfq.php?id=8', 0, '2026-09-01 03:31:08', NULL, NULL, NULL, NULL),
(90, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #9', 'goods_receipts.php?po_id=9', 1, '2026-09-01 03:33:44', NULL, NULL, NULL, NULL),
(91, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #9', 'goods_receipts.php?po_id=9', 0, '2026-09-01 03:33:44', NULL, NULL, NULL, NULL),
(92, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #9', 'goods_receipts.php?po_id=9', 0, '2026-09-01 03:33:44', NULL, NULL, NULL, NULL),
(93, 1, 'invoice_created', 'Invoice -1111 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=6', 1, '2026-09-01 03:35:16', NULL, NULL, NULL, NULL),
(94, 11, 'invoice_created', 'Invoice -1111 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=6', 0, '2026-09-01 03:35:16', NULL, NULL, NULL, NULL),
(95, 1, 'invoice_created', 'Invoice 1 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:35:43', NULL, NULL, NULL, NULL),
(96, 11, 'invoice_created', 'Invoice 1 logged for PO #9', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:35:43', NULL, NULL, NULL, NULL),
(97, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:11', NULL, NULL, NULL, NULL),
(98, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:11', NULL, NULL, NULL, NULL),
(99, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:19', NULL, NULL, NULL, NULL),
(100, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:19', NULL, NULL, NULL, NULL),
(101, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:21', NULL, NULL, NULL, NULL),
(102, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:21', NULL, NULL, NULL, NULL),
(103, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:33', NULL, NULL, NULL, NULL),
(104, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:33', NULL, NULL, NULL, NULL),
(105, 1, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 1, '2026-09-01 03:36:33', NULL, NULL, NULL, NULL),
(106, 11, 'invoice_exception', '3-way match exception on Invoice 1', 'Invoice total ₱62.00 differs from PO total ₱9,999,999,999.99 by 100.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=7', 0, '2026-09-01 03:36:33', NULL, NULL, NULL, NULL),
(107, 12, 'payment_advice', 'Payment sent', 'Payment of ₱62.00 for Invoice 1 has been completed.', 'supplier_portal.php', 0, '2026-09-01 03:37:40', NULL, NULL, NULL, NULL),
(108, 1, 'requisition_filed', 'New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 1, '2026-09-01 03:45:12', NULL, NULL, NULL, NULL),
(109, 6, 'requisition_filed', 'New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 0, '2026-09-01 03:45:12', NULL, NULL, NULL, NULL),
(110, 7, 'requisition_filed', 'New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 0, '2026-09-01 03:45:12', NULL, NULL, NULL, NULL),
(111, 11, 'requisition_filed', 'New requisition awaiting review', 'Increase of stock — ₱500.00', 'requisitions.php', 0, '2026-09-01 03:45:12', NULL, NULL, NULL, NULL),
(112, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 03:47:12', NULL, NULL, NULL, NULL),
(113, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1.00 on RFQ #11', 'rfq.php?id=11', 1, '2026-09-01 03:47:58', NULL, NULL, NULL, NULL),
(114, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1.00 on RFQ #11', 'rfq.php?id=11', 0, '2026-09-01 03:47:58', NULL, NULL, NULL, NULL),
(115, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1.00 on RFQ #11', 'rfq.php?id=11', 0, '2026-09-01 03:47:58', NULL, NULL, NULL, NULL),
(116, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #11', 'goods_receipts.php?po_id=11', 1, '2026-09-01 03:49:27', NULL, NULL, NULL, NULL),
(117, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #11', 'goods_receipts.php?po_id=11', 0, '2026-09-01 03:49:27', NULL, NULL, NULL, NULL),
(118, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #11', 'goods_receipts.php?po_id=11', 0, '2026-09-01 03:49:27', NULL, NULL, NULL, NULL),
(119, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #11', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=11', 1, '2026-09-01 03:50:19', NULL, NULL, NULL, NULL),
(120, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #11', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=11', 1, '2026-09-01 03:50:42', NULL, NULL, NULL, NULL),
(121, 1, 'invoice_created', 'Invoice 10 logged for PO #10', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=8', 1, '2026-09-01 03:52:30', NULL, NULL, NULL, NULL),
(122, 11, 'invoice_created', 'Invoice 10 logged for PO #10', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=8', 0, '2026-09-01 03:52:30', NULL, NULL, NULL, NULL),
(123, 1, 'invoice_exception', '3-way match exception on Invoice 10', '\"Koya Dsd\" invoiced for 500.00 but only 0 received. | Invoice total ₱250,030.00 differs from PO total ₱1,000.00 by 24903.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=8', 1, '2026-09-01 03:52:38', NULL, NULL, NULL, NULL),
(124, 11, 'invoice_exception', '3-way match exception on Invoice 10', '\"Koya Dsd\" invoiced for 500.00 but only 0 received. | Invoice total ₱250,030.00 differs from PO total ₱1,000.00 by 24903.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=8', 0, '2026-09-01 03:52:38', NULL, NULL, NULL, NULL),
(125, 12, 'payment_advice', 'Payment sent', 'Payment of ₱250,030.00 for Invoice 10 has been completed.', 'supplier_portal.php', 0, '2026-09-01 03:53:54', NULL, NULL, NULL, NULL),
(126, 1, 'requisition_filed', 'New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 1, '2026-09-01 04:09:17', NULL, NULL, NULL, NULL),
(127, 6, 'requisition_filed', 'New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 0, '2026-09-01 04:09:17', NULL, NULL, NULL, NULL),
(128, 7, 'requisition_filed', 'New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 0, '2026-09-01 04:09:17', NULL, NULL, NULL, NULL),
(129, 11, 'requisition_filed', 'New requisition awaiting review', 'Stock increase — ₱500.00', 'requisitions.php', 0, '2026-09-01 04:09:17', NULL, NULL, NULL, NULL),
(130, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-01 04:11:57', NULL, NULL, NULL, NULL),
(131, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #12', 'goods_receipts.php?po_id=12', 1, '2026-09-01 04:12:47', NULL, NULL, NULL, NULL),
(132, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #12', 'goods_receipts.php?po_id=12', 0, '2026-09-01 04:12:47', NULL, NULL, NULL, NULL),
(133, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #12', 'goods_receipts.php?po_id=12', 0, '2026-09-01 04:12:47', NULL, NULL, NULL, NULL),
(134, 1, 'invoice_created', 'Invoice 10 logged for PO #12', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=9', 1, '2026-09-01 04:14:18', NULL, NULL, NULL, NULL),
(135, 11, 'invoice_created', 'Invoice 10 logged for PO #12', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=9', 0, '2026-09-01 04:14:18', NULL, NULL, NULL, NULL),
(136, 1, 'invoice_exception', '3-way match exception on Invoice 10', 'Invoice total ₱500.00 differs from PO total ₱1.00 by 49900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=9', 1, '2026-09-01 04:15:38', NULL, NULL, NULL, NULL),
(137, 11, 'invoice_exception', '3-way match exception on Invoice 10', 'Invoice total ₱500.00 differs from PO total ₱1.00 by 49900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=9', 0, '2026-09-01 04:15:38', NULL, NULL, NULL, NULL),
(138, 12, 'payment_advice', 'Payment sent', 'Payment of ₱500.00 for Invoice 10 has been completed.', 'supplier_portal.php', 0, '2026-09-01 04:16:19', NULL, NULL, NULL, NULL),
(139, 1, 'requisition_filed', 'New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 1, '2026-09-07 15:26:55', NULL, NULL, NULL, NULL),
(140, 6, 'requisition_filed', 'New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 0, '2026-09-07 15:26:55', NULL, NULL, NULL, NULL),
(141, 7, 'requisition_filed', 'New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 0, '2026-09-07 15:26:55', NULL, NULL, NULL, NULL),
(142, 11, 'requisition_filed', 'New requisition awaiting review', 'Iphone 15 — ₱100,000.00', 'requisitions.php', 0, '2026-09-07 15:26:55', NULL, NULL, NULL, NULL),
(143, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-07 15:34:42', NULL, NULL, NULL, NULL),
(144, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #13', 'goods_receipts.php?po_id=13', 1, '2026-09-07 15:35:19', NULL, NULL, NULL, NULL),
(145, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #13', 'goods_receipts.php?po_id=13', 0, '2026-09-07 15:35:19', NULL, NULL, NULL, NULL),
(146, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #13', 'goods_receipts.php?po_id=13', 0, '2026-09-07 15:35:19', NULL, NULL, NULL, NULL),
(147, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #13', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=13', 1, '2026-09-07 15:35:53', NULL, NULL, NULL, NULL),
(148, 1, 'grn_discrepancy', 'Delivery discrepancy on PO #13', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=13', 1, '2026-09-07 15:36:05', NULL, NULL, NULL, NULL),
(149, 1, 'invoice_created', 'Invoice 13 logged for PO #13', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=10', 1, '2026-09-07 15:37:57', NULL, NULL, NULL, NULL),
(150, 11, 'invoice_created', 'Invoice 13 logged for PO #13', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=10', 0, '2026-09-07 15:37:57', NULL, NULL, NULL, NULL),
(151, 1, 'invoice_exception', '3-way match exception on Invoice 13', 'Invoice total ₱101,000.00 differs from PO total ₱1.00 by 10099900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=10', 1, '2026-09-07 15:41:50', NULL, NULL, NULL, NULL),
(152, 11, 'invoice_exception', '3-way match exception on Invoice 13', 'Invoice total ₱101,000.00 differs from PO total ₱1.00 by 10099900.0% (tolerance is 3.0%).', 'three_way_match.php?invoice_id=10', 0, '2026-09-07 15:41:50', NULL, NULL, NULL, NULL),
(153, 12, 'payment_advice', 'Payment sent', 'Payment of ₱101,000.00 for Invoice 13 has been completed.', 'supplier_portal.php', 0, '2026-09-07 15:43:18', NULL, NULL, NULL, NULL),
(154, 1, 'requisition_filed', 'New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 1, '2026-09-10 19:15:53', NULL, NULL, NULL, NULL),
(155, 6, 'requisition_filed', 'New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 0, '2026-09-10 19:15:53', NULL, NULL, NULL, NULL),
(156, 7, 'requisition_filed', 'New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 0, '2026-09-10 19:15:53', NULL, NULL, NULL, NULL),
(157, 11, 'requisition_filed', 'New requisition awaiting review', 'Ice request — ₱500.00', 'requisitions.php', 0, '2026-09-10 19:15:53', NULL, NULL, NULL, NULL),
(158, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-10 19:16:41', NULL, NULL, NULL, NULL),
(159, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #14', 'goods_receipts.php?po_id=14', 1, '2026-09-10 19:17:24', NULL, NULL, NULL, NULL),
(160, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #14', 'goods_receipts.php?po_id=14', 0, '2026-09-10 19:17:24', NULL, NULL, NULL, NULL),
(161, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #14', 'goods_receipts.php?po_id=14', 0, '2026-09-10 19:17:24', NULL, NULL, NULL, NULL),
(162, 1, 'invoice_created', 'Invoice 14 logged for PO #14', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=11', 1, '2026-09-10 19:18:44', NULL, NULL, NULL, NULL),
(163, 11, 'invoice_created', 'Invoice 14 logged for PO #14', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=11', 0, '2026-09-10 19:18:44', NULL, NULL, NULL, NULL),
(164, 12, 'payment_advice', 'Payment sent', 'Payment of ₱500.00 for Invoice 14 has been completed.', 'supplier_portal.php', 0, '2026-09-10 19:19:11', NULL, NULL, NULL, NULL),
(165, 1, 'requisition_filed', 'New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 1, '2026-09-14 19:23:49', NULL, NULL, NULL, NULL),
(166, 6, 'requisition_filed', 'New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 0, '2026-09-14 19:23:49', NULL, NULL, NULL, NULL),
(167, 7, 'requisition_filed', 'New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 0, '2026-09-14 19:23:49', NULL, NULL, NULL, NULL),
(168, 11, 'requisition_filed', 'New requisition awaiting review', 'Espresso Request — ₱150.00', 'requisitions.php', 0, '2026-09-14 19:23:49', NULL, NULL, NULL, NULL),
(169, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-14 19:24:39', NULL, NULL, NULL, NULL),
(170, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #15', 'goods_receipts.php?po_id=15', 1, '2026-09-14 19:25:12', NULL, NULL, NULL, NULL),
(171, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #15', 'goods_receipts.php?po_id=15', 0, '2026-09-14 19:25:12', NULL, NULL, NULL, NULL),
(172, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #15', 'goods_receipts.php?po_id=15', 0, '2026-09-14 19:25:12', NULL, NULL, NULL, NULL),
(173, 1, 'invoice_created', 'Invoice 15 logged for PO #15', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=12', 1, '2026-09-14 19:26:10', NULL, NULL, NULL, NULL),
(174, 11, 'invoice_created', 'Invoice 15 logged for PO #15', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=12', 0, '2026-09-14 19:26:10', NULL, NULL, NULL, NULL),
(175, 12, 'payment_advice', 'Payment sent', 'Payment of ₱150.00 for Invoice 15 has been completed.', 'supplier_portal.php', 0, '2026-09-14 19:26:42', NULL, NULL, NULL, NULL),
(176, 1, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(177, 6, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(178, 8, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(179, 13, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(180, 16, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(181, 1, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(182, 6, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(183, 8, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(184, 13, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(185, 16, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(186, 1, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(187, 6, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(188, 8, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(189, 13, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(190, 16, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(191, 1, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(192, 6, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(193, 8, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(194, 13, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(195, 16, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-19 16:58:19', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(196, 1, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(197, 6, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(198, 8, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(199, 13, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(200, 16, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(201, 1, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(202, 6, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(203, 8, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(204, 13, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(205, 16, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(206, 1, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(207, 6, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(208, 8, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(209, 13, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(210, 16, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(211, 1, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(212, 6, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(213, 8, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(214, 13, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(215, 16, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-20 20:12:12', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(216, 1, 'inventory_auto_reorder', 'Auto-reorder filed — Brown Sugar', 'Stock hit 0 g. Requested 1,000 g from the default supplier.', 'requisitions.php?id=17', 0, '2026-09-21 05:10:42', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 17),
(217, 6, 'inventory_auto_reorder', 'Auto-reorder filed — Brown Sugar', 'Stock hit 0 g. Requested 1,000 g from the default supplier.', 'requisitions.php?id=17', 0, '2026-09-21 05:10:42', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 17),
(218, 11, 'inventory_auto_reorder', 'Auto-reorder filed — Brown Sugar', 'Stock hit 0 g. Requested 1,000 g from the default supplier.', 'requisitions.php?id=17', 0, '2026-09-21 05:10:42', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 17),
(219, 2, 'requisition_filed', 'New requisition awaiting review', 'Test — ₱1.00', 'requisitions.php', 0, '2026-09-22 05:54:07', NULL, NULL, NULL, NULL),
(220, 6, 'requisition_filed', 'New requisition awaiting review', 'Test — ₱1.00', 'requisitions.php', 0, '2026-09-22 05:54:07', NULL, NULL, NULL, NULL),
(221, 7, 'requisition_filed', 'New requisition awaiting review', 'Test — ₱1.00', 'requisitions.php', 0, '2026-09-22 05:54:07', NULL, NULL, NULL, NULL),
(222, 11, 'requisition_filed', 'New requisition awaiting review', 'Test — ₱1.00', 'requisitions.php', 0, '2026-09-22 05:54:07', NULL, NULL, NULL, NULL),
(223, 12, 'procurement_letter', 'New Procurement Award Letter Issued', 'Kofee Manila has issued official Procurement Letter KM-LTR-2026-0018 for Test (₱1.00). Please review and acknowledge.', 'procurement_letter.php?id=1', 0, '2026-09-22 06:46:27', NULL, NULL, NULL, NULL),
(224, 1, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0018: \"Delivery scheduled for Friday morning.\"', 'procurement_letter.php?id=1', 0, '2026-09-22 06:46:27', NULL, NULL, NULL, NULL),
(225, 2, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0018: \"Delivery scheduled for Friday morning.\"', 'procurement_letter.php?id=1', 0, '2026-09-22 06:46:27', NULL, NULL, NULL, NULL),
(226, 6, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0018: \"Delivery scheduled for Friday morning.\"', 'procurement_letter.php?id=1', 0, '2026-09-22 06:46:27', NULL, NULL, NULL, NULL),
(227, 7, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0018: \"Delivery scheduled for Friday morning.\"', 'procurement_letter.php?id=1', 0, '2026-09-22 06:46:27', NULL, NULL, NULL, NULL),
(228, 11, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0018: \"Delivery scheduled for Friday morning.\"', 'procurement_letter.php?id=1', 0, '2026-09-22 06:46:27', NULL, NULL, NULL, NULL),
(229, 1, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 06:54:00', NULL, NULL, NULL, NULL),
(230, 2, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 06:54:00', NULL, NULL, NULL, NULL),
(231, 6, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 06:54:00', NULL, NULL, NULL, NULL),
(232, 7, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 06:54:00', NULL, NULL, NULL, NULL),
(233, 12, 'procurement_letter', 'New Procurement Award Letter Issued', 'Kofee Manila has issued official Procurement Letter KM-LTR-2026-0019 for Testing (₱1,000.00). Please review and acknowledge.', 'procurement_letter.php?id=2', 0, '2026-09-22 06:55:08', NULL, NULL, NULL, NULL),
(234, 1, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0019.', 'procurement_letter.php?id=2', 0, '2026-09-22 06:55:32', NULL, NULL, NULL, NULL),
(235, 2, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0019.', 'procurement_letter.php?id=2', 0, '2026-09-22 06:55:32', NULL, NULL, NULL, NULL),
(236, 6, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0019.', 'procurement_letter.php?id=2', 0, '2026-09-22 06:55:32', NULL, NULL, NULL, NULL),
(237, 7, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0019.', 'procurement_letter.php?id=2', 0, '2026-09-22 06:55:32', NULL, NULL, NULL, NULL),
(238, 11, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0019.', 'procurement_letter.php?id=2', 0, '2026-09-22 06:55:32', NULL, NULL, NULL, NULL),
(239, 1, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(240, 2, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(241, 6, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(242, 8, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(243, 13, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(244, 16, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(245, 1, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(246, 2, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(247, 6, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9);
INSERT INTO `notifications` (`id`, `recipient_user_id`, `type`, `title`, `message`, `link_url`, `is_read`, `created_at`, `actor_id`, `action_type`, `entity_type`, `entity_id`) VALUES
(248, 8, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(249, 13, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(250, 16, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(251, 1, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(252, 2, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(253, 6, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(254, 8, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(255, 13, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(256, 16, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(257, 1, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(258, 2, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(259, 6, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(260, 8, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(261, 13, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(262, 16, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 06:59:30', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(263, 12, 'rfq_invite', 'New RFQ invitation', 'You have been invited to quote on a new RFQ.', 'supplier_portal.php', 0, '2026-09-22 22:16:35', NULL, NULL, NULL, NULL),
(264, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #16', 'rfq.php?id=16', 0, '2026-09-22 22:29:31', NULL, NULL, NULL, NULL),
(265, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #16', 'rfq.php?id=16', 0, '2026-09-22 22:29:31', NULL, NULL, NULL, NULL),
(266, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #16', 'rfq.php?id=16', 0, '2026-09-22 22:29:31', NULL, NULL, NULL, NULL),
(267, 1, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(268, 2, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(269, 6, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(270, 8, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(271, 13, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(272, 16, 'inventory_expiry_warning', 'Expiring soon — Whole Milk', '96 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 8),
(273, 1, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(274, 2, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(275, 6, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(276, 8, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(277, 13, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(278, 16, 'inventory_expiry_warning', 'Expiring soon — Condensed Milk', '10,000 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 9),
(279, 1, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(280, 2, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(281, 6, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(282, 8, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(283, 13, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(284, 16, 'inventory_expiry_warning', 'Expiring soon — Heavy Cream', '100 ml expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 10),
(285, 1, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(286, 2, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(287, 6, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(288, 8, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(289, 13, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(290, 16, 'inventory_expiry_warning', 'Expiring soon — Ice Cream', '100 g expires on Sep 27, 2026.', 'inventory.php?status=expiring', 0, '2026-09-22 22:39:39', NULL, 'INVENTORY_EXPIRY_WARNING', 'batch', 11),
(291, 2, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 22:42:43', NULL, NULL, NULL, NULL),
(292, 6, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 22:42:43', NULL, NULL, NULL, NULL),
(293, 7, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 22:42:43', NULL, NULL, NULL, NULL),
(294, 11, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱1,000.00', 'requisitions.php', 0, '2026-09-22 22:42:43', NULL, NULL, NULL, NULL),
(295, 12, 'procurement_letter', 'New Procurement Award Letter Issued', 'Kofee Manila has issued official Procurement Letter KM-LTR-2026-0020 for Testing (₱1,000.00). Please review and acknowledge.', 'procurement_letter.php?id=3', 0, '2026-09-22 22:46:14', NULL, NULL, NULL, NULL),
(296, 2, 'invoice_created', 'Invoice ada logged for PO #6', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=13', 0, '2026-09-25 08:31:29', NULL, NULL, NULL, NULL),
(297, 7, 'invoice_created', 'Invoice ada logged for PO #6', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=13', 0, '2026-09-25 08:31:29', NULL, NULL, NULL, NULL),
(298, 11, 'invoice_created', 'Invoice ada logged for PO #6', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=13', 0, '2026-09-25 08:31:29', NULL, NULL, NULL, NULL),
(299, 1, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329634. Purchase Order can now be issued.', 'purchase_contracts.php?id=1', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(300, 2, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329634. Purchase Order can now be issued.', 'purchase_contracts.php?id=1', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(301, 6, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329634. Purchase Order can now be issued.', 'purchase_contracts.php?id=1', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(302, 7, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329634. Purchase Order can now be issued.', 'purchase_contracts.php?id=1', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(303, 11, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329634. Purchase Order can now be issued.', 'purchase_contracts.php?id=1', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(304, 1, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0016', 'Supplier dispatched goods against PO #16 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=16', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(305, 6, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0016', 'Supplier dispatched goods against PO #16 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=16', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(306, 13, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0016', 'Supplier dispatched goods against PO #16 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=16', 0, '2026-09-25 09:47:14', NULL, NULL, NULL, NULL),
(307, 1, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329648. Purchase Order can now be issued.', 'purchase_contracts.php?id=2', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(308, 2, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329648. Purchase Order can now be issued.', 'purchase_contracts.php?id=2', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(309, 6, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329648. Purchase Order can now be issued.', 'purchase_contracts.php?id=2', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(310, 7, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329648. Purchase Order can now be issued.', 'purchase_contracts.php?id=2', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(311, 11, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329648. Purchase Order can now be issued.', 'purchase_contracts.php?id=2', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(312, 1, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0017', 'Supplier dispatched goods against PO #17 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=17', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(313, 6, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0017', 'Supplier dispatched goods against PO #17 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=17', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(314, 13, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0017', 'Supplier dispatched goods against PO #17 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=17', 0, '2026-09-25 09:47:28', NULL, NULL, NULL, NULL),
(315, 1, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329662. Purchase Order can now be issued.', 'purchase_contracts.php?id=3', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(316, 2, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329662. Purchase Order can now be issued.', 'purchase_contracts.php?id=3', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(317, 6, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329662. Purchase Order can now be issued.', 'purchase_contracts.php?id=3', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(318, 7, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329662. Purchase Order can now be issued.', 'purchase_contracts.php?id=3', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(319, 11, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329662. Purchase Order can now be issued.', 'purchase_contracts.php?id=3', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(320, 1, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0018', 'Supplier dispatched goods against PO #18 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=18', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(321, 6, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0018', 'Supplier dispatched goods against PO #18 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=18', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(322, 13, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0018', 'Supplier dispatched goods against PO #18 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=18', 0, '2026-09-25 09:47:42', NULL, NULL, NULL, NULL),
(323, 1, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329676. Purchase Order can now be issued.', 'purchase_contracts.php?id=4', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(324, 2, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329676. Purchase Order can now be issued.', 'purchase_contracts.php?id=4', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(325, 6, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329676. Purchase Order can now be issued.', 'purchase_contracts.php?id=4', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(326, 7, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329676. Purchase Order can now be issued.', 'purchase_contracts.php?id=4', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(327, 11, 'contract_signed', 'Purchase Contract Fully Signed', 'Supplier has countersigned Contract CNT-E2E-1790329676. Purchase Order can now be issued.', 'purchase_contracts.php?id=4', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(328, 1, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0019', 'Supplier dispatched goods against PO #19 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=19', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(329, 6, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0019', 'Supplier dispatched goods against PO #19 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=19', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(330, 13, 'delivery_notice_received', 'Delivery Notice Received: ASN ASN-2026-0019', 'Supplier dispatched goods against PO #19 (Carrier: Lalamove Express). Expected arrival: 2026-09-25.', 'goods_receipts.php?po_id=19', 0, '2026-09-25 09:47:56', NULL, NULL, NULL, NULL),
(331, 2, 'requisition_filed', 'New requisition awaiting review', 'asdas — ₱2,000.00', 'requisitions.php', 0, '2026-09-25 09:51:19', NULL, NULL, NULL, NULL),
(332, 6, 'requisition_filed', 'New requisition awaiting review', 'asdas — ₱2,000.00', 'requisitions.php', 0, '2026-09-25 09:51:19', NULL, NULL, NULL, NULL),
(333, 7, 'requisition_filed', 'New requisition awaiting review', 'asdas — ₱2,000.00', 'requisitions.php', 0, '2026-09-25 09:51:19', NULL, NULL, NULL, NULL),
(334, 11, 'requisition_filed', 'New requisition awaiting review', 'asdas — ₱2,000.00', 'requisitions.php', 0, '2026-09-25 09:51:19', NULL, NULL, NULL, NULL),
(335, 2, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱276.00', 'requisitions.php', 0, '2026-09-25 10:24:24', NULL, NULL, NULL, NULL),
(336, 6, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱276.00', 'requisitions.php', 0, '2026-09-25 10:24:24', NULL, NULL, NULL, NULL),
(337, 7, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱276.00', 'requisitions.php', 0, '2026-09-25 10:24:24', NULL, NULL, NULL, NULL),
(338, 11, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱276.00', 'requisitions.php', 0, '2026-09-25 10:24:24', NULL, NULL, NULL, NULL),
(339, 1, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0020.', 'procurement_letter.php?id=3', 0, '2026-09-25 10:25:52', NULL, NULL, NULL, NULL),
(340, 2, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0020.', 'procurement_letter.php?id=3', 0, '2026-09-25 10:25:52', NULL, NULL, NULL, NULL),
(341, 6, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0020.', 'procurement_letter.php?id=3', 0, '2026-09-25 10:25:52', NULL, NULL, NULL, NULL),
(342, 7, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0020.', 'procurement_letter.php?id=3', 0, '2026-09-25 10:25:52', NULL, NULL, NULL, NULL),
(343, 11, 'letter_acknowledged', 'Procurement Letter Acknowledged', 'Selecta has formally acknowledged receipt of KM-LTR-2026-0020.', 'procurement_letter.php?id=3', 0, '2026-09-25 10:25:52', NULL, NULL, NULL, NULL),
(344, 6, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing can now go out for supplier quotes.', 'rfq.php?requisition_id=32', 0, '2026-09-25 10:37:59', NULL, NULL, NULL, NULL),
(345, 11, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing can now go out for supplier quotes.', 'rfq.php?requisition_id=32', 0, '2026-09-25 10:37:59', NULL, NULL, NULL, NULL),
(346, 12, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0033', 'Kofee Manila invites Selecta to submit a quotation for \"Clean Requisition Test\" due by 2026-09-30.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 10:48:25', NULL, NULL, NULL, NULL),
(347, 12, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0035', 'Kofee Manila invites Selecta to submit a quotation for \"Clean Requisition Test\" due by 2026-09-30.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 11:04:50', NULL, NULL, NULL, NULL),
(348, 12, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0032', 'Kofee Manila invites Selecta to submit a quotation for \"Testing\" due by 2026-10-02.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 11:05:56', NULL, NULL, NULL, NULL),
(349, 2, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱400.00', 'requisitions.php', 0, '2026-09-25 14:23:18', NULL, NULL, NULL, NULL),
(350, 6, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱400.00', 'requisitions.php', 0, '2026-09-25 14:23:18', NULL, NULL, NULL, NULL),
(351, 7, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱400.00', 'requisitions.php', 0, '2026-09-25 14:23:18', NULL, NULL, NULL, NULL),
(352, 11, 'requisition_filed', 'New requisition awaiting review', 'Testing — ₱400.00', 'requisitions.php', 0, '2026-09-25 14:23:18', NULL, NULL, NULL, NULL),
(353, 6, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing can now go out for supplier quotes.', 'rfq.php?requisition_id=39', 0, '2026-09-25 14:23:30', NULL, NULL, NULL, NULL),
(354, 11, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing can now go out for supplier quotes.', 'rfq.php?requisition_id=39', 0, '2026-09-25 14:23:30', NULL, NULL, NULL, NULL),
(355, 12, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0039', 'Kofee Manila invites Selecta to submit a quotation for \"Testing\" due by 2026-10-02.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 14:23:57', NULL, NULL, NULL, NULL),
(356, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,000.00 on RFQ #16', 'rfq.php?id=16', 0, '2026-09-25 14:26:40', NULL, NULL, NULL, NULL),
(357, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,000.00 on RFQ #16', 'rfq.php?id=16', 0, '2026-09-25 14:26:40', NULL, NULL, NULL, NULL),
(358, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱2,000.00 on RFQ #16', 'rfq.php?id=16', 0, '2026-09-25 14:26:40', NULL, NULL, NULL, NULL),
(359, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,552.00 on RFQ #29', 'rfq.php?id=29', 0, '2026-09-25 14:26:51', NULL, NULL, NULL, NULL),
(360, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,552.00 on RFQ #29', 'rfq.php?id=29', 0, '2026-09-25 14:26:51', NULL, NULL, NULL, NULL),
(361, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,552.00 on RFQ #29', 'rfq.php?id=29', 0, '2026-09-25 14:26:51', NULL, NULL, NULL, NULL),
(362, 2, 'requisition_filed', 'New requisition awaiting review', 'Testing1234 — ₱750.00', 'requisitions.php', 0, '2026-09-25 14:31:36', NULL, NULL, NULL, NULL),
(363, 6, 'requisition_filed', 'New requisition awaiting review', 'Testing1234 — ₱750.00', 'requisitions.php', 0, '2026-09-25 14:31:36', NULL, NULL, NULL, NULL),
(364, 7, 'requisition_filed', 'New requisition awaiting review', 'Testing1234 — ₱750.00', 'requisitions.php', 0, '2026-09-25 14:31:36', NULL, NULL, NULL, NULL),
(365, 11, 'requisition_filed', 'New requisition awaiting review', 'Testing1234 — ₱750.00', 'requisitions.php', 0, '2026-09-25 14:31:36', NULL, NULL, NULL, NULL),
(366, 6, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing1234 can now go out for supplier quotes.', 'rfq.php?requisition_id=40', 0, '2026-09-25 14:31:54', NULL, NULL, NULL, NULL),
(367, 11, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing1234 can now go out for supplier quotes.', 'rfq.php?requisition_id=40', 0, '2026-09-25 14:31:54', NULL, NULL, NULL, NULL),
(368, 12, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0040', 'Kofee Manila invites Selecta to submit a quotation for \"Testing1234\" due by 2026-09-26.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 14:32:24', NULL, NULL, NULL, NULL),
(369, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #33', 'rfq.php?id=33', 0, '2026-09-25 14:33:10', NULL, NULL, NULL, NULL),
(370, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #33', 'rfq.php?id=33', 0, '2026-09-25 14:33:10', NULL, NULL, NULL, NULL),
(371, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱1,000.00 on RFQ #33', 'rfq.php?id=33', 0, '2026-09-25 14:33:10', NULL, NULL, NULL, NULL),
(372, 12, 'contract_ready', 'New Purchase Contract Awaiting Your Signature', 'Kofee Manila has sent Purchase Contract KM-CNT-2026-0040 for Supply Agreement: Testing1234 — Selecta (₱750.00). Please review terms and countersign.', 'supplier_portal.php?tab=contracts&contract_id=11', 0, '2026-09-25 14:40:54', NULL, NULL, NULL, NULL),
(373, 1, 'contract_signed', 'Contract Countersigned by Supplier', 'Supplier Selecta has countersigned Contract KM-CNT-2026-0040. You can now issue the Purchase Order.', 'purchase_contracts.php?id=11', 0, '2026-09-25 14:41:41', NULL, NULL, NULL, NULL),
(374, 1, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=11', 0, '2026-09-25 14:41:41', NULL, NULL, NULL, NULL),
(375, 2, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=11', 0, '2026-09-25 14:41:41', NULL, NULL, NULL, NULL),
(376, 6, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=11', 0, '2026-09-25 14:41:41', NULL, NULL, NULL, NULL),
(377, 7, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=11', 0, '2026-09-25 14:41:41', NULL, NULL, NULL, NULL),
(378, 11, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=11', 0, '2026-09-25 14:41:41', NULL, NULL, NULL, NULL),
(379, 12, 'contract_ready', 'New Purchase Contract Awaiting Your Signature', 'Kofee Manila has sent Purchase Contract KM-CNT-2026-0040-2 for Supply Agreement: Testing1234 — Selecta (₱750.00). Please review terms and countersign.', 'supplier_portal.php?tab=contracts&contract_id=12', 0, '2026-09-25 14:43:28', NULL, NULL, NULL, NULL),
(380, 1, 'contract_signed', 'Contract Countersigned by Supplier', 'Supplier Selecta has countersigned Contract KM-CNT-2026-0040-2. You can now issue the Purchase Order.', 'purchase_contracts.php?id=12', 0, '2026-09-25 14:44:05', NULL, NULL, NULL, NULL),
(381, 1, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040-2 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=12', 0, '2026-09-25 14:44:05', NULL, NULL, NULL, NULL),
(382, 2, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040-2 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=12', 0, '2026-09-25 14:44:05', NULL, NULL, NULL, NULL),
(383, 6, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040-2 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=12', 0, '2026-09-25 14:44:05', NULL, NULL, NULL, NULL),
(384, 7, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040-2 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=12', 0, '2026-09-25 14:44:05', NULL, NULL, NULL, NULL),
(385, 11, 'contract_signed', 'Contract Countersigned by Supplier', 'Contract KM-CNT-2026-0040-2 for Selecta is now fully signed. Ready to issue Purchase Order.', 'purchase_contracts.php?id=12', 0, '2026-09-25 14:44:05', NULL, NULL, NULL, NULL),
(386, 12, 'po_issued', 'Purchase Order Issued — KM-PO-2026-0040', 'Kofee Manila has issued PO KM-PO-2026-0040 under Contract KM-CNT-2026-0040. Please review and acknowledge in your portal.', 'supplier_portal.php?tab=pos&po_id=34', 0, '2026-09-25 15:15:35', NULL, NULL, NULL, NULL),
(387, 1, 'po_acknowledged', 'PO Acknowledged — KM-PO-2026-0040', 'Supplier Selecta has formally acknowledged Purchase Order KM-PO-2026-0040.', 'purchase_orders.php?id=34', 0, '2026-09-25 15:16:27', NULL, NULL, NULL, NULL),
(388, 2, 'po_acknowledged', 'PO Acknowledged — KM-PO-2026-0040', 'Supplier Selecta has formally acknowledged Purchase Order KM-PO-2026-0040.', 'purchase_orders.php?id=34', 0, '2026-09-25 15:16:27', NULL, NULL, NULL, NULL),
(389, 6, 'po_acknowledged', 'PO Acknowledged — KM-PO-2026-0040', 'Supplier Selecta has formally acknowledged Purchase Order KM-PO-2026-0040.', 'purchase_orders.php?id=34', 0, '2026-09-25 15:16:27', NULL, NULL, NULL, NULL),
(390, 7, 'po_acknowledged', 'PO Acknowledged — KM-PO-2026-0040', 'Supplier Selecta has formally acknowledged Purchase Order KM-PO-2026-0040.', 'purchase_orders.php?id=34', 0, '2026-09-25 15:16:27', NULL, NULL, NULL, NULL),
(391, 11, 'po_acknowledged', 'PO Acknowledged — KM-PO-2026-0040', 'Supplier Selecta has formally acknowledged Purchase Order KM-PO-2026-0040.', 'purchase_orders.php?id=34', 0, '2026-09-25 15:16:27', NULL, NULL, NULL, NULL),
(392, 1, 'po_shipped', 'Order shipped', 'Selecta shipped PO #34', 'goods_receipts.php?po_id=34', 0, '2026-09-25 15:17:05', NULL, NULL, NULL, NULL),
(393, 6, 'po_shipped', 'Order shipped', 'Selecta shipped PO #34', 'goods_receipts.php?po_id=34', 0, '2026-09-25 15:17:05', NULL, NULL, NULL, NULL),
(394, 13, 'po_shipped', 'Order shipped', 'Selecta shipped PO #34', 'goods_receipts.php?po_id=34', 0, '2026-09-25 15:17:05', NULL, NULL, NULL, NULL),
(395, 2, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:11', 1, 'INVENTORY_RESTOCK', 'grn', 26),
(396, 6, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:11', 1, 'INVENTORY_RESTOCK', 'grn', 26),
(397, 8, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:11', 1, 'INVENTORY_RESTOCK', 'grn', 26),
(398, 13, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:11', 1, 'INVENTORY_RESTOCK', 'grn', 26),
(399, 16, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:11', 1, 'INVENTORY_RESTOCK', 'grn', 26),
(400, 13, 'grn_discrepancy', 'Delivery discrepancy on PO #34', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=34', 0, '2026-09-25 15:18:11', NULL, NULL, NULL, NULL),
(401, 2, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:29', 1, 'INVENTORY_RESTOCK', 'grn', 27),
(402, 6, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:29', 1, 'INVENTORY_RESTOCK', 'grn', 27),
(403, 8, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:29', 1, 'INVENTORY_RESTOCK', 'grn', 27),
(404, 13, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:29', 1, 'INVENTORY_RESTOCK', 'grn', 27),
(405, 16, 'inventory_restock', 'Inventory restocked from PO #34', '1 batch(es) recorded with delivery and expiry dates.', 'inventory.php', 0, '2026-09-25 15:18:29', 1, 'INVENTORY_RESTOCK', 'grn', 27),
(406, 13, 'grn_discrepancy', 'Delivery discrepancy on PO #34', 'Received quantities/condition differ from what was ordered. Needs review.', 'goods_receipts.php?po_id=34', 0, '2026-09-25 15:18:29', NULL, NULL, NULL, NULL),
(407, 1, 'grn_confirmed', 'Goods Receipt Confirmed on PO #35', 'Delivery items for PO #35 (ASN linked) have been physically received and confirmed at Kofee Manila warehouse.', 'supplier_portal.php?tab=orders&po_id=35', 0, '2026-09-25 15:35:41', NULL, NULL, NULL, NULL),
(409, 1, 'asn_submitted', 'Incoming Delivery Notice / ASN — KM-ASN-2026-0034', 'Supplier Selecta dispatched shipment for PO KM-PO-2026-0040 via dasd (Trk: sad). 10 total unit(s) expected.', 'goods_receipts.php?po_id=34&asn_id=15', 0, '2026-09-25 15:58:47', NULL, NULL, NULL, NULL),
(410, 6, 'asn_submitted', 'Incoming Delivery Notice / ASN — KM-ASN-2026-0034', 'Supplier Selecta dispatched shipment for PO KM-PO-2026-0040 via dasd (Trk: sad). 10 total unit(s) expected.', 'goods_receipts.php?po_id=34&asn_id=15', 0, '2026-09-25 15:58:47', NULL, NULL, NULL, NULL),
(411, 13, 'asn_submitted', 'Incoming Delivery Notice / ASN — KM-ASN-2026-0034', 'Supplier Selecta dispatched shipment for PO KM-PO-2026-0040 via dasd (Trk: sad). 10 total unit(s) expected.', 'goods_receipts.php?po_id=34&asn_id=15', 0, '2026-09-25 15:58:47', NULL, NULL, NULL, NULL),
(412, 1, 'asn_submitted', 'Shipment Dispatched on PO KM-PO-2026-0040 (KM-ASN-2026-0034)', 'Supplier Selecta submitted ASN KM-ASN-2026-0034 with status \"shipped\".', 'purchase_orders.php?id=34', 0, '2026-09-25 15:58:47', NULL, NULL, NULL, NULL),
(413, 1, 'asn_submitted', 'Incoming Delivery Notice / ASN — KM-ASN-2026-0034-2', 'Supplier Selecta dispatched shipment for PO KM-PO-2026-0040 via JT (Trk: 213123). 10 total unit(s) expected.', 'goods_receipts.php?po_id=34&asn_id=16', 0, '2026-09-25 15:59:22', NULL, NULL, NULL, NULL),
(414, 6, 'asn_submitted', 'Incoming Delivery Notice / ASN — KM-ASN-2026-0034-2', 'Supplier Selecta dispatched shipment for PO KM-PO-2026-0040 via JT (Trk: 213123). 10 total unit(s) expected.', 'goods_receipts.php?po_id=34&asn_id=16', 0, '2026-09-25 15:59:22', NULL, NULL, NULL, NULL),
(415, 13, 'asn_submitted', 'Incoming Delivery Notice / ASN — KM-ASN-2026-0034-2', 'Supplier Selecta dispatched shipment for PO KM-PO-2026-0040 via JT (Trk: 213123). 10 total unit(s) expected.', 'goods_receipts.php?po_id=34&asn_id=16', 0, '2026-09-25 15:59:22', NULL, NULL, NULL, NULL),
(416, 1, 'asn_submitted', 'Shipment Dispatched on PO KM-PO-2026-0040 (KM-ASN-2026-0034-2)', 'Supplier Selecta submitted ASN KM-ASN-2026-0034-2 with status \"shipped\".', 'purchase_orders.php?id=34', 0, '2026-09-25 15:59:22', NULL, NULL, NULL, NULL),
(417, 1, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_ING_1790353489', 'Stock hit 3 kg. Requested 25 kg from the default supplier.', 'requisitions.php?id=57', 0, '2026-09-25 16:24:49', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 57),
(418, 6, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_ING_1790353489', 'Stock hit 3 kg. Requested 25 kg from the default supplier.', 'requisitions.php?id=57', 0, '2026-09-25 16:24:49', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 57),
(419, 11, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_ING_1790353489', 'Stock hit 3 kg. Requested 25 kg from the default supplier.', 'requisitions.php?id=57', 0, '2026-09-25 16:24:49', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 57),
(420, 1, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_ING_1790353511', 'Stock hit 3 kg. Requested 25 kg from the default supplier.', 'requisitions.php?id=58', 0, '2026-09-25 16:25:11', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 58),
(421, 6, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_ING_1790353511', 'Stock hit 3 kg. Requested 25 kg from the default supplier.', 'requisitions.php?id=58', 0, '2026-09-25 16:25:11', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 58),
(422, 11, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_ING_1790353511', 'Stock hit 3 kg. Requested 25 kg from the default supplier.', 'requisitions.php?id=58', 0, '2026-09-25 16:25:11', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 58),
(423, 2, 'invoice_created', 'Invoice 56256 logged for PO #35', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=26', 0, '2026-09-25 16:28:38', NULL, NULL, NULL, NULL),
(424, 7, 'invoice_created', 'Invoice 56256 logged for PO #35', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=26', 0, '2026-09-25 16:28:38', NULL, NULL, NULL, NULL),
(425, 11, 'invoice_created', 'Invoice 56256 logged for PO #35', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=26', 0, '2026-09-25 16:28:38', NULL, NULL, NULL, NULL),
(426, 6, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Auto-reorder — TEST_ING_1790353489 can now go out for supplier quotes.', 'rfq.php?requisition_id=57', 0, '2026-09-25 16:31:44', NULL, NULL, NULL, NULL),
(427, 11, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Auto-reorder — TEST_ING_1790353489 can now go out for supplier quotes.', 'rfq.php?requisition_id=57', 0, '2026-09-25 16:31:44', NULL, NULL, NULL, NULL),
(428, 1, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0057', 'Kofee Manila invites ASN Test Logistics Supplier to submit a quotation for \"Auto-reorder — TEST_ING_1790353489\" due by 2026-09-27.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 16:32:13', NULL, NULL, NULL, NULL),
(429, 1, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0057', 'Kofee Manila invites ASN Test Logistics Supplier to submit a quotation for \"Auto-reorder — TEST_ING_1790353489\" due by 2026-09-27.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 16:32:13', NULL, NULL, NULL, NULL),
(430, 12, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0057', 'Kofee Manila invites Selecta to submit a quotation for \"Auto-reorder — TEST_ING_1790353489\" due by 2026-09-27.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 16:32:13', NULL, NULL, NULL, NULL),
(431, 1, 'bid_submitted', 'New quote submitted', 'ASN Test Logistics Supplier quoted ₱3,700.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:32:32', NULL, NULL, NULL, NULL),
(432, 6, 'bid_submitted', 'New quote submitted', 'ASN Test Logistics Supplier quoted ₱3,700.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:32:32', NULL, NULL, NULL, NULL),
(433, 11, 'bid_submitted', 'New quote submitted', 'ASN Test Logistics Supplier quoted ₱3,700.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:32:32', NULL, NULL, NULL, NULL),
(434, 1, 'bid_submitted', 'New quote submitted', 'ASN Test Logistics Supplier quoted ₱3,700.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:32:41', NULL, NULL, NULL, NULL),
(435, 6, 'bid_submitted', 'New quote submitted', 'ASN Test Logistics Supplier quoted ₱3,700.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:32:41', NULL, NULL, NULL, NULL),
(436, 11, 'bid_submitted', 'New quote submitted', 'ASN Test Logistics Supplier quoted ₱3,700.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:32:41', NULL, NULL, NULL, NULL),
(437, 1, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱3,638.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:33:07', NULL, NULL, NULL, NULL),
(438, 6, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱3,638.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:33:07', NULL, NULL, NULL, NULL),
(439, 11, 'bid_submitted', 'New quote submitted', 'Selecta quoted ₱3,638.00 on RFQ #38', 'rfq.php?id=38', 0, '2026-09-25 16:33:07', NULL, NULL, NULL, NULL),
(440, 2, 'requisition_filed', 'New requisition awaiting review', 'Testing Over The Budget — ₱64.00', 'requisitions.php', 0, '2026-09-25 16:34:46', NULL, NULL, NULL, NULL),
(441, 6, 'requisition_filed', 'New requisition awaiting review', 'Testing Over The Budget — ₱64.00', 'requisitions.php', 0, '2026-09-25 16:34:46', NULL, NULL, NULL, NULL),
(442, 7, 'requisition_filed', 'New requisition awaiting review', 'Testing Over The Budget — ₱64.00', 'requisitions.php', 0, '2026-09-25 16:34:46', NULL, NULL, NULL, NULL),
(443, 11, 'requisition_filed', 'New requisition awaiting review', 'Testing Over The Budget — ₱64.00', 'requisitions.php', 0, '2026-09-25 16:34:46', NULL, NULL, NULL, NULL),
(444, 6, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing Over The Budget can now go out for supplier quotes.', 'rfq.php?requisition_id=59', 0, '2026-09-25 16:34:53', NULL, NULL, NULL, NULL),
(445, 11, 'requisition_approved', 'Requisition approved — ready for RFQ', 'Testing Over The Budget can now go out for supplier quotes.', 'rfq.php?requisition_id=59', 0, '2026-09-25 16:34:53', NULL, NULL, NULL, NULL),
(446, 1, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0059', 'Kofee Manila invites ASN Test Logistics Supplier to submit a quotation for \"Testing Over The Budget\" due by 2026-10-03.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 16:35:04', NULL, NULL, NULL, NULL),
(447, 1, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0059', 'Kofee Manila invites ASN Test Logistics Supplier to submit a quotation for \"Testing Over The Budget\" due by 2026-10-03.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 16:35:04', NULL, NULL, NULL, NULL),
(448, 12, 'rfq_invite', 'New RFQ Invitation Letter — KM-RFQ-2026-0059', 'Kofee Manila invites Selecta to submit a quotation for \"Testing Over The Budget\" due by 2026-10-03.', 'supplier_portal.php?tab=rfqs', 0, '2026-09-25 16:35:04', NULL, NULL, NULL, NULL),
(449, 2, 'finance_review_needed', 'Purchase Proposal Requires Finance Review', 'Selected quote for RFQ KM-RFQ-2026-0059 (₱10,000.00) exceeds threshold (₱10,000.00).', 'finance_purchase_approvals.php', 0, '2026-09-25 16:35:23', NULL, NULL, NULL, NULL),
(450, 6, 'finance_review_needed', 'Purchase Proposal Requires Finance Review', 'Selected quote for RFQ KM-RFQ-2026-0059 (₱10,000.00) exceeds threshold (₱10,000.00).', 'finance_purchase_approvals.php', 0, '2026-09-25 16:35:23', NULL, NULL, NULL, NULL),
(451, 7, 'finance_review_needed', 'Purchase Proposal Requires Finance Review', 'Selected quote for RFQ KM-RFQ-2026-0059 (₱10,000.00) exceeds threshold (₱10,000.00).', 'finance_purchase_approvals.php', 0, '2026-09-25 16:35:23', NULL, NULL, NULL, NULL),
(452, 1, 'finance_approved', 'Finance Approved Purchase Quote', 'Quotation from Selecta (₱10,000.00) for Testing Over The Budget has been approved by Finance. You can now issue the Purchase Contract.', 'rfq.php?id=39', 0, '2026-09-25 16:36:51', NULL, NULL, NULL, NULL),
(453, 1, 'finance_approved', 'Finance Approved Purchase Quote', 'Quotation from Selecta (₱10,000.00) for Testing Over The Budget has been authorized by Finance.', 'rfq.php?id=39', 0, '2026-09-25 16:36:51', NULL, NULL, NULL, NULL),
(454, 6, 'finance_approved', 'Finance Approved Purchase Quote', 'Quotation from Selecta (₱10,000.00) for Testing Over The Budget has been authorized by Finance.', 'rfq.php?id=39', 0, '2026-09-25 16:36:51', NULL, NULL, NULL, NULL),
(455, 11, 'finance_approved', 'Finance Approved Purchase Quote', 'Quotation from Selecta (₱10,000.00) for Testing Over The Budget has been authorized by Finance.', 'rfq.php?id=39', 0, '2026-09-25 16:36:51', NULL, NULL, NULL, NULL),
(456, 12, 'contract_ready', 'New Purchase Contract Awaiting Your Signature', 'Kofee Manila has sent Purchase Contract KM-CNT-2026-0059 for Supply Agreement: Testing Over The Budget — Selecta (₱64.00). Please review terms and countersign.', 'supplier_portal.php?tab=contracts&contract_id=23', 0, '2026-09-25 16:44:20', NULL, NULL, NULL, NULL),
(457, 1, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790356113', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=60', 0, '2026-09-25 17:08:33', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 60),
(458, 6, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790356113', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=60', 0, '2026-09-25 17:08:33', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 60),
(459, 11, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790356113', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=60', 0, '2026-09-25 17:08:33', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 60),
(460, 1, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790356134', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=61', 0, '2026-09-25 17:08:54', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 61),
(461, 6, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790356134', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=61', 0, '2026-09-25 17:08:54', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 61),
(462, 11, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790356134', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=61', 0, '2026-09-25 17:08:54', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 61),
(463, 1, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790358873', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=67', 0, '2026-09-25 17:54:33', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 67),
(464, 6, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790358873', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=67', 0, '2026-09-25 17:54:33', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 67),
(465, 11, 'inventory_auto_reorder', 'Auto-reorder filed — TEST_MATCHA_POWDER_1790358873', 'Stock hit 3 kg. Requested 20 kg from the default supplier.', 'requisitions.php?id=67', 0, '2026-09-25 17:54:33', NULL, 'INVENTORY_AUTO_REORDER', 'requisition', 67),
(466, 2, 'invoice_created', 'Invoice dasda logged for PO #34', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=30', 0, '2026-09-25 17:56:35', NULL, NULL, NULL, NULL),
(467, 7, 'invoice_created', 'Invoice dasda logged for PO #34', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=30', 0, '2026-09-25 17:56:35', NULL, NULL, NULL, NULL),
(468, 11, 'invoice_created', 'Invoice dasda logged for PO #34', 'Ready for 3-way match against PO and Goods Receipt.', 'three_way_match.php?invoice_id=30', 0, '2026-09-25 17:56:35', NULL, NULL, NULL, NULL),
(469, 12, 'payment_advice', 'Payment sent', 'Payment of ₱750.00 for Invoice dasda has been completed.', 'supplier_portal.php', 0, '2026-09-25 17:57:04', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(12) NOT NULL,
  `user_id` int(50) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tip_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `service_charge_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) NOT NULL DEFAULT 'dine-in',
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` date NOT NULL DEFAULT curdate(),
  `placed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `stock_deducted` tinyint(1) NOT NULL DEFAULT 0,
  `ingredients_deducted_at` datetime DEFAULT NULL,
  `paymongo_session_id` varchar(100) DEFAULT NULL,
  `paymongo_payment_id` varchar(100) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_amount` decimal(12,2) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `user_id`, `employee_id`, `total_amount`, `tip_amount`, `service_charge_amount`, `payment_method`, `status`, `created_at`, `placed_at`, `stock_deducted`, `ingredients_deducted_at`, `paymongo_session_id`, `paymongo_payment_id`, `payment_status`, `amount_tendered`, `change_amount`, `payment_reference`) VALUES
(1, 1, 2, 246.00, 0.00, 0.00, 'Dine In', 'cancelled', '2026-08-04', '2026-08-04 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(2, 1, 2, 2324.00, 0.00, 0.00, 'Dine In', 'cancelled', '2026-08-14', '2026-08-14 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(3, 1, 2, 2324.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-01', '2026-09-01 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(4, 8, 4, 6800.00, 0.00, 0.00, 'Dine In', 'cancelled', '2026-09-11', '2026-09-11 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(5, 8, 4, 140.00, 0.00, 0.00, 'Dine In', 'cancelled', '2026-09-11', '2026-09-11 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(6, 8, 4, 140.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-11', '2026-09-11 12:00:00', 0, '2026-09-11 03:29:49', NULL, NULL, 'pending', NULL, NULL, NULL),
(7, 8, 4, 140.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-11', '2026-09-11 12:00:00', 0, '2026-09-11 04:01:06', NULL, NULL, 'pending', NULL, NULL, NULL),
(8, 8, 4, 275.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-13', '2026-09-13 12:00:00', 0, '2026-09-13 20:02:38', NULL, NULL, 'pending', NULL, NULL, NULL),
(9, 8, 4, 450.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-13', '2026-09-13 12:00:00', 0, '2026-09-13 20:03:15', NULL, NULL, 'pending', NULL, NULL, NULL),
(10, 8, 4, 2250.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-13', '2026-09-13 12:00:00', 0, '2026-09-13 20:04:43', NULL, NULL, 'pending', NULL, NULL, NULL),
(11, 8, 4, 140.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-13', '2026-09-13 12:00:00', 0, '2026-09-13 20:06:29', NULL, NULL, 'pending', NULL, NULL, NULL),
(13, 10, 8, 23.00, 0.00, 0.00, 'Dine In', 'cancelled', '2026-09-15', '2026-09-15 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(15, 10, 8, 23.00, 0.00, 0.00, 'PayMongo', 'cancelled', '2026-09-15', '2026-09-15 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(16, 10, 8, 46.00, 0.00, 0.00, 'PayMongo', 'cancelled', '2026-09-15', '2026-09-15 12:00:00', 0, NULL, NULL, NULL, 'pending', NULL, NULL, NULL),
(17, 10, 8, 23.00, 0.00, 0.00, 'PayMongo', 'completed', '2026-09-15', '2026-09-15 12:00:00', 0, '2026-09-15 03:13:41', NULL, NULL, 'pending', NULL, NULL, NULL),
(18, 8, 4, 23.00, 0.00, 0.00, 'PayMongo', 'completed', '2026-09-15', '2026-09-15 12:00:00', 0, '2026-09-15 03:41:37', NULL, NULL, 'pending', NULL, NULL, NULL),
(19, 10, 8, 23.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-15', '2026-09-15 12:00:00', 0, '2026-09-15 14:00:23', NULL, NULL, 'pending', NULL, NULL, NULL),
(20, 10, 8, 23.00, 0.00, 0.00, 'PayMongo', 'completed', '2026-09-15', '2026-09-15 12:00:00', 0, '2026-09-15 05:04:30', NULL, NULL, 'pending', NULL, NULL, NULL),
(21, 8, 4, 53.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-15', '2026-09-15 12:00:00', 0, '2026-09-15 14:00:22', NULL, NULL, 'pending', NULL, NULL, NULL),
(22, 10, 8, 23.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-15', '2026-09-15 12:00:00', 0, '2026-09-15 14:44:13', NULL, NULL, 'pending', NULL, NULL, NULL),
(23, 10, 8, 23.00, 0.00, 0.00, 'PayMongo', 'completed', '2026-09-15', '2026-09-15 12:00:00', 0, '2026-09-15 14:44:06', NULL, NULL, 'pending', NULL, NULL, NULL),
(31, 10, 8, 300.00, 0.00, 0.00, 'Dine In', 'completed', '2026-09-18', '2026-09-18 12:00:00', 1, '2026-09-18 15:47:56', NULL, NULL, 'pending', NULL, NULL, NULL),
(33, 10, 8, 150.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-18', '2026-09-18 12:00:00', 1, '2026-09-18 16:05:26', 'cs_bdb0af5a74b0939c82ce025d', NULL, 'paid', NULL, NULL, NULL),
(34, 10, 8, 150.00, 0.00, 0.00, 'cash', 'completed', '2026-09-18', '2026-09-18 12:00:00', 1, '2026-09-18 16:59:55', NULL, NULL, 'pending', NULL, NULL, NULL),
(35, 10, 8, 300.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-18', '2026-09-18 12:00:00', 1, '2026-09-18 17:02:26', 'cs_0280c2eb4e626b93ac23f8e9', NULL, 'pending', NULL, NULL, NULL),
(36, 10, 8, 1500.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-20', '2026-09-20 10:41:08', 1, '2026-09-20 01:01:04', 'cs_2604763a1c06633ef016ac11', NULL, 'paid', NULL, NULL, NULL),
(37, 10, 8, 110.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-20', '2026-09-20 10:41:08', 1, '2026-09-20 01:02:54', 'cs_1db4dd2028fc7d7f4f18b2dc', NULL, 'paid', NULL, NULL, NULL),
(40, 1, NULL, 23.00, 0.00, 0.00, 'cash', 'pending', '2026-09-20', '2026-09-20 13:03:52', 1, '2026-09-20 13:03:52', NULL, NULL, 'paid', 73.00, 50.00, NULL),
(41, 1, NULL, 23.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-20', '2026-09-20 13:03:52', 1, '2026-09-20 13:03:52', 'cs_demo_test123', NULL, 'pending', NULL, NULL, NULL),
(46, 1, 2, 300.00, 0.00, 0.00, 'cash', 'completed', '2026-09-20', '2026-09-20 13:12:26', 1, '2026-09-20 13:12:26', NULL, NULL, 'paid', 1000.00, 700.00, NULL),
(47, 1, 2, 450.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-20', '2026-09-20 13:12:35', 1, '2026-09-20 13:12:35', 'cs_demo_872d0495ade4c07b', 'pay_sim_4b2891253ec1', 'paid', NULL, NULL, 'pay_sim_4b2891253ec1'),
(48, 1, 2, 300.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-20', '2026-09-20 13:13:08', 1, '2026-09-20 13:13:08', 'cs_demo_f102103be14532fe', 'pay_sim_c65f6f4bbb88', 'paid', NULL, NULL, 'pay_sim_c65f6f4bbb88'),
(49, 1, 2, 150.00, 0.00, 0.00, 'paymongo', 'completed', '2026-09-21', '2026-09-21 13:07:40', 1, '2026-09-21 13:07:40', 'cs_demo_eccf82c709dbb7b6', NULL, 'pending', NULL, NULL, NULL),
(50, 1, 2, 184.00, 0.00, 0.00, 'paymongo', 'cancelled', '2026-09-21', '2026-09-21 13:08:13', 1, '2026-09-21 13:08:13', 'cs_demo_6fe706086b73f017', NULL, 'failed', NULL, NULL, NULL),
(51, 1, 2, 184.00, 0.00, 0.00, 'cash', 'completed', '2026-09-21', '2026-09-21 13:08:51', 1, '2026-09-21 13:08:51', NULL, NULL, 'paid', 1000.00, 816.00, NULL);

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
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `size`, `quantity`, `price`, `subtotal`) VALUES
(15, 13, 7, 'small', 1, 23.00, 23.00),
(17, 15, 7, 'small', 1, 23.00, 23.00),
(18, 16, 7, 'small', 2, 23.00, 46.00),
(19, 17, 8, 'small', 1, 23.00, 23.00),
(20, 18, 8, 'small', 1, 23.00, 23.00),
(21, 19, 8, 'small', 1, 23.00, 23.00),
(22, 20, 8, 'small', 1, 23.00, 23.00),
(23, 21, 8, 'small', 1, 53.00, 53.00),
(24, 22, 8, 'small', 1, 23.00, 23.00),
(25, 23, 8, 'small', 1, 23.00, 23.00),
(33, 31, 9, 'small', 2, 150.00, 300.00),
(35, 33, 9, 'small', 1, 150.00, 150.00),
(36, 34, 9, 'small', 1, 150.00, 150.00),
(37, 35, 9, 'small', 2, 150.00, 300.00),
(38, 36, 9, 'small', 10, 150.00, 1500.00),
(39, 37, 10, 'small', 1, 34.00, 34.00),
(40, 37, 10, 'large', 1, 76.00, 76.00),
(45, 46, 9, 'small', 2, 150.00, 300.00),
(46, 47, 9, 'small', 3, 150.00, 450.00),
(47, 48, 9, 'small', 2, 150.00, 300.00),
(48, 49, 9, 'small', 1, 150.00, 150.00),
(49, 50, 9, 'small', 1, 150.00, 150.00),
(50, 50, 10, 'small', 1, 34.00, 34.00),
(51, 51, 10, 'small', 1, 34.00, 34.00),
(52, 51, 9, 'small', 1, 150.00, 150.00);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `email`, `token`, `expires_at`, `used_at`, `created_at`) VALUES
(2, 8, 'crew@gmail.com', '7aa14d6cb61428e20e2f6112abfca292dad2f2451f12d6a23971cf70213b71cd', '2026-09-21 06:38:42', '2026-09-21 06:08:44', '2026-09-20 22:08:42'),
(3, 8, 'crew@gmail.com', '823881be90bd1a406a5bc8dc0d11f28db586205851edcc293e1e6c9a8c9c61b7', '2026-09-21 06:38:44', '2026-09-21 06:08:46', '2026-09-20 22:08:44'),
(4, 8, 'crew@gmail.com', '4f114903b2ba128d621ae3682ae381318f0fd6bdb8c3baf13d1920afee86e7ca', '2026-09-21 06:38:46', '2026-09-21 06:08:48', '2026-09-20 22:08:46'),
(5, 8, 'crew@gmail.com', 'b76ef7ced475d2bc186a15b3d487dfc523aadf9da88b56382dc08f07eece3122', '2026-09-21 06:38:48', '2026-09-21 06:10:29', '2026-09-20 22:08:48'),
(6, 8, 'crew@gmail.com', '9b7713c26b3c2fd440f0ae8a3201673973c8d2298a4e70f88d9481dd1ce2e767', '2026-09-21 06:40:29', '2026-09-21 06:10:31', '2026-09-20 22:10:29'),
(7, 8, 'crew@gmail.com', '5a8613a5c6ae4b5870246219d236430cac124b4dd4e300613030f2e5ca06c0b9', '2026-09-21 06:40:31', NULL, '2026-09-20 22:10:31'),
(8, 2, 'khyllechester.roque07@gmail.com', 'fe209e371a40a33d600d42c9d0d82e9a9a585db4ae151bd373866c49509d044d', '2026-09-21 06:40:43', '2026-09-21 06:17:28', '2026-09-20 22:10:43'),
(9, 2, 'khyllechester.roque07@gmail.com', '6f87cfe0127386d656f91b50ecdf0289c0197088a337686a933218237f479faa', '2026-09-21 06:47:28', '2026-09-21 06:22:11', '2026-09-20 22:17:28'),
(10, 2, 'khyllechester.roque07@gmail.com', '272a0fbb4581ae92341540c6f8ad803339bf61ce4c2d4ae6a35df6e3fe7ccf8c', '2026-09-21 06:52:11', '2026-09-21 06:22:16', '2026-09-20 22:22:11'),
(11, 2, 'khyllechester.roque07@gmail.com', 'd34caef5e5e14c009696f279d404c218f6af49851058ac9361cd8610dd58867c', '2026-09-21 06:52:16', '2026-09-21 06:22:26', '2026-09-20 22:22:16'),
(12, 2, 'khyllechester.roque07@gmail.com', '2ee207101a2d8166bf87b1ab6699b00513b07c37a0a13a203e24a4de5bee92a4', '2026-09-21 06:52:26', '2026-09-21 06:24:41', '2026-09-20 22:22:26');

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
(8, 12, 15, 150.00, 'cash', '1234566723', 'completed', NULL, 7, '2026-09-14 19:26:41', '2026-09-15 03:26:42'),
(9, 17, 21, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 10:22:11', '2026-09-25 18:22:11'),
(10, 18, 22, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 10:48:34', '2026-09-25 18:48:35'),
(11, 19, 23, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 11:05:54', '2026-09-25 19:05:54'),
(12, 20, 24, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 14:13:48', '2026-09-25 22:13:48'),
(13, 21, 25, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 14:39:48', '2026-09-25 22:39:48'),
(14, 22, 29, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 14:57:49', '2026-09-25 22:57:49'),
(15, 23, 33, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 15:10:37', '2026-09-25 23:10:37'),
(16, 24, 40, 11500.00, 'bank_transfer', 'TXN-BANK-112233', 'completed', NULL, 1, '2026-09-25 15:37:26', '2026-09-25 23:37:26'),
(19, 30, 34, 750.00, 'check', 'asdaf', 'completed', NULL, 1, '2026-09-25 17:56:59', '2026-09-26 01:57:04');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_audit`
--

CREATE TABLE `payroll_audit` (
  `id` int(11) NOT NULL,
  `period_id` int(11) DEFAULT NULL,
  `payslip_id` int(11) DEFAULT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_audit`
--

INSERT INTO `payroll_audit` (`id`, `period_id`, `payslip_id`, `actor_id`, `action`, `detail`, `created_at`) VALUES
(1, 1, NULL, 1, 'created', 'Period opened: Sep 14 - Sep 20, 2026', '2026-09-20 11:09:41'),
(2, 1, NULL, 1, 'calculated', '9 payslip(s), 9 exception(s)', '2026-09-20 11:17:33'),
(3, 1, NULL, 1, 'approved', 'Approved with 9 exception(s).', '2026-09-20 11:26:50'),
(5, 1, NULL, 1, 'released', 'Payroll released. Net total: -1,038.42', '2026-09-20 11:54:31'),
(6, 3, NULL, 1, 'created', 'Period opened: Sep 21 - Sep 30, 2026', '2026-09-21 05:29:10'),
(7, 3, NULL, 1, 'calculated', '10 payslip(s), 10 exception(s)', '2026-09-21 05:29:20'),
(8, 3, NULL, 1, 'period_deleted', 'Deleted pay period: Sep 21 - Sep 30, 2026', '2026-09-21 05:29:52');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_periods`
--

CREATE TABLE `payroll_periods` (
  `id` int(11) NOT NULL,
  `label` varchar(80) NOT NULL,
  `frequency` enum('weekly','biweekly','semimonthly','monthly') NOT NULL DEFAULT 'semimonthly',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `pay_date` date NOT NULL,
  `branch` varchar(80) DEFAULT NULL,
  `status` enum('draft','calculated','approved','paid','locked') NOT NULL DEFAULT 'draft',
  `gross_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deduction_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `headcount` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `calculated_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_periods`
--

INSERT INTO `payroll_periods` (`id`, `label`, `frequency`, `period_start`, `period_end`, `pay_date`, `branch`, `status`, `gross_total`, `deduction_total`, `net_total`, `headcount`, `created_by`, `calculated_at`, `approved_by`, `approved_at`, `paid_at`, `notes`, `created_at`) VALUES
(1, 'Sep 14 - Sep 20, 2026', 'weekly', '2026-09-14', '2026-09-20', '2026-09-23', NULL, 'paid', 0.00, 1038.42, -1038.42, 9, 1, '2026-09-20 11:17:33', 1, '2026-09-20 11:26:50', '2026-09-20 11:54:31', NULL, '2026-09-20 11:09:41');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_settings`
--

CREATE TABLE `payroll_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(200) NOT NULL,
  `label` varchar(150) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll_settings`
--

INSERT INTO `payroll_settings` (`setting_key`, `setting_value`, `label`, `updated_at`) VALUES
('auto_approve_attendance', '0', 'Include unapproved attendance in payroll runs', '2026-09-20 11:03:40'),
('grace_period_minutes', '15', 'Minutes of lateness before a deduction applies', '2026-09-20 11:03:40'),
('holiday_multiplier', '2.00', 'Regular-holiday hourly multiplier', '2026-09-20 11:03:40'),
('night_diff_multiplier', '1.10', 'Night differential multiplier (22:00-06:00)', '2026-09-20 11:03:40'),
('overtime_multiplier', '1.25', 'Overtime hourly multiplier', '2026-09-20 11:03:40'),
('rest_day_multiplier', '1.30', 'Rest-day hourly multiplier', '2026-09-20 11:03:40'),
('standard_hours_per_day', '8', 'Standard working hours per day', '2026-09-20 11:03:40'),
('tip_pool_mode', 'hours', 'How pooled tips are split: hours or equal', '2026-09-20 11:03:40'),
('working_days_per_month', '26', 'Working days used to derive a daily rate', '2026-09-20 11:03:40');

-- --------------------------------------------------------

--
-- Table structure for table `payslips`
--

CREATE TABLE `payslips` (
  `id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `days_worked` decimal(6,2) NOT NULL DEFAULT 0.00,
  `regular_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `night_diff_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `holiday_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `rest_day_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `undertime_minutes` int(11) NOT NULL DEFAULT 0,
  `absent_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `paid_leave_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `basic_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `overtime_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `holiday_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rest_day_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `night_diff_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tips` decimal(12,2) NOT NULL DEFAULT 0.00,
  `allowances` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `late_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `absence_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sss` decimal(12,2) NOT NULL DEFAULT 0.00,
  `philhealth` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pagibig` decimal(12,2) NOT NULL DEFAULT 0.00,
  `withholding_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deduction` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(12,2) NOT NULL DEFAULT 0.00,
  `snapshot_pay_type` enum('hourly','daily','monthly','commission') NOT NULL DEFAULT 'monthly',
  `snapshot_pay_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','bank_transfer','payroll_card','ewallet') NOT NULL DEFAULT 'cash',
  `payment_status` enum('unpaid','paid','held') NOT NULL DEFAULT 'unpaid',
  `paid_at` datetime DEFAULT NULL,
  `has_exception` tinyint(1) NOT NULL DEFAULT 0,
  `exception_note` varchar(400) DEFAULT NULL,
  `notes` varchar(400) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payslips`
--

INSERT INTO `payslips` (`id`, `period_id`, `employee_id`, `days_worked`, `regular_hours`, `overtime_hours`, `night_diff_hours`, `holiday_hours`, `rest_day_hours`, `late_minutes`, `undertime_minutes`, `absent_days`, `paid_leave_days`, `basic_pay`, `overtime_pay`, `holiday_pay`, `rest_day_pay`, `night_diff_pay`, `commission`, `tips`, `allowances`, `bonus`, `gross_pay`, `late_deduction`, `absence_deduction`, `sss`, `philhealth`, `pagibig`, `withholding_tax`, `loan_deduction`, `other_deduction`, `total_deductions`, `net_pay`, `snapshot_pay_type`, `snapshot_pay_rate`, `payment_method`, `payment_status`, `paid_at`, `has_exception`, `exception_note`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 0.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, 'Net pay is negative - deductions exceed gross; No pay rate configured for this employee; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(2, 1, 4, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 20000.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, '3 shift(s) never clocked out - hours not counted; Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(3, 1, 3, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 23233.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, 'Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(4, 1, 6, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 25434.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, 'Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(5, 1, 8, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 524363.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, 'Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(6, 1, 5, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 22353.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, 'Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(7, 1, 9, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 51473.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, 'Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(8, 1, 10, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 53443.99, 'cash', 'paid', '2026-09-20 11:54:31', 1, '1 shift(s) never clocked out - hours not counted; Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31'),
(9, 1, 2, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0, 0, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 57.69, 57.69, 0.00, 0.00, 0.00, 0.00, 115.38, -115.38, 'monthly', 20000.00, 'cash', 'paid', '2026-09-20 11:54:31', 1, 'Net pay is negative - deductions exceed gross; No attendance recorded in this period', NULL, '2026-09-20 11:17:33', '2026-09-20 11:54:31');

-- --------------------------------------------------------

--
-- Table structure for table `payslip_adjustments`
--

CREATE TABLE `payslip_adjustments` (
  `id` int(11) NOT NULL,
  `payslip_id` int(11) NOT NULL,
  `kind` enum('allowance','bonus','deduction','reimbursement') NOT NULL,
  `label` varchar(120) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
('analytics.view', 'Financial Analytics & Reports', 'reports', 'View sales reports, P&L, bestsellers, and cashier summaries'),
('attendance.view', 'Attendance & Time-Clock', 'hr', 'Review staff clock-in/out records and calculate working hours'),
('dashboard.view', 'View Dashboard Overview', 'reports', 'Access system summary metrics, daily charts, and quick actions'),
('employee_dashboard.view', 'View Employee Dashboard', 'General', 'Personal staff dashboard to clock in, request leave, and view metrics'),
('files.download', 'Download Secured Files', 'Settings', 'Download secure files, payslips, and exports'),
('inventory.expiry.manage', 'Manage Batch Expiry Dates', 'Inventory', 'Track and manage perishable batch expiration dates'),
('inventory.manage', 'Manage & Restock Inventory', 'inventory', 'Add new ingredients, adjust stock, and record deliveries'),
('inventory.view', 'View Inventory & BOM', 'inventory', 'View ingredient stock levels, unit costs, and reorder flags'),
('leave.view', 'Leave & PTO Management', 'hr', 'Review, approve, and reject employee leave requests'),
('menu.delete', 'Delete & Archive Items', 'menu', 'Archive or permanently delete menu products'),
('menu.edit', 'Edit Items & Pricing', 'menu', 'Modify drink prices, descriptions, and recipe configurations'),
('menu.manage', 'Menu Management & Add Items', 'menu', 'Create new drinks and recipes in the menu manager'),
('orders.history', 'Order Receipts & History', 'orders', 'View completed transaction history and print receipts'),
('orders.new', 'Create POS Orders', 'orders', 'Ring up customer orders and process checkout payments'),
('orders.pending', 'Kitchen & Pending Orders', 'orders', 'View and fulfill active orders in the kitchen/barista queue'),
('payroll.advance.request', 'Request Cash Advance (Staff)', 'Payroll', 'Staff self-service: submit salary advance or emergency cash requests for management review'),
('payroll.approve', 'Approve Calculated Payroll Runs', 'Payroll', 'Authorize and approve draft payroll periods prior to disbursement'),
('payroll.loans', 'Manage & Approve Loans / Advances', 'Payroll', 'Issue employee cash advances, review staff requests, and approve or decline amortization terms'),
('payroll.manage', 'Manage Payroll Periods & Calculate', 'Payroll', 'Create pay periods, trigger automated hours/tips/deduction calculations, and add manual adjustments'),
('payroll.own', 'My Compensation & Payslips (Staff)', 'Payroll', 'Employee self-service: view personal compensation, released payslips, and loan/advance ledger'),
('payroll.release', 'Disburse & Release Payroll', 'Payroll', 'Disburse funds via cash/bank advice/e-wallets, release payslips to staff, and lock pay period'),
('payroll.settings', 'Configure Payroll Standards & Multipliers', 'Payroll', 'Configure working hours, overtime/holiday/night multipliers, tip distribution rules, and statutory schedules'),
('payroll.view', 'View Payroll Register & Dashboard', 'Payroll', 'Access payroll summary metrics, period lists, and employee payslip registers'),
('permissions.manage', 'Manage Role Permissions', 'settings', 'Configure dynamic RBAC permissions matrix for system roles'),
('procurement.attachments.manage', 'Manage Procurement Attachments', 'Procurement', 'Upload and view supporting documents on procurement records'),
('procurement.audit.view', 'View Procurement Audit Log', 'Procurement', 'See the full procurement activity/audit trail'),
('procurement.bidding.review', 'Review Supplier Bids', 'Procurement', 'Evaluate submitted quotes on price, quality, delivery, risk'),
('procurement.budget.manage', 'Manage Procurement Budgets', 'Procurement', 'Allocate and adjust departmental procurement budgets per period'),
('procurement.close', 'Close & Rate Orders', 'Procurement', 'Close completed orders and rate supplier performance'),
('procurement.finance.review', 'Finance Review of Quotes', 'Procurement', 'Review and authorize or reject high-value purchase quotations exceeding the finance threshold'),
('procurement.grn.discrepancy.manage', 'Resolve Delivery Discrepancies', 'Procurement', 'Review and act on short/over/damaged delivery discrepancies'),
('procurement.invoice.create', 'Log Supplier Invoices', 'Procurement', 'Record incoming supplier invoices against a Purchase Order'),
('procurement.invoice.match', 'Match Invoices (3-Way Match)', 'Procurement', 'Match Purchase Order, Goods Receipt, and Invoice'),
('procurement.negotiation', 'Negotiate Supplier Terms', 'Procurement', 'Contact suppliers and negotiate final commercial terms'),
('procurement.payment.process', 'Process Supplier Payments', 'Procurement', 'Schedule and execute payments to suppliers'),
('procurement.performance.rate', 'Rate Supplier Performance', 'Procurement', 'Score suppliers on quality, timeliness, price, and communication'),
('procurement.po.manage', 'Manage Purchase Orders', 'Procurement', 'Create, send, and approve Purchase Orders'),
('procurement.receiving', 'Record Goods Receipt', 'Procurement', 'Confirm delivery and log received quantities (GRN)'),
('procurement.reports.view', 'View Procurement Reports', 'Procurement', 'Access procurement reports and export data'),
('procurement.requisition.create', 'File Purchase Requisitions', 'Procurement', 'Request new goods or services for department'),
('procurement.requisition.review', 'Review Requisitions', 'Procurement', 'Check budget availability, approve or reject requisitions'),
('procurement.requisitions', 'Create / Edit Requisitions', 'Procurement', 'View and manage purchase requisitions'),
('procurement.rfq.manage', 'Manage RFQs', 'Procurement', 'Create and send Requests for Quotation to suppliers'),
('procurement.supplier.portal', 'Supplier Portal Access', 'Procurement', 'Supplier-side access: view RFQ invites, submit bids, acknowledge POs'),
('procurement.suppliers.manage', 'Manage Suppliers', 'Procurement', 'Add, edit, or deactivate suppliers in the directory'),
('procurement.view', 'View Procurement', 'Procurement', 'See the procurement dashboard and requisition list'),
('recruitment.manage', 'Recruitment & Job Vacancies', 'hr', 'Manage job postings, candidate applications, interview stages, and careers portal'),
('requests.manage', 'Manage HR Requests', 'HR', 'Review and process employee HR requests and inquiries'),
('users.manage', 'Staff & User Management', 'users', 'Create and modify employee accounts and system roles');

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
(130, 'po', 15, 'closed', 6, 'Procurement cycle complete, overall rating 5/5', '2026-09-14 19:27:32'),
(131, 'requisition', 17, 'auto_created', 1, 'Auto-reorder triggered for Brown Sugar', '2026-09-21 05:10:42'),
(132, 'requisition', 18, 'letter_issued', NULL, 'Official procurement letter KM-LTR-2026-0018 dispatched to supplier Selecta', '2026-09-22 06:46:27'),
(133, 'procurement_letter', 1, 'acknowledged', NULL, 'Supplier Selecta acknowledged letter KM-LTR-2026-0018', '2026-09-22 06:46:27'),
(134, 'requisition', 19, 'letter_issued', 11, 'Official procurement letter KM-LTR-2026-0019 dispatched to supplier Selecta', '2026-09-22 06:55:08'),
(135, 'procurement_letter', 2, 'acknowledged', 12, 'Supplier Selecta acknowledged letter KM-LTR-2026-0019', '2026-09-22 06:55:32'),
(136, 'bid', 16, 'quoted', 12, 'Selecta quoted ₱1,000.00', '2026-09-22 22:29:31'),
(137, 'requisition', 20, 'letter_issued', 1, 'Official procurement letter KM-LTR-2026-0020 dispatched to supplier Selecta', '2026-09-22 22:46:14'),
(138, 'invoice', 13, 'created', 1, 'PO #6 — ada — ₱53,429.23', '2026-09-25 08:31:29'),
(139, 'contract', 1, 'supplier_signed', NULL, 'Supplier Juan Dela Cruz signed contract CNT-E2E-1790329634', '2026-09-25 09:47:14'),
(140, 'delivery_notice', 1, 'submitted', NULL, 'ASN ASN-2026-0016 submitted for PO #16', '2026-09-25 09:47:14'),
(141, 'contract', 2, 'supplier_signed', NULL, 'Supplier Juan Dela Cruz signed contract CNT-E2E-1790329648', '2026-09-25 09:47:28'),
(142, 'delivery_notice', 2, 'submitted', NULL, 'ASN ASN-2026-0017 submitted for PO #17', '2026-09-25 09:47:28'),
(143, 'contract', 3, 'supplier_signed', NULL, 'Supplier Juan Dela Cruz signed contract CNT-E2E-1790329662', '2026-09-25 09:47:42'),
(144, 'delivery_notice', 3, 'submitted', NULL, 'ASN ASN-2026-0018 submitted for PO #18', '2026-09-25 09:47:42'),
(145, 'contract', 4, 'supplier_signed', NULL, 'Supplier Juan Dela Cruz signed contract CNT-E2E-1790329676', '2026-09-25 09:47:56'),
(146, 'delivery_notice', 4, 'submitted', NULL, 'ASN ASN-2026-0019 submitted for PO #19', '2026-09-25 09:47:56'),
(147, 'requisition', 27, 'approved', 1, 'Approved for sourcing', '2026-09-25 09:54:12'),
(148, 'procurement_letter', 3, 'acknowledged', 12, 'Supplier Selecta acknowledged letter KM-LTR-2026-0020', '2026-09-25 10:25:52'),
(149, 'rfq', 25, 'rfq_created', NULL, 'RFQ invitation letter KM-RFQ-2026-0033 created with buyer e-signature and dispatched to 1 supplier(s)', '2026-09-25 10:48:25'),
(150, 'bid', 32, 'withdrawn', NULL, 'Quotation withdrawn by Selecta for RFQ KM-RFQ-2026-0033', '2026-09-25 10:48:25'),
(151, 'rfq', 27, 'rfq_created', NULL, 'RFQ invitation letter KM-RFQ-2026-0035 created with buyer e-signature and dispatched to 1 supplier(s)', '2026-09-25 11:04:50'),
(152, 'bid', 34, 'withdrawn', NULL, 'Quotation withdrawn by Selecta for RFQ KM-RFQ-2026-0035', '2026-09-25 11:04:50'),
(153, 'rfq', 29, 'rfq_created', 1, 'RFQ invitation letter KM-RFQ-2026-0032 created with buyer e-signature and dispatched to 1 supplier(s)', '2026-09-25 11:05:56'),
(154, 'rfq', 32, 'rfq_created', 1, 'RFQ invitation letter KM-RFQ-2026-0039 created with buyer e-signature and dispatched to 1 supplier(s)', '2026-09-25 14:23:57'),
(155, 'bid', 32, 'bid_recorded', 1, 'Manual quote recorded for supplier ID #1: ₱401.00', '2026-09-25 14:24:07'),
(156, 'bid', 38, 'quote_selected', 1, 'Selected quote from Selecta (₱401.00) — Finance Status: approved', '2026-09-25 14:24:37'),
(157, 'bid', 16, 'quoted', 12, 'Selecta quoted ₱2,000.00', '2026-09-25 14:26:40'),
(158, 'bid', 29, 'quoted', 12, 'Selecta quoted ₱1,552.00', '2026-09-25 14:26:51'),
(159, 'rfq', 33, 'rfq_created', 1, 'RFQ invitation letter KM-RFQ-2026-0040 created with buyer e-signature and dispatched to 1 supplier(s)', '2026-09-25 14:32:24'),
(160, 'bid', 33, 'quoted', 12, 'Selecta quoted ₱1,000.00', '2026-09-25 14:33:10'),
(161, 'bid', 41, 'shortlisted', 1, 'Bid #41 shortlisted by admin', '2026-09-25 14:33:31'),
(162, 'bid', 41, 'quote_selected', 1, 'Selected quote from Selecta (₱1,000.00) — Finance Status: approved', '2026-09-25 14:33:42'),
(163, 'contract', 11, 'created', 1, 'Purchase contract KM-CNT-2026-0040 issued to Selecta', '2026-09-25 14:40:54'),
(164, 'contract', 11, 'fully_signed', 12, 'Supplier Selecta countersigned contract KM-CNT-2026-0040', '2026-09-25 14:41:41'),
(165, 'contract', 12, 'created', 1, 'Purchase contract KM-CNT-2026-0040-2 issued to Selecta', '2026-09-25 14:43:28'),
(166, 'contract', 12, 'fully_signed', 12, 'Supplier Selecta countersigned contract KM-CNT-2026-0040-2', '2026-09-25 14:44:05'),
(169, 'purchase_order', 34, 'issued', 1, 'Issued PO KM-PO-2026-0040 under Contract KM-CNT-2026-0040', '2026-09-25 15:15:35'),
(170, 'po', 34, 'acknowledged', 12, 'Selecta acknowledged order KM-PO-2026-0040', '2026-09-25 15:16:27'),
(171, 'po', 34, 'shipped', 12, 'Selecta marked the order shipped — 83483', '2026-09-25 15:17:05'),
(172, 'grn', 26, 'recorded', 1, 'PO #34 — status: discrepancy', '2026-09-25 15:18:11'),
(173, 'grn', 27, 'recorded', 1, 'PO #34 — status: discrepancy', '2026-09-25 15:18:29'),
(174, 'grn', 27, 'discrepancy_resolved', 1, 'asd', '2026-09-25 15:18:40'),
(175, 'grn', 26, 'discrepancy_resolved', 1, 'asfgawd', '2026-09-25 15:18:51'),
(179, 'delivery_notice', 15, 'submitted', 12, 'ASN KM-ASN-2026-0034 submitted by Selecta via dasd (Status: shipped, Units: 10)', '2026-09-25 15:58:47'),
(180, 'po', 34, 'shipped', 12, 'ASN KM-ASN-2026-0034 dispatched via dasd (Tracking: sad)', '2026-09-25 15:58:47'),
(181, 'delivery_notice', 16, 'submitted', 12, 'ASN KM-ASN-2026-0034-2 submitted by Selecta via JT (Status: shipped, Units: 10)', '2026-09-25 15:59:22'),
(182, 'po', 34, 'shipped', 12, 'ASN KM-ASN-2026-0034-2 dispatched via JT (Tracking: 213123)', '2026-09-25 15:59:22'),
(183, 'requisition', 57, 'auto_created', 1, 'Auto-reorder triggered for TEST_ING_1790353489', '2026-09-25 16:24:49'),
(184, 'requisition', 58, 'auto_created', 1, 'Auto-reorder triggered for TEST_ING_1790353511', '2026-09-25 16:25:11'),
(185, 'invoice', 26, 'created', 1, 'PO #35 — 56256 — ₱15,000.00', '2026-09-25 16:28:38'),
(186, 'requisition', 57, 'approved_over_budget', 1, 'tESTING oVVERUDE', '2026-09-25 16:31:44'),
(187, 'rfq', 38, 'rfq_created', 1, 'RFQ invitation letter KM-RFQ-2026-0057 created with buyer e-signature and dispatched to 3 supplier(s)', '2026-09-25 16:32:13'),
(188, 'bid', 38, 'quoted', 1, 'ASN Test Logistics Supplier quoted ₱3,700.00', '2026-09-25 16:32:32'),
(189, 'bid', 38, 'quoted', 1, 'ASN Test Logistics Supplier quoted ₱3,700.00', '2026-09-25 16:32:41'),
(190, 'bid', 38, 'quoted', 12, 'Selecta quoted ₱3,638.00', '2026-09-25 16:33:07'),
(191, 'bid', 46, 'quote_selected', 1, 'Selected quote from ASN Test Logistics Supplier (₱3,700.00) — Finance Status: approved', '2026-09-25 16:34:08'),
(192, 'rfq', 39, 'rfq_created', 1, 'RFQ invitation letter KM-RFQ-2026-0059 created with buyer e-signature and dispatched to 3 supplier(s)', '2026-09-25 16:35:04'),
(193, 'bid', 39, 'bid_recorded', 1, 'Manual quote recorded for supplier ID #1: ₱10,000.00', '2026-09-25 16:35:16'),
(194, 'bid', 49, 'quote_selected', 1, 'Selected quote from Selecta (₱10,000.00) — Finance Status: pending', '2026-09-25 16:35:23'),
(195, 'finance_approval', 49, 'approved', 1, 'Finance approved quote for Selecta (₱10,000.00). Notes: ', '2026-09-25 16:36:51'),
(196, 'contract', 23, 'created', 1, 'Purchase contract KM-CNT-2026-0059 issued to Selecta', '2026-09-25 16:44:20'),
(197, 'requisition', 60, 'auto_created', 1, 'Auto-reorder triggered for TEST_MATCHA_POWDER_1790356113', '2026-09-25 17:08:33'),
(198, 'requisition', 61, 'auto_created', 1, 'Auto-reorder triggered for TEST_MATCHA_POWDER_1790356134', '2026-09-25 17:08:54'),
(199, 'requisition', 67, 'auto_created', 1, 'Auto-reorder triggered for TEST_MATCHA_POWDER_1790358873', '2026-09-25 17:54:33'),
(200, 'invoice', 30, 'created', 1, 'PO #34 — dasda — ₱750.00', '2026-09-25 17:56:35'),
(201, 'invoice', 30, '3way_match_run', 1, 'matched — Matched clean: invoice ₱750.00 vs PO ₱750.00 (0.0% variance).', '2026-09-25 17:56:44'),
(202, 'invoice', 30, 'approved', 1, NULL, '2026-09-25 17:56:46'),
(203, 'payment', 19, 'scheduled', 1, 'Invoice dasda — ₱750.00', '2026-09-25 17:56:59'),
(204, 'payment', 19, 'completed', 1, '₱750.00', '2026-09-25 17:57:04');

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
(1, 'manager', '2026-Q3', 50000.00, 400.00),
(2, 'crew', '2026-Q3', 10000000.00, 104740.00),
(3, 'finance', '2026-Q3', 10000.00, 1000.00),
(6, 'admin', '2026-Q3', 0.00, 0.00),
(7, 'hr', '2026-Q3', 0.00, 0.00),
(21, 'Inventory', '2026-Q3', 0.00, 3637.50);

-- --------------------------------------------------------

--
-- Table structure for table `procurement_letters`
--

CREATE TABLE `procurement_letters` (
  `id` int(11) NOT NULL,
  `letter_ref` varchar(50) NOT NULL,
  `requisition_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `approver_id` int(11) NOT NULL,
  `approver_name` varchar(150) NOT NULL,
  `approver_title` varchar(100) NOT NULL,
  `approver_signature` longtext NOT NULL,
  `subject` varchar(255) NOT NULL,
  `delivery_terms` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `status` enum('sent','acknowledged') DEFAULT 'sent',
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledgement_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procurement_letters`
--

INSERT INTO `procurement_letters` (`id`, `letter_ref`, `requisition_id`, `supplier_id`, `approver_id`, `approver_name`, `approver_title`, `approver_signature`, `subject`, `delivery_terms`, `payment_terms`, `special_instructions`, `status`, `sent_at`, `acknowledged_at`, `acknowledgement_notes`, `created_at`) VALUES
(1, 'KM-LTR-2026-0018', 18, 1, 1, 'Admin User', 'General Manager', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMgAAAA8CAYAAAAW3wGPAAAACXBIWXMAAAsTAAALEwEAmpwYAAAFmklEQVR42u3cf2wTZRzA8fd1v', 'Procurement Award & Purchase Authorization — Test (#0018)', 'Within 3-5 business days', 'Net 30 Days', 'Deliver to Commissary', 'acknowledged', '2026-09-22 14:46:27', '2026-09-22 14:46:27', 'Delivery scheduled for Friday morning.', '2026-09-22 06:46:27'),
(2, 'KM-LTR-2026-0019', 19, 1, 11, 'Procurment Testing', 'Procurement Manager', 'data:image/png;base64,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', 'Procurement Award & Purchase Authorization — Testing (#0019)', 'Within 5-7 business days upon receipt of order', 'Net 30 Days after delivery and three-way match', 'IN THe Warehourse', 'acknowledged', '2026-09-22 14:55:08', '2026-09-22 14:55:32', '', '2026-09-22 06:55:08'),
(3, 'KM-LTR-2026-0020', 20, 1, 1, 'Admin User', 'Procurement Director / Admin', 'data:image/png;base64,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', 'Procurement Award & Purchase Authorization — Testing (#0020)', 'Within 5-7 business days upon receipt of order', 'Net 30 Days after delivery and three-way match', 'zxzc', 'acknowledged', '2026-09-23 06:46:14', '2026-09-25 18:25:52', '', '2026-09-22 22:46:14');

-- --------------------------------------------------------

--
-- Table structure for table `procurement_settings`
--

CREATE TABLE `procurement_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procurement_settings`
--

INSERT INTO `procurement_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('finance_approval_threshold', '10000.00', 'Purchases exceeding this amount require explicit Finance approval before contract issuance', '2026-09-25 09:10:48'),
('three_way_match_price_tolerance_pct', '3.0', 'Maximum acceptable percentage variance between PO total and Invoice total', '2026-09-25 09:10:48'),
('three_way_match_qty_tolerance_units', '0.0', 'Maximum acceptable unit variance between received quantity and invoiced quantity', '2026-09-25 09:10:48');

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
(8, 'WinnerMelon', 'asda', 23.00, 53.00, 0, 0, 4, '2026-09-15', '2026-09-15', 'assets/menu/1.jpg', 0),
(9, 'Spanishhhhh', '', 150.00, 200.00, 150, 1, 1, '2026-09-18', '2026-09-18', 'assets/menu/item_1789717241_233275db.jpg', 0),
(10, 'Ice Caramel Mahh', '', 34.00, 76.00, 34, 1, 1, '2026-09-20', '2026-09-20', 'assets/menu/item_1789837360_a7cc62aa.jpg', 0);

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
(8, 4, 'large', 18, 1.00),
(11, 10, 'small', 8, 5.00),
(12, 10, 'small', 18, 20.00),
(13, 10, 'large', 8, 6.25),
(14, 10, 'large', 18, 30.00),
(15, 9, 'small', 24, 100.00),
(16, 9, 'large', 9, 10.00),
(17, 9, 'large', 8, 1.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_contracts`
--

CREATE TABLE `purchase_contracts` (
  `id` int(11) NOT NULL,
  `contract_ref` varchar(60) NOT NULL,
  `requisition_id` int(11) NOT NULL,
  `rfq_id` int(11) DEFAULT NULL,
  `bid_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `delivery_terms` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `contract_terms` text DEFAULT NULL,
  `buyer_signed_by` int(11) DEFAULT NULL,
  `buyer_signed_name` varchar(150) DEFAULT NULL,
  `buyer_signed_title` varchar(100) DEFAULT NULL,
  `buyer_signature` longtext DEFAULT NULL,
  `buyer_signed_at` datetime DEFAULT NULL,
  `supplier_signed_by` int(11) DEFAULT NULL,
  `supplier_signed_name` varchar(150) DEFAULT NULL,
  `supplier_signature` longtext DEFAULT NULL,
  `supplier_signed_at` datetime DEFAULT NULL,
  `status` enum('draft','sent_to_supplier','fully_signed','po_created','cancelled') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_contracts`
--

INSERT INTO `purchase_contracts` (`id`, `contract_ref`, `requisition_id`, `rfq_id`, `bid_id`, `supplier_id`, `title`, `total_amount`, `delivery_terms`, `payment_terms`, `contract_terms`, `buyer_signed_by`, `buyer_signed_name`, `buyer_signed_title`, `buyer_signature`, `buyer_signed_at`, `supplier_signed_by`, `supplier_signed_name`, `supplier_signature`, `supplier_signed_at`, `status`, `created_at`) VALUES
(5, 'CTR-TEST-1790331656', 30, 23, 30, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 18:20:56', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 18:20:56', 'fully_signed', '2026-09-25 10:20:56'),
(6, 'CTR-TEST-1790331731', 31, 24, 31, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 18:22:11', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 18:22:11', 'fully_signed', '2026-09-25 10:22:11'),
(7, 'CTR-TEST-1790333314', 34, 26, 33, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 18:48:34', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 18:48:34', 'fully_signed', '2026-09-25 10:48:34'),
(8, 'CTR-TEST-1790334354', 36, 28, 35, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 19:05:54', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 19:05:54', 'fully_signed', '2026-09-25 11:05:54'),
(9, 'CTR-TEST-1790345628', 38, 31, 37, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 22:13:48', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 22:13:48', 'fully_signed', '2026-09-25 14:13:48'),
(10, 'CTR-TEST-1790347188', 41, 34, 42, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 22:39:48', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 22:39:48', 'fully_signed', '2026-09-25 14:39:48'),
(11, 'KM-CNT-2026-0040', 40, 33, 41, 1, 'Supply Agreement: Testing1234 — Selecta', 750.00, 'Delivery within 1 day(s) upon receipt of Purchase Order to Kofee Manila Commissary', 'Net 30 Days upon complete goods receipt and three-way invoice matching', '1. Quality Standards: All items delivered must strictly comply with agreed food safety and freshness standards. Defective or damaged items will be rejected upon delivery.\r\n2. Advance Shipping Notice: The supplier must provide an ASN with tracking details prior to delivery arrival.\r\n3. Three-Way Matching: Invoices will be matched against the Purchase Order and actual received Goods Receipt before payment scheduling.\r\n4. Binding Agreement: This agreement becomes legally binding once countersigned by both Buyer and Supplier authorized representatives.', 1, 'Admin User', 'Procurement Director', 'data:image/png;base64,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', '2026-09-25 22:40:54', 12, 'Supplier Testing', 'data:image/png;base64,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', '2026-09-25 22:41:41', 'po_created', '2026-09-25 14:40:54'),
(12, 'KM-CNT-2026-0040-2', 40, 33, 41, 1, 'Supply Agreement: Testing1234 — Selecta', 750.00, 'Delivery within 1 day(s) upon receipt of Purchase Order to Kofee Manila Commissary', 'Net 30 Days upon complete goods receipt and three-way invoice matching', '1. Quality Standards: All items delivered must strictly comply with agreed food safety and freshness standards. Defective or damaged items will be rejected upon delivery.\r\n2. Advance Shipping Notice: The supplier must provide an ASN with tracking details prior to delivery arrival.\r\n3. Three-Way Matching: Invoices will be matched against the Purchase Order and actual received Goods Receipt before payment scheduling.\r\n4. Binding Agreement: This agreement becomes legally binding once countersigned by both Buyer and Supplier authorized representatives.', 1, 'Admin User', 'Procurement Director', 'data:image/png;base64,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', '2026-09-25 22:43:28', 12, 'Supplier Testing', 'data:image/png;base64,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', '2026-09-25 22:44:05', 'fully_signed', '2026-09-25 14:43:28'),
(16, 'CTR-TEST-1790348269', 46, 35, 43, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 22:57:49', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 22:57:49', 'fully_signed', '2026-09-25 14:57:49'),
(19, 'CTR-TEST-1790349037', 49, 36, 44, 1, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 23:10:37', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 23:10:37', 'fully_signed', '2026-09-25 15:10:37'),
(22, 'CTR-TEST-1790350646', 54, 37, 45, 2, 'Coffee Supply Agreement', 11500.00, 'Delivery to Main Branch', 'Net 30', NULL, 1, 'Kofee Procurement Manager', 'Procurement Head', 'data:image/png;base64,buyer_sig_data', '2026-09-25 23:37:26', 1, 'Supplier Representative', 'data:image/png;base64,supplier_sig_data', '2026-09-25 23:37:26', 'fully_signed', '2026-09-25 15:37:26');
INSERT INTO `purchase_contracts` (`id`, `contract_ref`, `requisition_id`, `rfq_id`, `bid_id`, `supplier_id`, `title`, `total_amount`, `delivery_terms`, `payment_terms`, `contract_terms`, `buyer_signed_by`, `buyer_signed_name`, `buyer_signed_title`, `buyer_signature`, `buyer_signed_at`, `supplier_signed_by`, `supplier_signed_name`, `supplier_signature`, `supplier_signed_at`, `status`, `created_at`) VALUES
(23, 'KM-CNT-2026-0059', 59, 39, 49, 1, 'Supply Agreement: Testing Over The Budget — Selecta', 64.00, 'Delivery within 3 day(s) upon receipt of Purchase Order to Kofee Manila Commissary', 'Net 30 Days upon complete goods receipt and three-way invoice matching', '1. Quality Standards: All items delivered must strictly comply with agreed food safety and freshness standards. Defective or damaged items will be rejected upon delivery.\r\n2. Advance Shipping Notice: The supplier must provide an ASN with tracking details prior to delivery arrival.\r\n3. Three-Way Matching: Invoices will be matched against the Purchase Order and actual received Goods Receipt before payment scheduling.\r\n4. Binding Agreement: This agreement becomes legally binding once countersigned by both Buyer and Supplier authorized representatives.\r\n5. Contract Price and Taxes. The Total Contract Obligation is ₱10,000.00, VAT-inclusive. This amount includes all applicable taxes, duties, charges, delivery costs, and other costs necessary to complete delivery of the agreed items, unless otherwise stated in this Contract. The Supplier shall issue a valid invoice reflecting the applicable tax treatment.', 1, 'Admin User', 'Procurement Director', 'data:image/png;base64,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', '2026-09-26 00:44:20', NULL, NULL, NULL, NULL, 'sent_to_supplier', '2026-09-25 16:44:20');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_contract_items`
--

CREATE TABLE `purchase_contract_items` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `requisition_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(20) DEFAULT 'pcs',
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_contract_items`
--

INSERT INTO `purchase_contract_items` (`id`, `contract_id`, `requisition_item_id`, `item_name`, `quantity`, `unit`, `unit_price`, `line_total`) VALUES
(1, 5, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(2, 6, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(3, 7, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(4, 8, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(5, 9, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(6, 10, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(7, 11, 40, 'Premium Arabica Whole Beans', 10.00, 'kg', 75.00, 750.00),
(8, 12, 40, 'Premium Arabica Whole Beans', 10.00, 'kg', 75.00, 750.00),
(12, 16, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(15, 19, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(18, 22, NULL, 'Premium Arabica Whole Beans', 20.00, 'kg', 575.00, 11500.00),
(19, 23, 56, 'Arabica Beans', 2.00, 'g', 32.00, 64.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL,
  `rfq_id` int(11) DEFAULT NULL,
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
  `paid_at` datetime DEFAULT NULL,
  `po_number` varchar(60) DEFAULT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `issue_status` enum('none','open','resolved') DEFAULT 'none',
  `issue_notes` text DEFAULT NULL,
  `issue_raised_at` datetime DEFAULT NULL,
  `issue_resolved_at` datetime DEFAULT NULL,
  `issue_resolution_notes` text DEFAULT NULL,
  `issue_resolved_by` int(11) DEFAULT NULL,
  `fulfillment_status` enum('preparing','partially_shipped','shipped','delivered') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`id`, `rfq_id`, `supplier_id`, `requisition_id`, `total_amount`, `status`, `negotiation_notes`, `created_by`, `created_at`, `expected_delivery_date`, `delivered_at`, `acknowledged_at`, `shipped_at`, `shipping_notes`, `closed_at`, `supplier_rating`, `invoice_number`, `paid_at`, `po_number`, `contract_id`, `issue_status`, `issue_notes`, `issue_raised_at`, `issue_resolved_at`, `issue_resolution_notes`, `issue_resolved_by`, `fulfillment_status`) VALUES
(1, 1, 1, 1, 2232.00, 'closed', 'Order Close', 1, '2026-08-24 19:57:59', NULL, '2026-08-25 03:58:26', NULL, NULL, NULL, '2026-08-25 04:00:10', 3, '#0001', '2026-08-25 04:00:05', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 4, 1, 5, 0.01, 'closed', 'xs', 1, '2026-08-25 07:15:58', NULL, '2026-08-25 15:16:05', NULL, NULL, NULL, '2026-08-25 15:16:34', 5, '#0001', '2026-08-25 15:16:21', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 5, 1, 7, 2333.01, 'delivered', '', 1, '2026-08-31 20:13:06', NULL, '2026-09-01 04:13:49', NULL, NULL, NULL, NULL, NULL, '56256', '2026-09-01 04:16:47', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 3, 1, 5, 112001.00, 'closed', '', 1, '2026-08-31 20:20:11', NULL, '2026-09-01 04:31:19', '2026-09-01 04:29:45', '2026-09-01 04:29:53', 'Track', '2026-09-01 05:54:11', 5, '56256', '2026-09-01 04:35:26', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 6, 1, 6, 3233.00, 'delivered', '', 11, '2026-08-31 20:51:27', NULL, '2026-09-01 10:20:28', '2026-09-01 04:52:05', '2026-09-01 10:16:21', 'LBC', NULL, NULL, NULL, NULL, NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 7, 1, 9, 2456.00, 'closed', '', 11, '2026-08-31 22:14:04', NULL, '2026-09-01 06:18:49', '2026-09-01 06:14:20', '2026-09-01 06:14:29', '3323', '2026-09-01 06:23:19', 5, NULL, '2026-09-01 06:23:03', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 9, 1, 10, 20.00, 'delivered', 'okiii', 1, '2026-09-01 02:31:39', NULL, '2026-09-01 10:50:55', '2026-09-01 10:32:11', '2026-09-01 10:35:15', 'LBC', NULL, NULL, NULL, NULL, NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 10, 1, 11, 9999999999.99, 'closed', 'Done', 11, '2026-09-01 03:29:15', NULL, '2026-09-01 11:34:15', '2026-09-01 11:33:00', '2026-09-01 11:33:44', 'LBC', '2026-09-01 11:38:44', 5, NULL, '2026-09-01 11:37:40', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 8, 1, 8, 1000.00, 'closed', 'thank you!', 6, '2026-09-01 03:43:55', NULL, '2026-09-01 11:44:05', NULL, NULL, NULL, '2026-09-01 11:54:58', 5, NULL, '2026-09-01 11:53:54', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 11, 1, 12, 1.00, 'delivered', 'Agree', 11, '2026-09-01 03:48:44', NULL, '2026-09-26 01:52:08', '2026-09-01 11:49:11', '2026-09-01 11:49:27', 'LBC', NULL, NULL, NULL, NULL, NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, 'delivered'),
(12, 12, 1, 13, 1.00, 'closed', 'Agree', 11, '2026-09-01 04:12:17', NULL, '2026-09-01 12:13:31', '2026-09-01 12:12:34', '2026-09-01 12:12:47', 'LBC', '2026-09-01 12:16:46', 5, NULL, '2026-09-01 12:16:19', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 13, 1, 14, 1.00, 'closed', 'g', 11, '2026-09-07 15:34:58', NULL, '2026-09-07 23:36:59', '2026-09-07 23:35:11', '2026-09-07 23:35:19', 'LBCZ', '2026-09-07 23:43:36', 5, NULL, '2026-09-07 23:43:18', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 14, 1, 15, 500.00, 'closed', '', 11, '2026-09-10 19:17:02', NULL, '2026-09-11 03:18:06', '2026-09-11 03:17:15', '2026-09-11 03:17:24', 'LBCZ', '2026-09-11 03:19:50', 5, NULL, '2026-09-11 03:19:11', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(15, 15, 1, 16, 150.00, 'closed', '', 11, '2026-09-14 19:24:52', NULL, '2026-09-15 03:25:36', '2026-09-15 03:25:05', '2026-09-15 03:25:12', 'LBC', '2026-09-15 03:27:32', 5, NULL, '2026-09-15 03:26:42', NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(21, 24, 1, 31, 11500.00, 'closed', NULL, 1, '2026-09-25 10:22:11', NULL, '2026-09-25 18:22:11', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 18:22:11', 'PO-TEST-1790331731', 6, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(22, 26, 1, 34, 11500.00, 'closed', NULL, 1, '2026-09-25 10:48:34', NULL, '2026-09-25 18:48:34', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 18:48:35', 'PO-TEST-1790333314', 7, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(23, 28, 1, 36, 11500.00, 'closed', NULL, 1, '2026-09-25 11:05:54', NULL, '2026-09-25 19:05:54', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 19:05:54', 'PO-TEST-1790334354', 8, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(24, 31, 1, 38, 11500.00, 'closed', NULL, 1, '2026-09-25 14:13:48', NULL, '2026-09-25 22:13:48', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 22:13:48', 'PO-TEST-1790345628', 9, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(25, 34, 1, 41, 11500.00, 'closed', NULL, 1, '2026-09-25 14:39:48', NULL, '2026-09-25 22:39:48', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 22:39:48', 'PO-TEST-1790347188', 10, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(29, 35, 1, 46, 11500.00, 'closed', NULL, 1, '2026-09-25 14:57:49', NULL, '2026-09-25 22:57:49', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 22:57:49', 'PO-TEST-1790348269', 16, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(33, 36, 1, 49, 11500.00, 'closed', NULL, 1, '2026-09-25 15:10:37', NULL, '2026-09-25 23:10:37', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 23:10:37', 'PO-TEST-1790349037', 19, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(34, 33, 1, 40, 750.00, 'delivered', NULL, 1, '2026-09-25 15:15:35', '2026-10-02', '2026-09-26 01:52:08', '2026-09-25 23:16:27', '2026-09-25 23:17:05', 'Carrier: JT (Trk: 213123)', NULL, NULL, NULL, '2026-09-26 01:57:04', 'KM-PO-2026-0040', 11, 'none', NULL, NULL, NULL, NULL, NULL, 'delivered'),
(35, NULL, 3, 50, 15000.00, 'delivered', NULL, 1, '2026-09-25 15:35:41', NULL, '2026-09-25 23:35:41', '2026-09-25 23:35:41', '2026-09-25 23:35:41', 'Carrier: LBC Express Express Freight (Trk: TRK-ASN-107956) — 3 bags safely sealed on pallet', NULL, NULL, NULL, NULL, 'KM-PO-ASN-1790350541', NULL, 'none', NULL, NULL, NULL, NULL, NULL, 'delivered'),
(40, 37, 2, 54, 11500.00, 'closed', NULL, 1, '2026-09-25 15:37:26', NULL, '2026-09-25 23:37:26', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-25 23:37:26', 'PO-TEST-1790350646', 22, 'none', NULL, NULL, NULL, NULL, NULL, NULL);

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
  `supplier_id` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `source` varchar(40) NOT NULL DEFAULT 'manual',
  `source_ingredient_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_requisitions`
--

INSERT INTO `purchase_requisitions` (`id`, `requested_by`, `department`, `title`, `notes`, `estimated_total`, `status`, `reviewed_by`, `supplier_id`, `reviewed_at`, `review_notes`, `created_at`, `source`, `source_ingredient_id`) VALUES
(1, 1, 'manager', 'Inventory', 'Low Stock', 0.00, 'awarded', 1, NULL, '2026-08-25 03:39:42', '', '2026-08-23 20:34:31', 'manual', NULL),
(3, 1, 'hr', 'Food', '', 160.00, 'approved', 1, NULL, '2026-08-25 04:27:55', '', '2026-08-24 20:21:46', 'manual', NULL),
(4, 1, 'crew', 'Testing 2', 'sada', 529.00, 'approved', 1, NULL, '2026-08-25 13:40:52', '', '2026-08-25 05:40:36', 'manual', NULL),
(5, 1, 'manager', 'restock', '', 11200.00, 'closed', 1, NULL, '2026-08-25 15:14:46', '', '2026-08-25 07:11:50', 'manual', NULL),
(6, 1, 'crew', 'adasd', '', 53429.00, 'awarded', 1, NULL, '2026-09-01 02:15:16', '', '2026-08-31 18:12:39', 'manual', NULL),
(7, 1, 'crew', 'Testomg', '', 46000.00, 'awarded', 1, NULL, '2026-09-01 02:15:11', '', '2026-08-31 18:14:05', 'manual', NULL),
(8, 1, 'crew', 'asda', 'sada', 15565641.00, 'closed', 1, NULL, '2026-09-01 04:18:04', '', '2026-08-31 19:11:46', 'manual', NULL),
(9, 1, 'manager', 'Coffee Beans', '', 2454.00, 'closed', 1, NULL, '2026-09-01 06:12:55', '', '2026-08-31 22:12:35', 'manual', NULL),
(10, 1, 'manager', 'Iphone 17 pro', 'For documentations', 400.00, 'awarded', 1, NULL, '2026-09-01 10:30:01', 'okay noted', '2026-09-01 02:26:53', 'manual', NULL),
(11, 8, 'crew', 'Kahit ano basta', 'Pls', 50.00, 'closed', 7, NULL, '2026-09-01 11:26:24', 'okay lang yan', '2026-09-01 03:25:21', 'manual', NULL),
(12, 8, 'crew', 'Increase of stock', 'Badly needed, ASAP', 500.00, 'awarded', 7, NULL, '2026-09-01 11:46:21', 'Okay lang asap daw e', '2026-09-01 03:45:12', 'manual', NULL),
(13, 8, 'crew', 'Stock increase', '', 500.00, 'closed', 7, NULL, '2026-09-01 12:11:24', '', '2026-09-01 04:09:17', 'manual', NULL),
(14, 8, 'crew', 'Iphone 15', 'Please', 100000.00, 'closed', 11, NULL, '2026-09-07 23:28:41', 'Approved', '2026-09-07 15:26:55', 'manual', NULL),
(15, 8, 'crew', 'Ice request', '', 500.00, 'closed', 7, NULL, '2026-09-11 03:16:09', '', '2026-09-10 19:15:53', 'manual', NULL),
(16, 8, 'crew', 'Espresso Request', 'Pls be still', 150.00, 'closed', 7, NULL, '2026-09-15 03:24:09', '', '2026-09-14 19:23:49', 'manual', NULL),
(17, 1, 'Inventory', 'Auto-reorder — Brown Sugar', 'Generated automatically. Stock fell to 0 g (threshold 500 g).', 0.00, 'approved', 1, NULL, '2026-09-21 13:11:33', '', '2026-09-21 05:10:42', 'auto_reorder', 24),
(18, 1, 'crew', 'Test', '', 1.00, 'rejected', 1, 1, '2026-09-22 15:00:10', '', '2026-09-22 05:54:07', 'manual', NULL),
(19, 11, 'finance', 'Testing', '', 1000.00, 'sourcing', 11, 1, '2026-09-22 14:55:08', '', '2026-09-22 06:54:00', 'manual', NULL),
(20, 1, 'crew', 'Testing', 'sdaj', 1000.00, 'approved', 1, 1, '2026-09-23 06:46:14', '', '2026-09-22 22:42:43', 'manual', NULL),
(27, 1, 'crew', 'asdas', '', 2000.00, 'approved', 1, NULL, '2026-09-25 17:54:12', '', '2026-09-25 09:51:19', 'manual', NULL),
(28, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 18:13:14', 'Approved by manager', '2026-09-25 10:13:14', 'manual', NULL),
(29, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 18:14:03', 'Approved by manager', '2026-09-25 10:14:03', 'manual', NULL),
(30, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 18:20:56', 'Approved by manager', '2026-09-25 10:20:56', 'manual', NULL),
(31, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 18:22:11', 'Approved by manager', '2026-09-25 10:22:11', 'manual', NULL),
(32, 1, 'crew', 'Testing', '', 276.00, 'sourcing', 1, NULL, '2026-09-25 18:37:59', '', '2026-09-25 10:24:24', 'manual', NULL),
(33, 1, 'Barista Bar', 'Clean Requisition Test', NULL, 5000.00, 'sourcing', 1, NULL, '2026-09-25 18:48:25', 'Clean approval without letter', '2026-09-25 10:48:25', 'manual', NULL),
(34, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 18:48:34', 'Approved by manager', '2026-09-25 10:48:34', 'manual', NULL),
(35, 1, 'Barista Bar', 'Clean Requisition Test', NULL, 5000.00, 'sourcing', 1, NULL, '2026-09-25 19:04:50', 'Clean approval without letter', '2026-09-25 11:04:50', 'manual', NULL),
(36, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 19:05:54', 'Approved by manager', '2026-09-25 11:05:54', 'manual', NULL),
(37, 1, 'Kitchen', 'Espresso Machine Test', NULL, 45000.00, 'approved', NULL, NULL, NULL, NULL, '2026-09-25 14:13:42', 'manual', NULL),
(38, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 22:13:48', 'Approved by manager', '2026-09-25 14:13:48', 'manual', NULL),
(39, 1, 'manager', 'Testing', '', 400.00, 'sourcing', 1, NULL, '2026-09-25 22:23:30', '', '2026-09-25 14:23:18', 'manual', NULL),
(40, 1, 'crew', 'Testing1234', '', 750.00, 'sourcing', 1, NULL, '2026-09-25 22:31:54', '', '2026-09-25 14:31:36', 'manual', NULL),
(41, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 22:39:48', 'Approved by manager', '2026-09-25 14:39:48', 'manual', NULL),
(46, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 22:57:49', 'Approved by manager', '2026-09-25 14:57:49', 'manual', NULL),
(49, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 23:10:36', 'Approved by manager', '2026-09-25 15:10:36', 'manual', NULL),
(50, 1, 'Operations', 'ASN Test Coffee Shipment', NULL, 15000.00, 'approved', NULL, NULL, NULL, NULL, '2026-09-25 15:35:41', 'manual', NULL),
(54, 1, 'Barista Bar', 'Test Arabica Coffee Beans', 'Restock urgent needed', 12000.00, 'approved', 1, NULL, '2026-09-25 23:37:26', 'Approved by manager', '2026-09-25 15:37:26', 'manual', NULL),
(57, 1, 'Inventory', 'Auto-reorder — TEST_ING_1790353489', 'Generated automatically. Stock fell to 3 kg (threshold 10 kg). Estimated auto-reorder total: ₱3,637.50 (₱145.50 / kg).', 3637.50, 'sourcing', 1, NULL, '2026-09-26 00:31:44', '', '2026-09-25 16:24:49', 'auto_reorder', 58),
(59, 1, 'crew', 'Testing Over The Budget', '', 64.00, 'sourcing', 1, NULL, '2026-09-26 00:34:53', '', '2026-09-25 16:34:46', 'manual', NULL);

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
  `est_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `ingredient_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requisition_items`
--

INSERT INTO `requisition_items` (`id`, `requisition_id`, `item_name`, `quantity`, `unit`, `est_unit_price`, `ingredient_id`) VALUES
(1, 1, 'Chocolate', 0.25, 'liters', 0.00, NULL),
(3, 3, 'Strawberry', 32.00, 'pcs', 5.00, NULL),
(4, 4, 'Chocolate', 23.00, 'pcs', 23.00, NULL),
(5, 5, 'plastic cups', 56.00, 'bundle', 200.00, NULL),
(6, 6, 'trtr', 23.00, 'j', 2323.00, NULL),
(7, 7, 'Koya Dsd', 23.00, '12', 2000.00, NULL),
(8, 8, 'Koya Dsd', 23.00, '12', 676767.00, NULL),
(9, 9, 'Arabica Beans', 23.00, 'g', 45.00, NULL),
(10, 9, 'Excelsa Beans', 33.00, 'g', 43.00, NULL),
(11, 10, 'Arabica Beans', 20.00, 'g', 20.00, NULL),
(12, 11, 'Excelsa Beans', 10.00, 'g', 5.00, NULL),
(13, 12, 'Arabica Beans', 500.00, 'g', 1.00, NULL),
(14, 13, 'Excelsa Beans', 500.00, 'g', 1.00, NULL),
(15, 14, 'Robusta Beans', 100000.00, 'g', 1.00, NULL),
(16, 15, 'Ice', 500.00, '500', 1.00, NULL),
(17, 16, 'Espresso', 150.00, 'ml', 1.00, NULL),
(18, 17, 'Brown Sugar', 1000.00, 'g', 0.00, 24),
(19, 18, 'Caramel Syrup', 1.00, 'ml', 1.00, NULL),
(20, 19, 'Espresso', 10.00, 'ml', 100.00, NULL),
(21, 20, 'Caramel Syrup', 1.00, 'ml', 1000.00, NULL),
(27, 27, 'Ice', 2.00, 'g', 1000.00, NULL),
(28, 28, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(29, 29, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(30, 30, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(31, 31, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(32, 32, 'Cocoa Powder', 23.00, 'g', 12.00, NULL),
(33, 33, 'Filter Papers', 10.00, 'packs', 500.00, NULL),
(34, 34, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(35, 35, 'Filter Papers', 10.00, 'packs', 500.00, NULL),
(36, 36, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(37, 37, 'Commercial Espresso Unit', 1.00, 'unit', 45000.00, NULL),
(38, 38, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(39, 39, 'Ice Cream', 20.00, 'g', 20.00, NULL),
(40, 40, 'Premium Arabica Whole Beans', 10.00, 'kg', 75.00, 41),
(41, 41, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(45, 46, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(48, 49, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(49, 50, 'Arabica Special Reserve', 30.00, 'kg', 500.00, NULL),
(53, 54, 'Premium Arabica Whole Beans', 20.00, 'kg', 600.00, NULL),
(54, 57, 'TEST_ING_1790353489', 25.00, 'kg', 145.50, 58),
(56, 59, 'Arabica Beans', 2.00, 'g', 32.00, NULL);

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
(16, 9, 150.00, 13, '2026-09-15 03:25:36'),
(17, 41, 9.00, 1, '2026-09-25 23:18:11'),
(18, 41, 1.00, 1, '2026-09-25 23:18:29');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `title` varchar(255) DEFAULT NULL,
  `rfq_ref` varchar(60) DEFAULT NULL,
  `invitation_letter` text DEFAULT NULL,
  `buyer_signature` longtext DEFAULT NULL,
  `buyer_signed_by` int(11) DEFAULT NULL,
  `buyer_signed_at` datetime DEFAULT NULL,
  `terms_and_conditions` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rfqs`
--

INSERT INTO `rfqs` (`id`, `requisition_id`, `created_by`, `status`, `due_date`, `created_at`, `title`, `rfq_ref`, `invitation_letter`, `buyer_signature`, `buyer_signed_by`, `buyer_signed_at`, `terms_and_conditions`) VALUES
(1, 1, 1, 'awarded', '2026-08-29', '2026-08-24 19:46:50', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 5, 1, 'awarded', '2026-08-12', '2026-08-25 07:15:00', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 5, 1, 'awarded', '2026-08-12', '2026-08-25 07:15:17', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 7, 1, 'awarded', '2026-09-01', '2026-08-31 19:54:27', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 6, 1, 'awarded', '2026-09-03', '2026-08-31 20:50:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(7, 9, 1, 'awarded', NULL, '2026-08-31 22:12:59', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 8, 1, 'awarded', '2026-09-01', '2026-09-01 02:21:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 10, 1, 'awarded', '2026-09-01', '2026-09-01 02:30:57', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 11, 11, 'awarded', '2026-09-05', '2026-09-01 03:28:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 12, 11, 'awarded', '2026-09-01', '2026-09-01 03:47:12', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 13, 11, 'awarded', '2026-09-01', '2026-09-01 04:11:57', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(13, 14, 11, 'awarded', '2026-09-07', '2026-09-07 15:34:42', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(14, 15, 11, 'awarded', '2026-09-11', '2026-09-10 19:16:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(15, 16, 11, 'awarded', '2026-09-15', '2026-09-14 19:24:39', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 19, 1, 'open', '2026-09-23', '2026-09-22 22:16:35', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 29, 1, 'open', '2026-10-02', '2026-09-25 10:14:03', 'RFQ - Arabica Beans', 'RFQ-TEST-1790331243', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(23, 30, 1, 'open', '2026-10-02', '2026-09-25 10:20:56', 'RFQ - Arabica Beans', 'RFQ-TEST-1790331656', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(24, 31, 1, 'open', '2026-10-02', '2026-09-25 10:22:11', 'RFQ - Arabica Beans', 'RFQ-TEST-1790331731', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(25, 33, 1, 'open', '2026-09-23', '2026-09-25 10:48:25', 'RFQ - Filter Papers', 'KM-RFQ-2026-0033', 'Official Invitation to Bid for Filter Papers to Kofee Manila Commissary', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 1, '2026-09-25 18:48:25', 'Net 30 payment terms'),
(26, 34, 1, 'open', '2026-10-02', '2026-09-25 10:48:34', 'RFQ - Arabica Beans', 'RFQ-TEST-1790333314', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(27, 35, 1, 'open', '2026-09-23', '2026-09-25 11:04:50', 'RFQ - Filter Papers', 'KM-RFQ-2026-0035', 'Official Invitation to Bid for Filter Papers to Kofee Manila Commissary', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 1, '2026-09-25 19:04:50', 'Net 30 payment terms'),
(28, 36, 1, 'open', '2026-10-02', '2026-09-25 11:05:54', 'RFQ - Arabica Beans', 'RFQ-TEST-1790334354', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(29, 32, 1, 'open', '2026-10-02', '2026-09-25 11:05:56', 'RFQ - Testing', 'KM-RFQ-2026-0032', '', 'data:image/png;base64,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', 1, '2026-09-25 19:05:56', '1. Prices must be quoted in Philippine Peso (PHP) inclusive of all applicable taxes.\r\n2. Quoted lead time and delivery schedule must be strictly observed.\r\n3. Payment Terms: Net 30 Days upon complete goods receipt, inspection, and 3-way invoice reconciliation.\r\n4. Supplier must provide batch and expiry dates for perishable ingredients upon dispatch.'),
(30, 37, 1, 'open', '2026-09-30', '2026-09-25 14:13:42', 'Espresso Machine RFQ', 'KM-RFQ-TEST-FIN', NULL, NULL, NULL, NULL, NULL),
(31, 38, 1, 'open', '2026-10-02', '2026-09-25 14:13:48', 'RFQ - Arabica Beans', 'RFQ-TEST-1790345628', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days');
INSERT INTO `rfqs` (`id`, `requisition_id`, `created_by`, `status`, `due_date`, `created_at`, `title`, `rfq_ref`, `invitation_letter`, `buyer_signature`, `buyer_signed_by`, `buyer_signed_at`, `terms_and_conditions`) VALUES
(32, 39, 1, 'awarded', '2026-10-02', '2026-09-25 14:23:57', 'RFQ - Testing', 'KM-RFQ-2026-0039', '', 'data:image/png;base64,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', 1, '2026-09-25 22:23:57', '1. Prices must be quoted in Philippine Peso (PHP) inclusive of all applicable taxes.\r\n2. Quoted lead time and delivery schedule must be strictly observed.\r\n3. Payment Terms: Net 30 Days upon complete goods receipt, inspection, and 3-way invoice reconciliation.\r\n4. Supplier must provide batch and expiry dates for perishable ingredients upon dispatch.'),
(33, 40, 1, 'awarded', '2026-09-26', '2026-09-25 14:32:24', 'RFQ - Testing1234', 'KM-RFQ-2026-0040', '', 'data:image/png;base64,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', 1, '2026-09-25 22:32:24', '1. Prices must be quoted in Philippine Peso (PHP) inclusive of all applicable taxes.\r\n2. Quoted lead time and delivery schedule must be strictly observed.\r\n3. Payment Terms: Net 30 Days upon complete goods receipt, inspection, and 3-way invoice reconciliation.\r\n4. Supplier must provide batch and expiry dates for perishable ingredients upon dispatch.'),
(34, 41, 1, 'open', '2026-10-02', '2026-09-25 14:39:48', 'RFQ - Arabica Beans', 'RFQ-TEST-1790347188', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(35, 46, 1, 'open', '2026-10-02', '2026-09-25 14:57:49', 'RFQ - Arabica Beans', 'RFQ-TEST-1790348269', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(36, 49, 1, 'open', '2026-10-02', '2026-09-25 15:10:36', 'RFQ - Arabica Beans', 'RFQ-TEST-1790349036', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(37, 54, 1, 'open', '2026-10-02', '2026-09-25 15:37:26', 'RFQ - Arabica Beans', 'RFQ-TEST-1790350646', NULL, 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', NULL, NULL, 'Net 30 days'),
(38, 57, 1, 'awarded', '2026-09-27', '2026-09-25 16:32:13', 'RFQ - Auto-reorder — TEST_ING_1790353489', 'KM-RFQ-2026-0057', '', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAfQAAAB4CAYAAAAE0wCdAAAQAElEQVR4AeydW4wc2VmAz3+6Z8aX9Xo33vVM96zNerq9ju2ZnvVuCBEIiERA8ICIEApCAqE8QEKA8IDEQ/KQAEpeQLxwCRCUB0BIIZECvAAhiBAiNkD2Mt3tS9bV443X7p712tmbbzPTVYf/VHdVV4+rL1V1qvpU1d/T1XXqXP7z/9+5/HWqums4o1ciBCCRWqgSIkAEiAARyCsBDR16Nl2fyGsPI7uJABEgAnkgoIHritehh2pEcn2hsMVYSIN+GqN1JJoI6EKARpouLeHqEaRJYnZd06iioUN3UaY8MA1+BSYmUE3M/VQBBBIxSwIJdMFZmpdg3TTSEoQ9XVUaNck0qmjv0MdMFtM1yMxyTYNfgXIJVaNAUxKRUQLUBTPasLqbFcU5RCmrMRftHTpNFhr3ntyqFnw2CF5CBdzZ1KpCc5IxDQGf9n0g6oGIaQS7eaKVdsUMBZTJjOIcopQdskavA+0d+sxwUcVEYCSB4LNB8BIjKw+QMJtaAyhIWSMR8GnfB6IeiAhUY7TS/lXFIdO/Jn1ilZ3ETDCJHPoEQJRMBIgAESACRCAKgdEnMWpdPTn0KK3klA3eJk5J2ueQAHWXHDZ6YJMz3ksybt70zT3a1U8vY5CTHPqARfiQ2jYJr0faS2ZikE82grpL2jtqEvpnvJdk3LwkeohfHeTQ/aikPS6t+mdikGfCiOl70OTzl+llUU4iQAQiEci8Q6f5JlL/iKkwtUpMYFHsNGynycPYVLkCnr9MJZPl6UVE8tTacduaeYcecL6Jm3cW5CuwgVpFAcQRIqZhO00exqbLNUKNEdFxyBxRVUqiiUhKGioVambeoaeiFUhJIkAEiAARIAIRCZBDjwiQiismMFEcXaKciIgyEIFxBGgIjaOT6jRy6KluvniV13Pc0yXKeFs9PdL17J8p4EdDKHwjad7pyKGHb9peSc0buKdkuM8MjvtwIKiUlgSy3D8zPK1o2ZemVkrzTpcthx5qFIQqNGh/zRt4oCiFiAARSAsBmlbS0lJ66Zkthx5qFIQqpFcrkjZ6ECAtiAARIAIzJJAthz5DkFQ1ESACRIAIEIFZEiCHHhP9iBfyY9KKxIYl8OSR1e0TpTNmtbRqVcs1q1JaE5WlNSusvBDlqAgRyDQBmjOjNy859OgMfSXQhXxfLLFEqpwIlpdX71WW0GGX19Bxo9Mu1QQ6cFFc4PMFKHIGXFYH+GIYhCNHjlzwNUrm8k2gSCJABPwI0JzpR8UbN2FSwWTuzU5hIpBGAlEmgifLZ82VpVWrUpaOe03sF3wfcAYg36z3OY7JrVu3zvimR1HKV2DMkSQ+RwQgR7am3NShppowqWAyH2/ukLTxWSekqpM0oSJKJgJjCKwcXe9Wymv2qtteebMCxxc6cFnIr5fiKJFvgR8yS3+TR+YO2+kf0o4IpIiA7L0pUjeCqn4jOoK4EEUBlwUhijlFAjbVBIceUJqjhM9enSQf4aOiYFQCxeeNwMnl9R3pwHlRFGDUEMNOim9mCfTeO8I02nUw2g0wOnV0+OAik3lEF8wrN+sLbiQFxhFIfdqg9VNvSq4MkGN1tgaLWP4nwiibJjh0LJbmnjz71kSA9J4FgePvOrOzUl41K6VVs1paQxct5kbpYXcTHHe7XPx+C534ZqfBjZuNopO/iit69xwAM3OA3c0bG266k4/22SWAzZ5d43JmWZpd2qSmmuzQqSdPYkjpCRM4duDpuyvlmoUrbvved6Us738Pb/P7inOccfS9nDNcX49T0R7gwKDYZZ/05qs8ttqtlGo4AqAfLdgdtrt9+frGfD+CdnsJOKj2xqs69pOvSjbJyQUBHNCZtZNn1jIybDKBFEyOx5dX71XlT8VK6MBL6/a974VHrP3YcaX2tquWgcnGTs4heE/SkSNHmhVc1cM8L9gVYFFc4rNb9+Y2O52L+/CQ3qMIqJwte80xXJNK+X3JftX0k2hHBFJFAOfFVOmrlbKpnwhimByjNNDJxZP3q0v2N85x9d374tq84PsYoKcFhn8TFB6RjNFi535393Bpbt7AS+rOxt+5f8ur7za3dlZKZ81HF5bPAoAnSbBWpwFvvPFCxRP5QNBb4oFEighOABsueKHgJSZUE1zg2BLUS8biyWxiMu1ODj1CB0p2IoigqIZFVxZXu/KSeX+zV96isH+BcS5dKfZ+fI/QW3LHW96iK3pfXNt+k99j8qsne4rIVbV02nhfnF/93oX5559/ftcr0jq074h7jEL3dcU8h4JnTAhmWZYw2o09kt1SQwEUMXR8YvHMbrW0ZlXx6sLKwZVXhxLpIKcE9vaSjGOYauRknIFtXjLt7pm87FrpgwgoJXBi8TSuetfkvW5cdQ/uc/MCL2BFcrjLDYOj3vZAEGLH7H/rvA7ooHFr8Fc6jSLeP7fkJXiGC3jmeW3f29mVq+qX33n5MU+0G8QTCVuwE2EJJqAwcObyZOCN7fb5za1m6DFSKBSLDAAY/vHDB5+Qujr1ZWIPyViRUDXJGLO3lqDHaYMxNMqCGkv5gxKYPFkl0oESqSQoG8ofgMCJxRN3cCVqoqMcctyFwtwcrrnRq6Fbm0YeOlbWBdd5y9Wx0a7z1s3zRW/x7zu6vot1Cew5+B6k4PyBK+o6vPrGpflB7HAIy+19ZKvgfKCfsISQJwO3bt1aHS4Z8EigNm4RkBWA/Y15Ny7lAa95MZqirhqIUcuERKuDkZDCVE1SBGTv5hMrS6QDJVLJRFO1yiBbRyuFeso888wzH1pZXDXlahMdo3C2QuHQAfRYsj+N13zQ1Oi6LQEAu3LFjU4b7K1T58aEn4ThiYM1VxRDDh61sx05ypI64KH/W+qNKa6OciU+dMyE1dpqjJWB+ad6D0zF7O4BMNRBVJZqJsbSO1ECbiMkWusMK6Oqc0RA9m4lE1eOmCVnqmyd5Grzram6iPe5l/AecLlmSScknffbW90v4uVyjh4R377FRkSiQQJ25ENabMeNq26j0+TyJ2CYMqLMcPTxo++2V+V44jBUt8l25Yp+Yl+W97OxIL4duYLhCYVzwIR05u2GvBXgxkUJAINecSF3lv0pQzIWOJuor8xL26wIyFaaVd1ULxEIR4AmlXDcZlaqWl615G+j5c+q4tik03Y2VuAFxkHObPbHRKNxuWuCZc0/0l2QTpsx7zVnFANi/kTIlenZx89W54vzw6tyi9mr8ivti8PxPopWSusWA0AlvImeQ8Gs1gRnjlyQ/ZoYy32pn+59GI2sBrj8HFSOrAYHFNKPgHv+pZ9qumhEemhHgGunESk0gQAHQNcAALZ/AlC7n1C5nYxuWr6tO7d3b0vH7W6dBr9yvVm4cOHCyGecF3BlipfsLVtQgI/tYuHyILtg22/ze8ZWfar+Kx0xgIBB+UHI9qumZeKVg8IgdhB68nH5pLmafWsBYwFf47lz6KUzwOzDb7suvD9vvXX7ioGshlPpiAgQAV0JPDia9dR0qglRT9VzqhW60sQsF0yg5xXb1va267TbdWh16lyuZjtvXzw0Thcx5NQGiuMle1gp1VD0uNKDNLzcb3lFGe0GvHr7pQODHKND0plj6ojxKBjbFabxWtN3hX9i8axZnOPcWzfKCvMW3S5eAeg0wMD785t3NlfCCKEyRCBHBLQyFWcKrfQZpQwflUDxehLAlV3vy2Ptevz7Tp1v4r3uV7e+sy8MDdix7g7KATMtZjG8Uy3j5EJWOuqTy2uGPB61rRytmeiN8d3PIVi3H5q4q5TX5BfPBmX3lhDQbd30d+Yya6HA3fHROx0RltEezR3L+Ix7IW8L8Fdu1H2vAGAZemeUwOiOl1GDyayZE3AnrJlrQgpkjoBxs/mQxyi4soVOTXD3cjxOeCAEVKp4v7laqomV0uqQs5bPbOdF5vZRYVqW0anPeWT6Bp9aevrTUiYwcMtixiFnK//16ThZ8gt0zFma45UKvCqBJ1APfmFOPllOnpjglQApH9iel9FW8435PWLpMAUEZIdIgZr5VDGjVnsnvIyamC2zHvAYKTPP6GwsmALedlbqPfXRKnxz4AXpGKUzXinXzIVHrP29dJlbiNZrzYmr3JXSmmlx61PMccYMX/LmtRhECNO0rtysL2CK7/vEY7U3GAAwYPb1BHT89jg5fvDc907ILyXKE5By7746hwJebJA5HVFCBsT9u9u7Bq7m5QFtRCBtBLDrp01l0hcJ2BMV7jP+zk73tN1FilrLq+/p0ummVP1KZ+OwgffBd4rmPzLLwvWvzCU3mSo3YNgx8c3cl8Bs1cVzb7oRewLV8uoX5MkABxgq1xW7eP6Ast0uIFjrtfNjTwwKc+ywLR6LyX2178DnD5uPFhjWwFxhMtnd0BS8vI73yfE2xbU3vzPywTZuAQoQAU0J9Lu+ptppr9bMFOQzqznRipPonv6TfKJmalgZOmIX/rYonPGqePXq+Q8aW01udKQTbIB9g1242b1ZGeccWME8jJe3hXSwuLdWllbvM3z1Lo/zD2Nw8MbTBANXyNY2WIBOmPVfr98R3+0HH9gtL6+9XSmtWeiv3cbsBXqfwwWwAozAT2Hu9u6tb27R5XVEkt+3XzfJL40IlhPIsPBy4tDD4glSzt8R9STkt4MeuC++3GPAGEinzEa/5BfwpHO3ele6fTP2SAL6XCmOL8hVOQMAb2bRtX+GZvft+X3FwT1307Leeqv5pJO38lCls7K4auEJgZBy9gs4BPhy0of22Ly9K/cMfTjrGniFoYUnDK12g195/cF760Nlc3Qw1BAeu0fFe7KkP4h9JP1GJGjByE5BIMe2wphEe9Ibk05JSgjkt4M232x+CF1gIIrYKd2hfrv/kzl08vhmI9bvw+KhCAVcwQu5eVMEB44rcGE78FJNwMMHl+RP6PCEwJttEJbNZmGd3Ts7RqcOrU4DWu06XlGY/MW8gZB8hSQyP4tHxfvlpbicEKBOobChe1Mmzp0KZZIoIuBDQPT6GpM7uRJ+8mhN/pzMJydj1cXhb7pv9X8yh6v3gmV2TSnDt+BQJNh1wVAcY3LxLTcM4AEb+8K5RkgnbmzVeetGa+QX6MYKoUQiQASIQCIEcMbCehQ4dJRCbyIwhoC5a8nVtZujWGRcrpJr76r9nRvpBArc/cKaxUzb8S8vr96TJwK8gCWdfM5eyMvfdRBduI1R/RV8r3Pj8cQ35hRMXkv35JQRciXO9p4RePJQkAgQgSwS8Bn0dpT9ob3BER26bkbqpo/27T+dglNj9c/4yuvNwj6wmkOX3gHY3X3sFyqLZ22n7adIt2uK6tKatV/woQfboBNmXbxRbuA9bFxFz8myrRsbh/CYoyOWvxcHyyr4PrBG+m68lX6Pm/c7qI+8eAAMAKQMueGph2i1+19ukxXJSNqIABHICQGfQW9H2R/aM4jo0OM3MhhB3fQJpr22uafGOjpj83pzDZ0vMGENZYJCgcvV90qpZq0cXR96sIz9z1j4wNmiA5a/ckOHW4dXLicSrgAAEABJREFUOhfclbwft82tF0+igweL7b7sTQcAVuB8v1XYV2KAf8zzsoTYxEvsnhh9ghCfKjGKjk9pkhyeADV4eHaal4zo0DW3LuPqpXFcyn+Zepcd+BPWvzjuNBEHBrwoxjhpYd/T3gz407DN9sVTTOziJf/hEwmnXmdvyNV+QNlO2UT2Q6dBamuMUbRaRUmaGgLU4Go4aigl5w5dwxYJoFJax2W7/a3fNDoNvDRexxW7XHdPMlre1WZQKa2JaTe56pePk5V7BnPYzzmMq0X+tn1lad19LO24vJRGBIgAEdCRAE50UdUaO09GFU7lM07A6NS5XB3jyYm8vT3CWmAAwTZbENifQx/y1KBo7XTminP/O/xzd2Ccizl5AlBZWvsPRq+ZEfBptpnpQhUTgTQRUODQcSpO1OL0DPdEsaS8ss12nbc6dXvVfvA+fMUy8RK5gq4lReCGF/gt6x7c+kv5hbdLW5fKF68+/wNGuwHSsWPiED3g8P6VpVW8TD8UTQcJEcD2SqgmqoYIZIuAAoeeNBAa7kkTT6I+b6tufG/jZzdfk4+E7Tl4ox1+38KyuPFWu1m4fv36R/baIh27PJHY3d19zpvGOQf507pTy6e+6I2nMBEgAjERoLVaZLApdOiRbc6IgD1m0GDYAyTY4Xdfv/iD8sRBMOi6KPEyvykWPoT37fes1t0cwSqh3PoQoCbUpy0cTYQToH1YAjlw6DkZuTQYwo6BoXKt9sbcArN+x47sdx3Al3yM7JknzvyNHc9mBLuvT0+HqJ9KhUVVJvnyM2rC5A2lGvNEIAcOXZeRm64JNE+DYK+tzXbzDwy8VC8EuL+Ll623YxV/cWVxdeSDcPbKUX6spCtLS6RmSoRJQYlsjtaJVEaVEIGUEsiBQ9elZdI1gSZCTfNZuoWrdbZ997Peb8PzAufyJ27Hj6//eSKMlFeSzn6YTq2VN15uBGo+NWjbDrlw6NQ5Ava/2IH19ZnRLB3EPOOW8Umj3QAmhGdlDmy+Kz4y09V6HyHtNCAQpENpoG4aVJjR1JAGNGN15GNTM5JInSNgQ2YcWBjzjE6jKFfrwlPYXq2XaqJ6pPqZgIQpe5YIePpEkmbReUSStNNRVy4cejqagrRURUClHO+kKVfr8idupv1E+f4sLjMsHPhEpby+q7JekqWIgGyfPaJ8ovbkmHAYWcAE+VMm93vglLkpWx4IkEPPQyuTjaEJ+E2aV9rNwu4c/wtvGl6TL1aXVrdDV0QF4yHgbaR+DT5R/ZQpd5EFTFlPxrJpch6kLVUVfMiha9u8pJieBHpaXb268dFWuy7H4OA36pzPV468L+LldymyVwd9EoEsEaDzoPGtqYIPOfTxjCl1SgJK3ZBSYVMaEDKb0a4XcCDiuy9g4e4n+qGQu4GokAKoGBHIMYEUTR4qW6lvdj4det94lTyzISs8GKVuSKmw+FsGV+qHnVokwUo5/HPgHTm0JwJEIAwBHScPOSuEsSVAmb7Z+XTofeMD4MpJVgITrKHdgfrOXHH+353fqwMDOH38PV8LJotyEwEioC0BiKJZcvNqPh16lLaJVDZSr4hQc5R6o5SNoHIqig4G6sWr3/4AE/hn6w1st7vzYxg8hNts3r7NNhtV0l0rgUx3+ynSfjDUFQmMR0wqHXp6h9isekWUeqOUjafT6irV6DS449KljpVy7S25V7tN2fup2RRhJ5CKQJKYKASmHPZKHfqUdUYxyy6rZIgFVTZofltT+sgbAbFz4LOi//QZ7DLw7vLad9UyUNL71arUl0Y7IkAEYiIw5bBX6tCnrDOgxTgtBiwxVfagygbNP5USlEklgZh6CmMBBLdufeuTIMQO67+6Ao6fO3fuA/1DLXcBzBurvyo5YyuhxJwRoF4VpMGVOvQgFU+flzzp9KzynTO2nhJQsLHVXGD9Vbo8GXhnq/tvOrdMQPNGmqJKjn8FFJtPAvnsVWFPY1Lg0PPZjcnqdBM4tFT8cfd+OgCjp8iluz1JeyKQJIGwpzHk0JNsJaorNwRefPHFrxVBXHUMFgAKniLnSKO9agIkjwhkgQA59Cy0ItmgJYFL7cb34Zk2vhkDwItokZ8ix+ilhAC2hRI5JCS9BLLZB8ihp7dHhtc8m305PI8YS9JT5GKEG1q0fY4VunTwglRCPwKyD2RvIiSHrl9Pi18j2ZdD1qJmCKiREtKEpIvRU+R8ieeqD/gSoMhZE4gwEc5a9RH1k0MfAYai/QmoGQJqpPhr6B87S/chnyInPF97n/lT5PwRJRybfB9I2MDEqtO3olmOOn2pxKYZ4iaHHhtdEqwTgVm7j1a7yb06xPMUOZ2Iky5EwNvjiUbsBBA3OfTYKVMFRKBPYHv4KXKVJ2o3+im0IwKaEiC14iKAC2rlohU59DhUC2qrDjoE1TlL+Yn/pNbc+xQ5sNjj6+vr5yaVSzq9urT+hZPl2jvV0toF3L5ub+W1r5w8vraStC5Un74EMjfiEzYIF9RqGxf1V+TQlasWwlAddAihdmaKEP9pmrL3FLlBzjs3rBcGR7MPnVys/Rbj4sPYmg8xgNO4/ai9Mfig6MJ/VxZrR2evJWmgAwHsI5HV0EpA2g1C/RU5dK2ahZQhAloTOHgUnnEVBGDV5dq2ezzjgCiwPxqjwiIU2Ndjc+q4whhTNyWpIkCcI5DUGx459AhNS0WJQBgCGxsbLxaZ5ylyltDiKXInn3j6P9Eee04QQmwb7Tr0Nxn3eUyT79OswL5x4ujqojxQuuEKQ6k8EuZPIDec/c2PFqs3PDlQo9lHpYkAEQhMQD5FjvV/ygYADGb8FLlnn332sLCsH3EMsfaxn3DCuBfo2H+VMfhThi9g7FShWPjmU+VnH8NDemeSALZyJu2asVFerN6wIrXIoSsCSWJmTSCG0RGzSUa7ccBbRbW0ZnmPkwy/1dlpOvXh+cW1K1ca33COnb3R3vgNYOKPe8eiKqD7a70wfWaPwLiVaPrGWhztE0qmF6s3HErYg4X0dOjUXx5sKYqZQMAzOtLTf+7zAnzTNQwAjj9+5p/c40QDsOxUd/l6/ZgT3ru/3G58HON+F7eXTbD+Aff0zh0Bz1jLne16G6ynQ6f+onev0V27FPWfl1/d+GEmLFfj+bniTyeN9+zRsz/EBOudBgkwJ9VvtOufxu3U5rVGo19qUpFMpPcAZcIUMiIRAlF6TDgF9XTo4WyhUkQglQSMTnNoHFaWalaShmwX+ZcdxwzM+q9AdYtAuWPOHO8EqsTUeFWMmS+JD0ZASY8JVOXQRBKoZNYz08DLegtrZd8CmJ9zFALOYGWp9iXnOP49LDl1XO40vF+Gc6JD7ZMfQslPoIHBpEDFwDZRAW0I8Ck1yV82Gnj5a/MZWnz++vmP4WXvrqMC5+znnHCc+2PHVn/ekS+Yfbl91zmOuhdRBVB5hQQmnF5NSFaoCImKkQA59BjhkmgiEISA0anPefNXy6uxX3rfZ8JfO3VyYbnfdHfiaJ8VAhNOryYkx0WBziPUktXDoau1KTvSqLdnpy2ntMTaOfipQVaAU8ee+dfBsfLQQ4LBvCMVL7c/7YRpn10COk0rIruYZ2IZOfSZYJ+yUurtU4LqZ9NppuqrFHS3efO537OA9R8FC8zsdpXd096rS3V5zXDihBDvOGHaZ5tA/NNK2gdisvqrrC0PDr0/OlVi64uknV4E4p+pErF383p9HzrYXl3YbStLMT1wRoD9+FaJrfjwzvf3KqRPIhCVgOxRUWXMsnyy+qusLUcOXSW2WXY2qjsPBPYXxccY6/VZ4OovvT9VXv9nD0fzO/hyfrrmiacgESACKSKg3qHjiiJF9kdXlSRkg4Bm/bb5avNzlijcceCaptpL75YQP+nIBi4+Y4d75w92kD6IABFIkICi+Ue9Q6dJIcFeQFUpI6Bhv93svPRQf5Fum1ktq3ngzOny6V93V+NCMONa41N2BfRBBIhASAIRPbKi+afn0CPqEpJA/ooF55w/Rnmy2O4P9sdIqxfeZf6UJxGeKj39f57jUMFdvvBZtyAUrrvhsYHxeo4tSolEIPMEFHnkkJyc0dlz6LPVJaQJKSxGnFPYaDGqbPcH+2NkJefPn/8Xy+RvOhksMN/jhEPvLethp2zx4fveEwYn2mc/Xk+fArmJcibT3BhMhmpHwBmdXDvNSKHkCFBNWhEY5Rg2X3vpUbwy3tcV2EpJzaV3eTn/0qVLjb7gfO1GwQ5BwZlMQxSlIkRAKYFsOHSFg1MpXRJGBAIQGOcYKp2lNSedA4MTpXP/E0C0m3Xliaf/3j3oPep1cJinkAMzTzZ7bQXvAYWzQiAbDj3vg1PP3khaBSAwaX79Kvtqk5ms7YgsMPO9TjjIviCsnxnkF98ehCmUKwIaz5mTxkKu2imgsdlw6AGNpuxEQDcC08yvrdfqy67eOOtVyrVpirlFZEAI4T7q1ejU3yfjaCMCOhEI3KkjKY8DKVJ5vQqTQ9erPUibaQnkNJ/RPrUi731L8+VUVF1ad/9Dm4ybsC0w5/dqg5vyjF5EIL8Ekj19iJszOfS4CZN8JCBdD+7orYDAl66YJnf/QxrjovBUae3iNIKrT9TcS+w4jd2fpoxWeagbadUcpIx+BMih69cmGdQI3Ue6rNJa2ys3Xvplxpnnp2zw7jPLZz7KJr0sdsbNAuLzbjgtAepGaWkp0nNGBDLl0OkEfka9iKpNnIBxrf4owxviTsU7ovg5Jzx6L9zx3mo3Pz46X/ZT4pgrIsuMLCD77UYWjifgDvDx2dKRSifw6Win3GupCIDRaeD4HfT6ypjfp1cWa88xun/OnNeAmhMTfR9G5pAPDyMgutokIUMEcELIkDVkChHIGYGd4twvOX4AgMHJpdptPwRQYINvtIP4K788FJc8Aaftkq+ZaswiAXLoWWxVsik3BK5efeFvBed1x2DB2cHK4vpl51jujx9f/4jcO5vRbv6KE07NHjyaesOeaAoSgbwTIIee9x4wU/tpZlaBf/PaS+t4O939+RoURLW6XHvdkT2/a/6ZE0bn33HCqdp7l7LecKqMIGWJQLwEyKHHy3eG0tPgLGlmVtVBWp3GnGCeH5cL9li1XDOPHVv9bQbcHeetay+VI9VJhYkAEdCWgDvQhzVMgzMY1piO9hIgZ7mXSNaPW+0GB4u95bGTL5j8D51jYLDjhGlPBKISgKgCqLxyAiMcOjkD5aSVCaRhpAxlBgVd3qo/YnHu+z/TxYE779fcZFIvRQTIS+jXWCMcun6KkkYOARpGDgmV+yydJuE99fca7Towq/i801tAQNcwjOdUMiNZRIAIqCCgbvYhh66iPUiGXgR8xodP1JDOjuMbikzLwQjjjK0X3tNCxy6d++XOxlxazIlNTxJMBLQkoG72SalDHzGDadlYpFTiBHzGh09U4mrFVmGmjYuNGgkmApkjkFKHTjNY5noiGUQE0k2AtCcCMyeQUoc+c25TKKDHVQQ9tJgCF2UhAkSACBCBSDQ8HFEAAABCSURBVATIoUfCN66wHlcR9NBiHCdKIwJEYCIBTTPQgiFCw8QAjxx6hPagokRAEohhXEqxtBEB7QnQgiFCE8UA7/8BAAD//y2vCsMAAAAGSURBVAMAi7zEakKPxywAAAAASUVORK5CYII=', 1, '2026-09-26 00:32:13', '1. Prices must be quoted in Philippine Peso (PHP) inclusive of all applicable taxes.\r\n2. Quoted lead time and delivery schedule must be strictly observed.\r\n3. Payment Terms: Net 30 Days upon complete goods receipt, inspection, and 3-way invoice reconciliation.\r\n4. Supplier must provide batch and expiry dates for perishable ingredients upon dispatch.');
INSERT INTO `rfqs` (`id`, `requisition_id`, `created_by`, `status`, `due_date`, `created_at`, `title`, `rfq_ref`, `invitation_letter`, `buyer_signature`, `buyer_signed_by`, `buyer_signed_at`, `terms_and_conditions`) VALUES
(39, 59, 1, 'open', '2026-10-03', '2026-09-25 16:35:04', 'RFQ - Testing Over The Budget', 'KM-RFQ-2026-0059', '', 'data:image/png;base64,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', 1, '2026-09-26 00:35:04', '1. Prices must be quoted in Philippine Peso (PHP) inclusive of all applicable taxes.\r\n2. Quoted lead time and delivery schedule must be strictly observed.\r\n3. Payment Terms: Net 30 Days upon complete goods receipt, inspection, and 3-way invoice reconciliation.\r\n4. Supplier must provide batch and expiry dates for perishable ingredients upon dispatch.');

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
(15, 15, 1),
(16, 16, 1),
(17, 25, 1),
(18, 27, 1),
(19, 29, 1),
(20, 32, 1),
(21, 33, 1),
(24, 38, 1),
(22, 38, 2),
(23, 38, 3),
(27, 39, 1),
(25, 39, 2),
(26, 39, 3);

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
('cashier', 'Cashier', 0),
('crew', 'Crew', 1),
('finance', 'Finance', 1),
('hr', 'HR', 1),
('manager', 'Manager', 1),
('ops', 'Ops', 0),
('procurement', 'Procurement Officer', 0),
('staff', 'Crew / Barista', 0),
('supplier', 'Supplier', 0),
('suppliers', 'Suppliers', 0),
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
('admin', 'analytics.view'),
('admin', 'attendance.view'),
('admin', 'dashboard.view'),
('admin', 'employee_dashboard.view'),
('admin', 'files.download'),
('admin', 'inventory.expiry.manage'),
('admin', 'inventory.manage'),
('admin', 'inventory.view'),
('admin', 'leave.view'),
('admin', 'menu.delete'),
('admin', 'menu.edit'),
('admin', 'menu.manage'),
('admin', 'orders.history'),
('admin', 'orders.new'),
('admin', 'orders.pending'),
('admin', 'payroll.advance.request'),
('admin', 'payroll.approve'),
('admin', 'payroll.loans'),
('admin', 'payroll.manage'),
('admin', 'payroll.own'),
('admin', 'payroll.release'),
('admin', 'payroll.settings'),
('admin', 'payroll.view'),
('admin', 'permissions.manage'),
('admin', 'procurement.attachments.manage'),
('admin', 'procurement.audit.view'),
('admin', 'procurement.bidding.review'),
('admin', 'procurement.budget.manage'),
('admin', 'procurement.close'),
('admin', 'procurement.finance.review'),
('admin', 'procurement.grn.discrepancy.manage'),
('admin', 'procurement.invoice.create'),
('admin', 'procurement.invoice.match'),
('admin', 'procurement.negotiation'),
('admin', 'procurement.payment.process'),
('admin', 'procurement.performance.rate'),
('admin', 'procurement.po.manage'),
('admin', 'procurement.receiving'),
('admin', 'procurement.reports.view'),
('admin', 'procurement.requisition.create'),
('admin', 'procurement.requisition.review'),
('admin', 'procurement.requisitions'),
('admin', 'procurement.rfq.manage'),
('admin', 'procurement.supplier.portal'),
('admin', 'procurement.suppliers.manage'),
('admin', 'procurement.view'),
('admin', 'recruitment.manage'),
('admin', 'requests.manage'),
('admin', 'users.manage'),
('cashier', 'dashboard.view'),
('cashier', 'employee_dashboard.view'),
('cashier', 'orders.history'),
('cashier', 'orders.new'),
('cashier', 'orders.pending'),
('cashier', 'payroll.advance.request'),
('cashier', 'payroll.own'),
('crew', 'attendance.view'),
('crew', 'dashboard.view'),
('crew', 'employee_dashboard.view'),
('crew', 'files.download'),
('crew', 'inventory.expiry.manage'),
('crew', 'inventory.view'),
('crew', 'orders.history'),
('crew', 'orders.new'),
('crew', 'orders.pending'),
('crew', 'payroll.advance.request'),
('crew', 'payroll.own'),
('finance', 'analytics.view'),
('finance', 'dashboard.view'),
('finance', 'employee_dashboard.view'),
('finance', 'payroll.loans'),
('finance', 'payroll.own'),
('finance', 'payroll.release'),
('finance', 'payroll.view'),
('finance', 'procurement.audit.view'),
('finance', 'procurement.budget.manage'),
('finance', 'procurement.finance.review'),
('finance', 'procurement.invoice.create'),
('finance', 'procurement.invoice.match'),
('finance', 'procurement.payment.process'),
('finance', 'procurement.po.manage'),
('finance', 'procurement.reports.view'),
('finance', 'procurement.requisition.review'),
('finance', 'procurement.view'),
('hr', 'attendance.view'),
('hr', 'dashboard.view'),
('hr', 'employee_dashboard.view'),
('hr', 'files.download'),
('hr', 'leave.view'),
('hr', 'payroll.loans'),
('hr', 'payroll.manage'),
('hr', 'payroll.own'),
('hr', 'payroll.settings'),
('hr', 'payroll.view'),
('hr', 'permissions.manage'),
('hr', 'recruitment.manage'),
('hr', 'requests.manage'),
('hr', 'users.manage'),
('manager', 'analytics.view'),
('manager', 'attendance.view'),
('manager', 'dashboard.view'),
('manager', 'employee_dashboard.view'),
('manager', 'files.download'),
('manager', 'inventory.expiry.manage'),
('manager', 'inventory.manage'),
('manager', 'inventory.view'),
('manager', 'leave.view'),
('manager', 'menu.delete'),
('manager', 'menu.edit'),
('manager', 'menu.manage'),
('manager', 'orders.history'),
('manager', 'orders.new'),
('manager', 'orders.pending'),
('manager', 'payroll.approve'),
('manager', 'payroll.loans'),
('manager', 'payroll.manage'),
('manager', 'payroll.own'),
('manager', 'payroll.view'),
('manager', 'procurement.audit.view'),
('manager', 'procurement.bidding.review'),
('manager', 'procurement.budget.manage'),
('manager', 'procurement.close'),
('manager', 'procurement.finance.review'),
('manager', 'procurement.negotiation'),
('manager', 'procurement.performance.rate'),
('manager', 'procurement.po.manage'),
('manager', 'procurement.receiving'),
('manager', 'procurement.reports.view'),
('manager', 'procurement.requisition.create'),
('manager', 'procurement.requisition.review'),
('manager', 'procurement.requisitions'),
('manager', 'procurement.rfq.manage'),
('manager', 'procurement.suppliers.manage'),
('manager', 'procurement.view'),
('manager', 'recruitment.manage'),
('ops', 'attendance.view'),
('ops', 'dashboard.view'),
('ops', 'employee_dashboard.view'),
('ops', 'leave.view'),
('ops', 'menu.delete'),
('ops', 'menu.edit'),
('ops', 'menu.manage'),
('ops', 'orders.history'),
('ops', 'orders.new'),
('ops', 'orders.pending'),
('ops', 'payroll.advance.request'),
('ops', 'payroll.own'),
('procurement', 'dashboard.view'),
('procurement', 'employee_dashboard.view'),
('procurement', 'payroll.advance.request'),
('procurement', 'payroll.own'),
('procurement', 'procurement.attachments.manage'),
('procurement', 'procurement.bidding.review'),
('procurement', 'procurement.close'),
('procurement', 'procurement.invoice.match'),
('procurement', 'procurement.negotiation'),
('procurement', 'procurement.performance.rate'),
('procurement', 'procurement.po.manage'),
('procurement', 'procurement.reports.view'),
('procurement', 'procurement.requisition.create'),
('procurement', 'procurement.requisition.review'),
('procurement', 'procurement.requisitions'),
('procurement', 'procurement.rfq.manage'),
('procurement', 'procurement.suppliers.manage'),
('procurement', 'procurement.view'),
('staff', 'attendance.view'),
('staff', 'dashboard.view'),
('staff', 'employee_dashboard.view'),
('staff', 'files.download'),
('staff', 'orders.pending'),
('staff', 'payroll.advance.request'),
('staff', 'payroll.own'),
('supplier', 'procurement.supplier.portal'),
('suppliers', 'procurement.supplier.portal'),
('warehouse', 'dashboard.view'),
('warehouse', 'employee_dashboard.view'),
('warehouse', 'inventory.manage'),
('warehouse', 'inventory.view'),
('warehouse', 'payroll.advance.request'),
('warehouse', 'payroll.own'),
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
(1, 12, 'Selecta', 'Anton', 'selecta@gmail.com', '998223213', 'Selecta Street', 'active', 4.77, 10, '2026-08-24 19:40:40'),
(2, 1, 'ASN Test Logistics Supplier', 'Carlos Logistics', 'asn_test@example.com', '09123456789', NULL, 'active', NULL, 0, '2026-09-25 15:35:04'),
(3, 1, 'ASN Test Logistics Supplier', 'Carlos Logistics', 'asn_test@example.com', '09123456789', NULL, 'active', NULL, 0, '2026-09-25 15:35:41');

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
(8, 15, 1, 6, 5, 5, 5, 5, 'Kumpleto daw sabi ni warehouse', '2026-09-14 19:27:32'),
(11, 21, 1, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 10:22:11'),
(12, 22, 1, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 10:48:35'),
(13, 23, 1, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 11:05:54'),
(14, 24, 1, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 14:13:48'),
(15, 25, 1, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 14:39:48'),
(16, 29, 1, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 14:57:49'),
(17, 33, 1, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 15:10:37'),
(18, 40, 2, 1, 5, 5, 5, 4, 'Exceptional bean quality and on-time shipment', '2026-09-25 15:37:26');

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
(1, 'admin', 'admin@kofeecafe.local', '$2b$12$qOp8gghDWgZARl6QgPjokOym9pdos0eYGuukmuKjwftvDNoYiLsce', 'Admin', 'User', 'admin', 'active', '2026-09-26 01:45:20', '2026-08-01 05:54:54', '2026-09-25 17:45:20'),
(2, 'khylle', 'khyllechester.roque07@gmail.com', '$2y$12$VUbEIn4SkyThKDFRhOOHc.t9IHUYXDz8bVTSH4RO0kbgh/PDhRd3O', 'Khylle', 'Roque', 'crew', 'active', '2026-09-21 07:23:58', '2026-08-01 17:10:32', '2026-09-22 05:52:51'),
(4, 'hr', 'hr@gmail.com', '$2y$12$GgAf5abpEeCCMShTPyNBx.fFkkldjYtSqXBC2fGO3QeOCZq2E.RC6', 'Hr', 'Test', 'hr', 'active', '2026-09-21 12:24:54', '2026-08-03 02:44:30', '2026-09-21 04:24:54'),
(6, 'manager', 'manager@gmail.com', '$2y$12$KZjpe74FXfwp1apOhfVTWuEpWZR8NDpwtSa3hMgdzWfL6u68DGuJa', 'manager', 'test', 'manager', 'active', '2026-09-18 15:42:59', '2026-08-07 16:26:32', '2026-09-18 07:42:59'),
(7, 'finance', 'finance@gmail.com', '$2y$12$grKPN2YaboFgVuNdjss.x.m35lXZVvhc.oXtndynSFIVfAfADUrVe', 'finance', 'testing', 'finance', 'active', '2026-09-15 03:25:54', '2026-08-25 07:19:47', '2026-09-14 19:25:54'),
(8, 'crew', 'crew@gmail.com', '$2y$12$sDuD5IqUP1dYowMEEOZpMeb9gsmvqqaCMBPJeQe5FGGOtQ4exgGmO', 'Crew', 'Test', 'crew', 'active', '2026-09-21 12:29:37', '2026-08-31 19:16:53', '2026-09-21 04:29:37'),
(10, 'ops', 'ops@gmail.com', '$2y$12$b/Biet0SDoRiUe85NikfUOxVpkN2MC.PKrGUoe1u/UB07lXiIRcwK', 'ops', 'test', 'ops', 'active', '2026-09-20 01:00:56', '2026-08-31 19:33:41', '2026-09-19 17:00:56'),
(11, 'procurement', 'procurement@gmail.com', '$2y$12$QN9qrF8k8f/ACcuSk2hGfuXQ4Sd/TmyRur2tYTbxDrnORFR/URxpG', 'Procurment', 'Testing', 'procurement', 'active', '2026-09-22 14:51:13', '2026-08-31 19:34:39', '2026-09-22 06:51:13'),
(12, 'supplier', 'supplier@gmail.com', '$2y$12$qEoXF2nSXx1hCzuiFKnMn.4GA4.vcUTUl4EqRS1Dr//ezjKkf3tOO', 'Supplier', 'Testing', 'suppliers', 'active', '2026-09-26 00:44:29', '2026-08-31 19:35:35', '2026-09-25 16:44:29'),
(13, 'warehouse', 'receiving@gmail.com', '$2y$12$U.fk3.cm1Eef/i.RIDjkou5EeDI1cQpWTPDqzm3UcwjJAR.uzw56u', 'Receiving', 'Testing', 'warehouse', 'active', '2026-09-20 00:54:34', '2026-08-31 19:36:38', '2026-09-19 16:54:34'),
(16, 'crew2', 'crew2@gmail.com', '$2y$12$wNQeq7F7GKW/CL3/JISV3em4Rdqqc.iItnmsOYlny3DgZgcJzdOUq', 'testing', 'Test', 'crew', 'active', '2026-09-13 19:54:59', '2026-08-31 21:06:16', '2026-09-13 11:54:59'),
(19, 'cashier', 'cashier@gmail.com', '$2b$12$qOp8gghDWgZARl6QgPjokOym9pdos0eYGuukmuKjwftvDNoYiLsce', 'Cashier', 'Staff', 'cashier', 'active', NULL, '2026-09-25 16:53:25', '2026-09-25 16:53:32');

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
(309, 2, 'crew'),
(310, 2, 'finance'),
(311, 2, 'hr'),
(312, 2, 'staff'),
(211, 4, 'hr'),
(226, 6, 'manager'),
(220, 7, 'finance'),
(205, 8, 'crew'),
(239, 10, 'ops'),
(242, 11, 'procurement'),
(327, 12, 'suppliers'),
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
-- Indexes for table `auth_throttle`
--
ALTER TABLE `auth_throttle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_throttle_id` (`identifier`,`attempted_at`),
  ADD KEY `idx_throttle_ip` (`ip_address`,`attempted_at`);

--
-- Indexes for table `bids`
--
ALTER TABLE `bids`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_rfq_supplier_bid` (`rfq_id`,`supplier_id`),
  ADD KEY `fk_bid_supplier` (`supplier_id`);

--
-- Indexes for table `bid_items`
--
ALTER TABLE `bid_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bid_id` (`bid_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `delivery_notices`
--
ALTER TABLE `delivery_notices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `notice_ref` (`notice_ref`),
  ADD KEY `po_id` (`po_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `delivery_notice_items`
--
ALTER TABLE `delivery_notice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_notice_id` (`delivery_notice_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD KEY `fk_employees_user` (`user_id`);

--
-- Indexes for table `employee_loans`
--
ALTER TABLE `employee_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_loan_employee` (`employee_id`,`status`);

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
-- Indexes for table `ingredient_batches`
--
ALTER TABLE `ingredient_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_batch_ingredient` (`ingredient_id`),
  ADD KEY `idx_batch_status` (`status`),
  ADD KEY `idx_batch_expiry` (`expiry_date`);

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
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_code` (`application_code`),
  ADD KEY `idx_code` (`application_code`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_job` (`job_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `job_postings`
--
ALTER TABLE `job_postings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_slug` (`slug`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_leave_employee` (`employee_id`),
  ADD KEY `fk_leave_reviewer` (`reviewed_by`);

--
-- Indexes for table `loan_repayments`
--
ALTER TABLE `loan_repayments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_repay_loan` (`loan_id`);

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
  ADD KEY `fk_orders_user` (`user_id`),
  ADD KEY `idx_orders_placed` (`placed_at`),
  ADD KEY `idx_orders_cashier` (`user_id`,`placed_at`),
  ADD KEY `idx_orders_employee` (`employee_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_order_items_order` (`order_id`),
  ADD KEY `fk_order_items_product` (`product_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_invoice` (`invoice_id`),
  ADD KEY `idx_payments_po` (`po_id`);

--
-- Indexes for table `payroll_audit`
--
ALTER TABLE `payroll_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_paudit_period` (`period_id`,`created_at`);

--
-- Indexes for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_period` (`period_start`,`period_end`,`branch`),
  ADD KEY `idx_period_status` (`status`,`pay_date`);

--
-- Indexes for table `payroll_settings`
--
ALTER TABLE `payroll_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `payslips`
--
ALTER TABLE `payslips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_payslip` (`period_id`,`employee_id`),
  ADD KEY `idx_payslip_employee` (`employee_id`);

--
-- Indexes for table `payslip_adjustments`
--
ALTER TABLE `payslip_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adj_payslip` (`payslip_id`);

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
-- Indexes for table `procurement_letters`
--
ALTER TABLE `procurement_letters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `letter_ref` (`letter_ref`),
  ADD KEY `requisition_id` (`requisition_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `procurement_settings`
--
ALTER TABLE `procurement_settings`
  ADD PRIMARY KEY (`setting_key`);

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
-- Indexes for table `purchase_contracts`
--
ALTER TABLE `purchase_contracts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `contract_ref` (`contract_ref`),
  ADD KEY `requisition_id` (`requisition_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `purchase_contract_items`
--
ALTER TABLE `purchase_contract_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contract_id` (`contract_id`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `auth_throttle`
--
ALTER TABLE `auth_throttle`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `bids`
--
ALTER TABLE `bids`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `bid_items`
--
ALTER TABLE `bid_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `delivery_notices`
--
ALTER TABLE `delivery_notices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `delivery_notice_items`
--
ALTER TABLE `delivery_notice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `employee_loans`
--
ALTER TABLE `employee_loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `goods_receipts`
--
ALTER TABLE `goods_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `goods_receipt_items`
--
ALTER TABLE `goods_receipt_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `hr_requests`
--
ALTER TABLE `hr_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `ingredient_batches`
--
ALTER TABLE `ingredient_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `ingredient_categories`
--
ALTER TABLE `ingredient_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `ingredient_usage_log`
--
ALTER TABLE `ingredient_usage_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `job_postings`
--
ALTER TABLE `job_postings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `loan_repayments`
--
ALTER TABLE `loan_repayments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=470;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `payroll_audit`
--
ALTER TABLE `payroll_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payslips`
--
ALTER TABLE `payslips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `payslip_adjustments`
--
ALTER TABLE `payslip_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `procurement_attachments`
--
ALTER TABLE `procurement_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `procurement_audit_log`
--
ALTER TABLE `procurement_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=205;

--
-- AUTO_INCREMENT for table `procurement_budgets`
--
ALTER TABLE `procurement_budgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `procurement_letters`
--
ALTER TABLE `procurement_letters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(50) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `product_ingredients`
--
ALTER TABLE `product_ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `purchase_contracts`
--
ALTER TABLE `purchase_contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `purchase_contract_items`
--
ALTER TABLE `purchase_contract_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `purchase_requisitions`
--
ALTER TABLE `purchase_requisitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `requisition_items`
--
ALTER TABLE `requisition_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `restock_log`
--
ALTER TABLE `restock_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `rfqs`
--
ALTER TABLE `rfqs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `rfq_invites`
--
ALTER TABLE `rfq_invites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `sales_history`
--
ALTER TABLE `sales_history`
  MODIFY `id` int(50) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `supplier_performance_ratings`
--
ALTER TABLE `supplier_performance_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(50) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=332;

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
-- Constraints for table `employee_loans`
--
ALTER TABLE `employee_loans`
  ADD CONSTRAINT `fk_loan_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `hr_requests`
--
ALTER TABLE `hr_requests`
  ADD CONSTRAINT `fk_hrreq_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hrreq_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ingredient_batches`
--
ALTER TABLE `ingredient_batches`
  ADD CONSTRAINT `fk_batch_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ingredient_usage_log`
--
ALTER TABLE `ingredient_usage_log`
  ADD CONSTRAINT `fk_usage_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_usage_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_usage_user` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `fk_job_app_posting` FOREIGN KEY (`job_id`) REFERENCES `job_postings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `fk_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_leave_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `loan_repayments`
--
ALTER TABLE `loan_repayments`
  ADD CONSTRAINT `fk_repay_loan` FOREIGN KEY (`loan_id`) REFERENCES `employee_loans` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payslips`
--
ALTER TABLE `payslips`
  ADD CONSTRAINT `fk_payslip_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payslip_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payslip_adjustments`
--
ALTER TABLE `payslip_adjustments`
  ADD CONSTRAINT `fk_adj_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`id`) ON DELETE CASCADE;

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
