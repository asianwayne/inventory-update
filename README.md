# Inventory Management Web App (PHP + MySQL)

Simple inventory management system built with PHP, MySQL, HTML, CSS, and JavaScript.

## Features
- Login/logout authentication
- Dashboard with totals and low-stock insights
- Product create/edit/delete
- Product image upload
- Category creation directly from product form
- Product list with pagination and filters
- Settings page for basic system settings
- Role-based access (`admin`, `staff`)
- Stock movement ledger (initial, purchase in, adjustments, delete out)
- Purchase receiving page that updates stock and weighted purchase cost
- Admin user management page
- Dedicated supplier master page and supplier dropdown selection in product/receiving pages
- Dedicated customer master page and customer dropdown selection in sales orders
- Purchase module: purchase orders, PO items, goods receipts, PO status workflow
- Sales module: sales orders, order items, shipment stock deduction workflow
- Invoices module: invoice generation and payment workflow
- Returns module: sales/purchase returns with approval/completion workflow
- Performance pass: added high-impact indexes + paginated/filterable operational lists

## Requirements
- PHP 8.0+
- MySQL 5.7+ or MariaDB equivalent
- Web server (Apache/Nginx) or `php -S`

## Setup
1. Create a database (example: `inventory_app`).
2. Import `database.sql`.
3. Update database credentials in `config/config.php`.
4. Ensure `uploads/` is writable by PHP.
5. Open `login.php`.

## Default Login
- Email: `admin@example.com`
- Password: `admin123`
- Role: `admin`

Change this password after first login.

## Existing Database Migration
The schema has expanded significantly (purchase/sales/invoice/returns modules and workflow tables).

Recommended:
1. Backup your current database.
2. Re-apply the full `database.sql` on a fresh database.
3. Migrate your legacy data as needed.
