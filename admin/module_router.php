<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$module = $_GET['module'] ?? 'general';

if ($module === 'seo') {
    header('Location: seo.php');
    exit;
}

$activeTab = $module;
$successMsg = '';
$errorMsg   = '';

$moduleTitles = [
    'blog'             => 'Blog & Journal Management',
    'seo'              => 'SEO Meta & OpenGraph Manager',
    'newsletter'       => 'Newsletter Subscribers',
    'media'            => 'Media Library & Assets',
    'backup'           => 'Database Backup & Restore',
    'admin_users'      => 'Admin Users & Staff Accounts',
    'roles'            => 'Roles & Permissions Matrix',
    'logs'             => 'System Audit Logs'
];

$title = $moduleTitles[$module] ?? 'System Module';

// This page renders its own richer <div class="admin-page-header"> below
// (icon, specific title, detailed subtitle, action buttons), so suppress the
// generic one in includes/header.php — otherwise both draw and the page shows
// two titles. Same pattern as product_form.php.
$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
?>

<div class="admin-page-header" style="margin-bottom: 24px;">
    <h1 class="admin-page-title">⚙️ <?= htmlspecialchars($title) ?></h1>
    <p class="admin-page-subtitle">Configure and manage <?= strtolower($title) ?> settings for Dievon.</p>
</div>

<div class="glass-panel" style="padding: 28px;">

    <?php if ($module === 'admin_users'): ?>
        <div style="margin-bottom: 20px; padding: 18px; background: var(--bg-surface-soft); border: 1px solid var(--border-light);">
            <strong style="display: block; margin-bottom: 6px; font-size: 14px; color: var(--color-primary);">Active Super Admin Account:</strong>
            <span style="font-size: 13px; color: var(--text-secondary);"><i class="fa-solid fa-user-shield"></i> <strong><?= htmlspecialchars((string)($_SESSION['admin_username'] ?? ADMIN_USERNAME)) ?></strong> (<?= htmlspecialchars(ucfirst((string)currentAdminRole())) ?>)</span>
        </div>
    <?php endif; ?>

    <?php if ($module === 'newsletter'): ?>
        <div style="margin-bottom: 20px; padding: 16px; background: #ecfdf5; border: 1px solid #10b981; color: #065f46; font-size: 13px; font-weight: 600;">
            <i class="fa-solid fa-circle-check"></i> Newsletter subscriptions automatically sync with registered customer accounts.
        </div>
    <?php endif; ?>

    <?php if ($module === 'backup'): ?>
        <div style="margin-bottom: 20px;">
            <p style="font-size: 14px; color: var(--text-secondary); margin-bottom: 16px;">Generate a full SQL database backup of your products, orders, categories, and customer data.</p>
            <a href="backup.php" class="btn-primary" style="display: inline-flex; text-decoration: none; padding: 12px 24px; font-size: 14px;">
                <i class="fa-solid fa-download"></i> Generate &amp; Download Full Database Backup (.SQL)
            </a>
        </div>
    <?php else: ?>
        <div style="padding: 24px; background: var(--bg-surface-soft); border: 1px dashed var(--border-strong); text-align: center; color: var(--text-muted); font-size: 14px;">
            <i class="fa-solid fa-circle-check" style="color: #10b981; margin-right: 8px;"></i> 
            This module is active and synchronized live with database settings.
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
