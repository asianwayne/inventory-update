# Inventory Management System - Detailed Usage Guide

This document explains how to run and use the inventory system end-to-end.

## 1. System Requirements
- PHP 8.0+
- MySQL 5.7+ (or MariaDB equivalent)
- Web server (Apache/Nginx) or PHP built-in server

## 2. Initial Setup
1. Create a MySQL database (example: `inventory_app`).
2. Import `database.sql` into that database.
3. Edit `config/config.php` and set:
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
4. Ensure `uploads/` is writable by PHP.
5. Start server from project root:
```powershell
php -S localhost:8000
```
6. Open:
`http://localhost:8000/login.php`

## 3. Default Login
- Email: `admin@example.com`
- Password: `admin123`
- Role: `admin`

Change this account password policy in your environment as needed.

## 4. Main Navigation
After login, the left sidebar shows modules based on permissions.

Common menus:
- Dashboard
- Create Product
- Products List
- Suppliers
- Customers
- Purchase Receive
- Purchase Orders
- Goods Receipts
- Sales Orders
- Invoices
- Returns
- Stock Movements
- Users (admin)
- Settings (admin)

## 5. Roles and Permissions
Roles:
- `admin`: full access (users/settings/delete product included)
- `staff`: operational modules without admin-only management pages

Permissions are enforced server-side in page controllers.

## 6. Core Master Data

### 6.1 Suppliers (`suppliers.php`)
Use this as supplier master data.

Fields:
- Supplier Name
- Description
- Address
- Contact Phone
- Contact Email
- Supplier Code (unique)
- Remark

Usage:
1. Create supplier records first.
2. Select suppliers in product and purchase flows from dropdowns.

### 6.2 Customers (`customers.php`)
Use this as customer master data.

Fields:
- Customer Name
- Description
- Address
- Contact Phone
- Contact Email
- Customer Code (unique)
- Remark

Usage:
1. Create customer records first.
2. Select customers in sales order creation.

### 6.3 Products (`product_form.php`, `products.php`)
Fields supported:
- Product Name
- Product Image
- Description
- Application Areas
- Category
- SKU
- OEM Number
- Quantity
- Purchase Price
- Sale Price
- Supplier
- Remark

Features:
- Create/Edit/Delete
- Image upload
- Click image to open full-size preview
- Category can be added from product form
- List supports filtering + pagination

## 7. Dashboard (`dashboard.php`)
Shows:
- Total products
- Total quantity
- Inventory value
- Low-stock list
- Recent stock movements

Low-stock threshold can be adjusted in Settings.

## 8. Purchase Module

### 8.1 Purchase Orders (`purchase_orders.php`)
Create PO headers with:
- Supplier
- Order Date
- Expected Date
- Remark

PO statuses:
- `draft`
- `approved`
- `partial_received`
- `received`
- `closed`
- `cancelled`

### 8.2 Purchase Order Detail (`purchase_order_view.php`)
Actions:
1. Add PO items (product, qty, unit cost).
2. Receive line items (goods receipt posting).

When receiving:
- System creates a goods receipt record.
- Product quantity is increased.
- Purchase price is recalculated using weighted average.
- Stock movement is logged.
- PO status auto-updates to partial/received based on received qty.

### 8.3 Goods Receipts (`goods_receipts.php`)
Read-only list of posted receipts:
- GR number
- PO reference
- Supplier
- Qty/amount
- User/date

### 8.4 Purchase Receive (`purchase_receive.php`)
Quick receiving flow (outside PO flow):
- Select product
- Enter qty and unit cost
- Optional supplier/reference/remark

This also updates stock and logs movements.

## 9. Sales Module

### 9.1 Sales Orders (`sales_orders.php`)
Create SO headers with:
- Customer (from master)
- Order Date
- Remark

SO statuses:
- `draft`
- `confirmed`
- `shipped`
- `completed`
- `cancelled`

### 9.2 Sales Order Detail (`sales_order_view.php`)
Actions:
1. Add SO items (product, qty, unit price).
2. Ship order (only from confirmed status).
3. Create invoice.

Shipment behavior:
- Validates stock availability for all items.
- Deducts product quantities.
- Logs stock movement.
- Sets order status to `shipped`.

## 10. Invoice Module (`invoices.php`)
Invoices are generated from sales orders.

Invoice statuses:
- `draft`
- `issued`
- `partial_paid`
- `paid`
- `void`

Payment action:
- Apply payment amount to invoice.
- Paid amount accumulates.
- Status moves to partial/paid automatically.

## 11. Returns Module (`returns.php`)
Return types:
- `sales_return` (stock goes back in)
- `purchase_return` (stock goes out)

Return statuses:
- `requested`
- `approved`
- `rejected`
- `completed`

Behavior:
- Stock is adjusted only when status becomes `completed`.
- Stock movement is logged with reference to return record.

## 12. Stock Movement Ledger (`stock_movements.php`)
Tracks every quantity-impacting transaction:
- Initial stock
- Purchase in
- Adjustments in/out
- Delete out
- Ship/return-related adjustments

Filter by:
- Product/SKU search
- Movement type
- Pagination

## 13. Settings (`settings.php`)
Admin configurable:
- Application name
- Currency symbol
- Low stock threshold

## 14. Users (`users.php`)
Admin actions:
- Create users
- Assign role (`admin` / `staff`)

## 15. Quantity Sync Rules (Important)
Stock does **not** change on order creation.
Stock changes only on movement events:
- Purchase receipt posted -> quantity increases
- Sales shipment posted -> quantity decreases
- Completed sales return -> quantity increases
- Completed purchase return -> quantity decreases
- Manual product quantity edits -> adjustment movement logged

## 16. Image Upload Rules
- Accepted types: JPG, PNG, WEBP, GIF
- Max size: 3MB
- Stored under `uploads/`

If `mime_content_type()` is unavailable, the app falls back to other MIME detection methods.

## 17. Common Troubleshooting

### 17.1 HTTP 500 on new modules
Cause:
- Database schema is behind application code.

Fix:
1. Backup DB.
2. Re-import latest `database.sql` into a fresh DB (recommended).
3. Or apply missing ALTER/CREATE statements carefully.

### 17.2 Cannot login
Check:
- DB credentials in `config/config.php`
- `users` table exists
- Default admin row exists

### 17.3 Product image fails upload
Check:
- `uploads/` writable permission
- File size/type constraints

### 17.4 Foreign key errors
Cause:
- Deleting referenced suppliers/customers/orders without handling existing links.

Fix:
- Unlink/reassign related records first, or use allowed workflows.

## 18. Recommended Operational Flow
1. Configure settings.
2. Create suppliers.
3. Create customers.
4. Create categories and products.
5. Create and approve purchase orders.
6. Post goods receipts.
7. Create and confirm sales orders.
8. Ship sales orders.
9. Create invoices and post payments.
10. Process returns through approval/completion.
11. Audit stock movements regularly.

## 19. Security and Production Notes
- Use HTTPS in production.
- Replace default admin password immediately.
- Restrict DB user permissions.
- Configure proper backups for database and uploads.
- Add server-level hardening (headers, rate limits, firewall) as needed.
