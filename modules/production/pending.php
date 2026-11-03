<?php
/**
 * Garment Manufacturing ERP - Pending Operations & Shortage Alerts
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Pending Operations & Alerts';
$activeNav = 'pending_work';
$db = get_db();

// Filter
$lotFilter = (int)($_GET['lot_id'] ?? 0);

$lots = $db->query("SELECT id, lot_number, total_pieces FROM lots WHERE status != 'Closed' ORDER BY id DESC")->fetchAll();

// Fetch all active lots with their workflow stages
$sql = "SELECT l.id AS lot_id, l.lot_number, l.lot_date, l.total_pieces, l.status AS lot_status,
               p.name AS product_name, d.design_number,
               lws.sequence, lws.is_mandatory,
               o.id AS operation_id, o.name AS op_name, dep.name AS dept_name,
               (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = l.id AND operation_id = o.id) AS good_done,
               (SELECT COALESCE(SUM(rejected_qty), 0) FROM production_entries WHERE lot_id = l.id AND operation_id = o.id) AS rejected_done,
               (SELECT COALESCE(SUM(rework_qty), 0) FROM production_entries WHERE lot_id = l.id AND operation_id = o.id) AS rework_done
        FROM lots l
        JOIN products p ON l.product_id = p.id
        LEFT JOIN designs d ON l.design_id = d.id
        JOIN lot_workflow_steps lws ON l.id = lws.lot_id
        JOIN operations o ON lws.operation_id = o.id
        LEFT JOIN departments dep ON o.department_id = dep.id
        WHERE l.status != 'Closed'";

if ($lotFilter > 0) {
    $sql .= " AND l.id = " . $lotFilter;
}

$sql .= " ORDER BY l.id DESC, lws.sequence ASC";
$allStages = $db->query($sql)->fetchAll();

// Group by Lot
$grouped = [];
foreach ($allStages as $stg) {
    $grouped[$stg['lot_id']]['info'] = [
        'lot_id' => $stg['lot_id'],
        'lot_number' => $stg['lot_number'],
        'lot_date' => $stg['lot_date'],
        'total_pieces' => $stg['total_pieces'],
        'lot_status' => $stg['lot_status'],
        'product_name' => $stg['product_name'],
        'design_number' => $stg['design_number']
    ];
    $grouped[$stg['lot_id']]['steps'][] = $stg;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Pending Operations & Discrepancy Alerts</h4>
                <p class="text-muted small mb-0">Continuously monitors factory bottlenecks, incomplete stages and shortages</p>
            </div>
            <a href="<?= BASE_URL ?>modules/production/index.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-lightning-charge-fill"></i>
                <span>Fast Entry</span>
            </a>
        </div>

        <!-- Filter Bar -->
        <div class="card-modern mb-4">
            <div class="card-modern-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-12 col-md-4">
                        <select name="lot_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">-- All Active Lots --</option>
                            <?php foreach ($lots as $l): ?>
                                <option value="<?= $l['id'] ?>" <?= $lotFilter === $l['id'] ? 'selected' : '' ?>>
                                    LOT #<?= clean($l['lot_number']) ?> (<?= $l['total_pieces'] ?> pcs)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($lotFilter > 0): ?>
                        <div class="col-auto">
                            <a href="<?= BASE_URL ?>modules/production/pending.php" class="btn btn-sm btn-outline-secondary">Clear Filter</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Lot-wise Pending Stage Cards -->
        <?php if (empty($grouped)): ?>
            <div class="card-modern text-center py-5">
                <i class="bi bi-check-circle text-success fs-1 mb-2"></i>
                <h6 class="fw-bold text-dark">No Pending Work Found</h6>
                <p class="text-muted small">All active operations have been processed or no active lots exist.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $lotId => $data): 
                $info = $data['info'];
                $steps = $data['steps'];
            ?>
                <div class="card-modern mb-4">
                    <div class="card-modern-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                        <div>
                            <span class="fs-5 fw-bold text-dark me-2">LOT #<?= clean($info['lot_number']) ?></span>
                            <span class="badge bg-light text-dark border"><?= clean($info['product_name']) ?></span>
                            <?php if ($info['design_number']): ?>
                                <span class="badge bg-primary-subtle text-primary">Design #<?= clean($info['design_number']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <span class="fw-bold text-dark">Total: <?= number_format($info['total_pieces']) ?> Pcs</span>
                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $info['lot_id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2">
                                <i class="bi bi-eye me-1"></i> Lot Details
                            </a>
                        </div>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Seq</th>
                                        <th>Operation</th>
                                        <th>Department</th>
                                        <th class="text-center">Required</th>
                                        <th class="text-center">Good Done</th>
                                        <th class="text-center">Rejected</th>
                                        <th class="text-center">Rework</th>
                                        <th class="text-center">Pending Balance</th>
                                        <th>Alert Status</th>
                                        <th class="text-end">Quick Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($steps as $st): 
                                        $good = (int)$st['good_done'];
                                        $total = (int)$info['total_pieces'];
                                        $pending = max(0, $total - $good);
                                        $rej = (int)$st['rejected_done'];
                                        $rew = (int)$st['rework_done'];

                                        $isOver = ($good > $total);
                                        $isComplete = ($good === $total);
                                        $isNotStarted = ($good === 0);
                                        $isShortage = (!$isComplete && !$isOver && $good > 0);
                                    ?>
                                        <tr class="<?= $isOver ? 'table-danger bg-opacity-10' : ($isShortage ? 'table-warning bg-opacity-10' : '') ?>">
                                            <td class="fw-bold text-center"><?= $st['sequence'] ?></td>
                                            <td class="fw-bold text-dark">
                                                <?= clean($st['op_name']) ?>
                                                <?php if (!$st['is_mandatory']): ?>
                                                    <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.7rem;">Optional</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= clean($st['dept_name']) ?></td>
                                            <td class="text-center fw-bold"><?= number_format($total) ?></td>
                                            <td class="text-center fw-bold <?= $isOver ? 'text-danger' : 'text-success' ?>"><?= number_format($good) ?></td>
                                            <td class="text-center <?= $rej > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= number_format($rej) ?></td>
                                            <td class="text-center <?= $rew > 0 ? 'text-warning fw-bold' : 'text-muted' ?>"><?= number_format($rew) ?></td>
                                            <td class="text-center fw-bold fs-6 <?= $isOver ? 'text-danger' : ($pending > 0 ? 'text-warning-emphasis' : 'text-success') ?>">
                                                <?= $isOver ? '<span class="text-danger fw-bold">+' . number_format($good - $total) . ' Over</span>' : number_format($pending) ?>
                                            </td>
                                            <td>
                                                <?php if ($isOver): ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold px-2 py-1">
                                                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Over (+<?= number_format($good - $total) ?> pcs)
                                                    </span>
                                                <?php elseif ($isComplete): ?>
                                                    <span class="badge-status badge-completed"><i class="bi bi-check-circle-fill"></i> Completed</span>
                                                <?php elseif ($isShortage): ?>
                                                    <span class="badge-status badge-critical">
                                                        <span class="pulse-indicator"></span>
                                                        <span><?= $pending ?> Short / Incomplete</span>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge-status badge-draft"><i class="bi bi-hourglass"></i> Not Started</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (!$isComplete && !$isOver): ?>
                                                    <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $info['lot_id'] ?>&operation_id=<?= $st['operation_id'] ?>" class="btn btn-sm btn-outline-warning py-1 px-2">
                                                        <i class="bi bi-plus-lg me-1"></i> Record Work
                                                    </a>
                                                <?php elseif ($isOver): ?>
                                                    <span class="badge bg-danger-subtle text-danger border" title="Capacity exceeded: <?= $good - $total ?> pieces over lot limit"><i class="bi bi-slash-circle me-1"></i> Max Exceeded</span>
                                                <?php else: ?>
                                                    <span class="text-muted small"><i class="bi bi-check-all text-success fs-5"></i></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
