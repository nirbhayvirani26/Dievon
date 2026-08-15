-- ============================================================
--  Dievon – Admin password reset by email (2026-08-06)
--
--  Adds the 'password_reset' purpose to admin_login_codes so the
--  new forgot_password.php / reset_password.php flow can store
--  its own one-time codes (a reset code must never double as a
--  sign-in code).
--
--  Idempotent: safe to run twice. Existing rows keep their values.
--  Apply via phpMyAdmin on the live database (Hostinger).
-- ============================================================

ALTER TABLE `admin_login_codes`
    MODIFY `purpose` ENUM('login','setup','password_reset')
    NOT NULL DEFAULT 'login';
