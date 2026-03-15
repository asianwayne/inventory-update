<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

require_auth();
$companyId = require_company_id();

$type = $_GET['type'] ?? 'products';
$allowedTypes = ['products', 'suppliers', 'customers'];
if (!in_array($type, $allowedTypes)) {
    $type = 'products';
}

// Permissions check
if ($type === 'products') require_permission('manage_products');
if ($type === 'suppliers') require_permission('manage_suppliers');
if ($type === 'customers') require_permission('manage_customers');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect("import.php?type={$type}");
    }

    if (isset($_POST['bulk_delete'])) {
        $tableMap = ['products' => 'products', 'suppliers' => 'suppliers', 'customers' => 'customers'];
        $targetTable = $tableMap[$type] ?? null;
        if ($targetTable) {
            try {
                $conn = db();
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                
                if ($targetTable === 'products') {
                    // Clear all transactional data to ensure no garbled snapshots remain
                    $conn->query("DELETE FROM stock_movements WHERE company_id = $companyId");
                    $conn->query("DELETE FROM categories WHERE company_id = $companyId");
                    $conn->query("DELETE FROM suppliers WHERE company_id = $companyId");
                    $conn->query("DELETE FROM customers WHERE company_id = $companyId");
                    $conn->query("DELETE FROM sales_order_items WHERE company_id = $companyId");
                    $conn->query("DELETE FROM sales_orders WHERE company_id = $companyId");
                    $conn->query("DELETE FROM purchase_order_items WHERE company_id = $companyId");
                    $conn->query("DELETE FROM purchase_orders WHERE company_id = $companyId");
                    $conn->query("DELETE FROM goods_receipt_items WHERE company_id = $companyId");
                    $conn->query("DELETE FROM goods_receipts WHERE company_id = $companyId");
                    $conn->query("DELETE FROM invoices WHERE company_id = $companyId");
                    $conn->query("DELETE FROM inventory_returns WHERE company_id = $companyId");
                    $conn->query("DELETE FROM purchase_receipts WHERE company_id = $companyId");
                }
                $conn->query("DELETE FROM $targetTable WHERE company_id = $companyId");
                
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                set_flash('success', sprintf(__('All %s cleared.'), __($type)));
            } catch (Throwable $e) {
                db()->query("SET FOREIGN_KEY_CHECKS = 1");
                set_flash('error', __('Cannot clear table: ') . $e->getMessage());
            }
        }
        redirect("import.php?type={$type}");
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', __('Please select a valid CSV file.'));
        redirect("import.php?type={$type}");
    }

    $file = $_FILES['csv_file']['tmp_name'];
    
    // Read file content
    $content = file_get_contents($file);
    if ($content === false) {
        set_flash('error', __('Could not read the uploaded file.'));
        redirect("import.php?type={$type}");
    }

    // Convert to UTF-8 if not already
    if (!mb_check_encoding($content, 'UTF-8')) {
        // Try GBK/GB18030 as it's the most common non-UTF8 encoding for Chinese CSVs
        $converted = @mb_convert_encoding($content, 'UTF-8', 'GB18030');
        if (mb_check_encoding($converted, 'UTF-8')) {
            $content = $converted;
        } else {
            // Fallback to mb_detect_encoding for other languages if GB18030 failed
            $detected = mb_detect_encoding($content, ['GBK', 'BIG-5', 'SJIS', 'EUR-JP', 'ISO-8859-1'], true);
            if ($detected) {
                $content = mb_convert_encoding($content, 'UTF-8', $detected);
            }
        }
    }

    // Remove BOM if present
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }

    // Use a temporary stream to work with fgetcsv
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    if (!$handle) {
        set_flash('error', __('Could not open the converted data stream.'));
        redirect("import.php?type={$type}");
    }

    // Read header
    $header = fgetcsv($handle);
    if (!$header) {
        set_flash('error', __('The CSV file is empty.'));
        fclose($handle);
        redirect("import.php?type={$type}");
    }

    $conn = db();
    $conn->begin_transaction();
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    $currentRow = 1; // Header is row 1

    try {
        if ($type === 'products') {
            // Expected columns: Name, SKU, Category, OEM, Description, Application, Purchase Price, Sale Price, Initial Qty, Supplier, Remark
            while (($row = fgetcsv($handle)) !== false) {
                $currentRow++;
                if (count($row) < 2 || empty(trim(implode('', $row)))) continue; 

                $name = trim($row[0] ?? '');
                $sku = trim($row[1] ?? '');
                $categoryName = trim($row[2] ?? 'General');
                $oem = trim($row[3] ?? '');
                $description = trim($row[4] ?? '');
                $application = trim($row[5] ?? '');
                $purchasePrice = (float) ($row[6] ?? 0);
                $salePrice = (float) ($row[7] ?? 0);
                $qty = (int) ($row[8] ?? 0);
                $supplierName = trim($row[9] ?? '');
                $remark = trim($row[10] ?? '');

                if ($name === '' || $sku === '') {
                    $errorCount++;
                    $errors[] = ['row' => $currentRow, 'name' => $name, 'sku' => $sku, 'msg' => __('Name and SKU are required.')];
                    continue;
                }

                // Lookup Category
                $stmt = $conn->prepare('SELECT id FROM categories WHERE name = ? AND company_id = ? LIMIT 1');
                $stmt->bind_param('si', $categoryName, $companyId);
                $stmt->execute();
                $catRes = $stmt->get_result()->fetch_assoc();
                if ($catRes) {
                    $categoryId = (int) $catRes['id'];
                } else {
                    $stmt = $conn->prepare('INSERT INTO categories (company_id, name) VALUES (?, ?)');
                    $stmt->bind_param('is', $companyId, $categoryName);
                    $stmt->execute();
                    $categoryId = (int) $conn->insert_id;
                }

                // Lookup Supplier
                $supplierId = null;
                if ($supplierName !== '') {
                    $stmt = $conn->prepare('SELECT id FROM suppliers WHERE supplier_name = ? AND company_id = ? LIMIT 1');
                    $stmt->bind_param('si', $supplierName, $companyId);
                    $stmt->execute();
                    $supRes = $stmt->get_result()->fetch_assoc();
                    if ($supRes) {
                        $supplierId = (int) $supRes['id'];
                    }
                }

                $stmt = $conn->prepare('INSERT INTO products (company_id, product_name, category_id, sku, oem_number, description, application, purchase_price, sale_price, quantity, supplier_id, supplier, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isissssddiiss', $companyId, $name, $categoryId, $sku, $oem, $description, $application, $purchasePrice, $salePrice, $qty, $supplierId, $supplierName, $remark);
                
                try {
                    $stmt->execute();
                    $productId = (int) $conn->insert_id;
                    $successCount++;

                    if ($qty > 0) {
                        log_stock_movement($productId, $name, $sku, 'initial', $qty, 0, $qty, $purchasePrice, null, null, 'Initial import');
                    }
                } catch (Throwable $e) {
                    $errorCount++;
                    $errors[] = ['row' => $currentRow, 'name' => $name, 'sku' => $sku, 'msg' => $e->getMessage()];
                }
            }
        } elseif ($type === 'suppliers') {
            while (($row = fgetcsv($handle)) !== false) {
                $currentRow++;
                if (count($row) < 2 || empty(trim(implode('', $row)))) continue;
                $name = trim($row[0] ?? '');
                $code = trim($row[1] ?? '');
                $desc = trim($row[2] ?? '');
                $addr = trim($row[3] ?? '');
                $phone = trim($row[4] ?? '');
                $email = trim($row[5] ?? '');
                $remark = trim($row[6] ?? '');

                if ($name === '' || $code === '') {
                    $errorCount++;
                    $errors[] = ['row' => $currentRow, 'name' => $name, 'code' => $code, 'msg' => __('Name and Code are required.')];
                    continue;
                }

                $stmt = $conn->prepare('INSERT INTO suppliers (company_id, supplier_name, supplier_code, description, address, contact_phone, contact_email, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssssss', $companyId, $name, $code, $desc, $addr, $phone, $email, $remark);
                
                try {
                    $stmt->execute();
                    $successCount++;
                } catch (Throwable $e) {
                    $errorCount++;
                    $errors[] = ['row' => $currentRow, 'name' => $name, 'code' => $code, 'msg' => $e->getMessage()];
                }
            }
        } elseif ($type === 'customers') {
            while (($row = fgetcsv($handle)) !== false) {
                $currentRow++;
                if (count($row) < 2 || empty(trim(implode('', $row)))) continue;
                $name = trim($row[0] ?? '');
                $code = trim($row[1] ?? '');
                $desc = trim($row[2] ?? '');
                $addr = trim($row[3] ?? '');
                $phone = trim($row[4] ?? '');
                $email = trim($row[5] ?? '');
                $remark = trim($row[6] ?? '');

                if ($name === '' || $code === '') {
                    $errorCount++;
                    $errors[] = ['row' => $currentRow, 'name' => $name, 'code' => $code, 'msg' => __('Name and Code are required.')];
                    continue;
                }

                $stmt = $conn->prepare('INSERT INTO customers (company_id, customer_name, customer_code, description, address, contact_phone, contact_email, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssssss', $companyId, $name, $code, $desc, $addr, $phone, $email, $remark);
                
                try {
                    $stmt->execute();
                    $successCount++;
                } catch (Throwable $e) {
                    $errorCount++;
                    $errors[] = ['row' => $currentRow, 'name' => $name, 'code' => $code, 'msg' => $e->getMessage()];
                }
            }
        }

        $conn->commit();
        fclose($handle);

        $msg = sprintf(__('Imported %d records successfully.'), $successCount);
        if ($errorCount > 0) {
            $msg .= ' ' . sprintf(__('%d records failed.'), $errorCount);
            $_SESSION['import_errors'] = $errors;
        }
        set_flash('success', $msg);
        redirect("import.php?type={$type}");

    } catch (Throwable $e) {
        $conn->rollback();
        fclose($handle);
        set_flash('error', __('Import failed: ') . $e->getMessage());
        redirect("import.php?type={$type}");
    }
}

