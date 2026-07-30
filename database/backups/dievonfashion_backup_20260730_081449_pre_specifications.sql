-- MySQL dump 10.13  Distrib 5.7.44, for osx11.0 (x86_64)
--
-- Host: 127.0.0.1    Database: dievonfashion
-- ------------------------------------------------------
-- Server version	5.7.44

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `banners`
--

DROP TABLE IF EXISTS `banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `banners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `subtitle` text,
  `image` varchar(255) DEFAULT NULL,
  `link` varchar(255) DEFAULT 'shop',
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `sort_order` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `banners`
--

LOCK TABLES `banners` WRITE;
/*!40000 ALTER TABLE `banners` DISABLE KEYS */;
INSERT INTO `banners` VALUES (1,'The Silk Kurtis','Volume I — Fluid silhouettes draped in pure heavy mulberry silk.','silk_short_kurti_1784414719998.jpg','shop?category=Kurtis','Active',1),(2,'3-Piece Suits','Volume II — Elegant tailored brocades and soft raw silks.','silk_3_piece_1784414705496.jpg','shop?category=3+Piece+Suits','Active',2),(3,'Coord Sets','Volume III — Luxurious satin wide-legs and tailored organic linens.','linen_coord_1784414712638.jpg','shop?category=Coord+Sets','Active',3);
/*!40000 ALTER TABLE `banners` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blog_posts`
--

DROP TABLE IF EXISTS `blog_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blog_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'Style Guide',
  `image` varchar(255) DEFAULT NULL,
  `excerpt` text,
  `content` longtext NOT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text,
  `status` enum('Published','Draft') DEFAULT 'Published',
  `published_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blog_posts`
--

LOCK TABLES `blog_posts` WRITE;
/*!40000 ALTER TABLE `blog_posts` DISABLE KEYS */;
INSERT INTO `blog_posts` VALUES (1,'Summer Silk Draping: A Study in Elegance','summer-silk-draping-elegance','Style Guide','lookbook_3.png','How to style, fit, and accessorize our signature silk evening slip dress for summer soirées.','Draping is an ancient textile art form that speaks without words. In this chronicle, we explore the fluid movement and weights of pure organic mulberry silk. Our designers spent months researching Como silk weavers to formulate the precise weight profile of our Aurelia slip gown.\n\nTo accessorize our signature silk slip dress for summer soirée invitations, we suggest keeping things minimalist yet impactful. A delicate gold herringbone chain and a hand-selected baroque freshwater pearl suspended from a thin link will highlight the delicate cowl neckline.','silk, summer, style guide, evening wear',NULL,NULL,'Published','2026-07-15','2026-07-29 08:45:39'),(2,'Calfskin Sourcing: Preserving the Tuscan Heritage','calfskin-sourcing-tuscan-heritage','Atelier Craftsmanship','lookbook_2.png','A tour inside our leather ateliers in Florence, where raw leather meets gold hardware.','Tuscany is famous across the globe for its leatherwork heritage, but what sets the Elysian leather handbag apart? It starts at the selection tables, where our master artisans source only full-grain calfskins showing dense, uniform grain.\n\nVegetable tanning processes follow, utilising oak bark and chestnut extracts in a slow, 30-day barrel tanning cycle. This traditional, chemical-free method preserves the organic texture of the hides.','leather, florence, craftsmanship, luxury bags',NULL,NULL,'Published','2026-07-10','2026-07-29 08:45:39'),(3,'The Autumn Silhouette Sneak Preview','autumn-silhouette-sneak-preview','Editorial','lookbook_1.png','A sneak peek at next season\'s cashmere double-breasted trench coats and blazers.','As the warm coastal winds of summer mature, our design desk in Paris turns its focus to structural tailoring. The upcoming Autumn Collection centers on Virgin Wool and Cashmere outer layers.\n\nOur signature double-breasted trench coat is woven in Biella, Italy from a heavy, double-faced cashmere virgin wool blend.','autumn, trench coat, cashmere, paris',NULL,NULL,'Published','2026-07-01','2026-07-29 08:45:39');
/*!40000 ALTER TABLE `blog_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `brands`
--

DROP TABLE IF EXISTS `brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `brands`
--

LOCK TABLES `brands` WRITE;
/*!40000 ALTER TABLE `brands` DISABLE KEYS */;
/*!40000 ALTER TABLE `brands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `parent_id` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Kurtis',1,'2026-07-18 22:55:43',0),(2,'3 Piece Suits',2,'2026-07-18 22:55:43',0),(3,'Coord Sets',3,'2026-07-18 22:55:43',0),(4,'Short Kurtis',4,'2026-07-18 22:55:43',1),(5,'Women\'s Tops',5,'2026-07-18 22:55:43',0),(6,'Bottoms',6,'2026-07-18 22:55:43',0),(7,'Long Kurtis',2,'2026-07-26 23:11:12',1),(8,'Anarkali Sets',3,'2026-07-26 23:11:12',1),(9,'Sharara & Gharara Sets',4,'2026-07-26 23:11:12',1),(10,'Straight Cut Kurtis',5,'2026-07-26 23:11:12',1),(11,'Testing',0,'2026-07-26 23:11:49',5);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_addresses`
--

DROP TABLE IF EXISTS `customer_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `address_type` enum('shipping','billing','both') DEFAULT 'shipping',
  `full_name` varchar(191) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address_line1` varchar(255) NOT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL,
  `postcode` varchar(20) NOT NULL,
  `country` varchar(100) DEFAULT 'United Kingdom',
  `is_default` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_addresses`
--

LOCK TABLES `customer_addresses` WRITE;
/*!40000 ALTER TABLE `customer_addresses` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_logs`
--

DROP TABLE IF EXISTS `customer_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_logs`
--

LOCK TABLES `customer_logs` WRITE;
/*!40000 ALTER TABLE `customer_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_returns`
--

DROP TABLE IF EXISTS `customer_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_code` varchar(50) NOT NULL,
  `order_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `request_type` enum('return','exchange') NOT NULL,
  `reason` varchar(255) NOT NULL,
  `exchange_size` varchar(50) DEFAULT NULL,
  `details` text,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Pickup Scheduled','Quality Check','Completed') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_code` (`return_code`),
  KEY `customer_id` (`customer_id`),
  KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_returns`
--

LOCK TABLES `customer_returns` WRITE;
/*!40000 ALTER TABLE `customer_returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_tickets`
--

DROP TABLE IF EXISTS `customer_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_code` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('Open','In Progress','Resolved','Closed') DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_code` (`ticket_code`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_tickets`
--

LOCK TABLES `customer_tickets` WRITE;
/*!40000 ALTER TABLE `customer_tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'Prince Virani','princevir2610@gmail.com','$2y$10$acgFMm/rfzX0TfZp101qEeibBYsHiIgHU1BAUdhCivexLpuVSvqR6','09879092355',NULL,'2026-07-21 01:05:32','2026-07-24 00:47:42'),(2,'Prince Virani','hideinfotech@gmail.com','$2y$10$I0cD2ZUv/OVig/3vsmlheuvC5mYzcX3qQcFullEHfz9NojzLX3q1e','09879092355',NULL,'2026-07-24 00:17:34','2026-07-24 00:17:34'),(3,'Prince Virani','nirbhay.virani2610@gmail.com','$2y$10$Wi.oc1abPCcd/7SBitE.XOkKdprkjdcoiLmUaKWJt7/5pqhm3TSwK','09879092355',NULL,'2026-07-24 00:39:51','2026-07-24 00:39:51');
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_logs`
--

DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email_type` varchar(50) NOT NULL,
  `recipient` varchar(150) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text,
  `test_mode` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email_type` (`email_type`),
  KEY `recipient` (`recipient`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_logs`
--

LOCK TABLES `email_logs` WRITE;
/*!40000 ALTER TABLE `email_logs` DISABLE KEYS */;
INSERT INTO `email_logs` VALUES (1,'contact_admin_notice','princevir2610@gmail.com','? Contact Form: Private Fitting from Prince Virani','sent',NULL,1,'2026-07-28 01:14:14'),(2,'contact_customer_ack','princevir2610@gmail.com','We Have Received Your Inquiry — DIEVON Atelier','sent',NULL,1,'2026-07-28 01:14:18'),(3,'contact_admin_notice','princevir2610@gmail.com','? Contact Form: Private Fitting from Prince Virani','sent',NULL,1,'2026-07-28 01:14:59'),(4,'contact_customer_ack','princevir2610@gmail.com','We Have Received Your Inquiry — DIEVON Atelier','sent',NULL,1,'2026-07-28 01:15:01'),(5,'password_reset','princevir2610@gmail.com','Reset Your DIEVON Account Password','sent',NULL,1,'2026-07-28 01:17:00'),(6,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-397345 – ₹290.00 [Pending]','sent',NULL,1,'2026-07-28 01:27:40'),(7,'order_confirmation','princevir2610@gmail.com','Your DIEVON Order Confirmation [CB-397345]','sent',NULL,1,'2026-07-28 01:27:42'),(8,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-473401 – ₹160.00 [Pending]','sent',NULL,1,'2026-07-28 01:36:57'),(9,'order_confirmation','princevir2610@gmail.com','Your DIEVON Order Confirmation [CB-473401]','sent',NULL,1,'2026-07-28 01:37:02'),(10,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-976316 – ₹490.00 [Pending]','sent',NULL,1,'2026-07-28 01:43:13'),(11,'order_confirmation','princevir2610@gmail.com','Your DIEVON Order Confirmation [CB-976316]','sent',NULL,1,'2026-07-28 01:43:19'),(12,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-584266 – ₹3,333.00 [Pending]','sent',NULL,1,'2026-07-28 01:48:22'),(13,'order_confirmation','princevir2610@gmail.com','Your DIEVON Order Confirmation [CB-584266]','sent',NULL,1,'2026-07-28 01:48:25'),(14,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-357179 – ₹3,333.00 [Pending]','sent',NULL,1,'2026-07-28 02:03:17'),(15,'order_confirmation','princevir2610@gmail.com','Your DIEVON Order Confirmation [CB-357179]','sent',NULL,1,'2026-07-28 02:03:21'),(16,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-538888 – ₹135.00 [Pending]','sent',NULL,1,'2026-07-28 02:21:41'),(17,'order_confirmation','princevir2610@gmail.com','Your DIEVON Order Confirmation [CB-538888]','sent',NULL,1,'2026-07-28 02:21:45'),(18,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-180217 – ₹180.00 [Pending]','sent',NULL,1,'2026-07-29 00:17:52'),(19,'order_confirmation','debugtest@example.com','Your DIEVON Order Confirmation [CB-180217]','sent',NULL,1,'2026-07-29 00:17:55'),(20,'welcome','qa.verify.customer@example.com','Welcome to DIEVON Atelier','sent',NULL,1,'2026-07-29 00:26:30'),(21,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-342257 – ₹360.00 [Pending]','sent',NULL,1,'2026-07-29 00:26:33'),(22,'order_confirmation','qa.verify.customer@example.com','Your DIEVON Order Confirmation [CB-342257]','sent',NULL,1,'2026-07-29 00:26:34'),(23,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-704687 – ₹180.00 [Pending]','sent',NULL,1,'2026-07-29 01:03:37'),(24,'order_confirmation','regression@example.com','Your DIEVON Order Confirmation [CB-704687]','sent',NULL,1,'2026-07-29 01:03:38'),(25,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-348449 – ₹180.00 [Pending]','sent',NULL,1,'2026-07-29 01:04:39'),(26,'order_confirmation','sigvalid@example.com','Your DIEVON Order Confirmation [CB-348449]','sent',NULL,1,'2026-07-29 01:04:45'),(27,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-566917 – ₹180.00 [Pending]','sent',NULL,1,'2026-07-29 01:05:22'),(28,'order_confirmation','sigforged@example.com','Your DIEVON Order Confirmation [CB-566917]','sent',NULL,1,'2026-07-29 01:05:28'),(29,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-417912 – ₹180.00 [Pending]','sent',NULL,1,'2026-07-29 01:07:34'),(30,'order_confirmation','retest@example.com','Your DIEVON Order Confirmation [CB-417912]','sent',NULL,1,'2026-07-29 01:07:38'),(43,'welcome','tickets.qa@example.com','Welcome to DIEVON Atelier','sent',NULL,0,'2026-07-29 02:33:40'),(44,'welcome','refund.qa@example.com','Welcome to DIEVON Atelier','sent',NULL,0,'2026-07-29 02:37:59'),(45,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-793138 – ₹180.00 [Pending]','sent',NULL,0,'2026-07-29 02:38:04'),(46,'order_confirmation','refund.qa@example.com','Your DIEVON Order Confirmation [CB-793138]','sent',NULL,0,'2026-07-29 02:38:06'),(47,'order_status_update','refund.qa@example.com','Order Status Update: #CB-793138 is now Refunded','sent',NULL,0,'2026-07-29 02:38:32'),(48,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-478166 – ₹180.00 [Pending]','sent',NULL,0,'2026-07-29 02:40:36'),(49,'order_confirmation','image.test@example.com','Your DIEVON Order Confirmation [CB-478166]','sent',NULL,0,'2026-07-29 02:40:39'),(50,'welcome','full.qa@example.com','Welcome to DIEVON Atelier','sent',NULL,0,'2026-07-29 09:02:52'),(51,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-273593 – ₹180.00 [Pending]','sent',NULL,0,'2026-07-29 09:03:10'),(52,'order_confirmation','full.qa@example.com','Your DIEVON Order Confirmation [CB-273593]','sent',NULL,0,'2026-07-29 09:03:13'),(53,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-891907 – ₹330.00 [Pending]','sent',NULL,0,'2026-07-29 09:03:49'),(54,'order_confirmation','full.qa@example.com','Your DIEVON Order Confirmation [CB-891907]','sent',NULL,0,'2026-07-29 09:03:51'),(55,'order_status_update','full.qa@example.com','Order Status Update: #CB-891907 is now Delivered','sent',NULL,0,'2026-07-29 09:05:09'),(56,'contact_admin_notice','princevir2610@gmail.com','? Contact Form: General Enquiry from QA Inquiry Test','sent',NULL,0,'2026-07-29 09:48:57'),(57,'contact_customer_ack','qa.inquiry@example.com','We Have Received Your Inquiry — DIEVON Atelier','sent',NULL,0,'2026-07-29 09:48:59'),(58,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-261010 – ₹6,666.00 [Pending]','sent',NULL,0,'2026-07-29 19:55:34'),(59,'order_confirmation','testcustomer@example.com','Your DIEVON Order Confirmation [CB-261010]','sent',NULL,0,'2026-07-29 19:55:36'),(60,'order_status_update','testcustomer@example.com','Order Status Update: #CB-261010 is now Delivered','sent',NULL,0,'2026-07-29 19:57:04'),(61,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-607522 – ₹3,333.00 [Pending]','sent',NULL,0,'2026-07-29 19:57:16'),(62,'order_confirmation','testcustomer2@example.com','Your DIEVON Order Confirmation [CB-607522]','sent',NULL,0,'2026-07-29 19:57:19'),(63,'order_status_update','testcustomer2@example.com','Order Status Update: #CB-607522 is now Delivered','sent',NULL,0,'2026-07-29 19:58:24'),(64,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-632220 – ₹6,666.00 [Pending]','sent',NULL,0,'2026-07-29 20:05:16'),(65,'order_confirmation','canceltest@example.com','Your DIEVON Order Confirmation [CB-632220]','sent',NULL,0,'2026-07-29 20:05:18'),(66,'order_status_update','canceltest@example.com','Order Status Update: #CB-632220 is now Delivered','sent',NULL,0,'2026-07-29 20:05:35'),(67,'order_status_update','canceltest@example.com','Order Status Update: #CB-632220 is now Cancelled','sent',NULL,0,'2026-07-29 20:05:37'),(68,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-587200 – ₹3,333.00 [Pending]','sent',NULL,0,'2026-07-29 20:05:53'),(69,'order_confirmation','canceltest2@example.com','Your DIEVON Order Confirmation [CB-587200]','sent',NULL,0,'2026-07-29 20:05:55'),(70,'order_status_update','canceltest2@example.com','Order Status Update: #CB-587200 is now Cancelled','sent',NULL,0,'2026-07-29 20:05:57'),(71,'admin_order_notification','princevir2610@gmail.com','? NEW ORDER CB-753127 – ₹180.00 [Pending]','sent',NULL,0,'2026-07-29 21:09:14'),(72,'order_confirmation','smoketest@example.com','Your DIEVON Order Confirmation [CB-753127]','sent',NULL,0,'2026-07-29 21:09:16');
/*!40000 ALTER TABLE `email_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gallery`
--

DROP TABLE IF EXISTS `gallery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gallery` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gallery`
--

LOCK TABLES `gallery` WRITE;
/*!40000 ALTER TABLE `gallery` DISABLE KEYS */;
INSERT INTO `gallery` VALUES (1,'lookbook_1.png','The Autumn Silk & Wool Collection Edit',1,'2026-07-18 22:55:43'),(2,'lookbook_2.png','Bespoke Handcrafted Leather Goods Campaign',2,'2026-07-18 22:55:43'),(3,'lookbook_3.png','Tuscan Atelier Behind-the-Scenes Chronicles',3,'2026-07-18 22:55:43');
/*!40000 ALTER TABLE `gallery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `homepage_sections`
--

DROP TABLE IF EXISTS `homepage_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `homepage_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_key` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `eyebrow` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `sort_order` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_key` (`section_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `homepage_sections`
--

LOCK TABLES `homepage_sections` WRITE;
/*!40000 ALTER TABLE `homepage_sections` DISABLE KEYS */;
INSERT INTO `homepage_sections` VALUES (1,'hero_slider','Hero Banner Slider','Volume Collections',1,1),(2,'collections','Dievon Collections','Curated Ensembles',1,2),(3,'new_arrivals','New Arrivals','Latest Creations',1,3),(4,'best_sellers','Best Sellers & Trending','Curated Favorites',1,4),(5,'editorial_story','The Dievon Atelier Story','Craftsmanship & Heritage',1,5),(6,'reviews','Customer Reviews','Verified Ratings & Feedback',1,6),(7,'instagram','@DievonOfficial Instagram','Follow Our Atelier',1,7),(8,'newsletter','VIP Atelier Newsletter','Exclusive Offers & Releases',1,8);
/*!40000 ALTER TABLE `homepage_sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inquiries`
--

DROP TABLE IF EXISTS `inquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inquiries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inquiries`
--

LOCK TABLES `inquiries` WRITE;
/*!40000 ALTER TABLE `inquiries` DISABLE KEYS */;
INSERT INTO `inquiries` VALUES (1,'Prince Virani [Service: Private Fitting]','princevir2610@gmail.com','09879092355','dasd','2026-07-28 01:14:09',0),(2,'Prince Virani [Service: Private Fitting]','princevir2610@gmail.com','09879092355','dasd','2026-07-28 01:14:54',0);
/*!40000 ALTER TABLE `inquiries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mega_menu_links`
--

DROP TABLE IF EXISTS `mega_menu_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mega_menu_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `column_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `link_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `link_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mega_menu_links`
--

LOCK TABLES `mega_menu_links` WRITE;
/*!40000 ALTER TABLE `mega_menu_links` DISABLE KEYS */;
INSERT INTO `mega_menu_links` VALUES (1,'Occasions','Wedding Guest Edit','shop.php?category=3+Piece+Suits',1,'2026-07-19 00:06:07'),(2,'Occasions','Office Sophistication','shop.php?category=Coord+Sets',2,'2026-07-19 00:06:07'),(3,'Occasions','Private Party Gowns','shop.php?category=Kurtis',3,'2026-07-19 00:06:07'),(4,'Occasions','Festive Accents','shop.php?category=Short+Kurtis',4,'2026-07-19 00:06:07'),(5,'Journal & Story','Our Heritage','about.php',1,'2026-07-19 00:06:07'),(6,'Journal & Story','Lookbooks','gallery.php',2,'2026-07-19 00:06:07'),(7,'Journal & Story','Maison Journal','blog.php',3,'2026-07-19 00:06:07'),(8,'Journal & Story','Private Fittings','contact.php',4,'2026-07-19 00:06:07');
/*!40000 ALTER TABLE `mega_menu_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `newsletter_subscribers`
--

DROP TABLE IF EXISTS `newsletter_subscribers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletter_subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `newsletter_subscribers`
--

LOCK TABLES `newsletter_subscribers` WRITE;
/*!40000 ALTER TABLE `newsletter_subscribers` DISABLE KEYS */;
/*!40000 ALTER TABLE `newsletter_subscribers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `variant_name` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT '1',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_item_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `postcode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `items_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `last_notified_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `carrier` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `stock_deducted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `promo_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `delivery_charge` decimal(6,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'GBP',
  `currency_symbol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '£',
  `payment_method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'later',
  `payment_status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unpaid',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_code` (`order_code`),
  UNIQUE KEY `uq_order_code` (`order_code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (1,'CB-397345',NULL,'Nirbhay Virani','','+449879092355','79` Alicia Gardens','HA3 8JD','','[{\"cart_key\":\"5:var18\",\"product_id\":5,\"variant_id\":18,\"variant_name\":\"Size: M\",\"name\":\"Luna Satin Coord Set\",\"emoji\":\"\\ud83d\\udc57\",\"image\":\"dievon_leather_handbag.png\",\"price\":290,\"quantity\":1}]',290.00,'Pending','','','',0,'2026-07-28 00:27:37',NULL,0.00,0.00,'GBP','£','cod','Unpaid'),(2,'CB-473401',NULL,'Ashok Trada','','+447823606928','79` Alicia Gardens','HA3 8JD','','[{\"cart_key\":\"11:var37\",\"product_id\":11,\"variant_id\":37,\"variant_name\":\"Size: S\",\"name\":\"Sienna Wide-Leg Silk Trousers\",\"emoji\":\"\\ud83d\\udc56\",\"image\":\"silk_trousers_1784414749191.jpg\",\"price\":160,\"quantity\":1}]',160.00,'Pending','','','',0,'2026-07-28 00:36:52',NULL,0.00,0.00,'GBP','£','cod','Unpaid'),(3,'CB-976316',NULL,'Nirbhay Virani','','+449879092355','79` Alicia Gardens','HA3 8JD','','[{\"cart_key\":\"6:var20\",\"product_id\":6,\"variant_id\":20,\"variant_name\":\"Size: S\",\"name\":\"Soraya Linen Coord Set\",\"emoji\":\"\\ud83d\\udc57\",\"image\":\"linen_coord_1784414712638.jpg\",\"price\":245,\"quantity\":2}]',490.00,'Pending','','','',0,'2026-07-28 00:43:10',NULL,0.00,0.00,'GBP','£','cod','Unpaid'),(4,'CB-584266',NULL,'Prince Virani','','09879092355','Vijay Nagar Yogichowk','HA3 8JD','','[{\"cart_key\":\"14:M\",\"product_id\":14,\"variant_id\":null,\"variant_name\":\"Size: M\",\"name\":\"Prince\",\"emoji\":\"\\ud83c\\udf66\",\"image\":\"product_1784597958_4d662e9f.jpg\",\"price\":3333,\"quantity\":1}]',3333.00,'Pending','','','',0,'2026-07-28 00:48:19',NULL,0.00,0.00,'GBP','£','cod','Unpaid'),(5,'CB-357179',NULL,'Prince Virani','','09879092355','Vijay Nagar Yogichowk','HA3 8JD','','[{\"cart_key\":\"14:XL\",\"product_id\":14,\"variant_id\":null,\"variant_name\":\"Size: XL\",\"name\":\"Prince\",\"emoji\":\"\\ud83c\\udf66\",\"image\":\"product_1784597958_4d662e9f.jpg\",\"price\":3333,\"quantity\":1}]',3333.00,'Pending','','','',1,'2026-07-28 01:03:13',NULL,0.00,0.00,'GBP','£','cod','Unpaid'),(6,'CB-538888',NULL,'Prince Virani','','09879092355','79` Alicia Gardens','HA3 8JD','','[{\"cart_key\":\"8:var28\",\"product_id\":8,\"variant_id\":28,\"variant_name\":\"Size: S\",\"name\":\"Kiara Georgette Short Kurti\",\"emoji\":\"\\ud83d\\udc5a\",\"image\":\"georgette_short_kurti_1784414734051.jpg\",\"price\":135,\"quantity\":1}]',135.00,'Pending','','','',0,'2026-07-28 01:21:36',NULL,0.00,0.00,'GBP','£','cod','Paid');
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(191) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `token` (`token`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
INSERT INTO `password_resets` VALUES (4,'hideinfotech@gmail.com','e13a4c52eae3df8fcb093939ed0d646f0978f32fc2f729d162130af517373fe7','2026-07-24 01:33:48','2026-07-24 00:33:48'),(6,'nirbhay.virani2610@gmail.com','2ace0f5c8afbeb072b45d8cb4de38b42d57d2f0421419247045353c3a85eacf1','2026-07-24 01:40:24','2026-07-24 00:40:24'),(7,'princevir2610@gmail.com','aca91d0d9e6955fc384fc4574ef2b2e56df06563ff42917693a69f0150d1769e','2026-07-28 01:16:55','2026-07-28 00:16:55');
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_attributes`
--

DROP TABLE IF EXISTS `product_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_attributes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attr_type` enum('color','size') NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_attributes`
--

LOCK TABLES `product_attributes` WRITE;
/*!40000 ALTER TABLE `product_attributes` DISABLE KEYS */;
INSERT INTO `product_attributes` VALUES (3,'color','red','#541c1c','2026-07-29 19:34:23'),(4,'color','red','#541c1c','2026-07-29 20:08:36');
/*!40000 ALTER TABLE `product_attributes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_color_images`
--

DROP TABLE IF EXISTS `product_color_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_color_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `color_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_color_id` (`color_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_color_images`
--

LOCK TABLES `product_color_images` WRITE;
/*!40000 ALTER TABLE `product_color_images` DISABLE KEYS */;
INSERT INTO `product_color_images` VALUES (3,4,'dievon_silk_dress.png',1,'2026-07-29 19:17:49');
/*!40000 ALTER TABLE `product_color_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_colors`
--

DROP TABLE IF EXISTS `product_colors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_colors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `color_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_override` decimal(10,2) DEFAULT NULL,
  `mrp_price_override` decimal(10,2) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_colors`
--

LOCK TABLES `product_colors` WRITE;
/*!40000 ALTER TABLE `product_colors` DISABLE KEYS */;
INSERT INTO `product_colors` VALUES (4,1,'Ivory Gold',NULL,'dievon_silk_dress.png',NULL,NULL,1,1,'2026-07-29 19:17:34');
/*!40000 ALTER TABLE `product_colors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_images`
--

DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `sort_order` int(11) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_images`
--

LOCK TABLES `product_images` WRITE;
/*!40000 ALTER TABLE `product_images` DISABLE KEYS */;
INSERT INTO `product_images` VALUES (1,14,'product_addl_1784597958_3fd7b78e.jpeg',0,'2026-07-21 01:39:18'),(2,14,'product_addl_1784597958_077be569.jpg',0,'2026-07-21 01:39:18'),(3,14,'product_addl_1784597958_a6da92d6.png',0,'2026-07-21 01:39:18');
/*!40000 ALTER TABLE `product_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_questions`
--

DROP TABLE IF EXISTS `product_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `asker_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `question` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `answer` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_questions_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_questions`
--

LOCK TABLES `product_questions` WRITE;
/*!40000 ALTER TABLE `product_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_reviews`
--

DROP TABLE IF EXISTS `product_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `reviewer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` int(11) NOT NULL,
  `review_text` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `customer_id` int(11) DEFAULT NULL,
  `author_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_reviews`
--

LOCK TABLES `product_reviews` WRITE;
/*!40000 ALTER TABLE `product_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variants`
--

DROP TABLE IF EXISTS `product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_variants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `color_id` int(11) DEFAULT NULL,
  `size_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stock_qty` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_color_id` (`color_id`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` VALUES (1,1,'Size: XS',180.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(2,1,'Size: S',180.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(3,1,'Size: M',180.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(4,1,'Size: L',180.00,4,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(5,1,'Size: XL',180.00,5,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(6,2,'Size: S',165.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(7,2,'Size: M',165.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(8,2,'Size: L',165.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(9,3,'Size: S',350.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(10,3,'Size: M',350.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(11,3,'Size: L',350.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(12,3,'Size: XL',350.00,4,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(13,4,'Size: XS',420.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(14,4,'Size: S',420.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(15,4,'Size: M',420.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(16,4,'Size: L',420.00,4,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(17,5,'Size: S',290.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(18,5,'Size: M',290.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(19,5,'Size: L',290.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(20,6,'Size: S',245.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(21,6,'Size: M',245.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(22,6,'Size: L',245.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(23,7,'Size: S',95.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(24,7,'Size: M',95.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(25,7,'Size: L',95.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(26,7,'Size: XL',95.00,4,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(27,8,'Size: XS',135.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(28,8,'Size: S',135.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(29,8,'Size: M',135.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(30,8,'Size: L',135.00,4,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(31,9,'Size: S',110.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(32,9,'Size: M',110.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(33,9,'Size: L',110.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(34,10,'Size: S',125.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(35,10,'Size: M',125.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(36,10,'Size: L',125.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(37,11,'Size: S',160.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(38,11,'Size: M',160.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(39,11,'Size: L',160.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(40,12,'Size: S',140.00,1,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(41,12,'Size: M',140.00,2,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(42,12,'Size: L',140.00,3,1,'2026-07-18 22:55:43',NULL,NULL,NULL),(49,1,'30/XS',180.00,6,1,'2026-07-29 19:17:50',4,'XS',8),(50,1,'32/S',180.00,7,1,'2026-07-29 19:17:50',4,'S',12),(51,1,'34/M',180.00,8,1,'2026-07-29 19:17:50',4,'M',5),(52,1,'36/L',180.00,9,1,'2026-07-29 19:17:50',4,'L',0),(53,1,'38/XL',180.00,10,1,'2026-07-29 19:17:50',4,'XL',3);
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `emoji` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '?',
  `color` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#ff6b9d,#c44dff',
  `badge` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `available` tinyint(1) NOT NULL DEFAULT '1',
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `nuts_allergy` tinyint(1) NOT NULL DEFAULT '0',
  `track_stock` tinyint(1) NOT NULL DEFAULT '0',
  `stock_qty` int(11) NOT NULL DEFAULT '0',
  `damage_stock` int(11) NOT NULL DEFAULT '0',
  `sold_offline` int(11) NOT NULL DEFAULT '0',
  `total_stock` int(11) NOT NULL DEFAULT '0',
  `sold_online` int(11) NOT NULL DEFAULT '0',
  `brand` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fabric` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sleeve` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `neck` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pattern` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occasion` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_percentage` int(11) DEFAULT '0',
  `video_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wash_care` text COLLATE utf8mb4_unicode_ci,
  `shipping_info` text COLLATE utf8mb4_unicode_ci,
  `returns_info` text COLLATE utf8mb4_unicode_ci,
  `specifications` json DEFAULT NULL,
  `atelier_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color_way` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sourcing` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `composition` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weight` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dimensions` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` text COLLATE utf8mb4_unicode_ci,
  `seo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` text COLLATE utf8mb4_unicode_ci,
  `related_ids` text COLLATE utf8mb4_unicode_ci,
  `cost_price` decimal(10,2) DEFAULT '0.00',
  `mrp_price` decimal(10,2) DEFAULT '0.00',
  `hsn_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gst_rate` decimal(5,2) DEFAULT '0.00',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'Aurelia Silk Kurti','Crafted from pure heavy mulberry silk, this elegant kurti features delicate hand-embellished beadwork and custom embroidery details around the neckline and side panels.',180.00,'Kurtis','?','Ivory Gold','Best Seller',1,'dievon_silk_dress.png','2026-07-18 22:55:43',0,1,50,0,0,50,0,'Dievon','Crepe','3/4 Sleeve','Round Neck','Solid','Party',0,'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(2,'Zariah Georgette Kurti','A lightweight georgette kurti in a flowy, fluid silhouette with side slits and a soft crepe lining. Perfect for hot summer afternoons.',165.00,'Kurtis','?','Rose Petal','New',1,'georgette_kurti_1784414698877.jpg','2026-07-18 22:55:43',0,1,40,0,0,40,2,'Dievon','Silk','Half Sleeve','Collar','Embroidered','Casual',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(3,'Adara Brocade 3-Piece Suit','A masterclass of tailoring. Includes an intricately woven brocade kurti, solid straight-cut pencil trousers, and a lightweight embroidered organza dupatta with gold lace borders.',350.00,'3 Piece Suits','?','Champagne','Hot',1,'dievon_gold_heels.png','2026-07-18 22:55:43',0,1,25,0,0,25,0,'Sabyasachi','Crepe','3/4 Sleeve','Boat Neck','Embroidered','Casual',0,'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(4,'Meera Silk 3-Piece Suit','Premium raw-silk kurti and pants coupled with a hand-painted pure chiffon dupatta. Features sophisticated modern styling cuts with traditional borders.',420.00,'3 Piece Suits','?','Emerald Green','Best Seller',1,'silk_3_piece_1784414705496.jpg','2026-07-18 22:55:43',0,1,20,0,0,20,0,'Manish Malhotra','Organza','Full Sleeve','V-Neck','Embroidered','Casual',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(5,'Luna Satin Coord Set','A minimal chic look. Features a luxurious, fluid satin button-down tunic shirt with matching wide-leg trousers. High comfort styling for lounges or travel.',290.00,'Coord Sets','?','Olive Silk','New',1,'dievon_leather_handbag.png','2026-07-18 22:55:43',0,1,30,0,0,30,0,'Anita Dongre','Organza','Full Sleeve','Round Neck','Striped','Formal',0,'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(6,'Soraya Linen Coord Set','Ethically sourced linen coord set featuring a tailored belted peplum tunic top and breathable crop straight-leg trousers.',245.00,'Coord Sets','?','Natural Sand','Best Seller',1,'linen_coord_1784414712638.jpg','2026-07-18 22:55:43',0,1,35,0,0,35,0,'Sabyasachi','Organza','Half Sleeve','Collar','Striped','Formal',0,'','','','',NULL,'','','','','','','','','','','','','',0.00,0.00,'',0.00,0),(7,'Raya Cotton Short Kurti','A chic everyday staple. Tailored from hand-loomed organic cotton, featuring a modern mandarin collar and clean front-button placket.',95.00,'Short Kurtis','?','Indigo Blue','New',1,'cotton_short_kurti_1784414719998.jpg','2026-07-18 22:55:43',0,1,60,0,0,60,0,'Anita Dongre','Linen','Half Sleeve','Boat Neck','Striped','Wedding',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(8,'Kiara Georgette Short Kurti','Short, flowy kurti featuring detailed neckline embroidery in silver zari threads. A beautiful, lightweight option for evening gatherings.',135.00,'Short Kurtis','?','Lilac','Hot',1,'georgette_short_kurti_1784414734051.jpg','2026-07-18 22:55:43',0,1,40,0,0,40,0,'Manish Malhotra','Silk','Half Sleeve','Boat Neck','Embroidered','Party',0,'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(9,'Elena Cowl-Neck Satin Top','A fluid, high-lustre satin top featuring a beautiful cowl neck drop. Complements straight-fit skirts or trousers perfectly.',110.00,'Women\'s Tops','?','Champagne','Best Seller',1,'dievon_gold_necklace.png','2026-07-18 22:55:43',0,1,45,0,0,45,0,'Dievon','Linen','Full Sleeve','Collar','Solid','Wedding',0,'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(10,'Freya Linen Wrap Top','A clean wrap-around blouse crafted in breathable pre-washed linen. Features adjustable tie closure straps.',125.00,'Women\'s Tops','?','Natural Olive','New',1,'linen_wrap_top_1784414741898.jpg','2026-07-18 22:55:43',0,1,30,0,0,30,0,'Dievon','Linen','Full Sleeve','Boat Neck','Embroidered','Formal',0,'','','','',NULL,'','','','','','','','','','','','','',0.00,0.00,'',0.00,0),(11,'Sienna Wide-Leg Silk Trousers','High-waisted, wide-leg trousers styled in heavy satin silk. Features hidden side zip fasteners and a relaxed, comfortable silhouette.',160.00,'Bottoms','?','Midnight Black','Hot',1,'silk_trousers_1784414749191.jpg','2026-07-18 22:55:43',0,1,40,0,0,40,0,'Sabyasachi','Organza','3/4 Sleeve','Boat Neck','Embroidered','Party',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(12,'Iris Straight Crepe Pants','Tailored straight-leg crepe pants designed with front creases and functional slant pockets. Sharp and polished.',140.00,'Bottoms','?','Bone Ivory','Best Seller',1,'crepe_pants_1784414756343.jpg','2026-07-18 22:55:43',0,1,35,0,0,35,0,'Sabyasachi','Georgette','Half Sleeve','Boat Neck','Polka Dots','Party',0,'',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(13,'Prince','Prince bhai',2.22,'Bottoms','?','RED','Hot',1,'product_1784597504_2ce17121.jpeg','2026-07-21 01:31:44',0,0,0,0,0,0,0,'Dievon Prince','Lathor','Full','Round','Straght','Diwali',1,'','Nedd','23','79',NULL,'0000','Pink','Indian','Lather',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,NULL,0.00,0),(14,'Prince','Des Prince',3333.00,'Testing','?','Pink','Best Seller',1,'product_1784597958_4d662e9f.jpg','2026-07-21 01:39:18',0,0,0,0,0,0,0,'Dievon Prince','Lather','Full','Round','Good','Diwali',1,'','One day','23','79',NULL,'878787','Pink','Indian','Silk nop prince','','','','','','','','','',0.00,0.00,'',0.00,0);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promo_codes`
--

DROP TABLE IF EXISTS `promo_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promo_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `discount_type` enum('percentage','fixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL,
  `min_order` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_uses` int(11) DEFAULT NULL,
  `uses_count` int(11) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promo_codes`
--

LOCK TABLES `promo_codes` WRITE;
/*!40000 ALTER TABLE `promo_codes` DISABLE KEYS */;
INSERT INTO `promo_codes` VALUES (1,'WELCOME10','','percentage',10.00,0.00,NULL,0,1,'2027-12-31','2026-07-18 22:55:43'),(2,'MAISON50','','fixed',50.00,300.00,NULL,0,1,'2027-12-31','2026-07-18 22:55:43');
/*!40000 ALTER TABLE `promo_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `review_images`
--

DROP TABLE IF EXISTS `review_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `review_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `review_id` int(11) NOT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `review_id` (`review_id`),
  CONSTRAINT `review_images_ibfk_1` FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `review_images`
--

LOCK TABLES `review_images` WRITE;
/*!40000 ALTER TABLE `review_images` DISABLE KEYS */;
/*!40000 ALTER TABLE `review_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reviews`
--

DROP TABLE IF EXISTS `reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `rating` int(11) DEFAULT '5',
  `comment` text NOT NULL,
  `status` enum('Approved','Pending','Hidden') DEFAULT 'Approved',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reviews`
--

LOCK TABLES `reviews` WRITE;
/*!40000 ALTER TABLE `reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `search_history`
--

DROP TABLE IF EXISTS `search_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `search_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `query` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `search_history`
--

LOCK TABLES `search_history` WRITE;
/*!40000 ALTER TABLE `search_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `search_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `seo_settings`
--

DROP TABLE IF EXISTS `seo_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seo_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_slug` varchar(100) NOT NULL,
  `meta_title` varchar(255) NOT NULL,
  `meta_description` text,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `og_image` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_slug` (`page_slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `seo_settings`
--

LOCK TABLES `seo_settings` WRITE;
/*!40000 ALTER TABLE `seo_settings` DISABLE KEYS */;
INSERT INTO `seo_settings` VALUES (1,'home','Dievon Atelier | Timeless Luxury Women\'s Fashion','Discover Dievon\'s hand-crafted Mulberry Silk Kurtis, Brocade 3-Piece Suits, and Organic Linen Coord Sets. Free Worldwide Shipping.','luxury fashion, silk kurtis, 3-piece suits, coord sets, dievon','lookbook_1.png','2026-07-29 08:47:41'),(2,'shop','Shop Luxury Collections | Dievon Atelier','Browse our exclusive collection of luxury Kurtis, Dresses, and Coord Sets. Worldwide Express Delivery & Custom Fittings.','buy kurtis, luxury clothing, silk dresses, designer fashion','lookbook_2.png','2026-07-21 23:59:40'),(3,'about','Our Heritage & Craftsmanship | Dievon Atelier','Learn about Dievon\'s dedication to sustainable luxury, bespoke tailoring, and heritage embroidery.','heritage fashion, luxury atelier, bespoke kurtis','lookbook_3.png','2026-07-21 23:59:40'),(4,'contact','Contact Our Atelier | Dievon Support','Get in touch with Dievon Atelier customer care for custom fitting, order inquiries, and personal styling.','contact dievon, customer care, styling inquiries','lookbook_1.png','2026-07-21 23:59:40');
/*!40000 ALTER TABLE `seo_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `size_guide_charts`
--

DROP TABLE IF EXISTS `size_guide_charts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `size_guide_charts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `unit` enum('in','cm') NOT NULL DEFAULT 'in',
  `instructions_shoulder` text,
  `instructions_bust` text,
  `instructions_waist` text,
  `instructions_hips` text,
  `instructions_length` text,
  `illustration_image` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `size_guide_charts`
--

LOCK TABLES `size_guide_charts` WRITE;
/*!40000 ALTER TABLE `size_guide_charts` DISABLE KEYS */;
INSERT INTO `size_guide_charts` VALUES (3,1,NULL,'in','Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.','Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.','Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.','Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.','Measure from the highest point of the shoulder straight down to where the hem should fall.',NULL,'2026-07-29 19:21:51'),(4,2,NULL,'in','Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.','Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.','Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.','Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.','Measure from the highest point of the shoulder straight down to where the hem should fall.',NULL,'2026-07-29 19:21:51'),(5,3,NULL,'in','Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.','Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.','Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.','Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.','Measure from the highest point of the shoulder straight down to where the hem should fall.',NULL,'2026-07-29 19:21:51'),(6,4,NULL,'in','Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.','Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.','Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.','Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.','Measure from the highest point of the shoulder straight down to where the hem should fall.',NULL,'2026-07-29 19:21:51'),(7,5,NULL,'in','Measure across the back from the edge of one shoulder to the other, following the natural shoulder line.','Wrap the tape around the fullest part of the bust, keeping it parallel to the floor and snug but not tight.','Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.','Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.','Measure from the highest point of the shoulder straight down to where the hem should fall.',NULL,'2026-07-29 19:21:51'),(8,6,NULL,'in','','','Measure around the natural waistline — the narrowest part of the torso, usually just above the belly button.','Measure around the fullest part of the hips, roughly 8 inches below the natural waistline.','Measure from the waistband straight down along the outer seam to the ankle or desired hem.',NULL,'2026-07-29 19:21:51');
/*!40000 ALTER TABLE `size_guide_charts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `size_guide_content`
--

DROP TABLE IF EXISTS `size_guide_content`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `size_guide_content` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chart_id` int(11) NOT NULL,
  `measurement_type` enum('body','garment') NOT NULL DEFAULT 'body',
  `size_label` varchar(20) NOT NULL,
  `numeric_size` varchar(20) DEFAULT NULL,
  `bust` decimal(6,2) DEFAULT NULL,
  `waist` decimal(6,2) DEFAULT NULL,
  `hips` decimal(6,2) DEFAULT NULL,
  `shoulder` decimal(6,2) DEFAULT NULL,
  `length` decimal(6,2) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chart_type_size` (`chart_id`,`measurement_type`,`size_label`),
  KEY `idx_chart_id` (`chart_id`)
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `size_guide_content`
--

LOCK TABLES `size_guide_content` WRITE;
/*!40000 ALTER TABLE `size_guide_content` DISABLE KEYS */;
INSERT INTO `size_guide_content` VALUES (6,3,'body','XS','30',30.00,24.00,33.00,13.50,NULL,0),(7,3,'body','S','32',32.00,26.00,35.00,14.00,NULL,1),(8,3,'body','M','34',34.00,28.00,37.00,14.50,NULL,2),(9,3,'body','L','36',36.00,30.00,39.00,15.00,NULL,3),(10,3,'body','XL','38',38.00,32.00,41.00,15.50,NULL,4),(11,3,'body','2XL','40',40.00,34.00,43.00,16.00,NULL,5),(12,3,'body','3XL','42',42.00,36.00,45.00,16.50,NULL,6),(13,3,'body','4XL','44',44.00,38.00,47.00,17.00,NULL,7),(14,3,'body','5XL','46',46.00,40.00,49.00,17.50,NULL,8),(15,3,'garment','XS','30',33.00,27.00,35.00,14.00,42.00,9),(16,3,'garment','S','32',35.00,29.00,37.00,14.50,42.00,10),(17,3,'garment','M','34',37.00,31.00,39.00,15.00,43.00,11),(18,3,'garment','L','36',39.00,33.00,41.00,15.50,43.00,12),(19,3,'garment','XL','38',41.00,35.00,43.00,16.00,44.00,13),(20,3,'garment','2XL','40',43.00,37.00,45.00,16.50,44.00,14),(21,3,'garment','3XL','42',45.00,39.00,47.00,17.00,45.00,15),(22,3,'garment','4XL','44',47.00,41.00,49.00,17.50,45.00,16),(23,3,'garment','5XL','46',49.00,43.00,51.00,18.00,46.00,17),(24,4,'body','XS','30',30.00,24.00,33.00,13.50,NULL,0),(25,4,'body','S','32',32.00,26.00,35.00,14.00,NULL,1),(26,4,'body','M','34',34.00,28.00,37.00,14.50,NULL,2),(27,4,'body','L','36',36.00,30.00,39.00,15.00,NULL,3),(28,4,'body','XL','38',38.00,32.00,41.00,15.50,NULL,4),(29,4,'body','2XL','40',40.00,34.00,43.00,16.00,NULL,5),(30,4,'body','3XL','42',42.00,36.00,45.00,16.50,NULL,6),(31,4,'body','4XL','44',44.00,38.00,47.00,17.00,NULL,7),(32,4,'body','5XL','46',46.00,40.00,49.00,17.50,NULL,8),(33,4,'garment','XS','30',33.00,27.00,35.00,14.00,42.00,9),(34,4,'garment','S','32',35.00,29.00,37.00,14.50,42.00,10),(35,4,'garment','M','34',37.00,31.00,39.00,15.00,43.00,11),(36,4,'garment','L','36',39.00,33.00,41.00,15.50,43.00,12),(37,4,'garment','XL','38',41.00,35.00,43.00,16.00,44.00,13),(38,4,'garment','2XL','40',43.00,37.00,45.00,16.50,44.00,14),(39,4,'garment','3XL','42',45.00,39.00,47.00,17.00,45.00,15),(40,4,'garment','4XL','44',47.00,41.00,49.00,17.50,45.00,16),(41,4,'garment','5XL','46',49.00,43.00,51.00,18.00,46.00,17),(42,5,'body','XS','30',30.00,24.00,33.00,13.50,NULL,0),(43,5,'body','S','32',32.00,26.00,35.00,14.00,NULL,1),(44,5,'body','M','34',34.00,28.00,37.00,14.50,NULL,2),(45,5,'body','L','36',36.00,30.00,39.00,15.00,NULL,3),(46,5,'body','XL','38',38.00,32.00,41.00,15.50,NULL,4),(47,5,'body','2XL','40',40.00,34.00,43.00,16.00,NULL,5),(48,5,'body','3XL','42',42.00,36.00,45.00,16.50,NULL,6),(49,5,'body','4XL','44',44.00,38.00,47.00,17.00,NULL,7),(50,5,'body','5XL','46',46.00,40.00,49.00,17.50,NULL,8),(51,5,'garment','XS','30',32.00,26.00,35.00,14.00,24.00,9),(52,5,'garment','S','32',34.00,28.00,37.00,14.50,24.00,10),(53,5,'garment','M','34',36.00,30.00,39.00,15.00,25.00,11),(54,5,'garment','L','36',38.00,32.00,41.00,15.50,25.00,12),(55,5,'garment','XL','38',40.00,34.00,43.00,16.00,26.00,13),(56,5,'garment','2XL','40',42.00,36.00,45.00,16.50,26.00,14),(57,5,'garment','3XL','42',44.00,38.00,47.00,17.00,27.00,15),(58,5,'garment','4XL','44',46.00,40.00,49.00,17.50,27.00,16),(59,5,'garment','5XL','46',48.00,42.00,51.00,18.00,28.00,17),(60,6,'body','XS','30',30.00,24.00,33.00,13.50,NULL,0),(61,6,'body','S','32',32.00,26.00,35.00,14.00,NULL,1),(62,6,'body','M','34',34.00,28.00,37.00,14.50,NULL,2),(63,6,'body','L','36',36.00,30.00,39.00,15.00,NULL,3),(64,6,'body','XL','38',38.00,32.00,41.00,15.50,NULL,4),(65,6,'body','2XL','40',40.00,34.00,43.00,16.00,NULL,5),(66,6,'body','3XL','42',42.00,36.00,45.00,16.50,NULL,6),(67,6,'body','4XL','44',44.00,38.00,47.00,17.00,NULL,7),(68,6,'body','5XL','46',46.00,40.00,49.00,17.50,NULL,8),(69,6,'garment','XS','30',33.00,27.00,35.00,14.00,30.00,9),(70,6,'garment','S','32',35.00,29.00,37.00,14.50,30.00,10),(71,6,'garment','M','34',37.00,31.00,39.00,15.00,31.00,11),(72,6,'garment','L','36',39.00,33.00,41.00,15.50,31.00,12),(73,6,'garment','XL','38',41.00,35.00,43.00,16.00,32.00,13),(74,6,'garment','2XL','40',43.00,37.00,45.00,16.50,32.00,14),(75,6,'garment','3XL','42',45.00,39.00,47.00,17.00,33.00,15),(76,6,'garment','4XL','44',47.00,41.00,49.00,17.50,33.00,16),(77,6,'garment','5XL','46',49.00,43.00,51.00,18.00,34.00,17),(78,7,'body','XS','30',30.00,24.00,33.00,13.50,NULL,0),(79,7,'body','S','32',32.00,26.00,35.00,14.00,NULL,1),(80,7,'body','M','34',34.00,28.00,37.00,14.50,NULL,2),(81,7,'body','L','36',36.00,30.00,39.00,15.00,NULL,3),(82,7,'body','XL','38',38.00,32.00,41.00,15.50,NULL,4),(83,7,'body','2XL','40',40.00,34.00,43.00,16.00,NULL,5),(84,7,'body','3XL','42',42.00,36.00,45.00,16.50,NULL,6),(85,7,'body','4XL','44',44.00,38.00,47.00,17.00,NULL,7),(86,7,'body','5XL','46',46.00,40.00,49.00,17.50,NULL,8),(87,7,'garment','XS','30',32.00,26.00,35.00,14.00,22.00,9),(88,7,'garment','S','32',34.00,28.00,37.00,14.50,22.00,10),(89,7,'garment','M','34',36.00,30.00,39.00,15.00,23.00,11),(90,7,'garment','L','36',38.00,32.00,41.00,15.50,23.00,12),(91,7,'garment','XL','38',40.00,34.00,43.00,16.00,24.00,13),(92,7,'garment','2XL','40',42.00,36.00,45.00,16.50,24.00,14),(93,7,'garment','3XL','42',44.00,38.00,47.00,17.00,25.00,15),(94,7,'garment','4XL','44',46.00,40.00,49.00,17.50,25.00,16),(95,7,'garment','5XL','46',48.00,42.00,51.00,18.00,26.00,17),(96,8,'body','XS','30',30.00,24.00,33.00,13.50,NULL,0),(97,8,'body','S','32',32.00,26.00,35.00,14.00,NULL,1),(98,8,'body','M','34',34.00,28.00,37.00,14.50,NULL,2),(99,8,'body','L','36',36.00,30.00,39.00,15.00,NULL,3),(100,8,'body','XL','38',38.00,32.00,41.00,15.50,NULL,4),(101,8,'body','2XL','40',40.00,34.00,43.00,16.00,NULL,5),(102,8,'body','3XL','42',42.00,36.00,45.00,16.50,NULL,6),(103,8,'body','4XL','44',44.00,38.00,47.00,17.00,NULL,7),(104,8,'body','5XL','46',46.00,40.00,49.00,17.50,NULL,8),(105,8,'garment','XS','30',NULL,26.00,35.00,NULL,38.00,9),(106,8,'garment','S','32',NULL,28.00,37.00,NULL,38.00,10),(107,8,'garment','M','34',NULL,30.00,39.00,NULL,39.00,11),(108,8,'garment','L','36',NULL,32.00,41.00,NULL,39.00,12),(109,8,'garment','XL','38',NULL,34.00,43.00,NULL,40.00,13),(110,8,'garment','2XL','40',NULL,36.00,45.00,NULL,40.00,14),(111,8,'garment','3XL','42',NULL,38.00,47.00,NULL,41.00,15),(112,8,'garment','4XL','44',NULL,40.00,49.00,NULL,41.00,16),(113,8,'garment','5XL','46',NULL,42.00,51.00,NULL,42.00,17);
/*!40000 ALTER TABLE `size_guide_content` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `store_settings`
--

DROP TABLE IF EXISTS `store_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `store_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `store_settings`
--

LOCK TABLES `store_settings` WRITE;
/*!40000 ALTER TABLE `store_settings` DISABLE KEYS */;
INSERT INTO `store_settings` VALUES ('contact_email','concierge@dievon.com','2026-07-29 09:01:03'),('contact_phone','+44 20 7946 0192','2026-07-29 09:01:03'),('default_currency','GBP','2026-07-29 09:01:03'),('free_shipping_min','150','2026-07-29 09:01:03'),('site_name','DIEVON Luxury Atelier','2026-07-29 09:01:03'),('standard_shipping_fee','15','2026-07-29 09:01:03');
/*!40000 ALTER TABLE `store_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'dievonfashion'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-30  8:15:29
