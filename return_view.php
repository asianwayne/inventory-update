<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_returns');
$companyId = require_company_id();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', __('Invalid return record.'));
    redirect('returns.php');
}

$stmt = db()->prepare('
    SELECT r.*, p.product_name, p.sku, u.name AS creator_name
    FROM inventory_returns r
    INNER JOIN products p ON p.id = r.product_id AND p.company_id = r.company_id
    LEFT JOIN users u ON u.id = r.created_by
    WHERE r.id = ? AND r.company_id = ?
');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$return = $stmt->get_result()->fetch_assoc();

if (!$return) {
    set_flash('error', __('Return not found.'));
    redirect('returns.php');
}

$title = page_title(__('Return Detail'));
require_once __DIR__ . '/includes/header.php';
?>

<div class="view-header">
    <h1><?= e($return['return_number']) ?></h1>
    <div class="header-actions">
        <a href="returns.php" class="link-btn secondary-btn">
            <?= e(__('Back to List')) ?>
        </a>
    </div>
</div>

<div class="view-grid">
    <section class="card">
        <h3><?= e(__('Return Information')) ?></h3>
        <div class="detail-list">
            <div class="detail-row">
                <span class="label"><?= e(__('Status')) ?>:</span>
                <span class="value badge status-<?= e($return['status']) ?>"><?= e(__($return['status'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Return Type')) ?>:</span>
                <span class="value"><?= e(__($return['return_type'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Date')) ?>:</span>
                <span class="value"><?= e($return['created_at']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Reference')) ?>:</span>
                <span class="value"><?= $return['reference_type'] ? e($return['reference_type'] . ' #' . $return['reference_id']) : '-' ?></span>
            </div>
        </div>
    </section>

    <section class="card">
        <h3><?= e(__('Product Details')) ?></h3>
        <div class="detail-list">
            <div class="detail-row">
                <span class="label"><?= e(__('Product')) ?>:</span>
                <span class="value"><?= e($return['product_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('SKU')) ?>:</span>
                <span class="value"><?= e($return['sku']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Quantity')) ?>:</span>
                <span class="value"><?= e((string) $return['qty']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Unit Amount')) ?>:</span>
                <span class="value"><?= e(format_money((float) $return['unit_amount'])) ?></span>
            </div>
        </div>
    </section>

    <section class="card full-width">
        <h3><?= e(__('Note')) ?></h3>
        <p><?= nl2br(e((string) $return['note'])) ?: e(__('No note provided.')) ?></p>
    </section>

    <?php
    $statusOptions = [
        'requested' => ['approved', 'rejected'],
        'approved' => ['completed', 'rejected'],
        'rejected' => [],
        'completed' => [],
    ];
    $next = $statusOptions[$return['status']] ?? [];
    ?>

    <?php if (!empty($next)): ?>
    <section class="card full-width">
        <h3><?= e(__('Update Status')) ?></h3>
        <form method="post" action="returns.php" class="inline-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <select name="status" required>
                <?php foreach ($next as $opt): ?>
                    <option value="<?= e($opt) ?>"><?= e(__($opt)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?= e(__('Update Return Status')) ?></button>
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
.status-requested { background: #dbeafe; color: #1e40af; }
.status-approved { background: #fef9c3; color: #854d0e; }
.status-completed { background: #dcfce7; color: #166534; }
.status-rejected { background: #fee2e2; color: #991b1b; }
@media (max-width: 900px) { .view-grid { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
