<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/xml; charset=utf-8');

$products = [];
try {
    $products = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY id DESC")->fetchAll();
} catch (PDOException $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
  <channel>
    <title>Dievon Atelier Product Feed</title>
    <link><?= SITE_URL ?></link>
    <description>Google Merchant Center product feed for Dievon Atelier luxury garments.</description>
    <?php foreach ($products as $p): 
        $link = SITE_URL . "/product.php?id=" . $p['id'];
        $img = !empty($p['image']) ? SITE_URL . "/uploads/products/" . $p['image'] : SITE_URL . "/assets/images/logo/logo.PNG";
        $stock = (!empty($p['track_stock']) && (int)$p['stock_qty'] <= 0) ? 'out of stock' : 'in stock';
    ?>
    <item>
      <g:id><?= $p['id'] ?></g:id>
      <g:title><![CDATA[<?= $p['name'] ?>]]></g:title>
      <g:description><![CDATA[<?= strip_tags($p['description']) ?>]]></g:description>
      <g:link><?= htmlspecialchars($link) ?></g:link>
      <g:image_link><?= htmlspecialchars($img) ?></g:image_link>
      <g:availability><?= $stock ?></g:availability>
      <g:price><?= number_format($p['price'], 2) ?> GBP</g:price>
      <g:brand><![CDATA[<?= !empty($p['brand']) ? $p['brand'] : 'Dievon Atelier' ?>]]></g:brand>
      <g:condition>new</g:condition>
    </item>
    <?php endforeach; ?>
  </channel>
</rss>
