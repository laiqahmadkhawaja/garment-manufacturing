<?php
/**
 * Garment Manufacturing ERP - Raw Material Purchases
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Raw Material Purchases';
$activeNav = 'raw_materials';
$db = get_db();

// Handle Purchase Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_purchase') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseDate = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $invoiceNo = trim($_POST['invoice_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $materialId = (int)($_POST['material_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0.00);
    $unitPrice = (float)($_POST['unit_price'] ?? 0.00);

    if ($supplierId <= 0 || $materialId <= 0 || $quantity <= 0) {
        set_flash('danger', 'Please choose a supplier, raw material, and valid quantity.');
    } else {
        $db->beginTransaction();
        try {
            $totalAmount = round($quantity * $unitPrice, 2);
            $purNum = generate_doc_number('PUR-', 'material_purchases', 'purchase_number');

            $pStmt = $db->prepare("INSERT INTO material_purchases (purchase_number, purchase_date, supplier_id, total_amount, invoice_no, notes, created_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $pStmt->execute([$purNum, $purchaseDate, $supplierId, $totalAmount, $invoiceNo, $notes, $_SESSION['user_id'] ?? null]);
            $purchaseId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO material_purchase_items (purchase_id, material_id, quantity, unit_price, total_price) 
                                      VALUES (?, ?, ?, ?, ?)");
            $itemStmt->execute([$purchaseId, $materialId, $quantity, $unitPrice, $totalAmount]);

            // Increase raw material stock
            $up = $db->prepare("UPDATE materials SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
            $up->execute([$quantity, $unitPrice > 0 ? $unitPrice : $costPrice, $materialId]);

            // Record in inventory_transactions
            $tx = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, material_id, warehouse_id, transaction_type, quantity_in, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Material', ?, 2, 'Purchase', ?, ?, 'PURCHASE', ?, ?, ?)");
            $tx->execute([$purchaseDate, $materialId, $quantity, $unitPrice, $purchaseId, "Supplier Invoice: $invoiceNo", $_SESSION['user_id'] ?? null]);

            log_audit('PURCHASE_MATERIAL', 'material_purchases', $purchaseId, null, ['amount' => $totalAmount, 'quantity' => $quantity]);
            $db->commit();
            redirect('modules/raw_materials/purchases.php', "Purchase #$purNum recorded successfully! Raw stock updated.");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error recording purchase: ' . $e->getMessage());
        }
    }
}

// Purchases list
$purchases = $db->query("SELECT mp.*, s.name AS supplier_name, 
                         (SELECT COUNT(*) FROM material_purchase_items WHERE purchase_id = mp.id) AS items_count 
                         FROM material_purchases mp 
                         JOIN suppliers s ON mp.supplier_id = s.id 
                         ORDER BY mp.id DESC")->fetchAll();

$suppliers = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$materials = $db->query("SELECT m.id, m.name, m.code, m.cost_price, u.code AS unit_code 
                         FROM materials m 
                         JOIN units u ON m.unit_id = u.id 
                         WHERE m.is_active = 1 ORDER BY m.name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Material Purchases & Inward Goods</h4>
                <p class="text-muted small mb-0">Record raw yarn, fabric and accessories received from suppliers</p>
            </div>
            <a href="<?= BASE_URL ?>modules/raw_materials/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-boxes me-1"></i> Stock Balances
            </a>
        </div>

        <!-- Inward Purchase Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-cart-plus me-1 text-primary"></i> Record Supplier Inward Purchase</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_purchase">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Purchase Date *</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Supplier / Mill *</label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">-- Choose Supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= clean($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Supplier Bill / Invoice #</label>
                            <input type="text" name="invoice_no" class="form-control" placeholder="e.g. INV-2026-9081">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Raw Material *</label>
                            <select name="material_id" id="purMaterial" class="form-select" required>
                                <option value="">-- Choose Raw Material --</option>
                                <?php foreach ($materials as $m): ?>
                                    <option value="<?= $m['id'] ?>" data-cost="<?= $m['cost_price'] ?>">
                                        <?= clean($m['name']) ?> (<?= clean($m['code']) ?>) [<?= clean($m['unit_code']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Purchased Quantity *</label>
                            <input type="number" name="quantity" id="purQty" class="form-control fw-bold text-end" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Unit Price (Rs.) *</label>
                            <input type="number" name="unit_price" id="purPrice" class="form-control text-end" step="0.01" min="0" value="0.00" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Total Bill Amount</label>
                            <div class="p-2 border rounded bg-light text-end fw-bold fs-6 text-success" id="lblPurTotal">
                                Rs. 0.00
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-primary px-5 fw-bold">
                                <i class="bi bi-check-lg me-1"></i> Save Inward Stock
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Purchases Log -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Purchases History (<?= count($purchases) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Purchase #</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>Invoice #</th>
                                <th class="text-end">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($purchases)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No purchase records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($purchases as $p): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= clean($p['purchase_number']) ?></td>
                                        <td><?= format_date($p['purchase_date']) ?></td>
                                        <td class="fw-semibold text-dark"><?= clean($p['supplier_name']) ?></td>
                                        <td><?= clean($p['invoice_no'] ?: '-') ?></td>
                                        <td class="text-end fw-bold text-success fs-6"><?= format_currency($p['total_amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const matSelect = document.getElementById('purMaterial');
    const qtyInput = document.getElementById('purQty');
    const priceInput = document.getElementById('purPrice');
    const totalDisplay = document.getElementById('lblPurTotal');

    function calcTotal() {
        const q = parseFloat(qtyInput.value) || 0;
        const p = parseFloat(priceInput.value) || 0;
        const tot = q * p;
        totalDisplay.textContent = 'Rs. ' + tot.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    matSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.cost) {
            priceInput.value = opt.dataset.cost;
            calcTotal();
        }
    });

    qtyInput.addEventListener('input', calcTotal);
    priceInput.addEventListener('input', calcTotal);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
