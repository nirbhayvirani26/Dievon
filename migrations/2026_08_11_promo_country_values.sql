-- Dievon — per-country values for fixed-amount promo codes
--
-- promo_codes carries ONE discount_value and ONE min_order, entered in the
-- shop's own currency. Nothing in this shop converts currency: each country
-- has its own prices. So those two figures are meaningless abroad — a ₹50-off
-- code was advertised to a UK shopper as "£50.00 off over £300.00" (about a
-- hundred times the real discount) while the validator refused them against a
-- ₹300 minimum an £84 basket can never reach.
--
-- A code with no row here for a country is NOT offered in that country. That is
-- deliberate: with no exchange rate there is nothing honest to fall back on.
-- Percentage codes need no rows — 10% is 10% in any currency.

CREATE TABLE IF NOT EXISTS promo_country_values (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    promo_id       INT NOT NULL,
    country_code   CHAR(2) NOT NULL,
    discount_value DECIMAL(10,2) NULL,
    min_order      DECIMAL(10,2) NULL,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_promo_country (promo_id, country_code),
    INDEX idx_pcv_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
