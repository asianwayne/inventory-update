<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Translate a string to Simplified Chinese.
 */
function __(string $text): string
{
    static $translations = null;
    if ($translations === null) {
        $translations = require __DIR__ . '/lang.php';
    }
    if (!array_key_exists($text, $translations)) {
        return $text;
    }
    $translated = $translations[$text];
    if (!is_string($translated) || trim($translated) === '') {
        return $text;
    }
    return $translated;
}

function redirect(string $path): void
{
    header("Location: {$path}");
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function ensure_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    $token = ensure_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

function validate_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && is_string($sessionToken) && hash_equals($sessionToken, $token);
}

function auth_user(): ?array
{
    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['user']['company_id']) && isset($_SESSION['user']['id'])) {
            $userId = (int) $_SESSION['user']['id'];
            $stmt = db()->prepare(
                'SELECT u.id, u.name, u.email, u.role, u.company_id, c.name AS company_name, c.code AS company_code
                 FROM users u
                 INNER JOIN companies c ON c.id = u.company_id
                 WHERE u.id = ? AND c.is_active = 1
                 LIMIT 1'
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user) {
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'] ?? 'staff',
                    'company_id' => (int) $user['company_id'],
                    'company_name' => (string) $user['company_name'],
                    'company_code' => (string) $user['company_code'],
                ];
            }
        }
        return $_SESSION['user'];
    }

    // Check for remember me cookie
    $token = $_COOKIE['remember_me'] ?? null;
    if ($token) {
        $stmt = db()->prepare(
            'SELECT u.id, u.name, u.email, u.role, u.company_id, c.name AS company_name, c.code AS company_code
             FROM users u
             INNER JOIN companies c ON c.id = u.company_id
             WHERE u.remember_token = ? AND c.is_active = 1
             LIMIT 1'
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'] ?? 'staff',
                'company_id' => (int) $user['company_id'],
                'company_name' => (string) $user['company_name'],
                'company_code' => (string) $user['company_code'],
            ];
            return $_SESSION['user'];
        } else {
            // Invalid token, clear cookie
            setcookie('remember_me', '', time() - 3600, '/');
        }
    }

    return null;
}

function current_company_id(): ?int
{
    $user = auth_user();
    if (!$user || !isset($user['company_id'])) {
        return null;
    }
    return (int) $user['company_id'];
}

function current_company_name(): ?string
{
    $user = auth_user();
    if (!$user || !isset($user['company_name'])) {
        return null;
    }
    return (string) $user['company_name'];
}

function require_company_id(): int
{
    $companyId = current_company_id();
    if ($companyId === null || $companyId <= 0) {
        throw new RuntimeException('Missing company context.');
    }
    return $companyId;
}

function require_auth(): void
{
    if (auth_user() === null) {
        redirect('login.php');
    }
}

function has_permission(string $permission): bool
{
    $user = auth_user();
    if (!$user) {
        return false;
    }

    $role = (string) ($user['role'] ?? 'staff');
    $permissions = [
        'admin' => [
            'view_dashboard',
            'manage_products',
            'manage_suppliers',
            'manage_customers',
            'manage_purchases',
            'manage_sales',
            'manage_quotations',
            'manage_invoices',
            'manage_returns',
            'delete_product',
            'view_movements',
            'receive_purchase',
            'manage_settings',
            'manage_users',
            'approve_orders',
        ],
        'staff' => [
            'view_dashboard',
            'manage_products',
            'manage_suppliers',
            'manage_customers',
            'manage_purchases',
            'manage_sales',
            'manage_quotations',
            'manage_invoices',
            'manage_returns',
            'view_movements',
            'receive_purchase',
            'delete_product',
        ],
    ];

    return in_array($permission, $permissions[$role] ?? [], true);
}

function require_permission($permission): void
{
    require_auth();
    $perms = is_array($permission) ? $permission : [$permission];
    $granted = false;
    foreach ($perms as $p) {
        if (has_permission($p)) {
            $granted = true;
            break;
        }
    }
    if (!$granted) {
        set_flash('error', __('You do not have permission to perform this action.'));
        redirect('dashboard.php');
    }
}

function current_user_id(): ?int
{
    $user = auth_user();
    if (!$user || !isset($user['id'])) {
        return null;
    }
    return (int) $user['id'];
}

