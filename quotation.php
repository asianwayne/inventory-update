<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_permission('manage_quotations');
$companyId = require_company_id();

$stmt = db()->prepare(
    'SELECT id, product_name, sku, oem_number, application, sale_price
     FROM products
     WHERE company_id = ?
     ORDER BY product_name ASC'
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$products = $stmt->get_result();

$title = page_title(__('Price Quotation'));
require_once __DIR__ . '/includes/header.php';
?>
<h1><?= e(__('Price Quotation')) ?></h1>

<section class="card">
    <h2><?= e(__('Quotation Header')) ?></h2>
    <div class="grid-3 quote-meta-grid">
        <label><?= e(__('Quotation No')) ?>
            <input type="text" id="quoteNo" value="<?= e('QT-' . date('Ymd-His')) ?>">
        </label>
        <label><?= e(__('Quotation Date')) ?>
            <input type="date" id="quoteDate" value="<?= e(date('Y-m-d')) ?>">
        </label>
        <label><?= e(__('Valid Days')) ?>
            <input type="number" id="quoteValidDays" value="30" min="1" step="1">
        </label>
        <label><?= e(__('Customer Name')) ?>
            <input type="text" id="quoteCustomer" placeholder="<?= e(__('Enter customer name')) ?>">
        </label>
        <label><?= e(__('Prepared By')) ?>
            <input type="text" id="quotePreparedBy" value="<?= e((string) (auth_user()['name'] ?? '')) ?>">
        </label>
        <label><?= e(__('Currency')) ?>
            <input type="text" id="quoteCurrency" value="<?= e((string) (setting('currency_symbol', '$') ?? '$')) ?>">
        </label>
    </div>
    <label><?= e(__('Remark')) ?>
        <textarea id="quoteRemark" rows="2" placeholder="<?= e(__('Optional overall remark')) ?>"></textarea>
    </label>
</section>

<section class="card">
    <h2><?= e(__('Build Quotation Table')) ?></h2>
    <div class="quote-builder-tools">
        <label class="quote-product-picker"><?= e(__('Select Product')) ?>
            <select id="productPicker">
                <option value=""><?= e(__('Select product')) ?></option>
                <?php while ($product = $products->fetch_assoc()): ?>
                    <option value="<?= e((string) $product['id']) ?>"
                        data-name="<?= e((string) $product['product_name']) ?>"
                        data-sku="<?= e((string) $product['sku']) ?>"
                        data-oem="<?= e((string) $product['oem_number']) ?>"
                        data-application="<?= e((string) $product['application']) ?>"
                        data-price="<?= e((string) $product['sale_price']) ?>">
                        <?= e((string) $product['product_name']) ?> | SKU: <?= e((string) $product['sku']) ?> | OEM:
                        <?= e((string) $product['oem_number']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <button type="button" id="addProductBtn"><?= e(__('Add Product')) ?></button>
        <button type="button" class="secondary-btn" id="clearRowsBtn"><?= e(__('Clear')) ?></button>
    </div>

    <div class="table-responsive">
        <table id="quoteTableEditor">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= e(__('Product Name')) ?></th>
                    <th><?= e(__('SKU')) ?></th>
                    <th><?= e(__('OEM')) ?></th>
                    <th><?= e(__('Application')) ?></th>
                    <th><?= e(__('Unit Price')) ?></th>
                    <th><?= e(__('Qty')) ?></th>
                    <th><?= e(__('Line Total')) ?></th>
                    <th><?= e(__('Remark')) ?></th>
                    <th><?= e(__('Actions')) ?></th>
                </tr>
            </thead>
            <tbody id="quoteRowsBody">
                <tr id="quoteEmptyRow">
                    <td colspan="10"><?= e(__('No items yet.')) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="quote-summary">
        <div>
            <span><?= e(__('Total Qty')) ?>:</span>
            <strong id="summaryQty">0</strong>
        </div>
        <div>
            <span><?= e(__('Total Amount')) ?>:</span>
            <strong id="summaryAmount">0.00</strong>
        </div>
    </div>

    <div class="quote-actions">
        <button type="button" id="generateViewBtn"><?= e(__('Generate Table View')) ?></button>
        <button type="button" class="secondary-btn" id="downloadImageBtn"><?= e(__('Download Quotation Image')) ?></button>
    </div>
</section>

<section class="card" id="quotationPreviewCard">
    <h2><?= e(__('Quotation Preview')) ?></h2>
    <div id="quotationPreview" class="quotation-preview"></div>
</section>

<script>
(() => {
    const rows = [];
    const currencyInput = document.getElementById('quoteCurrency');
    const picker = document.getElementById('productPicker');
    const quoteRowsBody = document.getElementById('quoteRowsBody');
    const quoteEmptyRow = document.getElementById('quoteEmptyRow');
    const summaryQty = document.getElementById('summaryQty');
    const summaryAmount = document.getElementById('summaryAmount');
    const previewCard = document.getElementById('quotationPreviewCard');
    const previewEl = document.getElementById('quotationPreview');

    previewCard.style.display = 'none';

    function safeNumber(value, fallback = 0) {
        const parsed = Number(value);
        if (!Number.isFinite(parsed)) {
            return fallback;
        }
        return parsed;
    }

    function getCurrency() {
        const value = String(currencyInput.value || '').trim();
        return value === '' ? '$' : value;
    }

    function formatAmount(value) {
        return safeNumber(value, 0).toFixed(2);
    }

    function escHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escAttr(value) {
        return escHtml(value);
    }

    function recalcSummary() {
        let totalQty = 0;
        let totalAmount = 0;
        rows.forEach((row) => {
            totalQty += safeNumber(row.qty, 0);
            totalAmount += safeNumber(row.qty, 0) * safeNumber(row.price, 0);
        });
        summaryQty.textContent = String(totalQty);
        summaryAmount.textContent = getCurrency() + formatAmount(totalAmount);
    }

    function rebuildTable() {
        quoteRowsBody.innerHTML = '';
        if (rows.length === 0) {
            quoteRowsBody.appendChild(quoteEmptyRow);
            recalcSummary();
            return;
        }

        rows.forEach((row, idx) => {
            const tr = document.createElement('tr');
            const lineTotal = safeNumber(row.price, 0) * safeNumber(row.qty, 0);
            tr.innerHTML = `
                <td>${idx + 1}</td>
                <td><input type="text" value="${escAttr(row.name)}" data-field="name" data-index="${idx}"></td>
                <td><input type="text" value="${escAttr(row.sku)}" data-field="sku" data-index="${idx}"></td>
                <td><input type="text" value="${escAttr(row.oem)}" data-field="oem" data-index="${idx}"></td>
                <td><input type="text" value="${escAttr(row.application)}" data-field="application" data-index="${idx}"></td>
                <td><input type="number" min="0" step="0.01" value="${formatAmount(row.price)}" data-field="price" data-index="${idx}"></td>
                <td><input type="number" min="1" step="1" value="${row.qty}" data-field="qty" data-index="${idx}"></td>
                <td>${getCurrency()}${formatAmount(lineTotal)}</td>
                <td><input type="text" value="${escAttr(row.remark)}" data-field="remark" data-index="${idx}"></td>
                <td><button type="button" class="danger remove-row-btn" data-index="${idx}">X</button></td>
            `;
            quoteRowsBody.appendChild(tr);
        });

        recalcSummary();
    }

    function addSelectedProduct() {
        const selected = picker.options[picker.selectedIndex];
        if (!selected || !selected.value) {
            alert(<?= json_encode(__('Please select a product first.')) ?>);
            return;
        }

        rows.push({
            id: Number(selected.value),
            name: selected.dataset.name || '',
            sku: selected.dataset.sku || '',
            oem: selected.dataset.oem || '',
            application: selected.dataset.application || '',
            price: safeNumber(selected.dataset.price, 0),
            qty: 1,
            remark: ''
        });
        rebuildTable();
    }

    function quotationData() {
        const quoteNo = document.getElementById('quoteNo').value.trim();
        const quoteDate = document.getElementById('quoteDate').value;
        const quoteValidDays = safeNumber(document.getElementById('quoteValidDays').value, 30);
        const customer = document.getElementById('quoteCustomer').value.trim();
        const preparedBy = document.getElementById('quotePreparedBy').value.trim();
        const remark = document.getElementById('quoteRemark').value.trim();
        const totalQty = rows.reduce((sum, row) => sum + safeNumber(row.qty, 0), 0);
        const totalAmount = rows.reduce((sum, row) => sum + (safeNumber(row.qty, 0) * safeNumber(row.price, 0)), 0);

        return {
            quoteNo,
            quoteDate,
            quoteValidDays,
            customer,
            preparedBy,
            remark,
            currency: getCurrency(),
            totalQty,
            totalAmount,
            rows: rows.map((row) => ({
                ...row,
                price: safeNumber(row.price, 0),
                qty: Math.max(1, Math.round(safeNumber(row.qty, 1))),
            })),
        };
    }

    function renderPreview() {
        if (rows.length === 0) {
            previewCard.style.display = 'none';
            return;
        }

        const data = quotationData();
        const bodyRows = data.rows.map((row, idx) => {
            const lineTotal = row.price * row.qty;
            return `<tr>
                <td>${idx + 1}</td>
                <td>${escHtml(row.name)}</td>
                <td>${escHtml(row.sku)}</td>
                <td>${escHtml(row.oem)}</td>
                <td>${escHtml(row.application)}</td>
                <td>${data.currency}${formatAmount(row.price)}</td>
                <td>${row.qty}</td>
                <td>${data.currency}${formatAmount(lineTotal)}</td>
                <td>${escHtml(row.remark)}</td>
            </tr>`;
        }).join('');

        previewEl.innerHTML = `
            <div class="quotation-head">
                <h3>${<?= json_encode(__('Price Quotation'), JSON_UNESCAPED_UNICODE) ?>}</h3>
                <div>${<?= json_encode(__('Quotation No'), JSON_UNESCAPED_UNICODE) ?>}: ${escHtml(data.quoteNo)}</div>
                <div>${<?= json_encode(__('Quotation Date'), JSON_UNESCAPED_UNICODE) ?>}: ${escHtml(data.quoteDate)}</div>
                <div>${<?= json_encode(__('Valid Days'), JSON_UNESCAPED_UNICODE) ?>}: ${data.quoteValidDays}</div>
                <div>${<?= json_encode(__('Customer Name'), JSON_UNESCAPED_UNICODE) ?>}: ${escHtml(data.customer || '-')}</div>
                <div>${<?= json_encode(__('Prepared By'), JSON_UNESCAPED_UNICODE) ?>}: ${escHtml(data.preparedBy || '-')}</div>
            </div>
            <table class="quotation-output-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>${<?= json_encode(__('Product Name'), JSON_UNESCAPED_UNICODE) ?>}</th>
                        <th>${<?= json_encode(__('SKU'), JSON_UNESCAPED_UNICODE) ?>}</th>
                        <th>${<?= json_encode(__('OEM'), JSON_UNESCAPED_UNICODE) ?>}</th>
                        <th>${<?= json_encode(__('Application'), JSON_UNESCAPED_UNICODE) ?>}</th>
                        <th>${<?= json_encode(__('Unit Price'), JSON_UNESCAPED_UNICODE) ?>}</th>
                        <th>${<?= json_encode(__('Qty'), JSON_UNESCAPED_UNICODE) ?>}</th>
                        <th>${<?= json_encode(__('Line Total'), JSON_UNESCAPED_UNICODE) ?>}</th>
                        <th>${<?= json_encode(__('Remark'), JSON_UNESCAPED_UNICODE) ?>}</th>
                    </tr>
                </thead>
                <tbody>${bodyRows}</tbody>
            </table>
            <div class="quotation-foot">
                <div>${<?= json_encode(__('Total Qty'), JSON_UNESCAPED_UNICODE) ?>}: <strong>${data.totalQty}</strong></div>
                <div>${<?= json_encode(__('Total Amount'), JSON_UNESCAPED_UNICODE) ?>}: <strong>${data.currency}${formatAmount(data.totalAmount)}</strong></div>
                <div>${<?= json_encode(__('Remark'), JSON_UNESCAPED_UNICODE) ?>}: ${escHtml(data.remark || '-')}</div>
            </div>
        `;

        previewCard.style.display = 'block';
    }

    function wrapText(ctx, text, maxWidth) {
        const words = String(text || '').split(' ');
        const lines = [];
        let current = '';

        words.forEach((word) => {
            const probe = current ? `${current} ${word}` : word;
            if (ctx.measureText(probe).width > maxWidth && current) {
                lines.push(current);
                current = word;
            } else {
                current = probe;
            }
        });
        if (current) {
            lines.push(current);
        }
        if (lines.length === 0) {
            lines.push('');
        }
        return lines;
    }

    function downloadImage() {
        if (rows.length === 0) {
            alert(<?= json_encode(__('No items yet.')) ?>);
            return;
        }

        const data = quotationData();
        const headers = [
            '#',
            <?= json_encode(__('Product Name'), JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode(__('SKU'), JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode(__('OEM'), JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode(__('Application'), JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode(__('Unit Price'), JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode(__('Qty'), JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode(__('Line Total'), JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode(__('Remark'), JSON_UNESCAPED_UNICODE) ?>
        ];
        const colWidths = [40, 180, 90, 90, 170, 100, 60, 110, 170];
        const tableWidth = colWidths.reduce((sum, w) => sum + w, 0);
        const canvasWidth = tableWidth + 60;

        const testCanvas = document.createElement('canvas');
        const testCtx = testCanvas.getContext('2d');
        testCtx.font = '13px Inter, Arial, sans-serif';
        const rowHeights = data.rows.map((row) => {
            const cells = [
                String(row.name || ''),
                String(row.sku || ''),
                String(row.oem || ''),
                String(row.application || ''),
                `${data.currency}${formatAmount(row.price)}`,
                String(row.qty),
                `${data.currency}${formatAmount(row.price * row.qty)}`,
                String(row.remark || '')
            ];
            const lineCounts = cells.map((cell, idx) => {
                const colIdx = idx + 1;
                const maxWidth = colWidths[colIdx] - 10;
                return wrapText(testCtx, cell, maxWidth).length;
            });
            return Math.max(28, 18 * Math.max(...lineCounts));
        });

        const baseTop = 150;
        const tableHead = 32;
        const rowsHeight = rowHeights.reduce((sum, h) => sum + h, 0);
        const footerHeight = 80;
        const canvasHeight = baseTop + tableHead + rowsHeight + footerHeight;

        const scale = 2;
        const canvas = document.createElement('canvas');
        canvas.width = canvasWidth * scale;
        canvas.height = canvasHeight * scale;
        const ctx = canvas.getContext('2d');
        ctx.scale(scale, scale);

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvasWidth, canvasHeight);
        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 20px Inter, Arial, sans-serif';
        ctx.fillText(<?= json_encode(__('Price Quotation'), JSON_UNESCAPED_UNICODE) ?>, 30, 34);

        ctx.font = '13px Inter, Arial, sans-serif';
        ctx.fillText(`${<?= json_encode(__('Quotation No'), JSON_UNESCAPED_UNICODE) ?>}: ${data.quoteNo}`, 30, 62);
        ctx.fillText(`${<?= json_encode(__('Quotation Date'), JSON_UNESCAPED_UNICODE) ?>}: ${data.quoteDate}`, 30, 82);
        ctx.fillText(`${<?= json_encode(__('Valid Days'), JSON_UNESCAPED_UNICODE) ?>}: ${data.quoteValidDays}`, 30, 102);
        ctx.fillText(`${<?= json_encode(__('Customer Name'), JSON_UNESCAPED_UNICODE) ?>}: ${data.customer || '-'}`, 430, 62);
        ctx.fillText(`${<?= json_encode(__('Prepared By'), JSON_UNESCAPED_UNICODE) ?>}: ${data.preparedBy || '-'}`, 430, 82);

        let x = 30;
        let y = baseTop;
        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(x, y, tableWidth, tableHead);
        ctx.strokeStyle = '#cbd5e1';
        ctx.strokeRect(x, y, tableWidth, tableHead);
        ctx.fillStyle = '#334155';
        ctx.font = 'bold 12px Inter, Arial, sans-serif';

        headers.forEach((header, idx) => {
            ctx.fillText(header, x + 5, y + 21);
            x += colWidths[idx];
            if (idx < headers.length - 1) {
                ctx.beginPath();
                ctx.moveTo(x, y);
                ctx.lineTo(x, y + tableHead);
                ctx.stroke();
            }
        });

        y += tableHead;
        ctx.font = '12px Inter, Arial, sans-serif';
        ctx.fillStyle = '#0f172a';

        data.rows.forEach((row, idx) => {
            const rowHeight = rowHeights[idx];
            const values = [
                String(idx + 1),
                String(row.name || ''),
                String(row.sku || ''),
                String(row.oem || ''),
                String(row.application || ''),
                `${data.currency}${formatAmount(row.price)}`,
                String(row.qty),
                `${data.currency}${formatAmount(row.price * row.qty)}`,
                String(row.remark || '')
            ];

            let cellX = 30;
            ctx.strokeStyle = '#e2e8f0';
            ctx.strokeRect(cellX, y, tableWidth, rowHeight);
            values.forEach((value, colIdx) => {
                const cw = colWidths[colIdx];
                const lines = wrapText(ctx, value, cw - 10);
                lines.forEach((line, lineIdx) => {
                    ctx.fillText(line, cellX + 5, y + 17 + (lineIdx * 14));
                });
                cellX += cw;
                if (colIdx < values.length - 1) {
                    ctx.beginPath();
                    ctx.moveTo(cellX, y);
                    ctx.lineTo(cellX, y + rowHeight);
                    ctx.stroke();
                }
            });
            y += rowHeight;
        });

        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 13px Inter, Arial, sans-serif';
        ctx.fillText(`${<?= json_encode(__('Total Qty'), JSON_UNESCAPED_UNICODE) ?>}: ${data.totalQty}`, 30, y + 28);
        ctx.fillText(`${<?= json_encode(__('Total Amount'), JSON_UNESCAPED_UNICODE) ?>}: ${data.currency}${formatAmount(data.totalAmount)}`, 250, y + 28);
        ctx.font = '12px Inter, Arial, sans-serif';
        ctx.fillText(`${<?= json_encode(__('Remark'), JSON_UNESCAPED_UNICODE) ?>}: ${data.remark || '-'}`, 30, y + 50);

        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = `${data.quoteNo || 'quotation'}.png`;
        link.click();
    }

    document.getElementById('addProductBtn').addEventListener('click', addSelectedProduct);
    document.getElementById('clearRowsBtn').addEventListener('click', () => {
        rows.length = 0;
        rebuildTable();
        previewCard.style.display = 'none';
    });
    document.getElementById('generateViewBtn').addEventListener('click', renderPreview);
    document.getElementById('downloadImageBtn').addEventListener('click', downloadImage);

    quoteRowsBody.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) {
            return;
        }
        const index = safeNumber(target.dataset.index, -1);
        const field = String(target.dataset.field || '');
        if (index < 0 || index >= rows.length || field === '') {
            return;
        }
        if (field === 'qty') {
            rows[index][field] = Math.max(1, Math.round(safeNumber(target.value, 1)));
            target.value = String(rows[index][field]);
        } else if (field === 'price') {
            rows[index][field] = Math.max(0, safeNumber(target.value, 0));
        } else {
            rows[index][field] = target.value;
        }
        rebuildTable();
    });

    quoteRowsBody.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const removeBtn = target.closest('.remove-row-btn');
        if (!removeBtn) {
            return;
        }
        const index = safeNumber(removeBtn.getAttribute('data-index'), -1);
        if (index < 0 || index >= rows.length) {
            return;
        }
        rows.splice(index, 1);
        rebuildTable();
    });

    ['quoteNo', 'quoteDate', 'quoteValidDays', 'quoteCustomer', 'quotePreparedBy', 'quoteCurrency', 'quoteRemark']
        .forEach((id) => {
            const el = document.getElementById(id);
            if (!el) {
                return;
            }
            el.addEventListener('input', () => {
                if (previewCard.style.display === 'block') {
                    renderPreview();
                }
                recalcSummary();
            });
        });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
