-- ============================================================
-- GUECO OPTICAL CLINIC MANAGEMENT SYSTEM
-- Database: gueco_optical
-- Created: 2025
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

CREATE DATABASE IF NOT EXISTS `gueco_optical` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gueco_optical`;

-- ============================================================
-- TABLE: users (Staff accounts: admin, doctor, saleslady)
-- ============================================================
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','doctor','saleslady') NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: patients (Patient accounts for appointment booking)
-- ============================================================
CREATE TABLE `patients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `birthdate` DATE DEFAULT NULL,
  `gender` ENUM('male','female','other') DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `reset_otp_hash` VARCHAR(255) DEFAULT NULL,
  `reset_expires` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: categories (Product categories)
-- ============================================================
CREATE TABLE `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: suppliers
-- ============================================================
CREATE TABLE `suppliers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: products
-- ============================================================
CREATE TABLE `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `supplier_id` INT DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock_quantity` INT NOT NULL DEFAULT 0,
  `low_stock_alert` INT NOT NULL DEFAULT 5,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: appointments
-- ============================================================
CREATE TABLE `appointments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `appointment_date` DATE NOT NULL,
  `appointment_time` TIME NOT NULL,
  `purpose` ENUM('consultation','eyeglass_claim','follow_up','contact_lens_fitting','other') NOT NULL DEFAULT 'consultation',
  `status` ENUM('pending','confirmed','completed','cancelled','no_show') DEFAULT 'pending',
  `notes` TEXT DEFAULT NULL,
  `verified_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`verified_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: patient_records (Medical records created by doctor)
-- ============================================================
CREATE TABLE `patient_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `doctor_id` INT NOT NULL,
  `appointment_id` INT DEFAULT NULL,
  `visit_date` DATE NOT NULL,
  `chief_complaint` TEXT DEFAULT NULL,
  `diagnosis` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('active','archived') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: prescriptions (Eye prescriptions)
-- ============================================================
CREATE TABLE `prescriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `doctor_id` INT NOT NULL,
  `record_id` INT DEFAULT NULL,
  -- Right Eye (OD - Oculus Dexter)
  `od_sphere` DECIMAL(5,2) DEFAULT NULL,
  `od_cylinder` DECIMAL(5,2) DEFAULT NULL,
  `od_axis` INT DEFAULT NULL,
  `od_add` DECIMAL(5,2) DEFAULT NULL,
  `od_va` VARCHAR(20) DEFAULT NULL,
  -- Left Eye (OS - Oculus Sinister)
  `os_sphere` DECIMAL(5,2) DEFAULT NULL,
  `os_cylinder` DECIMAL(5,2) DEFAULT NULL,
  `os_axis` INT DEFAULT NULL,
  `os_add` DECIMAL(5,2) DEFAULT NULL,
  `os_va` VARCHAR(20) DEFAULT NULL,
  -- Pupillary Distance
  `pd` DECIMAL(5,2) DEFAULT NULL,
  `pd_right` DECIMAL(5,2) DEFAULT NULL,
  `pd_left` DECIMAL(5,2) DEFAULT NULL,
  -- Recommendations
  `recommendations` TEXT DEFAULT NULL,
  `lens_type` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`record_id`) REFERENCES `patient_records`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: sales (Transaction headers)
-- ============================================================
CREATE TABLE `sales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_no` VARCHAR(20) NOT NULL UNIQUE,
  `patient_id` INT DEFAULT NULL,
  `cashier_id` INT NOT NULL,
  `appointment_id` INT DEFAULT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','gcash','other') DEFAULT 'cash',
  `amount_paid` DECIMAL(10,2) DEFAULT 0.00,
  `change_amount` DECIMAL(10,2) DEFAULT 0.00,
  `status` ENUM('completed','refunded','voided') DEFAULT 'completed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: sale_items (Items per sale)
-- ============================================================
CREATE TABLE `sale_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT NOT NULL,
  `product_id` INT DEFAULT NULL,
  `item_name` VARCHAR(150) NOT NULL,
  `item_type` ENUM('product','consultation_fee','service') DEFAULT 'product',
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `total_price` DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: inventory_logs (Stock movement tracking)
-- ============================================================
CREATE TABLE `inventory_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `type` ENUM('stock_in','stock_out','adjustment') NOT NULL,
  `quantity` INT NOT NULL,
  `previous_stock` INT NOT NULL,
  `new_stock` INT NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `reference_id` INT DEFAULT NULL,
  `user_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: activity_logs (System audit trail)
