<?php
/**
 * Garment Manufacturing ERP - Production History & Log
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Production History';
$activeNav = 'production_history';
$db = get_db();

// Filters
$lotId = (int)($_GET['lot_id'] ?? 0);
$workerId = (int)($_GET['worker_id'] ?? 0);
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$sql = "SELECT pe.*, l.lot_number, l.status AS lot_status, p.name AS product_name, o.name AS op_name, w.name AS worker_name, w.code AS worker_code, 
               c.name AS color_name, s.name AS size_name, u.full_name AS recorder_name 
        FROM production_entries pe 
        JOIN lots l ON pe.lot_id = l.id 
        JOIN products p ON l.product_id = p.id 
        JOIN operations o ON pe.operation_id = o.id 
        JOIN workers w ON pe.worker_id = w.id 
        LEFT JOIN colors c ON pe.color_id = c.id 
        LEFT JOIN sizes s ON pe.size_id = s.id 
        LEFT JOIN users u ON pe.created_by = u.id 
        WHERE 1=1";

$params = [];
if ($lotId > 0) {
    $sql .= " AND pe.lot_id = ?";
    $params[] = $lotId;
}
if ($workerId > 0) {
    $sql .= " AND pe.worker_id = ?";
    $params[] = $workerId;
}
if (!empty($dateFrom)) {
    $sql .= " AND pe.entry_date >= ?";
    $params[] = $dateFrom;
}
if (!empty($dateTo)) {
    $sql .= " AND pe.entry_date <= ?";
    $params[] = $dateTo;
}

$sql .= " ORDER BY pe.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$lots = $db->query("SELECT id, lot_number FROM lots ORDER BY id DESC")->fetchAll();
$workers = $db->query("SELECT id, name FROM workers ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production History Log</h4>
                <p class="text-muted small mb-0">Complete audit log of all artisan work entries and labour calculations</p>
            </div>
            <a href="<?= BASE_URL ?>modules/production/index.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-plus-lg"></i>
                <span>New Work Entry</span>
            </a>
        </div>

        <!-- Filter Card -->
        <div class="card-modern mb-4">
            <div class="card-modern-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-12 col-sm-6 col-md-3">
                        <select name="lot_id" class="form-select form-select-sm">
                            <option value="">-- All Lots --</option>
                            <?php foreach ($lots as $l): ?>
                                <option value="<?= $l['id'] ?>" <?= $lotId === $l['id'] ? 'selected' : '' ?>>LOT #<?= clean($l['lot_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <select name="worker_id" class="form-select form-select-sm">
                            <option value="">-- All Workers --</option>
                            <?php foreach ($workers as $w): ?>
                                <option value="<?= $w['id'] ?>" <?= $workerId === $w['id'] ? 'selected' : '' ?>><?= clean($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= clean($dateFrom) ?>" placeholder="From Date">
                    </div>
                    <div class="col-6 col-md-2">
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= clean($dateTo) ?>" placeholder="To Date">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-secondary w-100">Filter</button>
                        <a href="<?= BASE_URL ?>modules/production/history.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($lotId > 0 && !empty($entries)): 
            $totLotPlanned = (int)$db->query("SELECT total_pieces FROM lots WHERE id = $lotId")->fetchColumn();
            $totLotGood = (int)$db->query("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = $lotId")->fetchColumn();
            $totLotRej = (int)$db->query("SELECT COALESCE(SUM(rejected_qty), 0) FROM production_entries WHERE lot_id = $lotId")->fetchColumn();
            $totLotRew = (int)$db->query("SELECT COALESCE(SUM(rework_qty), 0) FROM production_entries WHERE lot_id = $lotId")->fetchColumn();
            $totLotLabour = (float)$db->query("SELECT COALESCE(SUM(labour_amount), 0) FROM production_entries WHERE lot_id = $lotId")->fetchColumn();
            $totLotUnmade = max(0, $totLotPlanned - ($totLotGood + $totLotRej + $totLotRew));
        ?>
        <div class="card-modern mb-3 bg-light border p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-pie-chart-fill text-primary me-2"></i> لاٹ پروڈکشن لیجر سمری (Lot Production Audit Summary)
                </h6>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-white text-dark border p-2">🎯 کل منصوبہ بندی (Planned): <strong><?= number_format($totLotPlanned) ?> pcs</strong></span>
                    <span class="badge bg-success text-white p-2">✅ کل تیار (Good Done): <strong><?= number_format($totLotGood) ?> pcs</strong></span>
                    <span class="badge bg-danger text-white p-2">🔴 کل خراب (Rejected): <strong><?= number_format($totLotRej) ?> pcs</strong></span>
                    <span class="badge bg-warning text-dark p-2">🟡 کل ری ورک (In Rework): <strong><?= number_format($totLotRew) ?> pcs</strong></span>
                    <span class="badge bg-secondary text-white p-2">⏳ بنے ہی نہیں (Unproduced): <strong><?= number_format($totLotUnmade) ?> pcs</strong></span>
                    <span class="badge bg-primary text-white p-2">💰 کل اجرت (Total Labour): <strong><?= format_currency($totLotLabour) ?></strong></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- History Table -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Production Records (<?= count($entries) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Entry #</th>
                                <th>Date</th>
                                <th>Lot Number</th>
                                <th>Operation</th>
                                <th>Artisan / Worker</th>
                                <th>Color & Size</th>
                                <th class="text-center">Good Qty</th>
                                <th class="text-center">Rejected</th>
                                <th class="text-center">Rework</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Labour Amount</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entries)): ?>
                                <tr><td colspan="12" class="text-center py-4 text-muted">No production history entries found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($entries as $e): 
                                    $isClosed = in_array($e['lot_status'], ['Closed', 'Dispatched', 'Completed', 'Archived']);
                                ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= clean($e['entry_number']) ?></td>
                                        <td><?= format_date($e['entry_date']) ?></td>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $e['lot_id'] ?>" class="fw-semibold text-decoration-none">
                                                LOT #<?= clean($e['lot_number']) ?>
                                            </a>
                                        </td>
                                        <td class="fw-bold text-dark"><?= clean($e['op_name']) ?></td>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $e['worker_id'] ?>" class="text-decoration-none text-dark fw-semibold">
                                                <?= clean($e['worker_name']) ?>
                                            </a>
                                            <div class="text-muted small"><?= clean($e['worker_code']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($e['color_name'])): ?>
                                                <?= clean($e['color_name']) ?> / <span class="badge bg-light text-dark border"><?= clean($e['size_name']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border">Batch / All</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center fw-bold text-success fs-6"><?= number_format($e['good_qty']) ?></td>
                                        <td class="text-center <?= $e['rejected_qty'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= number_format($e['rejected_qty']) ?></td>
                                        <td class="text-center <?= $e['rework_qty'] > 0 ? 'text-warning fw-bold' : 'text-muted' ?>"><?= number_format($e['rework_qty']) ?></td>
                                        <td class="text-end">Rs. <?= number_format($e['rate'], 2) ?></td>
                                        <td class="text-end fw-bold text-primary fs-6"><?= format_currency($e['labour_amount']) ?></td>
                                        <td class="text-end">
                                            <?php if (!$isClosed): ?>
                                                <a href="<?= BASE_URL ?>modules/production/edit.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 fw-semibold" title="Edit Entry">
                                                    <i class="bi bi-pencil-square me-1"></i>Edit
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border" title="Locked: Lot is Closed/Dispatched">
                                                    <i class="bi bi-lock-fill"></i> Locked
                                                </span>
                                            <?php endif; ?>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