if (isset($_GET['download']) && $_GET['download'] === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=template_' . $type . '.csv');
    $output = fopen('php://output', 'w');
    if ($type === 'products') {
        fputcsv($output, ['Name', 'SKU', 'Category', 'OEM Number', 'Description', 'Application', 'Purchase Price', 'Sale Price', 'Initial Quantity', 'Supplier Name', 'Remark']);
        fputcsv($output, ['Product A', 'SKU001', 'General', 'OEM123', 'A good product', 'Automotive', '10.00', '15.00', '100', 'Supplier X', 'Note here']);
    } elseif ($type === 'suppliers') {
        fputcsv($output, ['Supplier Name', 'Supplier Code', 'Description', 'Address', 'Phone', 'Email', 'Remark']);
        fputcsv($output, ['Supplier X', 'SUP001', 'Description', '123 St', '0123456', 'sup@ex.com', 'Internal note']);
    } elseif ($type === 'customers') {
        fputcsv($output, ['Customer Name', 'Customer Code', 'Description', 'Address', 'Phone', 'Email', 'Remark']);
        fputcsv($output, ['Customer Y', 'CUST001', 'Description', '456 Rd', '0654321', 'cust@ex.com', 'Customer note']);
    }
    fclose($output);
    exit;
}

$title = page_title(__('Import Data'));
require_once __DIR__ . '/includes/header.php';

