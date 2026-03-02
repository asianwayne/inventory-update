<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_purchases');
$companyId = require_company_id();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', __('Invalid purchase order.'));
    redirect('purchase_orders.php');
}

$conn = db();

$stmt = $conn->prepare('SELECT status FROM purchase_orders WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$statusRow = $stmt->get_result()->fetch_assoc();
if (!$statusRow) {
    set_flash('error', __('Purchase order not found.'));
    redirect('purchase_orders.php');
}
if (has_permission('approve_orders') && (string) $statusRow['status'] === 'draft') {
    redirect('purchase_order_approve.php?id=' . $id);
}

function refresh_po_totals(mysqli $conn, int $poId, int $companyId): void
{
    $stmt = $conn->prepare('SELECT COALESCE(SUM(line_total),0) AS total FROM purchase_order_items WHERE purchase_order_id = ? AND company_id = ?');
    $stmt->bind_param('ii', $poId, $companyId);
    $stmt->execute();
    $total = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    $stmt = $conn->prepare('UPDATE purchase_orders SET total_amount = ? WHERE id = ? AND company_id = ?');
    $stmt->bind_param('dii', $total, $poId, $companyId);
    $stmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect("purchase_order_view.php?id={$id}");
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_item') {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        $unitCost = (float) ($_POST['unit_cost'] ?? 0);

        if ($productId <= 0 || $qty <= 0 || $unitCost < 0) {
            set_flash('error', __('Invalid item values.'));
            redirect("purchase_order_view.php?id={$id}");
        }
        $stmt = $conn->prepare('SELECT id FROM products WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $productId, $companyId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            set_flash('error', __('Product not found.'));
            redirect("purchase_order_view.php?id={$id}");
        }

        $lineTotal = $qty * $unitCost;
        $stmt = $conn->prepare(
            'INSERT INTO purchase_order_items (company_id, purchase_order_id, product_id, qty, unit_cost, line_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiiidd', $companyId, $id, $productId, $qty, $unitCost, $lineTotal);
        $stmt->execute();
        refresh_po_totals($conn, $id, $companyId);
        set_flash('success', __('PO item added.'));
        redirect("purchase_order_view.php?id={$id}");
    }

    if ($action === 'receive_item') {
        if (!has_permission('receive_purchase')) {
            set_flash('error', __('You do not have permission to receive items.'));
            redirect("purchase_order_view.php?id={$id}");
        }
        $poItemId = (int) ($_POST['po_item_id'] ?? 0);
        $receiveQty = (int) ($_POST['receive_qty'] ?? 0);
        $remark = trim((string) ($_POST['remark'] ?? ''));
        if ($poItemId <= 0 || $receiveQty <= 0) {
            set_flash('error', __('Invalid receipt values.'));
            redirect("purchase_order_view.php?id={$id}");
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                'SELECT poi.*, po.po_number, po.status, p.product_name, p.sku, p.quantity AS current_qty, p.purchase_price
                 FROM purchase_order_items poi
                 INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
                 INNER JOIN products p ON p.id = poi.product_id
                 WHERE poi.id = ? AND poi.purchase_order_id = ? AND poi.company_id = ? AND po.company_id = ? AND p.company_id = ?
                 FOR UPDATE'
            );
            $stmt->bind_param('iiiii', $poItemId, $id, $companyId, $companyId, $companyId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                throw new RuntimeException(__('PO item not found.'));
            }

            $poStatus = (string) $row['status'];
            if (!in_array($poStatus, ['approved', 'partial_received', 'received'], true)) {
                throw new RuntimeException(__('PO must be approved before receiving.'));
            }

            $remaining = (int) $row['qty'] - (int) $row['received_qty'];
            if ($receiveQty > $remaining) {
                throw new RuntimeException(__('Receive qty exceeds remaining qty.'));
            }

            $newReceived = (int) $row['received_qty'] + $receiveQty;
            $stmt = $conn->prepare('UPDATE purchase_order_items SET received_qty = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('iii', $newReceived, $poItemId, $companyId);
            $stmt->execute();

            $grNumber = generate_doc_number('GR', 'goods_receipts');
            $createdBy = current_user_id();
            $lineTotal = $receiveQty * (float) $row['unit_cost'];

            $stmt = $conn->prepare(
                'INSERT INTO goods_receipts (company_id, gr_number, purchase_order_id, total_qty, total_amount, remark, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('isiidsi', $companyId, $grNumber, $id, $receiveQty, $lineTotal, $remark, $createdBy);
            $stmt->execute();
            $grId = (int) $conn->insert_id;

            $stmt = $conn->prepare(
                'INSERT INTO goods_receipt_items (company_id, goods_receipt_id, purchase_order_item_id, product_id, qty, unit_cost, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('iiiiidd', $companyId, $grId, $poItemId, $row['product_id'], $receiveQty, $row['unit_cost'], $lineTotal);
            $stmt->execute();

            $oldQty = (int) $row['current_qty'];
            $newQty = $oldQty + $receiveQty;
            $oldCost = (float) $row['purchase_price'];
            $newCost = $newQty > 0
                ? (($oldQty * $oldCost) + ($receiveQty * (float) $row['unit_cost'])) / $newQty
                : (float) $row['unit_cost'];

            $stmt = $conn->prepare('UPDATE products SET quantity = ?, purchase_price = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('idii', $newQty, $newCost, $row['product_id'], $companyId);
            $stmt->execute();

            log_stock_movement(
                (int) $row['product_id'],
                (string) $row['product_name'],
                (string) $row['sku'],
                'purchase_in',
                $receiveQty,
                $oldQty,
                $newQty,
                (float) $row['unit_cost'],
                'goods_receipt',
                $grId,
                $remark !== '' ? $remark : ('Goods receipt ' . $grNumber)
            );

            $stmt = $conn->prepare(
                'SELECT SUM(qty) AS ordered_qty, SUM(received_qty) AS rec_qty FROM purchase_order_items WHERE purchase_order_id = ? AND company_id = ?'
            );
            $stmt->bind_param('ii', $id, $companyId);
            $stmt->execute();
            $totals = $stmt->get_result()->fetch_assoc();
            $orderedQty = (int) ($totals['ordered_qty'] ?? 0);
            $receivedQtyTotal = (int) ($totals['rec_qty'] ?? 0);

            $nextStatus = 'approved';
            if ($orderedQty > 0 && $receivedQtyTotal >= $orderedQty) {
                $nextStatus = 'received';
            } elseif ($receivedQtyTotal > 0) {
                $nextStatus = 'partial_received';
            }

            if ($nextStatus !== $poStatus) {
                $stmt = $conn->prepare('UPDATE purchase_orders SET status = ? WHERE id = ? AND company_id = ?');
                $stmt->bind_param('sii', $nextStatus, $id, $companyId);
                $stmt->execute();
            }

            $conn->commit();
            set_flash('success', __('Goods receipt posted and stock updated.'));
        } catch (Throwable $e) {
            $conn->rollback();
            set_flash('error', $e->getMessage());
        }

        redirect("purchase_order_view.php?id={$id}");
    }
}

$stmt = $conn->prepare(
    'SELECT po.*, s.supplier_name, s.supplier_code, u.name AS creator_name
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
    redirect('purchase_orders.php');
}

$stmt = $conn->prepare('SELECT id, product_name, sku FROM products WHERE company_id = ? ORDER BY product_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$products = $stmt->get_result();
$stmt = $conn->prepare(
    'SELECT poi.*, p.product_name, p.sku
     FROM purchase_order_items poi
     INNER JOIN products p ON p.id = poi.product_id AND p.company_id = poi.company_id
     WHERE poi.purchase_order_id = ? AND poi.company_id = ?
     ORDER BY poi.id ASC'
);
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$items = $stmt->get_result();

$title = page_title(__('Purchase Order Detail'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Purchase Order Detail')) ?></h1>
<section class="card">
    <p><strong><?= e(__('PO No')) ?>:</strong> <?= e($po['po_number']) ?></p>
    <p><strong><?= e(__('Status')) ?>:</strong> <?= e(__($po['status'])) ?></p>
    <p><strong><?= e(__('Supplier')) ?>:</strong> <?= e($po['supplier_name']) ?> (<?= e($po['supplier_code']) ?>)</p>
    <p><strong><?= e(__('Total')) ?>:</strong> <?= e(format_money((float) $po['total_amount'])) ?></p>
</section>

<section class="card">
    <h2><?= e(__('Add Item')) ?></h2>
    <form method="post" class="inline-form">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="add_item">
        <select name="product_id" required>
            <option value=""><?= e(__('Product')) ?></option>
            <?php while ($product = $products->fetch_assoc()): ?>
                <option value="<?= e((string) $product['id']) ?>"><?= e($product['product_name']) ?>
                    (<?= e($product['sku']) ?>)</option>
            <?php endwhile; ?>
        </select>
        <input type="number" name="qty" min="1" placeholder="<?= e(__('Qty')) ?>" required>
        <input type="number" name="unit_cost" min="0" step="0.01" placeholder="<?= e(__('Unit Cost')) ?>" required>
        <button type="submit"><?= e(__('Add')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Items / Goods Receipt')) ?></h2>
    <table>
        <thead>
            <tr>
                <th><?= e(__('Product')) ?></th>
                <th><?= e(__('SKU')) ?></th>
                <th><?= e(__('Qty')) ?></th>
                <th><?= e(__('Received')) ?></th>
                <th><?= e(__('Remaining')) ?></th>
                <th><?= e(__('Unit Cost')) ?></th>
                <th><?= e(__('Line Total')) ?></th>
                <th><?= e(__('Receive')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items->num_rows === 0): ?>
                <tr>
                    <td colspan="8"><?= e(__('No items yet.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($item = $items->fetch_assoc()): ?>
                    <?php $remaining = (int) $item['qty'] - (int) $item['received_qty']; ?>
                    <tr>
                        <td><?= e($item['product_name']) ?></td>
                        <td><?= e($item['sku']) ?></td>
                        <td><?= e((string) $item['qty']) ?></td>
                        <td><?= e((string) $item['received_qty']) ?></td>
                        <td><?= e((string) $remaining) ?></td>
                        <td><?= e(format_money((float) $item['unit_cost'])) ?></td>
                        <td><?= e(format_money((float) $item['line_total'])) ?></td>
                        <td>
                            <?php if ($remaining > 0 && has_permission('receive_purchase') && in_array((string) $po['status'], ['approved', 'partial_received', 'received'], true)): ?>
                                <form method="post" class="inline-form">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="receive_item">
                                    <input type="hidden" name="po_item_id" value="<?= e((string) $item['id']) ?>">
                                    <input type="number" name="receive_qty" min="1" max="<?= e((string) $remaining) ?>"
                                        placeholder="<?= e(__('Qty')) ?>" required>
                                    <input type="text" name="remark" placeholder="<?= e(__('Remark')) ?>">
                                    <button type="submit"><?= e(__('Receive')) ?></button>
                                </form>
                            <?php elseif ($remaining > 0 && !in_array((string) $po['status'], ['approved', 'partial_received', 'received'], true)): ?>
                                <?= e(__('Waiting approval')) ?>
                            <?php else: ?>
                                <?= e(__('Completed')) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
