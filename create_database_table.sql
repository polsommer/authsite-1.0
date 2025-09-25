SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Table structure for table `user_account`
-- --------------------------------------------------------

CREATE TABLE `user_account` (
  `user_id` int(11) NOT NULL,
  `accesslevel` varchar(255) NOT NULL DEFAULT 'standard',
  `username` varchar(255) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email_verification_token` varchar(255) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `faction` varchar(100) DEFAULT NULL,
  `favorite_activity` varchar(100) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `discord_handle` varchar(100) DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `biography` text,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Indexes for table `user_account`
ALTER TABLE `user_account`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

-- AUTO_INCREMENT for table `user_account`
ALTER TABLE `user_account`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- Table structure for table `user_friendships`
-- --------------------------------------------------------

CREATE TABLE `user_friendships` (
  `id` int(11) NOT NULL,
  `requester_id` int(11) NOT NULL,
  `addressee_id` int(11) NOT NULL,
  `status` enum('pending','accepted','declined','blocked') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `user_friendships`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_friend_pair` (`requester_id`, `addressee_id`),
  ADD KEY `idx_friend_addressee` (`addressee_id`),
  ADD KEY `idx_friend_requester` (`requester_id`);

ALTER TABLE `user_friendships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_friendships`
  ADD CONSTRAINT `fk_friend_requester` FOREIGN KEY (`requester_id`) REFERENCES `user_account` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_friend_addressee` FOREIGN KEY (`addressee_id`) REFERENCES `user_account` (`user_id`) ON DELETE CASCADE;

-- --------------------------------------------------------
-- Table structure for table `auth_events`
-- --------------------------------------------------------

CREATE TABLE `auth_events` (
  `id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `network_host` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `auth_events`
  ADD PRIMARY KEY (`id`),
  ADD INDEX `idx_action_created` (`action`, `created_at`),
  ADD INDEX `idx_ip_created` (`ip_address`, `created_at`);

ALTER TABLE `auth_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------
-- Table structure for table `banned_networks`
-- --------------------------------------------------------

CREATE TABLE `banned_networks` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `network_host` varchar(255) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `banned_networks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ip_address` (`ip_address`);

ALTER TABLE `banned_networks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
