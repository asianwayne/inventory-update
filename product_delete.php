<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('delete_product');
$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf()) {
    set_flash('error', 'Invalid request.');
    redirect('products.php');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Invalid product.');
    redirect('products.php');
}

$stmt = db()->prepare('SELECT id, product_name, sku, quantity, image_path FROM products WHERE id = ? AND company_id = ? LIMIT 1');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect('products.php');
}

log_stock_movement(
    (int) $product['id'],
    (string) $product['product_name'],
    (string) $product['sku'],
    'delete_out',
    -((int) $product['quantity']),
    (int) $product['quantity'],
    0,
    null,
    'product',
    $id,
    'Product deleted'
);

$stmt = db()->prepare('DELETE FROM products WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();

if (!empty($product['image_path'])) {
    $fullPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $product['image_path'];
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

set_flash('success', 'Product deleted.');
redirect('products.php');
