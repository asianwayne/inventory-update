<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('view_movements');
$companyId = require_company_id();

$search = trim((string) ($_GET['search'] ?? ''));
$movementType = trim((string) ($_GET['movement_type'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

$filters = ['sm.company_id = ?'];
$types = 'i';
$params = [$companyId];

if ($search !== '') {
    $filters[] = '(sm.product_name LIKE ? OR sm.sku LIKE ?)';
    $term = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $term;
    $params[] = $term;
}

$allowedTypes = ['initial', 'purchase_in', 'adjustment_in', 'adjustment_out', 'delete_out'];
if ($movementType !== '' && in_array($movementType, $allowedTypes, true)) {
    $filters[] = 'sm.movement_type = ?';
    $types .= 's';
    $params[] = $movementType;
}

$whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';

$countSql = "SELECT COUNT(*) AS total FROM stock_movements sm {$whereSql}";
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
    SELECT sm.*, u.name AS user_name
    FROM stock_movements sm
    LEFT JOIN users u ON u.id = sm.created_by
    {$whereSql}
    ORDER BY sm.id DESC
    LIMIT ? OFFSET ?
";
$stmt = db()->prepare($sql);
$typesWithPaging = $types . 'ii';
$paramsWithPaging = [...$params, $limit, $offset];
$stmt->bind_param($typesWithPaging, ...$paramsWithPaging);
$stmt->execute();
$movements = $stmt->get_result();

function movement_page_query(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    return http_build_query($query);
}

$title = page_title(__('Stock Movements'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Stock Movements')) ?></h1>

<section class="card">
    <form method="get" class="filter-form">
        <input type="text" name="search" value="<?= e($search) ?>"
            placeholder="<?= e(__('Search by product or SKU')) ?>">
        <select name="movement_type">
            <option value=""><?= e(__('All movement types')) ?></option>
            <?php foreach ($allowedTypes as $type): ?>
                <option value="<?= e($type) ?>" <?= $movementType === $type ? 'selected' : '' ?>>
                    <?= e(__($type)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= e(__('Filter')) ?></button>
        <a class="link-btn" href="stock_movements.php"><?= e(__('Reset')) ?></a>
    </form>
</section>

<section class="card">
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th><?= e(__('ID')) ?></th>
                <th><?= e(__('Date')) ?></th>
                <th><?= e(__('Product')) ?></th>
                <th><?= e(__('SKU')) ?></th>
                <th><?= e(__('Type')) ?></th>
                <th><?= e(__('Change')) ?></th>
                <th><?= e(__('Before')) ?></th>
                <th><?= e(__('After')) ?></th>
                <th><?= e(__('Cost')) ?></th>
                <th><?= e(__('Reference')) ?></th>
                <th><?= e(__('User')) ?></th>
                <th><?= e(__('Note')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($movements->num_rows === 0): ?>
                <tr>
                    <td colspan="12"><?= e(__('No movements found.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($row = $movements->fetch_assoc()): ?>
                    <tr>
                        <td><?= e((string) $row['id']) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td><?= e($row['product_name']) ?></td>
                        <td><?= e($row['sku']) ?></td>
                        <td><?= e(__($row['movement_type'])) ?></td>
                        <td><?= e((string) $row['qty_change']) ?></td>
                        <td><?= e((string) $row['qty_before']) ?></td>
                        <td><?= e((string) $row['qty_after']) ?></td>
                        <td><?= $row['unit_cost'] !== null ? e(format_money((float) $row['unit_cost'])) : '-' ?></td>
                        <td>
                            <?php if (!empty($row['reference_type'])): ?>
                                <?= e($row['reference_type']) ?>#<?= e((string) $row['reference_id']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= e($row['user_name'] ?? 'System') ?></td>
                        <td><?= e($row['note']) ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="stock_movements.php?<?= e(movement_page_query(['page' => $page - 1])) ?>"><?= e(__('Prev')) ?></a>
        <?php endif; ?>
        <span><?= e(__('Page')) ?> <?= e((string) $page) ?> <?= e(__('of')) ?> <?= e((string) $totalPages) ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="stock_movements.php?<?= e(movement_page_query(['page' => $page + 1])) ?>"><?= e(__('Next')) ?></a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
