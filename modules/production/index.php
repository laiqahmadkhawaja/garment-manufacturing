<?php
/**
 * Garment Manufacturing ERP - Ultra Fast Production Work Entry
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Production Work Entry';
$activeNav = 'production_entry';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);
$error = '';

// Handle Production Work Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $lotId = (int)($_POST['lot_id'] ?? 0);
    $operationId = (int)($_POST['operation_id'] ?? 0);
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $variantKey = trim($_POST['variant_key'] ?? ''); // format: "colorId_sizeId"
    $qtyReceived = (int)($_POST['qty_received'] ?? 0);
    $goodQty = (int)($_POST['good_qty'] ?? 0);
    $rejectedQty = (int)($_POST['rejected_qty'] ?? 0);
    $reworkQty = (int)($_POST['rework_qty'] ?? 0);
    $rate = (float)($_POST['rate'] ?? 0.00);
    $notes = trim($_POST['notes'] ?? '');

    // Parse variant key
    $colorId = 0;
    $sizeId = 0;
    if (str_contains($variantKey, '_')) {
        [$colorId, $sizeId] = explode('_', $variantKey);
        $colorId = (int)$colorId;
        $sizeId = (int)$sizeId;
    }

    if ($lotId <= 0 || $operationId <= 0 || $workerId <= 0 || $colorId <= 0 || $sizeId <= 0) {
        $error = 'Please fill all required fields: Lot, Operation, Worker, and Color/Size variant.';
    } elseif ($goodQty < 0 || $rejectedQty < 0 || $reworkQty < 0) {
        $error = 'Quantities cannot be negative.';
    } elseif ($goodQty === 0 && $rejectedQty === 0 && $reworkQty === 0) {
        $error = 'Please enter at least one piece under Good, Rejected, or Rework.';
    } else {
        if ($qtyReceived <= 0) {
            $qtyReceived = $goodQty + $rejectedQty + $reworkQty;
        }

        $labourAmount = round($goodQty * $rate, 2);

        $db->beginTransaction();
        try {
            // Check planned variant quantity for reconciliation warning
            $pvStmt = $db->prepare("SELECT quantity FROM lot_variants WHERE lot_id = ? AND color_id = ? AND size_id = ?");
            $pvStmt->execute([$lotId, $colorId, $sizeId]);
            $variantPlanned = (int)$pvStmt->fetchColumn();

            // Check existing good qty for this variant & operation
            $curDoneStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = ? AND color_id = ? AND size_id = ?");
            $curDoneStmt->execute([$lotId, $operationId, $colorId, $sizeId]);
            $previouslyDone = (int)$curDoneStmt->fetchColumn();

            $cumulativeGood = $previouslyDone + $goodQty;

            // Generate entry number
            $entryNumber = generate_doc_number('PE-', 'production_entries', 'entry_number');

            // 1. Insert Production Entry
            $stmt = $db->prepare("INSERT INTO production_entries 
                (entry_number, entry_date, lot_id, operation_id, worker_id, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, labour_amount, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $entryNumber,
                $entryDate,
                $lotId,
                $operationId,
                $workerId,
                $colorId,
                $sizeId,
                $qtyReceived,
                $goodQty,
                $rejectedQty,
                $reworkQty,
                $rate,
                $labourAmount,
                $notes,
                $_SESSION['user_id'] ?? null
            ]);
            $entryId = (int)$db->lastInsertId();

            // 2. Track Rejections if any
            if ($rejectedQty > 0) {
                $rejStmt = $db->prepare("INSERT INTO production_rejections (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $rejStmt->execute([$entryId, $lotId, $operationId, $colorId, $sizeId, $rejectedQty, $notes ?: 'Quality inspection rejection']);
            }

            // 3. Track Rework if any
            if ($reworkQty > 0) {
                $rewStmt = $db->prepare("INSERT INTO production_rework (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')");
                $rewStmt->execute([$entryId, $lotId, $operationId, $colorId, $sizeId, $reworkQty, $notes ?: 'Requires correction/touchup']);
            }

            // 4. Update Worker Ledger (Double-Entry: Credit worker earnings)
            // Get previous running balance
            $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
            $balStmt->execute([$workerId]);
            $lastBal = $balStmt->fetchColumn();
            if ($lastBal === false) {
                $wStmt = $db->prepare("SELECT opening_balance FROM workers WHERE id = ?");
                $wStmt->execute([$workerId]);
                $lastBal = (float)$wStmt->fetchColumn();
            } else {
                $lastBal = (float)$lastBal;
            }

            $newBal = $lastBal + $labourAmount;

            // Fetch lot and operation details for description
            $lQuery = $db->prepare("SELECT lot_number FROM lots WHERE id = ?");
            $lQuery->execute([$lotId]);
            $lotNum = $lQuery->fetchColumn();

            $opQuery = $db->prepare("SELECT name FROM operations WHERE id = ?");
            $opQuery->execute([$operationId]);
            $opName = $opQuery->fetchColumn();

            $ledgerDesc = "Labour earned on Lot #$lotNum - $opName ($goodQty pcs @ Rs. $rate)";
            $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
                (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by) 
                VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)");
            $ledgStmt->execute([
                $workerId,
                $entryDate,
                $entryId,
                $lotId,
                $operationId,
                $labourAmount,
                $newBal,
                $ledgerDesc,
                $_SESSION['user_id'] ?? null
            ]);

            // 5. Update Lot Status if it was Draft
            $lotStStmt = $db->prepare("SELECT status FROM lots WHERE id = ?");
            $lotStStmt->execute([$lotId]);
            $currentLotStatus = $lotStStmt->fetchColumn();

            if ($currentLotStatus === 'Draft') {
                $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
                $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, 'Draft', 'In Production', 'First production entry submitted', ?)")
                   ->execute([$lotId, $_SESSION['user_id'] ?? null]);
            }

            // Update step status in lot_workflow_steps
            $stepStmt = $db->prepare("UPDATE lot_workflow_steps SET status = 'In Progress' WHERE lot_id = ? AND operation_id = ? AND status = 'Pending'");
            $stepStmt->execute([$lotId, $operationId]);

            log_audit('PRODUCTION_ENTRY', 'production_entries', $entryId, null, [
                'lot_id' => $lotId,
                'operation' => $opName,
                'good_qty' => $goodQty,
                'labour' => $labourAmount
            ]);

            $db->commit();

            // Construct feedback message
            $feedbackMsg = "Production entry saved! Good: $goodQty pcs | Labour: " . format_currency($labourAmount) . " credited to worker.";
            if ($cumulativeGood < $variantPlanned && $opName === 'Cutting') {
                $missing = $variantPlanned - $cumulativeGood;
                $feedbackMsg .= " Alert: $missing piece(s) shortage currently pending in Cutting.";
            }

            redirect("modules/production/index.php?lot_id=$lotId", $feedbackMsg);
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Error saving entry: ' . $e->getMessage();
        }
    }
}

// Fetch active lots for dropdown
$lots = $db->query("SELECT l.id, l.lot_number, l.total_pieces, p.name AS product_name, d.design_number 
                   FROM lots l 
                   JOIN products p ON l.product_id = p.id 
                   LEFT JOIN designs d ON l.design_id = d.id 
                   WHERE l.status != 'Closed' 
                   ORDER BY l.id DESC")->fetchAll();

// Fetch active workers
$workers = $db->query("SELECT w.*, dep.name AS department_name 
                       FROM workers w 
                       LEFT JOIN departments dep ON w.department_id = dep.id 
                       WHERE w.is_active = 1 
                       ORDER BY w.name ASC")->fetchAll();

// Recent 10 entries for live history panel
$recentEntries = $db->query("SELECT pe.*, l.lot_number, o.name AS op_name, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                            FROM production_entries pe 
                            JOIN lots l ON pe.lot_id = l.id 
                            JOIN operations o ON pe.operation_id = o.id 
                            JOIN workers w ON pe.worker_id = w.id 
                            JOIN colors c ON pe.color_id = c.id 
                            JOIN sizes s ON pe.size_id = s.id 
                            ORDER BY pe.id DESC LIMIT 8")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production Work Entry</h4>
                <p class="text-muted small mb-0">Record pieces produced by operators, calculate labour, track shortages & rejections</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/production/pending.php" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-exclamation-octagon"></i>
                    <span>Pending Operations</span>
                </a>
                <a href="<?= BASE_URL ?>modules/production/history.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-clock-history"></i>
                    <span>Full Log</span>
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Fast Production Entry Form -->
            <div class="col-12 col-lg-7 col-xl-8">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary p-2 rounded-2"><i class="bi bi-lightning-charge-fill text-white fs-6"></i></span>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Quick Production Work Entry Form</h6>
                                <span class="text-muted small">Auto-calculates labour and verifies batch quantities</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="" id="prodEntryForm">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="row g-3">
                                <!-- Date & Lot -->
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Entry Date *</label>
                                    <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label">Select Lot Number *</label>
                                    <select name="lot_id" id="pe_lot_id" class="form-select fw-bold text-primary" required>
                                        <option value="">-- Choose Factory Lot --</option>
                                        <?php foreach ($lots as $l): ?>
                                            <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id'] || (isset($_POST['lot_id']) && $_POST['lot_id'] == $l['id'])) ? 'selected' : '' ?>>
                                                LOT #<?= clean($l['lot_number']) ?> — <?= clean($l['product_name']) ?> (<?= $l['design_number'] ? 'Design #' . clean($l['design_number']) : 'No Design' ?>) [<?= $l['total_pieces'] ?> pcs]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Operation & Worker -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Select Operation *</label>
                                    <select name="operation_id" id="pe_operation_id" class="form-select" required>
                                        <option value="">-- Select Lot First --</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label mb-0">Worker / Operator *</label>
                                        <span id="workerCountBadge" class="badge bg-light text-secondary border small d-none">0 matching</span>
                                    </div>
                                    <select name="worker_id" id="pe_worker_id" class="form-select" required>
                                        <option value="">-- Select Operation First --</option>
                                    </select>
                                </div>

                                <!-- Color & Size Variant -->
                                <div class="col-12">
                                    <label class="form-label">Color & Size Variant *</label>
                                    <select name="variant_key" id="pe_variant_key" class="form-select" required>
                                        <option value="">-- Select Operation First --</option>
                                    </select>
                                </div>

                                <!-- Live Stage Balance Pill -->
                                <div class="col-12 d-none" id="stageBalanceAlert">
                                    <div class="p-3 bg-light border rounded-3 d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                                        <div>Planned Variant Qty: <strong id="lblPlanned" class="text-dark">0</strong></div>
                                        <div>Already Good Done: <strong id="lblDone" class="text-success">0</strong></div>
                                        <div>Pending in Stage: <strong id="lblPending" class="text-warning-emphasis fw-bold">0</strong></div>
                                    </div>
                                </div>

                                <!-- Quantities breakdown -->
                                <div class="col-6 col-sm-3">
                                    <label class="form-label text-success fw-bold">Good Produced *</label>
                                    <input type="number" name="good_qty" id="pe_good_qty" class="form-control form-control-lg fw-bold text-success text-center" min="0" placeholder="0" required>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <label class="form-label text-danger">Rejected Pcs</label>
                                    <input type="number" name="rejected_qty" id="pe_rejected_qty" class="form-control form-control-lg text-danger text-center" min="0" value="0">
                                </div>
                                <div class="col-6 col-sm-3">
                                    <label class="form-label text-warning">Rework Pcs</label>
                                    <input type="number" name="rework_qty" id="pe_rework_qty" class="form-control form-control-lg text-warning text-center" min="0" value="0">
                                </div>
                                <div class="col-6 col-sm-3">
                                    <label class="form-label text-muted">Total Received</label>
                                    <input type="number" name="qty_received" id="pe_qty_received" class="form-control form-control-lg text-center" min="0" placeholder="Auto">
                                </div>

                                <!-- Rate & Calculated Labour Amount -->
                                <div class="col-12 col-sm-6">
                                    <label class="form-label">Piece Rate (Rs.) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" name="rate" id="pe_rate" class="form-control fw-bold" step="0.25" min="0" value="0.00" required>
                                    </div>
                                    <div class="form-text">Auto-populated from operation rate; editable if exception applies.</div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label text-dark fw-bold">Calculated Worker Labour</label>
                                    <div class="p-2 border rounded-2 bg-light text-end">
                                        <div class="fs-4 fw-bold text-primary" id="lblLabourAmount">Rs. 0.00</div>
                                        <div class="small text-muted">Good Qty × Rate</div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Remarks / Discrepancy Note</label>
                                    <input type="text" name="notes" class="form-control" placeholder="Optional operator remarks or shortage reason">
                                </div>

                                <div class="col-12 pt-2">
                                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm" id="btnSaveEntry">
                                        <i class="bi bi-check2-circle me-1"></i> Save Production Work Entry
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Entries & Live Shortage Alerts -->
            <div class="col-12 col-lg-5 col-xl-4">
                <div class="card-modern h-100">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Recent Submissions</h6>
                            <span class="text-muted small">Live factory stream</span>
                        </div>
                        <a href="<?= BASE_URL ?>modules/production/history.php" class="btn btn-sm btn-outline-secondary">View All</a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0" style="font-size: 0.82rem;">
                                <thead>
                                    <tr>
                                        <th>Lot / Op</th>
                                        <th>Worker</th>
                                        <th class="text-center">Good</th>
                                        <th class="text-end">Labour</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentEntries)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No entries yet today.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentEntries as $re): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-primary">#<?= clean($re['lot_number']) ?></span>
                                                    <div><?= clean($re['op_name']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.72rem;"><?= clean($re['color_name']) ?> (<?= clean($re['size_name']) ?>)</div>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark"><?= clean($re['worker_name']) ?></span>
                                                    <div class="text-muted" style="font-size: 0.72rem;"><?= format_date($re['entry_date']) ?></div>
                                                </td>
                                                <td class="text-center fw-bold text-success">
                                                    <?= number_format($re['good_qty']) ?>
                                                    <?php if ($re['rejected_qty'] > 0): ?>
                                                        <div class="text-danger small" style="font-size: 0.7rem;"><?= $re['rejected_qty'] ?> rej</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark">
                                                    <?= format_currency($re['labour_amount']) ?>
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
    const lotSelect = document.getElementById('pe_lot_id');
    const opSelect = document.getElementById('pe_operation_id');
    const workerSelect = document.getElementById('pe_worker_id');
    const workerBadge = document.getElementById('workerCountBadge');
    const varSelect = document.getElementById('pe_variant_key');
    const rateInput = document.getElementById('pe_rate');
    const goodInput = document.getElementById('pe_good_qty');
    const rejInput = document.getElementById('pe_rejected_qty');
    const rewInput = document.getElementById('pe_rework_qty');
    const recInput = document.getElementById('pe_qty_received');
    const labourDisplay = document.getElementById('lblLabourAmount');

    function calculateLabour() {
        const good = parseInt(goodInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        const total = good * rate;
        labourDisplay.textContent = 'Rs. ' + total.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const rej = parseInt(rejInput.value) || 0;
        const rew = parseInt(rewInput.value) || 0;
        if (!recInput.value || parseInt(recInput.value) === 0) {
            recInput.placeholder = good + rej + rew;
        }
    }

    goodInput.addEventListener('input', calculateLabour);
    rejInput.addEventListener('input', calculateLabour);
    rewInput.addEventListener('input', calculateLabour);
    rateInput.addEventListener('input', calculateLabour);

    // Load operations and variants for selected lot
    function loadLotData(lotId) {
        if (!lotId) {
            opSelect.innerHTML = '<option value="">-- Select Lot First --</option>';
            workerSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
            if (workerBadge) workerBadge.classList.add('d-none');
            varSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
            return;
        }

        workerSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
        if (workerBadge) workerBadge.classList.add('d-none');

        fetch('<?= BASE_URL ?>modules/production/ajax.php?action=get_lot_operations_and_variants&lot_id=' + lotId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Populate operations
                    opSelect.innerHTML = '<option value="">-- Choose Operation --</option>';
                    data.operations.forEach(op => {
                        const opt = document.createElement('option');
                        opt.value = op.operation_id;
                        opt.dataset.rate = op.default_rate;
                        opt.textContent = op.sequence + '. ' + op.op_name + ' (' + op.dept_name + ') - Rs. ' + parseFloat(op.default_rate).toFixed(2);
                        opSelect.appendChild(opt);
                    });

                    // Populate variants
                    varSelect.innerHTML = '<option value="">-- Choose Color & Size --</option>';
                    data.variants.forEach(v => {
                        const opt = document.createElement('option');
                        opt.value = v.color_id + '_' + v.size_id;
                        opt.dataset.color = v.color_id;
                        opt.dataset.size = v.size_id;
                        opt.dataset.planned = v.quantity;
                        opt.textContent = v.color_name + ' / ' + v.size_name + ' (' + v.size_code + ') — Planned: ' + v.quantity + ' pcs';
                        varSelect.appendChild(opt);
                    });
                }
            })
            .catch(err => console.error(err));
    }

    lotSelect.addEventListener('change', function() {
        loadLotData(this.value);
    });

    if (lotSelect.value) {
        loadLotData(lotSelect.value);
    }

    function loadWorkersForOperation(opId) {
        if (!opId) {
            workerSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
            if (workerBadge) workerBadge.classList.add('d-none');
            return;
        }

        workerSelect.innerHTML = '<option value="">Loading related artisans...</option>';
        if (workerBadge) {
            workerBadge.textContent = 'Loading...';
            workerBadge.className = 'badge bg-light text-secondary border small';
            workerBadge.classList.remove('d-none');
        }

        fetch('<?= BASE_URL ?>modules/production/ajax.php?action=get_operation_workers&operation_id=' + opId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    workerSelect.innerHTML = '<option value="">-- Choose Artisan --</option>';
                    const count = data.workers ? data.workers.length : 0;
                    if (workerBadge) {
                        workerBadge.textContent = count + ' related artisan' + (count === 1 ? '' : 's');
                        workerBadge.className = 'badge bg-primary-subtle text-primary border small';
                        workerBadge.classList.remove('d-none');
                    }

                    data.workers.forEach(w => {
                        const opt = document.createElement('option');
                        opt.value = w.id;
                        opt.dataset.rate = w.effective_rate || '';
                        opt.textContent = w.name + ' (' + w.code + ') — ' + (w.department_name || 'Worker');
                        workerSelect.appendChild(opt);
                    });

                    // Auto-select if exactly 1 matching artisan
                    if (count === 1) {
                        workerSelect.value = data.workers[0].id;
                        if (data.workers[0].effective_rate && parseFloat(data.workers[0].effective_rate) > 0) {
                            rateInput.value = parseFloat(data.workers[0].effective_rate).toFixed(2);
                            calculateLabour();
                        }
                    }
                }
            })
            .catch(err => {
                console.error(err);
                workerSelect.innerHTML = '<option value="">-- Choose Artisan --</option>';
            });
    }

    workerSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.rate && parseFloat(opt.dataset.rate) > 0) {
            rateInput.value = parseFloat(opt.dataset.rate).toFixed(2);
            calculateLabour();
        }
    });

    // When operation changes, update default rate and load related workers
    opSelect.addEventListener('change', function() {
        const selectedOpt = this.options[this.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.rate) {
            rateInput.value = selectedOpt.dataset.rate;
            calculateLabour();
        }
        loadWorkersForOperation(this.value);
        checkBalance();
    });

    varSelect.addEventListener('change', function() {
        checkBalance();
    });

    function checkBalance() {
        const lotId = lotSelect.value;
        const opId = opSelect.value;
        const varVal = varSelect.value;

        if (!lotId || !opId || !varVal) {
            document.getElementById('stageBalanceAlert').classList.add('d-none');
            return;
        }

        const [colorId, sizeId] = varVal.split('_');

        fetch(`<?= BASE_URL ?>modules/production/ajax.php?action=get_variant_stage_balance&lot_id=${lotId}&operation_id=${opId}&color_id=${colorId}&size_id=${sizeId}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('stageBalanceAlert').classList.remove('d-none');
                    document.getElementById('lblPlanned').textContent = data.planned + ' pcs';
                    document.getElementById('lblDone').textContent = data.good_done + ' pcs';
                    document.getElementById('lblPending').textContent = data.pending + ' pcs';

                    // Auto-fill good quantity suggestion if currently blank
                    if (!goodInput.value || parseInt(goodInput.value) === 0) {
                        goodInput.placeholder = data.pending;
                    }
                }
            })
            .catch(err => console.error(err));
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
