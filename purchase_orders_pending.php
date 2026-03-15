<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('approve_orders');
$companyId = require_company_id();

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$stmt = db()->prepare("SELECT COUNT(*) AS total FROM purchase_orders WHERE company_id = ? AND status = 'draft'");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$totalRows = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$stmt = db()->prepare(
    "SELECT po.id, po.po_number, po.order_date, po.total_amount, po.created_at, s.supplier_name, s.supplier_code, u.name AS creator_name
     FROM purchase_orders po
     INNER JOIN suppliers s ON s.id = po.supplier_id AND s.company_id = po.company_id
     LEFT JOIN users u ON u.id = po.created_by
     WHERE po.company_id = ? AND po.status = 'draft'
     ORDER BY po.id DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('iii', $companyId, $limit, $offset);
$stmt->execute();
$orders = $stmt->get_result();

$title = page_title(__('Pending Purchase Order Approvals'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Pending Purchase Order Approvals')) ?></h1>

<section class="card">
    <h2><?= e(__('Unapproved Purchase Orders')) ?></h2>
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th><?= e(__('PO No')) ?></th>
                <th><?= e(__('Supplier')) ?></th>
                <th><?= e(__('Order Date')) ?></th>
                <th><?= e(__('Total')) ?></th>
                <th><?= e(__('Created By')) ?></th>
                <th><?= e(__('Created At')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders->num_rows === 0): ?>
                <tr><td colspan="7"><?= e(__('No pending purchase orders.')) ?></td></tr>
            <?php else: ?>
                <?php while ($row = $orders->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['po_number']) ?></td>
                        <td><?= e($row['supplier_name']) ?> (<?= e($row['supplier_code']) ?>)</td>
                        <td><?= e($row['order_date']) ?></td>
                        <td><?= e(format_money((float) $row['total_amount'])) ?></td>
                        <td><?= e($row['creator_name'] ?? '-') ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td><a href="purchase_order_approve.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Review')) ?></a></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="purchase_orders_pending.php?page=<?= e((string) ($page - 1)) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="purchase_orders_pending.php?page=<?= e((string) ($page + 1)) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

