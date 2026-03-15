<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('receive_purchase');
$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('purchase_receive.php');
    }

    $productId = (int) ($_POST['product_id'] ?? 0);
    $receivedQty = (int) ($_POST['received_qty'] ?? 0);
    $unitCost = (float) ($_POST['unit_cost'] ?? 0);
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $referenceNo = trim((string) ($_POST['reference_no'] ?? ''));
    $remark = trim((string) ($_POST['remark'] ?? ''));

    if ($productId <= 0 || $receivedQty <= 0 || $unitCost < 0) {
        set_flash('error', __('Product, quantity (>0), and valid unit cost are required.'));
        redirect('purchase_receive.php');
    }

    $supplierName = '';
    if ($supplierId > 0) {
        $stmt = db()->prepare('SELECT supplier_name FROM suppliers WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $supplierId, $companyId);
        $stmt->execute();
        $supplierRow = $stmt->get_result()->fetch_assoc();
        if (!$supplierRow) {
            $supplierId = 0;
            $supplierName = '';
        } else {
            $supplierName = (string) $supplierRow['supplier_name'];
        }
    }

    $conn = db();
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('SELECT id, product_name, sku, quantity, purchase_price FROM products WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $productId, $companyId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        if (!$product) {
            throw new RuntimeException(__('Product not found.'));
        }

        $oldQty = (int) $product['quantity'];
        $newQty = $oldQty + $receivedQty;
        $oldCost = (float) $product['purchase_price'];
        $newCost = $newQty > 0 ? (($oldQty * $oldCost) + ($receivedQty * $unitCost)) / $newQty : $unitCost;

        $stmt = $conn->prepare(
            'INSERT INTO purchase_receipts (company_id, product_id, received_qty, unit_cost, supplier_id, supplier, reference_no, remark, created_by)
             VALUES (?, ?, ?, ?, NULLIF(?,0), ?, ?, ?, ?)'
        );
        $createdBy = current_user_id();
        $stmt->bind_param('iiidisssi', $companyId, $productId, $receivedQty, $unitCost, $supplierId, $supplierName, $referenceNo, $remark, $createdBy);
        $stmt->execute();
        $receiptId = (int) $conn->insert_id;

        $stmt = $conn->prepare('UPDATE products SET quantity = ?, purchase_price = ?, supplier_id = NULLIF(?,0), supplier = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('idisii', $newQty, $newCost, $supplierId, $supplierName, $productId, $companyId);
        $stmt->execute();

        log_stock_movement(
            $productId,
            (string) $product['product_name'],
            (string) $product['sku'],
            'purchase_in',
            $receivedQty,
            $oldQty,
            $newQty,
            $unitCost,
            'purchase_receipt',
            $receiptId,
            $referenceNo !== '' ? "PO/Ref: {$referenceNo}" : 'Purchase receipt'
        );

        $conn->commit();
        set_flash('success', __('Purchase received and stock updated.'));
    } catch (Throwable $e) {
        $conn->rollback();
        set_flash('error', $e->getMessage());
    }

    redirect('purchase_receive.php');
}

$stmt = db()->prepare('SELECT id, product_name, sku, quantity FROM products WHERE company_id = ? ORDER BY product_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$products = $stmt->get_result();

$stmt = db()->prepare('SELECT id, supplier_name, supplier_code FROM suppliers WHERE company_id = ? ORDER BY supplier_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$suppliers = $stmt->get_result();

$stmt = db()->prepare(
    'SELECT pr.id, p.product_name, p.sku, pr.received_qty, pr.unit_cost, pr.supplier, pr.reference_no, pr.created_at, u.name AS user_name, s.supplier_name
     FROM purchase_receipts pr
     INNER JOIN products p ON p.id = pr.product_id
     LEFT JOIN suppliers s ON s.id = pr.supplier_id AND s.company_id = pr.company_id
     LEFT JOIN users u ON u.id = pr.created_by
     WHERE pr.company_id = ?
     ORDER BY pr.id DESC
     LIMIT 12'
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$recentReceipts = $stmt->get_result();

$title = page_title(__('Purchase Receive'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Purchase Receive')) ?></h1>

<section class="card">
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <label><?= e(__('Product')) ?>
            <select name="product_id" required>
                <option value=""><?= e(__('Select product')) ?></option>
                <?php while ($product = $products->fetch_assoc()): ?>
                    <option value="<?= e((string) $product['id']) ?>">
                        <?= e($product['product_name']) ?> (<?= e($product['sku']) ?>) -
                        <?= e(__('Current Qty: ')) ?>    <?= e((string) $product['quantity']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label><?= e(__('Received Quantity')) ?>
            <input type="number" name="received_qty" min="1" required>
        </label>
        <label><?= e(__('Unit Cost')) ?>
            <input type="number" name="unit_cost" min="0" step="0.01" required>
        </label>
        <label><?= e(__('Supplier')) ?>
            <select name="supplier_id">
                <option value="0"><?= e(__('Select supplier')) ?></option>
                <?php while ($supplier = $suppliers->fetch_assoc()): ?>
                    <option value="<?= e((string) $supplier['id']) ?>">
                        <?= e($supplier['supplier_name']) ?> (<?= e($supplier['supplier_code']) ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label><?= e(__('Reference No')) ?>
            <input type="text" name="reference_no" maxlength="120" placeholder="PO-0001">
        </label>
        <label><?= e(__('Remark')) ?>
            <textarea name="remark" rows="3"></textarea>
        </label>
        <button type="submit"><?= e(__('Receive Stock')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Recent Receipts')) ?></h2>
    <?php if ($recentReceipts->num_rows === 0): ?>
        <p><?= e(__('No purchase receipts yet.')) ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
            <thead>
                <tr>
                    <th><?= e(__('ID')) ?></th>
                    <th><?= e(__('Product')) ?></th>
                    <th><?= e(__('SKU')) ?></th>
                    <th><?= e(__('Qty')) ?></th>
                    <th><?= e(__('Unit Cost')) ?></th>
                    <th><?= e(__('Supplier')) ?></th>
                    <th><?= e(__('Reference')) ?></th>
                    <th><?= e(__('User')) ?></th>
                    <th><?= e(__('Date')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $recentReceipts->fetch_assoc()): ?>
                    <tr>
                        <td><?= e((string) $row['id']) ?></td>
                        <td><?= e($row['product_name']) ?></td>
                        <td><?= e($row['sku']) ?></td>
                        <td><?= e((string) $row['received_qty']) ?></td>
                        <td><?= e(format_money((float) $row['unit_cost'])) ?></td>
                        <td><?= e($row['supplier_name'] ?? $row['supplier']) ?></td>
                        <td><?= e($row['reference_no']) ?></td>
                        <td><?= e(__($row['user_name'] ?? 'System')) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
