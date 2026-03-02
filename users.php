<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_users');
$companyId = require_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect('users.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'staff'));
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            set_flash('error', __('Name, email, and password are required.'));
            redirect('users.php');
        }
        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare('INSERT INTO users (company_id, name, email, role, password_hash) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issss', $companyId, $name, $email, $role, $passwordHash);
        try {
            $stmt->execute();
            set_flash('success', __('User created.'));
        } catch (Throwable $e) {
            set_flash('error', __('Could not create user (email may already exist).'));
        }
        redirect('users.php');
    }

    if ($action === 'update_role') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = trim((string) ($_POST['role'] ?? 'staff'));
        if ($userId <= 0 || !in_array($role, ['admin', 'staff'], true)) {
            set_flash('error', __('Invalid role update request.'));
            redirect('users.php');
        }

        $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ? AND company_id = ?');
        $stmt->bind_param('sii', $role, $userId, $companyId);
        $stmt->execute();

        if ($userId === current_user_id()) {
            $_SESSION['user']['role'] = $role;
        }
        set_flash('success', __('User role updated.'));
        redirect('users.php');
    }
}

$stmt = db()->prepare('SELECT id, name, email, role, created_at FROM users WHERE company_id = ? ORDER BY id ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$users = $stmt->get_result();

$title = page_title(__('Users'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Users')) ?></h1>

<section class="card">
    <h2><?= e(__('Create User')) ?></h2>
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <label><?= e(__('Full Name')) ?>
            <input type="text" name="name" required>
        </label>
        <label><?= e(__('Email')) ?>
            <input type="email" name="email" required>
        </label>
        <label><?= e(__('Role')) ?>
            <select name="role" required>
                <option value="staff"><?= e(__('Staff')) ?></option>
                <option value="admin"><?= e(__('Admin')) ?></option>
            </select>
        </label>
        <label><?= e(__('Temporary Password')) ?>
            <input type="password" name="password" required>
        </label>
        <button type="submit"><?= e(__('Create User')) ?></button>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Existing Users')) ?></h2>
    <table>
        <thead>
            <tr>
                <th><?= e(__('ID')) ?></th>
                <th><?= e(__('Name')) ?></th>
                <th><?= e(__('Email')) ?></th>
                <th><?= e(__('Role')) ?></th>
                <th><?= e(__('Created')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $users->fetch_assoc()): ?>
                <tr>
                    <td><?= e((string) $row['id']) ?></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e($row['email']) ?></td>
                    <td><?= e(__($row['role'])) ?></td>
                    <td><?= e($row['created_at']) ?></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="update_role">
                            <input type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
                            <select name="role">
                                <option value="staff" <?= $row['role'] === 'staff' ? 'selected' : '' ?>><?= e(__('Staff')) ?>
                                </option>
                                <option value="admin" <?= $row['role'] === 'admin' ? 'selected' : '' ?>><?= e(__('Admin')) ?>
                                </option>
                            </select>
                            <button type="submit"><?= e(__('Save')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
