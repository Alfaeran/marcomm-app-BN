-- MariaDB dump 10.19  Distrib 10.4.27-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: marcomm_event
-- ------------------------------------------------------
-- Server version	10.4.27-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11005 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_branch` varchar(255) NOT NULL,
  `brand` enum('IM3','3ID') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nama_branch` (`nama_branch`,`brand`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `deletion_requests`
--

DROP TABLE IF EXISTS `deletion_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deletion_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `request_type` varchar(20) NOT NULL DEFAULT 'delete',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_activity_request` (`activity_id`),
  KEY `user_id` (`user_id`),
  KEY `reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB AUTO_INCREMENT=190 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `duplicate_msisdn_log`
--

DROP TABLE IF EXISTS `duplicate_msisdn_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `duplicate_msisdn_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `new_submission_id` int(11) NOT NULL,
  `duplicate_msisdn` varchar(20) NOT NULL,
  `original_submission_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `new_submission_id` (`new_submission_id`),
  KEY `original_submission_id` (`original_submission_id`),
  CONSTRAINT `duplicate_msisdn_log_ibfk_1` FOREIGN KEY (`new_submission_id`) REFERENCES `event_submissions` (`unique_id`) ON DELETE CASCADE,
  CONSTRAINT `duplicate_msisdn_log_ibfk_2` FOREIGN KEY (`original_submission_id`) REFERENCES `event_submissions` (`unique_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9839 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `event_categories`
--

DROP TABLE IF EXISTS `event_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_kategori` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nama_kategori` (`nama_kategori`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `event_requests`
--

DROP TABLE IF EXISTS `event_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `request_type` enum('edit','delete') NOT NULL,
  `reason` text NOT NULL,
  `requested_columns` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=244 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `event_submissions`
--

DROP TABLE IF EXISTS `event_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_submissions` (
  `unique_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `edit_allowed_for_user_id` int(11) DEFAULT NULL,
  `edit_permission_expires_at` datetime DEFAULT NULL,
  `event_name` varchar(255) NOT NULL,
  `waktu_input` timestamp NOT NULL DEFAULT current_timestamp(),
  `site_id` int(11) DEFAULT NULL,
  `site_snapshot_name` varchar(255) DEFAULT NULL COMMENT 'Simpan nama site saat event dibuat',
  `location_latitude` decimal(10,8) NOT NULL,
  `location_longitude` decimal(11,8) NOT NULL,
  `kategori_event_id` int(11) NOT NULL,
  `foto_event_url` varchar(255) NOT NULL,
  `sp_0k` int(11) DEFAULT 0,
  `sp_3gb` int(11) DEFAULT 0,
  `sp_5gb` int(11) DEFAULT 0,
  `sp_7gb` int(11) DEFAULT 0,
  `sp_100gb` int(11) DEFAULT 0,
  `fwa` int(11) DEFAULT 0,
  `fwa_5g` int(11) DEFAULT 0,
  `sp_existing` int(11) DEFAULT 0,
  `hit_haji_umroh` int(11) DEFAULT 0,
  `jumlah_qsc` int(11) DEFAULT NULL,
  `benefit_sp` decimal(15,2) DEFAULT 0.00,
  `benefit_fwa` decimal(15,2) DEFAULT 0.00,
  `jumlah_audience` int(11) NOT NULL DEFAULT 0,
  `reload` int(11) NOT NULL DEFAULT 0,
  `mobo_paket` int(11) NOT NULL DEFAULT 0,
  `cost` decimal(15,2) DEFAULT 0.00,
  `benefit_total` decimal(15,2) DEFAULT 0.00,
  `ratio_cost_benefit` decimal(5,2) DEFAULT 0.00,
  `alasan` text DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `provider_digunakan` varchar(100) DEFAULT NULL,
  `provider_terbaik` varchar(100) DEFAULT NULL,
  `kenal_im3` enum('Ya','Tidak') DEFAULT NULL,
  `sudah_beli_im3` enum('Ya','Tidak') DEFAULT NULL,
  `lokasi_beli` varchar(255) DEFAULT NULL,
  `tertarik_beli_im3` enum('Ya','Tidak') DEFAULT NULL,
  PRIMARY KEY (`unique_id`),
  KEY `user_id` (`user_id`),
  KEY `site_id` (`site_id`),
  KEY `kategori_event_id` (`kategori_event_id`),
  CONSTRAINT `event_submissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `event_submissions_ibfk_2` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`),
  CONSTRAINT `event_submissions_ibfk_3` FOREIGN KEY (`kategori_event_id`) REFERENCES `event_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13514 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `marpro_allocations`
--

DROP TABLE IF EXISTS `marpro_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marpro_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receive_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `micro_cluster_id` int(11) NOT NULL,
  `tanggal_alokasi` date NOT NULL,
  `qty_pcs` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `photo_url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `receive_id` (`receive_id`),
  KEY `micro_cluster_id` (`micro_cluster_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `marpro_allocations_ibfk_1` FOREIGN KEY (`receive_id`) REFERENCES `marpro_receives` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marpro_allocations_ibfk_2` FOREIGN KEY (`micro_cluster_id`) REFERENCES `micro_clusters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marpro_allocations_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `marpro_receives`
--

DROP TABLE IF EXISTS `marpro_receives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marpro_receives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `type_name` varchar(255) NOT NULL,
  `tanggal_terima` date NOT NULL,
  `qty_branch` int(11) NOT NULL,
  `qty_allocated` int(11) DEFAULT 0,
  `diterima_siapa` varchar(255) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `photo_url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `qty_admin` int(11) DEFAULT NULL,
  `qty_gap` int(11) DEFAULT NULL,
  `status_receive` varchar(50) DEFAULT 'Received',
  `tanggal_kirim` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `marpro_receives_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marpro_receives_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `matpro_activities`
--

DROP TABLE IF EXISTS `matpro_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matpro_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unique_id` varchar(255) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_datetime` datetime DEFAULT current_timestamp(),
  `location_latitude` decimal(10,8) NOT NULL,
  `location_longitude` decimal(11,8) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `micro_cluster_id` int(11) DEFAULT NULL,
  `site_id` int(11) NOT NULL,
  `outlet_id` int(11) DEFAULT NULL,
  `outlet_snapshot_name` varchar(255) DEFAULT NULL COMMENT 'Simpan nama outlet saat input',
  `project_name` varchar(255) DEFAULT NULL,
  `type_name` varchar(255) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `type_id` int(11) DEFAULT NULL,
  `qty_used` int(11) NOT NULL DEFAULT 1,
  `photo_before_url` varchar(255) NOT NULL,
  `photo_after_url` varchar(255) NOT NULL,
  `deletion_status` enum('none','pending','rejected') NOT NULL DEFAULT 'none' COMMENT 'Status permintaan hapus oleh user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_id` (`unique_id`),
  KEY `project_id` (`project_id`),
  KEY `type_id` (`type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=85310 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `matpro_edit_requests`
--

DROP TABLE IF EXISTS `matpro_edit_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matpro_edit_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `requested_columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`requested_columns`)),
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `matpro_projects`
--

DROP TABLE IF EXISTS `matpro_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matpro_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_name` varchar(255) NOT NULL,
  `brand` varchar(10) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_name` (`project_name`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `matpro_stocks`
--

DROP TABLE IF EXISTS `matpro_stocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matpro_stocks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'ID Pengguna yang dialokasikan stok ini (bisa NULL jika stok umum)',
  `project_name` varchar(255) NOT NULL COMMENT 'Nama Proyek Matpro',
  `project_brand` varchar(10) NOT NULL COMMENT 'Brand Proyek (IM3/3ID/BOTH)',
  `type_name` varchar(255) NOT NULL COMMENT 'Nama Jenis Matpro',
  `branch_id` int(11) NOT NULL COMMENT 'ID Branch lokasi stok',
  `micro_cluster_id` int(11) DEFAULT NULL COMMENT 'ID Micro Cluster lokasi stok (NULL jika stok level Branch)',
  `stock_quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Jumlah stok yang tersedia',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Status stok: 1 = Aktif, 0 = Tidak Aktif',
  `last_updated_by` int(11) DEFAULT NULL COMMENT 'ID Pengguna terakhir yang memperbarui stok ini',
  `last_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Waktu terakhir stok diperbarui',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_projname_typename_branch_mc` (`user_id`,`project_name`,`type_name`,`branch_id`,`micro_cluster_id`),
  KEY `branch_id` (`branch_id`),
  KEY `micro_cluster_id` (`micro_cluster_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_matpro_stocks_level` (`branch_id`,`micro_cluster_id`),
  KEY `project_name` (`project_name`),
  KEY `type_name` (`type_name`),
  KEY `fk_matpro_stocks_updated_by_denorm` (`last_updated_by`),
  CONSTRAINT `fk_matpro_stocks_branch_denorm` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_matpro_stocks_mc_denorm` FOREIGN KEY (`micro_cluster_id`) REFERENCES `micro_clusters` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_matpro_stocks_updated_by_denorm` FOREIGN KEY (`last_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_matpro_stocks_user_denorm` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3929 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `matpro_types`
--

DROP TABLE IF EXISTS `matpro_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matpro_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `type_name` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_project_type` (`project_id`,`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `micro_clusters`
--

DROP TABLE IF EXISTS `micro_clusters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `micro_clusters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `nama_micro_cluster` varchar(255) NOT NULL,
  `brand` enum('IM3','3ID') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  CONSTRAINT `micro_clusters_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12894 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `msisdn_data`
--

DROP TABLE IF EXISTS `msisdn_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `msisdn_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `submission_id` int(11) NOT NULL,
  `msisdn` varchar(20) NOT NULL,
  `type` enum('existing','new') NOT NULL DEFAULT 'new',
  PRIMARY KEY (`id`),
  UNIQUE KEY `msisdn` (`msisdn`),
  KEY `submission_id` (`submission_id`),
  CONSTRAINT `msisdn_data_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `event_submissions` (`unique_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=84739 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `outlets`
--

DROP TABLE IF EXISTS `outlets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `outlets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_outlet` varchar(255) NOT NULL,
  `nama_outlet` varchar(255) NOT NULL,
  `Id_Outlet_Nama_Outlet` varchar(255) NOT NULL,
  `site_id` int(11) DEFAULT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_outlet_2` (`id_outlet`,`site_id`),
  UNIQUE KEY `unique_outlet_brand` (`id_outlet`,`brand`),
  KEY `site_id` (`site_id`),
  CONSTRAINT `outlets_ibfk_1` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=266418 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sites`
--

DROP TABLE IF EXISTS `sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` varchar(100) NOT NULL,
  `site_name` varchar(255) NOT NULL,
  `brand` enum('IM3','3ID') NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `micro_cluster_id` int(11) DEFAULT NULL,
  `area` varchar(150) DEFAULT NULL,
  `kabupaten` varchar(150) DEFAULT NULL,
  `kecamatan` varchar(150) DEFAULT NULL,
  `region` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_id` (`site_id`),
  KEY `branch_id` (`branch_id`),
  KEY `micro_cluster_id` (`micro_cluster_id`),
  CONSTRAINT `sites_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `sites_ibfk_2` FOREIGN KEY (`micro_cluster_id`) REFERENCES `micro_clusters` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=38647 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_micro_clusters`
--

DROP TABLE IF EXISTS `user_micro_clusters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_micro_clusters` (
  `user_id` int(11) NOT NULL,
  `micro_cluster_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`micro_cluster_id`),
  KEY `micro_cluster_id` (`micro_cluster_id`),
  CONSTRAINT `user_micro_clusters_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_micro_clusters_ibfk_2` FOREIGN KEY (`micro_cluster_id`) REFERENCES `micro_clusters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user','dsf') NOT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = User must change password on next login',
  `last_login` timestamp NULL DEFAULT NULL,
  `has_seen_guide` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = Belum lihat, 1 = Sudah lihat',
  `brand` enum('IM3','3ID') DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `first_login` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `branch_id` (`branch_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=256 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-24  8:27:01
