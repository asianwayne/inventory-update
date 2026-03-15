<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_auth();
$companyId = require_company_id();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    set_flash('error', __('Invalid product.'));
    redirect('products.php');
}

$stmt = db()->prepare('
    SELECT p.*, c.name AS category_name, s.supplier_name AS linked_supplier_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id AND c.company_id = p.company_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id AND s.company_id = p.company_id
    WHERE p.id = ? AND p.company_id = ? LIMIT 1
');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    set_flash('error', __('Product not found.'));
    redirect('products.php');
}

// Get recent stock movements
$stmt = db()->prepare('SELECT * FROM stock_movements WHERE product_id = ? AND company_id = ? ORDER BY id DESC LIMIT 10');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$movements = $stmt->get_result();

$title = page_title(__('Product Detail'));
require_once __DIR__ . '/includes/header.php';
?>

<div class="view-header">
    <h1><?= e($product['product_name']) ?></h1>
    <div class="header-actions">
        <?php if (has_permission('manage_products')): ?>
            <a href="product_form.php?id=<?= e((string) $id) ?>" class="link-btn">
                <i class="ph-bold ph-pencil"></i> <?= e(__('Edit')) ?>
            </a>
        <?php endif; ?>
        <a href="products.php" class="link-btn secondary-btn">
            <?= e(__('Back to List')) ?>
        </a>
    </div>
</div>

<div class="view-grid">
    <section class="card product-main-info">
        <div class="product-visual">
            <?php if (!empty($product['image_path'])): ?>
                <img src="<?= e(UPLOAD_URL . '/' . $product['image_path']) ?>" alt="Product image" class="view-image">
            <?php else: ?>
                <div class="no-view-image"><i class="ph-bold ph-package"></i></div>
            <?php endif; ?>
        </div>
        <div class="product-details">
            <div class="detail-row">
                <span class="label"><?= e(__('SKU')) ?>:</span>
                <span class="value"><?= e($product['sku']) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('OEM Number')) ?>:</span>
                <span class="value"><?= e($product['oem_number'] ?: '-') ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Category')) ?>:</span>
                <span class="value"><?= e($product['category_name'] ?: __('Uncategorized')) ?></span>
            </div>
            <div class="detail-row">
                <span class="label"><?= e(__('Supplier')) ?>:</span>
                <span class="value"><?= e($product['linked_supplier_name'] ?: $product['supplier'] ?: '-') ?></span>
            </div>
        </div>
    </section>

    <section class="card product-stats-info">
        <h3><?= e(__('Inventory & Pricing')) ?></h3>
        <div class="stats-grid">
            <div class="stat-box">
                <span class="label"><?= e(__('Current Stock')) ?></span>
                <span class="value big"><?= e((string) $product['quantity']) ?></span>
            </div>
            <div class="stat-box">
                <span class="label"><?= e(__('Purchase Price')) ?></span>
                <span class="value"><?= e(format_money((float) $product['purchase_price'])) ?></span>
            </div>
            <div class="stat-box">
                <span class="label"><?= e(__('Sale Price')) ?></span>
                <span class="value"><?= e(format_money((float) $product['sale_price'])) ?></span>
            </div>
        </div>
    </section>

    <?php if ($product['description'] || $product['application']): ?>
    <section class="card">
        <h3><?= e(__('Description & Application')) ?></h3>
        <?php if ($product['description']): ?>
            <div class="text-block">
                <strong><?= e(__('Description')) ?>:</strong>
                <p><?= nl2br(e((string) $product['description'])) ?></p>
            </div>
        <?php endif; ?>
        <?php if ($product['application']): ?>
            <div class="text-block mt-3">
                <strong><?= e(__('Application Areas')) ?>:</strong>
                <p><?= nl2br(e((string) $product['application'])) ?></p>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card full-width">
        <h3><?= e(__('Recent Stock Movements')) ?></h3>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?= e(__('Date')) ?></th>
                        <th><?= e(__('Type')) ?></th>
                        <th><?= e(__('Change')) ?></th>
                        <th><?= e(__('Balance')) ?></th>
                        <th><?= e(__('Note')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($m = $movements->fetch_assoc()): ?>
                        <tr>
                            <td><?= e($m['created_at']) ?></td>
                            <td><?= e(__($m['movement_type'])) ?></td>
                            <td class="<?= $m['quantity_change'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $m['quantity_change'] > 0 ? '+' : '' ?><?= e((string) $m['quantity_change']) ?>
                            </td>
                            <td><?= e((string) $m['new_quantity']) ?></td>
                            <td><?= e((string) $m['note']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if ($movements->num_rows === 0): ?>
                        <tr><td colspan="5"><?= e(__('No movements yet.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<style>
.view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}
.header-actions {
    display: flex;
    gap: 10px;
}
.view-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.product-main-info {
    display: flex;
    flex-direction: row !important;
    gap: 25px;
}
.product-visual {
    width: 200px;
    height: 200px;
    background: #f1f5f9;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}
.view-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.no-view-image i {
    font-size: 4rem;
    color: var(--text-muted);
}
.product-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 15px;
}
.detail-row {
    display: flex;
    gap: 10px;
}
.detail-row .label {
    font-weight: 600;
    color: var(--text-muted);
    min-width: 100px;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}
.stat-box {
    background: #f8fafc;
    padding: 15px;
    border-radius: 10px;
    text-align: center;
}
.stat-box .label {
    display: block;
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 5px;
}
.stat-box .value {
    font-weight: 700;
    font-size: 1.1rem;
}
.stat-box .value.big {
    font-size: 1.5rem;
    color: var(--primary);
}
.full-width {
    grid-column: span 2;
}
.text-block p {
    margin-top: 5px;
    color: var(--text-secondary);
}
.mt-3 { margin-top: 1rem; }

@media (max-width: 900px) {
    .view-grid { grid-template-columns: 1fr; }
    .full-width { grid-column: span 1; }
    .product-main-info { flex-direction: column !important; }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
