<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_invoices');
$companyId = require_company_id();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', __('Invalid invoice.'));
    redirect('invoices.php');
}

$stmt = db()->prepare('
    SELECT i.*, so.so_number, so.customer_name, so.customer_phone, so.customer_email, u.name AS creator_name
    FROM invoices i
    INNER JOIN sales_orders so ON so.id = i.sales_order_id AND so.company_id = i.company_id
    LEFT JOIN users u ON u.id = i.created_by
    WHERE i.id = ? AND i.company_id = ?
');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    set_flash('error', __('Invoice not found.'));
    redirect('invoices.php');
}

// Get invoice items (from sales order items since invoice is for the whole SO)
$stmt = db()->prepare('
    SELECT soi.*, p.product_name, p.sku
    FROM sales_order_items soi
    INNER JOIN products p ON p.id = soi.product_id AND p.company_id = soi.company_id
    WHERE soi.sales_order_id = ? AND soi.company_id = ?
');
$stmt->bind_param('ii', $invoice['sales_order_id'], $companyId);
$stmt->execute();
$items = $stmt->get_result();

$title = page_title(__('Invoice Detail'));
require_once __DIR__ . '/includes/header.php';
?>

<div class="view-header">
    <h1><?= e($invoice['invoice_number']) ?></h1>
    <div class="header-actions">
        <a href="invoices.php" class="link-btn secondary-btn">
            <?= e(__('Back to List')) ?>
        </a>
    </div>
</div>

<div class="view-grid">
    <section class="card">
        <h3><?= e(__('Invoice Information')) ?></h3>
        <div class="detail-list">
            <div class="detail-row">
                <span class="label"><?= e(__('Status')) ?>:</span>
                <span class="value badge status-<?= e($invoice['status']) ?>"><?= e(__($invoice['status'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Issue Date')) ?>:</span>
                <span class="value"><?= e($invoice['issue_date']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Due Date')) ?>:</span>
                <span class="value"><?= e($invoice['due_date'] ?: '-') ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('SO Number')) ?>:</span>
                <span class="value"><a href="sales_order_view.php?id=<?= e((string) $invoice['sales_order_id']) ?>"><?= e($invoice['so_number']) ?></a></span>
            </div>
        </div>
    </section>

    <section class="card">
        <h3><?= e(__('Customer Information')) ?></h3>
        <div class="detail-list">
            <div class="detail-row">
                <span class="label"><?= e(__('Customer')) ?>:</span>
                <span class="value"><?= e($invoice['customer_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Phone')) ?>:</span>
                <span class="value"><?= e($invoice['customer_phone'] ?: '-') ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Email')) ?>:</span>
                <span class="value"><?= e($invoice['customer_email'] ?: '-') ?></span>
            </div>
        </div>
    </section>

    <section class="card full-width">
        <h3><?= e(__('Items')) ?></h3>
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
                    <?php while ($item = $items->fetch_assoc()): ?>
                        <tr>
                            <td><?= e($item['product_name']) ?></td>
                            <td><?= e($item['sku']) ?></td>
                            <td><?= e((string) $item['qty']) ?></td>
                            <td><?= e(format_money((float) $item['unit_price'])) ?></td>
                            <td><?= e(format_money((float) $item['line_total'])) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="4" style="text-align: right;"><?= e(__('Total Amount')) ?>:</th>
                        <th><?= e(format_money((float) $invoice['total_amount'])) ?></th>
                    </tr>
                    <tr>
                        <th colspan="4" style="text-align: right;"><?= e(__('Paid Amount')) ?>:</th>
                        <th class="text-success"><?= e(format_money((float) $invoice['paid_amount'])) ?></th>
                    </tr>
                    <tr>
                        <th colspan="4" style="text-align: right;"><?= e(__('Balance')) ?>:</th>
                        <th class="text-danger"><?= e(format_money((float) $invoice['total_amount'] - (float) $invoice['paid_amount'])) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    <?php if (!in_array((string) $invoice['status'], ['paid', 'void'], true)): ?>
    <section class="card full-width">
        <h3><?= e(__('Apply Payment')) ?></h3>
        <form method="post" action="invoices.php" class="inline-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="payment">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <label><?= e(__('Payment Amount')) ?>
                <input type="number" min="0.01" step="0.01" name="amount" value="<?= e((string)($invoice['total_amount'] - $invoice['paid_amount'])) ?>" required>
            </label>
            <button type="submit"><?= e(__('Confirm Payment')) ?></button>
        </form>
    </section>
    <?php endif; ?>
</div>

<style>
.view-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
.header-actions { display: flex; gap: 10px; }
.view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.detail-list { display: flex; flex-direction: column; gap: 12px; }
.detail-row { display: flex; gap: 10px; }
.detail-row .label { font-weight: 600; color: var(--text-muted); min-width: 120px; }
.full-width { grid-column: span 2; }
.badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }
.status-paid { background: #dcfce7; color: #166534; }
.status-partial_paid { background: #fef9c3; color: #854d0e; }
.status-issued { background: #dbeafe; color: #1e40af; }
.status-void { background: #f1f5f9; color: #475569; }
@media (max-width: 900px) { .view-grid { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
