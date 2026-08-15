-- homepage_sections, dropped 2026-08-08 23:50
-- Restore by running this file if the builder is ever rebuilt.

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

INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('1', 'hero_slider', 'Hero Banner Slider', 'Volume Collections', '1', '1');
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('2', 'collections', 'Dievon Collections', 'Curated Ensembles', '1', '2');
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('3', 'new_arrivals', 'New Arrivals', 'Latest Creations', '1', '3');
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('4', 'best_sellers', 'Best Sellers & Trending', 'Curated Favorites', '1', '4');
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('5', 'editorial_story', 'The Dievon Story', 'Craftsmanship & Heritage', '1', '5');
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('6', 'reviews', 'Customer Reviews', 'Verified Ratings & Feedback', '1', '6');
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('7', 'instagram', '@DievonOfficial Instagram', 'Follow Our Atelier', '1', '7');
INSERT INTO `homepage_sections` (`id`, `section_key`, `title`, `eyebrow`, `is_active`, `sort_order`) VALUES ('8', 'newsletter', 'VIP Atelier Newsletter', 'Exclusive Offers & Releases', '1', '8');
