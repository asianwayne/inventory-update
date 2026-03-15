<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_sales');
$companyId = require_company_id();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', __('Invalid sales order.'));
    redirect('sales_orders.php');
}

$conn = db();

$stmt = $conn->prepare('SELECT status FROM sales_orders WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$statusRow = $stmt->get_result()->fetch_assoc();
if (!$statusRow) {
    set_flash('error', __('Sales order not found.'));
    redirect('sales_orders.php');
}
if (has_permission('approve_orders') && (string) $statusRow['status'] === 'draft') {
    redirect('sales_order_approve.php?id=' . $id);
}

function refresh_so_totals(mysqli $conn, int $soId, int $companyId): void
{
    $stmt = $conn->prepare('SELECT COALESCE(SUM(line_total),0) AS total FROM sales_order_items WHERE sales_order_id = ? AND company_id = ?');
    $stmt->bind_param('ii', $soId, $companyId);
    $stmt->execute();
    $total = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    $stmt = $conn->prepare('UPDATE sales_orders SET total_amount = ? WHERE id = ? AND company_id = ?');
    $stmt->bind_param('dii', $total, $soId, $companyId);
    $stmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect("sales_order_view.php?id={$id}");
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_item') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        $unitPrice = (float) ($_POST['unit_price'] ?? 0);

        if ($productId <= 0 || $qty <= 0 || $unitPrice < 0) {
            set_flash('error', __('Invalid item values.'));
            redirect("sales_order_view.php?id={$id}");
        }
        $stmt = $conn->prepare('SELECT id FROM products WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $productId, $companyId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            set_flash('error', __('Product not found.'));
            redirect("sales_order_view.php?id={$id}");
        }

        $lineTotal = $qty * $unitPrice;
        $stmt = $conn->prepare(
            'INSERT INTO sales_order_items (company_id, sales_order_id, product_id, qty, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiiidd', $companyId, $id, $productId, $qty, $unitPrice, $lineTotal);
        $stmt->execute();
        refresh_so_totals($conn, $id, $companyId);
        set_flash('success', __('Sales order item added.'));
        redirect("sales_order_view.php?id={$id}");
    }

    if ($action === 'ship') {
        if (!has_permission('manage_sales')) {
            set_flash('error', __('You do not have permission to ship orders.'));
            redirect("sales_order_view.php?id={$id}");
        }
        try {
            ship_sales_order($id);
            set_flash('success', __('Sales order shipped and stock deducted.'));
        } catch (Throwable $e) {
            set_flash('error', $e->getMessage());
        }

        redirect("sales_order_view.php?id={$id}");
    }

    if ($action === 'create_invoice') {
        if (!has_permission('manage_invoices')) {
            set_flash('error', __('You do not have permission to create invoices.'));
            redirect("sales_order_view.php?id={$id}");
        }
        $issueDate = (string) ($_POST['issue_date'] ?? date('Y-m-d'));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));

        $stmt = $conn->prepare('SELECT id, so_number, total_amount, status FROM sales_orders WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) {
            set_flash('error', __('Sales order not found.'));
            redirect("sales_order_view.php?id={$id}");
        }
        if (in_array((string) $order['status'], ['draft', 'cancelled'], true)) {
            set_flash('error', __('Only confirmed/shipped/completed order can be invoiced.'));
            redirect("sales_order_view.php?id={$id}");
        }

        $stmt = $conn->prepare('SELECT id FROM invoices WHERE sales_order_id = ? AND company_id = ?');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            set_flash('error', __('Invoice already exists for this sales order.'));
            redirect("sales_order_view.php?id={$id}");
        }

        $invoiceNumber = generate_doc_number('INV', 'invoices');
        $createdBy = current_user_id();
        $dueDateOrNull = $dueDate !== '' ? $dueDate : null;

        $stmt = $conn->prepare(
            'INSERT INTO invoices (company_id, invoice_number, sales_order_id, total_amount, issue_date, due_date, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isidssi', $companyId, $invoiceNumber, $id, $order['total_amount'], $issueDate, $dueDateOrNull, $createdBy);
        $stmt->execute();
        set_flash('success', __('Invoice created.'));
        redirect('invoices.php');
    }
}

