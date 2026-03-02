<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_purchases');
$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('purchase_orders.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $orderDate = (string) ($_POST['order_date'] ?? date('Y-m-d'));
        $expectedDate = trim((string) ($_POST['expected_date'] ?? ''));
        $remark = trim((string) ($_POST['remark'] ?? ''));

        if ($supplierId <= 0 || $orderDate === '') {
            set_flash('error', __('Supplier and order date are required.'));
            redirect('purchase_orders.php');
        }
        $stmt = db()->prepare('SELECT id FROM suppliers WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $supplierId, $companyId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            set_flash('error', __('Selected supplier not found.'));
            redirect('purchase_orders.php');
        }

        $poNumber = generate_doc_number('PO', 'purchase_orders');
        $createdBy = current_user_id();
        $expectedDateOrNull = $expectedDate !== '' ? $expectedDate : null;

        $stmt = db()->prepare(
            'INSERT INTO purchase_orders (company_id, po_number, supplier_id, order_date, expected_date, remark, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisssi', $companyId, $poNumber, $supplierId, $orderDate, $expectedDateOrNull, $remark, $createdBy);
        $stmt->execute();
        set_flash('success', __('Purchase order created.'));
        redirect('purchase_order_view.php?id=' . (int) db()->insert_id);
    }

    if ($action === 'status') {
        if (!has_permission('approve_orders')) {
            set_flash('error', __('You do not have permission to approve orders.'));
            redirect('purchase_orders.php');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $toStatus = (string) ($_POST['status'] ?? '');
        if ($id <= 0 || $toStatus === '') {
            set_flash('error', __('Invalid status update.'));
            redirect('purchase_orders.php');
        }

        $stmt = db()->prepare('SELECT status FROM purchase_orders WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $po = $stmt->get_result()->fetch_assoc();
        if (!$po) {
            set_flash('error', __('Purchase order not found.'));
            redirect('purchase_orders.php');
        }

        $fromStatus = (string) $po['status'];
        if (!can_transition_status('purchase_order', $fromStatus, $toStatus)) {
            set_flash('error', __('Invalid status transition'));
            redirect('purchase_orders.php');
        }

        $stmt = db()->prepare('UPDATE purchase_orders SET status = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('sii', $toStatus, $id, $companyId);
        $stmt->execute();
        set_flash('success', __('Purchase order status updated.'));
        redirect('purchase_orders.php');
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$supplierFilter = (int) ($_GET['supplier_id'] ?? 0);
$dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$filters = ['po.company_id = ?'];
$types = 'i';
$params = [$companyId];

if ($statusFilter !== '') {
    $filters[] = 'po.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
if ($supplierFilter > 0) {
    $filters[] = 'po.supplier_id = ?';
    $types .= 'i';
    $params[] = $supplierFilter;
}
if ($dateFrom !== '') {
    $filters[] = 'po.order_date >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $filters[] = 'po.order_date <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

$whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countSql = "SELECT COUNT(*) AS total FROM purchase_orders po {$whereSql}";
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
    SELECT po.*, s.supplier_name, u.name AS creator_name
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.id = po.supplier_id AND s.company_id = po.company_id
    LEFT JOIN users u ON u.id = po.created_by
    {$whereSql}
    ORDER BY po.id DESC
    LIMIT ? OFFSET ?
";
$stmt = db()->prepare($sql);
$typesWithPaging = $types . 'ii';
$paramsWithPaging = [...$params, $limit, $offset];
$stmt->bind_param($typesWithPaging, ...$paramsWithPaging);
$stmt->execute();
$orders = $stmt->get_result();

$stmt = db()->prepare('SELECT id, supplier_name, supplier_code FROM suppliers WHERE company_id = ? ORDER BY supplier_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$suppliers = $stmt->get_result();

$stmt = db()->prepare('SELECT id, supplier_name, supplier_code FROM suppliers WHERE company_id = ? ORDER BY supplier_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$suppliersForFilter = $stmt->get_result();

function po_page_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    return http_build_query($query);
}

$title = page_title(__('Purchase Orders'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Purchase Orders')) ?></h1>

<section class="card">
    <h2><?= e(__('Create Purchase Order')) ?></h2>
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <label><?= e(__('Supplier')) ?>
            <select name="supplier_id" required>
                <option value=""><?= e(__('Select supplier')) ?></option>
                <?php while ($supplier = $suppliers->fetch_assoc()): ?>
                    <option value="<?= e((string) $supplier['id']) ?>">
                        <?= e($supplier['supplier_name']) ?> (<?= e($supplier['supplier_code']) ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label><?= e(__('Order Date')) ?>
            <input type="date" name="order_date" value="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <label><?= e(__('Expected Date')) ?>
            <input type="date" name="expected_date">
        </label>
        <label><?= e(__('Remark')) ?>
            <textarea name="remark" rows="2"></textarea>
        </label>
        <button type="submit"><?= e(__('Create PO')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Filter')) ?></h2>
    <form method="get" class="filter-form">
        <select name="status">
            <option value=""><?= e(__('All statuses')) ?></option>
            <?php foreach (['draft', 'approved', 'partial_received', 'received', 'closed', 'cancelled'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(__($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="supplier_id">
            <option value="0"><?= e(__('All suppliers')) ?></option>
            <?php while ($supplier = $suppliersForFilter->fetch_assoc()): ?>
                <option value="<?= e((string) $supplier['id']) ?>" <?= $supplierFilter === (int) $supplier['id'] ? 'selected' : '' ?>>
                    <?= e($supplier['supplier_name']) ?> (<?= e($supplier['supplier_code']) ?>)
                </option>
            <?php endwhile; ?>
        </select>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        <button type="submit"><?= e(__('Apply')) ?></button>
        <a class="link-btn" href="purchase_orders.php"><?= e(__('Reset')) ?></a>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Purchase Order List')) ?></h2>
    <table>
        <thead>
            <tr>
                <th><?= e(__('PO No')) ?></th>
                <th><?= e(__('Supplier')) ?></th>
                <th><?= e(__('Order Date')) ?></th>
                <th><?= e(__('Expected')) ?></th>
                <th><?= e(__('Total')) ?></th>
                <th><?= e(__('Status')) ?></th>
                <th><?= e(__('Created By')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders->num_rows === 0): ?>
                <tr>
                    <td colspan="8"><?= e(__('No purchase orders yet.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($row = $orders->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['po_number']) ?></td>
                        <td><?= e($row['supplier_name']) ?></td>
                        <td><?= e($row['order_date']) ?></td>
                        <td><?= e($row['expected_date']) ?></td>
                        <td><?= e(format_money((float) $row['total_amount'])) ?></td>
                        <td><?= e(__($row['status'])) ?></td>
                        <td><?= e($row['creator_name']) ?></td>
                        <td>
                            <?php if (has_permission('approve_orders') && (string) $row['status'] === 'draft'): ?>
                                <a href="purchase_order_approve.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Review')) ?></a>
                            <?php else: ?>
                                <a href="purchase_order_view.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Open')) ?></a>
                            <?php endif; ?>
                            <?php
                            $statusOptions = [
                                'draft' => ['approved', 'cancelled'],
                                'approved' => ['partial_received', 'received', 'cancelled'],
                                'partial_received' => ['received', 'cancelled'],
                                'received' => ['closed'],
                                'closed' => [],
                                'cancelled' => [],
                            ];
                            $next = $statusOptions[$row['status']] ?? [];
                            ?>
                            <?php if (!empty($next) && has_permission('approve_orders') && (string) $row['status'] !== 'draft'): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                    <select name="status">
                                        <?php foreach ($next as $opt): ?>
                                            <option value="<?= e($opt) ?>"><?= e(__($opt)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit"><?= e(__('Update')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="purchase_orders.php?<?= e(po_page_query(['page' => $page - 1])) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="purchase_orders.php?<?= e(po_page_query(['page' => $page + 1])) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
