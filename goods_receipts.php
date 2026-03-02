<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_purchases');
$companyId = require_company_id();

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$supplierFilter = (int) ($_GET['supplier_id'] ?? 0);
$dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$filters = ['gr.company_id = ?'];
$types = 'i';
$params = [$companyId];

if ($statusFilter !== '') {
    $filters[] = 'gr.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
if ($supplierFilter > 0) {
    $filters[] = 'po.supplier_id = ?';
    $types .= 'i';
    $params[] = $supplierFilter;
}
if ($dateFrom !== '') {
    $filters[] = 'DATE(gr.created_at) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $filters[] = 'DATE(gr.created_at) <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

$whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countSql = "
    SELECT COUNT(*) AS total
    FROM goods_receipts gr
    INNER JOIN purchase_orders po ON po.id = gr.purchase_order_id
    {$whereSql}
";
$countStmt = db()->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$sql = "
    SELECT gr.*, po.po_number, s.supplier_name, u.name AS creator_name
    FROM goods_receipts gr
    INNER JOIN purchase_orders po ON po.id = gr.purchase_order_id AND po.company_id = gr.company_id
    INNER JOIN suppliers s ON s.id = po.supplier_id AND s.company_id = po.company_id
    LEFT JOIN users u ON u.id = gr.created_by
    {$whereSql}
    ORDER BY gr.id DESC
    LIMIT ? OFFSET ?
";
$stmt = db()->prepare($sql);
$typesWithPaging = $types . 'ii';
$paramsWithPaging = [...$params, $limit, $offset];
$stmt->bind_param($typesWithPaging, ...$paramsWithPaging);
$stmt->execute();
$rows = $stmt->get_result();

$stmt = db()->prepare('SELECT id, supplier_name, supplier_code FROM suppliers WHERE company_id = ? ORDER BY supplier_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$suppliers = $stmt->get_result();

function gr_page_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    return http_build_query($query);
}

$title = page_title(__('Goods Receipts'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Goods Receipts')) ?></h1>

<section class="card">
    <h2><?= e(__('Filter')) ?></h2>
    <form method="get" class="filter-form">
        <select name="status">
            <option value=""><?= e(__('All statuses')) ?></option>
            <option value="posted" <?= $statusFilter === 'posted' ? 'selected' : '' ?>><?= e(__('posted')) ?></option>
            <option value="void" <?= $statusFilter === 'void' ? 'selected' : '' ?>><?= e(__('void')) ?></option>
        </select>
        <select name="supplier_id">
            <option value="0"><?= e(__('All suppliers')) ?></option>
            <?php while ($supplier = $suppliers->fetch_assoc()): ?>
                <option value="<?= e((string) $supplier['id']) ?>" <?= $supplierFilter === (int) $supplier['id'] ? 'selected' : '' ?>>
                    <?= e($supplier['supplier_name']) ?> (<?= e($supplier['supplier_code']) ?>)
                </option>
            <?php endwhile; ?>
        </select>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        <button type="submit"><?= e(__('Apply')) ?></button>
        <a class="link-btn" href="goods_receipts.php"><?= e(__('Reset')) ?></a>
    </form>
</section>

<section class="card">
    <table>
        <thead>
            <tr>
                <th><?= e(__('GR No')) ?></th>
                <th><?= e(__('PO No')) ?></th>
                <th><?= e(__('Supplier')) ?></th>
                <th><?= e(__('Status')) ?></th>
                <th><?= e(__('Total Qty')) ?></th>
                <th><?= e(__('Total Amount')) ?></th>
                <th><?= e(__('Created By')) ?></th>
                <th><?= e(__('Date')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows->num_rows === 0): ?>
                <tr><td colspan="8"><?= e(__('No goods receipts yet.')) ?></td></tr>
            <?php else: ?>
                <?php while ($row = $rows->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['gr_number']) ?></td>
                        <td><a href="purchase_order_view.php?id=<?= e((string) $row['purchase_order_id']) ?>"><?= e($row['po_number']) ?></a></td>
                        <td><?= e($row['supplier_name']) ?></td>
                        <td><?= e(__($row['status'])) ?></td>
                        <td><?= e((string) $row['total_qty']) ?></td>
                        <td><?= e(format_money((float) $row['total_amount'])) ?></td>
                        <td><?= e($row['creator_name']) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="goods_receipts.php?<?= e(gr_page_query(['page' => $page - 1])) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="goods_receipts.php?<?= e(gr_page_query(['page' => $page + 1])) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
