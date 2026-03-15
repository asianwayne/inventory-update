<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_products');
$companyId = require_company_id();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $id > 0;
$category = [
    'name' => '',
];

if ($editing) {
    $stmt = db()->prepare('SELECT id, name FROM categories WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $companyId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    if (!$found) {
        set_flash('error', __('Category not found.'));
        redirect('categories.php');
    }
    $category = array_merge($category, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect($editing ? "categories.php?id={$id}" : 'categories.php');
    }

    if (isset($_POST['delete_id'])) {
        $deleteId = (int) $_POST['delete_id'];
        if ($deleteId <= 0) {
            set_flash('error', __('Invalid category.'));
            redirect('categories.php');
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM products WHERE category_id = ? AND company_id = ?');
        $countStmt->bind_param('ii', $deleteId, $companyId);
        $countStmt->execute();
        $productsCount = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);

        if ($productsCount > 0) {
            set_flash('error', __('Cannot delete category used by products.'));
            redirect('categories.php');
        }

        $stmt = db()->prepare('DELETE FROM categories WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $deleteId, $companyId);
        $stmt->execute();
        set_flash('success', __('Category deleted.'));
        redirect('categories.php');
    }

    $categoryName = trim((string) ($_POST['name'] ?? ''));
    if ($categoryName === '') {
        set_flash('error', __('Category name is required.'));
        redirect($editing ? "categories.php?id={$id}" : 'categories.php');
    }

    if ($editing) {
        $stmt = db()->prepare('UPDATE categories SET name = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('sii', $categoryName, $id, $companyId);
        try {
            $stmt->execute();
            set_flash('success', __('Category updated.'));
            redirect('categories.php');
        } catch (Throwable $e) {
            set_flash('error', __('Category name must be unique.'));
            redirect("categories.php?id={$id}");
        }
    }

    $stmt = db()->prepare('INSERT INTO categories (company_id, name) VALUES (?, ?)');
    $stmt->bind_param('is', $companyId, $categoryName);
    try {
        $stmt->execute();
        set_flash('success', __('Category created.'));
    } catch (Throwable $e) {
        set_flash('error', __('Category name must be unique.'));
    }
    redirect('categories.php');
}

$stmt = db()->prepare(
    'SELECT c.id, c.name, c.created_at, COUNT(p.id) AS products_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id AND p.company_id = c.company_id
     WHERE c.company_id = ?
     GROUP BY c.id, c.name, c.created_at
     ORDER BY c.name ASC'
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$categories = $stmt->get_result();

$title = page_title(__('Product Categories'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Product Categories')) ?></h1>

<section class="card">
    <h2><?= $editing ? e(__('Edit Category')) : e(__('Add Category')) ?></h2>
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <label><?= e(__('Category Name')) ?>
            <input type="text" name="name" value="<?= e((string) $category['name']) ?>" required>
        </label>
        <div class="inline-form">
            <button type="submit"><?= $editing ? e(__('Update Category')) : e(__('Create Category')) ?></button>
            <?php if ($editing): ?>
                <a class="link-btn" href="categories.php"><?= e(__('Cancel')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Category List')) ?></h2>
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th><?= e(__('Name')) ?></th>
                <th><?= e(__('Products')) ?></th>
                <th><?= e(__('Created At')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($categories->num_rows === 0): ?>
                <tr>
                    <td colspan="4"><?= e(__('No categories yet.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($row = $categories->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e((string) $row['products_count']) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td>
                            <a href="categories.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Edit')) ?></a>
                            <form method="post" class="inline-form"
                                onsubmit="return confirm('<?= e(__('Delete this category?')) ?>')">
                                <?= csrf_input() ?>
                                <input type="hidden" name="delete_id" value="<?= e((string) $row['id']) ?>">
                                <button type="submit" class="danger"><?= e(__('Delete')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
