-- MySQL dump 10.13  Distrib 8.0.30, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: soul_coffee
-- ------------------------------------------------------
-- Server version	8.0.30

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
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint unsigned DEFAULT NULL,
  `actor_role` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `before_json` json DEFAULT NULL,
  `after_json` json DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `audit_log_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `audit_log_actor_id_index` (`actor_id`),
  CONSTRAINT `audit_log_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `carts`
--

DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plate` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `kitchen_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `carts_code_unique` (`code`),
  KEY `carts_kitchen_id_foreign` (`kitchen_id`),
  KEY `carts_status_index` (`status`),
  CONSTRAINT `carts_kitchen_id_foreign` FOREIGN KEY (`kitchen_id`) REFERENCES `central_kitchens` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `carts`
--

LOCK TABLES `carts` WRITE;
/*!40000 ALTER TABLE `carts` DISABLE KEYS */;
INSERT INTO `carts` VALUES (1,'0018',NULL,'active',1,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(2,'0019',NULL,'active',1,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(3,'0020',NULL,'active',1,'2026-09-01 09:12:23','2026-09-01 09:12:23');
/*!40000 ALTER TABLE `carts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `central_kitchens`
--

DROP TABLE IF EXISTS `central_kitchens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `central_kitchens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `open_at` time NOT NULL,
  `close_at` time NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `central_kitchens`
--

LOCK TABLES `central_kitchens` WRITE;
/*!40000 ALTER TABLE `central_kitchens` DISABLE KEYS */;
INSERT INTO `central_kitchens` VALUES (1,'Dapur Pusat Cempaka Putih','Jl. Pramuka Kav 56, Cempaka Putih, Jakarta Pusat','06:00:00','21:00:00',1,'2026-09-01 09:12:23','2026-09-01 09:12:23');
/*!40000 ALTER TABLE `central_kitchens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `daily_allocation_lines`
--

DROP TABLE IF EXISTS `daily_allocation_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_allocation_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `allocation_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `target_qty` int unsigned NOT NULL,
  `qty_issued` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_allocation_lines_allocation_id_product_id_unique` (`allocation_id`,`product_id`),
  KEY `daily_allocation_lines_product_id_foreign` (`product_id`),
  CONSTRAINT `daily_allocation_lines_allocation_id_foreign` FOREIGN KEY (`allocation_id`) REFERENCES `daily_allocations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_allocation_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `daily_allocation_lines`
--

LOCK TABLES `daily_allocation_lines` WRITE;
/*!40000 ALTER TABLE `daily_allocation_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `daily_allocation_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `daily_allocations`
--

DROP TABLE IF EXISTS `daily_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operating_date` date NOT NULL,
  `cart_id` bigint unsigned NOT NULL,
  `staff_id` bigint unsigned NOT NULL,
  `kitchen_id` bigint unsigned NOT NULL,
  `barista_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_correction` tinyint(1) NOT NULL DEFAULT '0',
  `correction_reason` text COLLATE utf8mb4_unicode_ci,
  `over_target_pct` int unsigned NOT NULL DEFAULT '0',
  `finance_approval_id` bigint unsigned DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `daily_allocations_staff_id_foreign` (`staff_id`),
  KEY `daily_allocations_barista_id_foreign` (`barista_id`),
  KEY `daily_allocations_location_id_foreign` (`location_id`),
  KEY `daily_allocations_finance_approval_id_foreign` (`finance_approval_id`),
  KEY `daily_allocations_cart_id_operating_date_index` (`cart_id`,`operating_date`),
  KEY `daily_allocations_kitchen_id_operating_date_index` (`kitchen_id`,`operating_date`),
  CONSTRAINT `daily_allocations_barista_id_foreign` FOREIGN KEY (`barista_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `daily_allocations_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `daily_allocations_finance_approval_id_foreign` FOREIGN KEY (`finance_approval_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_allocations_kitchen_id_foreign` FOREIGN KEY (`kitchen_id`) REFERENCES `central_kitchens` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `daily_allocations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `daily_allocations_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `daily_allocations`
--

LOCK TABLES `daily_allocations` WRITE;
/*!40000 ALTER TABLE `daily_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `daily_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `daily_targets`
--

DROP TABLE IF EXISTS `daily_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_targets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` bigint unsigned DEFAULT NULL,
  `location_id` bigint unsigned DEFAULT NULL,
  `product_id` bigint unsigned NOT NULL,
  `target_qty` int unsigned NOT NULL,
  `weekday` tinyint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `daily_targets_product_id_foreign` (`product_id`),
  KEY `daily_targets_cart_id_weekday_index` (`cart_id`,`weekday`),
  KEY `daily_targets_location_id_weekday_index` (`location_id`,`weekday`),
  CONSTRAINT `daily_targets_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_targets_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_targets_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `daily_targets`
--

LOCK TABLES `daily_targets` WRITE;
/*!40000 ALTER TABLE `daily_targets` DISABLE KEYS */;
INSERT INTO `daily_targets` VALUES (1,1,NULL,1,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(2,1,NULL,2,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(3,1,NULL,3,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(4,1,NULL,4,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(5,1,NULL,5,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(6,1,NULL,6,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(7,1,NULL,7,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(8,1,NULL,8,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(9,1,NULL,9,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(10,1,NULL,10,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(11,2,NULL,1,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(12,2,NULL,2,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(13,2,NULL,3,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(14,2,NULL,4,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(15,2,NULL,5,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(16,2,NULL,6,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(17,2,NULL,7,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(18,2,NULL,8,5,NULL,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(19,2,NULL,9,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(20,2,NULL,10,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(21,3,NULL,1,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(22,3,NULL,2,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(23,3,NULL,3,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(24,3,NULL,4,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(25,3,NULL,5,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(26,3,NULL,6,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(27,3,NULL,7,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(28,3,NULL,8,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(29,3,NULL,9,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26'),(30,3,NULL,10,5,NULL,'2026-09-01 09:12:26','2026-09-01 09:12:26');
/*!40000 ALTER TABLE `daily_targets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `locations`
--

DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `geofence_m` int unsigned NOT NULL DEFAULT '100',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `locations`
--

LOCK TABLES `locations` WRITE;
/*!40000 ALTER TABLE `locations` DISABLE KEYS */;
INSERT INTO `locations` VALUES (1,'Sudirman',-6.2088000,106.8228000,100,NULL,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(2,'Thamrin',-6.1944000,106.8229000,100,NULL,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(3,'Kemang',-6.2607000,106.8133000,100,NULL,'2026-09-01 09:12:23','2026-09-01 09:12:23');
/*!40000 ALTER TABLE `locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `media`
--

DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bytes` bigint unsigned NOT NULL,
  `sha256` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exif_taken_at` timestamp NULL DEFAULT NULL,
  `uploaded_by` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `media_uploaded_by_foreign` (`uploaded_by`),
  KEY `media_kind_sha256_index` (`kind`,`sha256`),
  CONSTRAINT `media_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `media`
--

LOCK TABLES `media` WRITE;
/*!40000 ALTER TABLE `media` DISABLE KEYS */;
/*!40000 ALTER TABLE `media` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_09_01_154007_create_personal_access_tokens_table',1),(5,'2026_09_01_160000_create_central_kitchens_table',1),(6,'2026_09_01_160010_create_carts_table',1),(7,'2026_09_01_160020_create_locations_table',1),(8,'2026_09_01_160030_add_kitchen_fk_to_users_table',1),(9,'2026_09_01_160040_create_staff_assignments_table',1),(10,'2026_09_01_160050_create_products_table',1),(11,'2026_09_01_160060_create_product_price_versions_table',1),(12,'2026_09_01_160070_create_daily_targets_table',1),(13,'2026_09_01_160080_create_daily_allocations_table',1),(14,'2026_09_01_160090_create_daily_allocation_lines_table',1),(15,'2026_09_01_160100_create_media_table',1),(16,'2026_09_01_160110_create_refill_requests_table',1),(17,'2026_09_01_160120_create_refill_request_lines_table',1),(18,'2026_09_01_160130_create_refill_status_history_table',1),(19,'2026_09_01_160140_create_stock_ledger_table',1),(20,'2026_09_01_160150_create_settlements_table',1),(21,'2026_09_01_160160_create_settlement_lines_table',1),(22,'2026_09_01_160170_create_notifications_table',1),(23,'2026_09_01_160180_create_audit_log_table',1),(24,'2026_09_01_160190_create_outbox_events_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notifications_user_id_event_id_unique` (`user_id`,`event_id`),
  KEY `notifications_user_id_read_at_index` (`user_id`,`read_at`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `outbox_events`
--

DROP TABLE IF EXISTS `outbox_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `outbox_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json NOT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `outbox_events_event_id_unique` (`event_id`),
  KEY `outbox_events_published_at_index` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `outbox_events`
--

LOCK TABLES `outbox_events` WRITE;
/*!40000 ALTER TABLE `outbox_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `outbox_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_price_versions`
--

DROP TABLE IF EXISTS `product_price_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_price_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `cost_price_minor` bigint unsigned NOT NULL,
  `sell_price_minor` bigint unsigned NOT NULL,
  `effective_from` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_price_versions_product_id_effective_from_index` (`product_id`,`effective_from`),
  CONSTRAINT `product_price_versions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_price_versions`
--

LOCK TABLES `product_price_versions` WRITE;
/*!40000 ALTER TABLE `product_price_versions` DISABLE KEYS */;
INSERT INTO `product_price_versions` VALUES (1,1,8000,20000,'2026-09-01 09:12:23','2026-09-01 09:12:23','2026-09-01 09:12:23'),(2,2,8800,22000,'2026-09-01 09:12:23','2026-09-01 09:12:23','2026-09-01 09:12:23'),(3,3,8000,20000,'2026-09-01 09:12:23','2026-09-01 09:12:23','2026-09-01 09:12:23'),(4,4,7600,19000,'2026-09-01 09:12:23','2026-09-01 09:12:23','2026-09-01 09:12:23'),(5,5,9200,23000,'2026-09-01 09:12:24','2026-09-01 09:12:24','2026-09-01 09:12:24'),(6,6,8400,21000,'2026-09-01 09:12:24','2026-09-01 09:12:24','2026-09-01 09:12:24'),(7,7,10000,25000,'2026-09-01 09:12:24','2026-09-01 09:12:24','2026-09-01 09:12:24'),(8,8,9600,24000,'2026-09-01 09:12:24','2026-09-01 09:12:24','2026-09-01 09:12:24'),(9,9,8800,22000,'2026-09-01 09:12:24','2026-09-01 09:12:24','2026-09-01 09:12:24'),(10,10,7200,18000,'2026-09-01 09:12:24','2026-09-01 09:12:24','2026-09-01 09:12:24'),(11,11,2000,0,'2026-09-01 09:12:24','2026-09-01 09:12:24','2026-09-01 09:12:24');
/*!40000 ALTER TABLE `product_price_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_sellable` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_code_unique` (`code`),
  KEY `products_sort_order_index` (`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'SOUL-COFFEE','Soul Coffee','cup',1,1,1,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(2,'CYTRUS-COLD-BREW','Cytrus Cold Brew','cup',1,2,1,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(3,'THAITEA','Thaitea','cup',1,3,1,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(4,'KOPSU','Kopsu','cup',1,4,1,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(5,'PASSION-COLDBREW','Passion Coldbrew','cup',1,5,1,'2026-09-01 09:12:23','2026-09-01 09:12:23'),(6,'SOUL-LATTE','Soul Latte','cup',1,6,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(7,'BUTTERSCOTCH-SEASALT-CREAM','Butterscotch SeaSalt Cream','cup',1,7,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(8,'SOUL-MATCHA','Soul Matcha','cup',1,8,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(9,'SOUL-CHOCOLATE','Soul Chocolate','cup',1,9,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(10,'LECHEE-TEA','Lechee Tea','cup',1,10,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(11,'ES-BATU','ES BATU','pack',0,11,1,'2026-09-01 09:12:24','2026-09-01 09:12:24');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refill_request_lines`
--

DROP TABLE IF EXISTS `refill_request_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `refill_request_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `refill_request_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `qty_requested` int unsigned NOT NULL,
  `qty_approved` int unsigned DEFAULT NULL,
  `qty_prepared` int unsigned DEFAULT NULL,
  `qty_received` int unsigned DEFAULT NULL,
  `unit_cost_minor` bigint unsigned NOT NULL,
  `line_cost_minor` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `refill_request_lines_refill_request_id_product_id_unique` (`refill_request_id`,`product_id`),
  KEY `refill_request_lines_product_id_foreign` (`product_id`),
  CONSTRAINT `refill_request_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `refill_request_lines_refill_request_id_foreign` FOREIGN KEY (`refill_request_id`) REFERENCES `refill_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refill_request_lines`
--

LOCK TABLES `refill_request_lines` WRITE;
/*!40000 ALTER TABLE `refill_request_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `refill_request_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refill_requests`
--

DROP TABLE IF EXISTS `refill_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `refill_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `operating_date` date NOT NULL,
  `cart_id` bigint unsigned NOT NULL,
  `staff_id` bigint unsigned NOT NULL,
  `kitchen_id` bigint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '0',
  `evidence_photo_id` bigint unsigned NOT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `gps_unavailable` tinyint(1) NOT NULL DEFAULT '0',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `finance_id` bigint unsigned DEFAULT NULL,
  `decision_reason` text COLLATE utf8mb4_unicode_ci,
  `barista_id` bigint unsigned DEFAULT NULL,
  `prepared_at` timestamp NULL DEFAULT NULL,
  `shortfall_reason` text COLLATE utf8mb4_unicode_ci,
  `rider_id` bigint unsigned DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `signature_id` bigint unsigned DEFAULT NULL,
  `signature_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_cost_minor` bigint unsigned NOT NULL DEFAULT '0',
  `price_version_id` bigint unsigned DEFAULT NULL,
  `out_of_hours` tinyint(1) NOT NULL DEFAULT '0',
  `client_submitted_at` timestamp NULL DEFAULT NULL,
  `device_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active_cart_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `refill_requests_uuid_unique` (`uuid`),
  UNIQUE KEY `refill_requests_code_unique` (`code`),
  UNIQUE KEY `refill_requests_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `refill_requests_active_cart_id_unique` (`active_cart_id`),
  KEY `refill_requests_staff_id_foreign` (`staff_id`),
  KEY `refill_requests_evidence_photo_id_foreign` (`evidence_photo_id`),
  KEY `refill_requests_finance_id_foreign` (`finance_id`),
  KEY `refill_requests_barista_id_foreign` (`barista_id`),
  KEY `refill_requests_rider_id_foreign` (`rider_id`),
  KEY `refill_requests_signature_id_foreign` (`signature_id`),
  KEY `refill_requests_kitchen_id_status_updated_at_index` (`kitchen_id`,`status`,`updated_at`),
  KEY `refill_requests_cart_id_status_updated_at_index` (`cart_id`,`status`,`updated_at`),
  KEY `refill_requests_operating_date_cart_id_index` (`operating_date`,`cart_id`),
  CONSTRAINT `refill_requests_barista_id_foreign` FOREIGN KEY (`barista_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refill_requests_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `refill_requests_evidence_photo_id_foreign` FOREIGN KEY (`evidence_photo_id`) REFERENCES `media` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `refill_requests_finance_id_foreign` FOREIGN KEY (`finance_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refill_requests_kitchen_id_foreign` FOREIGN KEY (`kitchen_id`) REFERENCES `central_kitchens` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `refill_requests_rider_id_foreign` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refill_requests_signature_id_foreign` FOREIGN KEY (`signature_id`) REFERENCES `media` (`id`) ON DELETE SET NULL,
  CONSTRAINT `refill_requests_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refill_requests`
--

LOCK TABLES `refill_requests` WRITE;
/*!40000 ALTER TABLE `refill_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `refill_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `refill_status_history`
--

DROP TABLE IF EXISTS `refill_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `refill_status_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `refill_request_id` bigint unsigned NOT NULL,
  `from_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `actor_role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `device_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `refill_status_history_actor_id_foreign` (`actor_id`),
  KEY `refill_status_history_refill_request_id_created_at_index` (`refill_request_id`,`created_at`),
  CONSTRAINT `refill_status_history_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `refill_status_history_refill_request_id_foreign` FOREIGN KEY (`refill_request_id`) REFERENCES `refill_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `refill_status_history`
--

LOCK TABLES `refill_status_history` WRITE;
/*!40000 ALTER TABLE `refill_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `refill_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settlement_lines`
--

DROP TABLE IF EXISTS `settlement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settlement_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `settlement_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `qty_issued` int unsigned NOT NULL,
  `qty_sold` int unsigned NOT NULL,
  `qty_remaining` int unsigned NOT NULL,
  `qty_wasted` int unsigned NOT NULL DEFAULT '0',
  `variance_qty` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settlement_lines_settlement_id_product_id_unique` (`settlement_id`,`product_id`),
  KEY `settlement_lines_product_id_foreign` (`product_id`),
  CONSTRAINT `settlement_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `settlement_lines_settlement_id_foreign` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settlement_lines`
--

LOCK TABLES `settlement_lines` WRITE;
/*!40000 ALTER TABLE `settlement_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `settlement_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settlements`
--

DROP TABLE IF EXISTS `settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operating_date` date NOT NULL,
  `cart_id` bigint unsigned NOT NULL,
  `staff_id` bigint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'SUBMITTED',
  `cash_minor` bigint unsigned NOT NULL DEFAULT '0',
  `qris_minor` bigint unsigned NOT NULL DEFAULT '0',
  `transfer_minor` bigint unsigned NOT NULL DEFAULT '0',
  `declared_total_minor` bigint unsigned NOT NULL DEFAULT '0',
  `expected_total_minor` bigint unsigned NOT NULL DEFAULT '0',
  `variance_minor` bigint NOT NULL DEFAULT '0',
  `variance_reason` text COLLATE utf8mb4_unicode_ci,
  `reconciled_by` bigint unsigned DEFAULT NULL,
  `reconciled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settlements_cart_id_operating_date_unique` (`cart_id`,`operating_date`),
  KEY `settlements_staff_id_foreign` (`staff_id`),
  KEY `settlements_reconciled_by_foreign` (`reconciled_by`),
  CONSTRAINT `settlements_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `settlements_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `settlements_staff_id_foreign` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settlements`
--

LOCK TABLES `settlements` WRITE;
/*!40000 ALTER TABLE `settlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_assignments`
--

DROP TABLE IF EXISTS `staff_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `cart_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `operating_date` date NOT NULL,
  `assigned_by` bigint unsigned NOT NULL,
  `kitchen_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_assignments_user_id_operating_date_unique` (`user_id`,`operating_date`),
  UNIQUE KEY `staff_assignments_cart_id_operating_date_unique` (`cart_id`,`operating_date`),
  KEY `staff_assignments_location_id_foreign` (`location_id`),
  KEY `staff_assignments_assigned_by_foreign` (`assigned_by`),
  KEY `staff_assignments_kitchen_id_foreign` (`kitchen_id`),
  CONSTRAINT `staff_assignments_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `staff_assignments_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_assignments_kitchen_id_foreign` FOREIGN KEY (`kitchen_id`) REFERENCES `central_kitchens` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_assignments_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `staff_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_assignments`
--

LOCK TABLES `staff_assignments` WRITE;
/*!40000 ALTER TABLE `staff_assignments` DISABLE KEYS */;
INSERT INTO `staff_assignments` VALUES (1,5,1,1,'2026-09-01',1,1,'2026-09-01 09:12:25','2026-09-01 09:12:25');
/*!40000 ALTER TABLE `staff_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_ledger`
--

DROP TABLE IF EXISTS `stock_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `location_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `movement_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty_delta` int NOT NULL,
  `ref_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ref_id` bigint unsigned DEFAULT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `kitchen_id` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `stock_ledger_product_id_foreign` (`product_id`),
  KEY `stock_ledger_actor_id_foreign` (`actor_id`),
  KEY `stock_ledger_location_product_idx` (`location_type`,`location_id`,`product_id`,`created_at`),
  KEY `stock_ledger_kitchen_id_product_id_created_at_index` (`kitchen_id`,`product_id`,`created_at`),
  KEY `stock_ledger_ref_type_ref_id_index` (`ref_type`,`ref_id`),
  CONSTRAINT `stock_ledger_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_ledger_kitchen_id_foreign` FOREIGN KEY (`kitchen_id`) REFERENCES `central_kitchens` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_ledger_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_ledger`
--

LOCK TABLES `stock_ledger` WRITE;
/*!40000 ALTER TABLE `stock_ledger` DISABLE KEYS */;
INSERT INTO `stock_ledger` VALUES (1,'kitchen',1,1,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(2,'kitchen',1,2,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(3,'kitchen',1,3,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(4,'kitchen',1,4,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(5,'kitchen',1,5,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(6,'kitchen',1,6,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(7,'kitchen',1,7,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(8,'kitchen',1,8,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(9,'kitchen',1,9,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(10,'kitchen',1,10,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26'),(11,'kitchen',1,11,'PRODUCTION_IN',200,NULL,NULL,3,1,'2026-09-01 09:12:26');
/*!40000 ALTER TABLE `stock_ledger` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_e164` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kitchen_id` bigint unsigned DEFAULT NULL,
  `pin_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_phone_e164_unique` (`phone_e164`),
  KEY `users_kitchen_id_index` (`kitchen_id`),
  KEY `users_role_index` (`role`),
  CONSTRAINT `users_kitchen_id_foreign` FOREIGN KEY (`kitchen_id`) REFERENCES `central_kitchens` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Rizal Admin','+6281100000001','$2y$12$vdbX8VOiF5WZ8ehFyPX5x.kdeVJv15K0hdD96hyxu5nqjA7m3/gqC','ADMINISTRATOR',NULL,NULL,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(2,'Sari Finance','+6281100000002','$2y$12$qKNLFHe5HDgkQifpJaVGOu3h3oaRcjothfs7HV0mbbe8z4LK2DwTa','FINANCE',NULL,NULL,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(3,'Dimas Barista','+6281100000003','$2y$12$SNrJvMUmE5qWol.zDsGemetmNMg5FOJckX3sBDjainkYrHy6sgGFG','BARISTA',1,NULL,1,'2026-09-01 09:12:24','2026-09-01 09:12:24'),(4,'Agus Rider','+6281100000004','$2y$12$ZdHRtqbtFzd379RIuwU7QeOToK2/rTmQX5SQmGfvREj054mkwz0mG','RIDER',NULL,NULL,1,'2026-09-01 09:12:25','2026-09-01 09:12:25'),(5,'Maufu','+6281100000005','$2y$12$a3eeswlaSZS8TNjldNgNk.SzwmnizaUpigJgmzDC4aylpIwXDfIeu','STAFF',NULL,'$2y$12$YtNjTmgvEdHDpGCRo.Cn2uQkPYB7aVaEO91Ii.m4.xQ2bPYnDk/Ry',1,'2026-09-01 09:12:25','2026-09-01 09:12:25');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-01 23:18:24
