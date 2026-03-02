<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('approve_orders');
$companyId = require_company_id();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', __('Invalid sales order.'));
    redirect('sales_orders_pending.php');
}

$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('sales_order_approve.php?id=' . $id);
    }

    $decision = (string) ($_POST['decision'] ?? '');
    if (!in_array($decision, ['approve', 'reject'], true)) {
        set_flash('error', __('Invalid approval action.'));
        redirect('sales_order_approve.php?id=' . $id);
    }

    $stmt = $conn->prepare('SELECT status FROM sales_orders WHERE id = ? AND company_id = ?');
    $stmt->bind_param('ii', $id, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        set_flash('error', __('Sales order not found.'));
        redirect('sales_orders_pending.php');
    }

    $currentStatus = (string) $row['status'];
    if ($currentStatus !== 'draft') {
        set_flash('error', __('Only draft sales orders can be approved or rejected.'));
        redirect('sales_order_approve.php?id=' . $id);
    }

    $toStatus = $decision === 'approve' ? 'confirmed' : 'cancelled';
    if (!can_transition_status('sales_order', $currentStatus, $toStatus)) {
        set_flash('error', __('Invalid status transition.'));
        redirect('sales_order_approve.php?id=' . $id);
    }

    $stmt = $conn->prepare('UPDATE sales_orders SET status = ? WHERE id = ? AND company_id = ?');
    $stmt->bind_param('sii', $toStatus, $id, $companyId);
    $stmt->execute();

    if ($decision === 'approve') {
        set_flash('success', __('Sales order approved. Continue with shipment workflow.'));
        redirect('sales_order_view.php?id=' . $id);
    } else {
        set_flash('success', __('Sales order rejected.'));
        redirect('sales_orders_pending.php');
    }
}

$stmt = $conn->prepare(
    'SELECT so.*, c.customer_name AS linked_customer_name, c.customer_code, u.name AS creator_name
     FROM sales_orders so
     LEFT JOIN customers c ON c.id = so.customer_id AND c.company_id = so.company_id
     LEFT JOIN users u ON u.id = so.created_by
     WHERE so.id = ? AND so.company_id = ?'
);
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) {
    set_flash('error', __('Sales order not found.'));
    redirect('sales_orders_pending.php');
}

$stmt = $conn->prepare(
    'SELECT soi.qty, soi.unit_price, soi.line_total, p.product_name, p.sku
     FROM sales_order_items soi
     INNER JOIN products p ON p.id = soi.product_id AND p.company_id = soi.company_id
     WHERE soi.sales_order_id = ? AND soi.company_id = ?
     ORDER BY soi.id ASC'
);
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$items = $stmt->get_result();

$title = page_title(__('Sales Order Approval'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Sales Order Approval')) ?></h1>

<section class="card">
    <p><strong><?= e(__('SO No')) ?>:</strong> <?= e($order['so_number']) ?></p>
    <p><strong><?= e(__('Status')) ?>:</strong> <?= e(__($order['status'])) ?></p>
    <p><strong><?= e(__('Customer')) ?>:</strong> <?= e($order['linked_customer_name'] ?? $order['customer_name']) ?>
        <?php if (!empty($order['customer_code'])): ?>
            (<?= e($order['customer_code']) ?>)
        <?php endif; ?>
    </p>
    <p><strong><?= e(__('Customer Contact')) ?>:</strong> <?= e($order['customer_phone'] ?: '-') ?> / <?= e($order['customer_email'] ?: '-') ?>
    </p>
    <p><strong><?= e(__('Order Date')) ?>:</strong> <?= e($order['order_date']) ?></p>
    <p><strong><?= e(__('Created By')) ?>:</strong> <?= e($order['creator_name'] ?? '-') ?></p>
    <p><strong><?= e(__('Remark')) ?>:</strong> <?= e($order['remark'] ?: '-') ?></p>
    <p><strong><?= e(__('Total')) ?>:</strong> <?= e(format_money((float) $order['total_amount'])) ?></p>
</section>

<section class="card">
    <h2><?= e(__('Order Items')) ?></h2>
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
</section>

<section class="card">
    <h2><?= e(__('Decision')) ?></h2>
    <div class="inline-form">
        <a class="link-btn" href="sales_orders_pending.php"><?= e(__('Back to Pending')) ?></a>
        <?php if ((string) $order['status'] === 'draft'): ?>
            <form method="post" class="inline-form">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $order['id']) ?>">
                <input type="hidden" name="decision" value="approve">
                <button type="submit"><?= e(__('Approve')) ?></button>
            </form>
            <form method="post" class="inline-form">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $order['id']) ?>">
                <input type="hidden" name="decision" value="reject">
                <button type="submit" class="danger"><?= e(__('Reject')) ?></button>
            </form>
        <?php else: ?>
            <a class="link-btn" href="sales_order_view.php?id=<?= e((string) $order['id']) ?>"><?= e(__('Open Shipment Workflow')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