-- ============================================================
CREATE TABLE `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `user_type` ENUM('staff','patient') DEFAULT 'staff',
  `action` VARCHAR(200) NOT NULL,
  `module` VARCHAR(100) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: system_settings
-- ============================================================
CREATE TABLE `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- NOTE: Run setup.php to insert staff accounts with hashed passwords
-- ============================================================

-- Categories
INSERT INTO `categories` (`name`, `description`) VALUES
('Frames', 'Eyeglass frames of various styles and brands'),
('Lenses', 'Prescription and non-prescription lenses'),
('Contact Lenses', 'Soft and hard contact lenses'),
('Eye Care Solutions', 'Contact lens solutions and eye drops'),
('Accessories', 'Cases, cleaning cloths, and other accessories');

-- Suppliers
INSERT INTO `suppliers` (`company_name`, `contact_person`, `phone`, `email`, `address`) VALUES
('VisionPro Supply Co.', 'Juan Dela Cruz', '09171234567', 'visionpro@email.com', 'Manila, Philippines'),
('OpticsWorld Philippines', 'Maria Santos', '09281234567', 'opticsworld@email.com', 'Quezon City, Philippines'),
('LensCraft Distributors', 'Pedro Reyes', '09391234567', 'lenscraft@email.com', 'Tarlac City, Philippines');

-- Products (sample)
INSERT INTO `products` (`category_id`, `supplier_id`, `name`, `description`, `price`, `stock_quantity`, `low_stock_alert`) VALUES
(1, 1, 'Ray-Ban Classic Frame', 'Full-rim acetate frame, black', 1500.00, 20, 5),
(1, 1, 'Titan Titanium Frame', 'Lightweight titanium half-rim frame', 2500.00, 15, 5),
(1, 2, 'Oakley Sport Frame', 'Wraparound sport frame', 3000.00, 10, 3),
(2, 2, 'Single Vision Lens', 'Standard single vision prescription lens', 800.00, 50, 10),
(2, 2, 'Progressive Lens', 'No-line multifocal lens', 2000.00, 30, 8),
(2, 3, 'Anti-Radiation Lens', 'Blue light blocking lens', 1200.00, 40, 10),
(3, 2, 'Acuvue Oasys Monthly', 'Monthly disposable contact lenses (pair)', 600.00, 25, 5),
(3, 2, 'Air Optix Daily', 'Daily disposable contact lenses (30-pack)', 1200.00, 20, 5),
(4, 3, 'ReNu Multi-Purpose Solution', '360ml contact lens solution', 350.00, 30, 8),
(4, 3, 'Opti-Free Replenish', '300ml contact lens solution', 400.00, 25, 8),
(5, 1, 'Hard Shell Eyeglass Case', 'Protective hard case with logo', 150.00, 50, 10),
(5, 1, 'Microfiber Cleaning Cloth', 'Soft lens cleaning cloth', 80.00, 100, 20);

-- System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('clinic_name', 'Gueco Optical Clinic'),
('clinic_address', 'Capas, Tarlac'),
('clinic_phone', '09XX-XXX-XXXX'),
('clinic_hours', '9:00 AM - 5:00 PM'),
('consultation_fee', '300.00'),
('invoice_prefix', 'GO-'),
('appointment_slots', '09:00,09:30,10:00,10:30,11:00,11:30,13:00,13:30,14:00,14:30,15:00,15:30,16:00,16:30');
