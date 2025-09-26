SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Table structure for table `user_account`
-- --------------------------------------------------------
CREATE TABLE `market_listings` (
  `id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `category` varchar(100) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(12) NOT NULL DEFAULT 'CREDITS',
  `contact_channel` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('active','closed') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `market_listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_market_status` (`status`),
  ADD KEY `idx_market_seller` (`seller_id`),
  ADD KEY `idx_market_category` (`category`);

ALTER TABLE `market_listings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `market_listings`
  ADD CONSTRAINT `fk_market_seller` FOREIGN KEY (`seller_id`) REFERENCES `user_account` (`user_id`) ON DELETE CASCADE;

-- --------------------------------------------------------
-- Table structure for table `market_listings`
-- --------------------------------------------------------

CREATE TABLE `market_listings` (
  `id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `category` varchar(100) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(12) NOT NULL DEFAULT 'CREDITS',
  `contact_channel` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('active','closed') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `market_listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_market_status` (`status`),
  ADD KEY `idx_market_seller` (`seller_id`),
  ADD KEY `idx_market_category` (`category`);

ALTER TABLE `market_listings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `market_listings`
  ADD CONSTRAINT `fk_market_seller` FOREIGN KEY (`seller_id`) REFERENCES `user_account` (`user_id`) ON DELETE CASCADE;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
