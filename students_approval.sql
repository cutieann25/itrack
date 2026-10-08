CREATE TABLE IF NOT EXISTS `students` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_name` VARCHAR(255) NOT NULL,
  `strand` VARCHAR(255) NOT NULL,
  `dob` DATE DEFAULT NULL,
  `gender` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `parents` TEXT DEFAULT NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `status` ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_student_device` (`student_name`, `device_id`)
);
