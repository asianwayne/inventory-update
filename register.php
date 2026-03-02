<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (auth_user()) {
    redirect('dashboard.php');
}

if (setting('allow_registration', '1') !== '1') {
    set_flash('error', __('Registration is currently closed.'));
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('register.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $companyName = trim((string) ($_POST['company_name'] ?? ''));
    $companyCode = strtoupper(trim((string) ($_POST['company_code'] ?? '')));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || $companyName === '' || $companyCode === '' || $email === '' || $password === '') {
        set_flash('error', __('Name, company, email, and password are required.'));
        redirect('register.php');
    }
    if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $companyCode)) {
        set_flash('error', __('Company code must be 3-30 chars and use A-Z, 0-9, _ or -.'));
        redirect('register.php');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', __('Invalid contact email.'));
        redirect('register.php');
    }
    if (strlen($password) < 6) {
        set_flash('error', __('Password must be at least 6 characters.'));
        redirect('register.php');
    }
    if ($password !== $confirmPassword) {
        set_flash('error', __('Password confirmation does not match.'));
        redirect('register.php');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $conn = db();
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('INSERT INTO companies (name, code) VALUES (?, ?)');
        $stmt->bind_param('ss', $companyName, $companyCode);
        $stmt->execute();
        $companyId = (int) $conn->insert_id;

        $role = 'admin';
        $stmt = $conn->prepare('INSERT INTO users (company_id, name, email, role, password_hash) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $companyId, $name, $email, $role, $passwordHash);
        $stmt->execute();

        $settingsStmt = $conn->prepare(
            'INSERT INTO settings (company_id, `key`, `value`) VALUES (?, ?, ?)'
        );
        foreach (
            [
                'app_name' => 'Inventory Management',
                'currency_symbol' => '$',
                'low_stock_threshold' => '5',
                'allow_registration' => '1',
            ] as $key => $value
        ) {
            $settingsStmt->bind_param('iss', $companyId, $key, $value);
            $settingsStmt->execute();
        }

        $categoryName = 'General';
        $catStmt = $conn->prepare('INSERT INTO categories (company_id, name) VALUES (?, ?)');
        $catStmt->bind_param('is', $companyId, $categoryName);
        $catStmt->execute();

        $conn->commit();
        set_flash('success', __('Registration successful. Please sign in.'));
        redirect('login.php');
    } catch (Throwable $e) {
        $conn->rollback();
        set_flash('error', __('Could not create company/user (company code or email may already exist).'));
        redirect('register.php');
    }
}

$title = page_title(__('Register'));
require_once __DIR__ . '/includes/header.php';
?>
<section class="auth-card">
    <div style="text-align: center; margin-bottom: 2rem;">
        <i class="ph-bold ph-user-plus" style="font-size: 3rem; color: var(--primary-light);"></i>
        <h1 style="color: #fff; margin-top: 1rem;"><?= e(__('Register')) ?></h1>
        <p style="color: var(--sidebar-text);"><?= e(__('Create a new account')) ?></p>
    </div>
    <form method="post" class="card stack">
        <?= csrf_input() ?>
        <label><?= e(__('Full Name')) ?>
            <input type="text" name="name" required>
        </label>
        <label><?= e(__('Company Name')) ?>
            <input type="text" name="company_name" required>
        </label>
        <label><?= e(__('Company Code')) ?>
            <input type="text" name="company_code" placeholder="ACME" required>
        </label>
        <label><?= e(__('Email')) ?>
            <input type="email" name="email" placeholder="email@example.com" required>
        </label>
        <label><?= e(__('Password')) ?>
            <input type="password" name="password" minlength="6" required>
        </label>
        <label><?= e(__('Confirm Password')) ?>
            <input type="password" name="confirm_password" minlength="6" required>
        </label>
        <button type="submit" style="width: 100%; margin-top: 1rem;">
            <span><?= e(__('Create Account')) ?></span>
            <i class="ph-bold ph-arrow-right"></i>
        </button>
        <p class="muted" style="text-align: center; margin-top: 1rem;">
            <?= e(__('Already have an account?')) ?>
            <a href="login.php"><?= e(__('Sign In')) ?></a>
        </p>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
