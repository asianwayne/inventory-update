<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_settings');

$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('companies.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));

    if ($name === '' || $code === '') {
        set_flash('error', __('Company name and code are required.'));
        redirect('companies.php');
    }
    if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
        set_flash('error', __('Company code must be 3-30 chars and use A-Z, 0-9, _ or -.'));
        redirect('companies.php');
    }

    $stmt = db()->prepare('UPDATE companies SET name = ?, code = ? WHERE id = ?');
    $stmt->bind_param('ssi', $name, $code, $companyId);
    try {
        $stmt->execute();
        $_SESSION['user']['company_name'] = $name;
        $_SESSION['user']['company_code'] = $code;
        set_flash('success', __('Company updated.'));
    } catch (Throwable $e) {
        set_flash('error', __('Could not update company (code may already exist).'));
    }
    redirect('companies.php');
}

$stmt = db()->prepare('SELECT id, name, code, is_active, created_at FROM companies WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();

if (!$company) {
    set_flash('error', __('Company not found.'));
    redirect('dashboard.php');
}

$title = page_title(__('Company'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Company')) ?></h1>
<section class="card">
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <label><?= e(__('Company Name')) ?>
            <input type="text" name="name" value="<?= e((string) $company['name']) ?>" required>
        </label>
        <label><?= e(__('Company Code')) ?>
            <input type="text" name="code" value="<?= e((string) $company['code']) ?>" required>
        </label>
        <p class="muted"><?= e(__('Created At')) ?>: <?= e((string) $company['created_at']) ?></p>
        <button type="submit"><?= e(__('Save')) ?></button>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>