<?php
/**
 * Garment Manufacturing ERP - Return Unused Material from Production Lot
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Return Material from Lot';
$activeNav = 'material_returns';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);

// Handle Return Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_material') {
    $lotId = (int)($_POST['lot_id'] ?? 0);
    $returnDate = trim($_POST['return_date'] ?? date('Y-m-d'));
    $materialId = (int)($_POST['material_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0.00);
    $notes = trim($_POST['notes'] ?? '');

    if ($lotId <= 0 || $materialId <= 0 || $quantity <= 0) {
        set_flash('danger', 'Please choose a production lot, material, and valid quantity to return.');
    } else {
        // 1. Verify that this material was actually issued to this lot
        $issuedStmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM material_issue_items mii 
                                    JOIN material_issues mi ON mii.issue_id = mi.id 
                                    WHERE mi.lot_id = ? AND mii.material_id = ?");
        $issuedStmt->execute([$lotId, $materialId]);
        $totalIssued = (float)$issuedStmt->fetchColumn();

        if ($totalIssued <= 0) {
            set_flash('danger', 'Selected material was never issued to this lot. You can only return materials that were issued to the lot.');
        } else {
            // 2. Check already returned quantity to prevent over-returns
            $retCheck = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM material_return_items mri 
                                      JOIN material_returns mr ON mri.return_id = mr.id 
                                      WHERE mr.lot_id = ? AND mri.material_id = ?");
            $retCheck->execute([$lotId, $materialId]);
            $alreadyReturned = (float)$retCheck->fetchColumn();
            $availableToReturn = max(0.00, round($totalIssued - $alreadyReturned, 2));

            if ($quantity > $availableToReturn) {
                set_flash('danger', "Return quantity ($quantity) exceeds available returnable material ($availableToReturn) for this lot.");
            } else {
                $db->beginTransaction();
                try {
                    $mStmt = $db->prepare("SELECT cost_price, name, unit_id FROM materials WHERE id = ?");
                    $mStmt->execute([$materialId]);
                    $mat = $mStmt->fetch();

                    $unitCost = (float)$mat['cost_price'];
                    $totalCost = round($quantity * $unitCost, 2);
                    $retNum = generate_doc_number('RET-', 'material_returns', 'return_number');

                    // 1. Insert Material Return Master
                    $rStmt = $db->prepare("INSERT INTO material_returns (return_number, return_date, lot_id, total_cost, notes, created_by) 
                                          VALUES (?, ?, ?, ?, ?, ?)");
                    $rStmt->execute([$retNum, $returnDate, $lotId, $totalCost, $notes, $_SESSION['user_id'] ?? null]);
                    $returnId = (int)$db->lastInsertId();

                    // 2. Insert Item
                    $itemStmt = $db->prepare("INSERT INTO material_return_items (return_id, material_id, quantity, unit_price, total_cost) 
                                              VALUES (?, ?, ?, ?, ?)");
                    $itemStmt->execute([$returnId, $materialId, $quantity, $unitCost, $totalCost]);

                    // 3. Add Raw Material Stock Back
                    $up = $db->prepare("UPDATE materials SET current_stock = current_stock + ? WHERE id = ?");
                    $up->execute([$quantity, $materialId]);

                    // 4. Record Inventory Transaction
                    $tx = $db->prepare("INSERT INTO inventory_transactions 
                        (transaction_date, item_type, material_id, lot_id, warehouse_id, transaction_type, quantity_in, unit_cost, reference_type, reference_id, remarks, created_by) 
                        VALUES (?, 'Material', ?, ?, 2, 'Material Return', ?, ?, 'MATERIAL_RETURN', ?, ?, ?)");
                    $tx->execute([$returnDate, $materialId, $lotId, $quantity, $unitCost, $returnId, "Returned from Lot #$lotId", $_SESSION['user_id'] ?? null]);

                    log_audit('RETURN_MATERIAL', 'material_returns', $returnId, null, [
                        'lot_id' => $lotId,
                        'material' => $mat['name'],
                        'quantity' => $quantity,
                        'cost' => $totalCost
                    ]);

                    $db->commit();
                    redirect('modules/raw_materials/returns.php', "Return #$retNum processed! $quantity {$mat['name']} added back to raw inventory.");
                } catch (Exception $e) {
                    $db->rollBack();
                    set_flash('danger', 'Error processing return: ' . $e->getMessage());
                }
            }
        }
    }
}

// Fetch active lots
$lots = $db->query("SELECT l.id, l.lot_number, p.name AS product_name 
                    FROM lots l 
                    JOIN products p ON l.product_id = p.id 
                    WHERE l.status != 'Closed' 
                    ORDER BY l.id DESC")->fetchAll();

// Returns history list
$returnsList = $db->query("SELECT mr.*, l.lot_number, p.name AS product_name, m.name AS material_name, mri.quantity, u.code AS unit_code 
                           FROM material_returns mr 
                           JOIN lots l ON mr.lot_id = l.id 
                           JOIN products p ON l.product_id = p.id 
                           JOIN material_return_items mri ON mr.id = mri.return_id 
                           JOIN materials m ON mri.material_id = m.id 
                           JOIN units u ON m.unit_id = u.id 
                           ORDER BY mr.id DESC LIMIT 10")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Material Returns from Production</h4>
                <p class="text-muted small mb-0">Return unused yarn, fabric, or trims back to raw store and credit lot cost</p>
            </div>
            <a href="<?= BASE_URL ?>modules/raw_materials/issues.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-box-arrow-right"></i>
                <span>Issue to Lot</span>
            </a>
        </div>

        <!-- Return Form -->
        <div class="card-modern mb-4 shadow-sm">
            <div class="card-modern-header">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success p-2 rounded-2"><i class="bi bi-box-arrow-in-left text-white fs-6"></i></span>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">Return Unused Raw Material Form</h6>
                        <span class="text-muted small">Only materials issued to the selected lot are listed to prevent errors</span>
                    </div>
                </div>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="" id="returnMaterialForm">
                    <input type="hidden" name="action" value="return_material">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Return Date *</label>
                            <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Production Lot *</label>
                            <select name="lot_id" id="return_lot_id" class="form-select fw-bold text-primary" required>
                                <option value="">-- Choose Factory Lot --</option>
                                <?php foreach ($lots as $l): ?>
                                    <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id']) ? 'selected' : '' ?>>
                                        LOT #<?= clean($l['lot_number']) ?> — <?= clean($l['product_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold">Material to Return *</label>
                            <select name="material_id" id="return_material_id" class="form-select fw-semibold" required disabled>
                                <option value="">-- Select Factory Lot First --</option>
                            </select>

                            <!-- Balance / Available info -->
                            <div class="small mt-1 text-muted d-none" id="materialBalanceInfo">
                                <span class="badge bg-light text-secondary border">Issued: <strong id="lblTotalIssued" class="text-dark">0</strong></span>
                                <span class="badge bg-light text-secondary border">Returned: <strong id="lblTotalReturned" class="text-dark">0</strong></span>
                                <span class="badge bg-success-subtle text-success border">Available: <strong id="lblMaxAvailable" class="text-success fw-bold">0</strong></span>
                            </div>

                            <div class="alert alert-warning py-2 small d-none mt-2 mb-0" id="noMaterialNotice">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> No raw materials have been issued to this lot yet. Only materials issued to the lot can be returned.
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Return Quantity *</label>
                            <div class="input-group">
                                <input type="number" name="quantity" id="return_quantity" class="form-control form-control-lg fw-bold text-success text-end" step="0.01" min="0.01" placeholder="0.00" required disabled>
                                <span class="input-group-text fw-bold text-secondary" id="return_unit_label">—</span>
                            </div>
                            <div class="form-text small" id="quantityHelperText">Enter quantity returning to raw storage.</div>
                        </div>

                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold">Reason / Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Excess material from cutting / batch completed">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-3 border-top">
                            <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm" id="btnSubmitReturn" disabled>
                                <i class="bi bi-box-arrow-in-left me-1"></i> Confirm Material Return
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Returns List -->
        <div class="card-modern shadow-sm">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Material Returns Log (<?= count($returnsList) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Return #</th>
                                <th>Date</th>
                                <th>Lot Number</th>
                                <th>Product</th>
                                <th>Material Returned</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Cost Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returnsList)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No material returns recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($returnsList as $ret): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= clean($ret['return_number']) ?></td>
                                        <td><?= format_date($ret['return_date']) ?></td>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $ret['lot_id'] ?>" class="fw-bold text-decoration-none">
                                                LOT #<?= clean($ret['lot_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= clean($ret['product_name']) ?></td>
                                        <td class="fw-semibold text-dark"><?= clean($ret['material_name']) ?></td>
                                        <td class="text-end fw-bold text-success">+ <?= number_format($ret['quantity'], 2) ?> <?= clean($ret['unit_code']) ?></td>
                                        <td class="text-end fw-bold text-success fs-6"><?= format_currency($ret['total_cost']) ?></td>
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
    const lotSelect = document.getElementById('return_lot_id');
    const matSelect = document.getElementById('return_material_id');
    const qtyInput = document.getElementById('return_quantity');
    const unitLabel = document.getElementById('return_unit_label');
    const balanceInfo = document.getElementById('materialBalanceInfo');
    const noMatNotice = document.getElementById('noMaterialNotice');
    const submitBtn = document.getElementById('btnSubmitReturn');
    const lblTotalIssued = document.getElementById('lblTotalIssued');
    const lblTotalReturned = document.getElementById('lblTotalReturned');
    const lblMaxAvailable = document.getElementById('lblMaxAvailable');
    const qtyHelper = document.getElementById('quantityHelperText');

    function loadLotMaterials(lotId, selectedMatId = '') {
        if (!lotId) {
            matSelect.innerHTML = '<option value="">-- Select Factory Lot First --</option>';
            matSelect.disabled = true;
            qtyInput.value = '';
            qtyInput.disabled = true;
            submitBtn.disabled = true;
            unitLabel.textContent = '—';
            balanceInfo.classList.add('d-none');
            noMatNotice.classList.add('d-none');
            return;
        }

        matSelect.innerHTML = '<option value="">Loading issued materials...</option>';
        matSelect.disabled = true;
        balanceInfo.classList.add('d-none');
        noMatNotice.classList.add('d-none');

        fetch('<?= BASE_URL ?>modules/raw_materials/ajax.php?action=get_lot_issued_materials&lot_id=' + lotId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const materials = data.materials || [];
                    if (materials.length === 0) {
                        matSelect.innerHTML = '<option value="">-- No Materials Issued to this Lot --</option>';
                        matSelect.disabled = true;
                        qtyInput.disabled = true;
                        submitBtn.disabled = true;
                        noMatNotice.classList.remove('d-none');
                        return;
                    }

                    matSelect.innerHTML = '<option value="">-- Choose Material Issued to this Lot --</option>';
                    matSelect.disabled = false;
                    noMatNotice.classList.add('d-none');

                    materials.forEach(m => {
                        const opt = document.createElement('option');
                        opt.value = m.id;
                        opt.dataset.unit = m.unit_code;
                        opt.dataset.issued = m.total_issued;
                        opt.dataset.returned = m.total_returned;
                        opt.dataset.available = m.available_return;
                        opt.textContent = `${m.name} (${m.code}) [${m.unit_code}] — Returnable: ${m.available_return} ${m.unit_code}`;
                        if (selectedMatId && selectedMatId == m.id) opt.selected = true;
                        matSelect.appendChild(opt);
                    });

                    // Auto-select if only 1 material is issued
                    if (materials.length === 1 && !selectedMatId) {
                        matSelect.value = materials[0].id;
                    }

                    updateSelectedMaterial();
                } else {
                    matSelect.innerHTML = '<option value="">Error loading materials</option>';
                }
            })
            .catch(err => {
                console.error(err);
                matSelect.innerHTML = '<option value="">Failed to load materials</option>';
            });
    }

    function updateSelectedMaterial() {
        const selected = matSelect.options[matSelect.selectedIndex];
        if (selected && selected.value) {
            const unit = selected.dataset.unit || 'pcs';
            const issued = parseFloat(selected.dataset.issued) || 0;
            const returned = parseFloat(selected.dataset.returned) || 0;
            const available = parseFloat(selected.dataset.available) || 0;

            unitLabel.textContent = unit;
            qtyInput.disabled = false;
            qtyInput.max = available;

            lblTotalIssued.textContent = issued.toFixed(2) + ' ' + unit;
            lblTotalReturned.textContent = returned.toFixed(2) + ' ' + unit;
            lblMaxAvailable.textContent = available.toFixed(2) + ' ' + unit;
            balanceInfo.classList.remove('d-none');

            if (available <= 0) {
                submitBtn.disabled = true;
                qtyInput.value = '0.00';
                qtyInput.disabled = true;
                qtyHelper.textContent = 'All issued material has already been returned.';
                qtyHelper.className = 'form-text small text-danger fw-semibold';
            } else {
                submitBtn.disabled = false;
                qtyHelper.textContent = 'Maximum returnable: ' + available.toFixed(2) + ' ' + unit;
                qtyHelper.className = 'form-text small text-success';
                if (!qtyInput.value || parseFloat(qtyInput.value) <= 0) {
                    qtyInput.placeholder = 'Max: ' + available.toFixed(2);
                }
            }
        } else {
            unitLabel.textContent = '—';
            balanceInfo.classList.add('d-none');
            qtyInput.disabled = true;
            submitBtn.disabled = true;
            qtyHelper.textContent = 'Enter quantity returning to raw storage.';
            qtyHelper.className = 'form-text small';
        }
    }

    lotSelect.addEventListener('change', function() {
        loadLotMaterials(this.value);
    });

    matSelect.addEventListener('change', updateSelectedMaterial);

    qtyInput.addEventListener('input', function() {
        const selected = matSelect.options[matSelect.selectedIndex];
        if (selected && selected.value) {
            const max = parseFloat(selected.dataset.available) || 0;
            const entered = parseFloat(this.value) || 0;
            if (entered > max) {
                this.classList.add('is-invalid');
                qtyHelper.textContent = 'Cannot exceed returnable balance (' + max.toFixed(2) + ')!';
                qtyHelper.className = 'form-text small text-danger fw-bold';
                submitBtn.disabled = true;
            } else if (entered <= 0) {
                this.classList.remove('is-invalid');
                submitBtn.disabled = true;
            } else {
                this.classList.remove('is-invalid');
                qtyHelper.textContent = 'Maximum returnable: ' + max.toFixed(2);
                qtyHelper.className = 'form-text small text-success';
                submitBtn.disabled = false;
            }
        }
    });

    // Auto-load if lot is preselected
    if (lotSelect.value) {
        loadLotMaterials(lotSelect.value);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
