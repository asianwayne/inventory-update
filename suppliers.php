<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_suppliers');
$companyId = require_company_id();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editing = $id > 0;
$supplier = [
    'supplier_name' => '',
    'description' => '',
    'address' => '',
    'contact_phone' => '',
    'contact_email' => '',
    'supplier_code' => '',
    'remark' => '',
];

if ($editing) {
    $stmt = db()->prepare('SELECT * FROM suppliers WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->bind_param('ii', $id, $companyId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    if (!$found) {
        set_flash('error', __('Supplier not found.'));
        redirect('suppliers.php');
    }
    $supplier = array_merge($supplier, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        set_flash('error', __('Invalid request token.'));
        redirect($editing ? "suppliers.php?id={$id}" : 'suppliers.php');
    }

    if (isset($_POST['delete_id'])) {
        $deleteId = (int) $_POST['delete_id'];
        if ($deleteId <= 0) {
            set_flash('error', __('Invalid supplier.'));
            redirect('suppliers.php');
        }
        $stmt = db()->prepare('DELETE FROM suppliers WHERE id = ? AND company_id = ?');
        $stmt->bind_param('ii', $deleteId, $companyId);
        try {
            $stmt->execute();
            set_flash('success', __('Supplier deleted.'));
        } catch (Throwable $e) {
            set_flash('error', __('Cannot delete supplier used by products or receipts.'));
        }
        redirect('suppliers.php');
    }

    $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $supplierCode = trim((string) ($_POST['supplier_code'] ?? ''));
    $remark = trim((string) ($_POST['remark'] ?? ''));

    if ($supplierName === '' || $supplierCode === '') {
        set_flash('error', __('Supplier name and supplier code are required.'));
        redirect($editing ? "suppliers.php?id={$id}" : 'suppliers.php');
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', __('Invalid contact email.'));
        redirect($editing ? "suppliers.php?id={$id}" : 'suppliers.php');
    }

    if ($editing) {
        $stmt = db()->prepare(
            'UPDATE suppliers
             SET supplier_name=?, description=?, address=?, contact_phone=?, contact_email=?, supplier_code=?, remark=?
             WHERE id=? AND company_id=?'
        );
        $stmt->bind_param(
            'sssssssii',
            $supplierName,
            $description,
            $address,
            $contactPhone,
            $contactEmail,
            $supplierCode,
            $remark,
            $id,
            $companyId
        );
        try {
            $stmt->execute();
            set_flash('success', __('Supplier updated.'));
            redirect('suppliers.php');
        } catch (Throwable $e) {
            set_flash('error', __('Supplier code must be unique.'));
            redirect("suppliers.php?id={$id}");
        }
    }

    $stmt = db()->prepare(
        'INSERT INTO suppliers (company_id, supplier_name, description, address, contact_phone, contact_email, supplier_code, remark)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssssss', $companyId, $supplierName, $description, $address, $contactPhone, $contactEmail, $supplierCode, $remark);
    try {
        $stmt->execute();
        set_flash('success', __('Supplier created.'));
    } catch (Throwable $e) {
        set_flash('error', __('Supplier code must be unique.'));
    }
    redirect('suppliers.php');
}

$stmt = db()->prepare('SELECT * FROM suppliers WHERE company_id = ? ORDER BY supplier_name ASC');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$suppliers = $stmt->get_result();
$title = page_title(__('Suppliers'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Suppliers')) ?></h1>

<section class="card">
    <h2><?= $editing ? e(__('Edit Supplier')) : e(__('Add Supplier')) ?></h2>
    <form method="post" class="stack">
        <?= csrf_input() ?>
        <label><?= e(__('Supplier Name')) ?>
            <input type="text" name="supplier_name" value="<?= e((string) $supplier['supplier_name']) ?>" required>
        </label>
        <label><?= e(__('Description')) ?>
            <textarea name="description" rows="2"><?= e((string) $supplier['description']) ?></textarea>
        </label>
        <label><?= e(__('Address')) ?>
            <input type="text" name="address" value="<?= e((string) $supplier['address']) ?>">
        </label>
        <label><?= e(__('Phone')) ?>
            <input type="text" name="contact_phone" value="<?= e((string) $supplier['contact_phone']) ?>">
        </label>
        <label><?= e(__('Email')) ?>
            <input type="email" name="contact_email" value="<?= e((string) $supplier['contact_email']) ?>">
        </label>
        <label><?= e(__('Supplier Code')) ?>
            <input type="text" name="supplier_code" value="<?= e((string) $supplier['supplier_code']) ?>" required>
        </label>
        <label><?= e(__('Remark')) ?>
            <textarea name="remark" rows="2"><?= e((string) $supplier['remark']) ?></textarea>
        </label>
        <div class="inline-form">
            <button type="submit"><?= $editing ? e(__('Update Supplier')) : e(__('Create Supplier')) ?></button>
            <?php if ($editing): ?>
                <a class="link-btn" href="suppliers.php"><?= e(__('Cancel')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="card">
    <h2><?= e(__('Supplier List')) ?></h2>
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
            <?php if ($suppliers->num_rows === 0): ?>
                <tr>
                    <td colspan="7"><?= e(__('No suppliers yet.')) ?></td>
                </tr>
            <?php else: ?>
                <?php while ($row = $suppliers->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row['supplier_name']) ?></td>
                        <td><?= e($row['supplier_code']) ?></td>
                        <td><?= e($row['contact_phone']) ?></td>
                        <td><?= e($row['contact_email']) ?></td>
                        <td><?= e($row['address']) ?></td>
                        <td><?= e($row['remark']) ?></td>
                        <td>
                            <a href="suppliers.php?id=<?= e((string) $row['id']) ?>"><?= e(__('Edit')) ?></a>
                            <form method="post" class="inline-form"
                                onsubmit="return confirm('<?= e(__('Delete this supplier?')) ?>')">
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
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>