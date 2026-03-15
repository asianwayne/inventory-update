<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_auth();
$companyId = require_company_id();

$q = trim((string) ($_GET['q'] ?? ''));
$title = page_title($q !== '' ? sprintf(__('Search results for "%s"'), $q) : __('Global Search'));

$results = [
    'products' => [],
    'suppliers' => [],
    'customers' => [],
    'sales_orders' => [],
    'purchase_orders' => [],
    'invoices' => []
];

if ($q !== '') {
    $searchTerm = '%' . $q . '%';
    $conn = db();

    // 1. Search Products
    $stmt = $conn->prepare("SELECT id, product_name as title, sku as subtitle, 'product_view.php?id=' as link FROM products WHERE company_id = ? AND (product_name LIKE ? OR sku LIKE ? OR oem_number LIKE ?) LIMIT 10");
    $stmt->bind_param('isss', $companyId, $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $results['products'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 2. Search Suppliers
    $stmt = $conn->prepare("SELECT id, supplier_name as title, supplier_code as subtitle, 'supplier_view.php?id=' as link FROM suppliers WHERE company_id = ? AND (supplier_name LIKE ? OR supplier_code LIKE ?) LIMIT 10");
    $stmt->bind_param('iss', $companyId, $searchTerm, $searchTerm);
    $stmt->execute();
    $results['suppliers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 3. Search Customers
    $stmt = $conn->prepare("SELECT id, customer_name as title, customer_code as subtitle, 'customer_view.php?id=' as link FROM customers WHERE company_id = ? AND (customer_name LIKE ? OR customer_code LIKE ?) LIMIT 10");
    $stmt->bind_param('iss', $companyId, $searchTerm, $searchTerm);
    $stmt->execute();
    $results['customers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 4. Search Sales Orders
    $stmt = $conn->prepare("SELECT id, so_number as title, status as subtitle, 'sales_order_view.php?id=' as link FROM sales_orders WHERE company_id = ? AND so_number LIKE ? LIMIT 10");
    $stmt->bind_param('is', $companyId, $searchTerm);
    $stmt->execute();
    $results['sales_orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 5. Search Purchase Orders
    $stmt = $conn->prepare("SELECT id, po_number as title, status as subtitle, 'purchase_order_view.php?id=' as link FROM purchase_orders WHERE company_id = ? AND po_number LIKE ? LIMIT 10");
    $stmt->bind_param('is', $companyId, $searchTerm);
    $stmt->execute();
    $results['purchase_orders'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // 6. Search Invoices
    $stmt = $conn->prepare("SELECT id, invoice_number as title, status as subtitle, 'invoice_view.php?id=' as link FROM invoices WHERE company_id = ? AND invoice_number LIKE ? LIMIT 10");
    $stmt->bind_param('is', $companyId, $searchTerm);
    $stmt->execute();
    $results['invoices'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="search-page">
    <h1><?= e(__('Global Search')) ?></h1>
    
    <div class="card mb-4">
        <form action="search.php" method="get" class="global-search-form">
            <div class="search-input-wrapper">
                <i class="ph-bold ph-magnifying-glass"></i>
                <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Search everything...')) ?>" autofocus>
                <button type="submit" class="primary"><?= e(__('Search')) ?></button>
            </div>
        </form>
    </div>

    <?php if ($q !== ''): ?>
        <?php 
        $hasResults = false;
        foreach ($results as $group => $items) {
            if (!empty($items)) {
                $hasResults = true;
                break;
            }
        }
        ?>

        <?php if (!$hasResults): ?>
            <div class="empty-state card">
                <i class="ph-bold ph-ghost"></i>
                <p><?= e(sprintf(__('No results found for "%s"'), $q)) ?></p>
            </div>
        <?php else: ?>
            <div class="search-results-grid">
                <?php foreach ($results as $type => $groupResults): ?>
                    <?php if (!empty($groupResults)): ?>
                        <div class="search-group card">
                            <h2 class="group-title">
                                <?php 
                                $groupLabels = [
                                    'products' => __('Products'),
                                    'suppliers' => __('Suppliers'),
                                    'customers' => __('Customers'),
                                    'sales_orders' => __('Sales Orders'),
                                    'purchase_orders' => __('Purchase Orders'),
                                    'invoices' => __('Invoices'),
                                ];
                                echo e($groupLabels[$type]);
                                ?>
                            </h2>
                            <ul class="result-list">
                                <?php foreach ($groupResults as $res): ?>
                                    <li>
                                        <a href="<?= e($res['link'] . ($type === 'invoices' ? urlencode($res['title']) : $res['id'])) ?>" class="result-item">
                                            <div class="result-info">
                                                <span class="result-title"><?= e($res['title']) ?></span>
                                                <span class="result-subtitle"><?= e(__((string)$res['subtitle'])) ?></span>
                                            </div>
                                            <i class="ph-bold ph-caret-right"></i>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.search-input-wrapper {
    display: flex;
    align-items: center;
    gap: 15px;
    background: var(--bg-body);
    padding: 5px 15px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
}
.search-input-wrapper i {
    font-size: 1.2rem;
    color: var(--text-muted);
}
.search-input-wrapper input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 10px 0;
    font-size: 1.1rem;
    outline: none;
}
.search-results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}
.group-title {
    font-size: 1rem;
    color: var(--primary-color);
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}
.result-list {
    list-style: none;
    padding: 0;
}
.result-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-main);
    transition: background 0.2s;
}
.result-item:hover {
    background: rgba(0,0,0,0.03);
}
.result-info {
    display: flex;
    flex-direction: column;
}
.result-title {
    font-weight: 600;
}
.result-subtitle {
    font-size: 0.85rem;
    color: var(--text-muted);
}
.empty-state {
    text-align: center;
    padding: 50px;
    color: var(--text-muted);
}
.empty-state i {
    font-size: 3rem;
    margin-bottom: 15px;
}
.mb-4 { margin-bottom: 1.5rem; }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