$import_errors = $_SESSION['import_errors'] ?? [];
unset($_SESSION['import_errors']);
?>

<div class="import-container">
    <h1><?= e(__('Import Data')) ?></h1>

    <div class="tabs">
        <a href="import.php?type=products" class="tab-btn <?= $type === 'products' ? 'active' : '' ?>"><?= e(__('Products')) ?></a>
        <a href="import.php?type=suppliers" class="tab-btn <?= $type === 'suppliers' ? 'active' : '' ?>"><?= e(__('Suppliers')) ?></a>
        <a href="import.php?type=customers" class="tab-btn <?= $type === 'customers' ? 'active' : '' ?>"><?= e(__('Customers')) ?></a>
    </div>

    <section class="card">
        <header class="card-header">
            <h2><?= e(__('Import ' . ucfirst($type))) ?></h2>
            <a href="import.php?type=<?= $type ?>&download=template" class="link-btn small">
                <i class="ph-bold ph-download"></i> <?= e(__('Download Template')) ?>
            </a>
        </header>

        <form method="post" enctype="multipart/form-data" class="stack">
            <?= csrf_input() ?>
            <div class="form-group">
                <label><?= e(__('Select CSV File')) ?></label>
                <input type="file" name="csv_file" accept=".csv" required>
                <p class="help-text"><?= e(__('Make sure your CSV file matches the template structure.')) ?></p>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="primary">
                    <i class="ph-bold ph-upload"></i> <?= e(__('Upload and Import')) ?>
                </button>
            </div>
        </form>
    </section>

    <?php if (!empty($import_errors)): ?>
        <section class="card border-danger">
            <h3 class="text-danger"><?= e(__('Import Errors')) ?></h3>
            <p><?= e(__('The following records could not be imported:')) ?></p>
            <div class="table-responsive">
                <table class="error-table">
                    <thead>
                        <tr>
                            <th><?= e(__('Row')) ?></th>
                            <th><?= e(__('Name')) ?></th>
                            <th><?= e(__('Info')) ?></th>
                            <th><?= e(__('Error')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($import_errors as $err): ?>
                            <tr>
                                <td><?= e((string) $err['row']) ?></td>
                                <td><?= e($err['name'] ?? '-') ?></td>
                                <td><?= e($err['sku'] ?? $err['code'] ?? '-') ?></td>
                                <td class="text-danger"><?= e($err['msg']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="card mt-4">
        <h3><?= e(__('Instructions')) ?></h3>
        <ul class="instruction-list">
            <li><?= e(__('Download the template file first to see the required columns.')) ?></li>
            <li><?= e(__('Do not change the order of columns in the template.')) ?></li>
            <li><?= e(__('For products, if the category name doesn\'t exist, it will be created.')) ?></li>
            <li><?= e(__('SKU (for products) and Code (for suppliers/customers) must be unique.')) ?></li>
            <li><?= e(__('Prices and quantities should be numbers.')) ?></li>
        </ul>
    </section>

    <section class="card mt-4 border-danger">
        <h3><?= e(__('Cleanup Data')) ?></h3>
        <p class="text-muted"><?= e(__('If you have imported garbled data or want to start over, you can delete all records for this module.')) ?></p>
        <form method="post" onsubmit="return confirm('<?= e(__('Are you sure? This will delete all ' . $type . ' for your company!')) ?>')">
            <?= csrf_input() ?>
            <button type="submit" name="bulk_delete" value="1" class="btn-outline-danger">
                <?= e(__('Delete All ' . ucfirst($type))) ?>
            </button>
        </form>
    </section>
</div>

<style>
.tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}
.tab-btn {
    padding: 10px 20px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-muted);
}
.tab-btn.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.help-text {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-top: 5px;
}
.error-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 0.9rem;
}
.error-table th, .error-table td {
    padding: 10px;
    border: 1px solid var(--border-color);
    text-align: left;
}
.error-table th {
    background: rgba(0,0,0,0.05);
}
.table-responsive {
    overflow-x: auto;
}
.mt-4 { margin-top: 2rem; }
.text-danger { color: #e74c3c; }
.border-danger { border-left: 4px solid #e74c3c; }
.btn-outline-danger {
    background: transparent;
    border: 1px solid #e74c3c;
    color: #e74c3c;
    padding: 8px 15px;
    border-radius: 6px;
    cursor: pointer;
}
.btn-outline-danger:hover {
    background: #e74c3c;
    color: white;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
