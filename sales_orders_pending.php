<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('approve_orders');
$companyId = require_company_id();

function table_exists_local(string $tableName): bool
{
    try {
        $safeName = db()->real_escape_string($tableName);
        $result = db()->query("SHOW TABLES LIKE '{$safeName}'");
        if ($result === false) {
            return false;
        }
        return $result->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (!table_exists_local('sales_orders')) {
    $title = page_title(__('Pending Sales Order Approvals'));
    require_once __DIR__ . '/includes/header.php';
    ?>
    <h1><?= e(__('Pending Sales Order Approvals')) ?></h1>
    <section class="card">
        <p><?= e(__('Sales orders table is not available.')) ?></p>
    </section>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <?php
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$stmt = db()->prepare("SELECT COUNT(*) AS total FROM sales_orders WHERE company_id = ? AND status = 'draft'");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$totalRows = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$stmt = db()->prepare(
    "SELECT so.id, so.so_number, so.order_date, so.total_amount, so.created_at, so.customer_name, u.name AS creator_name
     FROM sales_orders so
     LEFT JOIN users u ON u.id = so.created_by
     WHERE so.company_id = ? AND so.status = 'draft'
     ORDER BY so.id DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('iii', $companyId, $limit, $offset);
$stmt->execute();
$orders = $stmt->get_result();

$title = page_title(__('Pending Sales Order Approvals'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Pending Sales Order Approvals')) ?></h1>

<section class="card">
    <h2><?= e(__('Unapproved Sales Orders')) ?></h2>
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th><?= e(__('SO No')) ?></th>
                <th><?= e(__('Customer')) ?></th>
                <th><?= e(__('Order Date')) ?></th>
                <th><?= e(__('Total')) ?></th>
                <th><?= e(__('Created By')) ?></th>
                <th><?= e(__('Created At')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders->num_rows === 0): ?>
                <tr><td colspan="7"><?= e(__('No pending sales orders.')) ?></td></tr>
            <?php else: ?>
                <?php while ($row = $orders->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['so_number']) ?></td>
                        <td><?= e($row['customer_name']) ?></td>
                        <td><?= e($row['order_date']) ?></td>
                        <td><?= e(format_money((float) $row['total_amount'])) ?></td>
                        <td><?= e($row['creator_name'] ?? '-') ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td><a href="sales_order_approve.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Review')) ?></a></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="sales_orders_pending.php?page=<?= e((string) ($page - 1)) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="sales_orders_pending.php?page=<?= e((string) ($page + 1)) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

