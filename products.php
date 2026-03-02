<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_products');
$companyId = require_company_id();

$search = trim((string) ($_GET['search'] ?? ''));
$categoryId = (int) ($_GET['category_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$filters = ['p.company_id = ?'];
$types = 'i';
$params = [$companyId];

if ($search !== '') {
    $filters[] = '(p.product_name LIKE ? OR p.sku LIKE ? OR p.oem_number LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $types .= 'sss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($categoryId > 0) {
    $filters[] = 'p.category_id = ?';
    $types .= 'i';
    $params[] = $categoryId;
}

$whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countSql = "SELECT COUNT(*) AS total FROM products p {$whereSql}";
$countStmt = db()->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$sql = "
    SELECT p.*, c.name AS category_name, s.supplier_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id AND c.company_id = p.company_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id AND s.company_id = p.company_id
    {$whereSql}
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = db()->prepare($sql);
$typesWithPaging = $types . 'ii';
$paramsWithPaging = [...$params, $limit, $offset];
$stmt->bind_param($typesWithPaging, ...$paramsWithPaging);
$stmt->execute();
$products = $stmt->get_result();

$stmt = db()->prepare('SELECT id, name FROM categories WHERE company_id = ? ORDER BY name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$categories = $stmt->get_result();

function page_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    return http_build_query($query);
}

$title = page_title(__('Products List'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Products List')) ?></h1>

<section class="card">
    <form method="get" class="filter-form">
        <input type="text" name="search" placeholder="<?= e(__('Search name, SKU, OEM...')) ?>" value="<?= e($search) ?>">
        <select name="category_id">
            <option value="0"><?= e(__('All categories')) ?></option>
            <?php while ($category = $categories->fetch_assoc()): ?>
                <option value="<?= e((string) $category['id']) ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                    <?= e($category['name']) ?>
                </option>
            <?php endwhile; ?>
        </select>
        <button type="submit"><?= e(__('Filter')) ?></button>
        <a class="link-btn" href="products.php"><?= e(__('Reset')) ?></a>
    </form>
</section>

<section class="card">
    <table>
        <thead>
            <tr>
                <th><?= e(__('Image')) ?></th>
                <th><?= e(__('Name')) ?></th>
                <th><?= e(__('Application')) ?></th>
                <th><?= e(__('Category')) ?></th>
                <th><?= e(__('SKU')) ?></th>
                <th><?= e(__('OEM')) ?></th>
                <th><?= e(__('Qty')) ?></th>
                <th><?= e(__('Purchase')) ?></th>
                <th><?= e(__('Sale')) ?></th>
                <th><?= e(__('Supplier')) ?></th>
                <th><?= e(__('Actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($products->num_rows === 0): ?>
            <tr><td colspan="11"><?= e(__('No products found.')) ?></td></tr>
        <?php else: ?>
            <?php while ($row = $products->fetch_assoc()): ?>
                <tr>
                    <td>
                        <?php if (!empty($row['image_path'])): ?>
                            <a href="<?= e(UPLOAD_URL . '/' . $row['image_path']) ?>" class="image-preview-link">
                                <img src="<?= e(UPLOAD_URL . '/' . $row['image_path']) ?>" alt="img" class="thumb small">
                            </a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['product_name']) ?></td>
                    <td><?= e($row['application']) ?></td>
                    <td><?= e(__($row['category_name'] ?? 'Uncategorized')) ?></td>
                    <td><?= e($row['sku']) ?></td>
                    <td><?= e($row['oem_number']) ?></td>
                    <td><?= e((string) $row['quantity']) ?></td>
                    <td><?= e(format_money((float) $row['purchase_price'])) ?></td>
                    <td><?= e(format_money((float) $row['sale_price'])) ?></td>
                    <td><?= e($row['supplier_name'] ?? $row['supplier']) ?></td>
                    <td>
                        <a href="product_form.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Edit')) ?></a>
                        <?php if (has_permission('delete_product')): ?>
                            <form method="post" action="product_delete.php" class="inline-form" onsubmit="return confirm('<?= e(__('Delete this product?')) ?>')">
                                <?= csrf_input() ?>
                                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                <button class="danger" type="submit"><?= e(__('Delete')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="products.php?<?= e(page_query(['page' => $page - 1])) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="products.php?<?= e(page_query(['page' => $page + 1])) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
