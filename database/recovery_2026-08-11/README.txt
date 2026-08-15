Dievon — recovery files from the session of 10-11 Aug 2026
==========================================================
Everything deleted or changed during testing, saved so it can be put back.

  qa_promos_recovery.sql   the 16 QA promo codes removed, including
                           QATESTFVHUGE (Rs 99,999,999 off) which made
                           orders free. Do NOT re-run unless you mean to.
  qa_product_recovery.sql  the QA AUDIT Test Kurti product + its colour
                           and country prices
  orphan_rows_recovery.sql 9 product_colors rows pointing at products
                           that no longer exist
  countries_restore.sql    store_countries + product_country_prices as
                           they were before testing
  restore_adara.sql        Adara variant stock snapshot
  restore_zariah.sql       Zariah variant stock snapshot
  rma22_restore.sql        RMA 22 status + return tracking
  zz_admin_test_recovery.sql / ZZ_TEST_recovery.sql
                           temporary menswear products, brand, colour
                           attribute and banner used for testing
  env.backup.txt           .env exactly as it was found (MAIL_TEST_MODE=false)
  qa_images/               27 QA-named image files removed from
                           uploads/products/

This folder is inside database/, which UPLOAD_LIST.txt excludes from
deployment. Delete it once you are happy nothing needs restoring.
