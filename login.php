<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (auth_user()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('login.php');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $companyCode = strtoupper(trim((string) ($_POST['company_code'] ?? '')));

    if ($email === '' || $password === '' || $companyCode === '') {
        set_flash('error', __('Company code, email, and password are required.'));
        redirect('login.php');
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.email, u.role, u.password_hash, u.company_id, c.name AS company_name, c.code AS company_code
         FROM users u
         INNER JOIN companies c ON c.id = u.company_id
         WHERE u.email = ? AND c.code = ? AND c.is_active = 1
         LIMIT 1'
    );
    $stmt->bind_param('ss', $email, $companyCode);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        set_flash('error', __('Invalid credentials.'));
        redirect('login.php');
    }

    $remember = isset($_POST['remember_me']);

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $stmt = db()->prepare('UPDATE users SET remember_token = ? WHERE id = ?');
        $stmt->bind_param('si', $token, $user['id']);
        $stmt->execute();

        // Set cookie for 30 days
        setcookie('remember_me', $token, time() + (86400 * 30), "/", "", false, true);
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'] ?? 'staff',
        'company_id' => (int) $user['company_id'],
        'company_name' => (string) $user['company_name'],
        'company_code' => (string) $user['company_code'],
    ];

    set_flash('success', __('Welcome back, ') . $user['name'] . '.');
    redirect('dashboard.php');
}

$title = page_title(__('Login'));
require_once __DIR__ . '/includes/header.php';
?>
<section class="auth-card">
    <div style="text-align: center; margin-bottom: 2rem;">
        <i class="ph-bold ph-package" style="font-size: 3rem; color: var(--primary-light);"></i>
        <h1 style="color: #fff; margin-top: 1rem;"><?= e(__('Login')) ?></h1>
        <p style="color: var(--sidebar-text);"><?= e(__('Please sign in to continue')) ?></p>
    </div>
    <form method="post" class="card stack">
        <?= csrf_input() ?>
        <label><?= e(__('Company Code')) ?>
            <input type="text" name="company_code" placeholder="DEFAULT" required>
        </label>
        <label><?= e(__('Email')) ?>
            <input type="email" name="email" placeholder="email@example.com" required>
        </label>
        <label><?= e(__('Password')) ?>
            <input type="password" name="password" placeholder="******" required>
        </label>
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
            <input type="checkbox" name="remember_me" id="remember_me" style="width: auto;">
            <label for="remember_me" style="margin-bottom: 0; cursor: pointer;"><?= e(__('Remember Me')) ?></label>
        </div>
        <button type="submit" style="width: 100%; margin-top: 1rem;">
            <span><?= e(__('Sign In')) ?></span>
            <i class="ph-bold ph-arrow-right"></i>
        </button>
        <?php if (setting('allow_registration', '1') === '1'): ?>
            <a href="register.php" class="link-btn" style="width: 100%; justify-content: center; margin-top: 0.75rem;">
                <?= e(__('Register')) ?>
            </a>
            <p class="muted" style="text-align: center; margin-top: 1rem;">
                <?= e(__('No account yet?')) ?>
                <a href="register.php"><?= e(__('Register')) ?></a>
            </p>
        <?php endif; ?>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
