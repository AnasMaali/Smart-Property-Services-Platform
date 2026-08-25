-- MySQL dump 10.13  Distrib 8.0.46, for Win64 (x86_64)
--
-- Host: localhost    Database: blue_db
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admin_audit_logs`
--

DROP TABLE IF EXISTS `admin_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_audit_logs` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `admin_user_id` binary(16) NOT NULL,
  `action_code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_type` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_identifier` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `action_description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT '1',
  `failure_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `request_trace_id` binary(16) DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_admin_audit_logs_admin_time` (`admin_user_id`,`created_at`),
  KEY `idx_admin_audit_logs_action_time` (`action_code`,`created_at`),
  KEY `idx_admin_audit_logs_entity` (`entity_type`,`entity_identifier`,`created_at`),
  KEY `idx_admin_audit_logs_request_trace` (`request_trace_id`),
  KEY `idx_admin_audit_logs_success_time` (`was_successful`,`created_at`),
  KEY `idx_admin_audit_logs_created_at` (`created_at`),
  CONSTRAINT `fk_admin_audit_logs_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_admin_audit_logs_action_code` CHECK (((char_length(trim(`action_code`)) between 2 and 80) and regexp_like(`action_code`,_utf8mb4'^[A-Z][A-Z0-9_]*$'))),
  CONSTRAINT `chk_admin_audit_logs_description` CHECK (((`action_description` is null) or (char_length(trim(`action_description`)) between 2 and 500))),
  CONSTRAINT `chk_admin_audit_logs_entity_identifier` CHECK (((`entity_identifier` is null) or (char_length(trim(`entity_identifier`)) between 1 and 191))),
  CONSTRAINT `chk_admin_audit_logs_entity_type` CHECK (((char_length(trim(`entity_type`)) between 2 and 80) and regexp_like(`entity_type`,_utf8mb4'^[A-Z][A-Z0-9_]*$'))),
  CONSTRAINT `chk_admin_audit_logs_failure_data` CHECK ((((`was_successful` = true) and (`failure_reason` is null)) or ((`was_successful` = false) and (`failure_reason` is not null)))),
  CONSTRAINT `chk_admin_audit_logs_failure_reason` CHECK (((`failure_reason` is null) or (char_length(trim(`failure_reason`)) between 2 and 500))),
  CONSTRAINT `chk_admin_audit_logs_success` CHECK ((`was_successful` in (0,1))),
  CONSTRAINT `chk_admin_audit_logs_user_agent` CHECK (((`user_agent` is null) or (char_length(trim(`user_agent`)) between 2 and 512)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_audit_logs`
--

