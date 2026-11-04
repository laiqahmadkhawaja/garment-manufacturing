<?php
/**
 * Garment Manufacturing ERP - Digital Job Card (جاب کارڈ کنسولیڈیٹڈ ویو)
 * Exact digital counterpart of the factory handwritten Excel Job Card:
 * - Order & Lot details (Lot#, Design#, Product, Total Pieces, Sizes)
 * - Operator Element Section (Hashim, Adnan/Molu: Front, Back, Bazo, Pocket, Gala) with pending highlights
 * - Cutting Master Log (Ali Raza, Cutting date, Cut pieces)
 * - Worker Operation Matrix (Arif, Asad, Bilal, Latif: Pocket, Gala, Safety, Tanke, Buttons, Press, Packing)
 * - SAM Minutes & Real-time Labour Costing
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Digital Job Card';
$activeNav = 'lots';
$db = get_db();

$lotId = (int)($_GET['id'] ?? 0);

// Fetch all active lots for switcher dropdown
$activeLots = $db->query("SELECT id, lot_number, total_pieces, status FROM lots WHERE status NOT IN ('Closed', 'Cancelled') ORDER BY id DESC")->fetchAll();

if ($lotId <= 0 && !empty($activeLots)) {
    $lotId = (int)$activeLots[0]['id'];
}

$stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code, p.product_type,
                             COALESCE(l.design_number, d.design_number) AS design_number,
                             d.name AS design_name, d.machine_type,
                             op.name AS operator_name, nop.name AS night_operator_name,
                             s.name AS single_size_name, s.code AS single_size_code
                      FROM lots l
                      JOIN products p ON l.product_id = p.id
                      LEFT JOIN designs d ON l.design_id = d.id
                      LEFT JOIN workers op ON l.operator_id = op.id
                      LEFT JOIN workers nop ON l.night_operator_id = nop.id
                      LEFT JOIN sizes s ON l.single_size_id = s.id
                      WHERE l.id = ?");
$stmt->execute([$lotId]);
$lot = $stmt->fetch();

if (!$lot) {
    if (!empty($activeLots)) {
        redirect('modules/lots/jobcard.php?id=' . (int)$activeLots[0]['id'], 'Lot #' . $lotId . ' was not found or closed. Loaded active lot #' . clean($activeLots[0]['lot_number']) . '.', 'info');
    } else {
        redirect('modules/lots/index.php', 'Please select a valid production lot.', 'warning');
    }
}

// Fetch Variants with Color Codes & Sizes
$vStmt = $db->prepare("SELECT lv.*, c.name AS color_name, COALESCE(lv.color_code, c.color_code) AS color_code, c.hex_code,
                              s.name AS size_name, s.code AS size_code
                       FROM lot_variants lv
                       JOIN colors c ON lv.color_id = c.id
                       JOIN sizes s ON lv.size_id = s.id
                       WHERE lv.lot_id = ?
                       ORDER BY lv.color_code ASC, s.sort_order ASC");
$vStmt->execute([$lotId]);
$variants = $vStmt->fetchAll();

// Group variants by color code
$colorSummary = [];
$sizesUsed = [];
foreach ($variants as $v) {
    $code = $v['color_code'] ?: $v['color_name'];
    if (!isset($colorSummary[$code])) {
        $colorSummary[$code] = [
            'color_code' => $code,
            'color_name' => $v['color_name'],
            'hex_code' => $v['hex_code'],
            'total_qty' => 0
        ];
    }
    $colorSummary[$code]['total_qty'] += (int)$v['quantity'];
    $sizesUsed[$v['size_id']] = $v['size_name'];
}

// Fetch Stage 1 Operator Element Entries
$oeStmt = $db->prepare("SELECT oe.*, pe.element_name, pe.quantity AS multiplier,
                               w1.name AS op1_name, w2.name AS op2_name
                        FROM lot_operator_element_entries oe
                        JOIN product_elements pe ON oe.element_id = pe.id
                        LEFT JOIN workers w1 ON oe.operator1_id = w1.id
                        LEFT JOIN workers w2 ON oe.operator2_id = w2.id
                        WHERE oe.lot_id = ?
                        ORDER BY pe.sort_order ASC, pe.id ASC");
$oeStmt->execute([$lotId]);
$operatorEntries = $oeStmt->fetchAll();

// If no entries saved yet, build standard template for preview
if (empty($operatorEntries)) {
    $peStmt = $db->prepare("SELECT id, element_name, quantity AS multiplier FROM product_elements WHERE product_id = ? ORDER BY sort_order ASC");
    $peStmt->execute([$lot['product_id']]);
    $stdElements = $peStmt->fetchAll();
    if (empty($stdElements)) {
        $stdElements = [
            ['id' => 1, 'element_name' => 'Front (پلا)', 'multiplier' => 1],
            ['id' => 2, 'element_name' => 'Back (پشت)', 'multiplier' => 1],
            ['id' => 3, 'element_name' => 'Bazo (بازو)', 'multiplier' => 2],
            ['id' => 4, 'element_name' => 'Pocket (پاکٹ)', 'multiplier' => 2],
            ['id' => 5, 'element_name' => 'Gol Gala (گول گلا)', 'multiplier' => 1],
        ];
    }
    foreach ($stdElements as $se) {
        $req = (int)$lot['total_pieces'] * (int)$se['multiplier'];
        $operatorEntries[] = [
            'element_id' => $se['id'],
            'element_name' => $se['element_name'],
            'required_pieces' => $req,
            'operator1_pieces' => 0,
            'operator2_pieces' => 0,
            'total_pieces' => 0,
            'minutes_spent' => 0,
            'op1_name' => $lot['operator_name'] ?: 'Hashim',
            'op2_name' => $lot['night_operator_name'] ?: 'Adnan/Ali'
        ];
    }
}

// Fetch Workflow Steps with Stage Progress
$stepStmt = $db->prepare("SELECT lws.*, o.name AS op_name, o.code AS op_code, o.default_rate, o.estimated_minutes,
                                 COALESCE((SELECT SUM(good_qty) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id), 0) AS total_done,
                                 COALESCE((SELECT SUM(rejected_qty) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id), 0) AS total_rejected,
                                 COALESCE((SELECT SUM(rework_qty) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id), 0) AS total_rework
                          FROM lot_workflow_steps lws
                          JOIN operations o ON lws.operation_id = o.id
                          WHERE lws.lot_id = ?
                          ORDER BY lws.sequence ASC");
$stepStmt->execute([$lotId]);
$workflowSteps = $stepStmt->fetchAll();

// Fetch Worker Submissions Matrix for this lot
$peMatrixStmt = $db->prepare("SELECT pe.worker_id, w.name AS worker_name, w.code AS worker_code,
                                     pe.operation_id, o.name AS op_name,
                                     SUM(pe.good_qty) AS pieces,
                                     SUM(pe.labour_amount) AS labour_rs,
                                     SUM(pe.total_minutes) AS minutes,
                                     MAX(pe.entry_date) AS last_date
                              FROM production_entries pe
                              JOIN workers w ON pe.worker_id = w.id
                              JOIN operations o ON pe.operation_id = o.id
                              WHERE pe.lot_id = ?
                              GROUP BY pe.worker_id, pe.operation_id
                              ORDER BY w.name ASC, o.sequence ASC");
$peMatrixStmt->execute([$lotId]);
$workerMatrixEntries = $peMatrixStmt->fetchAll();

// Group by worker
$workerGrid = [];
$activeOpsInLot = [];
foreach ($workflowSteps as $ws) {
    $activeOpsInLot[$ws['operation_id']] = $ws['op_name'];
}
foreach ($workerMatrixEntries as $wme) {
    $wId = $wme['worker_id'];
    if (!isset($workerGrid[$wId])) {
        $workerGrid[$wId] = [
            'worker_name' => $wme['worker_name'],
            'worker_code' => $wme['worker_code'],
            'operations' => [],
            'total_pieces' => 0,
            'total_rs' => 0.00,
            'total_mins' => 0.00,
            'last_date' => $wme['last_date']
        ];
    }
    $workerGrid[$wId]['operations'][$wme['operation_id']] = (int)$wme['pieces'];
    $workerGrid[$wId]['total_pieces'] += (int)$wme['pieces'];
    $workerGrid[$wId]['total_rs'] += (float)$wme['labour_rs'];
    $workerGrid[$wId]['total_mins'] += (float)$wme['minutes'];
    if ($wme['last_date'] > $workerGrid[$wId]['last_date']) {
        $workerGrid[$wId]['last_date'] = $wme['last_date'];
    }
}

// Find cutting operation stats
$cuttingDone = 0;
$cuttingDate = 'Pending';
foreach ($workflowSteps as $ws) {
    if (stripos($ws['op_name'], 'cut') !== false) {
        $cuttingDone = (int)$ws['total_done'];
        $cDateStmt = $db->prepare("SELECT MAX(entry_date) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
        $cDateStmt->execute([$lotId, $ws['operation_id']]);
        $cuttingDate = $cDateStmt->fetchColumn() ?: 'In Progress';
        break;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Top Toolbar -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h4 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-clipboard2-data-fill text-primary me-1"></i> Digital Job Card (جاب کارڈ)
                    </h4>
                    <span class="badge bg-primary fs-6 font-monospace">Lot #<?= clean($lot['lot_number']) ?></span>
                </div>
                <p class="text-muted small mb-0">Consolidated live factory tracking matching your handwritten job card</p>
            </div>
            
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <select class="form-select form-select-sm fw-bold border-primary text-primary" style="width: auto;" onchange="window.location.href='?id=' + this.value;">
                    <?php foreach ($activeLots as $al): ?>
                        <option value="<?= $al['id'] ?>" <?= $al['id'] == $lotId ? 'selected' : '' ?>>
                            #<?= clean($al['lot_number']) ?> (<?= (int)$al['total_pieces'] ?> pcs)
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="<?= BASE_URL ?>modules/production/operator_stage.php?lot_id=<?= $lotId ?>" class="btn btn-warning btn-sm fw-bold shadow-sm">
                    <i class="bi bi-cpu-fill me-1"></i> Operator Stage Entry
                </a>
                <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $lotId ?>" class="btn btn-primary btn-sm fw-bold shadow-sm">
                    <i class="bi bi-lightning-charge-fill me-1"></i> Worker Production Entry
                </a>
                <a href="<?= BASE_URL ?>modules/lots/print_design_sheet.php?id=<?= $lotId ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer me-1"></i> Print Design Sheet
                </a>
            </div>
        </div>

        <!-- 1. JOB CARD HEADER BANNER -->
        <div class="card-modern mb-4 shadow-sm">
            <div class="card-modern-body p-4 bg-light border rounded-3">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-md-3 border-end">
                        <span class="text-muted small text-uppercase fw-semibold">Lot & Design Number</span>
                        <div class="fs-4 fw-bold text-primary font-monospace">#<?= clean($lot['lot_number']) ?></div>
                        <div class="text-dark fw-bold">Design #: <span class="badge bg-dark text-white font-monospace"><?= clean($lot['design_number'] ?: '901') ?></span></div>
                        <span class="badge bg-light text-secondary border mt-1"><?= clean($lot['product_name']) ?></span>
                    </div>
                    <div class="col-12 col-md-3 border-end">
                        <span class="text-muted small text-uppercase fw-semibold">Planned Quantity & Colors</span>
                        <div class="fs-3 fw-bold text-dark">
                            <?= (int)$lot['total_pieces'] ?> <span class="fs-6 text-muted font-normal">Total Pieces</span>
                        </div>
                        <div class="small text-muted">
                            <strong><?= count($colorSummary) ?> Colors:</strong>
                            <?php foreach ($colorSummary as $cs): ?>
                                <span class="badge bg-white text-dark border me-1 font-monospace"><?= clean($cs['color_code']) ?> (<?= $cs['total_qty'] ?>)</span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12 col-md-3 border-end">
                        <span class="text-muted small text-uppercase fw-semibold">Cutting Progress</span>
                        <div class="fs-4 fw-bold <?= $cuttingDone >= $lot['total_pieces'] ? 'text-success' : 'text-warning' ?>">
                            <?= $cuttingDone ?> / <?= (int)$lot['total_pieces'] ?> pcs
                        </div>
                        <div class="small text-muted">
                            Master: <strong>علی رضا</strong> | Date: <?= clean($cuttingDate) ?>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <span class="text-muted small text-uppercase fw-semibold">Batch Status</span>
                        <div>
                            <span class="badge bg-success-subtle text-success border border-success fs-6 px-3 py-1">
                                <?= clean($lot['status']) ?>
                            </span>
                        </div>
                        <div class="small text-muted mt-1">
                            Shift: <?= clean($lot['shift_type']) ?> | Date: <?= format_date($lot['lot_date']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- 2. LEFT: OPERATOR SECTION & ELEMENT TOTALS (TOP BOX IN PHYSICAL SHEET) -->
            <div class="col-12 col-lg-5">
                <div class="card-modern shadow-sm h-100">
                    <div class="card-modern-header d-flex justify-content-between align-items-center bg-warning-subtle border-warning-subtle">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-cpu-fill text-warning me-1"></i> Operator Section (آپریٹر سیکشن)
                        </h6>
                        <a href="<?= BASE_URL ?>modules/production/operator_stage.php?lot_id=<?= $lotId ?>" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold">
                            + Edit Entries
                        </a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0 text-center" style="font-size: 0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-start">Element (حصہ)</th>
                                        <th>Target</th>
                                        <th>Op 1</th>
                                        <th>Op 2</th>
                                        <th>Total</th>
                                        <th>Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $allElementsComplete = true;
                                    foreach ($operatorEntries as $oe):
                                        $req = (int)$oe['required_pieces'];
                                        $done = (int)$oe['operator1_pieces'] + (int)$oe['operator2_pieces'];
                                        $pending = max(0, $req - $done);
                                        if ($pending > 0) $allElementsComplete = false;
                                    ?>
                                        <tr class="<?= $pending > 0 ? 'table-warning' : '' ?>">
                                            <td class="text-start fw-bold text-dark">
                                                <?= clean($oe['element_name']) ?>
                                            </td>
                                            <td class="fw-bold text-muted"><?= $req ?></td>
                                            <td class="fw-semibold"><?= (int)$oe['operator1_pieces'] ?></td>
                                            <td class="fw-semibold"><?= (int)$oe['operator2_pieces'] ?></td>
                                            <td class="fw-bold text-primary"><?= $done ?></td>
                                            <td class="fw-bold <?= $pending > 0 ? 'text-danger bg-danger-subtle' : 'text-success' ?>">
                                                <?= $pending > 0 ? ($pending . ' pending ⚠️') : '✓ Done' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-modern-footer p-2 bg-light border-top text-center small">
                        <?php if ($allElementsComplete): ?>
                            <span class="text-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i> تمام اعضاء مکمل تیار ہیں (All Elements Ready for Stitching)</span>
                        <?php else: ?>
                            <span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i> توجہ فرمائیں: کچھ اعضاء کے پیسز ابھی باقی ہیں (Pending highlighted above)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 3. RIGHT: 6-STAGE PRODUCTION PROGRESS & BOTTLENECK AUDIT -->
            <div class="col-12 col-lg-7">
                <div class="card-modern shadow-sm h-100">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-diagram-3-fill text-primary me-1"></i> Production Stages Progress (مراحل کی پیش رفت)
                        </h6>
                        <span class="badge bg-light text-secondary border">Real-time Stage Ceiling Validation</span>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th class="text-start">Stage Operation</th>
                                        <th>Target</th>
                                        <th>Good Done</th>
                                        <th>Rejected</th>
                                        <th>Pending</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($workflowSteps as $ws): 
                                        $req = (int)$lot['total_pieces'];
                                        $done = (int)$ws['total_done'];
                                        $rej = (int)$ws['total_rejected'];
                                        $pending = max(0, $req - $done);
                                        $pct = $req > 0 ? round(($done / $req) * 100) : 0;
                                        if ($pct > 100) $pct = 100;
                                    ?>
                                        <tr>
                                            <td class="fw-bold text-muted"><?= (int)$ws['sequence'] ?></td>
                                            <td class="text-start fw-bold text-dark">
                                                <?= clean($ws['op_name']) ?>
                                            </td>
                                            <td class="text-muted fw-semibold"><?= $req ?></td>
                                            <td class="fw-bold text-success fs-6"><?= $done ?></td>
                                            <td>
                                                <?php if ($rej > 0): ?>
                                                    <span class="badge bg-danger-subtle text-danger"><?= $rej ?> rej</span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold <?= $pending > 0 ? 'text-danger' : 'text-muted' ?>">
                                                <?= $pending > 0 ? $pending : '0' ?>
                                            </td>
                                            <td style="min-width: 110px;">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: 6px;">
                                                        <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $pct ?>%;"></div>
                                                    </div>
                                                    <span class="small fw-semibold <?= $pct >= 100 ? 'text-success' : 'text-muted' ?>"><?= $pct ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. BOTTOM FULL WIDTH: WORKER OPERATIONS GRID (EXCEL SHEET BOTTOM SECTION) -->
            <div class="col-12">
                <div class="card-modern shadow-sm">
                    <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="bi bi-people-fill text-primary me-1"></i> Worker Operations Grid (کاریگروں کا کام اور کھاتہ لاگ)
                            </h6>
                            <span class="text-muted small">Matches the Arif, Asad, Bilal, Latif row matrix from the handwritten Job Card</span>
                        </div>
                        <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $lotId ?>" class="btn btn-sm btn-primary fw-bold shadow-sm">
                            <i class="bi bi-plus-circle me-1"></i> Record Work for Worker
                        </a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-start" style="min-width: 160px;">Worker Name (کاریگر)</th>
                                        <th style="min-width: 110px;">تاریخ (Last Date)</th>
                                        <?php foreach ($activeOpsInLot as $opId => $opName): ?>
                                            <th style="min-width: 100px;"><?= clean($opName) ?></th>
                                        <?php endforeach; ?>
                                        <th class="table-active" style="width: 110px;">ٹوٹل پیسز</th>
                                        <th style="width: 110px;">منٹ (SAM)</th>
                                        <th style="width: 130px;" class="table-success">اجرت کھاتہ (Rs.)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($workerGrid)): ?>
                                        <tr>
                                            <td colspan="<?= count($activeOpsInLot) + 5 ?>" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox display-6 text-secondary opacity-50 d-block mb-2"></i>
                                                No detailed worker entries recorded for this lot yet. Click "Record Work for Worker" above.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($workerGrid as $wg): ?>
                                            <tr>
                                                <td class="text-start fw-bold text-dark">
                                                    <i class="bi bi-person-badge text-primary me-1"></i>
                                                    <span><?= clean($wg['worker_name']) ?></span>
                                                    <span class="text-muted small font-monospace">(<?= clean($wg['worker_code']) ?>)</span>
                                                </td>
                                                <td class="small text-muted"><?= format_date($wg['last_date']) ?></td>
                                                <?php foreach ($activeOpsInLot as $opId => $opName): 
                                                    $pcs = $wg['operations'][$opId] ?? null;
                                                ?>
                                                    <td>
                                                        <?php if ($pcs): ?>
                                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 px-2 py-1">
                                                                <?= $pcs ?> pcs
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted opacity-25">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="table-active fw-bold fs-6 text-dark"><?= number_format($wg['total_pieces']) ?></td>
                                                <td class="fw-semibold text-secondary"><?= number_format($wg['total_mins'], 1) ?> min</td>
                                                <td class="table-success fw-bold text-success fs-6"><?= format_currency($wg['total_rs']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($workerGrid)): 
                                    $grandPcs = array_sum(array_column($workerGrid, 'total_pieces'));
                                    $grandRs = array_sum(array_column($workerGrid, 'total_rs'));
                                    $grandMins = array_sum(array_column($workerGrid, 'total_mins'));
                                ?>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="<?= count($activeOpsInLot) + 2 ?>" class="text-end pe-3">Grand Total:</td>
                                            <td class="table-active text-dark fs-6"><?= number_format($grandPcs) ?> pcs</td>
                                            <td class="text-secondary"><?= number_format($grandMins, 1) ?> min</td>
                                            <td class="table-success text-success fs-5"><?= format_currency($grandRs) ?></td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div> <!-- end app-content -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