$stmt = $conn->prepare(
    'SELECT so.*, c.customer_name AS linked_customer_name, c.customer_code
     FROM sales_orders so
     LEFT JOIN customers c ON c.id = so.customer_id AND c.company_id = so.company_id
     WHERE so.id = ? AND so.company_id = ?'
);
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    set_flash('error', __('Sales order not found.'));
    redirect('sales_orders.php');
}

$stmt = $conn->prepare('SELECT id, product_name, sku, sale_price, quantity FROM products WHERE company_id = ? ORDER BY product_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$products = $stmt->get_result();
$stmt = $conn->prepare(
    'SELECT soi.*, p.product_name, p.sku
     FROM sales_order_items soi
     INNER JOIN products p ON p.id = soi.product_id AND p.company_id = soi.company_id
     WHERE soi.sales_order_id = ? AND soi.company_id = ?
     ORDER BY soi.id ASC'
);
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$items = $stmt->get_result();

$title = page_title(__('Sales Order Detail'));
require_once __DIR__ . '/includes/header.php';
?>
<div class="view-header">
    <h1><?= e(__('Sales Order Detail')) ?></h1>
    <div class="header-actions">
        <a href="sales_orders.php" class="link-btn secondary-btn">
            <?= e(__('Back to List')) ?>
        </a>
    </div>
</div>
<section class="card">
    <p><strong><?= e(__('SO No')) ?>:</strong> <?= e($order['so_number']) ?></p>
    <p><strong><?= e(__('Status')) ?>:</strong> <?= e(__($order['status'])) ?></p>
    <p><strong><?= e(__('Customer')) ?>:</strong> <?= e($order['linked_customer_name'] ?? $order['customer_name']) ?>
        <?php if (!empty($order['customer_code'])): ?>
            (<?= e($order['customer_code']) ?>)
        <?php endif; ?>
    </p>
    <p><strong><?= e(__('Total')) ?>:</strong> <?= e(format_money((float) $order['total_amount'])) ?></p>
</section>

<?php if ((string) $order['status'] !== 'completed'): ?>
<section class="card">
    <h2><?= e(__('Add Item')) ?></h2>
    <form method="post" class="inline-form">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="add_item">
        <select name="product_id" required>
            <option value=""><?= e(__('Product')) ?></option>
            <?php while ($product = $products->fetch_assoc()): ?>
                <option value="<?= e((string) $product['id']) ?>">
                    <?= e($product['product_name']) ?> (<?= e($product['sku']) ?>) - <?= e(__('Stock')) ?> <?= e((string) $product['quantity']) ?> - <?= e(format_money((float) $product['sale_price'])) ?>
                </option>
            <?php endwhile; ?>
        </select>
        <input type="number" name="qty" min="1" placeholder="<?= e(__('Qty')) ?>" required>
        <input type="number" name="unit_price" min="0" step="0.01" placeholder="<?= e(__('Unit Price')) ?>" required>
        <button type="submit"><?= e(__('Add')) ?></button>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= e(__('Items')) ?></h2>
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th><?= e(__('Product')) ?></th>
                <th><?= e(__('SKU')) ?></th>
                <th><?= e(__('Qty')) ?></th>
                <th><?= e(__('Unit Price')) ?></th>
                <th><?= e(__('Line Total')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items->num_rows === 0): ?>
                <tr><td colspan="5"><?= e(__('No items yet.')) ?></td></tr>
            <?php else: ?>
                <?php while ($item = $items->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= e($item['sku']) ?></td>
                        <td><?= e((string) $item['qty']) ?></td>
                        <td><?= e(format_money((float) $item['unit_price'])) ?></td>
                        <td><?= e(format_money((float) $item['line_total'])) ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2><?= e(__('Workflow Actions')) ?></h2>
    <div class="inline-form">
        <?php if ((string) $order['status'] === 'confirmed' && (int) $order['stock_deducted'] === 0 && has_permission('manage_sales')): ?>
            <form method="post" class="inline-form">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="ship">
                <button type="submit"><?= e(__('Ship Order (Deduct Stock)')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (in_array((string) $order['status'], ['confirmed', 'shipped', 'completed'], true) && has_permission('manage_invoices')): ?>
            <form method="post" class="inline-form">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create_invoice">
                <input type="date" name="issue_date" value="<?= e(date('Y-m-d')) ?>" required>
                <input type="date" name="due_date" placeholder="<?= e(__('Due date')) ?>">
                <button type="submit"><?= e(__('Create Invoice')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
