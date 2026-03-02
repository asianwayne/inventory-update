<?php
declare(strict_types=1);
$user = auth_user();
$appName = __((string) (setting('app_name', APP_NAME) ?? APP_NAME));
?>
<!doctype html>
<html lang="zh-CN">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $appName) ?></title>
    <link rel="stylesheet" href="assets/css/inter.css">
    <link rel="stylesheet" href="assets/css/phosphor-regular.css">
    <link rel="stylesheet" href="assets/css/phosphor-bold.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>

<body>
    <div class="layout">
        <?php if ($user): ?>
            <aside class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <div class="brand">
                        <i class="ph-bold ph-package brand-icon"></i>
                        <span><?= e($appName) ?></span>
                    </div>
                    <button class="mobile-toggle" id="mobileToggle">
                        <i class="ph-bold ph-list"></i>
                    </button>
                </div>

                <div class="user-profile">
                    <div class="avatar">
                        <i class="ph-bold ph-user"></i>
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?= e($user['name'] ?? '') ?></span>
                        <span class="user-role"><?= e($user['company_name'] ?? '') ?></span>
                        <span class="user-role"><?= e(__((string) ($user['role'] ?? 'staff'))) ?></span>
                    </div>
                </div>

                <nav class="sidebar-nav">
                    <a href="dashboard.php"
                        class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                        <i class="ph-bold ph-squares-four"></i>
                        <span><?= e(__('Dashboard')) ?></span>
                    </a>

                    <?php if (has_permission('manage_products')): ?>
                        <div class="nav-group"><?= e(__('Inventory')) ?></div>
                        <a href="product_form.php"
                            class="<?= basename($_SERVER['PHP_SELF']) == 'product_form.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-plus-circle"></i>
                            <span><?= e(__('Create Product')) ?></span>
                        </a>
                        <a href="products.php" class="<?= basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-browsers"></i>
                            <span><?= e(__('Products List')) ?></span>
                        </a>
                        <a href="categories.php"
                            class="<?= basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-tag"></i>
                            <span><?= e(__('Product Categories')) ?></span>
                        </a>
                    <?php endif; ?>

                    <?php if (has_permission('manage_suppliers') || has_permission('manage_customers')): ?>
                        <div class="nav-group"><?= e(__('Partners')) ?></div>
                        <?php if (has_permission('manage_suppliers')): ?>
                            <a href="suppliers.php"
                                class="<?= basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : '' ?>">
                                <i class="ph-bold ph-truck"></i>
                                <span><?= e(__('Suppliers')) ?></span>
                            </a>
                        <?php endif; ?>
                        <?php if (has_permission('manage_customers')): ?>
                            <a href="customers.php"
                                class="<?= basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : '' ?>">
                                <i class="ph-bold ph-users-three"></i>
                                <span><?= e(__('Customers')) ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (has_permission('manage_purchases') || has_permission('receive_purchase')): ?>
                        <div class="nav-group"><?= e(__('Purchasing')) ?></div>
                        <?php if (has_permission('receive_purchase')): ?>
                            <a href="purchase_receive.php"
                                class="<?= basename($_SERVER['PHP_SELF']) == 'purchase_receive.php' ? 'active' : '' ?>">
                                <i class="ph-bold ph-archive-box"></i>
                                <span><?= e(__('Purchase Receive')) ?></span>
                            </a>
                        <?php endif; ?>
                        <?php if (has_permission('manage_purchases')): ?>
                            <a href="purchase_orders.php"
                                class="<?= basename($_SERVER['PHP_SELF']) == 'purchase_orders.php' ? 'active' : '' ?>">
                                <i class="ph-bold ph-file-text"></i>
                                <span><?= e(__('Purchase Orders')) ?></span>
                            </a>
                            <?php if (has_permission('approve_orders')): ?>
                                <a href="purchase_orders_pending.php"
                                    class="<?= basename($_SERVER['PHP_SELF']) == 'purchase_orders_pending.php' || basename($_SERVER['PHP_SELF']) == 'purchase_order_approve.php' ? 'active' : '' ?>">
                                    <i class="ph-bold ph-check-square-offset"></i>
                                    <span><?= e(__('PO Approvals')) ?></span>
                                </a>
                            <?php endif; ?>
                            <a href="goods_receipts.php"
                                class="<?= basename($_SERVER['PHP_SELF']) == 'goods_receipts.php' ? 'active' : '' ?>">
                                <i class="ph-bold ph-receipt"></i>
                                <span><?= e(__('Goods Receipts')) ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (has_permission('manage_sales') || has_permission('manage_invoices')): ?>
                        <div class="nav-group"><?= e(__('Sales')) ?></div>
                        <?php if (has_permission('manage_sales')): ?>
                            <a href="sales_orders.php"
                                class="<?= basename($_SERVER['PHP_SELF']) == 'sales_orders.php' ? 'active' : '' ?>">
                                <i class="ph-bold ph-shopping-cart"></i>
                                <span><?= e(__('Sales Orders')) ?></span>
                            </a>
                            <?php if (has_permission('manage_quotations')): ?>
                                <a href="quotation.php"
                                    class="<?= basename($_SERVER['PHP_SELF']) == 'quotation.php' ? 'active' : '' ?>">
                                    <i class="ph-bold ph-file-arrow-down"></i>
                                    <span><?= e(__('Price Quotation')) ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (has_permission('approve_orders')): ?>
                                <a href="sales_orders_pending.php"
                                    class="<?= basename($_SERVER['PHP_SELF']) == 'sales_orders_pending.php' || basename($_SERVER['PHP_SELF']) == 'sales_order_approve.php' ? 'active' : '' ?>">
                                    <i class="ph-bold ph-seal-check"></i>
                                    <span><?= e(__('SO Approvals')) ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (has_permission('manage_invoices')): ?>
                            <a href="invoices.php" class="<?= basename($_SERVER['PHP_SELF']) == 'invoices.php' ? 'active' : '' ?>">
                                <i class="ph-bold ph-invoice"></i>
                                <span><?= e(__('Invoices')) ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="nav-group"><?= e(__('System')) ?></div>
                    <?php if (has_permission('manage_returns')): ?>
                        <a href="returns.php" class="<?= basename($_SERVER['PHP_SELF']) == 'returns.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-arrow-u-up-left"></i>
                            <span><?= e(__('Returns')) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (has_permission('view_movements')): ?>
                        <a href="stock_movements.php"
                            class="<?= basename($_SERVER['PHP_SELF']) == 'stock_movements.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-arrows-left-right"></i>
                            <span><?= e(__('Stock Movements')) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (has_permission('manage_users')): ?>
                        <a href="users.php" class="<?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-user-circle-gear"></i>
                            <span><?= e(__('Users')) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (has_permission('manage_settings')): ?>
                        <a href="companies.php"
                            class="<?= basename($_SERVER['PHP_SELF']) == 'companies.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-buildings"></i>
                            <span><?= e(__('Company')) ?></span>
                        </a>
                        <a href="settings.php" class="<?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
                            <i class="ph-bold ph-gear"></i>
                            <span><?= e(__('Settings')) ?></span>
                        </a>
                    <?php endif; ?>
                    <a href="logout.php" class="logout-link">
                        <i class="ph-bold ph-sign-out"></i>
                        <span><?= e(__('Logout')) ?></span>
                    </a>
                </nav>
            </aside>
        <?php endif; ?>
        <main class="content<?= $user ? '' : ' auth-mode' ?>">
            <?php if ($user): ?>
                <header class="top-bar">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="ph-bold ph-list"></i>
                    </button>
                    <h1 class="page-title"><?= e($title ?? $appName) ?></h1>
                    <div class="top-bar-actions">
                        <!-- Future: Notifications, Search etc -->
                    </div>
                </header>
            <?php endif; ?>

            <div class="content-inner">
                <?php $flash = get_flash(); ?>
                <?php if ($flash): ?>
                    <div class="alert <?= e($flash['type']) ?>">
                        <i
                            class="ph-bold <?= $flash['type'] === 'success' ? 'ph-check-circle' : 'ph-warning-circle' ?>"></i>
                        <span><?= e($flash['message']) ?></span>
                    </div>
                <?php endif; ?>
