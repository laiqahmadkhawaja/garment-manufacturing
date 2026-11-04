<?php
/**
 * Garment Manufacturing ERP - Stage 1 Machine Operator Production Entry (Element-Level, No Color)
 * Dedicated entry form for Machine Operators (Hashim, Adnan/Molu, etc.)
 * Records aggregate pieces by garment element (Front, Back, Sleeves, Pocket, Gala) without color breakdown.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Stage 1: Operator Element Production';
$activeNav = 'production_entry';
$db = get_db();

$lotId = (int)($_GET['lot_id'] ?? 0);

// Fetch all active lots for switcher dropdown
$activeLots = $db->query("SELECT id, lot_number, total_pieces, status FROM lots WHERE status NOT IN ('Closed', 'Cancelled') ORDER BY id DESC")->fetchAll();

if ($lotId <= 0 && !empty($activeLots)) {
    $lotId = (int)$activeLots[0]['id'];
}

$lot = null;
if ($lotId > 0) {
    $stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code,
                                 COALESCE(l.design_number, d.design_number) AS design_number,
                                 d.name AS design_name, d.machine_type,
                                 op.name AS operator_name, nop.name AS night_operator_name
                          FROM lots l
                          JOIN products p ON l.product_id = p.id
                          LEFT JOIN designs d ON l.design_id = d.id
                          LEFT JOIN workers op ON l.operator_id = op.id
                          LEFT JOIN workers nop ON l.night_operator_id = nop.id
                          WHERE l.id = ?");
    $stmt->execute([$lotId]);
    $lot = $stmt->fetch();
}

if (!$lot) {
    redirect('modules/lots/index.php', 'Please select a valid production lot.', 'warning');
}

// Fetch all workers/operators
$operators = $db->query("SELECT id, code, name, worker_type, rate_per_minute FROM workers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Product pattern elements for this lot
$peStmt = $db->prepare("SELECT pe.* FROM product_elements pe WHERE pe.product_id = ? ORDER BY pe.sort_order ASC, pe.id ASC");
$peStmt->execute([$lot['product_id']]);
$elements = $peStmt->fetchAll();

// Existing saved operator entries for this lot
$oeStmt = $db->prepare("SELECT * FROM lot_operator_element_entries WHERE lot_id = ?");
$oeStmt->execute([$lotId]);
$existingEntries = [];
foreach ($oeStmt->fetchAll() as $row) {
    $existingEntries[$row['element_id']] = $row;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $op1Id = !empty($_POST['operator1_id']) ? (int)$_POST['operator1_id'] : null;
    $op2Id = !empty($_POST['operator2_id']) ? (int)$_POST['operator2_id'] : null;
    $entriesData = $_POST['elements'] ?? [];

    $db->beginTransaction();
    try {
        $totalBatchGood = 0;
        $allComplete = true;

        foreach ($entriesData as $elId => $vals) {
            $elId = (int)$elId;
            $req = (int)($vals['required'] ?? $lot['total_pieces']);
            $p1 = (int)($vals['op1_pieces'] ?? 0);
            $p2 = (int)($vals['op2_pieces'] ?? 0);
            $mins = (float)($vals['minutes_spent'] ?? 0);
            $notes = trim($vals['notes'] ?? '');
            $sum = $p1 + $p2;

            if ($sum < $req) {
                $allComplete = false;
            }

            // Check if existing
            $chk = $db->prepare("SELECT id FROM lot_operator_element_entries WHERE lot_id = ? AND element_id = ?");
            $chk->execute([$lotId, $elId]);
            $existingId = $chk->fetchColumn();

            if ($existingId) {
                $up = $db->prepare("UPDATE lot_operator_element_entries SET 
                    entry_date = ?, operator1_id = ?, operator1_pieces = ?, 
                    operator2_id = ?, operator2_pieces = ?, required_pieces = ?, minutes_spent = ?, notes = ? 
                    WHERE id = ?");
                $up->execute([$entryDate, $op1Id, $p1, $op2Id, $p2, $req, $mins, $notes, $existingId]);
            } else {
                $ins = $db->prepare("INSERT INTO lot_operator_element_entries 
                    (lot_id, element_id, entry_date, operator1_id, operator1_pieces, operator2_id, operator2_pieces, required_pieces, minutes_spent, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$lotId, $elId, $entryDate, $op1Id, $p1, $op2Id, $p2, $req, $mins, $notes, $_SESSION['user_id'] ?? null]);
            }

            if ($sum > $totalBatchGood) {
                $totalBatchGood = $sum;
            }
        }

        // Also post to production_entries for Stage 1 (Knitting/Operator) if available in workflow
        $s1Stmt = $db->prepare("SELECT lws.operation_id, o.name AS op_name 
                               FROM lot_workflow_steps lws 
                               JOIN operations o ON lws.operation_id = o.id 
                               WHERE lws.lot_id = ? ORDER BY lws.sequence ASC LIMIT 1");
        $s1Stmt->execute([$lotId]);
        $s1Op = $s1Stmt->fetch();

        if ($s1Op && $totalBatchGood > 0) {
            $s1OpId = (int)$s1Op['operation_id'];
            // Record Op1 contribution
            $op1Sum = 0;
            foreach ($entriesData as $vals) $op1Sum += (int)($vals['op1_pieces'] ?? 0);
            $avgOp1Pieces = count($entriesData) > 0 ? round($op1Sum / count($entriesData)) : 0;

            if ($op1Id && $avgOp1Pieces > 0) {
                $chkP = $db->prepare("SELECT id FROM production_entries WHERE lot_id = ? AND operation_id = ? AND worker_id = ? LIMIT 1");
                $chkP->execute([$lotId, $s1OpId, $op1Id]);
                $peId = $chkP->fetchColumn();
                if ($peId) {
                    $db->prepare("UPDATE production_entries SET good_qty = ?, entry_date = ? WHERE id = ?")->execute([$avgOp1Pieces, $entryDate, $peId]);
                } else {
                    $db->prepare("INSERT INTO production_entries (lot_id, operation_id, worker_id, entry_date, good_qty, shift, created_by) VALUES (?, ?, ?, ?, ?, 'Day Shift', ?)")
                       ->execute([$lotId, $s1OpId, $op1Id, $entryDate, $avgOp1Pieces, $_SESSION['user_id'] ?? null]);
                }
            }

            // Record Op2 contribution
            $op2Sum = 0;
            foreach ($entriesData as $vals) $op2Sum += (int)($vals['op2_pieces'] ?? 0);
            $avgOp2Pieces = count($entriesData) > 0 ? round($op2Sum / count($entriesData)) : 0;

            if ($op2Id && $avgOp2Pieces > 0) {
                $chkP = $db->prepare("SELECT id FROM production_entries WHERE lot_id = ? AND operation_id = ? AND worker_id = ? LIMIT 1");
                $chkP->execute([$lotId, $s1OpId, $op2Id]);
                $peId = $chkP->fetchColumn();
                if ($peId) {
                    $db->prepare("UPDATE production_entries SET good_qty = ?, entry_date = ? WHERE id = ?")->execute([$avgOp2Pieces, $entryDate, $peId]);
                } else {
                    $db->prepare("INSERT INTO production_entries (lot_id, operation_id, worker_id, entry_date, good_qty, shift, created_by) VALUES (?, ?, ?, ?, ?, 'Night Shift', ?)")
                       ->execute([$lotId, $s1OpId, $op2Id, $entryDate, $avgOp2Pieces, $_SESSION['user_id'] ?? null]);
                }
            }
        }

        log_audit('OPERATOR_STAGE_ENTRY', 'lots', $lotId, null, [
            'lot_number' => $lot['lot_number'],
            'operator1_id' => $op1Id,
            'operator2_id' => $op2Id,
            'all_complete' => $allComplete
        ]);

        $db->commit();
        redirect("modules/production/operator_stage.php?lot_id=$lotId", "Operator pieces recorded successfully! " . ($allComplete ? "All elements completed." : "Some elements have pending balances."));
    } catch (Exception $e) {
        $db->rollBack();
        set_flash('danger', 'Error saving operator entry: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Header & Lot Switcher -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-cpu-fill text-primary me-2"></i>Stage 1: Machine Operator Production Entry (آپریٹر سیکشن)
                </h4>
                <p class="text-muted small mb-0">Record pieces produced by operators per garment element (Front, Back, Sleeves, Pocket, Gala) — <strong>No color breakdown required</strong>.</p>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <label class="small fw-semibold text-muted mb-0">Switch Lot:</label>
                <select class="form-select form-select-sm fw-bold border-primary text-primary" onchange="window.location.href='?lot_id=' + this.value;">
                    <?php foreach ($activeLots as $al): ?>
                        <option value="<?= $al['id'] ?>" <?= $al['id'] == $lotId ? 'selected' : '' ?>>
                            #<?= clean($al['lot_number']) ?> (<?= (int)$al['total_pieces'] ?> pcs) - <?= clean($al['status']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="<?= BASE_URL ?>modules/lots/jobcard.php?id=<?= $lotId ?>" class="btn btn-info btn-sm fw-bold text-dark shadow-sm">
                    <i class="bi bi-clipboard2-data-fill me-1"></i> Job Card
                </a>
            </div>
        </div>

        <!-- Lot Overview Card -->
        <div class="card-modern mb-4 shadow-sm border-start border-4 border-primary">
            <div class="card-modern-body p-3">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-md-3">
                        <span class="text-muted small text-uppercase fw-semibold">Lot Number</span>
                        <div class="fs-5 fw-bold text-primary font-monospace">#<?= clean($lot['lot_number']) ?></div>
                        <span class="badge bg-light text-secondary border"><?= clean($lot['product_name']) ?></span>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="text-muted small text-uppercase fw-semibold">Design & Machine</span>
                        <div class="fw-bold text-dark fs-6"><?= clean($lot['design_number'] ?: 'N/A') ?></div>
                        <span class="text-muted small"><i class="bi bi-gear me-1"></i><?= clean($lot['machine_type'] ?: 'CMS 430') ?></span>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="text-muted small text-uppercase fw-semibold">Planned Batch Pieces</span>
                        <div class="fs-4 fw-bold text-dark"><?= (int)$lot['total_pieces'] ?> <span class="fs-6 text-muted font-normal">pcs</span></div>
                        <span class="text-muted small">Shift: <?= clean($lot['shift_type']) ?></span>
                    </div>
                    <div class="col-12 col-md-3 text-md-end">
                        <a href="<?= BASE_URL ?>modules/lots/print_design_sheet.php?id=<?= $lotId ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-printer me-1"></i> Print 4-Copy Sheet
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Operator Form -->
        <form method="POST" action="" id="operatorStageForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <!-- Operator Assignments & Date -->
            <div class="card-modern mb-4 shadow-sm">
                <div class="card-modern-header">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-person-badge-fill text-primary me-1"></i> 1. Assigned Machine Operators & Date
                    </h6>
                </div>
                <div class="card-modern-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold">Entry Date (تاریخ) *</label>
                            <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold">
                                <span class="text-warning">☀️</span> Operator 1 (e.g. Hashim / Day Operator)
                            </label>
                            <select name="operator1_id" id="operator1_id" class="form-select fw-semibold">
                                <option value="">-- Choose Operator 1 --</option>
                                <?php foreach ($operators as $op): ?>
                                    <option value="<?= $op['id'] ?>" <?= ($op['id'] == $lot['operator_id']) ? 'selected' : '' ?>>
                                        <?= clean($op['name']) ?> (<?= clean($op['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold">
                                <span class="text-primary">🌙</span> Operator 2 (e.g. Adnan / Molu / Night Operator)
                            </label>
                            <select name="operator2_id" id="operator2_id" class="form-select fw-semibold">
                                <option value="">-- Choose Operator 2 (Optional) --</option>
                                <?php foreach ($operators as $op): ?>
                                    <option value="<?= $op['id'] ?>" <?= ($op['id'] == $lot['night_operator_id']) ? 'selected' : '' ?>>
                                        <?= clean($op['name']) ?> (<?= clean($op['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Element Production Breakdown Table -->
            <div class="card-modern mb-4 shadow-sm">
                <div class="card-modern-header d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-puzzle-fill text-primary me-1"></i> 2. Garment Elements Piece Verification (حصوں کے پیسز)
                        </h6>
                        <span class="text-muted small">Auto-calculated required quantities based on garment anatomy. Incomplete parts are highlighted.</span>
                    </div>
                    <span class="badge bg-light text-secondary border">No color breakdown needed here</span>
                </div>
                <div class="card-modern-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0 text-center" id="opElementsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-start" style="min-width: 160px;">Element (حصہ / عضو)</th>
                                    <th style="width: 100px;">فی پیس اجزاء</th>
                                    <th style="width: 120px;" class="table-active">کل مطلوبہ (Target)</th>
                                    <th style="width: 160px;"><span class="text-warning">☀️</span> Op 1 Pieces</th>
                                    <th style="width: 160px;"><span class="text-primary">🌙</span> Op 2 Pieces</th>
                                    <th style="width: 120px;">ٹوٹل مکمل</th>
                                    <th style="width: 140px;">پینڈنگ بقایا</th>
                                    <th style="width: 130px;">ٹائم (منٹ)</th>
                                    <th style="width: 130px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $standardElements = !empty($elements) ? $elements : [
                                    ['id' => 1, 'element_name' => 'Front (پلا)', 'quantity' => 1],
                                    ['id' => 2, 'element_name' => 'Back (پشت)', 'quantity' => 1],
                                    ['id' => 3, 'element_name' => 'Bazo (بازو)', 'quantity' => 2],
                                    ['id' => 4, 'element_name' => 'Pocket (پاکٹ)', 'quantity' => 2],
                                    ['id' => 5, 'element_name' => 'Gold Gala (گول گلا)', 'quantity' => 1],
                                ];

                                foreach ($standardElements as $el):
                                    $elId = (int)$el['id'];
                                    $mult = (int)($el['quantity'] ?? 1);
                                    if ($mult <= 0) $mult = 1;
                                    $reqPcs = (int)$lot['total_pieces'] * $mult;

                                    $saved = $existingEntries[$elId] ?? null;
                                    $p1 = $saved ? (int)$saved['operator1_pieces'] : 0;
                                    $p2 = $saved ? (int)$saved['operator2_pieces'] : 0;
                                    $spentMins = $saved ? (float)$saved['minutes_spent'] : 0;
                                    $done = $p1 + $p2;
                                    $pending = max(0, $reqPcs - $done);
                                    $isComplete = ($done >= $reqPcs);
                                ?>
                                    <tr class="el-row" data-req="<?= $reqPcs ?>">
                                        <td class="text-start fw-bold text-dark">
                                            <i class="bi bi-tag-fill text-primary me-1"></i> <?= clean($el['element_name']) ?>
                                            <input type="hidden" name="elements[<?= $elId ?>][required]" value="<?= $reqPcs ?>">
                                        </td>
                                        <td class="text-muted fw-semibold"><?= $mult ?>x</td>
                                        <td class="table-active fw-bold fs-6 text-dark"><?= number_format($reqPcs) ?></td>
                                        <td>
                                            <input type="number" name="elements[<?= $elId ?>][op1_pieces]" 
                                                   class="form-control text-center fw-bold inp-op1 fs-6" 
                                                   min="0" max="<?= $reqPcs ?>" 
                                                   value="<?= $p1 ?>" placeholder="0">
                                        </td>
                                        <td>
                                            <input type="number" name="elements[<?= $elId ?>][op2_pieces]" 
                                                   class="form-control text-center fw-bold inp-op2 fs-6" 
                                                   min="0" max="<?= $reqPcs ?>" 
                                                   value="<?= $p2 ?>" placeholder="0">
                                        </td>
                                        <td class="fw-bold fs-6 text-primary cell-done">
                                            <?= number_format($done) ?>
                                        </td>
                                        <td class="fw-bold cell-pending <?= $pending > 0 ? 'text-danger bg-danger-subtle' : 'text-success' ?>">
                                            <?= $pending > 0 ? ($pending . ' pending ⚠️') : '0 (Done)' ?>
                                        </td>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.5" name="elements[<?= $elId ?>][minutes_spent]" 
                                                       class="form-control text-center fw-semibold inp-mins" 
                                                       value="<?= $spentMins > 0 ? $spentMins : '' ?>" placeholder="e.g. 7">
                                                <span class="input-group-text small">min</span>
                                            </div>
                                        </td>
                                        <td class="cell-status">
                                            <?php if ($isComplete): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                    <i class="bi bi-check-circle-fill me-1"></i> مکمل
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1 fw-bold">
                                                    <i class="bi bi-hourglass-split me-1"></i> بقایا
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-modern-footer p-3 bg-light d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                        💡 Example: If 70 Front pieces are made by Op 1 and 74 by Op 2, enter 70 and 74. Total 144 is verified automatically.
                    </div>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Save Operator Entry (اینٹری محفوظ کریں)
                    </button>
                </div>
            </div>

        </form>
    </div> <!-- end app-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    function recalcRow(row) {
        const req = parseInt(row.dataset.req, 10) || 0;
        const p1 = parseInt(row.querySelector('.inp-op1').value, 10) || 0;
        const p2 = parseInt(row.querySelector('.inp-op2').value, 10) || 0;
        const done = p1 + p2;
        const pending = Math.max(0, req - done);

        row.querySelector('.cell-done').textContent = done.toLocaleString();
        const pendingCell = row.querySelector('.cell-pending');
        const statusCell = row.querySelector('.cell-status');

        if (pending > 0) {
            pendingCell.className = 'fw-bold cell-pending text-danger bg-danger-subtle';
            pendingCell.textContent = `${pending} pending ⚠️`;
            statusCell.innerHTML = `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1 fw-bold"><i class="bi bi-hourglass-split me-1"></i> بقایا</span>`;
        } else {
            pendingCell.className = 'fw-bold cell-pending text-success';
            pendingCell.textContent = '0 (Done)';
            statusCell.innerHTML = `<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> مکمل</span>`;
        }
    }

    document.querySelectorAll('#opElementsTable .el-row').forEach(row => {
        row.querySelectorAll('.inp-op1, .inp-op2').forEach(inp => {
            inp.addEventListener('input', () => recalcRow(row));
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
