<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('approve_orders');
$companyId = require_company_id();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', __('Invalid purchase order.'));
    redirect('purchase_orders_pending.php');
}

$conn = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('purchase_order_approve.php?id=' . $id);
    }

    $decision = (string) ($_POST['decision'] ?? '');
    if (!in_array($decision, ['approve', 'reject'], true)) {
        set_flash('error', __('Invalid approval action.'));
        redirect('purchase_order_approve.php?id=' . $id);
    }

    $stmt = $conn->prepare('SELECT status FROM purchase_orders WHERE id = ? AND company_id = ?');
    $stmt->bind_param('ii', $id, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        set_flash('error', __('Purchase order not found.'));
        redirect('purchase_orders_pending.php');
    }

    $currentStatus = (string) $row['status'];
    if ($currentStatus !== 'draft') {
        set_flash('error', __('Only draft purchase orders can be approved or rejected.'));
        redirect('purchase_order_approve.php?id=' . $id);
    }

    $toStatus = $decision === 'approve' ? 'approved' : 'cancelled';
    if (!can_transition_status('purchase_order', $currentStatus, $toStatus)) {
        set_flash('error', __('Invalid status transition.'));
        redirect('purchase_order_approve.php?id=' . $id);
    }

    $stmt = $conn->prepare('UPDATE purchase_orders SET status = ? WHERE id = ? AND company_id = ?');
    $stmt->bind_param('sii', $toStatus, $id, $companyId);
    $stmt->execute();

    if ($decision === 'approve') {
        set_flash('success', __('Purchase order approved. Continue with receiving workflow.'));
        redirect('purchase_order_view.php?id=' . $id);
    } else {
        set_flash('success', __('Purchase order rejected.'));
        redirect('purchase_orders_pending.php');
    }
}

$stmt = $conn->prepare(
    'SELECT po.*, s.supplier_name, s.supplier_code, s.contact_phone, s.contact_email, u.name AS creator_name
     FROM purchase_orders po
     INNER JOIN suppliers s ON s.id = po.supplier_id AND s.company_id = po.company_id
     LEFT JOIN users u ON u.id = po.created_by
     WHERE po.id = ? AND po.company_id = ?'
);
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
if (!$po) {
    set_flash('error', __('Purchase order not found.'));
    redirect('purchase_orders_pending.php');
}

$stmt = $conn->prepare(
    'SELECT poi.qty, poi.received_qty, poi.unit_cost, poi.line_total, p.product_name, p.sku
     FROM purchase_order_items poi
     INNER JOIN products p ON p.id = poi.product_id AND p.company_id = poi.company_id
     WHERE poi.purchase_order_id = ? AND poi.company_id = ?
     ORDER BY poi.id ASC'
);
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$items = $stmt->get_result();

$title = page_title(__('Purchase Order Approval'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Purchase Order Approval')) ?></h1>

<section class="card">
    <p><strong><?= e(__('PO No')) ?>:</strong> <?= e($po['po_number']) ?></p>
    <p><strong><?= e(__('Status')) ?>:</strong> <?= e(__($po['status'])) ?></p>
    <p><strong><?= e(__('Supplier')) ?>:</strong> <?= e($po['supplier_name']) ?> (<?= e($po['supplier_code']) ?>)</p>
    <p><strong><?= e(__('Supplier Contact')) ?>:</strong> <?= e($po['contact_phone'] ?: '-') ?> / <?= e($po['contact_email'] ?: '-') ?>
    </p>
    <p><strong><?= e(__('Order Date')) ?>:</strong> <?= e($po['order_date']) ?></p>
    <p><strong><?= e(__('Expected Date')) ?>:</strong> <?= e($po['expected_date'] ?: '-') ?></p>
    <p><strong><?= e(__('Created By')) ?>:</strong> <?= e($po['creator_name'] ?? '-') ?></p>
    <p><strong><?= e(__('Remark')) ?>:</strong> <?= e($po['remark'] ?: '-') ?></p>
    <p><strong><?= e(__('Total')) ?>:</strong> <?= e(format_money((float) $po['total_amount'])) ?></p>
</section>

<section class="card">
    <h2><?= e(__('Order Items')) ?></h2>
    <table>
        <thead>
            <tr>
                <th><?= e(__('Product')) ?></th>
                <th><?= e(__('SKU')) ?></th>
                <th><?= e(__('Qty')) ?></th>
                <th><?= e(__('Received')) ?></th>
                <th><?= e(__('Unit Cost')) ?></th>
                <th><?= e(__('Line Total')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items->num_rows === 0): ?>
                <tr><td colspan="6"><?= e(__('No items yet.')) ?></td></tr>
            <?php else: ?>
                <?php while ($item = $items->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= e($item['sku']) ?></td>
                        <td><?= e((string) $item['qty']) ?></td>
                        <td><?= e((string) $item['received_qty']) ?></td>
                        <td><?= e(format_money((float) $item['unit_cost'])) ?></td>
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
        <a class="link-btn" href="purchase_orders_pending.php"><?= e(__('Back to Pending')) ?></a>
        <?php if ((string) $po['status'] === 'draft'): ?>
            <form method="post" class="inline-form">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $po['id']) ?>">
                <input type="hidden" name="decision" value="approve">
                <button type="submit"><?= e(__('Approve')) ?></button>
            </form>
            <form method="post" class="inline-form">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= e((string) $po['id']) ?>">
                <input type="hidden" name="decision" value="reject">
                <button type="submit" class="danger"><?= e(__('Reject')) ?></button>
            </form>
        <?php else: ?>
            <a class="link-btn" href="purchase_order_view.php?id=<?= e((string) $po['id']) ?>"><?= e(__('Open Receiving Workflow')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