LOCK TABLES `admin_audit_logs` WRITE;
/*!40000 ALTER TABLE `admin_audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_permissions`
--

DROP TABLE IF EXISTS `admin_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_permissions` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_permissions_code` (`code`),
  KEY `idx_admin_permissions_active` (`is_active`),
  CONSTRAINT `chk_admin_permissions_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_admin_permissions_code` CHECK (((char_length(trim(`code`)) between 3 and 80) and regexp_like(`code`,_utf8mb4'^[a-z][a-z0-9_]*([.][a-z][a-z0-9_]*)+$'))),
  CONSTRAINT `chk_admin_permissions_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_admin_permissions_name` CHECK ((char_length(trim(`name`)) between 2 and 150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_permissions`
--

LOCK TABLES `admin_permissions` WRITE;
/*!40000 ALTER TABLE `admin_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_role_permissions`
--

DROP TABLE IF EXISTS `admin_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_role_permissions` (
  `role_id` smallint unsigned NOT NULL,
  `permission_id` smallint unsigned NOT NULL,
  `granted_by_user_id` binary(16) DEFAULT NULL,
  `granted_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_admin_role_permissions_permission_id` (`permission_id`),
  KEY `idx_admin_role_permissions_granted_by` (`granted_by_user_id`),
  CONSTRAINT `fk_admin_role_permissions_granted_by` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `admin_permissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_role_permissions`
--

LOCK TABLES `admin_role_permissions` WRITE;
/*!40000 ALTER TABLE `admin_role_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_webauthn_challenge_purposes`
--

DROP TABLE IF EXISTS `admin_webauthn_challenge_purposes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_webauthn_challenge_purposes` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_webauthn_challenge_purposes_code` (`code`),
  CONSTRAINT `chk_admin_webauthn_challenge_purposes_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_admin_webauthn_challenge_purposes_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_admin_webauthn_challenge_purposes_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_webauthn_challenge_purposes`
--

LOCK TABLES `admin_webauthn_challenge_purposes` WRITE;
/*!40000 ALTER TABLE `admin_webauthn_challenge_purposes` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_webauthn_challenge_purposes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_webauthn_challenges`
--

DROP TABLE IF EXISTS `admin_webauthn_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_webauthn_challenges` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `purpose_id` tinyint unsigned NOT NULL,
  `challenge_hash` binary(32) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `consumed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_webauthn_challenges_challenge_hash` (`challenge_hash`),
  KEY `idx_admin_webauthn_challenges_user_purpose` (`user_id`,`purpose_id`,`created_at`),
  KEY `idx_admin_webauthn_challenges_purpose` (`purpose_id`),
  KEY `idx_admin_webauthn_challenges_expires_at` (`expires_at`),
  KEY `idx_admin_webauthn_challenges_active` (`consumed_at`,`expires_at`),
  CONSTRAINT `fk_admin_webauthn_challenges_purpose` FOREIGN KEY (`purpose_id`) REFERENCES `admin_webauthn_challenge_purposes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_webauthn_challenges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_admin_webauthn_challenges_consumed` CHECK (((`consumed_at` is null) or (`consumed_at` >= `created_at`))),
  CONSTRAINT `chk_admin_webauthn_challenges_expiration` CHECK ((`expires_at` > `created_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_webauthn_challenges`
--

LOCK TABLES `admin_webauthn_challenges` WRITE;
/*!40000 ALTER TABLE `admin_webauthn_challenges` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_webauthn_challenges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admin_webauthn_credentials`
--

DROP TABLE IF EXISTS `admin_webauthn_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_webauthn_credentials` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `credential_id` varbinary(1024) NOT NULL,
  `public_key` blob NOT NULL,
  `sign_count` int unsigned NOT NULL DEFAULT '0',
  `transports` json DEFAULT NULL,
  `aaguid` binary(16) DEFAULT NULL,
  `backup_eligible` tinyint(1) DEFAULT NULL,
  `backup_state` tinyint(1) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `revoked_by_user_id` binary(16) DEFAULT NULL,
  `revoke_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `last_used_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_webauthn_credentials_credential_id` (`credential_id`),
  KEY `idx_admin_webauthn_credentials_user` (`user_id`),
  KEY `idx_admin_webauthn_credentials_user_active` (`user_id`,`revoked_at`),
  KEY `idx_admin_webauthn_credentials_revoked_by` (`revoked_by_user_id`),
  CONSTRAINT `fk_admin_webauthn_credentials_revoked_by` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_webauthn_credentials_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_admin_webauthn_credentials_backup_eligible` CHECK (((`backup_eligible` is null) or (`backup_eligible` in (0,1)))),
  CONSTRAINT `chk_admin_webauthn_credentials_backup_state` CHECK (((`backup_state` is null) or (`backup_state` in (0,1)))),
  CONSTRAINT `chk_admin_webauthn_credentials_label` CHECK (((`label` is null) or (char_length(trim(`label`)) between 2 and 120))),
  CONSTRAINT `chk_admin_webauthn_credentials_last_used` CHECK (((`last_used_at` is null) or (`last_used_at` >= `created_at`))),
  CONSTRAINT `chk_admin_webauthn_credentials_revoke_consistency` CHECK ((((`revoked_at` is null) and (`revoke_reason` is null)) or ((`revoked_at` is not null) and (`revoke_reason` is not null)))),
  CONSTRAINT `chk_admin_webauthn_credentials_revoke_reason` CHECK (((`revoke_reason` is null) or (char_length(trim(`revoke_reason`)) between 2 and 500))),
  CONSTRAINT `chk_admin_webauthn_credentials_revoked_at` CHECK (((`revoked_at` is null) or (`revoked_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_webauthn_credentials`
--

LOCK TABLES `admin_webauthn_credentials` WRITE;
/*!40000 ALTER TABLE `admin_webauthn_credentials` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_webauthn_credentials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_time_windows`
--

DROP TABLE IF EXISTS `appointment_time_windows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_time_windows` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointment_time_windows_code` (`code`),
  KEY `idx_appointment_time_windows_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_appointment_time_windows_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_appointment_time_windows_code` CHECK ((char_length(trim(`code`)) between 2 and 60)),
  CONSTRAINT `chk_appointment_time_windows_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_appointment_time_windows_name` CHECK ((char_length(trim(`name`)) between 2 and 120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_time_windows`
--

LOCK TABLES `appointment_time_windows` WRITE;
/*!40000 ALTER TABLE `appointment_time_windows` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_time_windows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_slots`
--

DROP TABLE IF EXISTS `appointment_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_slots` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `starts_at` datetime(6) NOT NULL,
  `ends_at` datetime(6) NOT NULL,
  `booking_capacity` smallint unsigned NOT NULL DEFAULT '1',
  `time_window_id` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `internal_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointment_slots_period` (`starts_at`,`ends_at`),
  KEY `idx_appointment_slots_active_start` (`is_active`,`starts_at`),
  KEY `idx_appointment_slots_start_end` (`starts_at`,`ends_at`),
  KEY `idx_appointment_slots_time_window` (`time_window_id`),
  CONSTRAINT `fk_appointment_slots_time_window` FOREIGN KEY (`time_window_id`) REFERENCES `appointment_time_windows` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_appointment_slots_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_appointment_slots_capacity` CHECK ((`booking_capacity` between 1 and 10000)),
  CONSTRAINT `chk_appointment_slots_internal_note` CHECK (((`internal_note` is null) or (char_length(trim(`internal_note`)) between 2 and 500))),
  CONSTRAINT `chk_appointment_slots_period` CHECK ((`ends_at` > `starts_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_slots`
--

LOCK TABLES `appointment_slots` WRITE;
/*!40000 ALTER TABLE `appointment_slots` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_slots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_holds`
--

DROP TABLE IF EXISTS `appointment_holds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_holds` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `cart_id` binary(16) NOT NULL,
  `appointment_slot_id` binary(16) NOT NULL,
  `held_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `expires_at` datetime(6) NOT NULL,
  `released_at` datetime(6) DEFAULT NULL,
  `converted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_appointment_holds_cart_created_at` (`cart_id`,`created_at`),
  KEY `idx_appointment_holds_cart_current` (`cart_id`,`released_at`,`converted_at`,`expires_at`),
  KEY `idx_appointment_holds_slot_active` (`appointment_slot_id`,`released_at`,`converted_at`,`expires_at`),
  KEY `idx_appointment_holds_expires_at` (`expires_at`),
  KEY `idx_appointment_holds_converted_at` (`converted_at`),
  CONSTRAINT `fk_appointment_holds_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_appointment_holds_slot` FOREIGN KEY (`appointment_slot_id`) REFERENCES `appointment_slots` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_appointment_holds_converted_at` CHECK (((`converted_at` is null) or (`converted_at` >= `held_at`))),
  CONSTRAINT `chk_appointment_holds_expiration` CHECK ((`expires_at` > `held_at`)),
  CONSTRAINT `chk_appointment_holds_final_state` CHECK (((`released_at` is null) or (`converted_at` is null))),
  CONSTRAINT `chk_appointment_holds_released_at` CHECK (((`released_at` is null) or (`released_at` >= `held_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_holds`
--

LOCK TABLES `appointment_holds` WRITE;
/*!40000 ALTER TABLE `appointment_holds` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_holds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `areas`
--

DROP TABLE IF EXISTS `areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `areas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `city_id` int unsigned NOT NULL,
  `code` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_areas_city_code` (`city_id`,`code`),
  UNIQUE KEY `uq_areas_city_name` (`city_id`,`name`),
  KEY `idx_areas_city_id` (`city_id`),
  KEY `idx_areas_city_active_order` (`city_id`,`is_active`,`display_order`),
  CONSTRAINT `fk_areas_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_areas_code` CHECK ((char_length(trim(`code`)) between 2 and 60)),
  CONSTRAINT `chk_areas_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_areas_name` CHECK ((char_length(trim(`name`)) between 2 and 150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `areas`
--

LOCK TABLES `areas` WRITE;
/*!40000 ALTER TABLE `areas` DISABLE KEYS */;
/*!40000 ALTER TABLE `areas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_client_types`
--

DROP TABLE IF EXISTS `auth_client_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_client_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_client_types_code` (`code`),
  CONSTRAINT `chk_auth_client_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_auth_client_types_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_auth_client_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_client_types`
--

LOCK TABLES `auth_client_types` WRITE;
/*!40000 ALTER TABLE `auth_client_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_client_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_sessions`
--

DROP TABLE IF EXISTS `auth_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_sessions` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `client_type_id` tinyint unsigned NOT NULL,
  `refresh_token_hash` binary(32) NOT NULL,
  `device_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `app_version` varchar(30) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `last_used_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_sessions_refresh_token_hash` (`refresh_token_hash`),
  KEY `idx_auth_sessions_user` (`user_id`),
  KEY `idx_auth_sessions_client_type` (`client_type_id`),
  KEY `idx_auth_sessions_user_active` (`user_id`,`revoked_at`,`expires_at`),
  KEY `idx_auth_sessions_expires_at` (`expires_at`),
  CONSTRAINT `fk_auth_sessions_client_type` FOREIGN KEY (`client_type_id`) REFERENCES `auth_client_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_auth_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_auth_sessions_expiration` CHECK ((`expires_at` > `created_at`)),
  CONSTRAINT `chk_auth_sessions_last_used` CHECK (((`last_used_at` is null) or (`last_used_at` >= `created_at`))),
  CONSTRAINT `chk_auth_sessions_revoked` CHECK (((`revoked_at` is null) or (`revoked_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_sessions`
--

LOCK TABLES `auth_sessions` WRITE;
/*!40000 ALTER TABLE `auth_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_item_option_choice_selections`
--

DROP TABLE IF EXISTS `booking_item_option_choice_selections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_item_option_choice_selections` (
  `booking_item_id` binary(16) NOT NULL,
  `service_option_choice_id` binary(16) NOT NULL,
  `option_code_snapshot` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `option_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `option_type_code_snapshot` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `choice_code_snapshot` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `choice_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `choice_description_snapshot` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `additional_unit_amount_snapshot` decimal(19,6) NOT NULL DEFAULT '0.000000',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`booking_item_id`,`service_option_choice_id`),
  KEY `idx_booking_choice_selections_choice` (`service_option_choice_id`),
  CONSTRAINT `fk_booking_choice_selections_booking_item` FOREIGN KEY (`booking_item_id`) REFERENCES `booking_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_choice_selections_choice` FOREIGN KEY (`service_option_choice_id`) REFERENCES `service_option_choices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_choice_amount` CHECK ((`additional_unit_amount_snapshot` >= 0)),
  CONSTRAINT `chk_booking_choice_code` CHECK ((char_length(trim(`choice_code_snapshot`)) between 2 and 80)),
  CONSTRAINT `chk_booking_choice_description` CHECK (((`choice_description_snapshot` is null) or (char_length(trim(`choice_description_snapshot`)) between 2 and 500))),
  CONSTRAINT `chk_booking_choice_name` CHECK ((char_length(trim(`choice_name_snapshot`)) between 2 and 160)),
  CONSTRAINT `chk_booking_choice_option_code` CHECK ((char_length(trim(`option_code_snapshot`)) between 2 and 80)),
  CONSTRAINT `chk_booking_choice_option_name` CHECK ((char_length(trim(`option_name_snapshot`)) between 2 and 160)),
  CONSTRAINT `chk_booking_choice_option_type` CHECK ((`option_type_code_snapshot` in (_utf8mb4'SINGLE_SELECT',_utf8mb4'MULTI_SELECT')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_item_option_choice_selections`
--

LOCK TABLES `booking_item_option_choice_selections` WRITE;
/*!40000 ALTER TABLE `booking_item_option_choice_selections` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_item_option_choice_selections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_item_option_selections`
--

DROP TABLE IF EXISTS `booking_item_option_selections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_item_option_selections` (
  `booking_item_id` binary(16) NOT NULL,
  `service_option_id` binary(16) NOT NULL,
  `measurement_unit_id` smallint unsigned DEFAULT NULL,
  `option_code_snapshot` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `option_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `option_type_code_snapshot` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `numeric_value` decimal(19,6) DEFAULT NULL,
  `boolean_value` tinyint(1) DEFAULT NULL,
  `measurement_unit_code_snapshot` varchar(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `measurement_unit_name_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `measurement_unit_symbol_snapshot` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `additional_unit_amount_snapshot` decimal(19,6) NOT NULL DEFAULT '0.000000',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`booking_item_id`,`service_option_id`),
  KEY `idx_booking_option_selections_option` (`service_option_id`),
  KEY `idx_booking_option_selections_unit` (`measurement_unit_id`),
  CONSTRAINT `fk_booking_option_selections_booking_item` FOREIGN KEY (`booking_item_id`) REFERENCES `booking_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_option_selections_measurement_unit` FOREIGN KEY (`measurement_unit_id`) REFERENCES `measurement_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_option_selections_service_option` FOREIGN KEY (`service_option_id`) REFERENCES `service_options` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_option_selection_amount` CHECK ((`additional_unit_amount_snapshot` >= 0)),
  CONSTRAINT `chk_booking_option_selection_boolean` CHECK (((`boolean_value` is null) or (`boolean_value` in (0,1)))),
  CONSTRAINT `chk_booking_option_selection_code` CHECK ((char_length(trim(`option_code_snapshot`)) between 2 and 80)),
  CONSTRAINT `chk_booking_option_selection_name` CHECK ((char_length(trim(`option_name_snapshot`)) between 2 and 160)),
  CONSTRAINT `chk_booking_option_selection_numeric` CHECK (((`numeric_value` is null) or (`numeric_value` >= 0))),
  CONSTRAINT `chk_booking_option_selection_type` CHECK ((`option_type_code_snapshot` in (_utf8mb4'NUMBER',_utf8mb4'BOOLEAN'))),
  CONSTRAINT `chk_booking_option_selection_unit_code` CHECK (((`measurement_unit_code_snapshot` is null) or (char_length(trim(`measurement_unit_code_snapshot`)) between 1 and 50))),
  CONSTRAINT `chk_booking_option_selection_unit_name` CHECK (((`measurement_unit_name_snapshot` is null) or (char_length(trim(`measurement_unit_name_snapshot`)) between 1 and 100))),
  CONSTRAINT `chk_booking_option_selection_unit_reference` CHECK ((((`measurement_unit_id` is null) and (`measurement_unit_code_snapshot` is null) and (`measurement_unit_name_snapshot` is null) and (`measurement_unit_symbol_snapshot` is null)) or ((`option_type_code_snapshot` = _utf8mb4'NUMBER') and (`measurement_unit_id` is not null) and (`measurement_unit_code_snapshot` is not null) and (`measurement_unit_name_snapshot` is not null)))),
  CONSTRAINT `chk_booking_option_selection_unit_symbol` CHECK (((`measurement_unit_symbol_snapshot` is null) or (char_length(trim(`measurement_unit_symbol_snapshot`)) between 1 and 20))),
  CONSTRAINT `chk_booking_option_selection_value` CHECK ((((`option_type_code_snapshot` = _utf8mb4'NUMBER') and (`numeric_value` is not null) and (`boolean_value` is null)) or ((`option_type_code_snapshot` = _utf8mb4'BOOLEAN') and (`numeric_value` is null) and (`boolean_value` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_item_option_selections`
--

LOCK TABLES `booking_item_option_selections` WRITE;
/*!40000 ALTER TABLE `booking_item_option_selections` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_item_option_selections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_item_status_history`
--

DROP TABLE IF EXISTS `booking_item_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_item_status_history` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_item_id` binary(16) NOT NULL,
  `from_status_id` tinyint unsigned DEFAULT NULL,
  `to_status_id` tinyint unsigned NOT NULL,
  `changed_by_user_id` binary(16) DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_item_status_history_item_time` (`booking_item_id`,`changed_at`),
  KEY `idx_item_status_history_to_status_time` (`to_status_id`,`changed_at`),
  KEY `idx_item_status_history_from_status` (`from_status_id`),
  KEY `idx_item_status_history_changed_by` (`changed_by_user_id`),
  CONSTRAINT `fk_item_status_history_booking_item` FOREIGN KEY (`booking_item_id`) REFERENCES `booking_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_item_status_history_changed_by` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_item_status_history_from_status` FOREIGN KEY (`from_status_id`) REFERENCES `booking_item_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_item_status_history_to_status` FOREIGN KEY (`to_status_id`) REFERENCES `booking_item_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_item_status_history_different_status` CHECK (((`from_status_id` is null) or (`from_status_id` <> `to_status_id`))),
  CONSTRAINT `chk_item_status_history_reason` CHECK (((`reason` is null) or (char_length(trim(`reason`)) between 2 and 500)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_item_status_history`
--

LOCK TABLES `booking_item_status_history` WRITE;
/*!40000 ALTER TABLE `booking_item_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_item_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_item_statuses`
--

DROP TABLE IF EXISTS `booking_item_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_item_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_item_statuses_code` (`code`),
  KEY `idx_booking_item_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_booking_item_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_booking_item_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_booking_item_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_booking_item_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 120)),
  CONSTRAINT `chk_booking_item_statuses_terminal` CHECK ((`is_terminal` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_item_statuses`
--

LOCK TABLES `booking_item_statuses` WRITE;
/*!40000 ALTER TABLE `booking_item_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_item_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_items`
--

DROP TABLE IF EXISTS `booking_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_items` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_id` binary(16) NOT NULL,
  `source_cart_item_id` binary(16) NOT NULL,
  `service_id` binary(16) NOT NULL,
  `pricing_scheme_version_id` binary(16) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `service_code_snapshot` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `service_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `pricing_status_snapshot` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `base_amount_snapshot` decimal(19,6) DEFAULT NULL,
  `pricing_breakdown` json NOT NULL,
  `unit_total_amount` decimal(19,6) NOT NULL,
  `line_total_amount` decimal(19,6) NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` datetime(6) DEFAULT NULL,
  `cancelled_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_items_source_cart_item` (`source_cart_item_id`),
  KEY `idx_booking_items_booking_order` (`booking_id`,`display_order`),
  KEY `idx_booking_items_booking_status` (`booking_id`,`status_id`),
  KEY `idx_booking_items_service` (`service_id`),
  KEY `idx_booking_items_status` (`status_id`),
  KEY `idx_booking_items_scheme_version` (`pricing_scheme_version_id`),
  CONSTRAINT `fk_booking_items_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_items_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_items_source_cart_item` FOREIGN KEY (`source_cart_item_id`) REFERENCES `cart_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_items_status` FOREIGN KEY (`status_id`) REFERENCES `booking_item_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_items_scheme_version` FOREIGN KEY (`pricing_scheme_version_id`) REFERENCES `pricing_scheme_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_items_base_amount` CHECK (((`base_amount_snapshot` is null) or (`base_amount_snapshot` >= 0))),
  CONSTRAINT `chk_booking_items_cancelled_at` CHECK (((`cancelled_at` is null) or (`cancelled_at` >= `created_at`))),
  CONSTRAINT `chk_booking_items_completed_at` CHECK (((`completed_at` is null) or (`completed_at` >= `created_at`))),
  CONSTRAINT `chk_booking_items_line_total` CHECK ((`line_total_amount` = (`unit_total_amount` * `quantity`))),
  CONSTRAINT `chk_booking_items_pricing_status` CHECK ((`pricing_status_snapshot` in (_utf8mb4'PRICED',_utf8mb4'QUOTE_REQUIRED'))),
  CONSTRAINT `chk_booking_items_quantity` CHECK ((`quantity` between 1 and 1000)),
  CONSTRAINT `chk_booking_items_service_code` CHECK ((char_length(trim(`service_code_snapshot`)) between 2 and 80)),
  CONSTRAINT `chk_booking_items_service_name` CHECK ((char_length(trim(`service_name_snapshot`)) between 2 and 160)),
  CONSTRAINT `chk_booking_items_single_final_state` CHECK (((`completed_at` is null) or (`cancelled_at` is null))),
  CONSTRAINT `chk_booking_items_status_changed` CHECK ((`status_changed_at` >= `created_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_items`
--

LOCK TABLES `booking_items` WRITE;
/*!40000 ALTER TABLE `booking_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_locations`
--

DROP TABLE IF EXISTS `booking_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_locations` (
  `booking_id` binary(16) NOT NULL,
  `property_type_id` smallint unsigned NOT NULL,
  `area_id` int unsigned NOT NULL,
  `property_type_name_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `country_name_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `city_name_snapshot` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `area_name_snapshot` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `other_property_type_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `street_name` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `address_line` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `building_name_or_number` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `floor_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `unit_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `nearby_landmark` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `additional_location_notes` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `visit_contact_phone` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`booking_id`),
  KEY `idx_booking_locations_property_type` (`property_type_id`),
  KEY `idx_booking_locations_area` (`area_id`),
  CONSTRAINT `fk_booking_locations_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_locations_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_locations_property_type` FOREIGN KEY (`property_type_id`) REFERENCES `property_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_locations_address` CHECK ((char_length(trim(`address_line`)) between 5 and 500)),
  CONSTRAINT `chk_booking_locations_area_snapshot` CHECK ((char_length(trim(`area_name_snapshot`)) between 2 and 150)),
  CONSTRAINT `chk_booking_locations_building` CHECK ((char_length(trim(`building_name_or_number`)) between 1 and 120)),
  CONSTRAINT `chk_booking_locations_city_snapshot` CHECK ((char_length(trim(`city_name_snapshot`)) between 2 and 120)),
  CONSTRAINT `chk_booking_locations_contact_phone` CHECK ((char_length(trim(`visit_contact_phone`)) between 8 and 20)),
  CONSTRAINT `chk_booking_locations_country_snapshot` CHECK ((char_length(trim(`country_name_snapshot`)) between 2 and 100)),
  CONSTRAINT `chk_booking_locations_floor` CHECK (((`floor_number` is null) or (char_length(trim(`floor_number`)) between 1 and 30))),
  CONSTRAINT `chk_booking_locations_landmark` CHECK (((`nearby_landmark` is null) or (char_length(trim(`nearby_landmark`)) between 2 and 250))),
  CONSTRAINT `chk_booking_locations_notes` CHECK (((`additional_location_notes` is null) or (char_length(trim(`additional_location_notes`)) between 2 and 1000))),
  CONSTRAINT `chk_booking_locations_other_type` CHECK (((`other_property_type_name` is null) or (char_length(trim(`other_property_type_name`)) between 2 and 120))),
  CONSTRAINT `chk_booking_locations_property_type_snapshot` CHECK ((char_length(trim(`property_type_name_snapshot`)) between 2 and 100)),
  CONSTRAINT `chk_booking_locations_street` CHECK ((char_length(trim(`street_name`)) between 2 and 180)),
  CONSTRAINT `chk_booking_locations_unit` CHECK (((`unit_number` is null) or (char_length(trim(`unit_number`)) between 1 and 50)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_locations`
--

LOCK TABLES `booking_locations` WRITE;
/*!40000 ALTER TABLE `booking_locations` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_sources`
--

DROP TABLE IF EXISTS `booking_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_sources` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_sources_code` (`code`),
  KEY `idx_booking_sources_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_booking_sources_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_booking_sources_code` CHECK ((char_length(trim(`code`)) between 2 and 20)),
  CONSTRAINT `chk_booking_sources_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_sources`
--

LOCK TABLES `booking_sources` WRITE;
/*!40000 ALTER TABLE `booking_sources` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_sources` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_status_history`
--

DROP TABLE IF EXISTS `booking_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_status_history` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_id` binary(16) NOT NULL,
  `from_status_id` tinyint unsigned DEFAULT NULL,
  `to_status_id` tinyint unsigned NOT NULL,
  `changed_by_user_id` binary(16) DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_booking_status_history_booking_time` (`booking_id`,`changed_at`),
  KEY `idx_booking_status_history_to_status_time` (`to_status_id`,`changed_at`),
  KEY `idx_booking_status_history_from_status` (`from_status_id`),
  KEY `idx_booking_status_history_changed_by` (`changed_by_user_id`),
  CONSTRAINT `fk_booking_status_history_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_status_history_changed_by` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_status_history_from_status` FOREIGN KEY (`from_status_id`) REFERENCES `booking_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_status_history_to_status` FOREIGN KEY (`to_status_id`) REFERENCES `booking_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_status_history_different_status` CHECK (((`from_status_id` is null) or (`from_status_id` <> `to_status_id`))),
  CONSTRAINT `chk_booking_status_history_reason` CHECK (((`reason` is null) or (char_length(trim(`reason`)) between 2 and 500)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_status_history`
--

LOCK TABLES `booking_status_history` WRITE;
/*!40000 ALTER TABLE `booking_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_statuses`
--

DROP TABLE IF EXISTS `booking_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_statuses_code` (`code`),
  KEY `idx_booking_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_booking_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_booking_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_booking_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_booking_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 120)),
  CONSTRAINT `chk_booking_statuses_terminal` CHECK ((`is_terminal` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_statuses`
--

LOCK TABLES `booking_statuses` WRITE;
/*!40000 ALTER TABLE `booking_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookings` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_number` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `cart_id` binary(16) NOT NULL,
  `payment_attempt_id` binary(16) DEFAULT NULL,
  `booking_source_id` tinyint unsigned NOT NULL,
  `service_contract_id` binary(16) DEFAULT NULL,
  `service_contract_item_id` binary(16) DEFAULT NULL,
  `appointment_slot_id` binary(16) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` datetime(6) DEFAULT NULL,
  `cancelled_at` datetime(6) DEFAULT NULL,
  `cancellation_refund_percentage` tinyint unsigned DEFAULT NULL,
  `cancellation_refund_amount` decimal(19,6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_booking_number` (`booking_number`),
  UNIQUE KEY `uq_bookings_cart` (`cart_id`),
  UNIQUE KEY `uq_bookings_payment_attempt` (`payment_attempt_id`),
  KEY `idx_bookings_status_created` (`status_id`,`created_at`),
  KEY `idx_bookings_appointment_status` (`appointment_slot_id`,`status_id`),
  KEY `idx_bookings_created_at` (`created_at`),
  KEY `idx_bookings_source` (`booking_source_id`),
  KEY `idx_bookings_contract` (`service_contract_id`),
  KEY `idx_bookings_contract_item` (`service_contract_item_id`,`service_contract_id`,`status_id`),
  CONSTRAINT `fk_bookings_appointment_slot` FOREIGN KEY (`appointment_slot_id`) REFERENCES `appointment_slots` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bookings_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bookings_payment_attempt` FOREIGN KEY (`payment_attempt_id`) REFERENCES `payment_attempts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bookings_source` FOREIGN KEY (`booking_source_id`) REFERENCES `booking_sources` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bookings_contract` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bookings_contract_item` FOREIGN KEY (`service_contract_item_id`, `service_contract_id`) REFERENCES `service_contract_items` (`id`, `service_contract_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bookings_status` FOREIGN KEY (`status_id`) REFERENCES `booking_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_bookings_booking_number` CHECK ((char_length(trim(`booking_number`)) between 6 and 40)),
  CONSTRAINT `chk_bookings_cancellation_refund_amount` CHECK (((`cancellation_refund_amount` is null) or (`cancellation_refund_amount` >= 0))),
  CONSTRAINT `chk_bookings_cancellation_refund_data` CHECK ((((`cancellation_refund_percentage` is null) and (`cancellation_refund_amount` is null)) or ((`cancellation_refund_percentage` is not null) and (`cancellation_refund_amount` is not null)))),
  CONSTRAINT `chk_bookings_cancellation_refund_percentage` CHECK (((`cancellation_refund_percentage` is null) or (`cancellation_refund_percentage` between 0 and 100))),
  CONSTRAINT `chk_bookings_cancelled_at` CHECK (((`cancelled_at` is null) or (`cancelled_at` >= `created_at`))),
  CONSTRAINT `chk_bookings_completed_at` CHECK (((`completed_at` is null) or (`completed_at` >= `created_at`))),
  CONSTRAINT `chk_bookings_single_final_state` CHECK (((`completed_at` is null) or (`cancelled_at` is null))),
  CONSTRAINT `chk_bookings_status_changed_at` CHECK ((`status_changed_at` >= `created_at`)),
  CONSTRAINT `chk_bookings_source_pairing` CHECK ((((`payment_attempt_id` is not null) and (`service_contract_id` is null) and (`service_contract_item_id` is null)) or ((`payment_attempt_id` is null) and (`service_contract_id` is not null) and (`service_contract_item_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_item_option_choice_selections`
--

DROP TABLE IF EXISTS `cart_item_option_choice_selections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_item_option_choice_selections` (
  `cart_item_id` binary(16) NOT NULL,
  `service_option_choice_id` binary(16) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`cart_item_id`,`service_option_choice_id`),
  KEY `idx_cart_choice_selections_choice` (`service_option_choice_id`),
  KEY `idx_cart_choice_selections_created_at` (`created_at`),
  CONSTRAINT `fk_cart_choice_selections_cart_item` FOREIGN KEY (`cart_item_id`) REFERENCES `cart_items` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_cart_choice_selections_choice` FOREIGN KEY (`service_option_choice_id`) REFERENCES `service_option_choices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_item_option_choice_selections`
--

LOCK TABLES `cart_item_option_choice_selections` WRITE;
/*!40000 ALTER TABLE `cart_item_option_choice_selections` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_item_option_choice_selections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_item_option_selections`
--

DROP TABLE IF EXISTS `cart_item_option_selections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_item_option_selections` (
  `cart_item_id` binary(16) NOT NULL,
  `service_option_id` binary(16) NOT NULL,
  `numeric_value` decimal(19,6) DEFAULT NULL,
  `boolean_value` tinyint(1) DEFAULT NULL,
  `text_value` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`cart_item_id`,`service_option_id`),
  KEY `idx_cart_option_selections_option` (`service_option_id`),
  CONSTRAINT `fk_cart_option_selections_cart_item` FOREIGN KEY (`cart_item_id`) REFERENCES `cart_items` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_cart_option_selections_service_option` FOREIGN KEY (`service_option_id`) REFERENCES `service_options` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_cart_option_selection_boolean_value` CHECK (((`boolean_value` is null) or (`boolean_value` in (0,1)))),
  CONSTRAINT `chk_cart_option_selection_numeric_value` CHECK (((`numeric_value` is null) or (`numeric_value` >= 0))),
  CONSTRAINT `chk_cart_option_selection_one_value` CHECK ((((`numeric_value` is not null) and (`boolean_value` is null) and (`text_value` is null)) or ((`numeric_value` is null) and (`boolean_value` is not null) and (`text_value` is null)) or ((`numeric_value` is null) and (`boolean_value` is null) and (`text_value` is not null)))),
  CONSTRAINT `chk_cart_option_selection_text_value` CHECK (((`text_value` is null) or (char_length(trim(`text_value`)) between 1 and 1000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_item_option_selections`
--

LOCK TABLES `cart_item_option_selections` WRITE;
/*!40000 ALTER TABLE `cart_item_option_selections` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_item_option_selections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_items`
--

DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_items` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `cart_id` binary(16) NOT NULL,
  `service_id` binary(16) NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_cart_items_cart_order` (`cart_id`,`display_order`,`created_at`),
  KEY `idx_cart_items_service_id` (`service_id`),
  KEY `idx_cart_items_cart_service` (`cart_id`,`service_id`),
  CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_cart_items_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_cart_items_quantity` CHECK ((`quantity` between 1 and 1000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_items`
--

LOCK TABLES `cart_items` WRITE;
/*!40000 ALTER TABLE `cart_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_locations`
--

DROP TABLE IF EXISTS `cart_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_locations` (
  `cart_id` binary(16) NOT NULL,
  `property_type_id` smallint unsigned NOT NULL,
  `area_id` int unsigned NOT NULL,
  `other_property_type_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `street_name` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `address_line` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `building_name_or_number` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `floor_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `unit_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `nearby_landmark` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `additional_location_notes` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `visit_contact_phone` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`cart_id`),
  KEY `idx_cart_locations_property_type` (`property_type_id`),
  KEY `idx_cart_locations_area` (`area_id`),
  CONSTRAINT `fk_cart_locations_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cart_locations_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_cart_locations_property_type` FOREIGN KEY (`property_type_id`) REFERENCES `property_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_cart_locations_address` CHECK ((char_length(trim(`address_line`)) between 5 and 500)),
  CONSTRAINT `chk_cart_locations_building` CHECK ((char_length(trim(`building_name_or_number`)) between 1 and 120)),
  CONSTRAINT `chk_cart_locations_contact_phone` CHECK ((char_length(trim(`visit_contact_phone`)) between 8 and 20)),
  CONSTRAINT `chk_cart_locations_floor` CHECK (((`floor_number` is null) or (char_length(trim(`floor_number`)) between 1 and 30))),
  CONSTRAINT `chk_cart_locations_landmark` CHECK (((`nearby_landmark` is null) or (char_length(trim(`nearby_landmark`)) between 2 and 250))),
  CONSTRAINT `chk_cart_locations_notes` CHECK (((`additional_location_notes` is null) or (char_length(trim(`additional_location_notes`)) between 2 and 1000))),
  CONSTRAINT `chk_cart_locations_other_type` CHECK (((`other_property_type_name` is null) or (char_length(trim(`other_property_type_name`)) between 2 and 120))),
  CONSTRAINT `chk_cart_locations_street` CHECK ((char_length(trim(`street_name`)) between 2 and 180)),
  CONSTRAINT `chk_cart_locations_unit` CHECK (((`unit_number` is null) or (char_length(trim(`unit_number`)) between 1 and 50)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_locations`
--

LOCK TABLES `cart_locations` WRITE;
/*!40000 ALTER TABLE `cart_locations` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_statuses`
--

DROP TABLE IF EXISTS `cart_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_statuses_code` (`code`),
  KEY `idx_cart_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_cart_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_cart_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_cart_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_cart_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100)),
  CONSTRAINT `chk_cart_statuses_terminal` CHECK ((`is_terminal` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_statuses`
--

LOCK TABLES `cart_statuses` WRITE;
/*!40000 ALTER TABLE `cart_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `carts`
--

DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carts` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `customer_user_id` binary(16) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `last_activity_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_carts_customer_status` (`customer_user_id`,`status_id`),
  KEY `idx_carts_customer_created` (`customer_user_id`,`created_at`),
  KEY `idx_carts_status_activity` (`status_id`,`last_activity_at`),
  KEY `idx_carts_currency_id` (`currency_id`),
  CONSTRAINT `fk_carts_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_carts_customer` FOREIGN KEY (`customer_user_id`) REFERENCES `customer_profiles` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_carts_status` FOREIGN KEY (`status_id`) REFERENCES `cart_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_carts_last_activity` CHECK ((`last_activity_at` >= `created_at`)),
  CONSTRAINT `chk_carts_status_changed` CHECK ((`status_changed_at` >= `created_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `carts`
--

LOCK TABLES `carts` WRITE;
/*!40000 ALTER TABLE `carts` DISABLE KEYS */;
/*!40000 ALTER TABLE `carts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cities`
--

DROP TABLE IF EXISTS `cities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `country_id` smallint unsigned NOT NULL,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cities_country_code` (`country_id`,`code`),
  UNIQUE KEY `uq_cities_country_name` (`country_id`,`name`),
  KEY `idx_cities_country_id` (`country_id`),
  KEY `idx_cities_country_active_order` (`country_id`,`is_active`,`display_order`),
  CONSTRAINT `fk_cities_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_cities_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_cities_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_cities_name` CHECK ((char_length(trim(`name`)) between 2 and 120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cities`
--

LOCK TABLES `cities` WRITE;
/*!40000 ALTER TABLE `cities` DISABLE KEYS */;
/*!40000 ALTER TABLE `cities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `iso2_code` char(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `iso3_code` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_countries_iso2_code` (`iso2_code`),
  UNIQUE KEY `uq_countries_iso3_code` (`iso3_code`),
  KEY `idx_countries_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_countries_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_countries_iso2_code` CHECK ((char_length(trim(`iso2_code`)) = 2)),
  CONSTRAINT `chk_countries_iso3_code` CHECK ((char_length(trim(`iso3_code`)) = 3)),
  CONSTRAINT `chk_countries_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `countries`
--

LOCK TABLES `countries` WRITE;
/*!40000 ALTER TABLE `countries` DISABLE KEYS */;
/*!40000 ALTER TABLE `countries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `numeric_code` char(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `symbol` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `minor_unit` tinyint unsigned NOT NULL DEFAULT '2',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currencies_code` (`code`),
  UNIQUE KEY `uq_currencies_numeric_code` (`numeric_code`),
  CONSTRAINT `chk_currencies_code` CHECK (((char_length(`code`) = 3) and (`code` = upper(`code`)))),
  CONSTRAINT `chk_currencies_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_currencies_minor_unit` CHECK ((`minor_unit` between 0 and 6)),
  CONSTRAINT `chk_currencies_name` CHECK ((char_length(trim(`name`)) between 2 and 100)),
  CONSTRAINT `chk_currencies_numeric_code` CHECK (regexp_like(`numeric_code`,_utf8mb4'^[0-9]{3}$')),
  CONSTRAINT `chk_currencies_symbol` CHECK (((`symbol` is null) or (char_length(trim(`symbol`)) > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_account_deletion_requests`
--

DROP TABLE IF EXISTS `customer_account_deletion_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_account_deletion_requests` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `requested_at` datetime(6) NOT NULL,
  `last_checked_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_account_deletion_requests_user` (`user_id`),
  KEY `idx_customer_account_deletion_requests_completed_at` (`completed_at`),
  CONSTRAINT `fk_customer_account_deletion_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_customer_account_deletion_requests_last_checked_at` CHECK (((`last_checked_at` is null) or (`last_checked_at` >= `requested_at`))),
  CONSTRAINT `chk_customer_account_deletion_requests_completed_at` CHECK (((`completed_at` is null) or (`completed_at` >= `requested_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_account_deletion_requests`
--

LOCK TABLES `customer_account_deletion_requests` WRITE;
/*!40000 ALTER TABLE `customer_account_deletion_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_account_deletion_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_profiles`
--

DROP TABLE IF EXISTS `customer_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_profiles` (
  `user_id` binary(16) NOT NULL,
  `property_relationship_type_id` smallint unsigned NOT NULL,
  `area_id` int unsigned NOT NULL,
  `stripe_customer_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_customer_profiles_stripe_customer` (`stripe_customer_id`),
  KEY `idx_customer_profiles_relationship_type` (`property_relationship_type_id`),
  KEY `idx_customer_profiles_area_id` (`area_id`),
  CONSTRAINT `fk_customer_profiles_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_customer_profiles_relationship_type` FOREIGN KEY (`property_relationship_type_id`) REFERENCES `property_relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_customer_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_customer_profiles_stripe_customer` CHECK (((`stripe_customer_id` is null) or (char_length(trim(`stripe_customer_id`)) between 1 and 191)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_profiles`
--

LOCK TABLES `customer_profiles` WRITE;
/*!40000 ALTER TABLE `customer_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_properties`
--

DROP TABLE IF EXISTS `customer_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_properties` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `customer_user_id` binary(16) NOT NULL,
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `property_relationship_type_id` smallint unsigned NOT NULL,
  `property_type_id` smallint unsigned NOT NULL,
  `other_property_type_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `area_id` int unsigned NOT NULL,
  `street_name` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `address_line` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `building_name_or_number` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `floor_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `unit_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `nearby_landmark` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `additional_location_notes` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `visit_contact_phone` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_properties_id_customer` (`id`,`customer_user_id`),
  KEY `idx_customer_properties_customer_active` (`customer_user_id`,`is_active`,`created_at`),
  KEY `idx_customer_properties_area` (`area_id`),
  KEY `idx_customer_properties_relationship_type` (`property_relationship_type_id`),
  KEY `idx_customer_properties_property_type` (`property_type_id`),
  CONSTRAINT `fk_customer_properties_customer` FOREIGN KEY (`customer_user_id`) REFERENCES `customer_profiles` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_customer_properties_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_customer_properties_relationship_type` FOREIGN KEY (`property_relationship_type_id`) REFERENCES `property_relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_customer_properties_property_type` FOREIGN KEY (`property_type_id`) REFERENCES `property_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_customer_properties_label` CHECK ((char_length(trim(`label`)) between 2 and 120)),
  CONSTRAINT `chk_customer_properties_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_customer_properties_address` CHECK ((char_length(trim(`address_line`)) between 5 and 500)),
  CONSTRAINT `chk_customer_properties_building` CHECK ((char_length(trim(`building_name_or_number`)) between 1 and 120)),
  CONSTRAINT `chk_customer_properties_contact_phone` CHECK ((char_length(trim(`visit_contact_phone`)) between 8 and 20)),
  CONSTRAINT `chk_customer_properties_floor` CHECK (((`floor_number` is null) or (char_length(trim(`floor_number`)) between 1 and 30))),
  CONSTRAINT `chk_customer_properties_landmark` CHECK (((`nearby_landmark` is null) or (char_length(trim(`nearby_landmark`)) between 2 and 250))),
  CONSTRAINT `chk_customer_properties_notes` CHECK (((`additional_location_notes` is null) or (char_length(trim(`additional_location_notes`)) between 2 and 1000))),
  CONSTRAINT `chk_customer_properties_other_type` CHECK (((`other_property_type_name` is null) or (char_length(trim(`other_property_type_name`)) between 2 and 120))),
  CONSTRAINT `chk_customer_properties_street` CHECK ((char_length(trim(`street_name`)) between 2 and 180)),
  CONSTRAINT `chk_customer_properties_unit` CHECK (((`unit_number` is null) or (char_length(trim(`unit_number`)) between 1 and 50)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_properties`
--

LOCK TABLES `customer_properties` WRITE;
/*!40000 ALTER TABLE `customer_properties` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_properties` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_service_interests`
--

DROP TABLE IF EXISTS `customer_service_interests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_service_interests` (
  `customer_user_id` binary(16) NOT NULL,
  `service_category_id` int unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`customer_user_id`,`service_category_id`),
  KEY `idx_customer_interests_category` (`service_category_id`),
  KEY `idx_customer_interests_created_at` (`created_at`),
  CONSTRAINT `fk_customer_interests_category` FOREIGN KEY (`service_category_id`) REFERENCES `service_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_customer_interests_customer` FOREIGN KEY (`customer_user_id`) REFERENCES `customer_profiles` (`user_id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_service_interests`
--

LOCK TABLES `customer_service_interests` WRITE;
/*!40000 ALTER TABLE `customer_service_interests` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_service_interests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `measurement_units`
--

DROP TABLE IF EXISTS `measurement_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `measurement_units` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `symbol` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_measurement_units_code` (`code`),
  KEY `idx_measurement_units_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_measurement_units_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_measurement_units_code` CHECK ((char_length(trim(`code`)) between 1 and 50)),
  CONSTRAINT `chk_measurement_units_name` CHECK ((char_length(trim(`name`)) between 1 and 100)),
  CONSTRAINT `chk_measurement_units_symbol` CHECK (((`symbol` is null) or (char_length(trim(`symbol`)) > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `measurement_units`
--

LOCK TABLES `measurement_units` WRITE;
/*!40000 ALTER TABLE `measurement_units` DISABLE KEYS */;
/*!40000 ALTER TABLE `measurement_units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_verification_purposes`
--

DROP TABLE IF EXISTS `otp_verification_purposes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_verification_purposes` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_otp_verification_purposes_code` (`code`),
  CONSTRAINT `chk_otp_purposes_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_otp_purposes_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_verification_purposes`
--

LOCK TABLES `otp_verification_purposes` WRITE;
/*!40000 ALTER TABLE `otp_verification_purposes` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_verification_purposes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_verification_statuses`
--

DROP TABLE IF EXISTS `otp_verification_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_verification_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_otp_verification_statuses_code` (`code`),
  CONSTRAINT `chk_otp_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 30)),
  CONSTRAINT `chk_otp_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100)),
  CONSTRAINT `chk_otp_statuses_terminal` CHECK ((`is_terminal` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_verification_statuses`
--

LOCK TABLES `otp_verification_statuses` WRITE;
/*!40000 ALTER TABLE `otp_verification_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_verification_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_verifications`
--

DROP TABLE IF EXISTS `otp_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_verifications` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `purpose_id` tinyint unsigned NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `target_phone_number` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_delivery_status` varchar(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `failed_attempt_count` tinyint unsigned NOT NULL DEFAULT '0',
  `max_attempts` tinyint unsigned NOT NULL DEFAULT '5',
  `expires_at` datetime(6) NOT NULL,
  `last_attempt_at` datetime(6) DEFAULT NULL,
  `verified_at` datetime(6) DEFAULT NULL,
  `invalidated_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_otp_verifications_provider_reference` (`provider_reference`),
  KEY `fk_otp_verifications_purpose` (`purpose_id`),
  KEY `idx_otp_user_purpose_status` (`user_id`,`purpose_id`,`status_id`,`created_at`),
  KEY `idx_otp_phone_created_at` (`target_phone_number`,`created_at`),
  KEY `idx_otp_status_expires_at` (`status_id`,`expires_at`),
  CONSTRAINT `fk_otp_verifications_purpose` FOREIGN KEY (`purpose_id`) REFERENCES `otp_verification_purposes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_otp_verifications_status` FOREIGN KEY (`status_id`) REFERENCES `otp_verification_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_otp_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_otp_expiration` CHECK ((`expires_at` > `created_at`)),
  CONSTRAINT `chk_otp_failed_attempts` CHECK ((`failed_attempt_count` <= `max_attempts`)),
  CONSTRAINT `chk_otp_max_attempts` CHECK ((`max_attempts` > 0)),
  CONSTRAINT `chk_otp_target_phone_number` CHECK ((char_length(trim(`target_phone_number`)) between 8 and 20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_verifications`
--

LOCK TABLES `otp_verifications` WRITE;
/*!40000 ALTER TABLE `otp_verifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_verifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_sessions`
--

DROP TABLE IF EXISTS `password_reset_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_sessions` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `otp_verification_id` binary(16) NOT NULL,
  `reset_token_hash` binary(32) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `used_at` datetime(6) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_reset_sessions_otp` (`otp_verification_id`),
  UNIQUE KEY `uq_password_reset_sessions_token_hash` (`reset_token_hash`),
  KEY `idx_password_reset_sessions_expires_at` (`expires_at`),
  KEY `idx_password_reset_sessions_active` (`used_at`,`revoked_at`,`expires_at`),
  CONSTRAINT `fk_password_reset_sessions_otp` FOREIGN KEY (`otp_verification_id`) REFERENCES `otp_verifications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_password_reset_sessions_expiration` CHECK ((`expires_at` > `created_at`)),
  CONSTRAINT `chk_password_reset_sessions_revoked` CHECK (((`revoked_at` is null) or (`revoked_at` >= `created_at`))),
  CONSTRAINT `chk_password_reset_sessions_state` CHECK (((`used_at` is null) or (`revoked_at` is null))),
  CONSTRAINT `chk_password_reset_sessions_used` CHECK (((`used_at` is null) or (`used_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_sessions`
--

LOCK TABLES `password_reset_sessions` WRITE;
/*!40000 ALTER TABLE `password_reset_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_attempts`
--

DROP TABLE IF EXISTS `payment_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_attempts` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `cart_id` binary(16) NOT NULL,
  `appointment_hold_id` binary(16) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `checkout_reference` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` binary(32) NOT NULL,
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_session_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_transaction_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `requested_amount` decimal(19,6) NOT NULL,
  `confirmed_amount` decimal(19,6) DEFAULT NULL,
  `checkout_snapshot` json NOT NULL,
  `checkout_snapshot_hash` binary(32) NOT NULL,
  `payment_method_type` varchar(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_status_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `failure_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `failure_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `requires_reconciliation` tinyint(1) NOT NULL DEFAULT '0',
  `reconciliation_reason_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `reconciled_at` datetime(6) DEFAULT NULL,
  `expires_at` datetime(6) DEFAULT NULL,
  `successful_at` datetime(6) DEFAULT NULL,
  `finalized_at` datetime(6) DEFAULT NULL,
  `open_cart_marker` binary(16) GENERATED ALWAYS AS ((case when (`finalized_at` is null) then `cart_id` else NULL end)) STORED,
  `successful_cart_marker` binary(16) GENERATED ALWAYS AS ((case when (`successful_at` is not null) then `cart_id` else NULL end)) STORED,
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_attempts_checkout_reference` (`checkout_reference`),
  UNIQUE KEY `uq_payment_attempts_idempotency_key` (`idempotency_key`),
  UNIQUE KEY `uq_payment_attempts_provider_session` (`provider_code`,`provider_session_reference`),
  UNIQUE KEY `uq_payment_attempts_provider_transaction` (`provider_code`,`provider_transaction_reference`),
  UNIQUE KEY `uq_payment_attempts_open_cart` (`open_cart_marker`),
  UNIQUE KEY `uq_payment_attempts_successful_cart` (`successful_cart_marker`),
  KEY `idx_payment_attempts_cart_created` (`cart_id`,`created_at`),
  KEY `idx_payment_attempts_cart_status` (`cart_id`,`status_id`),
  KEY `idx_payment_attempts_status_created` (`status_id`,`created_at`),
  KEY `idx_payment_attempts_currency` (`currency_id`),
  KEY `idx_payment_attempts_expires_at` (`expires_at`),
  KEY `idx_payment_attempts_hold` (`appointment_hold_id`),
  CONSTRAINT `fk_payment_attempts_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payment_attempts_hold` FOREIGN KEY (`appointment_hold_id`) REFERENCES `appointment_holds` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payment_attempts_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payment_attempts_status` FOREIGN KEY (`status_id`) REFERENCES `payment_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_payment_attempts_checkout_reference` CHECK ((char_length(trim(`checkout_reference`)) between 8 and 64)),
  CONSTRAINT `chk_payment_attempts_confirmed_amount` CHECK (((`confirmed_amount` is null) or (`confirmed_amount` >= 0))),
  CONSTRAINT `chk_payment_attempts_expiration` CHECK (((`expires_at` is null) or (`expires_at` > `created_at`))),
  CONSTRAINT `chk_payment_attempts_failure_code` CHECK (((`failure_code` is null) or (char_length(trim(`failure_code`)) between 1 and 100))),
  CONSTRAINT `chk_payment_attempts_failure_message` CHECK (((`failure_message` is null) or (char_length(trim(`failure_message`)) between 2 and 500))),
  CONSTRAINT `chk_payment_attempts_finalized_at` CHECK (((`finalized_at` is null) or (`finalized_at` >= `created_at`))),
  CONSTRAINT `chk_payment_attempts_method_type` CHECK (((`payment_method_type` is null) or (char_length(trim(`payment_method_type`)) between 2 and 50))),
  CONSTRAINT `chk_payment_attempts_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_payment_attempts_provider_status` CHECK (((`provider_status_code` is null) or (char_length(trim(`provider_status_code`)) between 1 and 100))),
  CONSTRAINT `chk_payment_attempts_requested_amount` CHECK ((`requested_amount` > 0)),
  CONSTRAINT `chk_payment_attempts_status_changed` CHECK ((`status_changed_at` >= `created_at`)),
  CONSTRAINT `chk_payment_attempts_successful_at` CHECK (((`successful_at` is null) or ((`successful_at` >= `created_at`) and (`confirmed_amount` is not null) and (`finalized_at` is not null)))),
  CONSTRAINT `chk_payment_attempts_requires_reconciliation` CHECK ((`requires_reconciliation` in (0,1))),
  CONSTRAINT `chk_payment_attempts_reconciliation_reason` CHECK (((`reconciliation_reason_code` is null) or (`reconciliation_reason_code` in (_utf8mb4'AMOUNT_MISMATCH',_utf8mb4'CURRENCY_MISMATCH',_utf8mb4'HOLD_EXPIRED',_utf8mb4'HOLD_RELEASED',_utf8mb4'HOLD_CART_MISMATCH',_utf8mb4'SNAPSHOT_INTEGRITY_FAILURE',_utf8mb4'UNEXPECTED_PROVIDER_STATE')))),
  CONSTRAINT `chk_payment_attempts_reconciliation_requires_flag` CHECK (((`reconciliation_reason_code` is null) or (`requires_reconciliation` = 1))),
  CONSTRAINT `chk_payment_attempts_reconciled_at` CHECK (((`reconciled_at` is null) or (`reconciled_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_attempts`
--

LOCK TABLES `payment_attempts` WRITE;
/*!40000 ALTER TABLE `payment_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_statuses`
--

DROP TABLE IF EXISTS `payment_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_final_for_checkout` tinyint(1) NOT NULL DEFAULT '0',
  `allows_booking_creation` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_statuses_code` (`code`),
  KEY `idx_payment_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_payment_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_payment_statuses_booking_creation` CHECK ((`allows_booking_creation` in (0,1))),
  CONSTRAINT `chk_payment_statuses_booking_requires_final` CHECK (((`allows_booking_creation` = false) or (`is_final_for_checkout` = true))),
  CONSTRAINT `chk_payment_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_payment_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_payment_statuses_final` CHECK ((`is_final_for_checkout` in (0,1))),
  CONSTRAINT `chk_payment_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_statuses`
--

LOCK TABLES `payment_statuses` WRITE;
/*!40000 ALTER TABLE `payment_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_webhook_event_statuses`
--

DROP TABLE IF EXISTS `payment_webhook_event_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_webhook_event_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_webhook_event_statuses_code` (`code`),
  KEY `idx_payment_webhook_event_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_payment_webhook_event_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_payment_webhook_event_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_payment_webhook_event_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_payment_webhook_event_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_webhook_event_statuses`
--

LOCK TABLES `payment_webhook_event_statuses` WRITE;
/*!40000 ALTER TABLE `payment_webhook_event_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_webhook_event_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_webhook_events`
--

DROP TABLE IF EXISTS `payment_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_webhook_events` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_event_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_attempt_id` binary(16) DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_transaction_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_hash` binary(32) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `processing_attempt_count` int unsigned NOT NULL DEFAULT '0',
  `received_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `processed_at` datetime(6) DEFAULT NULL,
  `last_error_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_webhook_events_provider_event` (`provider_code`,`provider_event_id`),
  KEY `idx_payment_webhook_events_attempt` (`payment_attempt_id`),
  KEY `idx_payment_webhook_events_transaction_ref` (`provider_transaction_reference`),
  KEY `idx_payment_webhook_events_status_received` (`status_id`,`received_at`),
  KEY `idx_payment_webhook_events_received_at` (`received_at`),
  CONSTRAINT `fk_payment_webhook_events_attempt` FOREIGN KEY (`payment_attempt_id`) REFERENCES `payment_attempts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payment_webhook_events_status` FOREIGN KEY (`status_id`) REFERENCES `payment_webhook_event_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_payment_webhook_events_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_payment_webhook_events_provider_event_id` CHECK ((char_length(trim(`provider_event_id`)) between 1 and 191)),
  CONSTRAINT `chk_payment_webhook_events_event_type` CHECK ((char_length(trim(`event_type`)) between 1 and 100)),
  CONSTRAINT `chk_payment_webhook_events_last_error_code` CHECK (((`last_error_code` is null) or (char_length(trim(`last_error_code`)) between 1 and 100))),
  CONSTRAINT `chk_payment_webhook_events_last_error_message` CHECK (((`last_error_message` is null) or (char_length(trim(`last_error_message`)) between 2 and 500))),
  CONSTRAINT `chk_payment_webhook_events_processed_at` CHECK (((`processed_at` is null) or (`processed_at` >= `received_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_webhook_events`
--

LOCK TABLES `payment_webhook_events` WRITE;
/*!40000 ALTER TABLE `payment_webhook_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_webhook_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_context_attributes`
--

DROP TABLE IF EXISTS `pricing_context_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_context_attributes` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pricing_context_attributes_code` (`code`),
  KEY `idx_pricing_context_attributes_active` (`is_active`),
  CONSTRAINT `chk_pricing_context_attributes_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_pricing_context_attributes_code` CHECK ((char_length(trim(`code`)) between 2 and 60)),
  CONSTRAINT `chk_pricing_context_attributes_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_pricing_context_attributes_name` CHECK ((char_length(trim(`name`)) between 2 and 120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_context_attributes`
--

LOCK TABLES `pricing_context_attributes` WRITE;
/*!40000 ALTER TABLE `pricing_context_attributes` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_context_attributes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_scheme_versions`
--

DROP TABLE IF EXISTS `pricing_scheme_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_scheme_versions` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_id` binary(16) NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'DRAFT',
  `effective_from` datetime(6) DEFAULT NULL,
  `effective_to` datetime(6) DEFAULT NULL,
  `open_ended_marker` tinyint unsigned GENERATED ALWAYS AS ((case when ((`status` = _utf8mb4'PUBLISHED') and (`effective_to` is null)) then 1 else NULL end)) STORED,
  `published_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pricing_scheme_versions_open_ended` (`service_id`,`currency_id`,`open_ended_marker`),
  KEY `idx_pricing_scheme_versions_lookup` (`service_id`,`currency_id`,`effective_from`,`effective_to`),
  KEY `idx_pricing_scheme_versions_currency` (`currency_id`),
  KEY `idx_pricing_scheme_versions_status` (`status`),
  CONSTRAINT `fk_pricing_scheme_versions_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pricing_scheme_versions_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_pricing_scheme_versions_status` CHECK ((`status` in (_utf8mb4'DRAFT',_utf8mb4'PUBLISHED',_utf8mb4'RETIRED'))),
  CONSTRAINT `chk_pricing_scheme_versions_period` CHECK (((`effective_to` is null) or ((`effective_from` is not null) and (`effective_to` > `effective_from`)))),
  CONSTRAINT `chk_pricing_scheme_versions_requires_from` CHECK (((`status` = _utf8mb4'DRAFT') or (`effective_from` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_scheme_versions`
--

LOCK TABLES `pricing_scheme_versions` WRITE;
/*!40000 ALTER TABLE `pricing_scheme_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_scheme_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_rules`
--

DROP TABLE IF EXISTS `pricing_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_rules` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `pricing_scheme_version_id` binary(16) NOT NULL,
  `rule_code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `label` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `priority` smallint unsigned NOT NULL,
  `effect_type` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effect_amount` decimal(19,6) DEFAULT NULL,
  `effect_subject_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `effect_subject_service_option_id` binary(16) DEFAULT NULL,
  `tier_calculation_mode` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stop_processing` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pricing_rules_scheme_code` (`pricing_scheme_version_id`,`rule_code`),
  UNIQUE KEY `uq_pricing_rules_scheme_priority` (`pricing_scheme_version_id`,`priority`),
  KEY `idx_pricing_rules_subject_option` (`effect_subject_service_option_id`),
  CONSTRAINT `fk_pricing_rules_scheme_version` FOREIGN KEY (`pricing_scheme_version_id`) REFERENCES `pricing_scheme_versions` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_pricing_rules_subject_option` FOREIGN KEY (`effect_subject_service_option_id`) REFERENCES `service_options` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_pricing_rules_code` CHECK ((char_length(trim(`rule_code`)) between 2 and 80)),
  CONSTRAINT `chk_pricing_rules_label` CHECK ((char_length(trim(`label`)) between 2 and 160)),
  CONSTRAINT `chk_pricing_rules_effect_type` CHECK ((`effect_type` in (_utf8mb4'SET_PRICE',_utf8mb4'ADD_FIXED',_utf8mb4'ADD_PER_UNIT',_utf8mb4'MULTIPLY',_utf8mb4'MIN_TOTAL',_utf8mb4'MAX_TOTAL',_utf8mb4'QUOTE_REQUIRED'))),
  CONSTRAINT `chk_pricing_rules_subject_type` CHECK (((`effect_subject_type` is null) or (`effect_subject_type` = _utf8mb4'OPTION_NUMERIC_VALUE'))),
  CONSTRAINT `chk_pricing_rules_tier_mode` CHECK (((`tier_calculation_mode` is null) or (`tier_calculation_mode` in (_utf8mb4'VOLUME',_utf8mb4'GRADUATED')))),
  CONSTRAINT `chk_pricing_rules_tier_mode_scope` CHECK (((`tier_calculation_mode` is null) or (`effect_type` = _utf8mb4'ADD_PER_UNIT'))),
  CONSTRAINT `chk_pricing_rules_per_unit_requires_tier_mode` CHECK (((`effect_type` <> _utf8mb4'ADD_PER_UNIT') or (`tier_calculation_mode` is not null))),
  CONSTRAINT `chk_pricing_rules_per_unit_subject` CHECK (((`effect_type` <> _utf8mb4'ADD_PER_UNIT') or ((`effect_subject_type` is not null) and (`effect_subject_service_option_id` is not null)))),
  CONSTRAINT `chk_pricing_rules_amount_required` CHECK ((((`effect_type` in (_utf8mb4'SET_PRICE',_utf8mb4'ADD_FIXED',_utf8mb4'MULTIPLY',_utf8mb4'MIN_TOTAL',_utf8mb4'MAX_TOTAL')) and (`effect_amount` is not null)) or ((`effect_type` in (_utf8mb4'ADD_PER_UNIT',_utf8mb4'QUOTE_REQUIRED')) and (`effect_amount` is null)))),
  CONSTRAINT `chk_pricing_rules_quote_required_stop` CHECK (((`effect_type` <> _utf8mb4'QUOTE_REQUIRED') or (`stop_processing` = 1))),
  CONSTRAINT `chk_pricing_rules_stop_processing` CHECK ((`stop_processing` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_rules`
--

LOCK TABLES `pricing_rules` WRITE;
/*!40000 ALTER TABLE `pricing_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_rule_condition_groups`
--

DROP TABLE IF EXISTS `pricing_rule_condition_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_rule_condition_groups` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `pricing_rule_id` binary(16) NOT NULL,
  `group_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pricing_rule_condition_groups_order` (`pricing_rule_id`,`group_order`),
  CONSTRAINT `fk_pricing_rule_condition_groups_rule` FOREIGN KEY (`pricing_rule_id`) REFERENCES `pricing_rules` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_rule_condition_groups`
--

LOCK TABLES `pricing_rule_condition_groups` WRITE;
/*!40000 ALTER TABLE `pricing_rule_condition_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_rule_condition_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_rule_conditions`
--

DROP TABLE IF EXISTS `pricing_rule_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_rule_conditions` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `pricing_rule_condition_group_id` binary(16) NOT NULL,
  `subject_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `service_option_id` binary(16) DEFAULT NULL,
  `context_attribute_id` smallint unsigned DEFAULT NULL,
  `operator` varchar(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_number` decimal(19,6) DEFAULT NULL,
  `value_number_high` decimal(19,6) DEFAULT NULL,
  `value_boolean` tinyint(1) DEFAULT NULL,
  `value_choice_id` binary(16) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_pricing_rule_conditions_group` (`pricing_rule_condition_group_id`),
  KEY `idx_pricing_rule_conditions_option` (`service_option_id`),
  KEY `idx_pricing_rule_conditions_context` (`context_attribute_id`),
  KEY `idx_pricing_rule_conditions_choice` (`value_choice_id`),
  CONSTRAINT `fk_pricing_rule_conditions_group` FOREIGN KEY (`pricing_rule_condition_group_id`) REFERENCES `pricing_rule_condition_groups` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_pricing_rule_conditions_option` FOREIGN KEY (`service_option_id`) REFERENCES `service_options` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pricing_rule_conditions_context` FOREIGN KEY (`context_attribute_id`) REFERENCES `pricing_context_attributes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pricing_rule_conditions_choice` FOREIGN KEY (`value_choice_id`) REFERENCES `service_option_choices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_pricing_rule_conditions_subject_type` CHECK ((`subject_type` in (_utf8mb4'OPTION_CHOICE',_utf8mb4'OPTION_NUMERIC_VALUE',_utf8mb4'OPTION_BOOLEAN_VALUE',_utf8mb4'ITEM_QUANTITY',_utf8mb4'CONTEXT_ATTRIBUTE'))),
  CONSTRAINT `chk_pricing_rule_conditions_operator` CHECK ((`operator` in (_utf8mb4'EQ',_utf8mb4'NEQ',_utf8mb4'GT',_utf8mb4'GTE',_utf8mb4'LT',_utf8mb4'LTE',_utf8mb4'IN',_utf8mb4'NOT_IN',_utf8mb4'BETWEEN'))),
  CONSTRAINT `chk_pricing_rule_conditions_option_subject` CHECK (((`subject_type` in (_utf8mb4'OPTION_CHOICE',_utf8mb4'OPTION_NUMERIC_VALUE',_utf8mb4'OPTION_BOOLEAN_VALUE')) = (`service_option_id` is not null))),
  CONSTRAINT `chk_pricing_rule_conditions_context_subject` CHECK (((`subject_type` = _utf8mb4'CONTEXT_ATTRIBUTE') = (`context_attribute_id` is not null))),
  CONSTRAINT `chk_pricing_rule_conditions_between` CHECK (((`operator` <> _utf8mb4'BETWEEN') or ((`value_number` is not null) and (`value_number_high` is not null) and (`value_number_high` > `value_number`)))),
  CONSTRAINT `chk_pricing_rule_conditions_boolean_operator` CHECK (((`subject_type` <> _utf8mb4'OPTION_BOOLEAN_VALUE') or (`operator` in (_utf8mb4'EQ',_utf8mb4'NEQ')))),
  CONSTRAINT `chk_pricing_rule_conditions_choice_operator` CHECK (((`subject_type` <> _utf8mb4'OPTION_CHOICE') or (`operator` in (_utf8mb4'EQ',_utf8mb4'NEQ',_utf8mb4'IN',_utf8mb4'NOT_IN')))),
  CONSTRAINT `chk_pricing_rule_conditions_choice_value` CHECK (((`value_choice_id` is null) or (`subject_type` = _utf8mb4'OPTION_CHOICE')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_rule_conditions`
--

LOCK TABLES `pricing_rule_conditions` WRITE;
/*!40000 ALTER TABLE `pricing_rule_conditions` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_rule_conditions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_rule_condition_values`
--

DROP TABLE IF EXISTS `pricing_rule_condition_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_rule_condition_values` (
  `pricing_rule_condition_id` binary(16) NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `value_number` decimal(19,6) DEFAULT NULL,
  `value_choice_id` binary(16) DEFAULT NULL,
  PRIMARY KEY (`pricing_rule_condition_id`,`sort_order`),
  KEY `idx_pricing_rule_condition_values_choice` (`value_choice_id`),
  CONSTRAINT `fk_pricing_rule_condition_values_condition` FOREIGN KEY (`pricing_rule_condition_id`) REFERENCES `pricing_rule_conditions` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_pricing_rule_condition_values_choice` FOREIGN KEY (`value_choice_id`) REFERENCES `service_option_choices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_pricing_rule_condition_values_one_value` CHECK ((((`value_number` is not null) and (`value_choice_id` is null)) or ((`value_number` is null) and (`value_choice_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_rule_condition_values`
--

LOCK TABLES `pricing_rule_condition_values` WRITE;
/*!40000 ALTER TABLE `pricing_rule_condition_values` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_rule_condition_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_rule_tiers`
--

DROP TABLE IF EXISTS `pricing_rule_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_rule_tiers` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `pricing_rule_id` binary(16) NOT NULL,
  `tier_order` smallint unsigned NOT NULL,
  `from_unit` decimal(19,6) NOT NULL,
  `to_unit` decimal(19,6) DEFAULT NULL,
  `charge_unit_size` decimal(19,6) NOT NULL DEFAULT '1.000000',
  `rate_amount` decimal(19,6) NOT NULL,
  `tier_pricing_mode` varchar(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pricing_rule_tiers_order` (`pricing_rule_id`,`tier_order`),
  CONSTRAINT `fk_pricing_rule_tiers_rule` FOREIGN KEY (`pricing_rule_id`) REFERENCES `pricing_rules` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_pricing_rule_tiers_mode` CHECK ((`tier_pricing_mode` in (_utf8mb4'FLAT',_utf8mb4'PER_UNIT'))),
  CONSTRAINT `chk_pricing_rule_tiers_range` CHECK (((`to_unit` is null) or (`to_unit` > `from_unit`))),
  CONSTRAINT `chk_pricing_rule_tiers_from` CHECK ((`from_unit` >= 0)),
  CONSTRAINT `chk_pricing_rule_tiers_rate` CHECK ((`rate_amount` >= 0)),
  CONSTRAINT `chk_pricing_rule_tiers_charge_unit` CHECK ((`charge_unit_size` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_rule_tiers`
--

LOCK TABLES `pricing_rule_tiers` WRITE;
/*!40000 ALTER TABLE `pricing_rule_tiers` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_rule_tiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `property_relationship_types`
--

DROP TABLE IF EXISTS `property_relationship_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_relationship_types` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_relationship_types_code` (`code`),
  KEY `idx_property_relationship_types_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_property_relationship_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_property_relationship_types_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_property_relationship_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `property_relationship_types`
--

LOCK TABLES `property_relationship_types` WRITE;
/*!40000 ALTER TABLE `property_relationship_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `property_relationship_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `property_types`
--

DROP TABLE IF EXISTS `property_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_types` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_types_code` (`code`),
  KEY `idx_property_types_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_property_types_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_property_types_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_property_types_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_property_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `property_types`
--

LOCK TABLES `property_types` WRITE;
/*!40000 ALTER TABLE `property_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `property_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ratings`
--

DROP TABLE IF EXISTS `ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ratings` (
  `booking_id` binary(16) NOT NULL,
  `rating_value` tinyint unsigned NOT NULL,
  `comment` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`booking_id`),
  KEY `idx_ratings_value_created` (`rating_value`,`created_at`),
  KEY `idx_ratings_created_at` (`created_at`),
  CONSTRAINT `fk_ratings_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_ratings_comment` CHECK (((`comment` is null) or (char_length(trim(`comment`)) between 2 and 1000))),
  CONSTRAINT `chk_ratings_value` CHECK ((`rating_value` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ratings`
--

LOCK TABLES `ratings` WRITE;
/*!40000 ALTER TABLE `ratings` DISABLE KEYS */;
/*!40000 ALTER TABLE `ratings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`),
  CONSTRAINT `chk_roles_code_not_blank` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_roles_name_not_blank` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_capabilities`
--

DROP TABLE IF EXISTS `service_capabilities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_capabilities` (
  `service_id` binary(16) NOT NULL,
  `capability_type_id` smallint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`service_id`,`capability_type_id`),
  KEY `idx_service_capabilities_type` (`capability_type_id`),
  CONSTRAINT `fk_service_capabilities_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_capabilities_type` FOREIGN KEY (`capability_type_id`) REFERENCES `service_capability_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_capabilities`
--

LOCK TABLES `service_capabilities` WRITE;
/*!40000 ALTER TABLE `service_capabilities` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_capabilities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_capability_types`
--

DROP TABLE IF EXISTS `service_capability_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_capability_types` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_capability_types_code` (`code`),
  KEY `idx_service_capability_types_active` (`is_active`),
  CONSTRAINT `chk_service_capability_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_capability_types_code` CHECK ((char_length(trim(`code`)) between 2 and 60)),
  CONSTRAINT `chk_service_capability_types_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_service_capability_types_name` CHECK ((char_length(trim(`name`)) between 2 and 120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_capability_types`
--

LOCK TABLES `service_capability_types` WRITE;
/*!40000 ALTER TABLE `service_capability_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_capability_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_categories`
--

DROP TABLE IF EXISTS `service_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_categories_code` (`code`),
  KEY `idx_service_categories_active_order` (`is_active`,`display_order`),
  KEY `idx_service_categories_name` (`name`),
  CONSTRAINT `chk_service_categories_code` CHECK ((char_length(trim(`code`)) between 2 and 60)),
  CONSTRAINT `chk_service_categories_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_service_categories_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_categories_name` CHECK ((char_length(trim(`name`)) between 2 and 120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_categories`
--

LOCK TABLES `service_categories` WRITE;
/*!40000 ALTER TABLE `service_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contract_statuses`
--

DROP TABLE IF EXISTS `service_contract_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contract_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_statuses_code` (`code`),
  KEY `idx_service_contract_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_service_contract_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_contract_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_service_contract_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_service_contract_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 120)),
  CONSTRAINT `chk_service_contract_statuses_terminal` CHECK ((`is_terminal` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contract_statuses`
--

LOCK TABLES `service_contract_statuses` WRITE;
/*!40000 ALTER TABLE `service_contract_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contract_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contracts`
--

DROP TABLE IF EXISTS `service_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contracts` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `contract_number` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `customer_user_id` binary(16) NOT NULL,
  `customer_property_id` binary(16) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `requested_service_ids` json DEFAULT NULL,
  `requested_all_services` tinyint(1) NOT NULL DEFAULT '0',
  `requested_starts_on` date DEFAULT NULL,
  `customer_note` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `internal_note` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `starts_at` datetime(6) DEFAULT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `term_months` smallint unsigned DEFAULT NULL,
  `currency_id` smallint unsigned DEFAULT NULL,
  `quoted_amount` decimal(19,6) DEFAULT NULL,
  `agreement_snapshot` json DEFAULT NULL,
  `agreement_hash` binary(32) DEFAULT NULL,
  `accepted_at` datetime(6) DEFAULT NULL,
  `accepted_by_user_id` binary(16) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contracts_number` (`contract_number`),
  KEY `idx_service_contracts_customer` (`customer_user_id`,`created_at`),
  KEY `idx_service_contracts_property` (`customer_property_id`,`customer_user_id`),
  KEY `idx_service_contracts_status` (`status_id`,`created_at`),
  KEY `idx_service_contracts_currency` (`currency_id`),
  KEY `idx_service_contracts_accepted_by` (`accepted_by_user_id`),
  KEY `idx_service_contracts_term` (`status_id`,`ends_at`),
  CONSTRAINT `fk_service_contracts_customer` FOREIGN KEY (`customer_user_id`) REFERENCES `customer_profiles` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contracts_property` FOREIGN KEY (`customer_property_id`, `customer_user_id`) REFERENCES `customer_properties` (`id`, `customer_user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contracts_status` FOREIGN KEY (`status_id`) REFERENCES `service_contract_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contracts_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contracts_accepted_by` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_contracts_number` CHECK ((char_length(trim(`contract_number`)) between 6 and 40)),
  CONSTRAINT `chk_service_contracts_requested_all` CHECK ((`requested_all_services` in (0,1))),
  CONSTRAINT `chk_service_contracts_customer_note` CHECK (((`customer_note` is null) or (char_length(trim(`customer_note`)) between 2 and 1000))),
  CONSTRAINT `chk_service_contracts_internal_note` CHECK (((`internal_note` is null) or (char_length(trim(`internal_note`)) between 2 and 1000))),
  CONSTRAINT `chk_service_contracts_term_period` CHECK (((`starts_at` is null) or (`ends_at` is null) or (`ends_at` > `starts_at`))),
  CONSTRAINT `chk_service_contracts_term_months` CHECK (((`term_months` is null) or (`term_months` between 1 and 120))),
  CONSTRAINT `chk_service_contracts_quoted_amount` CHECK (((`quoted_amount` is null) or (`quoted_amount` >= 0))),
  CONSTRAINT `chk_service_contracts_quote_currency_pairing` CHECK ((((`quoted_amount` is null) and (`currency_id` is null)) or ((`quoted_amount` is not null) and (`currency_id` is not null)))),
  CONSTRAINT `chk_service_contracts_acceptance_pairing` CHECK ((((`accepted_at` is null) and (`accepted_by_user_id` is null) and (`agreement_snapshot` is null) and (`agreement_hash` is null)) or ((`accepted_at` is not null) and (`accepted_by_user_id` is not null) and (`agreement_snapshot` is not null) and (`agreement_hash` is not null)))),
  CONSTRAINT `chk_service_contracts_status_changed` CHECK ((`status_changed_at` >= `created_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contracts`
--

LOCK TABLES `service_contracts` WRITE;
/*!40000 ALTER TABLE `service_contracts` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contracts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contract_items`
--

DROP TABLE IF EXISTS `service_contract_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contract_items` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_contract_id` binary(16) NOT NULL,
  `service_id` binary(16) NOT NULL,
  `service_code_snapshot` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `service_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `entitlement_mode` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `included_visits` smallint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_items_contract_service` (`service_contract_id`,`service_id`),
  UNIQUE KEY `uq_service_contract_items_id_contract` (`id`,`service_contract_id`),
  KEY `idx_service_contract_items_service` (`service_id`),
  CONSTRAINT `fk_service_contract_items_contract` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contract_items_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_contract_items_service_code` CHECK ((char_length(trim(`service_code_snapshot`)) between 2 and 80)),
  CONSTRAINT `chk_service_contract_items_service_name` CHECK ((char_length(trim(`service_name_snapshot`)) between 2 and 160)),
  CONSTRAINT `chk_service_contract_items_mode` CHECK ((`entitlement_mode` in (_utf8mb4'LIMITED_VISITS',_utf8mb4'UNLIMITED'))),
  CONSTRAINT `chk_service_contract_items_visits` CHECK ((((`entitlement_mode` = _utf8mb4'LIMITED_VISITS') and (`included_visits` is not null) and (`included_visits` >= 1)) or ((`entitlement_mode` = _utf8mb4'UNLIMITED') and (`included_visits` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contract_items`
--

LOCK TABLES `service_contract_items` WRITE;
/*!40000 ALTER TABLE `service_contract_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contract_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contract_status_history`
--

DROP TABLE IF EXISTS `service_contract_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contract_status_history` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_contract_id` binary(16) NOT NULL,
  `from_status_id` tinyint unsigned DEFAULT NULL,
  `to_status_id` tinyint unsigned NOT NULL,
  `changed_by_user_id` binary(16) DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_contract_status_history_contract_time` (`service_contract_id`,`changed_at`),
  KEY `idx_contract_status_history_to_status_time` (`to_status_id`,`changed_at`),
  KEY `idx_contract_status_history_from_status` (`from_status_id`),
  KEY `idx_contract_status_history_changed_by` (`changed_by_user_id`),
  CONSTRAINT `fk_contract_status_history_contract` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contract_status_history_changed_by` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contract_status_history_from_status` FOREIGN KEY (`from_status_id`) REFERENCES `service_contract_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contract_status_history_to_status` FOREIGN KEY (`to_status_id`) REFERENCES `service_contract_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_contract_status_history_different` CHECK (((`from_status_id` is null) or (`from_status_id` <> `to_status_id`))),
  CONSTRAINT `chk_contract_status_history_reason` CHECK (((`reason` is null) or (char_length(trim(`reason`)) between 2 and 500)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contract_status_history`
--

LOCK TABLES `service_contract_status_history` WRITE;
/*!40000 ALTER TABLE `service_contract_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contract_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contract_acceptances`
--

DROP TABLE IF EXISTS `service_contract_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contract_acceptances` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_contract_id` binary(16) NOT NULL,
  `accepted_by_user_id` binary(16) NOT NULL,
  `agreement_snapshot` json NOT NULL,
  `agreement_hash` binary(32) NOT NULL,
  `accepted_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_acceptances_contract` (`service_contract_id`),
  KEY `idx_service_contract_acceptances_accepted_by` (`accepted_by_user_id`),
  CONSTRAINT `fk_contract_acceptances_contract` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contract_acceptances_accepted_by` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contract_acceptances`
--

LOCK TABLES `service_contract_acceptances` WRITE;
/*!40000 ALTER TABLE `service_contract_acceptances` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contract_acceptances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contract_billing_statuses`
--
-- BLUE V1 Phase 11 - the recurring Stripe Billing lifecycle of one Service
-- Contract's subscription (service_contract_billings.status_id), distinct
-- from service_contract_statuses (the Contract's own operational status).
--

DROP TABLE IF EXISTS `service_contract_billing_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contract_billing_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_billing_statuses_code` (`code`),
  KEY `idx_service_contract_billing_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_service_contract_billing_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_contract_billing_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_service_contract_billing_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_service_contract_billing_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contract_billing_statuses`
--

LOCK TABLES `service_contract_billing_statuses` WRITE;
/*!40000 ALTER TABLE `service_contract_billing_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contract_billing_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contract_billings`
--
-- BLUE V1 Phase 11 - exactly one recurring Stripe subscription-billing
-- record per Service Contract, created by App\Actions\Admin\Contract\
-- AdminApproveContractAction with an immutable billing_interval /
-- recurring_amount / currency_id commercial snapshot, PENDING_CHECKOUT,
-- no Stripe references yet. `chk_service_contract_billings_cancel_at` /
-- `_cancelled_at` allow a 1-second tolerance against `created_at`: Stripe's
-- own `cancel_at`/`canceled_at` are whole-second Unix timestamps
-- (App\Support\Contract\Billing\Gateway\StripeContractBillingGateway::tsToDatetime()
-- has no fractional part), while `created_at` is datetime(6) - without the
-- tolerance, a cancellation genuinely reported by Stripe as happening in
-- the same wall-clock second the row was created could be rejected purely
-- from truncation, never from a real ordering problem. Every Stripe
-- reference column is populated
-- only once the corresponding Stripe object exists (see
-- App\Actions\Contract\Billing\ProcessContractBillingWebhookAction).
--
-- `provider_cancellation_requested_at` / `_last_attempt_at` /
-- `_attempt_count` (BLUE V1 Phase 11 durable-retry hardening): together
-- these make the outbound provider-side Subscription cancellation durable
-- across a provider outage - `_requested_at` is stamped exactly once, in
-- the SAME transaction as the parent Contract's own CANCELLED transition
-- (App\Actions\Admin\Contract\AdminCancelContractAction), and stays set
-- until the eventual customer.subscription.deleted webhook sets
-- `cancelled_at`; App\Actions\Contract\Billing\
-- RetryPendingContractBillingCancellationsAction
-- (`contracts:retry-pending-billing-cancellations`) keeps re-attempting
-- delivery for any row where a request is pending but not yet reconciled.
--
-- `billing_suspended_at` (BLUE V1 Phase 11 billing-suspension recovery):
-- set only by App\Actions\Contract\Billing\
-- SuspendContractsPastDueBillingAction, in the same transaction as the
-- Contract's ACTIVE -> SUSPENDED transition, and cleared automatically once
-- billing recovers (App\Actions\Contract\Billing\
-- ProcessContractBillingWebhookAction::handleInvoicePaid()). Deliberately
-- left NULL by a manual Admin suspension (App\Actions\Admin\Contract\
-- AdminSuspendContractAction) - this is the one durable marker that keeps
-- an automatic billing recovery from ever reactivating a Contract an Admin
-- suspended on purpose.
--

DROP TABLE IF EXISTS `service_contract_billings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contract_billings` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_contract_id` binary(16) NOT NULL,
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `billing_interval` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recurring_amount` decimal(19,6) NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `stripe_customer_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_subscription_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_checkout_session_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_checkout_url` varchar(500) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_price_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_product_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `current_period_start` datetime(6) DEFAULT NULL,
  `current_period_end` datetime(6) DEFAULT NULL,
  `past_due_since` datetime(6) DEFAULT NULL,
  `cancel_at` datetime(6) DEFAULT NULL,
  `cancelled_at` datetime(6) DEFAULT NULL,
  `provider_cancellation_requested_at` datetime(6) DEFAULT NULL,
  `provider_cancellation_last_attempt_at` datetime(6) DEFAULT NULL,
  `provider_cancellation_attempt_count` int unsigned NOT NULL DEFAULT '0',
  `billing_suspended_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_billings_contract` (`service_contract_id`),
  UNIQUE KEY `uq_service_contract_billings_subscription` (`stripe_subscription_id`),
  UNIQUE KEY `uq_service_contract_billings_checkout_session` (`stripe_checkout_session_id`),
  KEY `idx_service_contract_billings_status` (`status_id`,`updated_at`),
  KEY `idx_service_contract_billings_currency` (`currency_id`),
  KEY `idx_service_contract_billings_past_due` (`status_id`,`past_due_since`),
  KEY `idx_service_contract_billings_customer` (`stripe_customer_id`),
  KEY `idx_service_contract_billings_provider_cancel_pending` (`provider_cancellation_requested_at`,`cancelled_at`),
  CONSTRAINT `fk_service_contract_billings_contract` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contract_billings_status` FOREIGN KEY (`status_id`) REFERENCES `service_contract_billing_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contract_billings_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_contract_billings_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_service_contract_billings_interval` CHECK ((`billing_interval` in (_utf8mb4'MONTHLY',_utf8mb4'YEARLY'))),
  CONSTRAINT `chk_service_contract_billings_amount` CHECK ((`recurring_amount` > 0)),
  CONSTRAINT `chk_service_contract_billings_customer_id` CHECK (((`stripe_customer_id` is null) or (char_length(trim(`stripe_customer_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_subscription_id` CHECK (((`stripe_subscription_id` is null) or (char_length(trim(`stripe_subscription_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_checkout_session_id` CHECK (((`stripe_checkout_session_id` is null) or (char_length(trim(`stripe_checkout_session_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_price_id` CHECK (((`stripe_price_id` is null) or (char_length(trim(`stripe_price_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_product_id` CHECK (((`stripe_product_id` is null) or (char_length(trim(`stripe_product_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_period` CHECK (((`current_period_start` is null) or (`current_period_end` is null) or (`current_period_end` > `current_period_start`))),
  CONSTRAINT `chk_service_contract_billings_past_due_since` CHECK (((`past_due_since` is null) or (`past_due_since` >= `created_at`))),
  CONSTRAINT `chk_service_contract_billings_cancel_at` CHECK (((`cancel_at` is null) or (`cancel_at` >= (`created_at` - INTERVAL 1 SECOND)))),
  CONSTRAINT `chk_service_contract_billings_cancelled_at` CHECK (((`cancelled_at` is null) or (`cancelled_at` >= (`created_at` - INTERVAL 1 SECOND)))),
  CONSTRAINT `chk_service_contract_billings_provider_cancel_requested_at` CHECK (((`provider_cancellation_requested_at` is null) or (`provider_cancellation_requested_at` >= `created_at`))),
  CONSTRAINT `chk_service_contract_billings_provider_cancel_last_attempt_at` CHECK (((`provider_cancellation_last_attempt_at` is null) or (`provider_cancellation_requested_at` is not null and `provider_cancellation_last_attempt_at` >= `provider_cancellation_requested_at`))),
  CONSTRAINT `chk_service_contract_billings_billing_suspended_at` CHECK (((`billing_suspended_at` is null) or (`billing_suspended_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contract_billings`
--

LOCK TABLES `service_contract_billings` WRITE;
/*!40000 ALTER TABLE `service_contract_billings` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contract_billings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_contract_billing_webhook_events`
--
-- BLUE V1 Phase 11 - the inbound Stripe Contract Billing webhook ledger
-- (App\Actions\Contract\Billing\ProcessContractBillingWebhookAction),
-- structurally identical to `payment_webhook_events` but fully separate -
-- a Subscription/Invoice/Checkout-Session event is never mixed with a
-- PaymentIntent one. Reuses `payment_webhook_event_statuses` (RECEIVED /
-- PROCESSED / IGNORED / FAILED) rather than duplicating that lookup table,
-- since the technical webhook-processing lifecycle is identical for both
-- domains.
--

DROP TABLE IF EXISTS `service_contract_billing_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contract_billing_webhook_events` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_event_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `service_contract_billing_id` binary(16) DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_object_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_hash` binary(32) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `processing_attempt_count` int unsigned NOT NULL DEFAULT '0',
  `received_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `processed_at` datetime(6) DEFAULT NULL,
  `last_error_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_billing_webhook_events_provider_event` (`provider_code`,`provider_event_id`),
  KEY `idx_service_contract_billing_webhook_events_billing` (`service_contract_billing_id`),
  KEY `idx_service_contract_billing_webhook_events_object_ref` (`provider_object_reference`),
  KEY `idx_service_contract_billing_webhook_events_status_received` (`status_id`,`received_at`),
  KEY `idx_service_contract_billing_webhook_events_received_at` (`received_at`),
  CONSTRAINT `fk_contract_billing_webhook_events_billing` FOREIGN KEY (`service_contract_billing_id`) REFERENCES `service_contract_billings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contract_billing_webhook_events_status` FOREIGN KEY (`status_id`) REFERENCES `payment_webhook_event_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_contract_billing_webhook_events_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_contract_billing_webhook_events_provider_event_id` CHECK ((char_length(trim(`provider_event_id`)) between 1 and 191)),
  CONSTRAINT `chk_contract_billing_webhook_events_event_type` CHECK ((char_length(trim(`event_type`)) between 1 and 100)),
  CONSTRAINT `chk_contract_billing_webhook_events_last_error_code` CHECK (((`last_error_code` is null) or (char_length(trim(`last_error_code`)) between 1 and 100))),
  CONSTRAINT `chk_contract_billing_webhook_events_last_error_message` CHECK (((`last_error_message` is null) or (char_length(trim(`last_error_message`)) between 2 and 500))),
  CONSTRAINT `chk_contract_billing_webhook_events_processed_at` CHECK (((`processed_at` is null) or (`processed_at` >= `received_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_contract_billing_webhook_events`
--

LOCK TABLES `service_contract_billing_webhook_events` WRITE;
/*!40000 ALTER TABLE `service_contract_billing_webhook_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_contract_billing_webhook_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_media`
--

DROP TABLE IF EXISTS `service_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_media` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_id` binary(16) NOT NULL,
  `storage_key` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `mime_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `alt_text` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `caption` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `file_size_bytes` bigint unsigned DEFAULT NULL,
  `width_pixels` int unsigned DEFAULT NULL,
  `height_pixels` int unsigned DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `primary_marker` tinyint unsigned GENERATED ALWAYS AS ((case when ((`is_primary` = true) and (`is_active` = true)) then 1 else NULL end)) STORED,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_media_storage_key` (`storage_key`),
  UNIQUE KEY `uq_service_media_primary` (`service_id`,`primary_marker`),
  KEY `idx_service_media_service_display` (`service_id`,`is_active`,`display_order`),
  KEY `idx_service_media_mime_type` (`mime_type`),
  CONSTRAINT `fk_service_media_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_media_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_media_alt_text` CHECK ((char_length(trim(`alt_text`)) between 2 and 250)),
  CONSTRAINT `chk_service_media_caption` CHECK (((`caption` is null) or (char_length(trim(`caption`)) > 0))),
  CONSTRAINT `chk_service_media_file_size` CHECK (((`file_size_bytes` is null) or (`file_size_bytes` > 0))),
  CONSTRAINT `chk_service_media_height` CHECK (((`height_pixels` is null) or (`height_pixels` > 0))),
  CONSTRAINT `chk_service_media_mime_type` CHECK ((char_length(trim(`mime_type`)) between 3 and 100)),
  CONSTRAINT `chk_service_media_primary_flag` CHECK ((`is_primary` in (0,1))),
  CONSTRAINT `chk_service_media_storage_key` CHECK ((char_length(trim(`storage_key`)) between 1 and 500)),
  CONSTRAINT `chk_service_media_width` CHECK (((`width_pixels` is null) or (`width_pixels` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_media`
--

LOCK TABLES `service_media` WRITE;
/*!40000 ALTER TABLE `service_media` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_media` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_option_choices`
--

DROP TABLE IF EXISTS `service_option_choices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_option_choices` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_option_id` binary(16) NOT NULL,
  `code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_option_choices_option_code` (`service_option_id`,`code`),
  UNIQUE KEY `uq_service_option_choices_option_name` (`service_option_id`,`name`),
  KEY `idx_service_option_choices_option_active_order` (`service_option_id`,`is_active`,`display_order`),
  CONSTRAINT `fk_service_option_choices_option` FOREIGN KEY (`service_option_id`) REFERENCES `service_options` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_option_choices_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_option_choices_code` CHECK ((char_length(trim(`code`)) between 2 and 80)),
  CONSTRAINT `chk_service_option_choices_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_service_option_choices_name` CHECK ((char_length(trim(`name`)) between 2 and 160))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_option_choices`
--

LOCK TABLES `service_option_choices` WRITE;
/*!40000 ALTER TABLE `service_option_choices` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_option_choices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_option_numeric_rules`
--

DROP TABLE IF EXISTS `service_option_numeric_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_option_numeric_rules` (
  `service_option_id` binary(16) NOT NULL,
  `measurement_unit_id` smallint unsigned DEFAULT NULL,
  `minimum_value` decimal(19,6) NOT NULL,
  `maximum_value` decimal(19,6) NOT NULL,
  `step_value` decimal(19,6) NOT NULL DEFAULT '1.000000',
  `default_value` decimal(19,6) DEFAULT NULL,
  `decimal_places` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`service_option_id`),
  KEY `idx_numeric_rules_measurement_unit` (`measurement_unit_id`),
  CONSTRAINT `fk_numeric_rules_measurement_unit` FOREIGN KEY (`measurement_unit_id`) REFERENCES `measurement_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_numeric_rules_service_option` FOREIGN KEY (`service_option_id`) REFERENCES `service_options` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_numeric_rules_decimal_places` CHECK ((`decimal_places` between 0 and 6)),
  CONSTRAINT `chk_numeric_rules_default` CHECK (((`default_value` is null) or (`default_value` between `minimum_value` and `maximum_value`))),
  CONSTRAINT `chk_numeric_rules_minimum` CHECK ((`minimum_value` >= 0)),
  CONSTRAINT `chk_numeric_rules_range` CHECK ((`maximum_value` > `minimum_value`)),
  CONSTRAINT `chk_numeric_rules_step` CHECK (((`step_value` > 0) and (`step_value` <= (`maximum_value` - `minimum_value`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_option_numeric_rules`
--

LOCK TABLES `service_option_numeric_rules` WRITE;
/*!40000 ALTER TABLE `service_option_numeric_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_option_numeric_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_option_selection_rules`
--

DROP TABLE IF EXISTS `service_option_selection_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_option_selection_rules` (
  `service_option_id` binary(16) NOT NULL,
  `minimum_selections` smallint unsigned NOT NULL DEFAULT '0',
  `maximum_selections` smallint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`service_option_id`),
  CONSTRAINT `fk_selection_rules_service_option` FOREIGN KEY (`service_option_id`) REFERENCES `service_options` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_selection_rules_maximum` CHECK ((`maximum_selections` > 0)),
  CONSTRAINT `chk_selection_rules_range` CHECK ((`minimum_selections` <= `maximum_selections`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_option_selection_rules`
--

LOCK TABLES `service_option_selection_rules` WRITE;
/*!40000 ALTER TABLE `service_option_selection_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_option_selection_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_option_types`
--

DROP TABLE IF EXISTS `service_option_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_option_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `has_predefined_choices` tinyint(1) NOT NULL DEFAULT '0',
  `allows_multiple_values` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_option_types_code` (`code`),
  CONSTRAINT `chk_option_type_multiple_requires_choices` CHECK (((`allows_multiple_values` = false) or (`has_predefined_choices` = true))),
  CONSTRAINT `chk_service_option_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_option_types_choices` CHECK ((`has_predefined_choices` in (0,1))),
  CONSTRAINT `chk_service_option_types_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_service_option_types_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_service_option_types_multiple` CHECK ((`allows_multiple_values` in (0,1))),
  CONSTRAINT `chk_service_option_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_option_types`
--

LOCK TABLES `service_option_types` WRITE;
/*!40000 ALTER TABLE `service_option_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_option_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_options`
--

DROP TABLE IF EXISTS `service_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_options` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_id` binary(16) NOT NULL,
  `option_type_id` tinyint unsigned NOT NULL,
  `code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_options_service_code` (`service_id`,`code`),
  UNIQUE KEY `uq_service_options_id_service` (`id`,`service_id`),
  KEY `idx_service_options_service_active_order` (`service_id`,`is_active`,`display_order`),
  KEY `idx_service_options_type_id` (`option_type_id`),
  CONSTRAINT `fk_service_options_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_options_type` FOREIGN KEY (`option_type_id`) REFERENCES `service_option_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_options_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_options_code` CHECK ((char_length(trim(`code`)) between 2 and 80)),
  CONSTRAINT `chk_service_options_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_service_options_name` CHECK ((char_length(trim(`name`)) between 2 and 160)),
  CONSTRAINT `chk_service_options_required` CHECK ((`is_required` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_options`
--

LOCK TABLES `service_options` WRITE;
/*!40000 ALTER TABLE `service_options` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_specializations`
--

DROP TABLE IF EXISTS `service_specializations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_specializations` (
  `service_id` binary(16) NOT NULL,
  `specialization_id` int unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `primary_marker` tinyint unsigned GENERATED ALWAYS AS ((case when ((`is_primary` = true) and (`is_active` = true)) then 1 else NULL end)) STORED,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`service_id`,`specialization_id`),
  UNIQUE KEY `uq_service_specializations_primary` (`service_id`,`primary_marker`),
  KEY `idx_service_specializations_specialization` (`specialization_id`),
  KEY `idx_service_specializations_service_active` (`service_id`,`is_active`,`display_order`),
  KEY `idx_service_specializations_matching` (`specialization_id`,`is_active`,`service_id`),
  CONSTRAINT `fk_service_specializations_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_specializations_specialization` FOREIGN KEY (`specialization_id`) REFERENCES `specializations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_specializations_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_specializations_primary` CHECK ((`is_primary` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_specializations`
--

LOCK TABLES `service_specializations` WRITE;
/*!40000 ALTER TABLE `service_specializations` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_specializations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_zones`
--

DROP TABLE IF EXISTS `service_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_zones` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_zones_code` (`code`),
  KEY `idx_service_zones_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_service_zones_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_zones_code` CHECK ((char_length(trim(`code`)) between 2 and 60)),
  CONSTRAINT `chk_service_zones_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_service_zones_name` CHECK ((char_length(trim(`name`)) between 2 and 120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_zones`
--

LOCK TABLES `service_zones` WRITE;
/*!40000 ALTER TABLE `service_zones` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_zones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_zone_areas`
--

DROP TABLE IF EXISTS `service_zone_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_zone_areas` (
  `area_id` int unsigned NOT NULL,
  `service_zone_id` smallint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`area_id`),
  KEY `idx_service_zone_areas_zone` (`service_zone_id`),
  CONSTRAINT `fk_service_zone_areas_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_zone_areas_zone` FOREIGN KEY (`service_zone_id`) REFERENCES `service_zones` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_zone_areas`
--

LOCK TABLES `service_zone_areas` WRITE;
/*!40000 ALTER TABLE `service_zone_areas` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_zone_areas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `category_id` int unsigned NOT NULL,
  `code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `slug` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `short_description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_code` (`code`),
  UNIQUE KEY `uq_services_slug` (`slug`),
  UNIQUE KEY `uq_services_category_name` (`category_id`,`name`),
  KEY `idx_services_category_active_order` (`category_id`,`is_active`,`display_order`),
  KEY `idx_services_active_name` (`is_active`,`name`),
  CONSTRAINT `fk_services_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_services_code` CHECK ((char_length(trim(`code`)) between 2 and 80)),
  CONSTRAINT `chk_services_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_services_is_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_services_name` CHECK ((char_length(trim(`name`)) between 2 and 160)),
  CONSTRAINT `chk_services_short_description` CHECK (((`short_description` is null) or (char_length(trim(`short_description`)) > 0))),
  CONSTRAINT `chk_services_slug` CHECK ((char_length(trim(`slug`)) between 2 and 160))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `specializations`
--

DROP TABLE IF EXISTS `specializations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `specializations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_specializations_code` (`code`),
  UNIQUE KEY `uq_specializations_name` (`name`),
  KEY `idx_specializations_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_specializations_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_specializations_code` CHECK ((char_length(trim(`code`)) between 2 and 80)),
  CONSTRAINT `chk_specializations_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_specializations_name` CHECK ((char_length(trim(`name`)) between 2 and 150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `specializations`
--

LOCK TABLES `specializations` WRITE;
/*!40000 ALTER TABLE `specializations` DISABLE KEYS */;
/*!40000 ALTER TABLE `specializations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_messages`
--

DROP TABLE IF EXISTS `support_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_messages` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `support_request_id` binary(16) NOT NULL,
  `sender_user_id` binary(16) NOT NULL,
  `message_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_support_messages_request_time` (`support_request_id`,`created_at`),
  KEY `idx_support_messages_sender_time` (`sender_user_id`,`created_at`),
  CONSTRAINT `fk_support_messages_request` FOREIGN KEY (`support_request_id`) REFERENCES `support_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_support_messages_sender` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_support_messages_body` CHECK ((char_length(trim(`message_body`)) between 1 and 5000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_messages`
--

LOCK TABLES `support_messages` WRITE;
/*!40000 ALTER TABLE `support_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_request_statuses`
--

DROP TABLE IF EXISTS `support_request_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_request_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_request_statuses_code` (`code`),
  KEY `idx_support_request_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_support_request_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_support_request_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_support_request_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_support_request_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 120)),
  CONSTRAINT `chk_support_request_statuses_terminal` CHECK ((`is_terminal` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_request_statuses`
--

LOCK TABLES `support_request_statuses` WRITE;
/*!40000 ALTER TABLE `support_request_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_request_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_requests`
--

DROP TABLE IF EXISTS `support_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_requests` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `request_number` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `customer_user_id` binary(16) NOT NULL,
  `booking_id` binary(16) DEFAULT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `assigned_admin_user_id` binary(16) DEFAULT NULL,
  `subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `resolved_at` datetime(6) DEFAULT NULL,
  `closed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_requests_request_number` (`request_number`),
  KEY `idx_support_requests_customer_created` (`customer_user_id`,`created_at`),
  KEY `idx_support_requests_booking` (`booking_id`),
  KEY `idx_support_requests_status_created` (`status_id`,`created_at`),
  KEY `idx_support_requests_assigned_admin_status` (`assigned_admin_user_id`,`status_id`),
  KEY `idx_support_requests_created_at` (`created_at`),
  CONSTRAINT `fk_support_requests_assigned_admin` FOREIGN KEY (`assigned_admin_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_support_requests_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_support_requests_customer` FOREIGN KEY (`customer_user_id`) REFERENCES `customer_profiles` (`user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_support_requests_status` FOREIGN KEY (`status_id`) REFERENCES `support_request_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_support_requests_closed_at` CHECK (((`closed_at` is null) or (`closed_at` >= `created_at`))),
  CONSTRAINT `chk_support_requests_number` CHECK ((char_length(trim(`request_number`)) between 6 and 40)),
  CONSTRAINT `chk_support_requests_resolution_order` CHECK (((`resolved_at` is null) or (`closed_at` is null) or (`closed_at` >= `resolved_at`))),
  CONSTRAINT `chk_support_requests_resolved_at` CHECK (((`resolved_at` is null) or (`resolved_at` >= `created_at`))),
  CONSTRAINT `chk_support_requests_status_changed` CHECK ((`status_changed_at` >= `created_at`)),
  CONSTRAINT `chk_support_requests_subject` CHECK ((char_length(trim(`subject`)) between 3 and 200))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_requests`
--

LOCK TABLES `support_requests` WRITE;
/*!40000 ALTER TABLE `support_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `technician_assignments`
--

DROP TABLE IF EXISTS `technician_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `technician_assignments` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_item_id` binary(16) NOT NULL,
  `technician_id` binary(16) NOT NULL,
  `specialization_id` int unsigned NOT NULL,
  `assigned_by_user_id` binary(16) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '1',
  `assigned_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `released_at` datetime(6) DEFAULT NULL,
  `released_by_user_id` binary(16) DEFAULT NULL,
  `release_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `internal_note` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `active_assignment_marker` tinyint unsigned GENERATED ALWAYS AS ((case when (`released_at` is null) then 1 else NULL end)) STORED,
  `active_primary_marker` tinyint unsigned GENERATED ALWAYS AS ((case when ((`released_at` is null) and (`is_primary` = true)) then 1 else NULL end)) STORED,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_technician_assignments_active_technician` (`booking_item_id`,`technician_id`,`active_assignment_marker`),
  UNIQUE KEY `uq_technician_assignments_active_primary` (`booking_item_id`,`active_primary_marker`),
  KEY `idx_technician_assignments_booking_item` (`booking_item_id`,`released_at`),
  KEY `idx_technician_assignments_technician` (`technician_id`,`released_at`,`assigned_at`),
  KEY `idx_technician_assignments_specialization` (`specialization_id`),
  KEY `idx_technician_assignments_assigned_by` (`assigned_by_user_id`),
  KEY `idx_technician_assignments_released_by` (`released_by_user_id`),
  CONSTRAINT `fk_technician_assignments_assigned_by` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_technician_assignments_booking_item` FOREIGN KEY (`booking_item_id`) REFERENCES `booking_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_technician_assignments_released_by` FOREIGN KEY (`released_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_technician_assignments_specialization` FOREIGN KEY (`specialization_id`) REFERENCES `specializations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_technician_assignments_technician` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_technician_assignments_internal_note` CHECK (((`internal_note` is null) or (char_length(trim(`internal_note`)) between 2 and 1000))),
  CONSTRAINT `chk_technician_assignments_primary` CHECK ((`is_primary` in (0,1))),
  CONSTRAINT `chk_technician_assignments_release_data` CHECK ((((`released_at` is null) and (`released_by_user_id` is null) and (`release_reason` is null)) or ((`released_at` is not null) and (`released_by_user_id` is not null) and (`release_reason` is not null)))),
  CONSTRAINT `chk_technician_assignments_release_reason` CHECK (((`release_reason` is null) or (char_length(trim(`release_reason`)) between 2 and 500))),
  CONSTRAINT `chk_technician_assignments_released_at` CHECK (((`released_at` is null) or (`released_at` >= `assigned_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technician_assignments`
--

LOCK TABLES `technician_assignments` WRITE;
/*!40000 ALTER TABLE `technician_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `technician_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `technician_specializations`
--

DROP TABLE IF EXISTS `technician_specializations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `technician_specializations` (
  `technician_id` binary(16) NOT NULL,
  `specialization_id` int unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `primary_marker` tinyint unsigned GENERATED ALWAYS AS ((case when ((`is_primary` = true) and (`is_active` = true)) then 1 else NULL end)) STORED,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`technician_id`,`specialization_id`),
  UNIQUE KEY `uq_technician_specializations_primary` (`technician_id`,`primary_marker`),
  KEY `idx_technician_specializations_specialization` (`specialization_id`),
  KEY `idx_technician_specializations_active` (`technician_id`,`is_active`),
  KEY `idx_technician_specializations_matching` (`specialization_id`,`is_active`,`technician_id`),
  CONSTRAINT `fk_technician_specializations_specialization` FOREIGN KEY (`specialization_id`) REFERENCES `specializations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_technician_specializations_technician` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_technician_specializations_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_technician_specializations_primary` CHECK ((`is_primary` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technician_specializations`
--

LOCK TABLES `technician_specializations` WRITE;
/*!40000 ALTER TABLE `technician_specializations` DISABLE KEYS */;
/*!40000 ALTER TABLE `technician_specializations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `technician_statuses`
--

DROP TABLE IF EXISTS `technician_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `technician_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_assignable` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_technician_statuses_code` (`code`),
  KEY `idx_technician_statuses_active_order` (`is_active`,`display_order`),
  KEY `idx_technician_statuses_assignable` (`is_assignable`,`is_active`),
  CONSTRAINT `chk_technician_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_technician_statuses_assignable` CHECK ((`is_assignable` in (0,1))),
  CONSTRAINT `chk_technician_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_technician_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_technician_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technician_statuses`
--

LOCK TABLES `technician_statuses` WRITE;
/*!40000 ALTER TABLE `technician_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `technician_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `technicians`
--

DROP TABLE IF EXISTS `technicians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `technicians` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `employee_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `phone_number` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_phone_visible_to_customer` tinyint(1) NOT NULL DEFAULT '0',
  `internal_note` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_technicians_employee_code` (`employee_code`),
  UNIQUE KEY `uq_technicians_phone_number` (`phone_number`),
  UNIQUE KEY `uq_technicians_email` (`email`),
  KEY `idx_technicians_status` (`status_id`),
  KEY `idx_technicians_status_name` (`status_id`,`full_name`),
  KEY `idx_technicians_created_at` (`created_at`),
  CONSTRAINT `fk_technicians_status` FOREIGN KEY (`status_id`) REFERENCES `technician_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_technicians_email` CHECK (((`email` is null) or (char_length(trim(`email`)) > 0))),
  CONSTRAINT `chk_technicians_employee_code` CHECK ((char_length(trim(`employee_code`)) between 3 and 50)),
  CONSTRAINT `chk_technicians_full_name` CHECK ((char_length(trim(`full_name`)) between 2 and 150)),
  CONSTRAINT `chk_technicians_internal_note` CHECK (((`internal_note` is null) or (char_length(trim(`internal_note`)) between 2 and 1000))),
  CONSTRAINT `chk_technicians_phone_number` CHECK ((char_length(trim(`phone_number`)) between 8 and 20)),
  CONSTRAINT `chk_technicians_phone_visibility` CHECK ((`is_phone_visible_to_customer` in (0,1))),
  CONSTRAINT `chk_technicians_status_changed` CHECK ((`status_changed_at` >= `created_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technicians`
--

LOCK TABLES `technicians` WRITE;
/*!40000 ALTER TABLE `technicians` DISABLE KEYS */;
/*!40000 ALTER TABLE `technicians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_account_statuses`
--

DROP TABLE IF EXISTS `user_account_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_account_statuses` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_account_statuses_code` (`code`),
  UNIQUE KEY `uq_user_account_statuses_name` (`name`),
  KEY `idx_user_account_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_user_account_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_user_account_statuses_code` CHECK (((char_length(trim(`code`)) between 2 and 50) and regexp_like(`code`,_utf8mb4'^[A-Z][A-Z0-9_]*$'))),
  CONSTRAINT `chk_user_account_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 255))),
  CONSTRAINT `chk_user_account_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_account_statuses`
--

LOCK TABLES `user_account_statuses` WRITE;
/*!40000 ALTER TABLE `user_account_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_account_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_profiles`
--

DROP TABLE IF EXISTS `user_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_profiles` (
  `user_id` binary(16) NOT NULL,
  `full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`user_id`),
  KEY `idx_user_profiles_full_name` (`full_name`),
  CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_user_profiles_full_name` CHECK ((char_length(trim(`full_name`)) between 2 and 150)),
  CONSTRAINT `chk_user_profiles_full_name_trimmed` CHECK ((`full_name` = trim(`full_name`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_profiles`
--

LOCK TABLES `user_profiles` WRITE;
/*!40000 ALTER TABLE `user_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_roles` (
  `user_id` binary(16) NOT NULL,
  `role_id` smallint unsigned NOT NULL,
  `assigned_by_user_id` binary(16) DEFAULT NULL,
  `assigned_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_roles_role_id` (`role_id`),
  KEY `idx_user_roles_assigned_by_user_id` (`assigned_by_user_id`),
  CONSTRAINT `fk_user_roles_assigned_by` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `phone_number` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_status_id` smallint unsigned NOT NULL,
  `phone_verified_at` datetime(6) DEFAULT NULL,
  `last_login_at` datetime(6) DEFAULT NULL,
  `deleted_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_phone_number` (`phone_number`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_account_status_id` (`account_status_id`),
  KEY `idx_users_created_at` (`created_at`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_users_account_status` FOREIGN KEY (`account_status_id`) REFERENCES `user_account_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_users_email_not_blank` CHECK ((char_length(trim(`email`)) > 0)),
  CONSTRAINT `chk_users_phone_number_not_blank` CHECK ((char_length(trim(`phone_number`)) between 8 and 20)),
  CONSTRAINT `chk_users_deleted_at` CHECK (((`deleted_at` is null) or (`deleted_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
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

-- Dump completed on 2026-07-28 15:14:54
