<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_products');
$companyId = require_company_id();

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0775, true);
}

function upload_product_image(array $file, ?string $existing = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(__('Image upload failed.'));
    }

    $tmpPath = $file['tmp_name'] ?? '';
    $mime = '';
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath) ?: '';
    }
    if ($mime === '' && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $tmpPath) ?: '';
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('getimagesize')) {
        $imageInfo = @getimagesize($tmpPath);
        if (is_array($imageInfo) && isset($imageInfo['mime'])) {
            $mime = (string) $imageInfo['mime'];
        }
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException(__('Only JPG, PNG, WEBP, or GIF images are allowed.'));
    }

    $maxSize = 3 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException(__('Image size must be less than 3MB.'));
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $targetPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException(__('Could not save uploaded image.'));
    }

    if ($existing && is_file(rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $existing)) {
        @unlink(rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $existing);
    }

    return $filename;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $id > 0;
$product = [
    'product_name' => '',
    'image_path' => '',
    'description' => '',
    'application' => '',
    'category_id' => '',
    'sku' => '',
    'oem_number' => '',
    'quantity' => '0',
    'purchase_price' => '0.00',
    'sale_price' => '0.00',
    'supplier_id' => '',
    'supplier' => '',
    'remark' => '',
];

if ($editing) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $companyId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    if (!$found) {
        set_flash('error', __('Product not found.'));
        redirect('products.php');
    }
    $product = array_merge($product, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect($editing ? "product_form.php?id={$id}" : 'product_form.php');
    }

    if (isset($_POST['add_category'])) {
        $newCategory = trim((string) ($_POST['new_category_name'] ?? ''));
        if ($newCategory === '') {
            set_flash('error', __('Category name is required.'));
            redirect($editing ? "product_form.php?id={$id}" : 'product_form.php');
        }
        $stmt = db()->prepare('INSERT INTO categories (company_id, name) VALUES (?, ?)');
        $stmt->bind_param('is', $companyId, $newCategory);
        try {
            $stmt->execute();
            set_flash('success', __('Category added.'));
        } catch (Throwable $e) {
            set_flash('error', __('Category already exists or could not be added.'));
        }
        redirect($editing ? "product_form.php?id={$id}" : 'product_form.php');
    }

    $productName = trim((string) ($_POST['product_name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $application = trim((string) ($_POST['application'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $sku = trim((string) ($_POST['sku'] ?? ''));
    $oemNumber = trim((string) ($_POST['oem_number'] ?? ''));
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $purchasePrice = (float) ($_POST['purchase_price'] ?? 0);
    $salePrice = (float) ($_POST['sale_price'] ?? 0);
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    $remark = trim((string) ($_POST['remark'] ?? ''));

    if ($productName === '' || $sku === '') {
        set_flash('error', __('Product name and SKU are required.'));
        redirect($editing ? "product_form.php?id={$id}" : 'product_form.php');
    }

    $existingImage = $product['image_path'] ?? null;
    try {
        $imagePath = upload_product_image($_FILES['product_image'] ?? [], $existingImage ?: null);
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
        redirect($editing ? "product_form.php?id={$id}" : 'product_form.php');
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

    if ($editing) {
        $oldQty = (int) ($product['quantity'] ?? 0);
        $stmt = db()->prepare(
            'UPDATE products
             SET product_name=?, image_path=?, description=?, application=?, category_id=?, sku=?, oem_number=?, quantity=?, purchase_price=?, sale_price=?, supplier_id=NULLIF(?,0), supplier=?, remark=?
             WHERE id=? AND company_id=?'
        );
        $stmt->bind_param(
            'ssssissiddissii',
            $productName,
            $imagePath,
            $description,
            $application,
            $categoryId,
            $sku,
            $oemNumber,
            $quantity,
            $purchasePrice,
            $salePrice,
            $supplierId,
            $supplierName,
            $remark,
            $id,
            $companyId
        );
        $stmt->execute();

        if ($quantity !== $oldQty) {
            $qtyChange = $quantity - $oldQty;
            $movementType = $qtyChange >= 0 ? 'adjustment_in' : 'adjustment_out';
            log_stock_movement(
                $id,
                $productName,
                $sku,
                $movementType,
                $qtyChange,
                $oldQty,
                $quantity,
                $purchasePrice,
                'product',
                $id,
                __('Manual quantity adjustment in edit form')
            );
        }

        set_flash('success', __('Product updated.'));
        redirect('products.php');
    }

    $stmt = db()->prepare(
        'INSERT INTO products
        (company_id, product_name, image_path, description, application, category_id, sku, oem_number, quantity, purchase_price, sale_price, supplier_id, supplier, remark)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0), ?, ?)'
    );
    $stmt->bind_param(
        'issssissiddiss',
        $companyId,
        $productName,
        $imagePath,
        $description,
        $application,
        $categoryId,
        $sku,
        $oemNumber,
        $quantity,
        $purchasePrice,
        $salePrice,
        $supplierId,
        $supplierName,
        $remark
    );
    $stmt->execute();
    $newProductId = (int) db()->insert_id;

    if ($quantity > 0) {
        log_stock_movement(
            $newProductId,
            $productName,
            $sku,
            'initial',
            $quantity,
            0,
            $quantity,
            $purchasePrice,
            'product',
            $newProductId,
            __('Initial stock at product creation')
        );
    }

    set_flash('success', __('Product created.'));
    redirect('products.php');
}

$stmt = db()->prepare('SELECT id, name FROM categories WHERE company_id = ? ORDER BY name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$categories = $stmt->get_result();

$stmt = db()->prepare('SELECT id, supplier_name, supplier_code FROM suppliers WHERE company_id = ? ORDER BY supplier_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$suppliers = $stmt->get_result();
$title = page_title($editing ? __('Edit Product') : __('Create Product'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= $editing ? e(__('Edit Product')) : e(__('Create Product')) ?></h1>

<section class="card">
    <h2><?= e(__('Add Category')) ?></h2>
    <form method="post" class="inline-form">
        <?= csrf_input() ?>
        <input type="text" name="new_category_name" placeholder="<?= e(__('New category name')) ?>" required>
        <button type="submit" name="add_category" value="1"><?= e(__('Add Category')) ?></button>
    </form>
</section>

<section class="card">
    <form method="post" enctype="multipart/form-data" class="stack">
        <?= csrf_input() ?>
        <label><?= e(__('Product Name')) ?>
            <input type="text" name="product_name" value="<?= e((string) $product['product_name']) ?>" required>
        </label>
        <label><?= e(__('Product Image')) ?>
            <input type="file" name="product_image" accept="image/*">
        </label>
        <?php if (!empty($product['image_path'])): ?>
            <a href="<?= e(UPLOAD_URL . '/' . $product['image_path']) ?>" class="image-preview-link">
                <img src="<?= e(UPLOAD_URL . '/' . $product['image_path']) ?>" alt="Product image" class="thumb">
            </a>
        <?php endif; ?>
        <label><?= e(__('Description')) ?>
            <textarea name="description" rows="3"><?= e((string) $product['description']) ?></textarea>
        </label>
        <label><?= e(__('Application Areas')) ?>
            <input type="text" name="application" value="<?= e((string) $product['application']) ?>"
                placeholder="<?= e(__('e.g. Automotive braking system, Industrial pump')) ?>">
        </label>
        <label><?= e(__('Product Category')) ?>
            <select name="category_id" required>
                <option value=""><?= e(__('Select category')) ?></option>
                <?php while ($category = $categories->fetch_assoc()): ?>
                    <option value="<?= e((string) $category['id']) ?>" <?= (string) $product['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                        <?= e($category['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label><?= e(__('SKU')) ?>
            <input type="text" name="sku" value="<?= e((string) $product['sku']) ?>" required>
        </label>
        <label><?= e(__('OEM Number')) ?>
            <input type="text" name="oem_number" value="<?= e((string) $product['oem_number']) ?>">
        </label>
        <label><?= e(__('Quantity')) ?>
            <input type="number" name="quantity" min="0" value="<?= e((string) $product['quantity']) ?>" required>
        </label>
        <label><?= e(__('Purchase Price')) ?>
            <input type="number" name="purchase_price" min="0" step="0.01"
                value="<?= e((string) $product['purchase_price']) ?>" required>
        </label>
        <label><?= e(__('Sale Price')) ?>
            <input type="number" name="sale_price" min="0" step="0.01" value="<?= e((string) $product['sale_price']) ?>"
                required>
        </label>
        <label><?= e(__('Supplier')) ?>
            <select name="supplier_id">
                <option value="0"><?= e(__('Select supplier')) ?></option>
                <?php while ($supplier = $suppliers->fetch_assoc()): ?>
                    <option value="<?= e((string) $supplier['id']) ?>" <?= (string) $product['supplier_id'] === (string) $supplier['id'] ? 'selected' : '' ?>>
                        <?= e($supplier['supplier_name']) ?> (<?= e($supplier['supplier_code']) ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label><?= e(__('Remark')) ?>
            <textarea name="remark" rows="3"><?= e((string) $product['remark']) ?></textarea>
        </label>
        <div class="inline-form">
            <button type="submit"><?= $editing ? e(__('Update Product')) : e(__('Create Product')) ?></button>
            <?php if ($editing): ?>
                <a class="link-btn secondary-btn" href="product_view.php?id=<?= e((string) $id) ?>"><?= e(__('Back to Detail')) ?></a>
            <?php endif; ?>
            <a class="link-btn" href="products.php"><?= e(__('Cancel')) ?></a>
        </div>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
