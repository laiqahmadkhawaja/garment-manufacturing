<?php
/**
 * Garment Manufacturing ERP - Packing Management & Finished Goods Inward
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Packing Management';
$activeNav = 'packing';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);
$error = '';

// Handle Packing Entry Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_packing') {
    $lotId = (int)($_POST['lot_id'] ?? 0);
    $packingDate = trim($_POST['packing_date'] ?? date('Y-m-d'));
    $variantKey = trim($_POST['variant_key'] ?? ''); // format "colorId_sizeId"
    $packedQty = (int)($_POST['packed_qty'] ?? 0);
    $workerId = !empty($_POST['worker_id']) ? (int)$_POST['worker_id'] : null;
    $rate = (float)($_POST['rate'] ?? 2.00); // packing rate
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $packageType = trim($_POST['package_type'] ?? 'Box');
    $packageNo = trim($_POST['package_no'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    $colorId = 0;
    $sizeId = 0;
    if (str_contains($variantKey, '_')) {
        [$colorId, $sizeId] = explode('_', $variantKey);
        $colorId = (int)$colorId;
        $sizeId = (int)$sizeId;
    }

    if ($lotId <= 0 || $colorId <= 0 || $sizeId <= 0 || $packedQty <= 0) {
        $error = 'Please select a lot, variant, and valid packed quantity greater than zero.';
    } else {
        $db->beginTransaction();
        try {
            // Fetch lot info
            $lStmt = $db->prepare("SELECT product_id, design_id, total_pieces, lot_number FROM lots WHERE id = ?");
            $lStmt->execute([$lotId]);
            $lot = $lStmt->fetch();

            if (!$lot) {
                throw new Exception("Lot not found.");
            }

            // Check eligible good completed pieces for this variant
            // The user must not pack more pieces than completed good pieces from production
            $gStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND color_id = ? AND size_id = ?");
            $gStmt->execute([$lotId, $colorId, $sizeId]);
            $completedGood = (int)$gStmt->fetchColumn();

            // Already packed pieces for this variant
            $pDoneStmt = $db->prepare("SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = ? AND color_id = ? AND size_id = ?");
            $pDoneStmt->execute([$lotId, $colorId, $sizeId]);
            $alreadyPacked = (int)$pDoneStmt->fetchColumn();

            // If production entries exist, enforce completed good cap; if cutting only started, use total lot variant planned as cap
            $eligibleCap = $completedGood > 0 ? $completedGood : $lot['total_pieces'];
            if (($alreadyPacked + $packedQty) > $eligibleCap) {
                throw new Exception("Cannot pack $packedQty pieces! Completed good pieces: $eligibleCap, already packed: $alreadyPacked. Exceeds available output.");
            }

            $labourAmount = round($packedQty * $rate, 2);
            $packNum = generate_doc_number('PCK-', 'packing_entries', 'packing_number');

            // 1. Insert Packing Record
            $pkStmt = $db->prepare("INSERT INTO packing_entries 
                (packing_number, packing_date, lot_id, product_id, design_id, color_id, size_id, packed_qty, packing_worker_id, packing_rate, labour_amount, package_type, package_no, remarks, warehouse_id, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $pkStmt->execute([
                $packNum,
                $packingDate,
                $lotId,
                $lot['product_id'],
                $lot['design_id'],
                $colorId,
                $sizeId,
                $packedQty,
                $workerId,
                $rate,
                $labourAmount,
                $packageType,
                $packageNo,
                $remarks,
                $warehouseId,
                $_SESSION['user_id'] ?? null
            ]);
            $packingId = (int)$db->lastInsertId();

            // 2. Increase Finished Goods Stock
            $fgStmt = $db->prepare("INSERT INTO finished_goods_stock 
                (product_id, design_id, color_id, size_id, lot_id, warehouse_id, current_stock) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)");
            $fgStmt->execute([
                $lot['product_id'],
                $lot['design_id'],
                $colorId,
                $sizeId,
                $lotId,
                $warehouseId,
                $packedQty
            ]);

            // 3. Record Inventory Movement
            $tx = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, product_id, design_id, color_id, size_id, lot_id, warehouse_id, transaction_type, quantity_in, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Finished Goods', ?, ?, ?, ?, ?, ?, 'Packing', ?, 'PACKING_ENTRY', ?, ?, ?)");
            $tx->execute([
                $packingDate,
                $lot['product_id'],
                $lot['design_id'],
                $colorId,
                $sizeId,
                $lotId,
                $warehouseId,
                $packedQty,
                $packingId,
                "Packed from Lot #{$lot['lot_number']} into $packageType #$packageNo",
                $_SESSION['user_id'] ?? null
            ]);

            // 4. If packing worker assigned, credit worker in worker_ledger
            if ($workerId && $labourAmount > 0) {
                $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
                $balStmt->execute([$workerId]);
                $lastBal = (float)$balStmt->fetchColumn();
                $newBal = $lastBal + $labourAmount;

                $desc = "Packing labour on Lot #{$lot['lot_number']} ($packedQty pcs @ Rs. $rate)";
                $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
                    (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, debit, credit, running_balance, description, created_by) 
                    VALUES (?, ?, 'Labour', 'PACKING_ENTRY', ?, ?, 0.00, ?, ?, ?, ?)");
                $ledgStmt->execute([
                    $workerId,
                    $packingDate,
                    $packingId,
                    $lotId,
                    $labourAmount,
                    $newBal,
                    $desc,
                    $_SESSION['user_id'] ?? null
                ]);
            }

            // 5. Update Lot Completion Status (Draft / In Production -> Partially Packed or Ready Stock)
            $totPackedStmt = $db->prepare("SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = ?");
            $totPackedStmt->execute([$lotId]);
            $totalPackedForLot = (int)$totPackedStmt->fetchColumn();

            $newLotStatus = ($totalPackedForLot >= (int)$lot['total_pieces']) ? 'Ready Stock' : 'Partially Packed';
            $db->prepare("UPDATE lots SET status = ? WHERE id = ?")->execute([$newLotStatus, $lotId]);
            $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, NULL, ?, ?, ?)")
               ->execute([$lotId, $newLotStatus, "Packed $packedQty pcs. Total packed: $totalPackedForLot / {$lot['total_pieces']}", $_SESSION['user_id'] ?? null]);

            log_audit('PACKING_ENTRY', 'packing_entries', $packingId, null, [
                'lot_id' => $lotId,
                'packed_qty' => $packedQty,
                'new_status' => $newLotStatus
            ]);

            $db->commit();
            redirect("modules/packing/index.php?lot_id=$lotId", "Successfully packed $packedQty pieces! Finished goods inventory updated. Lot is now '$newLotStatus'.");
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to save packing entry: ' . $e->getMessage();
        }
    }
}

// Fetch active lots
$lots = $db->query("SELECT l.id, l.lot_number, l.total_pieces, p.name AS product_name, d.design_number,
                    (SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = l.id) AS total_packed
                    FROM lots l 
                    JOIN products p ON l.product_id = p.id 
                    LEFT JOIN designs d ON l.design_id = d.id 
                    WHERE l.status != 'Closed' 
                    ORDER BY l.id DESC")->fetchAll();

$warehouses = $db->query("SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$workers = $db->query("SELECT id, name, code FROM workers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Recent Packing Entries
$recentPacking = $db->query("SELECT pe.*, l.lot_number, p.name AS product_name, c.name AS color_name, s.name AS size_name, w.name AS worker_name, wh.name AS warehouse_name 
                             FROM packing_entries pe 
                             JOIN lots l ON pe.lot_id = l.id 
                             JOIN products p ON pe.product_id = p.id 
                             JOIN colors c ON pe.color_id = c.id 
                             JOIN sizes s ON pe.size_id = s.id 
                             LEFT JOIN workers w ON pe.packing_worker_id = w.id 
                             JOIN warehouses wh ON pe.warehouse_id = wh.id 
                             ORDER BY pe.id DESC LIMIT 12")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Packing & Finished Goods Inward</h4>
                <p class="text-muted small mb-0">Records final inspection packing, credits packing labour and deposits stock in warehouse</p>
            </div>
            <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-tags-fill"></i>
                <span>Finished Goods Stock</span>
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Packing Form -->
            <div class="col-12 col-xl-6">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-info p-2 rounded-2"><i class="bi bi-box-seam-fill text-white fs-6"></i></span>
                            <h6 class="fw-bold mb-0 text-dark">Record Finished Packing Entry</h6>
                        </div>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="save_packing">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Packing Date *</label>
                                    <input type="date" name="packing_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label">Production Lot *</label>
                                    <select name="lot_id" id="packLotSelect" class="form-select fw-bold text-primary" required>
                                        <option value="">-- Choose Factory Lot --</option>
                                        <?php foreach ($lots as $l): ?>
                                            <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id']) ? 'selected' : '' ?>>
                                                LOT #<?= clean($l['lot_number']) ?> — <?= clean($l['product_name']) ?> (Packed: <?= $l['total_packed'] ?> / <?= $l['total_pieces'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Color & Size Variant *</label>
                                    <select name="variant_key" id="packVariantSelect" class="form-select" required>
                                        <option value="">-- Select Lot First --</option>
                                    </select>
                                </div>

                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-success fw-bold">Packed Pieces *</label>
                                    <input type="number" name="packed_qty" class="form-control form-control-lg fw-bold text-success text-center" min="1" placeholder="0" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label">Destination Warehouse *</label>
                                    <select name="warehouse_id" class="form-select form-select-lg">
                                        <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?= $wh['id'] ?>"><?= clean($wh['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-sm-6">
                                    <label class="form-label">Packing Artisan / Worker</label>
                                    <select name="worker_id" class="form-select">
                                        <option value="">-- In-House Staff / None --</option>
                                        <?php foreach ($workers as $w): ?>
                                            <option value="<?= $w['id'] ?>"><?= clean($w['name']) ?> (<?= clean($w['code']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label">Packing Rate per piece (Rs.)</label>
                                    <input type="number" name="rate" class="form-control text-end" step="0.25" value="2.00">
                                </div>

                                <div class="col-6">
                                    <label class="form-label">Package Type</label>
                                    <select name="package_type" class="form-select">
                                        <option value="Box">Box / Carton</option>
                                        <option value="Polybag">Polybag</option>
                                        <option value="Bundle">Bundle</option>
                                        <option value="Hanger">Hanger Bag</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Carton / Package #</label>
                                    <input type="text" name="package_no" class="form-control" placeholder="e.g. CTN-01">
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Remarks</label>
                                    <input type="text" name="remarks" class="form-control" placeholder="e.g. 1st partial packing of batch">
                                </div>

                                <div class="col-12 pt-2">
                                    <button type="submit" class="btn btn-info w-100 py-2 fw-bold text-white shadow-sm">
                                        <i class="bi bi-box-seam-fill me-1"></i> Confirm Packing & Update Stock
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Packing Entries -->
            <div class="col-12 col-xl-6">
                <div class="card-modern h-100">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Recent Packing Logs</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0" style="font-size: 0.82rem;">
                                <thead>
                                    <tr>
                                        <th>Packing #</th>
                                        <th>Lot & Variant</th>
                                        <th class="text-center">Packed</th>
                                        <th>Carton</th>
                                        <th class="text-end">Labour</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentPacking)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">No packing recorded yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentPacking as $rp): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-primary"><?= clean($rp['packing_number']) ?></span>
                                                    <div class="text-muted" style="font-size: 0.72rem;"><?= format_date($rp['packing_date']) ?></div>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark">LOT #<?= clean($rp['lot_number']) ?></span>
                                                    <div><?= clean($rp['color_name']) ?> (<?= clean($rp['size_name']) ?>)</div>
                                                </td>
                                                <td class="text-center fw-bold text-success fs-6">
                                                    <?= number_format($rp['packed_qty']) ?> pcs
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border"><?= clean($rp['package_type']) ?> #<?= clean($rp['package_no'] ?: '-') ?></span>
                                                </td>
                                                <td class="text-end fw-bold text-dark">
                                                    <?= format_currency($rp['labour_amount']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const lotSelect = document.getElementById('packLotSelect');
    const varSelect = document.getElementById('packVariantSelect');

    function loadLotVariants(lotId) {
        if (!lotId) {
            varSelect.innerHTML = '<option value="">-- Select Lot First --</option>';
            return;
        }

        fetch('<?= BASE_URL ?>modules/lots/ajax.php?action=get_lot_details&lot_id=' + lotId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    varSelect.innerHTML = '<option value="">-- Choose Color & Size --</option>';
                    data.variants.forEach(v => {
                        const opt = document.createElement('option');
                        opt.value = v.color_id + '_' + v.size_id;
                        opt.textContent = v.color_name + ' / ' + v.size_name + ' (' + v.size_code + ') — Planned: ' + v.quantity + ' pcs';
                        varSelect.appendChild(opt);
                    });
                }
            })
            .catch(err => console.error(err));
    }

    lotSelect.addEventListener('change', function() {
        loadLotVariants(this.value);
    });

    if (lotSelect.value) {
        loadLotVariants(lotSelect.value);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
