CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    code VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_companies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    password_hash VARCHAR(255) NOT NULL,
    remember_token VARCHAR(128) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_company_email (company_id, email),
    KEY idx_users_company (company_id),
    CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_company_name (company_id, name),
    KEY idx_categories_company (company_id),
    CONSTRAINT fk_categories_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    supplier_name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    address VARCHAR(255) NULL,
    contact_phone VARCHAR(60) NULL,
    contact_email VARCHAR(150) NULL,
    supplier_code VARCHAR(120) NOT NULL,
    remark TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppliers_company_code (company_id, supplier_code),
    KEY idx_suppliers_company (company_id),
    CONSTRAINT fk_suppliers_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    customer_name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    address VARCHAR(255) NULL,
    contact_phone VARCHAR(60) NULL,
    contact_email VARCHAR(150) NULL,
    customer_code VARCHAR(120) NOT NULL,
    remark TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_company_code (company_id, customer_code),
    KEY idx_customers_company (company_id),
    CONSTRAINT fk_customers_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_name VARCHAR(180) NOT NULL,
    image_path VARCHAR(255) NULL,
    description TEXT NULL,
    application VARCHAR(255) NULL,
    category_id INT NOT NULL,
    sku VARCHAR(120) NOT NULL,
    oem_number VARCHAR(120) NULL,
    quantity INT NOT NULL DEFAULT 0,
    purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    supplier_id INT NULL,
    supplier VARCHAR(180) NULL,
    remark TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_company_sku (company_id, sku),
    INDEX idx_products_company_category (company_id, category_id),
    INDEX idx_products_company_supplier (company_id, supplier_id),
    INDEX idx_products_company_created_at (company_id, created_at),
    CONSTRAINT fk_products_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_products_categories FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    `key` VARCHAR(120) NOT NULL,
    `value` TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settings_company_key (company_id, `key`),
    KEY idx_settings_company (company_id),
    CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT NOT NULL,
    received_qty INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL,
    supplier_id INT NULL,
    supplier VARCHAR(180) NULL,
    reference_no VARCHAR(120) NULL,
    remark TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_purchase_receipts_company_product (company_id, product_id),
    INDEX idx_purchase_receipts_company_supplier (company_id, supplier_id),
    INDEX idx_purchase_receipts_company_created_at (company_id, created_at),
    CONSTRAINT fk_purchase_receipts_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_purchase_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_purchase_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(180) NOT NULL,
    sku VARCHAR(120) NULL,
    movement_type ENUM('initial','purchase_in','adjustment_in','adjustment_out','delete_out') NOT NULL,
    qty_change INT NOT NULL,
    qty_before INT NOT NULL,
    qty_after INT NOT NULL,
    unit_cost DECIMAL(12,2) NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT NULL,
    note VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_movements_company_product_date (company_id, product_id, created_at),
    INDEX idx_stock_movements_company_type_date (company_id, movement_type, created_at),
    INDEX idx_stock_movements_company_reference (company_id, reference_type, reference_id),
    CONSTRAINT fk_stock_movements_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_move_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_move_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    po_number VARCHAR(60) NOT NULL,
    supplier_id INT NOT NULL,
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    status ENUM('draft','approved','partial_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    remark TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_orders_company_number (company_id, po_number),
    INDEX idx_purchase_orders_company_status_date (company_id, status, order_date),
    INDEX idx_purchase_orders_company_supplier_date (company_id, supplier_id, order_date),
    CONSTRAINT fk_purchase_orders_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_po_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    purchase_order_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    received_qty INT NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_poi_company_po_id (company_id, purchase_order_id),
    INDEX idx_poi_company_product_id (company_id, product_id),
    CONSTRAINT fk_purchase_order_items_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goods_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    gr_number VARCHAR(60) NOT NULL,
    purchase_order_id INT NOT NULL,
    status ENUM('posted','void') NOT NULL DEFAULT 'posted',
    total_qty INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    remark TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_goods_receipts_company_number (company_id, gr_number),
    INDEX idx_goods_receipts_company_po_date (company_id, purchase_order_id, created_at),
    CONSTRAINT fk_goods_receipts_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gr_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_gr_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goods_receipt_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    goods_receipt_id INT NOT NULL,
    purchase_order_item_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    INDEX idx_gri_company_gr (company_id, goods_receipt_id),
    CONSTRAINT fk_goods_receipt_items_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gri_gr FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gri_poi FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_gri_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    so_number VARCHAR(60) NOT NULL,
    customer_id INT NULL,
    customer_name VARCHAR(180) NOT NULL,
    customer_phone VARCHAR(60) NULL,
    customer_email VARCHAR(150) NULL,
    order_date DATE NOT NULL,
    status ENUM('draft','confirmed','shipped','completed','cancelled') NOT NULL DEFAULT 'draft',
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock_deducted TINYINT(1) NOT NULL DEFAULT 0,
    remark TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_orders_company_number (company_id, so_number),
    INDEX idx_sales_orders_company_status_date (company_id, status, order_date),
    INDEX idx_sales_orders_company_customer_date (company_id, customer_id, order_date),
    CONSTRAINT fk_sales_orders_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_so_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_so_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    sales_order_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_soi_company_so_id (company_id, sales_order_id),
    INDEX idx_soi_company_product_id (company_id, product_id),
    CONSTRAINT fk_sales_order_items_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_soi_so FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_soi_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    invoice_number VARCHAR(60) NOT NULL,
    sales_order_id INT NOT NULL,
    status ENUM('draft','issued','partial_paid','paid','void') NOT NULL DEFAULT 'issued',
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    issue_date DATE NOT NULL,
    due_date DATE NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoices_company_number (company_id, invoice_number),
    UNIQUE KEY uq_invoices_company_so (company_id, sales_order_id),
    INDEX idx_invoices_company_status_issue (company_id, status, issue_date),
    CONSTRAINT fk_invoices_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_invoice_so FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_invoice_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    return_number VARCHAR(60) NOT NULL,
    return_type ENUM('sales_return','purchase_return') NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    unit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('requested','approved','rejected','completed') NOT NULL DEFAULT 'requested',
    reference_type VARCHAR(60) NULL,
    reference_id INT NULL,
    note TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_returns_company_number (company_id, return_number),
    INDEX idx_returns_company_status_date (company_id, status, created_at),
    INDEX idx_returns_company_type_date (company_id, return_type, created_at),
    INDEX idx_returns_company_product_date (company_id, product_id, created_at),
    CONSTRAINT fk_returns_company FOREIGN KEY (company_id) REFERENCES companies(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_return_product FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_return_user FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO companies (name, code, is_active)
SELECT 'Default Company', 'DEFAULT', 1
WHERE NOT EXISTS (
    SELECT 1 FROM companies WHERE code = 'DEFAULT'
);

INSERT INTO users (company_id, name, email, role, password_hash)
SELECT c.id, 'Administrator', 'admin@example.com', 'admin', '$2y$10$Ez4taMrRccwRw4yR/RqmCe9miLFYnDEMkEYwaVgvULunRH2qCpdfu'
FROM companies c
WHERE c.code = 'DEFAULT'
  AND NOT EXISTS (
    SELECT 1 FROM users u WHERE u.company_id = c.id AND u.email = 'admin@example.com'
  );

INSERT INTO categories (company_id, name)
SELECT c.id, 'General'
FROM companies c
WHERE c.code = 'DEFAULT'
  AND NOT EXISTS (
    SELECT 1 FROM categories x WHERE x.company_id = c.id AND x.name = 'General'
  );

INSERT INTO settings (company_id, `key`, `value`)
SELECT c.id, 'app_name', 'Inventory Management'
FROM companies c
WHERE c.code = 'DEFAULT'
  AND NOT EXISTS (SELECT 1 FROM settings s WHERE s.company_id = c.id AND s.`key` = 'app_name');

INSERT INTO settings (company_id, `key`, `value`)
SELECT c.id, 'currency_symbol', '$'
FROM companies c
WHERE c.code = 'DEFAULT'
  AND NOT EXISTS (SELECT 1 FROM settings s WHERE s.company_id = c.id AND s.`key` = 'currency_symbol');

INSERT INTO settings (company_id, `key`, `value`)
SELECT c.id, 'low_stock_threshold', '5'
FROM companies c
WHERE c.code = 'DEFAULT'
  AND NOT EXISTS (SELECT 1 FROM settings s WHERE s.company_id = c.id AND s.`key` = 'low_stock_threshold');

INSERT INTO settings (company_id, `key`, `value`)
SELECT c.id, 'allow_registration', '1'
FROM companies c
WHERE c.code = 'DEFAULT'
  AND NOT EXISTS (SELECT 1 FROM settings s WHERE s.company_id = c.id AND s.`key` = 'allow_registration');
