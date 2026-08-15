<?php
// Fragment, not a page. Requested directly it never bootstraps config.php, so
// SITE_URL was undefined a few lines below and PHP answered HTTP 200 with
// "Uncaught Error: Undefined constant SITE_URL in
// /Applications/MAMP/htdocs/DievonOrders/admin/includes/footer.php" — handing a
// stranger the absolute path of the install, with no login required.
if (!defined('SITE_URL')) { http_response_code(404); exit; }
?>
    </main>
</div>
</div>
<!-- Branded dialog (shares the storefront stylesheet, which admin already loads). -->
<script src="<?= SITE_URL ?>/assets/js/dievon-dialog.js?v=<?= filemtime(__DIR__ . '/../../assets/js/dievon-dialog.js') ?>"></script>
<?php /* Cache-busted like every stylesheet above and the dialog beside it. Without
         the ?v= a browser keeps serving whatever admin.js it cached, so a fix
         uploaded to live simply does not run for anyone who has been here
         before — and it looks as though the upload failed. */ ?>
<script src="<?= SITE_URL ?>/admin/assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>
</body>
</html>
