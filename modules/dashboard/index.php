<?php
/**
 * Garment Manufacturing ERP - Executive Dashboard
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Production Dashboard';
$activeNav = 'dashboard';
$db = get_db();

// 1. Production KPIs
$stmt = $db->query("SELECT 
    COUNT(CASE WHEN status != 'Closed' THEN 1 END) AS active_lots,
    COUNT(CASE WHEN status = 'Draft' THEN 1 END) AS draft_lots,
    COUNT(CASE WHEN status = 'In Production' THEN 1 END) AS in_prod_lots,
    COUNT(CASE WHEN status = 'Production Completed' THEN 1 END) AS prod_completed_lots,
    COUNT(CASE WHEN status = 'Partially Packed' THEN 1 END) AS part_packed_lots,
    COUNT(CASE WHEN status = 'Ready Stock' THEN 1 END) AS ready_stock_lots,
    COUNT(CASE WHEN status = 'Partially Dispatched' THEN 1 END) AS part_disp_lots,
    COUNT(CASE WHEN status = 'Dispatched' THEN 1 END) AS dispatched_lots,
    COALESCE(SUM(total_pieces), 0) AS total_planned_pieces
FROM lots");
$lotKpi = $stmt->fetch();

// Calculate total finished goods stock
$stmt = $db->query("SELECT COALESCE(SUM(current_stock), 0) AS total_fg_stock FROM finished_goods_stock");
$fgStock = (int)$stmt->fetchColumn();

// Calculate total dispatched pieces
$stmt = $db->query("SELECT COALESCE(SUM(total_pieces), 0) AS total_dispatched FROM dispatches");
$dispatchedTotal = (int)$stmt->fetchColumn();

// Total raw materials & low stock
$stmt = $db->query("SELECT 
    COUNT(*) AS total_materials,
    COUNT(CASE WHEN current_stock <= min_stock THEN 1 END) AS low_stock_count,
    COALESCE(SUM(current_stock * cost_price), 0) AS raw_stock_value
FROM materials WHERE is_active = 1");
$matKpi = $stmt->fetch();

// Total packed pieces
$stmt = $db->query("SELECT COALESCE(SUM(packed_qty), 0) AS total_packed FROM packing_entries");
$totalPacked = (int)$stmt->fetchColumn();

// WIP calculation: Planned pieces in active lots minus fully packed pieces
$wipPieces = max(0, (int)$lotKpi['total_planned_pieces'] - $totalPacked);

// 2. Financial KPIs: Worker Payables, Advances, Today's payments, Today's labour
$stmt = $db->query("SELECT 
    w.id,
    w.opening_balance,
    COALESCE(SUM(l.credit), 0) + w.opening_balance AS total_earned,
    COALESCE(SUM(l.debit), 0) AS total_paid
FROM workers w
LEFT JOIN worker_ledger l ON w.id = l.worker_id AND l.transaction_type != 'Opening Balance'
WHERE w.is_active = 1
GROUP BY w.id");
$workerBalances = $stmt->fetchAll();

$workerPayables = 0;
$workerAdvances = 0;
foreach ($workerBalances as $wb) {
    $bal = (float)$wb['total_earned'] - (float)$wb['total_paid'];
    if ($bal > 0) {
        $workerPayables += $bal;
    } else {
        $workerAdvances += abs($bal);
    }
}

// Today's Labour Cost
$today = date('Y-m-d');
$stmt = $db->prepare("SELECT COALESCE(SUM(labour_amount), 0) FROM production_entries WHERE entry_date = ?");
$stmt->execute([$today]);
$todayLabour = (float)$stmt->fetchColumn();

// Today's Worker Payments
$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM worker_payments WHERE payment_date = ? AND is_reversed = 0");
$stmt->execute([$today]);
$todayPayments = (float)$stmt->fetchColumn();

// Outstanding Customer Receivables
$stmt = $db->query("SELECT 
    c.id,
    c.opening_balance,
    COALESCE(SUM(cl.debit), 0) + c.opening_balance AS total_invoiced,
    COALESCE(SUM(cl.credit), 0) AS total_received
FROM customers c
LEFT JOIN customer_ledger cl ON c.id = cl.customer_id AND cl.transaction_type != 'Opening Balance'
WHERE c.is_active = 1
GROUP BY c.id");
$custBalances = $stmt->fetchAll();
$customerReceivables = 0;
foreach ($custBalances as $cb) {
    $cbal = (float)$cb['total_invoiced'] - (float)$cb['total_received'];
    if ($cbal > 0) {
        $customerReceivables += $cbal;
    }
}

// Recent Active Lots
$stmt = $db->query("SELECT l.*, p.name AS product_name, d.design_number,
    (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = l.id) AS completed_good,
    (SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = l.id) AS lot_packed
FROM lots l
JOIN products p ON l.product_id = p.id
LEFT JOIN designs d ON l.design_id = d.id
WHERE l.status != 'Closed'
ORDER BY l.id DESC LIMIT 5");
$recentLots = $stmt->fetchAll();

// Operation-wise Pending Quantity Aggregation
$stmt = $db->query("SELECT o.name AS op_name, dep.name AS dept_name,
    COALESCE(SUM(pe.good_qty), 0) AS good_done,
    COALESCE(SUM(pe.rejected_qty), 0) AS rejected,
    COALESCE(SUM(pe.rework_qty), 0) AS rework
FROM operations o
LEFT JOIN departments dep ON o.department_id = dep.id
LEFT JOIN production_entries pe ON o.id = pe.operation_id
GROUP BY o.id, o.name, dep.name
ORDER BY o.sequence ASC");
$opsData = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Page Title & Quick Actions -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Factory Production Dashboard</h4>
                <p class="text-muted small mb-0">Live overview of active lots, worker earnings, WIP & stock</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/lots/create.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-plus-lg"></i>
                    <span>New Lot</span>
                </a>
                <a href="<?= BASE_URL ?>modules/production/index.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-lightning-charge-fill"></i>
                    <span>Production Entry</span>
                </a>
            </div>
        </div>

        <!-- Production KPIs Row -->
        <h6 class="text-uppercase text-secondary fw-bold small mb-3 tracking-wide"><i class="bi bi-gear-wide-connected me-1"></i> Production Status</h6>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/lots/index.php?status=In+Production" class="kpi-card">
                    <div>
                        <div class="kpi-title">In Production</div>
                        <div class="kpi-value text-primary"><?= $lotKpi['in_prod_lots'] ?></div>
                        <div class="kpi-sub">Active factory lots</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/lots/index.php?status=Draft" class="kpi-card">
                    <div>
                        <div class="kpi-title">New / Draft</div>
                        <div class="kpi-value text-secondary"><?= $lotKpi['draft_lots'] ?></div>
                        <div class="kpi-sub">Awaiting startup</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/lots/index.php?status=Production+Completed" class="kpi-card">
                    <div>
                        <div class="kpi-title">Prod. Complete</div>
                        <div class="kpi-value text-info"><?= $lotKpi['prod_completed_lots'] ?></div>
                        <div class="kpi-sub">Ready for packing</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/lots/index.php?status=Partially+Packed" class="kpi-card">
                    <div>
                        <div class="kpi-title">Partial Packed</div>
                        <div class="kpi-value text-warning"><?= $lotKpi['part_packed_lots'] ?></div>
                        <div class="kpi-sub">In packing queue</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/lots/index.php?status=Ready+Stock" class="kpi-card">
                    <div>
                        <div class="kpi-title">Ready Stock</div>
                        <div class="kpi-value text-success"><?= $lotKpi['ready_stock_lots'] ?></div>
                        <div class="kpi-sub">Packed in warehouse</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/production/pending.php" class="kpi-card border-warning-subtle">
                    <div>
                        <div class="kpi-title text-warning">Pending Work</div>
                        <div class="kpi-value text-warning"><?= $lotKpi['active_lots'] ?></div>
                        <div class="kpi-sub">Operations in flight</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Inventory & Financial KPIs Row -->
        <h6 class="text-uppercase text-secondary fw-bold small mb-3 tracking-wide"><i class="bi bi-box-seam me-1"></i> Inventory & Financial Position</h6>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/inventory/index.php" class="kpi-card">
                    <div>
                        <div class="kpi-title">Finished Stock</div>
                        <div class="kpi-value"><?= number_format($fgStock) ?></div>
                        <div class="kpi-sub">Pieces available</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/packing/index.php" class="kpi-card">
                    <div>
                        <div class="kpi-title">Packed Pieces</div>
                        <div class="kpi-value text-success"><?= number_format($totalPacked) ?></div>
                        <div class="kpi-sub">Cumulative packed</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <a href="<?= BASE_URL ?>modules/dispatch/index.php" class="kpi-card">
                    <div>
                        <div class="kpi-title">Dispatched Pcs</div>
                        <div class="kpi-value text-dark"><?= number_format($dispatchedTotal) ?></div>
                        <div class="kpi-sub">Shipped to parties</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <a href="<?= BASE_URL ?>modules/workers/index.php" class="kpi-card">
                    <div>
                        <div class="kpi-title text-danger">Worker Payables</div>
                        <div class="kpi-value text-danger" style="font-size: 1.35rem;"><?= format_currency($workerPayables) ?></div>
                        <div class="kpi-sub text-success">Advances given: <?= format_currency($workerAdvances) ?></div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-xl-3">
                <a href="<?= BASE_URL ?>modules/customers/index.php" class="kpi-card">
                    <div>
                        <div class="kpi-title text-primary">Customer Balance</div>
                        <div class="kpi-value text-primary" style="font-size: 1.35rem;"><?= format_currency($customerReceivables) ?></div>
                        <div class="kpi-sub">Outstanding to collect</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Charts and Active Lots Section -->
        <div class="row g-4 mb-4">
            <!-- Active Lots Progress Table -->
            <div class="col-12 col-lg-8">
                <div class="card-modern h-100">
                    <div class="card-modern-header">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Active Factory Lots</h6>
                            <span class="text-muted small">Live production stage tracking</span>
                        </div>
                        <a href="<?= BASE_URL ?>modules/lots/index.php" class="btn btn-sm btn-outline-secondary">View All</a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Lot Number</th>
                                        <th>Product & Design</th>
                                        <th class="text-center">Total Pcs</th>
                                        <th class="text-center">Packed</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentLots)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                No lots created yet. <a href="<?= BASE_URL ?>modules/lots/create.php">Create your first lot</a>.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentLots as $lot): ?>
                                            <tr>
                                                <td>
                                                    <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lot['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                        <?= clean($lot['lot_number']) ?>
                                                    </a>
                                                    <div class="text-muted small"><?= format_date($lot['lot_date']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= clean($lot['product_name']) ?></div>
                                                    <div class="text-muted small">
                                                        <?= $lot['design_number'] ? 'Design: #' . clean($lot['design_number']) : '<span class="text-warning">Design Pending</span>' ?>
                                                    </div>
                                                </td>
                                                <td class="text-center fw-bold text-dark">
                                                    <?= number_format($lot['total_pieces']) ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-light text-dark border">
                                                        <?= number_format($lot['lot_packed']) ?> / <?= number_format($lot['total_pieces']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $st = $lot['status'];
                                                    $badgeClass = match($st) {
                                                        'Draft' => 'badge-draft',
                                                        'In Production' => 'badge-progress',
                                                        'Production Completed' => 'badge-completed',
                                                        'Partially Packed' => 'badge-pending',
                                                        'Ready Stock' => 'badge-ready',
                                                        'Dispatched' => 'badge-dispatched',
                                                        default => 'badge-draft'
                                                    };
                                                    ?>
                                                    <span class="badge-status <?= $badgeClass ?>">
                                                        <?= clean($st) ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lot['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="Lot Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $lot['id'] ?>" class="btn btn-sm btn-outline-warning py-1 px-2" title="Enter Work">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
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

            <!-- Department Pending Work Chart -->
            <div class="col-12 col-lg-4">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Department Work Status</h6>
                            <span class="text-muted small">Good output vs rejections</span>
                        </div>
                    </div>
                    <div class="card-modern-body">
                        <!-- Chart Container with strictly bounded height to prevent Chart.js resize loop -->
                        <div style="position: relative; height: 240px; width: 100%; overflow: hidden;">
                            <canvas id="deptWorkChart"></canvas>
                        </div>
                        
                        <div class="mt-3 border-top pt-3">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Today's Labour Cost:</span>
                                <span class="fw-bold text-dark"><?= format_currency($todayLabour) ?></span>
                            </div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>Today's Disbursed Payments:</span>
                                <span class="fw-bold text-success"><?= format_currency($todayPayments) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div> <!-- end app-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('deptWorkChart');
    if (ctx) {
        const labels = <?= json_encode(array_column($opsData, 'op_name')) ?>;
        const goodData = <?= json_encode(array_column($opsData, 'good_done')) ?>;
        const rejectedData = <?= json_encode(array_column($opsData, 'rejected')) ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Good Produced',
                        data: goodData,
                        backgroundColor: '#2563eb',
                        borderRadius: 4
                    },
                    {
                        label: 'Rejected',
                        data: rejectedData,
                        backgroundColor: '#ef4444',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { font: { size: 10 } }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
