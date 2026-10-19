<?php
/**
 * Application layout header. Included by every authenticated page.
 *
 * Expects (optional):
 *   $pageTitle   string
 *   $pageActions string  (HTML for the top-right action area)
 */

require_once __DIR__ . '/auth.php';

require_login();

$__settings = business_settings();
if (!defined('CURRENCY_CODE')) {
    define('CURRENCY_CODE', $__settings['currency'] ?? 'PKR');
}

$currentUser = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$shopName = $__settings['shop_name'] ?? APP_NAME;
$logoPath = $__settings['logo_path'] ?? '';
$flashes = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e($shopName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="<?= e(asset('css/app.css')) ?>?v=1" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="app-main">
        <header class="app-topbar">
            <button class="btn btn-link text-dark d-lg-none me-2" id="sidebarToggle" type="button" aria-label="Toggle menu">
                <i class="bi bi-list fs-4"></i>
            </button>
            <h1 class="app-topbar-title"><?= e($pageTitle) ?></h1>
            <div class="ms-auto d-flex align-items-center gap-2">
                <?php if (!empty($pageActions)) { echo $pageActions; } ?>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-sm-inline ms-1"><?= e($currentUser['full_name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text small text-muted">
                            Signed in as <strong><?= e($currentUser['username']) ?></strong><br>
                            <span class="badge text-bg-secondary"><?= e(ucfirst($currentUser['role'])) ?></span>
                        </span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(base_url('change_password.php')) ?>"><i class="bi bi-key me-1"></i> Change Password</a></li>
                        <?php if (can('manage_settings')): ?>
                        <li><a class="dropdown-item" href="<?= e(base_url('settings/business.php')) ?>"><i class="bi bi-gear me-1"></i> Business Settings</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= e(base_url('logout.php')) ?>"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="app-content">
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
