-- ─────────────────────────────────────────────────────────────
--  Kofee Manila — Careers & Recruitment System Tables
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `job_postings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL UNIQUE,
  `title` VARCHAR(150) NOT NULL,
  `department` VARCHAR(100) NOT NULL DEFAULT 'Coffee & Barista',
  `location` VARCHAR(100) NOT NULL DEFAULT 'Manila',
  `job_type` VARCHAR(50) NOT NULL DEFAULT 'Full-time',
  `tagline` VARCHAR(255) NOT NULL DEFAULT '',
  `description` TEXT NOT NULL,
  `about_role` TEXT NOT NULL,
  `responsibilities` TEXT NULL,
  `requirements` TEXT NULL,
  `benefits` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_slug` (`slug`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_applications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `application_code` VARCHAR(50) NOT NULL UNIQUE,
  `job_id` INT(11) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(50) NOT NULL,
  `city` VARCHAR(150) NOT NULL,
  `experience` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `resume_filename` VARCHAR(255) NOT NULL,
  `resume_path` VARCHAR(255) NOT NULL,
  `additional_message` TEXT NULL,
  `privacy_accepted` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('review', 'interview', 'decision', 'hired', 'rejected') NOT NULL DEFAULT 'review',
  `reviewer_notes` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_code` (`application_code`),
  INDEX `idx_email` (`email`),
  INDEX `idx_job` (`job_id`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_job_app_posting` FOREIGN KEY (`job_id`) REFERENCES `job_postings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed the 3 open positions from the design mockup ──────────
INSERT INTO `job_postings` (`id`, `slug`, `title`, `department`, `location`, `job_type`, `tagline`, `description`, `about_role`, `responsibilities`, `requirements`, `benefits`, `is_active`)
VALUES
(1, 'barista', 'Barista', 'Coffee & Barista', 'Manila', 'Full-time', 
 'Craft quality beverages and create welcoming experiences for every guest.',
 'Craft quality beverages and create welcoming experiences for every guest.',
 'Craft quality beverages and create welcoming experiences for every guest.',
 'Prepare espresso drinks, pour-overs, iced teas, and signature specialty coffee recipes.\nGreet each customer with warm hospitality, answer questions about flavor notes, and take orders accurately.\nMaintain a clean, organized, and sanitized workstation, espresso machine, and grinder area.\nManage cash and POS transactions efficiently while maintaining a friendly, positive café vibe.',
 'Passion for coffee culture and customer service.\nPrevious specialty coffee or café experience is a plus, but motivated beginners are warmly welcome.\nStrong communication skills and high attention to detail.\nAbility to work flexible retail shifts including mornings, weekends, or holidays.',
 'Competitive hourly wage with daily tips sharing.\nFree shift drinks and staff discount on merchandise & beans.\nHands-on barista certification and latte art training.\nClear pathway to Shift Lead and Café Supervisor roles.',
 1),

(2, 'store-supervisor', 'Store Supervisor', 'Store Operations', 'Quezon City', 'Full-time',
 'Lead the store team and support smooth, consistent daily operations.',
 'Lead the store team and support smooth, consistent daily operations.',
 'Lead the store team and support smooth, consistent daily operations.',
 'Oversee daily opening and closing store procedures and floor workflow.\nGuide and mentor baristas and counter crew to deliver exceptional service consistency.\nTrack ingredient inventories, spot stockouts, and coordinate stock requisitions.\nReconcile register cash drawers, manage daily shift handovers, and resolve customer feedback with grace.',
 'Minimum 1-2 years experience in café, quick-service restaurant, or retail supervision.\nProven leadership abilities and reliable problem-solving skills under fast-paced peak hours.\nFamiliarity with POS operations, inventory management, and basic hygiene safety standards.\nPositive team-first attitude and passion for hospitality excellence.',
 'Competitive monthly salary with supervisory performance incentives.\nPaid health benefits and leave credits.\nStaff meal allowance and unlimited specialty coffee on shift.\nDirect mentorship from operations management and growth opportunities.',
 1),

(3, 'kitchen-crew', 'Kitchen Crew', 'Kitchen & Food', 'Makati', 'Full-time',
 'Prepare food with care and keep our kitchen organized and ready.',
 'Prepare food with care and keep our kitchen organized and ready.',
 'Prepare food with care and keep our kitchen organized and ready.',
 'Prepare freshly baked pastries, artisanal sandwiches, and light savory bites according to recipes.\nEnsure all food preparation complies with stringent food safety and sanitation guidelines.\nMonitor ingredient freshness, label batch dates, and prevent kitchen waste.\nSupport dishwashing, prep station sanitizing, and receiving deliveries.',
 'Experience in commercial food preparation or culinary arts studies preferred, but enthusiastic trainees are welcome.\nKnowledge of food hygiene and basic knife handling skills.\nPunctual, responsible, and capable of working in an energetic kitchen environment.\nValid health card / food handler certificate (or willingness to obtain one upon hire).',
 'Competitive wage with shift meal provisions.\nHealth coverage and government statutory benefits.\nStructured culinary and baking training programs.\nFriendly and respectful collaborative kitchen environment.',
 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `department` = VALUES(`department`),
  `location` = VALUES(`location`),
  `job_type` = VALUES(`job_type`),
  `tagline` = VALUES(`tagline`),
  `description` = VALUES(`description`),
  `about_role` = VALUES(`about_role`),
  `responsibilities` = VALUES(`responsibilities`),
  `requirements` = VALUES(`requirements`),
  `benefits` = VALUES(`benefits`),
  `is_active` = 1;
