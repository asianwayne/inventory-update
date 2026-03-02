<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_settings');
$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('settings.php');
    }

    $inputs = [
        'app_name' => trim((string) ($_POST['app_name'] ?? APP_NAME)),
        'currency_symbol' => trim((string) ($_POST['currency_symbol'] ?? '$')),
        'low_stock_threshold' => (string) max(0, (int) ($_POST['low_stock_threshold'] ?? 5)),
        'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
    ];

    $stmt = db()->prepare(
        'INSERT INTO settings (company_id, `key`, `value`) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    );

    foreach ($inputs as $key => $value) {
        $stmt->bind_param('iss', $companyId, $key, $value);
        $stmt->execute();
    }

    set_flash('success', __('Settings saved.'));
    redirect('settings.php');
}

$title = page_title(__('Settings'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Settings')) ?></h1>

<section class="card">
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <label><?= e(__('Application Name')) ?>
            <input type="text" name="app_name" value="<?= e(setting('app_name', APP_NAME) ?? APP_NAME) ?>" required>
        </label>
        <label><?= e(__('Currency Symbol')) ?>
            <input type="text" name="currency_symbol" value="<?= e(setting('currency_symbol', '$') ?? '$') ?>"
                maxlength="3" required>
        </label>
        <label><?= e(__('Low Stock Threshold')) ?>
            <input type="number" name="low_stock_threshold" min="0"
                value="<?= e(setting('low_stock_threshold', '5') ?? '5') ?>" required>
        </label>
        <label style="flex-direction: row; align-items: center; gap: 0.5rem; cursor: pointer;">
            <input type="checkbox" name="allow_registration" value="1" <?= setting('allow_registration', '1') === '1' ? 'checked' : '' ?> style="width: auto;">
            <?= e(__('Allow Registration')) ?>
        </label>
        <button type="submit"><?= e(__('Save Settings')) ?></button>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
