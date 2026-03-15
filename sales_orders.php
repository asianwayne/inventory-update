<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_sales');
$companyId = require_company_id();

function table_exists(string $tableName): bool
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

function column_exists(string $tableName, string $columnName): bool
{
    try {
        $safeTable = db()->real_escape_string($tableName);
        $safeCol = db()->real_escape_string($columnName);
        $result = db()->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
        if ($result === false) {
            return false;
        }
        return $result->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$hasCustomerSchema = table_exists('customers') && column_exists('sales_orders', 'customer_id');

if (!$hasCustomerSchema) {
    $title = page_title(__('Sales Orders'));
    require_once __DIR__ . '/includes/header.php';
    ?>
    <h1><?= e(__('Sales Orders')) ?></h1>
    <section class="card">
        <h2><?= e(__('Database Migration Required')) ?></h2>
        <p><?= e(__('This module requires the latest schema updates.')) ?></p>
        <p><?= e(__('Run migration SQL to add:')) ?></p>
        <p><code>customers</code> table and <code>sales_orders.customer_id</code> column with foreign key.</p>
    </section>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('sales_orders.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $orderDate = (string) ($_POST['order_date'] ?? date('Y-m-d'));
        $remark = trim((string) ($_POST['remark'] ?? ''));

        if ($customerId <= 0 || $orderDate === '') {
            set_flash('error', __('Customer and order date are required.'));
            redirect('sales_orders.php');
        }

        $stmt = db()->prepare('SELECT customer_name, contact_phone, contact_email FROM customers WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $customerId, $companyId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        if (!$customer) {
            set_flash('error', __('Selected customer not found.'));
            redirect('sales_orders.php');
        }

        $customerName = (string) $customer['customer_name'];
        $customerPhone = (string) ($customer['contact_phone'] ?? '');
        $customerEmail = (string) ($customer['contact_email'] ?? '');

        $soNumber = generate_doc_number('SO', 'sales_orders');
        $createdBy = current_user_id();
        $stmt = db()->prepare(
            'INSERT INTO sales_orders (company_id, so_number, customer_id, customer_name, customer_phone, customer_email, order_date, remark, created_by)
             VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisssssi', $companyId, $soNumber, $customerId, $customerName, $customerPhone, $customerEmail, $orderDate, $remark, $createdBy);
        $stmt->execute();
        set_flash('success', __('Sales order created.'));
        redirect('sales_order_view.php?id=' . (int) db()->insert_id);
    }

    if ($action === 'status') {
        if (!has_permission('approve_orders')) {
            set_flash('error', __('You do not have permission to approve orders.'));
            redirect('sales_orders.php');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $toStatus = (string) ($_POST['status'] ?? '');
        if ($id <= 0 || $toStatus === '') {
            set_flash('error', __('Invalid status update.'));
            redirect('sales_orders.php');
        }

        $stmt = db()->prepare('SELECT status FROM sales_orders WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) {
            set_flash('error', __('Sales order not found.'));
            redirect('sales_orders.php');
        }

        $fromStatus = (string) $order['status'];
        if (!can_transition_status('sales_order', $fromStatus, $toStatus)) {
            set_flash('error', __('Invalid status transition'));
            redirect('sales_orders.php');
        }

        if ($toStatus === 'shipped') {
            try {
                ship_sales_order($id);
                set_flash('success', __('Sales order shipped and stock deducted.'));
            } catch (Throwable $e) {
                set_flash('error', $e->getMessage());
            }
            redirect('sales_orders.php');
        }

        $stmt = db()->prepare('UPDATE sales_orders SET status = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('sii', $toStatus, $id, $companyId);
        $stmt->execute();
        set_flash('success', __('Sales order status updated.'));
        redirect('sales_orders.php');
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$customerFilter = (int) ($_GET['customer_id'] ?? 0);
$dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')));
$dateTo = (string) ($_GET['date_to'] ?? date('Y-m-d'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$filters = ['so.company_id = ?'];
$types = 'i';
$params = [$companyId];

if ($statusFilter !== '') {
    $filters[] = 'so.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
if ($customerFilter > 0) {
    $filters[] = 'so.customer_id = ?';
    $types .= 'i';
    $params[] = $customerFilter;
}
if ($dateFrom !== '') {
    $filters[] = 'so.order_date >= ?';
    $types .= 's';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $filters[] = 'so.order_date <= ?';
    $types .= 's';
    $params[] = $dateTo;
}

$whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countSql = "SELECT COUNT(*) AS total FROM sales_orders so {$whereSql}";
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
    SELECT so.*, u.name AS creator_name, c.customer_name AS linked_customer_name
    FROM sales_orders so
    LEFT JOIN customers c ON c.id = so.customer_id AND c.company_id = so.company_id
    LEFT JOIN users u ON u.id = so.created_by
    {$whereSql}
    ORDER BY so.id DESC
    LIMIT ? OFFSET ?
";
$stmt = db()->prepare($sql);
$typesWithPaging = $types . 'ii';
$paramsWithPaging = [...$params, $limit, $offset];
$stmt->bind_param($typesWithPaging, ...$paramsWithPaging);
$stmt->execute();
$orders = $stmt->get_result();

$stmt = db()->prepare('SELECT id, customer_name, customer_code FROM customers WHERE company_id = ? ORDER BY customer_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$customers = $stmt->get_result();

$stmt = db()->prepare('SELECT id, customer_name, customer_code FROM customers WHERE company_id = ? ORDER BY customer_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$customersForFilter = $stmt->get_result();

function so_page_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    return http_build_query($query);
}

$title = page_title(__('Sales Orders'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Sales Orders')) ?></h1>

<section class="card">
    <h2><?= e(__('Create Sales Order')) ?></h2>
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <label><?= e(__('Customer')) ?>
            <select name="customer_id" required>
                <option value=""><?= e(__('Select customer')) ?></option>
                <?php while ($customer = $customers->fetch_assoc()): ?>
                    <option value="<?= e((string) $customer['id']) ?>">
                        <?= e($customer['customer_name']) ?> (<?= e($customer['customer_code']) ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label><?= e(__('Order Date')) ?>
            <input type="date" name="order_date" value="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <label><?= e(__('Remark')) ?>
            <textarea name="remark" rows="2"></textarea>
        </label>
        <button type="submit"><?= e(__('Create SO')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Filter')) ?></h2>
    <form method="get" class="filter-form">
        <select name="status">
            <option value=""><?= e(__('All statuses')) ?></option>
            <?php foreach (['draft', 'confirmed', 'shipped', 'completed', 'cancelled'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(__($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="customer_id">
            <option value="0"><?= e(__('All customers')) ?></option>
            <?php while ($customer = $customersForFilter->fetch_assoc()): ?>
                <option value="<?= e((string) $customer['id']) ?>" <?= $customerFilter === (int) $customer['id'] ? 'selected' : '' ?>>
                    <?= e($customer['customer_name']) ?> (<?= e($customer['customer_code']) ?>)
                </option>
            <?php endwhile; ?>
        </select>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        <button type="submit"><?= e(__('Apply')) ?></button>
        <a class="link-btn" href="sales_orders.php"><?= e(__('Reset')) ?></a>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Sales Order List')) ?></h2>
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th><?= e(__('SO No')) ?></th>
                <th><?= e(__('Customer')) ?></th>
                <th><?= e(__('Order Date')) ?></th>
                <th><?= e(__('Total')) ?></th>
                <th><?= e(__('Status')) ?></th>
                <th><?= e(__('Created By')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($orders->num_rows === 0): ?>
                <tr>
                    <td colspan="7"><?= e(__('No sales orders yet.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($row = $orders->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['so_number']) ?></td>
                        <td><?= e($row['linked_customer_name'] ?? $row['customer_name']) ?></td>
                        <td><?= e($row['order_date']) ?></td>
                        <td><?= e(format_money((float) $row['total_amount'])) ?></td>
                        <td><?= e(__($row['status'])) ?></td>
                        <td><?= e($row['creator_name']) ?></td>
                        <td>
                            <?php if (has_permission('approve_orders') && (string) $row['status'] === 'draft'): ?>
                                <a href="sales_order_approve.php?id=<?= e((string) $row['id']) ?>" class="view-link"><?= e(__('View')) ?></a>
                            <?php else: ?>
                                <a href="sales_order_view.php?id=<?= e((string) $row['id']) ?>" class="view-link"><?= e(__('View')) ?></a>
                            <?php endif; ?>
                            <?php
                            $statusOptions = [
                                'draft' => ['confirmed', 'cancelled'],
                                'confirmed' => ['shipped', 'cancelled'],
                                'shipped' => ['completed', 'cancelled'],
                                'completed' => [],
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
    </div>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="sales_orders.php?<?= e(so_page_query(['page' => 1])) ?>"><?= e(__('First')) ?></a>
            <a href="sales_orders.php?<?= e(so_page_query(['page' => $page - 1])) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="sales_orders.php?<?= e(so_page_query(['page' => $page + 1])) ?>"><?= e(__('Next')) ?></a>
            <a href="sales_orders.php?<?= e(so_page_query(['page' => $totalPages])) ?>"><?= e(__('Last')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
