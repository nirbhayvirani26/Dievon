-- ============================================================
--  One global BODY measurement table
-- ------------------------------------------------------------
--  Body measurements describe the WEARER, not the garment: a 34" bust is a
--  size M whether she is buying a kurti or trousers. They were previously
--  stored per category, which meant the identical 9-row table was duplicated
--  across every chart (verified: all 6 category charts held a byte-identical
--  body table). Editing the sizing meant editing it 6 times, and any missed
--  copy would silently drift out of step.
--
--  GARMENT measurements stay per category and are untouched — those are
--  genuinely different (5 distinct versions across the same 6 categories),
--  because a kurti hem is 44-48" while a top is 24-26", and bottoms carry
--  no bust or shoulder at all.
--
--  The existing per-chart body rows in size_guide_content are deliberately
--  NOT deleted. They simply stop being read, so this is reversible.
-- ============================================================

CREATE TABLE IF NOT EXISTS size_guide_body_global (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    size_label   VARCHAR(20) NOT NULL,
    numeric_size VARCHAR(20) NULL,
    bust         DECIMAL(6,2) NULL,
    waist        DECIMAL(6,2) NULL,
    hips         DECIMAL(6,2) NULL,
    shoulder     DECIMAL(6,2) NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_size_label (size_label),
    INDEX idx_sgbg_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
