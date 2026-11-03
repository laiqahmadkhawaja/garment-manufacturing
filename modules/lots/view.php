<?php
/**
 * Garment Manufacturing ERP - Complete Lot Details & Tracking Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$lotId = (int)($_GET['id'] ?? 0);
$db = get_db();

// Fetch Lot Master
$stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code, p.product_type,
                             d.design_number, d.name AS design_name, u.full_name AS creator_name 
                      FROM lots l
                      JOIN products p ON l.product_id = p.id
                      LEFT JOIN designs d ON l.design_id = d.id
                      LEFT JOIN users u ON l.created_by = u.id
                      WHERE l.id = ?");
$stmt->execute([$lotId]);
$lot = $stmt->fetch();

if (!$lot) {
    redirect('modules/lots/index.php', 'Lot not found.', 'danger');
}

$pageTitle = "Lot #" . $lot['lot_number'] . " Details";
$activeNav = 'lots';

// Status change handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $newStatus = trim($_POST['status'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $validStatuses = ['Draft', 'In Production', 'Production Completed', 'Partially Packed', 'Ready Stock', 'Partially Dispatched', 'Dispatched', 'Closed'];

    if (in_array($newStatus, $validStatuses, true) && $newStatus !== $lot['status']) {
        $db->beginTransaction();
        try {
            $oldStatus = $lot['status'];
            $up = $db->prepare("UPDATE lots SET status = ? WHERE id = ?");
            $up->execute([$newStatus, $lotId]);

            $hStmt = $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, ?, ?, ?, ?)");
            $hStmt->execute([$lotId, $oldStatus, $newStatus, $remarks ?: "Status manually changed to $newStatus", $_SESSION['user_id'] ?? null]);

            log_audit('UPDATE_STATUS', 'lots', $lotId, ['status' => $oldStatus], ['status' => $newStatus]);
            $db->commit();
            redirect("modules/lots/view.php?id=$lotId", "Lot status updated to '$newStatus'!");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Failed to update status: ' . $e->getMessage());
        }
    }
}

// 1. Color & Size Variants Matrix
$vStmt = $db->prepare("SELECT lv.*, c.name AS color_name, c.hex_code, s.name AS size_name, s.code AS size_code 
                       FROM lot_variants lv 
                       JOIN colors c ON lv.color_id = c.id 
                       JOIN sizes s ON lv.size_id = s.id 
                       WHERE lv.lot_id = ? 
                       ORDER BY c.name ASC, s.sort_order ASC");
$vStmt->execute([$lotId]);
$variants = $vStmt->fetchAll();

// 2. Production Tracking by Workflow Steps
$wStmt = $db->prepare("SELECT lws.*, o.name AS op_name, o.code AS op_code, o.default_rate, dep.name AS dept_name,
                       (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id) AS good_done,
                       (SELECT COALESCE(SUM(rejected_qty), 0) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id) AS rejected_done,
                       (SELECT COALESCE(SUM(rework_qty), 0) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id) AS rework_done,
                       (SELECT COALESCE(SUM(labour_amount), 0) FROM production_entries WHERE lot_id = lws.lot_id AND operation_id = lws.operation_id) AS labour_total
                       FROM lot_workflow_steps lws
                       JOIN operations o ON lws.operation_id = o.id
                       LEFT JOIN departments dep ON o.department_id = dep.id
                       WHERE lws.lot_id = ?
                       ORDER BY lws.sequence ASC");
$wStmt->execute([$lotId]);
$workflowSteps = $wStmt->fetchAll();

// 3. Worker Entries on this Lot
$weStmt = $db->prepare("SELECT pe.*, w.name AS worker_name, w.code AS worker_code, o.name AS op_name, c.name AS color_name, s.name AS size_name 
                        FROM production_entries pe 
                        JOIN workers w ON pe.worker_id = w.id 
                        JOIN operations o ON pe.operation_id = o.id 
                        JOIN colors c ON pe.color_id = c.id 
                        JOIN sizes s ON pe.size_id = s.id 
                        WHERE pe.lot_id = ? 
                        ORDER BY pe.id DESC");
$weStmt->execute([$lotId]);
$workerEntries = $weStmt->fetchAll();

// 4. Material Issues & Returns
$miStmt = $db->prepare("SELECT mii.*, mi.issue_number, mi.issue_date, m.name AS material_name, m.code AS material_code, u.code AS unit_code 
                        FROM material_issue_items mii 
                        JOIN material_issues mi ON mii.issue_id = mi.id 
                        JOIN materials m ON mii.material_id = m.id 
                        JOIN units u ON m.unit_id = u.id 
                        WHERE mi.lot_id = ? 
                        ORDER BY mi.issue_date DESC");
$miStmt->execute([$lotId]);
$materialIssues = $miStmt->fetchAll();

$mrStmt = $db->prepare("SELECT mri.*, mr.return_number, mr.return_date, m.name AS material_name, u.code AS unit_code 
                        FROM material_return_items mri 
                        JOIN material_returns mr ON mri.return_id = mr.id 
                        JOIN materials m ON mri.material_id = m.id 
                        JOIN units u ON m.unit_id = u.id 
                        WHERE mr.lot_id = ? 
                        ORDER BY mr.return_date DESC");
$mrStmt->execute([$lotId]);
$materialReturns = $mrStmt->fetchAll();

// Material Cost calculations
$totalMaterialIssuedCost = 0;
foreach ($materialIssues as $mi) { $totalMaterialIssuedCost += (float)$mi['total_cost']; }
$totalMaterialReturnedCost = 0;
foreach ($materialReturns as $mr) { $totalMaterialReturnedCost += (float)$mr['total_cost']; }
$netMaterialCost = max(0, $totalMaterialIssuedCost - $totalMaterialReturnedCost);

// 5. Packing Entries
$pkStmt = $db->prepare("SELECT pe.*, c.name AS color_name, s.name AS size_name, w.name AS worker_name, wh.name AS warehouse_name 
                        FROM packing_entries pe 
                        JOIN colors c ON pe.color_id = c.id 
                        JOIN sizes s ON pe.size_id = s.id 
                        LEFT JOIN workers w ON pe.packing_worker_id = w.id 
                        JOIN warehouses wh ON pe.warehouse_id = wh.id 
                        WHERE pe.lot_id = ? 
                        ORDER BY pe.id DESC");
$pkStmt->execute([$lotId]);
$packingEntries = $pkStmt->fetchAll();
$totalPackedPcs = 0;
$totalPackingLabour = 0;
foreach ($packingEntries as $pk) {
    $totalPackedPcs += (int)$pk['packed_qty'];
    $totalPackingLabour += (float)$pk['labour_amount'];
}

// 6. Finished Goods Warehouse Stock
$fgStmt = $db->prepare("SELECT fg.*, c.name AS color_name, s.name AS size_name, wh.name AS warehouse_name 
                        FROM finished_goods_stock fg 
                        JOIN colors c ON fg.color_id = c.id 
                        JOIN sizes s ON fg.size_id = s.id 
                        JOIN warehouses wh ON fg.warehouse_id = wh.id 
                        WHERE fg.lot_id = ?");
$fgStmt->execute([$lotId]);
$fgStocks = $fgStmt->fetchAll();

// 7. Costing & Total Labour
$totalProductionLabour = 0;
foreach ($workflowSteps as $ws) {
    $totalProductionLabour += (float)$ws['labour_total'];
}
$grandTotalCost = $netMaterialCost + $totalProductionLabour + $totalPackingLabour;
$costPerPiece = $lot['total_pieces'] > 0 ? ($grandTotalCost / $lot['total_pieces']) : 0;

// 8. History
$histStmt = $db->prepare("SELECT lsh.*, u.full_name AS changer_name 
                          FROM lot_status_history lsh 
                          LEFT JOIN users u ON lsh.changed_by = u.id 
                          WHERE lsh.lot_id = ? 
                          ORDER BY lsh.id DESC");
$histStmt->execute([$lotId]);
$statusHistory = $histStmt->fetchAll();

// Overall progress calculation based on last operation
$lastOpGood = 0;
if (!empty($workflowSteps)) {
    $lastOp = end($workflowSteps);
    $lastOpGood = (int)$lastOp['good_done'];
}
$progressPercent = $lot['total_pieces'] > 0 ? min(100, round(($lastOpGood / $lot['total_pieces']) * 100)) : 0;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Lot Header Card -->
        <div class="card-modern mb-4 border-primary-subtle shadow-sm">
            <div class="card-modern-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 pb-3 border-bottom mb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="fs-4 fw-bold text-dark">LOT #<?= clean($lot['lot_number']) ?></span>
                            <?php
                            $st = $lot['status'];
                            $badgeClass = match($st) {
                                'Draft' => 'badge-draft',
                                'In Production' => 'badge-progress',
                                'Production Completed' => 'badge-completed',
                                'Partially Packed' => 'badge-pending',
                                'Ready Stock' => 'badge-ready',
                                'Partially Dispatched' => 'badge-pending',
                                'Dispatched' => 'badge-dispatched',
                                'Closed' => 'badge-draft',
                                default => 'badge-draft'
                            };
                            ?>
                            <span class="badge-status <?= $badgeClass ?> fs-6"><?= clean($st) ?></span>
                        </div>
                        <div class="text-muted small">
                            <span class="fw-semibold text-dark"><?= clean($lot['product_name']) ?></span> (<?= clean($lot['product_type']) ?>)
                            <span class="mx-2">•</span>
                            <?= $lot['design_number'] ? '<span class="fw-semibold text-primary">Design #' . clean($lot['design_number']) . '</span>' : '<span class="text-warning fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>No Design (Editable)</span>' ?>
                            <span class="mx-2">•</span>
                            Started: <?= format_date($lot['lot_date']) ?>
                            <?php if ($lot['supervisor_name']): ?>
                                <span class="mx-2">•</span> Supervisor: <?= clean($lot['supervisor_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Action Buttons -->
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $lot['id'] ?>" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                            <i class="bi bi-lightning-charge-fill"></i>
                            <span>Production Entry</span>
                        </a>
                        <a href="<?= BASE_URL ?>modules/packing/index.php?lot_id=<?= $lot['id'] ?>" class="btn btn-outline-info btn-sm d-inline-flex align-items-center gap-1">
                            <i class="bi bi-box-seam-fill"></i>
                            <span>Pack Pieces</span>
                        </a>
                        <a href="<?= BASE_URL ?>modules/lots/print_job_sheet.php?id=<?= $lot['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
                            <i class="bi bi-printer"></i>
                            <span>Job Sheet</span>
                        </a>
                        <a href="<?= BASE_URL ?>modules/lots/edit.php?id=<?= $lot['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Edit details / Design #">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <!-- Status Changer Dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-dark dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                Status: <?= clean($lot['status']) ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <?php foreach (['Draft', 'In Production', 'Production Completed', 'Partially Packed', 'Ready Stock', 'Closed'] as $sOpt): ?>
                                    <li>
                                        <form method="POST" action="">
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="status" value="<?= $sOpt ?>">
                                            <button type="submit" class="dropdown-item small <?= $sOpt === $lot['status'] ? 'active' : '' ?>">
                                                <?= $sOpt ?>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- KPI Metric Pills -->
                <div class="row g-3 text-center text-sm-start">
                    <div class="col-6 col-md-3">
                        <div class="p-2 rounded bg-light border">
                            <div class="text-muted small">Total Lot Pieces</div>
                            <div class="fs-4 fw-bold text-dark"><?= number_format($lot['total_pieces']) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-2 rounded bg-light border">
                            <div class="text-muted small">Fully Packed</div>
                            <div class="fs-4 fw-bold text-success"><?= number_format($totalPackedPcs) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-2 rounded bg-light border">
                            <div class="text-muted small">Pending Packing</div>
                            <div class="fs-4 fw-bold text-warning"><?= max(0, $lot['total_pieces'] - $totalPackedPcs) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-2 rounded bg-light border">
                            <div class="text-muted small">Variants Distribution</div>
                            <div class="fs-5 fw-bold text-dark"><?= $lot['total_colors'] ?> Colors · <?= $lot['total_sizes'] ?> Sizes</div>
                        </div>
                    </div>
                </div>

                <!-- Overall Progress Bar -->
                <div class="mt-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Production Stage Completion:</span>
                        <span class="fw-bold text-dark"><?= $progressPercent ?>%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $progressPercent ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lot Tabs Navigation -->
        <ul class="nav nav-tabs nav-fill mb-4" id="lotTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-overview">
                    <i class="bi bi-grid-3x3 me-1"></i> Overview & Variants
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-production">
                    <i class="bi bi-gear-wide-connected me-1"></i> Production Stages
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-workers">
                    <i class="bi bi-people me-1"></i> Worker Records (<?= count($workerEntries) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-materials">
                    <i class="bi bi-boxes me-1"></i> Materials (<?= count($materialIssues) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-packing">
                    <i class="bi bi-box-seam me-1"></i> Packing (<?= count($packingEntries) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-inventory">
                    <i class="bi bi-tags me-1"></i> Finished Goods
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-costing">
                    <i class="bi bi-calculator me-1"></i> Lot Costing
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-history">
                    <i class="bi bi-clock-history me-1"></i> History
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- TAB 1: OVERVIEW & VARIANTS -->
            <div class="tab-pane fade show active" id="tab-overview">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Color & Size Planned Quantities</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Color</th>
                                        <th>Size</th>
                                        <th>Size Code</th>
                                        <th class="text-end">Planned Quantity</th>
                                        <th class="text-end">Good Output (Stitching)</th>
                                        <th class="text-end">Packed Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($variants as $v): 
                                        // Good output for this variant
                                        $goodStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND color_id = ? AND size_id = ?");
                                        $goodStmt->execute([$lotId, $v['color_id'], $v['size_id']]);
                                        $vGood = (int)$goodStmt->fetchColumn();

                                        // Packed for this variant
                                        $pStmt = $db->prepare("SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = ? AND color_id = ? AND size_id = ?");
                                        $pStmt->execute([$lotId, $v['color_id'], $v['size_id']]);
                                        $vPacked = (int)$pStmt->fetchColumn();
                                    ?>
                                        <tr>
                                            <td class="fw-semibold text-dark">
                                                <span class="d-inline-block rounded-circle border me-2 align-middle" style="width: 14px; height: 14px; background-color: <?= clean($v['hex_code']) ?>;"></span>
                                                <?= clean($v['color_name']) ?>
                                            </td>
                                            <td><?= clean($v['size_name']) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= clean($v['size_code']) ?></span></td>
                                            <td class="text-end fw-bold text-dark"><?= number_format($v['quantity']) ?> pcs</td>
                                            <td class="text-end fw-semibold text-primary"><?= number_format($vGood) ?> pcs</td>
                                            <td class="text-end fw-semibold text-success"><?= number_format($vPacked) ?> pcs</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: PRODUCTION STAGES & RECONCILIATION -->
            <div class="tab-pane fade" id="tab-production">
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">Stage-wise Quantity Reconciliation & Pending Discrepancies</h6>
                        <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $lotId ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg me-1"></i> Add Work Entry
                        </a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Seq</th>
                                        <th>Operation</th>
                                        <th>Department</th>
                                        <th class="text-center">Required (Lot)</th>
                                        <th class="text-center">Good Completed</th>
                                        <th class="text-center">Rejected</th>
                                        <th class="text-center">Rework</th>
                                        <th class="text-center">Pending / Short</th>
                                        <th class="text-end">Labour Cost</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($workflowSteps as $ws): 
                                        $pending = max(0, $lot['total_pieces'] - (int)$ws['good_done']);
                                        $isShortage = ((int)$ws['good_done'] > 0 && (int)$ws['good_done'] < $lot['total_pieces']);
                                    ?>
                                        <tr>
                                            <td><span class="badge bg-light text-dark border"><?= $ws['sequence'] ?></span></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= clean($ws['op_name']) ?></div>
                                                <div class="text-muted small">Standard Rate: Rs. <?= number_format($ws['default_rate'], 2) ?></div>
                                            </td>
                                            <td><?= clean($ws['dept_name']) ?></td>
                                            <td class="text-center fw-bold"><?= number_format($lot['total_pieces']) ?></td>
                                            <td class="text-center fw-bold text-success"><?= number_format($ws['good_done']) ?></td>
                                            <td class="text-center <?= (int)$ws['rejected_done'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                                                <?= number_format($ws['rejected_done']) ?>
                                            </td>
                                            <td class="text-center <?= (int)$ws['rework_done'] > 0 ? 'text-warning fw-bold' : 'text-muted' ?>">
                                                <?= number_format($ws['rework_done']) ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($pending > 0): ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis fw-bold">
                                                        <?php if ($isShortage): ?><span class="pulse-indicator me-1"></span><?php endif; ?>
                                                        <?= number_format($pending) ?> Pending
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-success-subtle text-success fw-bold">Complete</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold text-dark"><?= format_currency($ws['labour_total']) ?></td>
                                            <td>
                                                <?php if ((int)$ws['good_done'] >= $lot['total_pieces']): ?>
                                                    <span class="badge-status badge-completed"><i class="bi bi-check-circle-fill"></i> Completed</span>
                                                <?php elseif ((int)$ws['good_done'] > 0): ?>
                                                    <span class="badge-status badge-progress"><i class="bi bi-arrow-repeat"></i> In Progress</span>
                                                <?php else: ?>
                                                    <span class="badge-status badge-draft"><i class="bi bi-hourglass"></i> Not Started</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($pending > 0): ?>
                                                    <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $lot['id'] ?>&operation_id=<?= $ws['operation_id'] ?>" class="btn btn-sm btn-outline-warning py-0 px-2 fw-semibold" style="font-size: 0.78rem;">
                                                        <i class="bi bi-plus-lg me-1"></i> Record
                                                    </a>
                                                <?php else: ?>
                                                    <i class="bi bi-check-circle-fill text-success"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: WORKER ENTRIES -->
            <div class="tab-pane fade" id="tab-workers">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Artisan Work Submissions on this Lot</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Worker</th>
                                        <th>Operation</th>
                                        <th>Variant</th>
                                        <th class="text-center">Good Qty</th>
                                        <th class="text-center">Rejected</th>
                                        <th class="text-center">Rework</th>
                                        <th class="text-end">Applied Rate</th>
                                        <th class="text-end">Labour Earned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($workerEntries)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                No worker entries recorded yet. <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $lotId ?>">Record work now</a>.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($workerEntries as $we): ?>
                                            <tr>
                                                <td><?= format_date($we['entry_date']) ?></td>
                                                <td>
                                                    <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $we['worker_id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                        <?= clean($we['worker_name']) ?>
                                                    </a>
                                                    <div class="text-muted small"><?= clean($we['worker_code']) ?></div>
                                                </td>
                                                <td class="fw-semibold text-dark"><?= clean($we['op_name']) ?></td>
                                                <td>
                                                    <?= clean($we['color_name']) ?> / <span class="badge bg-light text-dark border"><?= clean($we['size_name']) ?></span>
                                                </td>
                                                <td class="text-center fw-bold text-success"><?= number_format($we['good_qty']) ?></td>
                                                <td class="text-center text-danger"><?= number_format($we['rejected_qty']) ?></td>
                                                <td class="text-center text-warning"><?= number_format($we['rework_qty']) ?></td>
                                                <td class="text-end">Rs. <?= number_format($we['rate'], 2) ?></td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency($we['labour_amount']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: MATERIALS -->
            <div class="tab-pane fade" id="tab-materials">
                <div class="row g-4">
                    <div class="col-12 col-lg-7">
                        <div class="card-modern">
                            <div class="card-modern-header d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-dark">Raw Materials Issued to Lot</h6>
                                <a href="<?= BASE_URL ?>modules/raw_materials/issues.php?lot_id=<?= $lotId ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-box-arrow-right me-1"></i> Issue Material
                                </a>
                            </div>
                            <div class="card-modern-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-modern align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Issue #</th>
                                                <th>Material</th>
                                                <th class="text-end">Issued Qty</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-end">Cost</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($materialIssues)): ?>
                                                <tr><td colspan="5" class="text-center py-3 text-muted">No materials issued yet.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($materialIssues as $mi): ?>
                                                    <tr>
                                                        <td><?= clean($mi['issue_number']) ?><div class="text-muted small"><?= format_date($mi['issue_date']) ?></div></td>
                                                        <td class="fw-semibold text-dark"><?= clean($mi['material_name']) ?></td>
                                                        <td class="text-end fw-bold"><?= number_format($mi['quantity'], 2) ?> <?= clean($mi['unit_code']) ?></td>
                                                        <td class="text-end">Rs. <?= number_format($mi['unit_price'], 2) ?></td>
                                                        <td class="text-end fw-bold"><?= format_currency($mi['total_cost']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-5">
                        <div class="card-modern">
                            <div class="card-modern-header d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-dark">Returned Raw Materials</h6>
                                <a href="<?= BASE_URL ?>modules/raw_materials/returns.php?lot_id=<?= $lotId ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-box-arrow-in-left me-1"></i> Return Material
                                </a>
                            </div>
                            <div class="card-modern-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-modern align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Return #</th>
                                                <th>Material</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Cost Credit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($materialReturns)): ?>
                                                <tr><td colspan="4" class="text-center py-3 text-muted">No returns recorded.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($materialReturns as $mr): ?>
                                                    <tr>
                                                        <td><?= clean($mr['return_number']) ?><div class="text-muted small"><?= format_date($mr['return_date']) ?></div></td>
                                                        <td class="fw-semibold text-dark"><?= clean($mr['material_name']) ?></td>
                                                        <td class="text-end fw-bold"><?= number_format($mr['quantity'], 2) ?> <?= clean($mr['unit_code']) ?></td>
                                                        <td class="text-end fw-bold text-success">- <?= format_currency($mr['total_cost']) ?></td>
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

            <!-- TAB 5: PACKING -->
            <div class="tab-pane fade" id="tab-packing">
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Packing Entries</h6>
                            <span class="text-muted small">Transforms completed production pieces into finished goods warehouse stock</span>
                        </div>
                        <a href="<?= BASE_URL ?>modules/packing/index.php?lot_id=<?= $lotId ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-box-seam-fill me-1"></i> New Packing Entry
                        </a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Packing #</th>
                                        <th>Date</th>
                                        <th>Color & Size</th>
                                        <th class="text-center">Packed Qty</th>
                                        <th>Package / Box</th>
                                        <th>Packing Worker</th>
                                        <th class="text-end">Labour</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($packingEntries)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">No packing recorded yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($packingEntries as $pk): ?>
                                            <tr>
                                                <td class="fw-bold text-primary"><?= clean($pk['packing_number']) ?></td>
                                                <td><?= format_date($pk['packing_date']) ?></td>
                                                <td><?= clean($pk['color_name']) ?> / <span class="badge bg-light text-dark border"><?= clean($pk['size_name']) ?></span></td>
                                                <td class="text-center fw-bold text-success fs-6"><?= number_format($pk['packed_qty']) ?> pcs</td>
                                                <td><?= clean($pk['package_type']) ?> #<?= clean($pk['package_no'] ?: '-') ?></td>
                                                <td><?= clean($pk['worker_name'] ?: 'In-house') ?></td>
                                                <td class="text-end fw-bold"><?= format_currency($pk['labour_amount']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 6: FINISHED GOODS INVENTORY -->
            <div class="tab-pane fade" id="tab-inventory">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Finished Warehouse Stock Traceable to Lot #<?= clean($lot['lot_number']) ?></h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Warehouse</th>
                                        <th>Color</th>
                                        <th>Size</th>
                                        <th class="text-end">Current Stock</th>
                                        <th class="text-end">Dispatched Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($fgStocks)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">No finished goods in stock yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($fgStocks as $fg): ?>
                                            <tr>
                                                <td class="fw-semibold text-dark"><?= clean($fg['warehouse_name']) ?></td>
                                                <td><?= clean($fg['color_name']) ?></td>
                                                <td><span class="badge bg-light text-dark border"><?= clean($fg['size_name']) ?></span></td>
                                                <td class="text-end fw-bold text-success fs-6"><?= number_format($fg['current_stock']) ?> pcs</td>
                                                <td class="text-end fw-bold text-secondary"><?= number_format($fg['dispatched_stock']) ?> pcs</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 7: COSTING & PROFITABILITY -->
            <div class="tab-pane fade" id="tab-costing">
                <div class="row g-4">
                    <div class="col-12 col-md-6">
                        <div class="card-modern">
                            <div class="card-modern-header">
                                <h6 class="fw-bold mb-0 text-dark">Lot Cost Breakdown</h6>
                            </div>
                            <div class="card-modern-body p-0">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                        <span class="fw-semibold text-dark"><i class="bi bi-boxes me-2 text-primary"></i> Net Raw Material Cost</span>
                                        <span class="fw-bold text-dark fs-6"><?= format_currency($netMaterialCost) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                        <span class="fw-semibold text-dark"><i class="bi bi-people me-2 text-warning"></i> Production Stages Labour</span>
                                        <span class="fw-bold text-dark fs-6"><?= format_currency($totalProductionLabour) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                                        <span class="fw-semibold text-dark"><i class="bi bi-box-seam me-2 text-info"></i> Packing Labour Cost</span>
                                        <span class="fw-bold text-dark fs-6"><?= format_currency($totalPackingLabour) ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-3 bg-light table-active">
                                        <span class="fw-bold fs-6 text-dark">Total Batch Production Cost</span>
                                        <span class="fw-bold fs-5 text-primary"><?= format_currency($grandTotalCost) ?></span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="card-modern text-center p-4">
                            <div class="text-muted small text-uppercase fw-bold mb-2">Unit Cost Calculation</div>
                            <div class="display-5 fw-bold text-dark mb-2"><?= format_currency($costPerPiece) ?></div>
                            <p class="text-muted small mb-0">Total Cost (<?= format_currency($grandTotalCost) ?>) ÷ <?= number_format($lot['total_pieces']) ?> Accepted Pieces</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 8: AUDIT & HISTORY -->
            <div class="tab-pane fade" id="tab-history">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Lot Lifecycle & Status History</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Old Status</th>
                                        <th>New Status</th>
                                        <th>Remarks</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statusHistory as $sh): ?>
                                        <tr>
                                            <td><?= date('d M Y, h:i A', strtotime($sh['created_at'])) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= clean($sh['old_status'] ?: 'Start') ?></span></td>
                                            <td><span class="badge-status badge-progress"><?= clean($sh['new_status']) ?></span></td>
                                            <td class="text-muted small"><?= clean($sh['remarks']) ?></td>
                                            <td><?= clean($sh['changer_name'] ?: 'System') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
