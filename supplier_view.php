<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_auth();
$companyId = require_company_id();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    set_flash('error', __('Invalid supplier.'));
    redirect('suppliers.php');
}

$stmt = db()->prepare('SELECT * FROM suppliers WHERE id = ? AND company_id = ? LIMIT 1');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$supplier = $stmt->get_result()->fetch_assoc();

if (!$supplier) {
    set_flash('error', __('Supplier not found.'));
    redirect('suppliers.php');
}

$title = page_title(__('Supplier Detail'));
require_once __DIR__ . '/includes/header.php';
?>

<div class="view-header">
    <h1><?= e($supplier['supplier_name']) ?></h1>
    <div class="header-actions">
        <?php if (has_permission('manage_suppliers')): ?>
            <a href="suppliers.php?id=<?= e((string) $id) ?>" class="link-btn">
                <i class="ph-bold ph-pencil"></i> <?= e(__('Edit')) ?>
            </a>
        <?php endif; ?>
        <a href="suppliers.php" class="link-btn secondary-btn">
            <?= e(__('Back to List')) ?>
        </a>
    </div>
</div>

<div class="view-grid">
    <section class="card">
        <h3><?= e(__('Contact Information')) ?></h3>
        <div class="detail-list">
            <div class="detail-row">
                <span class="label"><?= e(__('Supplier Code')) ?>:</span>
                <span class="value"><?= e($supplier['supplier_code']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Phone')) ?>:</span>
                <span class="value"><?= e($supplier['contact_phone'] ?: '-') ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Email')) ?>:</span>
                <span class="value"><?= e($supplier['contact_email'] ?: '-') ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Address')) ?>:</span>
                <span class="value"><?= e($supplier['address'] ?: '-') ?></span>
            </div>
        </div>
    </section>

    <section class="card">
        <h3><?= e(__('Other Details')) ?></h3>
        <div class="text-block">
            <strong><?= e(__('Description')) ?>:</strong>
            <p><?= nl2br(e((string) $supplier['description'])) ?: '-' ?></p>
        </div>
        <div class="text-block mt-3">
            <strong><?= e(__('Remark')) ?>:</strong>
            <p><?= nl2br(e((string) $supplier['remark'])) ?: '-' ?></p>
        </div>
    </section>
</div>

<style>
.view-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
.header-actions { display: flex; gap: 10px; }
.view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.detail-list { display: flex; flex-direction: column; gap: 15px; }
.detail-row { display: flex; gap: 10px; }
.detail-row .label { font-weight: 600; color: var(--text-muted); min-width: 120px; }
.text-block p { margin-top: 5px; color: var(--text-secondary); }
.mt-3 { margin-top: 1rem; }
@media (max-width: 768px) { .view-grid { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
