<?php
/**
 * Sidebar navigation. Menu items are filtered by capability, but the
 * server still enforces authorization on every page.
 */
require_once __DIR__ . '/auth.php';

$__s = business_settings();
$__shop = $__s['shop_name'] ?? APP_NAME;
$__logo = $__s['logo_path'] ?? '';
$__current = $_SERVER['PHP_SELF'] ?? '';

$nav = [
    ['label' => 'Dashboard',   'icon' => 'speedometer2',     'url' => 'dashboard.php',        'cap' => null,               'match' => '/dashboard.php'],
    ['label' => 'Customers',   'icon' => 'people',           'url' => 'customers/index.php',  'cap' => 'view_customers',   'match' => '/customers/'],
    ['label' => 'Agreements',  'icon' => 'file-earmark-text','url' => 'agreements/index.php', 'cap' => 'view_agreements',  'match' => '/agreements/'],
    ['label' => 'Payments',    'icon' => 'cash-coin',        'url' => 'payments/index.php',   'cap' => 'view_payments',    'match' => '/payments/'],
    ['label' => 'Reports',     'icon' => 'bar-chart-line',   'url' => 'reports/index.php',    'cap' => 'view_reports',     'match' => '/reports/'],
];
$settingsNav = [
    ['label' => 'Business Settings', 'icon' => 'shop',     'url' => 'settings/business.php', 'cap' => 'manage_settings', 'match' => '/settings/business.php'],
    ['label' => 'User Management',   'icon' => 'people-fill','url' => 'settings/users.php',   'cap' => 'manage_users',    'match' => '/settings/users.php'],
];
?>
<aside class="app-sidebar" id="appSidebar">
    <div class="app-brand">
        <?php if ($__logo): ?>
            <img src="<?= e(upload_url($__logo)) ?>" alt="Logo" class="app-brand-logo">
        <?php else: ?>
            <?= shop_logo_mark('app-brand-mark') ?>
        <?php endif; ?>
        <div class="app-brand-text">
            <span class="app-brand-name"><?= e(mb_strimwidth($__shop, 0, 28, '…')) ?></span>
            <span class="app-brand-sub">Installments</span>
        </div>
    </div>

    <nav class="app-nav">
        <ul class="list-unstyled mb-0">
            <?php foreach ($nav as $item): ?>
                <?php if ($item['cap'] === null || can($item['cap'])): ?>
                    <li>
                        <a class="app-nav-link<?= strpos($__current, $item['match']) !== false ? ' active' : '' ?>"
                           href="<?= e(base_url($item['url'])) ?>">
                            <i class="bi bi-<?= e($item['icon']) ?>"></i>
                            <span><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

        <?php
        $visibleSettings = array_filter($settingsNav, fn($i) => can($i['cap']));
        if (!empty($visibleSettings)):
        ?>
        <div class="app-nav-heading">Administration</div>
        <ul class="list-unstyled mb-0">
            <?php foreach ($visibleSettings as $item): ?>
                <li>
                    <a class="app-nav-link<?= strpos($__current, $item['match']) !== false ? ' active' : '' ?>"
                       href="<?= e(base_url($item['url'])) ?>">
                        <i class="bi bi-<?= e($item['icon']) ?>"></i>
                        <span><?= e($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </nav>

    <div class="app-sidebar-footer">
        <a class="app-nav-link" href="<?= e(base_url('change_password.php')) ?>">
            <i class="bi bi-key"></i><span>Change Password</span>
        </a>
        <a class="app-nav-link text-danger" href="<?= e(base_url('logout.php')) ?>">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</aside>
<div class="app-sidebar-backdrop" id="sidebarBackdrop"></div>