function log_stock_movement(
    int $productId,
    string $productName,
    string $sku,
    string $movementType,
    int $qtyChange,
    int $qtyBefore,
    int $qtyAfter,
    ?float $unitCost = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $note = null
): void {
    $createdBy = current_user_id();
    $companyId = require_company_id();
    $stmt = db()->prepare(
        'INSERT INTO stock_movements
        (company_id, product_id, product_name, sku, movement_type, qty_change, qty_before, qty_after, unit_cost, reference_type, reference_id, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iisssiiidsisi',
        $companyId,
        $productId,
        $productName,
        $sku,
        $movementType,
        $qtyChange,
        $qtyBefore,
        $qtyAfter,
        $unitCost,
        $referenceType,
        $referenceId,
        $note,
        $createdBy
    );
    $stmt->execute();
}

function setting(string $key, ?string $fallback = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $companyId = current_company_id();
        if ($companyId !== null && $companyId > 0) {
            $stmt = db()->prepare('SELECT `key`, `value` FROM settings WHERE company_id = ?');
            $stmt->bind_param('i', $companyId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $cache[$row['key']] = $row['value'];
            }
        }
    }
    return $cache[$key] ?? $fallback;
}

function format_money(float $amount): string
{
    $currency = setting('currency_symbol', '$') ?? '$';
    return $currency . number_format($amount, 2);
}

function page_title(string $title): string
{
    $appName = setting('app_name', APP_NAME) ?? APP_NAME;
    return "{$title} | {$appName}";
}

function can_transition_status(string $entity, string $from, string $to): bool
{
    $map = [
        'purchase_order' => [
            'draft' => ['approved', 'cancelled'],
            'approved' => ['partial_received', 'received', 'cancelled'],
            'partial_received' => ['received', 'cancelled'],
            'received' => ['closed'],
            'closed' => [],
            'cancelled' => [],
        ],
        'sales_order' => [
            'draft' => ['confirmed', 'cancelled'],
            'confirmed' => ['shipped', 'cancelled'],
            'shipped' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ],
        'invoice' => [
            'draft' => ['issued', 'void'],
            'issued' => ['partial_paid', 'paid', 'void'],
            'partial_paid' => ['paid', 'void'],
            'paid' => [],
            'void' => [],
        ],
        'return' => [
            'requested' => ['approved', 'rejected'],
            'approved' => ['completed', 'rejected'],
            'rejected' => [],
            'completed' => [],
        ],
    ];

    return in_array($to, $map[$entity][$from] ?? [], true);
}

function generate_doc_number(string $prefix, string $table, string $column = 'id'): string
{
    $companyId = require_company_id();
    $stmt = db()->prepare("SELECT COALESCE(MAX({$column}), 0) + 1 AS next_id FROM {$table} WHERE company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $next = (int) ($stmt->get_result()->fetch_assoc()['next_id'] ?? 1);
    return sprintf('%s-%s%05d', $prefix, date('Ymd'), $next);
}

function ship_sales_order(int $salesOrderId): void
{
    $conn = db();
    $companyId = require_company_id();
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('SELECT id, so_number, status, stock_deducted FROM sales_orders WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $salesOrderId, $companyId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Sales order not found.');
        }
        if ((string) $order['status'] !== 'confirmed') {
            throw new RuntimeException('Only confirmed sales orders can be shipped.');
        }
        if ((int) $order['stock_deducted'] === 1) {
            throw new RuntimeException('Stock already deducted for this order.');
        }

        $stmt = $conn->prepare(
            'SELECT soi.product_id, soi.qty, p.product_name, p.sku, p.quantity
             FROM sales_order_items soi
             INNER JOIN products p ON p.id = soi.product_id
             WHERE soi.sales_order_id = ? AND soi.company_id = ? AND p.company_id = ?
             FOR UPDATE'
        );
        $stmt->bind_param('iii', $salesOrderId, $companyId, $companyId);
        $stmt->execute();
        $items = $stmt->get_result();
        if ($items->num_rows === 0) {
            throw new RuntimeException('Sales order has no items.');
        }

        $rows = [];
        while ($row = $items->fetch_assoc()) {
            if ((int) $row['quantity'] < (int) $row['qty']) {
                throw new RuntimeException('Insufficient stock for ' . $row['product_name']);
            }
            $rows[] = $row;
        }

        foreach ($rows as $row) {
            $oldQty = (int) $row['quantity'];
            $delta = (int) $row['qty'];
            $newQty = $oldQty - $delta;
            $stmt = $conn->prepare('UPDATE products SET quantity = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('iii', $newQty, $row['product_id'], $companyId);
            $stmt->execute();

            log_stock_movement(
                (int) $row['product_id'],
                (string) $row['product_name'],
                (string) $row['sku'],
                'adjustment_out',
                -$delta,
                $oldQty,
                $newQty,
                null,
                'sales_order',
                $salesOrderId,
                'Shipment for ' . $order['so_number']
            );
        }

        $stmt = $conn->prepare('UPDATE sales_orders SET status = ?, stock_deducted = 1 WHERE id = ? AND company_id = ?');
        $status = 'shipped';
        $stmt->bind_param('sii', $status, $salesOrderId, $companyId);
        $stmt->execute();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
