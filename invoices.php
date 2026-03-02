<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_invoices');
$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('invoices.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'payment') {
        $id = (int) ($_POST['id'] ?? 0);
        $amount = (float) ($_POST['amount'] ?? 0);
        if ($id <= 0 || $amount <= 0) {
            set_flash('error', __('Invalid payment data.'));
            redirect('invoices.php');
        }

        $stmt = db()->prepare('SELECT total_amount, paid_amount, status FROM invoices WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        if (!$invoice) {
            set_flash('error', __('Invoice not found.'));
            redirect('invoices.php');
        }
        if (in_array((string) $invoice['status'], ['paid', 'void'], true)) {
            set_flash('error', __('Cannot apply payment to this invoice status.'));
            redirect('invoices.php');
        }

        $newPaid = (float) $invoice['paid_amount'] + $amount;
        $total = (float) $invoice['total_amount'];
        if ($newPaid > $total) {
            $newPaid = $total;
        }

        $status = 'issued';
        if ($newPaid >= $total) {
            $status = 'paid';
        } elseif ($newPaid > 0) {
            $status = 'partial_paid';
        }

        if (!can_transition_status('invoice', (string) $invoice['status'], $status) && (string) $invoice['status'] !== $status) {
            set_flash('error', __('Invoice status transition not allowed.'));
            redirect('invoices.php');
        }

        $stmt = db()->prepare('UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('dsii', $newPaid, $status, $id, $companyId);
        $stmt->execute();
        set_flash('success', __('Payment applied.'));
        redirect('invoices.php');
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$filters = ['i.company_id = ?'];
$types = 'i';
$params = [$companyId];

if ($statusFilter !== '') {
    $filters[] = 'i.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
if ($dateFrom !== '') {
    $filters[] = 'i.issue_date >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $filters[] = 'i.issue_date <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

$whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countSql = "SELECT COUNT(*) AS total FROM invoices i {$whereSql}";
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
    SELECT i.*, so.so_number, so.customer_name, u.name AS creator_name
    FROM invoices i
    INNER JOIN sales_orders so ON so.id = i.sales_order_id AND so.company_id = i.company_id
    LEFT JOIN users u ON u.id = i.created_by
    {$whereSql}
    ORDER BY i.id DESC
    LIMIT ? OFFSET ?
";
$stmt = db()->prepare($sql);
$typesWithPaging = $types . 'ii';
$paramsWithPaging = [...$params, $limit, $offset];
$stmt->bind_param($typesWithPaging, ...$paramsWithPaging);
$stmt->execute();
$rows = $stmt->get_result();

function invoice_page_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    return http_build_query($query);
}

$title = page_title(__('Invoices'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Invoices')) ?></h1>

<section class="card">
    <h2><?= e(__('Filter')) ?></h2>
    <form method="get" class="filter-form">
        <select name="status">
            <option value=""><?= e(__('All statuses')) ?></option>
            <?php foreach (['draft', 'issued', 'partial_paid', 'paid', 'void'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(__($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        <button type="submit"><?= e(__('Apply')) ?></button>
        <a class="link-btn" href="invoices.php"><?= e(__('Reset')) ?></a>
    </form>
</section>

<section class="card">
    <table>
        <thead>
            <tr>
                <th><?= e(__('Invoice No')) ?></th>
                <th><?= e(__('SO No')) ?></th>
                <th><?= e(__('Customer')) ?></th>
                <th><?= e(__('Total')) ?></th>
                <th><?= e(__('Paid')) ?></th>
                <th><?= e(__('Status')) ?></th>
                <th><?= e(__('Issue Date')) ?></th>
                <th><?= e(__('Due Date')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows->num_rows === 0): ?>
                <tr>
                    <td colspan="9"><?= e(__('No invoices yet.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($row = $rows->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['invoice_number']) ?></td>
                        <td><a
                                href="sales_order_view.php?id=<?= e((string) $row['sales_order_id']) ?>"><?= e($row['so_number']) ?></a>
                        </td>
                        <td><?= e($row['customer_name']) ?></td>
                        <td><?= e(format_money((float) $row['total_amount'])) ?></td>
                        <td><?= e(format_money((float) $row['paid_amount'])) ?></td>
                        <td><?= e(__($row['status'])) ?></td>
                        <td><?= e($row['issue_date']) ?></td>
                        <td><?= e($row['due_date']) ?></td>
                        <td>
                            <?php if (!in_array((string) $row['status'], ['paid', 'void'], true)): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="payment">
                                    <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                    <input type="number" min="0.01" step="0.01" name="amount"
                                        placeholder="<?= e(__('Payment amount')) ?>" required>
                                    <button type="submit"><?= e(__('Apply Payment')) ?></button>
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
            <a href="invoices.php?<?= e(invoice_page_query(['page' => $page - 1])) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="invoices.php?<?= e(invoice_page_query(['page' => $page + 1])) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
