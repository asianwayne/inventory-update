<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_customers');
$companyId = require_company_id();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $id > 0;
$customer = [
    'customer_name' => '',
    'description' => '',
    'address' => '',
    'contact_phone' => '',
    'contact_email' => '',
    'customer_code' => '',
    'remark' => '',
];

if ($editing) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $companyId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    if (!$found) {
        set_flash('error', __('Customer not found.'));
        redirect('customers.php');
    }
    $customer = array_merge($customer, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect($editing ? "customers.php?id={$id}" : 'customers.php');
    }

    if (isset($_POST['delete_id'])) {
        $deleteId = (int) $_POST['delete_id'];
        if ($deleteId <= 0) {
            set_flash('error', __('Invalid customer.'));
            redirect('customers.php');
        }
        $stmt = db()->prepare('DELETE FROM customers WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $deleteId, $companyId);
        try {
            $stmt->execute();
            set_flash('success', __('Customer deleted.'));
        } catch (Throwable $e) {
            set_flash('error', __('Cannot delete customer currently referenced.'));
        }
        redirect('customers.php');
    }

    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $customerCode = trim((string) ($_POST['customer_code'] ?? ''));
    $remark = trim((string) ($_POST['remark'] ?? ''));

    if ($customerName === '' || $customerCode === '') {
        set_flash('error', __('Customer name and customer code are required.'));
        redirect($editing ? "customers.php?id={$id}" : 'customers.php');
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', __('Invalid contact email.'));
        redirect($editing ? "customers.php?id={$id}" : 'customers.php');
    }

    if ($editing) {
        $stmt = db()->prepare(
            'UPDATE customers
             SET customer_name=?, description=?, address=?, contact_phone=?, contact_email=?, customer_code=?, remark=?
             WHERE id=? AND company_id=?'
        );
        $stmt->bind_param(
            'sssssssii',
            $customerName,
            $description,
            $address,
            $contactPhone,
            $contactEmail,
            $customerCode,
            $remark,
            $id,
            $companyId
        );
        try {
            $stmt->execute();
            set_flash('success', __('Customer updated.'));
            redirect('customers.php');
        } catch (Throwable $e) {
            set_flash('error', __('Customer code must be unique.'));
            redirect("customers.php?id={$id}");
        }
    }

    $stmt = db()->prepare(
        'INSERT INTO customers (company_id, customer_name, description, address, contact_phone, contact_email, customer_code, remark)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssssss', $companyId, $customerName, $description, $address, $contactPhone, $contactEmail, $customerCode, $remark);
    try {
        $stmt->execute();
        set_flash('success', __('Customer created.'));
    } catch (Throwable $e) {
        set_flash('error', __('Customer code must be unique.'));
    }
    redirect('customers.php');
}

$stmt = db()->prepare('SELECT * FROM customers WHERE company_id = ? ORDER BY customer_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$customers = $stmt->get_result();
$title = page_title(__('Customers'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Customers')) ?></h1>

<section class="card">
    <h2><?= $editing ? e(__('Edit Customer')) : e(__('Add Customer')) ?></h2>
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <label><?= e(__('Customer Name')) ?>
            <input type="text" name="customer_name" value="<?= e((string) $customer['customer_name']) ?>" required>
        </label>
        <label><?= e(__('Description')) ?>
            <textarea name="description" rows="2"><?= e((string) $customer['description']) ?></textarea>
        </label>
        <label><?= e(__('Address')) ?>
            <input type="text" name="address" value="<?= e((string) $customer['address']) ?>">
        </label>
        <label><?= e(__('Phone')) ?>
            <input type="text" name="contact_phone" value="<?= e((string) $customer['contact_phone']) ?>">
        </label>
        <label><?= e(__('Email')) ?>
            <input type="email" name="contact_email" value="<?= e((string) $customer['contact_email']) ?>">
        </label>
        <label><?= e(__('Customer Code')) ?>
            <input type="text" name="customer_code" value="<?= e((string) $customer['customer_code']) ?>" required>
        </label>
        <label><?= e(__('Remark')) ?>
            <textarea name="remark" rows="2"><?= e((string) $customer['remark']) ?></textarea>
        </label>
        <div class="inline-form">
            <button type="submit"><?= $editing ? e(__('Update Customer')) : e(__('Create Customer')) ?></button>
            <?php if ($editing): ?>
                <a class="link-btn" href="customers.php"><?= e(__('Cancel')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Customer List')) ?></h2>
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th><?= e(__('Name')) ?></th>
                <th><?= e(__('Code')) ?></th>
                <th><?= e(__('Phone')) ?></th>
                <th><?= e(__('Email')) ?></th>
                <th><?= e(__('Address')) ?></th>
                <th><?= e(__('Remark')) ?></th>
                <th><?= e(__('Action')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($customers->num_rows === 0): ?>
                <tr>
                    <td colspan="7"><?= e(__('No customers yet.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($row = $customers->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['customer_name']) ?></td>
                        <td><?= e($row['customer_code']) ?></td>
                        <td><?= e($row['contact_phone']) ?></td>
                        <td><?= e($row['contact_email']) ?></td>
                        <td><?= e($row['address']) ?></td>
                        <td><?= e($row['remark']) ?></td>
                        <td>
                            <a href="customer_view.php?id=<?= e((string) $row['id']) ?>" class="view-link"><?= e(__('View')) ?></a>
                            <a href="customers.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Edit')) ?></a>
                            <form method="post" class="inline-form"
                                onsubmit="return confirm('<?= e(__('Delete this customer?')) ?>')">
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