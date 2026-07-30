-- MySQL dump 10.13  Distrib 8.0.44, for macos11.7 (x86_64)
--
-- Host: localhost    Database: dievonfashion
-- ------------------------------------------------------
-- Server version	8.0.44

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
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Kurtis',1,'2026-07-18 22:55:43'),(2,'3 Piece Suits',2,'2026-07-18 22:55:43'),(3,'Coord Sets',3,'2026-07-18 22:55:43'),(4,'Short Kurtis',4,'2026-07-18 22:55:43'),(5,'Women\'s Tops',5,'2026-07-18 22:55:43'),(6,'Bottoms',6,'2026-07-18 22:55:43');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gallery`
--

DROP TABLE IF EXISTS `gallery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gallery` (
  `id` int NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT '0',
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
-- Table structure for table `inquiries`
--

DROP TABLE IF EXISTS `inquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inquiries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inquiries`
--

LOCK TABLES `inquiries` WRITE;
/*!40000 ALTER TABLE `inquiries` DISABLE KEYS */;
/*!40000 ALTER TABLE `inquiries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mega_menu_links`
--

DROP TABLE IF EXISTS `mega_menu_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mega_menu_links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `column_name` varchar(50) NOT NULL,
  `link_name` varchar(100) NOT NULL,
  `link_url` varchar(255) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
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
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `postcode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `items_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('Pending','Processing','Delivered','Cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `stock_deducted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `promo_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `delivery_charge` decimal(6,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'later',
  `payment_status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unpaid',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_code` (`order_code`),
  UNIQUE KEY `uq_order_code` (`order_code`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_questions`
--

DROP TABLE IF EXISTS `product_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `asker_name` varchar(255) NOT NULL,
  `question` text NOT NULL,
  `answer` text,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `reviewer_name` varchar(255) NOT NULL,
  `rating` int NOT NULL,
  `review_text` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_reviews_chk_1` CHECK (((`rating` >= 1) and (`rating` <= 5)))
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` VALUES (1,1,'Size: XS',180.00,1,1,'2026-07-18 22:55:43'),(2,1,'Size: S',180.00,2,1,'2026-07-18 22:55:43'),(3,1,'Size: M',180.00,3,1,'2026-07-18 22:55:43'),(4,1,'Size: L',180.00,4,1,'2026-07-18 22:55:43'),(5,1,'Size: XL',180.00,5,1,'2026-07-18 22:55:43'),(6,2,'Size: S',165.00,1,1,'2026-07-18 22:55:43'),(7,2,'Size: M',165.00,2,1,'2026-07-18 22:55:43'),(8,2,'Size: L',165.00,3,1,'2026-07-18 22:55:43'),(9,3,'Size: S',350.00,1,1,'2026-07-18 22:55:43'),(10,3,'Size: M',350.00,2,1,'2026-07-18 22:55:43'),(11,3,'Size: L',350.00,3,1,'2026-07-18 22:55:43'),(12,3,'Size: XL',350.00,4,1,'2026-07-18 22:55:43'),(13,4,'Size: XS',420.00,1,1,'2026-07-18 22:55:43'),(14,4,'Size: S',420.00,2,1,'2026-07-18 22:55:43'),(15,4,'Size: M',420.00,3,1,'2026-07-18 22:55:43'),(16,4,'Size: L',420.00,4,1,'2026-07-18 22:55:43'),(17,5,'Size: S',290.00,1,1,'2026-07-18 22:55:43'),(18,5,'Size: M',290.00,2,1,'2026-07-18 22:55:43'),(19,5,'Size: L',290.00,3,1,'2026-07-18 22:55:43'),(20,6,'Size: S',245.00,1,1,'2026-07-18 22:55:43'),(21,6,'Size: M',245.00,2,1,'2026-07-18 22:55:43'),(22,6,'Size: L',245.00,3,1,'2026-07-18 22:55:43'),(23,7,'Size: S',95.00,1,1,'2026-07-18 22:55:43'),(24,7,'Size: M',95.00,2,1,'2026-07-18 22:55:43'),(25,7,'Size: L',95.00,3,1,'2026-07-18 22:55:43'),(26,7,'Size: XL',95.00,4,1,'2026-07-18 22:55:43'),(27,8,'Size: XS',135.00,1,1,'2026-07-18 22:55:43'),(28,8,'Size: S',135.00,2,1,'2026-07-18 22:55:43'),(29,8,'Size: M',135.00,3,1,'2026-07-18 22:55:43'),(30,8,'Size: L',135.00,4,1,'2026-07-18 22:55:43'),(31,9,'Size: S',110.00,1,1,'2026-07-18 22:55:43'),(32,9,'Size: M',110.00,2,1,'2026-07-18 22:55:43'),(33,9,'Size: L',110.00,3,1,'2026-07-18 22:55:43'),(34,10,'Size: S',125.00,1,1,'2026-07-18 22:55:43'),(35,10,'Size: M',125.00,2,1,'2026-07-18 22:55:43'),(36,10,'Size: L',125.00,3,1,'2026-07-18 22:55:43'),(37,11,'Size: S',160.00,1,1,'2026-07-18 22:55:43'),(38,11,'Size: M',160.00,2,1,'2026-07-18 22:55:43'),(39,11,'Size: L',160.00,3,1,'2026-07-18 22:55:43'),(40,12,'Size: S',140.00,1,1,'2026-07-18 22:55:43'),(41,12,'Size: M',140.00,2,1,'2026-07-18 22:55:43'),(42,12,'Size: L',140.00,3,1,'2026-07-18 22:55:43');
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `emoji` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '?',
  `color` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#ff6b9d,#c44dff',
  `badge` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `available` tinyint(1) NOT NULL DEFAULT '1',
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `nuts_allergy` tinyint(1) NOT NULL DEFAULT '0',
  `track_stock` tinyint(1) NOT NULL DEFAULT '0',
  `stock_qty` int NOT NULL DEFAULT '0',
  `damage_stock` int NOT NULL DEFAULT '0',
  `sold_offline` int NOT NULL DEFAULT '0',
  `total_stock` int NOT NULL DEFAULT '0',
  `sold_online` int NOT NULL DEFAULT '0',
  `brand` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fabric` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sleeve` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `neck` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pattern` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occasion` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_percentage` int DEFAULT '0',
  `video_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wash_care` text COLLATE utf8mb4_unicode_ci,
  `shipping_info` text COLLATE utf8mb4_unicode_ci,
  `returns_info` text COLLATE utf8mb4_unicode_ci,
  `specifications` json DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'Aurelia Silk Kurti','Crafted from pure heavy mulberry silk, this elegant kurti features delicate hand-embellished beadwork and custom embroidery details around the neckline and side panels.',180.00,'Kurtis','👗','Ivory Gold','Best Seller',1,'dievon_silk_dress.png','2026-07-18 22:55:43',0,1,50,0,0,50,0,'Dievon','Crepe','3/4 Sleeve','Round Neck','Solid','Party',0,'',NULL,NULL,NULL,NULL),(2,'Zariah Georgette Kurti','A lightweight georgette kurti in a flowy, fluid silhouette with side slits and a soft crepe lining. Perfect for hot summer afternoons.',165.00,'Kurtis','👗','Rose Petal','New',1,'georgette_kurti_1784414698877.jpg','2026-07-18 22:55:43',0,1,40,0,0,40,0,'Dievon','Silk','Half Sleeve','Collar','Embroidered','Casual',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL),(3,'Adara Brocade 3-Piece Suit','A masterclass of tailoring. Includes an intricately woven brocade kurti, solid straight-cut pencil trousers, and a lightweight embroidered organza dupatta with gold lace borders.',350.00,'3 Piece Suits','👗','Champagne','Hot',1,'dievon_gold_heels.png','2026-07-18 22:55:43',0,1,25,0,0,25,0,'Sabyasachi','Crepe','3/4 Sleeve','Boat Neck','Embroidered','Casual',0,'',NULL,NULL,NULL,NULL),(4,'Meera Silk 3-Piece Suit','Premium raw-silk kurti and pants coupled with a hand-painted pure chiffon dupatta. Features sophisticated modern styling cuts with traditional borders.',420.00,'3 Piece Suits','👗','Emerald Green','Best Seller',1,'silk_3_piece_1784414705496.jpg','2026-07-18 22:55:43',0,1,20,0,0,20,0,'Manish Malhotra','Organza','Full Sleeve','V-Neck','Embroidered','Casual',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL),(5,'Luna Satin Coord Set','A minimal chic look. Features a luxurious, fluid satin button-down tunic shirt with matching wide-leg trousers. High comfort styling for lounges or travel.',290.00,'Coord Sets','👗','Olive Silk','New',1,'dievon_leather_handbag.png','2026-07-18 22:55:43',0,1,30,0,0,30,0,'Anita Dongre','Organza','Full Sleeve','Round Neck','Striped','Formal',0,'',NULL,NULL,NULL,NULL),(6,'Soraya Linen Coord Set','Ethically sourced linen coord set featuring a tailored belted peplum tunic top and breathable crop straight-leg trousers.',245.00,'Coord Sets','👗','Natural Sand','Best Seller',1,'linen_coord_1784414712638.jpg','2026-07-18 22:55:43',0,1,35,0,0,35,0,'Sabyasachi','Organza','Half Sleeve','Collar','Striped','Formal',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL),(7,'Raya Cotton Short Kurti','A chic everyday staple. Tailored from hand-loomed organic cotton, featuring a modern mandarin collar and clean front-button placket.',95.00,'Short Kurtis','👚','Indigo Blue','New',1,'cotton_short_kurti_1784414719998.jpg','2026-07-18 22:55:43',0,1,60,0,0,60,0,'Anita Dongre','Linen','Half Sleeve','Boat Neck','Striped','Wedding',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL),(8,'Kiara Georgette Short Kurti','Short, flowy kurti featuring detailed neckline embroidery in silver zari threads. A beautiful, lightweight option for evening gatherings.',135.00,'Short Kurtis','👚','Lilac','Hot',1,'georgette_short_kurti_1784414734051.jpg','2026-07-18 22:55:43',0,1,40,0,0,40,0,'Manish Malhotra','Silk','Half Sleeve','Boat Neck','Embroidered','Party',0,'',NULL,NULL,NULL,NULL),(9,'Elena Cowl-Neck Satin Top','A fluid, high-lustre satin top featuring a beautiful cowl neck drop. Complements straight-fit skirts or trousers perfectly.',110.00,'Women\'s Tops','👚','Champagne','Best Seller',1,'dievon_gold_necklace.png','2026-07-18 22:55:43',0,1,45,0,0,45,0,'Dievon','Linen','Full Sleeve','Collar','Solid','Wedding',0,'',NULL,NULL,NULL,NULL),(10,'Freya Linen Wrap Top','A clean wrap-around blouse crafted in breathable pre-washed linen. Features adjustable tie closure straps.',125.00,'Women\'s Tops','👚','Natural Olive','New',1,'linen_wrap_top_1784414741898.jpg','2026-07-18 22:55:43',0,1,30,0,0,30,0,'Dievon','Linen','Full Sleeve','Boat Neck','Embroidered','Formal',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL),(11,'Sienna Wide-Leg Silk Trousers','High-waisted, wide-leg trousers styled in heavy satin silk. Features hidden side zip fasteners and a relaxed, comfortable silhouette.',160.00,'Bottoms','👖','Midnight Black','Hot',1,'silk_trousers_1784414749191.jpg','2026-07-18 22:55:43',0,1,40,0,0,40,0,'Sabyasachi','Organza','3/4 Sleeve','Boat Neck','Embroidered','Party',0,'http://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',NULL,NULL,NULL,NULL),(12,'Iris Straight Crepe Pants','Tailored straight-leg crepe pants designed with front creases and functional slant pockets. Sharp and polished.',140.00,'Bottoms','👖','Bone Ivory','Best Seller',1,'crepe_pants_1784414756343.jpg','2026-07-18 22:55:43',0,1,35,0,0,35,0,'Sabyasachi','Georgette','Half Sleeve','Boat Neck','Polka Dots','Party',0,'',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promo_codes`
--

DROP TABLE IF EXISTS `promo_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `promo_codes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `discount_type` enum('percentage','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `discount_value` decimal(10,2) NOT NULL,
  `min_order` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_uses` int DEFAULT NULL,
  `uses_count` int NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `review_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `review_id` int NOT NULL,
  `image_path` varchar(255) NOT NULL,
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
-- Table structure for table `search_history`
--

DROP TABLE IF EXISTS `search_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `search_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `query` varchar(255) NOT NULL,
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
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-20 13:06:23
