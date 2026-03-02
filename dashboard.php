<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('view_dashboard');
$companyId = require_company_id();

$totals = [
    'products' => 0,
    'stock' => 0,
    'inventory_value' => 0.0,
    'pending_po' => 0,
    'pending_so' => 0,
];

$stmt = db()->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(quantity),0) AS q, COALESCE(SUM(quantity * purchase_price),0) AS val FROM products WHERE company_id = ?');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $totals['products'] = (int) $row['c'];
    $totals['stock'] = (int) $row['q'];
    $totals['inventory_value'] = (float) $row['val'];
}

$lowStockThreshold = (int) (setting('low_stock_threshold', '5') ?? '5');
$stmt = db()->prepare(
    'SELECT p.id, p.product_name, p.quantity, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id AND c.company_id = p.company_id
     WHERE p.quantity <= ? AND p.company_id = ?
     ORDER BY p.quantity ASC, p.product_name ASC
     LIMIT 8'
);
$stmt->bind_param('ii', $lowStockThreshold, $companyId);
$stmt->execute();
$lowStockProducts = $stmt->get_result();

$stmt = db()->prepare(
    'SELECT sm.product_name, sm.sku, sm.movement_type, sm.qty_change, sm.created_at, u.name AS user_name
     FROM stock_movements sm
     LEFT JOIN users u ON u.id = sm.created_by
     WHERE sm.company_id = ?
     ORDER BY sm.id DESC
     LIMIT 8'
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$recentMovements = $stmt->get_result();

$stmt = db()->prepare(
    'SELECT po.id, po.po_number, po.status, po.total_amount, po.order_date, s.supplier_name 
     FROM purchase_orders po 
     LEFT JOIN suppliers s ON s.id = po.supplier_id AND s.company_id = po.company_id
     WHERE po.company_id = ?
     ORDER BY po.id DESC LIMIT 5'
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$recentPO = $stmt->get_result();

$stmt = db()->prepare(
    'SELECT c.id, c.name, COUNT(p.id) AS products_count, COALESCE(SUM(p.quantity), 0) AS total_quantity
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id AND p.company_id = c.company_id
     WHERE c.company_id = ?
     GROUP BY c.id, c.name
     ORDER BY c.name ASC'
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$categorySummary = $stmt->get_result();

$hasSalesOrdersTable = false;
try {
    $res = db()->query("SHOW TABLES LIKE 'sales_orders'");
    $hasSalesOrdersTable = $res && $res->num_rows > 0;
} catch (Throwable $e) {
}

$recentSO = false;
if ($hasSalesOrdersTable) {
    try {
        $recentSO = db()->query(
            'SELECT id, so_number, status, total_amount, order_date, customer_name 
             FROM sales_orders 
             WHERE company_id = ' . (int) $companyId . '
             ORDER BY id DESC LIMIT 5'
        );
    } catch (Throwable $e) {
    }
}

if (has_permission('approve_orders')) {
    $stmt = db()->prepare("SELECT COUNT(*) AS total FROM purchase_orders WHERE company_id = ? AND status = 'draft'");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $totals['pending_po'] = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($hasSalesOrdersTable) {
        $stmt = db()->prepare("SELECT COUNT(*) AS total FROM sales_orders WHERE company_id = ? AND status = 'draft'");
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $totals['pending_so'] = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }
}

$title = page_title(__('Dashboard'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Dashboard')) ?></h1>
<div class="grid-3">
    <article class="card stat-card">
        <div class="card-icon" style="background: rgba(15, 118, 110, 0.1); color: var(--primary);">
            <i class="ph-bold ph-package"></i>
        </div>
        <div>
            <h3><?= e(__('Total Products')) ?></h3>
            <p class="stat"><?= e((string) $totals['products']) ?></p>
        </div>
    </article>
    <article class="card stat-card">
        <div class="card-icon" style="background: rgba(99, 102, 241, 0.1); color: var(--secondary);">
            <i class="ph-bold ph-coins"></i>
        </div>
        <div>
            <h3><?= e(__('Total Quantity')) ?></h3>
            <p class="stat"><?= e((string) $totals['stock']) ?></p>
        </div>
    </article>
    <article class="card stat-card">
        <div class="card-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
            <i class="ph-bold ph-chart-line-up"></i>
        </div>
        <div>
            <h3><?= e(__('Inventory Value')) ?></h3>
            <p class="stat"><?= e(format_money($totals['inventory_value'])) ?></p>
        </div>
    </article>
</div>

<?php if (has_permission('approve_orders')): ?>
    <div class="grid-3">
        <a class="card stat-card approval-card-link" href="purchase_orders_pending.php">
            <div class="card-icon" style="background: rgba(245, 158, 11, 0.15); color: #b45309;">
                <i class="ph-bold ph-clipboard-text"></i>
            </div>
            <div>
                <h3><?= e(__('Unapproved Purchase Orders')) ?></h3>
                <p class="stat"><?= e((string) $totals['pending_po']) ?></p>
            </div>
        </a>
        <a class="card stat-card approval-card-link" href="sales_orders_pending.php">
            <div class="card-icon" style="background: rgba(249, 115, 22, 0.15); color: #c2410c;">
                <i class="ph-bold ph-shopping-cart-simple"></i>
            </div>
            <div>
                <h3><?= e(__('Unapproved Sales Orders')) ?></h3>
                <p class="stat"><?= e((string) $totals['pending_so']) ?></p>
            </div>
        </a>
    </div>
<?php endif; ?>

<div class="grid-2">
    <section class="card d-block">
        <h2><i class="ph-bold ph-file-text"></i> <?= e(__('Recent Purchase Orders')) ?></h2>
        <?php if (!$recentPO || $recentPO->num_rows === 0): ?>
            <p class="muted"><?= e(__('No purchase orders found.')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?= e(__('PO No')) ?></th>
                            <th><?= e(__('Supplier')) ?></th>
                            <th><?= e(__('Status')) ?></th>
                            <th><?= e(__('Total')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($po = $recentPO->fetch_assoc()): ?>
                            <tr>
                                <td><a
                                        href="purchase_order_view.php?id=<?= e((string) $po['id']) ?>"><?= e($po['po_number']) ?></a>
                                </td>
                                <td><?= e($po['supplier_name']) ?></td>
                                <td><span class="badge"><?= e(__($po['status'])) ?></span></td>
                                <td><?= e(format_money((float) $po['total_amount'])) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card d-block">
        <h2><i class="ph-bold ph-shopping-cart"></i> <?= e(__('Recent Sales Orders')) ?></h2>
        <?php if (!$recentSO || $recentSO->num_rows === 0): ?>
            <p class="muted"><?= e(__('No sales orders found.')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><?= e(__('SO No')) ?></th>
                            <th><?= e(__('Customer')) ?></th>
                            <th><?= e(__('Status')) ?></th>
                            <th><?= e(__('Total')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($so = $recentSO->fetch_assoc()): ?>
                            <tr>
                                <td><a href="sales_order_view.php?id=<?= e((string) $so['id']) ?>"><?= e($so['so_number']) ?></a>
                                </td>
                                <td><?= e($so['customer_name'] ?? '-') ?></td>
                                <td><span class="badge"><?= e(__($so['status'])) ?></span></td>
                                <td><?= e(format_money((float) $so['total_amount'])) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="card d-block">
    <h2><i class="ph-bold ph-warning-octagon" style="color: var(--danger);"></i> <?= e(__('Low Stock')) ?> (<=
            <?= e((string) $lowStockThreshold) ?>)</h2>
            <?php if ($lowStockProducts->num_rows === 0): ?>
                <p class="muted"><?= e(__('No low-stock items.')) ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><?= e(__('Product')) ?></th>
                                <th><?= e(__('Category')) ?></th>
                                <th><?= e(__('Quantity')) ?></th>
                                <th><?= e(__('Action')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($item = $lowStockProducts->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= e($item['product_name']) ?></strong></td>
                                    <td><span class="badge"><?= e(__($item['category_name'] ?? 'Uncategorized')) ?></span></td>
                                    <td><span class="badge danger"><?= e((string) $item['quantity']) ?></span></td>
                                    <td><a href="product_form.php?id=<?= e((string) $item['id']) ?>"
                                            class="link-btn-sm"><?= e(__('Edit')) ?></a></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
</section>

<section class="card d-block">
    <h2><i class="ph-bold ph-tag"></i> <?= e(__('Product Categories')) ?></h2>
    <?php if ($categorySummary->num_rows === 0): ?>
        <p class="muted"><?= e(__('No categories yet.')) ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?= e(__('Category')) ?></th>
                        <th><?= e(__('Products')) ?></th>
                        <th><?= e(__('Total Quantity')) ?></th>
                        <th><?= e(__('Action')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($cat = $categorySummary->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= e($cat['name']) ?></strong></td>
                            <td><?= e((string) $cat['products_count']) ?></td>
                            <td><?= e((string) $cat['total_quantity']) ?></td>
                            <td><a href="categories.php?id=<?= e((string) $cat['id']) ?>"
                                    class="link-btn-sm"><?= e(__('Edit')) ?></a></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card d-block">
    <h2><i class="ph-bold ph-clock-counter-clockwise"></i> <?= e(__('Recent Stock Movements')) ?></h2>
    <?php if ($recentMovements->num_rows === 0): ?>
        <p class="muted"><?= e(__('No stock movement records yet.')) ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?= e(__('Product')) ?></th>
                        <th><?= e(__('SKU')) ?></th>
                        <th><?= e(__('Type')) ?></th>
                        <th><?= e(__('Qty Change')) ?></th>
                        <th><?= e(__('User')) ?></th>
                        <th><?= e(__('Date')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($move = $recentMovements->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= e($move['product_name']) ?></strong></td>
                            <td><code><?= e($move['sku']) ?></code></td>
                            <td><span
                                    class="badge <?= strpos($move['movement_type'], 'IN') !== false ? 'success' : 'warning' ?>"><?= e(__($move['movement_type'])) ?></span>
                            </td>
                            <td><strong><?= (int) $move['qty_change'] > 0 ? '+' : '' ?><?= e((string) $move['qty_change']) ?></strong>
                            </td>
                            <td><?= e(__($move['user_name'] ?? 'System')) ?></td>
                            <td class="muted"><?= e($move['created_at']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
