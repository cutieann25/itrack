-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 21, 2026 at 03:57 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `attendance_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `latitude` double NOT NULL,
  `longitude` double NOT NULL,
  `status` varchar(20) DEFAULT NULL,
  `log_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `device_id` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_logs`
--

INSERT INTO `attendance_logs` (`id`, `student_id`, `latitude`, `longitude`, `status`, `log_time`, `device_id`) VALUES
(24, 'jade llego sabetan', 11.2944585, 125.5949808, 'Success (IN)', '2026-04-09 15:26:17', 'f33ee82a6f1e75b5'),
(25, 'jade llego sabetan', 11.2944585, 125.5949808, 'Success (OUT)', '2026-04-09 15:28:30', 'f33ee82a6f1e75b5'),
(26, 'jade llego sabetan', 11.2943017, 125.594845, 'Success (IN)', '2026-05-22 13:09:40', NULL),
(27, 'jade llego sabetan', 11.2943017, 125.594845, 'Success (OUT)', '2026-05-22 13:10:32', NULL),
(28, 'Julie Ann', 11.2943502, 125.5950121, 'Success (IN)', '2026-05-22 14:27:47', 'fe095ae17870d67e'),
(29, 'jade llego sabetan', 11.2946342, 125.5950969, 'Success (IN)', '2026-05-23 02:38:55', 'f33ee82a6f1e75b5'),
(30, 'jade llego sabetan', 11.2944806, 125.5949663, 'Success (IN)', '2026-05-23 09:51:02', 'f33ee82a6f1e75b5'),
(31, 'jade llego sabetan', 11.2944806, 125.5949663, 'Success (OUT)', '2026-05-23 10:44:20', 'f33ee82a6f1e75b5'),
(32, 'jade llego sabetan', 11.294474, 125.5949696, 'Success (IN)', '2026-05-24 12:15:24', 'f33ee82a6f1e75b5'),
(33, 'jade llego sabetan', 11.294474, 125.5949696, 'Success (OUT)', '2026-05-24 12:23:58', 'f33ee82a6f1e75b5'),
(34, 'jade llego sabetan', 11.2944821, 125.5949745, 'Success (IN)', '2026-05-25 09:58:42', 'f33ee82a6f1e75b5'),
(35, 'jade llego sabetan', 11.2944821, 125.5949745, 'Success (OUT)', '2026-05-25 09:59:15', 'f33ee82a6f1e75b5'),
(36, 'jade llego sabetan', 11.2944835, 125.5949742, 'Success (IN)', '2026-06-05 12:46:37', 'f33ee82a6f1e75b5'),
(37, 'Julie Ann', 11.0366237, 125.7159884, 'Success (IN)', '2026-06-05 12:49:21', 'fe095ae17870d67e');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
