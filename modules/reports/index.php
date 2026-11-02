<?php
/**
 * Garment Manufacturing ERP - Reports & Analytics Center
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Reports & Analytics Center';
$activeNav = 'reports';
$db = get_db();

// Report type selector
$reportType = trim($_GET['type'] ?? 'lot_register');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filterWorker = (int)($_GET['worker_id'] ?? 0);
$filterLot = (int)($_GET['lot_id'] ?? 0);

$workers = $db->query("SELECT id, name FROM workers ORDER BY name ASC")->fetchAll();
$lots = $db->query("SELECT id, lot_number FROM lots ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4 no-print">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Factory Reports & Analytics</h4>
                <p class="text-muted small mb-0">Auditable management registers, production logs, worker summaries and inventory movements</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-printer"></i>
                    <span>Print Report</span>
                </button>
                <a href="<?= BASE_URL ?>modules/reports/export_csv.php?type=<?= urlencode($reportType) ?>" class="btn btn-outline-success btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-file-earmark-excel"></i>
                    <span>Export CSV</span>
                </a>
            </div>
        </div>

        <!-- Report Select Navigation Pills -->
        <div class="card-modern mb-4 no-print">
            <div class="card-modern-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Select Report Type</label>
                        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="lot_register" <?= $reportType === 'lot_register' ? 'selected' : '' ?>>Lot Register (All Batches)</option>
                            <option value="cutting_report" <?= $reportType === 'cutting_report' ? 'selected' : '' ?>>Cutting Operations Report</option>
                            <option value="worker_performance" <?= $reportType === 'worker_performance' ? 'selected' : '' ?>>Worker Performance & Labour</option>
                            <option value="pending_work" <?= $reportType === 'pending_work' ? 'selected' : '' ?>>Pending Work & Shortages</option>
                            <option value="material_consumption" <?= $reportType === 'material_consumption' ? 'selected' : '' ?>>Raw Material Consumption</option>
                            <option value="finished_goods" <?= $reportType === 'finished_goods' ? 'selected' : '' ?>>Finished Goods Inventory</option>
                            <option value="dispatch_report" <?= $reportType === 'dispatch_report' ? 'selected' : '' ?>>Customer Dispatches</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= clean($dateFrom) ?>">
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= clean($dateTo) ?>">
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Filter by Worker</label>
                        <select name="worker_id" class="form-select form-select-sm">
                            <option value="">-- All Workers --</option>
                            <?php foreach ($workers as $w): ?>
                                <option value="<?= $w['id'] ?>" <?= $filterWorker === $w['id'] ? 'selected' : '' ?>><?= clean($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="bi bi-funnel me-1"></i> Apply Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Render Report Content Based on Selection -->
        <div class="card-modern">
            <div class="card-modern-header">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <?php 
                        $reportTitles = [
                            'lot_register' => 'Complete Production Lot Register',
                            'cutting_report' => 'Cutting Department Output & Shortage Analysis',
                            'worker_performance' => 'Artisan & Worker Performance Report',
                            'pending_work' => 'Pending Work & Factory Bottlenecks',
                            'material_consumption' => 'Raw Material Issues & Consumption Report',
                            'finished_goods' => 'Finished Goods Stock by Product, Design, Color & Size',
                            'dispatch_report' => 'Customer Dispatches & Outward Delivery Register'
                        ];
                        echo $reportTitles[$reportType] ?? 'Report Register';
                        ?>
                    </h5>
                    <span class="text-muted small">Generated on <?= date('d M Y, h:i A') ?> · <?= clean(get_setting('company_name', 'Garment ERP')) ?></span>
                </div>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <?php if ($reportType === 'lot_register'): 
                        $rows = $db->query("SELECT l.*, p.name AS product_name, d.design_number,
                                            (SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = l.id) AS packed,
                                            (SELECT COALESCE(SUM(quantity), 0) FROM dispatch_items WHERE lot_id = l.id) AS dispatched
                                            FROM lots l JOIN products p ON l.product_id = p.id 
                                            LEFT JOIN designs d ON l.design_id = d.id 
                                            ORDER BY l.id DESC")->fetchAll();
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Lot #</th>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Design #</th>
                                    <th class="text-center">Total Pcs</th>
                                    <th class="text-center">Packed</th>
                                    <th class="text-center">Dispatched</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="fw-bold text-primary">LOT #<?= clean($r['lot_number']) ?></td>
                                        <td><?= format_date($r['lot_date']) ?></td>
                                        <td><?= clean($r['product_name']) ?></td>
                                        <td><?= $r['design_number'] ? '#' . clean($r['design_number']) : 'Pending' ?></td>
                                        <td class="text-center fw-bold"><?= number_format($r['total_pieces']) ?></td>
                                        <td class="text-center text-success fw-bold"><?= number_format($r['packed']) ?></td>
                                        <td class="text-center text-dark fw-bold"><?= number_format($r['dispatched']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= clean($r['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'cutting_report'): 
                        $rows = $db->query("SELECT pe.*, l.lot_number, l.total_pieces, p.name AS product_name, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                                            FROM production_entries pe 
                                            JOIN lots l ON pe.lot_id = l.id 
                                            JOIN products p ON l.product_id = p.id 
                                            JOIN operations o ON pe.operation_id = o.id 
                                            JOIN workers w ON pe.worker_id = w.id 
                                            JOIN colors c ON pe.color_id = c.id 
                                            JOIN sizes s ON pe.size_id = s.id 
                                            WHERE o.name = 'Cutting' 
                                            ORDER BY pe.id DESC")->fetchAll();
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Entry #</th>
                                    <th>Date</th>
                                    <th>Lot #</th>
                                    <th>Cutter</th>
                                    <th>Color & Size</th>
                                    <th class="text-center">Cut Completed</th>
                                    <th class="text-center">Rejected</th>
                                    <th class="text-end">Labour Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">No cutting records entered yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= clean($r['entry_number']) ?></td>
                                            <td><?= format_date($r['entry_date']) ?></td>
                                            <td class="fw-bold">LOT #<?= clean($r['lot_number']) ?></td>
                                            <td><?= clean($r['worker_name']) ?></td>
                                            <td><?= clean($r['color_name']) ?> (<?= clean($r['size_name']) ?>)</td>
                                            <td class="text-center fw-bold text-success fs-6"><?= number_format($r['good_qty']) ?> pcs</td>
                                            <td class="text-center text-danger"><?= number_format($r['rejected_qty']) ?></td>
                                            <td class="text-end fw-bold"><?= format_currency($r['labour_amount']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'worker_performance'): 
                        $rows = $db->query("SELECT w.name, w.code, w.worker_type, dep.name AS department_name,
                                            COUNT(pe.id) AS total_entries,
                                            COALESCE(SUM(pe.good_qty), 0) AS total_good,
                                            COALESCE(SUM(pe.rejected_qty), 0) AS total_rejected,
                                            COALESCE(SUM(pe.labour_amount), 0) AS total_labour,
                                            COALESCE((SELECT SUM(debit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_paid
                                            FROM workers w 
                                            LEFT JOIN departments dep ON w.department_id = dep.id 
                                            LEFT JOIN production_entries pe ON w.id = pe.worker_id 
                                            GROUP BY w.id 
                                            ORDER BY total_labour DESC")->fetchAll();
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Artisan</th>
                                    <th>Department</th>
                                    <th class="text-center">Submissions</th>
                                    <th class="text-center">Total Good Output</th>
                                    <th class="text-center">Total Rejected</th>
                                    <th class="text-end">Total Labour Earned</th>
                                    <th class="text-end">Paid to Date</th>
                                    <th class="text-end">Net Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $net = (float)$r['total_labour'] - (float)$r['total_paid'];
                                ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold text-dark"><?= clean($r['name']) ?></span>
                                            <div class="text-muted small"><?= clean($r['code']) ?></div>
                                        </td>
                                        <td><?= clean($r['department_name'] ?? 'General') ?></td>
                                        <td class="text-center"><?= $r['total_entries'] ?></td>
                                        <td class="text-center fw-bold text-success fs-6"><?= number_format($r['total_good']) ?> pcs</td>
                                        <td class="text-center text-danger"><?= number_format($r['total_rejected']) ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency($r['total_labour']) ?></td>
                                        <td class="text-end fw-semibold text-secondary"><?= format_currency($r['total_paid']) ?></td>
                                        <td class="text-end fw-bold <?= $net > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= format_currency(abs($net)) ?>
                                            <span class="small d-block fw-normal" style="font-size: 10px;"><?= $net > 0 ? 'Payable' : 'Advance' ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'finished_goods'): 
                        $rows = $db->query("SELECT fg.*, p.name AS product_name, d.design_number, c.name AS color_name, s.name AS size_name, l.lot_number, wh.name AS warehouse_name 
                                            FROM finished_goods_stock fg 
                                            JOIN products p ON fg.product_id = p.id 
                                            LEFT JOIN designs d ON fg.design_id = d.id 
                                            JOIN colors c ON fg.color_id = c.id 
                                            JOIN sizes s ON fg.size_id = s.id 
                                            JOIN lots l ON fg.lot_id = l.id 
                                            JOIN warehouses wh ON fg.warehouse_id = wh.id 
                                            ORDER BY p.name ASC, c.name ASC")->fetchAll();
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Design #</th>
                                    <th>Color</th>
                                    <th>Size</th>
                                    <th>Lot #</th>
                                    <th>Warehouse</th>
                                    <th class="text-end">Current Stock</th>
                                    <th class="text-end">Dispatched</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= clean($r['product_name']) ?></td>
                                        <td><?= $r['design_number'] ? '#' . clean($r['design_number']) : '-' ?></td>
                                        <td><?= clean($r['color_name']) ?></td>
                                        <td><?= clean($r['size_name']) ?></td>
                                        <td class="fw-semibold text-primary">LOT #<?= clean($r['lot_number']) ?></td>
                                        <td><?= clean($r['warehouse_name']) ?></td>
                                        <td class="text-end fw-bold text-success fs-6"><?= number_format($r['current_stock']) ?> pcs</td>
                                        <td class="text-end text-muted"><?= number_format($r['dispatched_stock']) ?> pcs</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php else: ?>
                        <!-- Default Pending Work -->
                        <?php 
                        $rows = $db->query("SELECT l.lot_number, p.name AS product_name, l.total_pieces, o.name AS op_name, lws.sequence,
                                            (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = l.id AND operation_id = o.id) AS good_done
                                            FROM lots l 
                                            JOIN products p ON l.product_id = p.id 
                                            JOIN lot_workflow_steps lws ON l.id = lws.lot_id 
                                            JOIN operations o ON lws.operation_id = o.id 
                                            WHERE l.status != 'Closed' 
                                            ORDER BY l.id DESC, lws.sequence ASC")->fetchAll();
                        ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Lot Number</th>
                                    <th>Product</th>
                                    <th>Operation</th>
                                    <th class="text-center">Required (Lot)</th>
                                    <th class="text-center">Good Completed</th>
                                    <th class="text-center">Pending Quantity</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $pending = max(0, (int)$r['total_pieces'] - (int)$r['good_done']);
                                    if ($pending === 0) continue; // Only show pending
                                ?>
                                    <tr>
                                        <td class="fw-bold text-primary">LOT #<?= clean($r['lot_number']) ?></td>
                                        <td><?= clean($r['product_name']) ?></td>
                                        <td class="fw-semibold text-dark"><?= clean($r['op_name']) ?></td>
                                        <td class="text-center fw-bold"><?= number_format($r['total_pieces']) ?></td>
                                        <td class="text-center text-success"><?= number_format($r['good_done']) ?></td>
                                        <td class="text-center fw-bold text-warning-emphasis fs-6"><?= number_format($pending) ?> pcs</td>
                                        <td><span class="badge bg-warning-subtle text-warning-emphasis">Pending</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
