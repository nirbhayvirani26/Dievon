-- ============================================================
--  Size guide undo snapshots
--
--  Measurement numbers had no validation and no history: a typo such as
--  340 instead of 34 saved straight through to customers with no warning
--  and no way back. Validation now warns on save (without blocking, since
--  an unusual sizing run can be legitimate), and this table keeps the
--  previous version of a chart so any mistake is one click to revert.
--
--  The whole chart + its rows are stored as one JSON blob rather than
--  shadow tables: a size chart is small, always restored as a complete
--  unit, and this keeps the restore path trivial and impossible to get
--  half-applied.
-- ============================================================

CREATE TABLE IF NOT EXISTS size_guide_snapshots (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    chart_id    INT NOT NULL,
    category_id INT NULL,
    product_id  INT NULL,
    label       VARCHAR(120) NULL,
    payload     LONGTEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sgs_chart (chart_id),
    INDEX idx_sgs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
