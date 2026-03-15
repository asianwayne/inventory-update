<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_purchases');
$companyId = require_company_id();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', __('Invalid goods receipt.'));
    redirect('goods_receipts.php');
}

$stmt = db()->prepare('
    SELECT gr.*, po.po_number, s.supplier_name, s.supplier_code, u.name AS creator_name
    FROM goods_receipts gr
    INNER JOIN purchase_orders po ON po.id = gr.purchase_order_id AND po.company_id = gr.company_id
    INNER JOIN suppliers s ON s.id = po.supplier_id AND s.company_id = po.company_id
    LEFT JOIN users u ON u.id = gr.created_by
    WHERE gr.id = ? AND gr.company_id = ?
');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$gr = $stmt->get_result()->fetch_assoc();

if (!$gr) {
    set_flash('error', __('Goods receipt not found.'));
    redirect('goods_receipts.php');
}

$stmt = db()->prepare('
    SELECT gri.*, p.product_name, p.sku
    FROM goods_receipt_items gri
    INNER JOIN products p ON p.id = gri.product_id AND p.company_id = gri.company_id
    WHERE gri.goods_receipt_id = ? AND gri.company_id = ?
');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$items = $stmt->get_result();

$title = page_title(__('Goods Receipt Detail'));
require_once __DIR__ . '/includes/header.php';
?>

<div class="view-header">
    <h1><?= e($gr['gr_number']) ?></h1>
    <div class="header-actions">
        <a href="goods_receipts.php" class="link-btn secondary-btn">
            <?= e(__('Back to List')) ?>
        </a>
    </div>
</div>

<div class="view-grid">
    <section class="card">
        <h3><?= e(__('Receipt Information')) ?></h3>
        <div class="detail-list">
            <div class="detail-row">
                <span class="label"><?= e(__('Status')) ?>:</span>
                <span class="value badge status-<?= e($gr['status']) ?>"><?= e(__($gr['status'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Date')) ?>:</span>
                <span class="value"><?= e($gr['created_at']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('PO Number')) ?>:</span>
                <span class="value"><a href="purchase_order_view.php?id=<?= e((string) $gr['purchase_order_id']) ?>"><?= e($gr['po_number']) ?></a></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Created By')) ?>:</span>
                <span class="value"><?= e($gr['creator_name']) ?></span>
            </div>
        </div>
    </section>

    <section class="card">
        <h3><?= e(__('Supplier Information')) ?></h3>
        <div class="detail-list">
            <div class="detail-row">
                <span class="label"><?= e(__('Supplier')) ?>:</span>
                <span class="value"><?= e($gr['supplier_name']) ?> (<?= e($gr['supplier_code']) ?>)</span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Remark')) ?>:</span>
                <span class="value"><?= e($gr['remark'] ?: '-') ?></span>
            </div>
        </div>
    </section>

    <section class="card full-width">
        <h3><?= e(__('Received Items')) ?></h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?= e(__('Product')) ?></th>
                        <th><?= e(__('SKU')) ?></th>
                        <th><?= e(__('Qty')) ?></th>
                        <th><?= e(__('Unit Cost')) ?></th>
                        <th><?= e(__('Line Total')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($item = $items->fetch_assoc()): ?>
                        <tr>
                            <td><?= e($item['product_name']) ?></td>
                            <td><?= e($item['sku']) ?></td>
                            <td><?= e((string) $item['qty']) ?></td>
                            <td><?= e(format_money((float) $item['unit_cost'])) ?></td>
                            <td><?= e(format_money((float) $item['line_total'])) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" style="text-align: right;"><?= e(__('Total')) ?>:</th>
                        <th><?= e((string) $gr['total_qty']) ?></th>
                        <th></th>
                        <th><?= e(format_money((float) $gr['total_amount'])) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
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
.status-posted { background: #dcfce7; color: #166534; }
.status-void { background: #fee2e2; color: #991b1b; }
@media (max-width: 900px) { .view-grid { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
