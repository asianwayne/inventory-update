<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_returns');
$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('returns.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $returnType = (string) ($_POST['return_type'] ?? '');
        $productId = (int) ($_POST['product_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        $unitAmount = (float) ($_POST['unit_amount'] ?? 0);
        $referenceType = trim((string) ($_POST['reference_type'] ?? ''));
        $referenceId = (int) ($_POST['reference_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));

        if (!in_array($returnType, ['sales_return', 'purchase_return'], true) || $productId <= 0 || $qty <= 0 || $unitAmount < 0) {
            set_flash('error', __('Invalid return data.'));
            redirect('returns.php');
        }
        $stmt = db()->prepare('SELECT id FROM products WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $productId, $companyId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            set_flash('error', __('Product not found.'));
            redirect('returns.php');
        }

        $returnNumber = generate_doc_number('RET', 'inventory_returns');
        $createdBy = current_user_id();
        $refTypeOrNull = $referenceType !== '' ? $referenceType : null;
        $refIdOrNull = $referenceId > 0 ? $referenceId : null;

        $stmt = db()->prepare(
            'INSERT INTO inventory_returns (company_id, return_number, return_type, product_id, qty, unit_amount, reference_type, reference_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), ?, ?)'
        );
        $stmt->bind_param('issiidsisi', $companyId, $returnNumber, $returnType, $productId, $qty, $unitAmount, $refTypeOrNull, $refIdOrNull, $note, $createdBy);
        $stmt->execute();
        set_flash('success', __('Return request created.'));
        redirect('returns.php');
    }

    if ($action === 'status') {
        $id = (int) ($_POST['id'] ?? 0);
        $toStatus = (string) ($_POST['status'] ?? '');
        if ($id <= 0 || $toStatus === '') {
            set_flash('error', __('Invalid status update.'));
            redirect('returns.php');
        }

        $conn = db();
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                'SELECT r.*, p.product_name, p.sku, p.quantity
                 FROM inventory_returns r
                 INNER JOIN products p ON p.id = r.product_id
                 WHERE r.id = ? AND r.company_id = ? AND p.company_id = ?
                 FOR UPDATE'
            );
            $stmt->bind_param('iii', $id, $companyId, $companyId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                throw new RuntimeException(__('Return not found.'));
            }

            $fromStatus = (string) $row['status'];
            if (!can_transition_status('return', $fromStatus, $toStatus)) {
                throw new RuntimeException(__('Invalid status transition'));
            }

            $stmt = $conn->prepare('UPDATE inventory_returns SET status = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('sii', $toStatus, $id, $companyId);
            $stmt->execute();

            if ($toStatus === 'completed') {
                $oldQty = (int) $row['quantity'];
                $qty = (int) $row['qty'];
                $newQty = $oldQty;
                $movementType = 'adjustment_in';
                $qtyChange = $qty;

                if ((string) $row['return_type'] === 'sales_return') {
                    $newQty = $oldQty + $qty;
                    $movementType = 'adjustment_in';
                    $qtyChange = $qty;
                } else {
                    if ($oldQty < $qty) {
                        throw new RuntimeException(__('Insufficient stock for purchase return.'));
                    }
                    $newQty = $oldQty - $qty;
                    $movementType = 'adjustment_out';
                    $qtyChange = -$qty;
                }

                $stmt = $conn->prepare('UPDATE products SET quantity = ? WHERE id = ? AND company_id = ?');
                $stmt->bind_param('iii', $newQty, $row['product_id'], $companyId);
                $stmt->execute();

                log_stock_movement(
                    (int) $row['product_id'],
                    (string) $row['product_name'],
                    (string) $row['sku'],
                    $movementType,
                    $qtyChange,
                    $oldQty,
                    $newQty,
                    (float) $row['unit_amount'],
                    'return',
                    $id,
                    (string) $row['return_type'] . ' completed'
                );
            }

            $conn->commit();
            set_flash('success', __('Return status updated.'));
        } catch (Throwable $e) {
            $conn->rollback();
            set_flash('error', $e->getMessage());
        }

        redirect('returns.php');
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$typeFilter = trim((string) ($_GET['return_type'] ?? ''));
$dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$filters = ['r.company_id = ?'];
$types = 'i';
$params = [$companyId];

if ($statusFilter !== '') {
    $filters[] = 'r.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
if ($typeFilter !== '') {
    $filters[] = 'r.return_type = ?';
    $types .= 's';
    $params[] = $typeFilter;
}
if ($dateFrom !== '') {
    $filters[] = 'DATE(r.created_at) >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $filters[] = 'DATE(r.created_at) <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

$whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countSql = "SELECT COUNT(*) AS total FROM inventory_returns r {$whereSql}";
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
    SELECT r.*, p.product_name, p.sku, u.name AS creator_name
    FROM inventory_returns r
    INNER JOIN products p ON p.id = r.product_id AND p.company_id = r.company_id
    LEFT JOIN users u ON u.id = r.created_by
    {$whereSql}
    ORDER BY r.id DESC
    LIMIT ? OFFSET ?
";
$stmt = db()->prepare($sql);
$typesWithPaging = $types . 'ii';
$paramsWithPaging = [...$params, $limit, $offset];
$stmt->bind_param($typesWithPaging, ...$paramsWithPaging);
$stmt->execute();
$rows = $stmt->get_result();

$stmt = db()->prepare('SELECT id, product_name, sku, quantity FROM products WHERE company_id = ? ORDER BY product_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$products = $stmt->get_result();

function return_page_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    return http_build_query($query);
}

$title = page_title(__('Returns'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Returns')) ?></h1>

<section class="card">
    <h2><?= e(__('Create Return')) ?></h2>
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <label><?= e(__('Return Type')) ?>
            <select name="return_type" required>
                <option value="sales_return"><?= e(__('Sales Return')) ?></option>
                <option value="purchase_return"><?= e(__('Purchase Return')) ?></option>
            </select>
        </label>
        <label><?= e(__('Product')) ?>
            <select name="product_id" required>
                <option value=""><?= e(__('Select product')) ?></option>
                <?php while ($product = $products->fetch_assoc()): ?>
                    <option value="<?= e((string) $product['id']) ?>">
                        <?= e($product['product_name']) ?> (<?= e($product['sku']) ?>) - <?= e(__('Stock')) ?> <?= e((string) $product['quantity']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label><?= e(__('Quantity')) ?>
            <input type="number" name="qty" min="1" required>
        </label>
        <label><?= e(__('Unit Amount')) ?>
            <input type="number" name="unit_amount" min="0" step="0.01" required>
        </label>
        <label><?= e(__('Reference Type')) ?>
            <input type="text" name="reference_type" placeholder="invoice / purchase_order / other">
        </label>
        <label><?= e(__('Reference ID')) ?>
            <input type="number" name="reference_id" min="0">
        </label>
        <label><?= e(__('Note')) ?>
            <textarea name="note" rows="2"></textarea>
        </label>
        <button type="submit"><?= e(__('Create Return')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Filter')) ?></h2>
    <form method="get" class="filter-form">
        <select name="status">
            <option value=""><?= e(__('All statuses')) ?></option>
            <?php foreach (['requested', 'approved', 'rejected', 'completed'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(__($status)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="return_type">
            <option value=""><?= e(__('All return types')) ?></option>
            <option value="sales_return" <?= $typeFilter === 'sales_return' ? 'selected' : '' ?>><?= e(__('sales_return')) ?></option>
            <option value="purchase_return" <?= $typeFilter === 'purchase_return' ? 'selected' : '' ?>><?= e(__('purchase_return')) ?></option>
        </select>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        <button type="submit"><?= e(__('Apply')) ?></button>
        <a class="link-btn" href="returns.php"><?= e(__('Reset')) ?></a>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Return List')) ?></h2>
    <table>
        <thead>
            <tr>
                <th><?= e(__('Return No')) ?></th>
                <th><?= e(__('Type')) ?></th>
                <th><?= e(__('Product')) ?></th>
                <th><?= e(__('Qty')) ?></th>
                <th><?= e(__('Unit Amount')) ?></th>
                <th><?= e(__('Status')) ?></th>
                <th><?= e(__('Reference')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows->num_rows === 0): ?>
                <tr><td colspan="8"><?= e(__('No returns yet.')) ?></td></tr>
            <?php else: ?>
                <?php while ($row = $rows->fetch_assoc()): ?>
                    <?php
                        $statusOptions = [
                            'requested' => ['approved', 'rejected'],
                            'approved' => ['completed', 'rejected'],
                            'rejected' => [],
                            'completed' => [],
                        ];
                        $next = $statusOptions[$row['status']] ?? [];
                    ?>
                    <tr>
                        <td><?= e($row['return_number']) ?></td>
                        <td><?= e(__($row['return_type'])) ?></td>
                        <td><?= e($row['product_name']) ?> (<?= e($row['sku']) ?>)</td>
                        <td><?= e((string) $row['qty']) ?></td>
                        <td><?= e(format_money((float) $row['unit_amount'])) ?></td>
                        <td><?= e(__($row['status'])) ?></td>
                        <td>
                            <?php if (!empty($row['reference_type'])): ?>
                                <?= e($row['reference_type']) ?>#<?= e((string) $row['reference_id']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($next)): ?>
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
            <a href="returns.php?<?= e(return_page_query(['page' => $page - 1])) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="returns.php?<?= e(return_page_query(['page' => $page + 1])) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
