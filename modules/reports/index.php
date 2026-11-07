<?php
/**
 * Garment Manufacturing ERP - Reports & Analytics Center
 * Comprehensive Financial & Non-Financial / Operational Management Reports
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Factory Reports & Analytics Center';
$activeNav = 'reports';
$db = get_db();

// Filter parameters
$reportType = trim($_GET['type'] ?? 'batch_costing');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$filterWorker = (int)($_GET['worker_id'] ?? 0);
$filterLot = (int)($_GET['lot_id'] ?? 0);

$workers = $db->query("SELECT id, name, code FROM workers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$lots = $db->query("SELECT id, lot_number FROM lots ORDER BY id DESC LIMIT 100")->fetchAll();

// Financial KPI summaries
$totalLiquidFunds = (float)$db->query("SELECT COALESCE(SUM(current_balance), 0) FROM cash_bank_accounts WHERE is_active = 1")->fetchColumn();
$totalReceivables = (float)$db->query("SELECT COALESCE(SUM(c.opening_balance), 0) + COALESCE((SELECT SUM(debit) - SUM(credit) FROM customer_ledger), 0) FROM customers c")->fetchColumn();
$totalPayables = (float)$db->query("SELECT COALESCE(SUM(s.opening_balance), 0) + COALESCE((SELECT SUM(credit) - SUM(debit) FROM supplier_ledger), 0) FROM suppliers s")->fetchColumn();
$totalWorkerLiability = (float)$db->query("SELECT COALESCE(SUM(w.opening_balance), 0) + COALESCE((SELECT SUM(credit) - SUM(debit) FROM worker_ledger), 0) FROM workers w")->fetchColumn();

// Operational KPI summaries
$activeLotsCount = (int)$db->query("SELECT COUNT(*) FROM lots WHERE status NOT IN ('Closed', 'Cancelled')")->fetchColumn();
$totalPcsInProduction = (int)$db->query("SELECT COALESCE(SUM(total_pieces), 0) FROM lots WHERE status IN ('Draft', 'In Production')")->fetchColumn();
$finishedGoodsPcs = (int)$db->query("SELECT COALESCE(SUM(current_stock), 0) FROM finished_goods_stock")->fetchColumn();
$lowStockMaterials = (int)$db->query("SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND is_active = 1")->fetchColumn();

// Report definitions
$reportTitles = [
    // Financial
    'batch_costing' => 'Lot Costing & Profitability Register (لاٹ پروڈکشن لاگت اور منافع رپورٹ)',
    'cash_flow' => 'Cash & Bank Liquidity Flow Statement (کیش بک اور بینک فنڈز فلو سٹیٹمنٹ)',
    'customer_receivables' => 'Customer Receivables & Outstanding Aging (کسٹمر واجبات و بقایا جات رپورٹ)',
    'supplier_payables' => 'Supplier Purchases & Payables Register (سپلائر خریداری اور ادائیگیاں)',
    'expense_breakdown' => 'Factory Overheads & Operational Expenses (فیکٹری متفرق اخراجات کی تفصیل)',
    'worker_performance' => 'Artisan Labour Earnings & Net Payables (ورکرز اجرت اور ادائیگی بیلنس)',
    
    // Operational
    'lot_register' => 'Complete Production Lot Master Register (مکمل لاٹ پروڈکشن رجسٹر)',
    'operator_output' => 'Flat Machine Knitting Operator Output (مشین نٹنگ آپریٹرز کارکردگی رپورٹ)',
    'cutting_report' => 'Cutting Department Output & Component Analysis (کٹنگ ڈیپارٹمنٹ رپورٹ)',
    'quality_rejections' => 'Quality Control & Defect Rejection Analysis (کوالٹی کنٹرول اور ریجیکشنز)',
    'material_stock' => 'Raw Material Stock & Reorder Levels (خام مال اسٹاک اور ری آرڈر وارننگ)',
    'finished_goods' => 'Finished Goods Warehouse Stock by Variant (تیار مال گودام اسٹاک رپورٹ)',
    'dispatch_report' => 'Customer Dispatches & Delivery Register (کسٹمر ڈسپیچ و ڈیلیوری رجسٹر)',
    'pending_work' => 'Factory Bottlenecks & Pending Operations (زیر التواء کام اور ورک فلو رکاوٹیں)'
];

$isFinancial = in_array($reportType, ['batch_costing', 'cash_flow', 'customer_receivables', 'supplier_payables', 'expense_breakdown', 'worker_performance']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Top Title & Action Bar -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4 no-print">
            <div>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Factory Reports & Analytics Center
                </h4>
                <p class="text-muted small mb-0">Management accounts, costing registers, artisan labour metrics, warehouse inventory and production bottlenecks</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button onclick="window.print()" class="btn btn-outline-dark btn-sm d-flex align-items-center gap-1 shadow-xs">
                    <i class="bi bi-printer-fill"></i>
                    <span>Print Report (پرنٹ)</span>
                </button>
                <a href="<?= BASE_URL ?>modules/reports/export_csv.php?type=<?= urlencode($reportType) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&worker_id=<?= $filterWorker ?>&lot_id=<?= $filterLot ?>" class="btn btn-success btn-sm d-flex align-items-center gap-1 shadow-xs fw-semibold">
                    <i class="bi bi-file-earmark-excel-fill"></i>
                    <span>Export to CSV / Excel</span>
                </a>
            </div>
        </div>

        <!-- Top High-Level KPI Banners -->
        <div class="row g-3 mb-4 no-print">
            <?php if ($isFinancial): ?>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-success">
                        <span class="text-muted small text-uppercase fw-bold">Available Liquid Cash</span>
                        <h4 class="fw-bold text-success mb-0 mt-1"><?= format_currency($totalLiquidFunds) ?></h4>
                        <div class="small text-muted mt-1"><i class="bi bi-bank me-1"></i> Cash drawers & banks</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-primary">
                        <span class="text-muted small text-uppercase fw-bold">Customer Receivables</span>
                        <h4 class="fw-bold text-primary mb-0 mt-1"><?= format_currency($totalReceivables) ?></h4>
                        <div class="small text-muted mt-1"><i class="bi bi-arrow-down-left me-1"></i> Outstanding from buyers</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-danger">
                        <span class="text-muted small text-uppercase fw-bold">Supplier Payables</span>
                        <h4 class="fw-bold text-danger mb-0 mt-1"><?= format_currency($totalPayables) ?></h4>
                        <div class="small text-muted mt-1"><i class="bi bi-truck me-1"></i> Yarn & accessories suppliers</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-warning">
                        <span class="text-muted small text-uppercase fw-bold">Labour Liabilities</span>
                        <h4 class="fw-bold text-warning-emphasis mb-0 mt-1"><?= format_currency($totalWorkerLiability) ?></h4>
                        <div class="small text-muted mt-1"><i class="bi bi-people me-1"></i> Artisans & workers net dues</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-primary">
                        <span class="text-muted small text-uppercase fw-bold">Active Batches (Lots)</span>
                        <h4 class="fw-bold text-primary mb-0 mt-1"><?= number_format($activeLotsCount) ?></h4>
                        <div class="small text-muted mt-1"><i class="bi bi-layers me-1"></i> Lots currently on floor</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-info">
                        <span class="text-muted small text-uppercase fw-bold">In-Progress Output</span>
                        <h4 class="fw-bold text-info-emphasis mb-0 mt-1"><?= number_format($totalPcsInProduction) ?> pcs</h4>
                        <div class="small text-muted mt-1"><i class="bi bi-gear-wide-connected me-1"></i> Garments being processed</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-success">
                        <span class="text-muted small text-uppercase fw-bold">Finished Goods Stock</span>
                        <h4 class="fw-bold text-success mb-0 mt-1"><?= number_format($finishedGoodsPcs) ?> pcs</h4>
                        <div class="small text-muted mt-1"><i class="bi bi-box-seam me-1"></i> Packed ready in warehouse</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-modern p-3 border-start border-4 border-danger">
                        <span class="text-muted small text-uppercase fw-bold">Low Material Alerts</span>
                        <h4 class="fw-bold text-danger mb-0 mt-1"><?= number_format($lowStockMaterials) ?> items</h4>
                        <div class="small text-muted mt-1"><i class="bi bi-exclamation-triangle me-1"></i> Below reorder threshold</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter & Report Selection Form -->
        <div class="card-modern mb-4 no-print shadow-sm">
            <div class="card-modern-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold mb-1">Select Report (رپورٹ منتخب کریں)</label>
                        <select name="type" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                            <optgroup label="📊 Financial Reports (مالیاتی رپورٹس)">
                                <option value="batch_costing" <?= $reportType === 'batch_costing' ? 'selected' : '' ?>>Lot Costing & Profitability (لاٹ لاگت و منافع)</option>
                                <option value="cash_flow" <?= $reportType === 'cash_flow' ? 'selected' : '' ?>>Cash & Bank Flow Statement (کیش و بینک سٹیٹمنٹ)</option>
                                <option value="customer_receivables" <?= $reportType === 'customer_receivables' ? 'selected' : '' ?>>Customer Receivables (کسٹمر واجبات و بقایا جات)</option>
                                <option value="supplier_payables" <?= $reportType === 'supplier_payables' ? 'selected' : '' ?>>Supplier Payables (سپلائر واجب الادا رقم)</option>
                                <option value="expense_breakdown" <?= $reportType === 'expense_breakdown' ? 'selected' : '' ?>>Factory Expenses Breakdown (فیکٹری متفرق اخراجات)</option>
                                <option value="worker_performance" <?= $reportType === 'worker_performance' ? 'selected' : '' ?>>Worker Labour & Payments (ورکرز اجرت و ادائیگیاں)</option>
                            </optgroup>
                            <optgroup label="⚙️ Production & Operational Reports (پروڈکشن و آپریشنل رپورٹس)">
                                <option value="lot_register" <?= $reportType === 'lot_register' ? 'selected' : '' ?>>Complete Lot Register (مکمل لاٹ ماسٹر رجسٹر)</option>
                                <option value="operator_output" <?= $reportType === 'operator_output' ? 'selected' : '' ?>>Knitting Operator Output (مشین نٹنگ آپریٹرز کارکردگی)</option>
                                <option value="cutting_report" <?= $reportType === 'cutting_report' ? 'selected' : '' ?>>Cutting Operations Report (کٹنگ ڈیپارٹمنٹ رپورٹ)</option>
                                <option value="quality_rejections" <?= $reportType === 'quality_rejections' ? 'selected' : '' ?>>Quality & Rejections Analysis (کوالٹی کنٹرول و ریجیکشنز)</option>
                                <option value="material_stock" <?= $reportType === 'material_stock' ? 'selected' : '' ?>>Raw Material Stock & Reorder (خام مال اسٹاک و ری آرڈر)</option>
                                <option value="finished_goods" <?= $reportType === 'finished_goods' ? 'selected' : '' ?>>Finished Goods Inventory (تیار مال گودام اسٹاک)</option>
                                <option value="dispatch_report" <?= $reportType === 'dispatch_report' ? 'selected' : '' ?>>Customer Dispatches (کسٹمر ڈسپیچ و ڈیلیوری)</option>
                                <option value="pending_work" <?= $reportType === 'pending_work' ? 'selected' : '' ?>>Pending Work & Bottlenecks (زیر التواء کام اور رکاوٹیں)</option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold mb-1">From Date (شروع تاریخ)</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= clean($dateFrom) ?>">
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold mb-1">To Date (آخری تاریخ)</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= clean($dateTo) ?>">
                    </div>

                    <div class="col-8 col-md-2">
                        <label class="form-label small fw-bold mb-1">Worker (ورکر)</label>
                        <select name="worker_id" class="form-select form-select-sm">
                            <option value="">-- All Workers --</option>
                            <?php foreach ($workers as $w): ?>
                                <option value="<?= $w['id'] ?>" <?= $filterWorker === (int)$w['id'] ? 'selected' : '' ?>><?= clean($w['name']) ?> (<?= clean($w['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-4 col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                            <i class="bi bi-funnel-fill me-1"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Render Report Content Container -->
        <div class="card-modern shadow-sm">
            <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                <div>
                    <h5 class="fw-bold mb-1 text-dark">
                        <?= $reportTitles[$reportType] ?? 'Factory Report Register' ?>
                    </h5>
                    <span class="text-muted small">
                        Generated on <?= date('d M Y, h:i A') ?> &bull; <?= clean(get_setting('company_name', 'Ali Garment Manufacturing Ltd.')) ?>
                        <?php if ($dateFrom || $dateTo): ?>
                            &bull; Period: <?= $dateFrom ? format_date($dateFrom) : 'Beginning' ?> to <?= $dateTo ? format_date($dateTo) : 'Today' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="d-none d-print-block text-end">
                    <span class="fw-bold text-dark"><?= clean(get_setting('company_name', 'Ali Garment Manufacturing Ltd.')) ?></span>
                </div>
            </div>
            
            <div class="card-modern-body p-0">
                <div class="table-responsive">

                    <!-- ============================================================== -->
                    <!-- 1. FINANCIAL REPORT: BATCH COSTING & PROFITABILITY             -->
                    <!-- ============================================================== -->
                    <?php if ($reportType === 'batch_costing'): 
                        $dateSql = "";
                        $params = [];
                        if ($dateFrom) { $dateSql .= " AND l.lot_date >= ?"; $params[] = $dateFrom; }
                        if ($dateTo) { $dateSql .= " AND l.lot_date <= ?"; $params[] = $dateTo; }

                        $stmt = $db->prepare("SELECT l.id, l.lot_number, l.lot_date, p.name AS product_name, COALESCE(l.design_number, d.design_number) AS design_number, l.total_pieces, l.status,
                                                     COALESCE((SELECT SUM(mii.quantity * m.cost_price) FROM material_issues mi JOIN material_issue_items mii ON mi.id = mii.issue_id JOIN materials m ON mii.material_id = m.id WHERE mi.lot_id = l.id), 0) AS material_cost,
                                                     COALESCE((SELECT SUM(labour_amount) FROM production_entries WHERE lot_id = l.id), 0) AS labour_cost,
                                                     COALESCE((SELECT SUM(total_amount) FROM dispatch_items WHERE lot_id = l.id), 0) AS dispatch_revenue,
                                                     COALESCE((SELECT SUM(quantity) FROM dispatch_items WHERE lot_id = l.id), 0) AS pcs_dispatched
                                              FROM lots l
                                              JOIN products p ON l.product_id = p.id
                                              LEFT JOIN designs d ON l.design_id = d.id
                                              WHERE 1=1 $dateSql
                                              ORDER BY l.id DESC");
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $sumPcs = 0; $sumMat = 0; $sumLab = 0; $sumCost = 0; $sumRev = 0; $sumProfit = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Lot #</th>
                                    <th>Date</th>
                                    <th>Product & Design</th>
                                    <th class="text-center">Total Pcs</th>
                                    <th class="text-end">Raw Materials</th>
                                    <th class="text-end">Artisan Labour</th>
                                    <th class="text-end">Total Batch Cost</th>
                                    <th class="text-end">Unit Cost</th>
                                    <th class="text-end">Revenue Billed</th>
                                    <th class="text-end">Gross Profit</th>
                                    <th class="text-center">Margin %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="11" class="text-center py-4 text-muted">No production lots found for selected criteria.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): 
                                        $pcs = (int)$r['total_pieces'];
                                        $matCost = (float)$r['material_cost'];
                                        $labCost = (float)$r['labour_cost'];
                                        $batchCost = $matCost + $labCost;
                                        $unitCost = $pcs > 0 ? ($batchCost / $pcs) : 0;
                                        $revenue = (float)$r['dispatch_revenue'];
                                        $profit = $revenue - $batchCost;
                                        $margin = $revenue > 0 ? (($profit / $revenue) * 100) : 0;

                                        $sumPcs += $pcs;
                                        $sumMat += $matCost;
                                        $sumLab += $labCost;
                                        $sumCost += $batchCost;
                                        $sumRev += $revenue;
                                        $sumProfit += $profit;
                                    ?>
                                        <tr>
                                            <td class="fw-bold">
                                                <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $r['id'] ?>" class="text-primary text-decoration-none">
                                                    LOT #<?= clean($r['lot_number']) ?>
                                                </a>
                                            </td>
                                            <td><?= format_date($r['lot_date']) ?></td>
                                            <td>
                                                <span class="fw-semibold text-dark"><?= clean($r['product_name']) ?></span>
                                                <div class="small text-muted"><?= $r['design_number'] ? 'Design #' . clean($r['design_number']) : 'No Design #' ?></div>
                                            </td>
                                            <td class="text-center fw-bold"><?= number_format($pcs) ?></td>
                                            <td class="text-end fw-semibold text-dark"><?= format_currency($matCost) ?></td>
                                            <td class="text-end fw-semibold text-dark"><?= format_currency($labCost) ?></td>
                                            <td class="text-end fw-bold text-dark"><?= format_currency($batchCost) ?></td>
                                            <td class="text-end text-muted font-monospace small"><?= format_currency($unitCost) ?></td>
                                            <td class="text-end fw-bold text-success"><?= format_currency($revenue) ?></td>
                                            <td class="text-end fw-bold <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= ($profit >= 0 ? '+ ' : '') . format_currency($profit) ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $margin >= 20 ? 'bg-success' : ($margin > 0 ? 'bg-info' : 'bg-secondary') ?> rounded-pill">
                                                    <?= number_format($margin, 1) ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="3" class="text-end">TOTALS:</td>
                                    <td class="text-center text-dark"><?= number_format($sumPcs) ?></td>
                                    <td class="text-end text-dark"><?= format_currency($sumMat) ?></td>
                                    <td class="text-end text-dark"><?= format_currency($sumLab) ?></td>
                                    <td class="text-end text-dark fs-6"><?= format_currency($sumCost) ?></td>
                                    <td></td>
                                    <td class="text-end text-success fs-6"><?= format_currency($sumRev) ?></td>
                                    <td class="text-end <?= $sumProfit >= 0 ? 'text-success' : 'text-danger' ?> fs-6">
                                        <?= ($sumProfit >= 0 ? '+ ' : '') . format_currency($sumProfit) ?>
                                    </td>
                                    <td class="text-center text-muted">
                                        <?= $sumRev > 0 ? number_format((($sumProfit / $sumRev) * 100), 1) . '%' : '—' ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 2. FINANCIAL REPORT: CASH & BANK FLOW STATEMENT                -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'cash_flow'): 
                        $stmt = $db->query("SELECT cba.id, cba.account_name, cba.bank_name, cba.account_number, cba.current_balance,
                                                   COALESCE((SELECT amount FROM account_transactions WHERE account_id = cba.id AND transaction_type = 'Opening Balance' LIMIT 1), 0) AS opening_balance,
                                                   COALESCE(SUM(at.credit), 0) AS total_inflow,
                                                   COALESCE(SUM(at.debit), 0) AS total_outflow
                                            FROM cash_bank_accounts cba
                                            LEFT JOIN account_transactions at ON cba.id = at.account_id
                                            GROUP BY cba.id
                                            ORDER BY cba.current_balance DESC");
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $totIn = 0; $totOut = 0; $totBal = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Account Name</th>
                                    <th>Institution / Bank</th>
                                    <th>Account / Drawer #</th>
                                    <th class="text-end">Opening Balance</th>
                                    <th class="text-end">Total Receipts (Inflows)</th>
                                    <th class="text-end">Total Payments (Outflows)</th>
                                    <th class="text-end">Current Liquid Balance</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $in = (float)$r['total_inflow'];
                                    $out = (float)$r['total_outflow'];
                                    $bal = (float)$r['current_balance'];
                                    $totIn += $in; $totOut += $out; $totBal += $bal;
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-bank2 me-1 text-primary"></i> <?= clean($r['account_name']) ?>
                                        </td>
                                        <td><?= clean($r['bank_name'] ?: 'Cash Drawer') ?></td>
                                        <td class="font-monospace small"><?= clean($r['account_number'] ?: '—') ?></td>
                                        <td class="text-end fw-semibold text-muted"><?= format_currency($r['opening_balance']) ?></td>
                                        <td class="text-end fw-bold text-success">+ <?= format_currency($in) ?></td>
                                        <td class="text-end fw-bold text-danger">- <?= format_currency($out) ?></td>
                                        <td class="text-end fw-bold text-primary fs-6"><?= format_currency($bal) ?></td>
                                        <td class="text-center">
                                            <a href="<?= BASE_URL ?>modules/accounts/statement.php?account_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 small">
                                                Ledger <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="4" class="text-end">TOTAL FACTORY FUNDS:</td>
                                    <td class="text-end text-success fs-6">+ <?= format_currency($totIn) ?></td>
                                    <td class="text-end text-danger fs-6">- <?= format_currency($totOut) ?></td>
                                    <td class="text-end text-primary fs-5"><?= format_currency($totBal) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 3. FINANCIAL REPORT: CUSTOMER RECEIVABLES                      -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'customer_receivables'): 
                        $stmt = $db->query("SELECT c.id, c.name, c.contact_person, c.phone, c.code, c.city,
                                                   c.opening_balance,
                                                   COALESCE((SELECT SUM(debit) FROM customer_ledger WHERE customer_id = c.id), 0) AS total_invoiced,
                                                   COALESCE((SELECT SUM(credit) FROM customer_ledger WHERE customer_id = c.id), 0) AS total_paid,
                                                   (c.opening_balance + COALESCE((SELECT SUM(debit) - SUM(credit) FROM customer_ledger WHERE customer_id = c.id), 0)) AS balance_due,
                                                   (SELECT COUNT(*) FROM dispatches WHERE customer_id = c.id) AS total_dispatches
                                            FROM customers c
                                            WHERE c.is_active = 1
                                            ORDER BY balance_due DESC");
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $totInv = 0; $totRec = 0; $totDue = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Customer / Company</th>
                                    <th>Contact & City</th>
                                    <th class="text-center">Dispatches</th>
                                    <th class="text-end">Opening Balance</th>
                                    <th class="text-end">Total Billed</th>
                                    <th class="text-end">Total Received</th>
                                    <th class="text-end">Net Receivable (باقی رقم)</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $inv = (float)$r['total_invoiced'];
                                    $rec = (float)$r['total_paid'];
                                    $due = (float)$r['balance_due'];
                                    $totInv += $inv; $totRec += $rec; $totDue += $due;
                                ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/customers/view.php?id=<?= $r['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                <?= clean($r['name']) ?>
                                            </a>
                                            <div class="small text-muted"><?= clean($r['code']) ?></div>
                                        </td>
                                        <td>
                                            <div><?= clean($r['contact_person'] ?: '—') ?></div>
                                            <small class="text-muted"><?= clean($r['phone'] ?: '') ?> <?= $r['city'] ? '• ' . clean($r['city']) : '' ?></small>
                                        </td>
                                        <td class="text-center fw-semibold"><?= (int)$r['total_dispatches'] ?></td>
                                        <td class="text-end text-muted"><?= format_currency($r['opening_balance']) ?></td>
                                        <td class="text-end fw-semibold text-dark"><?= format_currency($inv) ?></td>
                                        <td class="text-end fw-semibold text-success"><?= format_currency($rec) ?></td>
                                        <td class="text-end fw-bold <?= $due > 0 ? 'text-danger fs-6' : 'text-success' ?>">
                                            <?= format_currency($due) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $due > 0 ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-success-subtle text-success' ?>">
                                                <?= $due > 0 ? 'Receivable' : 'Settled' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="4" class="text-end">TOTAL OUTSTANDING RECEIVABLES:</td>
                                    <td class="text-end text-dark"><?= format_currency($totInv) ?></td>
                                    <td class="text-end text-success"><?= format_currency($totRec) ?></td>
                                    <td class="text-end text-danger fs-6"><?= format_currency($totDue) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 4. FINANCIAL REPORT: SUPPLIER PURCHASES & PAYABLES             -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'supplier_payables'): 
                        $stmt = $db->query("SELECT s.id, s.name, s.code, s.phone, s.opening_balance,
                                                   COALESCE((SELECT SUM(credit) FROM supplier_ledger WHERE supplier_id = s.id), 0) AS total_billed,
                                                   COALESCE((SELECT SUM(debit) FROM supplier_ledger WHERE supplier_id = s.id), 0) AS total_paid,
                                                   (s.opening_balance + COALESCE((SELECT SUM(credit) - SUM(debit) FROM supplier_ledger WHERE supplier_id = s.id), 0)) AS balance_payable,
                                                   (SELECT COUNT(*) FROM material_purchases WHERE supplier_id = s.id) AS total_bills
                                            FROM suppliers s
                                            WHERE s.is_active = 1
                                            ORDER BY balance_payable DESC");
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $totBill = 0; $totPaid = 0; $totPayable = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Supplier Name</th>
                                    <th>Code & Phone</th>
                                    <th class="text-center">Purchases</th>
                                    <th class="text-end">Opening Balance</th>
                                    <th class="text-end">Total Purchased</th>
                                    <th class="text-end">Total Paid</th>
                                    <th class="text-end">Balance Payable (واجب الادا)</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $bill = (float)$r['total_billed'];
                                    $paid = (float)$r['total_paid'];
                                    $pay = (float)$r['balance_payable'];
                                    $totBill += $bill; $totPaid += $paid; $totPayable += $pay;
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-truck me-1 text-secondary"></i> <?= clean($r['name']) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= clean($r['code']) ?></span>
                                            <span class="small text-muted ms-1"><?= clean($r['phone'] ?: '—') ?></span>
                                        </td>
                                        <td class="text-center fw-semibold"><?= (int)$r['total_bills'] ?></td>
                                        <td class="text-end text-muted"><?= format_currency($r['opening_balance']) ?></td>
                                        <td class="text-end fw-semibold text-dark"><?= format_currency($bill) ?></td>
                                        <td class="text-end fw-semibold text-success"><?= format_currency($paid) ?></td>
                                        <td class="text-end fw-bold <?= $pay > 0 ? 'text-danger fs-6' : 'text-success' ?>">
                                            <?= format_currency($pay) ?>
                                        </td>
                                        <td class="text-center">
                                            <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php?supplier_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-dark py-0 px-2 small">
                                                Ledger <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="4" class="text-end">TOTAL SUPPLIER LIABILITIES:</td>
                                    <td class="text-end text-dark"><?= format_currency($totBill) ?></td>
                                    <td class="text-end text-success"><?= format_currency($totPaid) ?></td>
                                    <td class="text-end text-danger fs-6"><?= format_currency($totPayable) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 5. FINANCIAL REPORT: FACTORY EXPENSES BREAKDOWN               -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'expense_breakdown'): 
                        $dateSql = "";
                        $params = [];
                        if ($dateFrom) { $dateSql .= " AND e.expense_date >= ?"; $params[] = $dateFrom; }
                        if ($dateTo) { $dateSql .= " AND e.expense_date <= ?"; $params[] = $dateTo; }

                        $stmt = $db->prepare("SELECT ec.name AS category_name, 
                                                     COUNT(e.id) AS voucher_count, 
                                                     COALESCE(SUM(e.amount), 0) AS total_amount
                                              FROM expense_categories ec
                                              LEFT JOIN expenses e ON ec.id = e.category_id $dateSql
                                              GROUP BY ec.id
                                              ORDER BY total_amount DESC");
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $overallExpenses = array_sum(array_column($rows, 'total_amount'));
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Expense Category</th>
                                    <th class="text-center">Vouchers Issued</th>
                                    <th class="text-end">Total Amount</th>
                                    <th class="text-end" style="width: 250px;">Percentage Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $amt = (float)$r['total_amount'];
                                    $pct = $overallExpenses > 0 ? (($amt / $overallExpenses) * 100) : 0;
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-tag-fill me-1 text-danger"></i> <?= clean($r['category_name']) ?>
                                        </td>
                                        <td class="text-center fw-semibold"><?= (int)$r['voucher_count'] ?></td>
                                        <td class="text-end fw-bold text-danger fs-6"><?= format_currency($amt) ?></td>
                                        <td class="text-end">
                                            <div class="d-flex align-items-center justify-content-end gap-2">
                                                <div class="progress flex-grow-1" style="height: 8px;">
                                                    <div class="progress-bar bg-danger" style="width: <?= min(100, $pct) ?>%"></div>
                                                </div>
                                                <span class="small fw-bold text-muted" style="min-width: 45px;"><?= number_format($pct, 1) ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td class="text-end">TOTAL FACTORY EXPENSES:</td>
                                    <td class="text-center"><?= array_sum(array_column($rows, 'voucher_count')) ?></td>
                                    <td class="text-end text-danger fs-6"><?= format_currency($overallExpenses) ?></td>
                                    <td class="text-end">100.0%</td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 6. FINANCIAL REPORT: WORKER LABOUR & PERFORMANCE               -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'worker_performance'): 
                        $filterSql = "";
                        $params = [];
                        if ($filterWorker > 0) { $filterSql .= " AND w.id = ?"; $params[] = $filterWorker; }

                        $stmt = $db->prepare("SELECT w.id, w.name, w.code, w.worker_type, dep.name AS department_name,
                                                     w.opening_balance,
                                                     COUNT(pe.id) AS total_entries,
                                                     COALESCE(SUM(pe.good_qty), 0) AS total_good,
                                                     COALESCE(SUM(pe.rejected_qty), 0) AS total_rejected,
                                                     COALESCE((SELECT SUM(credit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_labour,
                                                     COALESCE((SELECT SUM(debit) FROM worker_ledger WHERE worker_id = w.id), 0) AS total_paid
                                              FROM workers w 
                                              LEFT JOIN departments dep ON w.department_id = dep.id 
                                              LEFT JOIN production_entries pe ON w.id = pe.worker_id 
                                              WHERE w.is_active = 1 $filterSql
                                              GROUP BY w.id 
                                              ORDER BY total_labour DESC");
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $sumGood = 0; $sumRej = 0; $sumLab = 0; $sumPaid = 0; $sumNet = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Artisan / Worker</th>
                                    <th>Department</th>
                                    <th class="text-center">Entries</th>
                                    <th class="text-center">Good Output</th>
                                    <th class="text-center">Rejections</th>
                                    <th class="text-end">Total Labour Earned</th>
                                    <th class="text-end">Total Paid</th>
                                    <th class="text-end">Net Balance</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $lab = (float)$r['total_labour'];
                                    $paid = (float)$r['total_paid'];
                                    $net = $lab - $paid;

                                    $sumGood += (int)$r['total_good'];
                                    $sumRej += (int)$r['total_rejected'];
                                    $sumLab += $lab;
                                    $sumPaid += $paid;
                                    $sumNet += $net;
                                ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $r['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                <?= clean($r['name']) ?>
                                            </a>
                                            <div class="text-muted small"><?= clean($r['code']) ?> &bull; <?= clean($r['worker_type']) ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= clean($r['department_name'] ?? 'General') ?></span></td>
                                        <td class="text-center fw-semibold"><?= $r['total_entries'] ?></td>
                                        <td class="text-center fw-bold text-success"><?= number_format($r['total_good']) ?> pcs</td>
                                        <td class="text-center text-danger"><?= number_format($r['total_rejected']) ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency($lab) ?></td>
                                        <td class="text-end fw-semibold text-secondary"><?= format_currency($paid) ?></td>
                                        <td class="text-end fw-bold <?= $net > 0 ? 'text-danger fs-6' : 'text-success' ?>">
                                            <?= format_currency(abs($net)) ?>
                                            <span class="small d-block fw-normal" style="font-size: 10px;"><?= $net > 0 ? 'Payable (واجب الادا)' : 'Advance (ایڈوانس)' ?></span>
                                        </td>
                                        <td class="text-center">
                                            <a href="<?= BASE_URL ?>modules/workers/statement.php?worker_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-dark py-0 px-2 small">
                                                Statement <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="3" class="text-end">TOTALS:</td>
                                    <td class="text-center text-success"><?= number_format($sumGood) ?> pcs</td>
                                    <td class="text-center text-danger"><?= number_format($sumRej) ?></td>
                                    <td class="text-end text-dark fs-6"><?= format_currency($sumLab) ?></td>
                                    <td class="text-end text-secondary fs-6"><?= format_currency($sumPaid) ?></td>
                                    <td class="text-end <?= $sumNet > 0 ? 'text-danger' : 'text-success' ?> fs-6">
                                        <?= format_currency(abs($sumNet)) ?>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 7. OPERATIONAL: COMPLETE LOT MASTER REGISTER                   -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'lot_register'): 
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
                                    <th class="text-center">Planned Pcs</th>
                                    <th class="text-center">Packed Output</th>
                                    <th class="text-center">Dispatched</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
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
                                        <td class="text-center">
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2 small">
                                                View Lot <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 8. OPERATIONAL: MACHINE OPERATOR KNITTING OUTPUT               -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'operator_output'): 
                        $stmt = $db->query("SELECT pe.id, pe.entry_date, pe.shift, pe.good_qty, pe.rate, pe.labour_amount,
                                                   l.lot_number, p.name AS product_name, COALESCE(l.design_number, d.design_number) AS design_number,
                                                   w.name AS operator_name, w.code AS operator_code
                                            FROM production_entries pe
                                            JOIN lots l ON pe.lot_id = l.id
                                            JOIN products p ON l.product_id = p.id
                                            LEFT JOIN designs d ON l.design_id = d.id
                                            JOIN workers w ON pe.worker_id = w.id
                                            JOIN operations o ON pe.operation_id = o.id
                                            WHERE o.code = 'OP-OPERATOR' OR o.name LIKE '%Operator%' OR o.name LIKE '%Knitt%'
                                            ORDER BY pe.id DESC");
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $totKnitted = 0; $totOpLabour = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Operator Name</th>
                                    <th>Shift</th>
                                    <th>Lot #</th>
                                    <th>Product & Design</th>
                                    <th class="text-center">Knitted Pieces</th>
                                    <th class="text-end">Piece Rate</th>
                                    <th class="text-end">Labour Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">No machine knitting records logged yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): 
                                        $qty = (int)$r['good_qty'];
                                        $amt = (float)$r['labour_amount'];
                                        $totKnitted += $qty; $totOpLabour += $amt;
                                    ?>
                                        <tr>
                                            <td><?= format_date($r['entry_date']) ?></td>
                                            <td>
                                                <span class="fw-bold text-dark"><?= clean($r['operator_name']) ?></span>
                                                <div class="small text-muted"><?= clean($r['operator_code']) ?></div>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?= clean($r['shift'] ?: 'Day Shift') ?></span></td>
                                            <td class="fw-bold text-primary">LOT #<?= clean($r['lot_number']) ?></td>
                                            <td><?= clean($r['product_name']) ?> <?= $r['design_number'] ? '(#' . clean($r['design_number']) . ')' : '' ?></td>
                                            <td class="text-center fw-bold text-success fs-6"><?= number_format($qty) ?> pcs</td>
                                            <td class="text-end text-muted font-monospace"><?= format_currency($r['rate']) ?></td>
                                            <td class="text-end fw-bold text-dark"><?= format_currency($amt) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="5" class="text-end">TOTAL OPERATOR OUTPUT:</td>
                                    <td class="text-center text-success fs-6"><?= number_format($totKnitted) ?> pcs</td>
                                    <td></td>
                                    <td class="text-end text-dark fs-6"><?= format_currency($totOpLabour) ?></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 9. OPERATIONAL: CUTTING REPORT                                 -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'cutting_report'): 
                        $rows = $db->query("SELECT pe.*, l.lot_number, l.total_pieces, p.name AS product_name, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                                            FROM production_entries pe 
                                            JOIN lots l ON pe.lot_id = l.id 
                                            JOIN products p ON l.product_id = p.id 
                                            JOIN operations o ON pe.operation_id = o.id 
                                            JOIN workers w ON pe.worker_id = w.id 
                                            LEFT JOIN colors c ON pe.color_id = c.id 
                                            LEFT JOIN sizes s ON pe.size_id = s.id 
                                            WHERE o.code = 'OP-CUT' OR o.name LIKE '%Cut%' 
                                            ORDER BY pe.id DESC")->fetchAll();
                        $totCut = 0; $totRej = 0; $totCutLab = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Entry #</th>
                                    <th>Date</th>
                                    <th>Lot #</th>
                                    <th>Cutter Master</th>
                                    <th>Color & Size</th>
                                    <th class="text-center">Cut Pieces</th>
                                    <th class="text-center">Rejected</th>
                                    <th class="text-end">Labour Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">No cutting records entered yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): 
                                        $g = (int)$r['good_qty'];
                                        $rej = (int)$r['rejected_qty'];
                                        $lab = (float)$r['labour_amount'];
                                        $totCut += $g; $totRej += $rej; $totCutLab += $lab;
                                    ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= clean($r['entry_number']) ?></td>
                                            <td><?= format_date($r['entry_date']) ?></td>
                                            <td class="fw-bold">LOT #<?= clean($r['lot_number']) ?></td>
                                            <td><?= clean($r['worker_name']) ?></td>
                                            <td><?= clean($r['color_name'] ?: 'All Colors') ?> (<?= clean($r['size_name'] ?: 'Batch') ?>)</td>
                                            <td class="text-center fw-bold text-success fs-6"><?= number_format($g) ?> pcs</td>
                                            <td class="text-center text-danger"><?= number_format($rej) ?></td>
                                            <td class="text-end fw-bold"><?= format_currency($lab) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="5" class="text-end">TOTAL CUT OUTPUT:</td>
                                    <td class="text-center text-success fs-6"><?= number_format($totCut) ?> pcs</td>
                                    <td class="text-center text-danger"><?= number_format($totRej) ?></td>
                                    <td class="text-end text-dark fs-6"><?= format_currency($totCutLab) ?></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 10. OPERATIONAL: QUALITY & DEFECT REJECTIONS                   -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'quality_rejections'): 
                        $rows = $db->query("SELECT pr.id, pr.created_at, pr.quantity AS rejected_qty, pr.reason,
                                                   l.lot_number, p.name AS product_name, o.name AS op_name,
                                                   c.name AS color_name, s.name AS size_name,
                                                   w.name AS worker_name
                                            FROM production_rejections pr
                                            JOIN lots l ON pr.lot_id = l.id
                                            JOIN products p ON l.product_id = p.id
                                            JOIN operations o ON pr.operation_id = o.id
                                            LEFT JOIN colors c ON pr.color_id = c.id
                                            LEFT JOIN sizes s ON pr.size_id = s.id
                                            LEFT JOIN production_entries pe ON pr.production_entry_id = pe.id
                                            LEFT JOIN workers w ON pe.worker_id = w.id
                                            ORDER BY pr.id DESC")->fetchAll();
                        $sumRejections = array_sum(array_column($rows, 'rejected_qty'));
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Log Date</th>
                                    <th>Lot #</th>
                                    <th>Product</th>
                                    <th>Workflow Stage</th>
                                    <th>Color & Size</th>
                                    <th>Responsible Worker</th>
                                    <th class="text-center">Rejected Pcs</th>
                                    <th>Defect Reason / Quality Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">Zero defective pieces logged. Quality inspection 100% clean!</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td><?= format_date($r['created_at']) ?></td>
                                            <td class="fw-bold text-primary">LOT #<?= clean($r['lot_number']) ?></td>
                                            <td><?= clean($r['product_name']) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= clean($r['op_name']) ?></span></td>
                                            <td><?= clean($r['color_name'] ?: '—') ?> (<?= clean($r['size_name'] ?: '—') ?>)</td>
                                            <td><?= clean($r['worker_name'] ?: 'General Stage') ?></td>
                                            <td class="text-center fw-bold text-danger fs-6"><?= number_format($r['rejected_qty']) ?> pcs</td>
                                            <td class="text-muted small"><?= clean($r['reason'] ?: 'Quality tolerance deviation') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="6" class="text-end">TOTAL RECORDED DEFECTS:</td>
                                    <td class="text-center text-danger fs-6"><?= number_format($sumRejections) ?> pcs</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 11. OPERATIONAL: RAW MATERIAL STOCK & REORDER ALERTS           -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'material_stock'): 
                        $rows = $db->query("SELECT m.*, u.code AS unit_code, (m.current_stock * m.cost_price) AS stock_value
                                            FROM materials m
                                            JOIN units u ON m.unit_id = u.id
                                            WHERE m.is_active = 1
                                            ORDER BY (m.current_stock <= m.min_stock) DESC, m.name ASC")->fetchAll();
                        $sumStockValue = array_sum(array_column($rows, 'stock_value'));
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Material Code</th>
                                    <th>Material Name</th>
                                    <th>Category</th>
                                    <th class="text-end">Current Stock</th>
                                    <th class="text-end">Min Stock Level</th>
                                    <th class="text-end">Unit Cost</th>
                                    <th class="text-end">Total Stock Value</th>
                                    <th class="text-center">Inventory Health</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): 
                                    $isLow = (float)$r['current_stock'] <= (float)$r['min_stock'];
                                ?>
                                    <tr>
                                        <td class="font-monospace fw-bold text-dark"><?= clean($r['code']) ?></td>
                                        <td class="fw-bold text-dark"><?= clean($r['name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= clean($r['category']) ?></span></td>
                                        <td class="text-end fw-bold fs-6 <?= $isLow ? 'text-danger' : 'text-success' ?>">
                                            <?= number_format($r['current_stock'], 2) ?> <?= clean($r['unit_code']) ?>
                                        </td>
                                        <td class="text-end text-muted font-monospace"><?= number_format($r['min_stock'], 2) ?> <?= clean($r['unit_code']) ?></td>
                                        <td class="text-end text-muted font-monospace"><?= format_currency($r['cost_price']) ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency($r['stock_value']) ?></td>
                                        <td class="text-center">
                                            <?php if ($isLow): ?>
                                                <span class="badge bg-danger text-white"><i class="bi bi-exclamation-triangle-fill me-1"></i> Low Stock (کمی ہے)</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle">Healthy</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="6" class="text-end">TOTAL RAW MATERIAL VALUATION:</td>
                                    <td class="text-end text-primary fs-6"><?= format_currency($sumStockValue) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 12. OPERATIONAL: FINISHED GOODS WAREHOUSE INVENTORY            -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'finished_goods'): 
                        $rows = $db->query("SELECT fg.*, p.name AS product_name, d.design_number, c.name AS color_name, s.name AS size_name, l.lot_number, wh.name AS warehouse_name 
                                            FROM finished_goods_stock fg 
                                            JOIN products p ON fg.product_id = p.id 
                                            LEFT JOIN designs d ON fg.design_id = d.id 
                                            JOIN colors c ON fg.color_id = c.id 
                                            JOIN sizes s ON fg.size_id = s.id 
                                            JOIN lots l ON fg.lot_id = l.id 
                                            LEFT JOIN warehouses wh ON fg.warehouse_id = wh.id 
                                            ORDER BY p.name ASC, c.name ASC")->fetchAll();
                        $sumStockPcs = array_sum(array_column($rows, 'current_stock'));
                        $sumDispPcs = array_sum(array_column($rows, 'dispatched_stock'));
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Design #</th>
                                    <th>Color</th>
                                    <th>Size</th>
                                    <th>Lot #</th>
                                    <th>Warehouse Location</th>
                                    <th class="text-end">Ready In-Stock</th>
                                    <th class="text-end">Dispatched to Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= clean($r['product_name']) ?></td>
                                        <td><?= $r['design_number'] ? '#' . clean($r['design_number']) : '-' ?></td>
                                        <td><?= clean($r['color_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= clean($r['size_name']) ?></span></td>
                                        <td class="fw-semibold text-primary">LOT #<?= clean($r['lot_number']) ?></td>
                                        <td><?= clean($r['warehouse_name'] ?: 'Main Warehouse') ?></td>
                                        <td class="text-end fw-bold text-success fs-6"><?= number_format($r['current_stock']) ?> pcs</td>
                                        <td class="text-end text-muted"><?= number_format($r['dispatched_stock']) ?> pcs</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="6" class="text-end">TOTAL FINISHED GOODS ON HAND:</td>
                                    <td class="text-end text-success fs-6"><?= number_format($sumStockPcs) ?> pcs</td>
                                    <td class="text-end text-muted"><?= number_format($sumDispPcs) ?> pcs</td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 13. OPERATIONAL: CUSTOMER DISPATCHES & DELIVERIES              -->
                    <!-- ============================================================== -->
                    <?php elseif ($reportType === 'dispatch_report'): 
                        $rows = $db->query("SELECT d.*, c.name AS customer_name, c.phone AS customer_phone,
                                                   (SELECT COUNT(*) FROM dispatch_items WHERE dispatch_id = d.id) AS total_items,
                                                   (SELECT COALESCE(SUM(quantity), 0) FROM dispatch_items WHERE dispatch_id = d.id) AS total_pcs
                                            FROM dispatches d
                                            JOIN customers c ON d.customer_id = c.id
                                            ORDER BY d.id DESC")->fetchAll();
                        $sumDispatchedPcs = array_sum(array_column($rows, 'total_pcs'));
                        $sumDispatchedVal = array_sum(array_column($rows, 'total_amount'));
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Challan / Inv #</th>
                                    <th>Dispatch Date</th>
                                    <th>Customer / Buyer</th>
                                    <th>Vehicle / Driver</th>
                                    <th class="text-center">Line Items</th>
                                    <th class="text-center">Total Quantity</th>
                                    <th class="text-end">Invoice Value</th>
                                    <th>Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="fw-bold text-primary font-monospace"><?= clean($r['dispatch_number']) ?></td>
                                        <td><?= format_date($r['dispatch_date']) ?></td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= clean($r['customer_name']) ?></span>
                                            <div class="small text-muted"><?= clean($r['customer_phone'] ?: '') ?></div>
                                        </td>
                                        <td>
                                            <div><?= clean($r['driver_name'] ?: 'Direct Handover') ?></div>
                                            <small class="text-muted"><?= clean($r['vehicle_number'] ?: '') ?></small>
                                        </td>
                                        <td class="text-center fw-semibold"><?= (int)$r['total_items'] ?></td>
                                        <td class="text-center fw-bold text-success fs-6"><?= number_format($r['total_pcs']) ?> pcs</td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency($r['total_amount'] ?? 0) ?></td>
                                        <td><span class="badge bg-success-subtle text-success border border-success-subtle"><?= clean($r['status']) ?></span></td>
                                        <td class="text-center">
                                            <a href="<?= BASE_URL ?>modules/dispatch/challan.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark py-0 px-2 small">
                                                Challan <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="5" class="text-end">TOTAL DISPATCHED DELIVERIES:</td>
                                    <td class="text-center text-success fs-6"><?= number_format($sumDispatchedPcs) ?> pcs</td>
                                    <td class="text-end text-dark fs-6"><?= format_currency($sumDispatchedVal ?? 0) ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>

                    <!-- ============================================================== -->
                    <!-- 14. OPERATIONAL: PENDING WORK & BOTTLENECK ANALYSIS            -->
                    <!-- ============================================================== -->
                    <?php else: 
                        $rows = $db->query("SELECT l.id AS lot_id, l.lot_number, p.name AS product_name, l.total_pieces, o.name AS op_name, lws.sequence,
                                            (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = l.id AND operation_id = o.id) AS good_done
                                            FROM lots l 
                                            JOIN products p ON l.product_id = p.id 
                                            JOIN lot_workflow_steps lws ON l.id = lws.lot_id 
                                            JOIN operations o ON lws.operation_id = o.id 
                                            WHERE l.status NOT IN ('Closed', 'Cancelled', 'Ready Stock', 'Dispatched') 
                                            ORDER BY l.id DESC, lws.sequence ASC")->fetchAll();
                        $totPendingPcs = 0;
                    ?>
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Lot Number</th>
                                    <th>Product</th>
                                    <th>Workflow Operation</th>
                                    <th class="text-center">Batch Target</th>
                                    <th class="text-center">Completed</th>
                                    <th class="text-center">Pending Balance</th>
                                    <th>Floor Status</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">All active production batches are fully completed! Zero pending work.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): 
                                        $pending = max(0, (int)$r['total_pieces'] - (int)$r['good_done']);
                                        if ($pending === 0) continue;
                                        $totPendingPcs += $pending;
                                    ?>
                                        <tr>
                                            <td class="fw-bold">
                                                <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $r['lot_id'] ?>" class="text-primary text-decoration-none">
                                                    LOT #<?= clean($r['lot_number']) ?>
                                                </a>
                                            </td>
                                            <td><?= clean($r['product_name']) ?></td>
                                            <td class="fw-bold text-dark">
                                                <span class="badge bg-secondary-subtle text-secondary me-1"><?= $r['sequence'] ?></span>
                                                <?= clean($r['op_name']) ?>
                                            </td>
                                            <td class="text-center fw-bold"><?= number_format($r['total_pieces']) ?></td>
                                            <td class="text-center text-success"><?= number_format($r['good_done']) ?></td>
                                            <td class="text-center fw-bold text-danger fs-6"><?= number_format($pending) ?> pcs</td>
                                            <td><span class="badge bg-warning-subtle text-warning-emphasis"><i class="bi bi-clock-history me-1"></i> Incomplete</span></td>
                                            <td class="text-center">
                                                <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $r['lot_id'] ?>" class="btn btn-sm btn-primary py-0 px-2 small">
                                                    Enter Work <i class="bi bi-plus-circle"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="5" class="text-end">TOTAL PENDING WORK IN PIPELINE:</td>
                                    <td class="text-center text-danger fs-6"><?= number_format($totPendingPcs) ?> pcs</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>

<style>
@media print {
    .app-sidebar, #sidebarBackdrop, .app-header, .topbar, .no-print, .btn, form, nav[aria-label="breadcrumb"] {
        display: none !important;
    }
    .app-main {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .app-content {
        padding: 0 !important;
    }
    .card-modern {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 11px !important;
    }
    .badge {
        border: 1px solid #999 !important;
        color: #000 !important;
        background: transparent !important;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
