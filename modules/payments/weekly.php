<?php
/**
 * Garment Manufacturing ERP - Weekly Worker Payments & Lot Labour Disbursement
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Weekly Worker Payments';
$activeNav = 'weekly_payments';
$db = get_db();

// -------------------------------------------------------------
// 1. DATE PRESETS & FILTER PARAMETERS
// -------------------------------------------------------------
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$yesterday6 = date('Y-m-d', strtotime('-7 days'));
$lastThursday = date('Y-m-d', strtotime('last thursday'));
$lastThursday6 = date('Y-m-d', strtotime('last thursday -6 days'));
$lastFriday = date('Y-m-d', strtotime('last friday'));
$lastFriday6 = date('Y-m-d', strtotime('last friday -6 days'));

// Standard work week Monday to Sunday
$defaultMonday = date('Y-m-d', strtotime('monday this week'));
$defaultSunday = date('Y-m-d', strtotime('sunday this week'));
$lastMonday = date('Y-m-d', strtotime('monday last week'));
$lastSunday = date('Y-m-d', strtotime('sunday last week'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$dateFrom = trim($_GET['date_from'] ?? $defaultMonday);
$dateTo = trim($_GET['date_to'] ?? $today);
$searchTerm = trim($_GET['search'] ?? '');
$deptFilter = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$wageTypeFilter = trim($_GET['wage_type'] ?? '');
$balanceFilter = trim($_GET['balance_status'] ?? '');
$lotFilter = !empty($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;
$activeTab = trim($_GET['tab'] ?? 'workers'); // 'workers' or 'lots'

// Default payment date for vouchers: use dateTo if available, otherwise today
$paymentDateDefault = !empty($dateTo) ? $dateTo : $today;

// -------------------------------------------------------------
// 2. FETCH REFERENCE DATA (Departments, Lots, Accounts, Methods)
// -------------------------------------------------------------
$departments = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, current_balance FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();
$paymentMethods = $db->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Lots active in date range or recent lots for filter
$recentLotsStmt = $db->query("SELECT id, lot_number, total_pieces, status FROM lots ORDER BY id DESC LIMIT 50");
$recentLots = $recentLotsStmt->fetchAll();

// -------------------------------------------------------------
// 3. FETCH LAST PAYMENT AMOUNTS FOR EACH WORKER (LAST WEEK'S PAYMENT)
// -------------------------------------------------------------
// We find what was paid in the previous weekly disbursement:
// If dateFrom is provided, find latest payment prior to dateFrom; otherwise get latest overall.
$lastPaymentsMap = [];
if (!empty($dateFrom)) {
    $prevStmt = $db->prepare("
        SELECT wp.worker_id, wp.amount, wp.payment_date 
        FROM worker_payments wp
        INNER JOIN (
            SELECT worker_id, MAX(id) AS max_id 
            FROM worker_payments 
            WHERE is_reversed = 0 AND payment_date < ?
            GROUP BY worker_id
        ) latest ON wp.id = latest.max_id
    ");
    $prevStmt->execute([$dateFrom]);
    foreach ($prevStmt->fetchAll() as $row) {
        $lastPaymentsMap[$row['worker_id']] = [
            'amount' => (float)$row['amount'],
            'payment_date' => $row['payment_date']
        ];
    }
}

// Fallback for workers with no payment before dateFrom (or if dateFrom is empty)
$latestStmt = $db->query("
    SELECT wp.worker_id, wp.amount, wp.payment_date 
    FROM worker_payments wp
    INNER JOIN (
        SELECT worker_id, MAX(id) AS max_id 
        FROM worker_payments 
        WHERE is_reversed = 0
        GROUP BY worker_id
    ) latest ON wp.id = latest.max_id
");
foreach ($latestStmt->fetchAll() as $row) {
    if (!isset($lastPaymentsMap[$row['worker_id']])) {
        $lastPaymentsMap[$row['worker_id']] = [
            'amount' => (float)$row['amount'],
            'payment_date' => $row['payment_date']
        ];
    }
}

// -------------------------------------------------------------
// 4. FETCH WORKERS WITH ACCUMULATED LEDGER AS OF DATE & PERIOD LABOUR
// -------------------------------------------------------------
$params = [];

// Balance calculation as of dateTo (settlement date) or all-time if dateTo is empty
if (!empty($dateTo)) {
    $balSql = "COALESCE((SELECT SUM(credit) - SUM(debit) FROM worker_ledger WHERE worker_id = w.id AND transaction_date <= :p_to_bal), w.opening_balance) AS current_balance,";
    $params[':p_to_bal'] = $dateTo;
} else {
    $balSql = "COALESCE((SELECT SUM(credit) - SUM(debit) FROM worker_ledger WHERE worker_id = w.id), w.opening_balance) AS current_balance,";
}

// Period earned & paid
if (!empty($dateFrom) && !empty($dateTo)) {
    $earnedSql = "COALESCE((SELECT SUM(credit) FROM worker_ledger 
                            WHERE worker_id = w.id 
                              AND transaction_type = 'Labour' 
                              AND (transaction_date >= :p_from AND transaction_date <= :p_to)), 0.00) AS period_earned,";
    $paidSql = "COALESCE((SELECT SUM(debit) FROM worker_ledger 
                          WHERE worker_id = w.id 
                            AND transaction_type IN ('Payment', 'Advance') 
                            AND (transaction_date >= :p_from2 AND transaction_date <= :p_to2)), 0.00) AS period_paid";
    $params[':p_from'] = $dateFrom;
    $params[':p_to'] = $dateTo;
    $params[':p_from2'] = $dateFrom;
    $params[':p_to2'] = $dateTo;
} else {
    $earnedSql = "0.00 AS period_earned,";
    $paidSql = "0.00 AS period_paid";
}

$sql = "SELECT w.*, 
               dep.name AS department_name,
               $balSql
               -- All-time Running Balance for comparison
               COALESCE((SELECT SUM(credit) - SUM(debit) FROM worker_ledger WHERE worker_id = w.id), w.opening_balance) AS all_time_balance,
               $earnedSql
               $paidSql
        FROM workers w
        LEFT JOIN departments dep ON w.department_id = dep.id
        WHERE w.is_active = 1";

if (!empty($searchTerm)) {
    $sql .= " AND (w.name LIKE :search1 OR w.code LIKE :search2 OR w.phone LIKE :search3)";
    $params[':search1'] = "%$searchTerm%";
    $params[':search2'] = "%$searchTerm%";
    $params[':search3'] = "%$searchTerm%";
}

if ($deptFilter > 0) {
    $sql .= " AND w.department_id = :dept_id";
    $params[':dept_id'] = $deptFilter;
}

if (!empty($wageTypeFilter)) {
    $sql .= " AND w.worker_type = :wage_type";
    $params[':wage_type'] = $wageTypeFilter;
}

$sql .= " ORDER BY dep.name ASC, w.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allWorkersRaw = $stmt->fetchAll();

// Pre-fetch lots worked per worker in date range for fast rendering
$workerLotsMap = [];
if (!empty($dateFrom) && !empty($dateTo)) {
    $wLotSql = "SELECT pe.worker_id, pe.lot_id, l.lot_number, p.name AS product_name, 
                       SUM(pe.good_qty) AS pieces, SUM(pe.labour_amount) AS labour
                FROM production_entries pe
                JOIN lots l ON pe.lot_id = l.id
                JOIN products p ON l.product_id = p.id
                WHERE (pe.entry_date >= ? AND pe.entry_date <= ?)
                GROUP BY pe.worker_id, pe.lot_id, l.lot_number, p.name";
    $wLotStmt = $db->prepare($wLotSql);
    $wLotStmt->execute([$dateFrom, $dateTo]);
    foreach ($wLotStmt->fetchAll() as $r) {
        $workerLotsMap[$r['worker_id']][] = $r;
    }
}

// Filter in-memory for balance status or specific lot
$filteredWorkers = [];
$totalPeriodEarned = 0.00;
$totalPeriodPaid = 0.00;
$totalOutstandingLiability = 0.00;
$totalPayingNowSheet = 0.00;
$totalCheckedWorkers = 0;

foreach ($allWorkersRaw as $w) {
    $wId = (int)$w['id'];
    $curBal = (float)$w['current_balance'];
    $allTimeBal = (float)$w['all_time_balance'];
    $pEarned = (float)$w['period_earned'];
    $pPaid = (float)$w['period_paid'];

    // Balance filter check
    if ($balanceFilter === 'payable' && $curBal <= 0) continue;
    if ($balanceFilter === 'settled' && abs($curBal) > 0.01) continue;
    if ($balanceFilter === 'advance' && $curBal >= 0) continue;

    // Lot filter check
    if ($lotFilter > 0) {
        $workedOnLot = false;
        if (!empty($workerLotsMap[$wId])) {
            foreach ($workerLotsMap[$wId] as $lotItem) {
                if ($lotItem['lot_id'] == $lotFilter) {
                    $workedOnLot = true;
                    break;
                }
            }
        }
        if (!$workedOnLot) continue;
    }

    // Last week paid info
    $lastPaid = (float)($lastPaymentsMap[$wId]['amount'] ?? 0.00);
    $lastPayDate = $lastPaymentsMap[$wId]['payment_date'] ?? null;

    // Default amount to pay:
    // Starts at 0.00 on load; populated upon clicking the One-Click "Auto-Fill Last Week" button!
    $defaultPayAmount = 0.00;
    $shouldCheck = false;

    $w['last_paid'] = $lastPaid;
    $w['last_pay_date'] = $lastPayDate;
    $w['default_pay_amount'] = $defaultPayAmount;
    $w['should_check'] = $shouldCheck;
    $w['lots_worked'] = $workerLotsMap[$wId] ?? [];
    $filteredWorkers[] = $w;

    $totalPeriodEarned += $pEarned;
    $totalPeriodPaid += $pPaid;
    if ($curBal > 0) {
        $totalOutstandingLiability += $curBal;
    }
    if ($shouldCheck) {
        $totalPayingNowSheet += $defaultPayAmount;
        $totalCheckedWorkers++;
    }
}

// -------------------------------------------------------------
// 5. FETCH LOT-WISE LABOUR SUMMARY FOR TAB 2
// -------------------------------------------------------------
$lotSummaryMap = [];
if (!empty($dateFrom) && !empty($dateTo)) {
    $lotLabourSql = "SELECT l.id AS lot_id, l.lot_number, l.status, p.name AS product_name,
                            pe.worker_id, w.name AS worker_name, w.code AS worker_code, w.worker_type,
                            dep.name AS dept_name, o.name AS op_name,
                            SUM(pe.good_qty) AS good_pieces,
                            SUM(pe.labour_amount) AS total_labour,
                            MAX(pe.entry_date) AS last_work_date
                     FROM production_entries pe
                     JOIN lots l ON pe.lot_id = l.id
                     JOIN products p ON l.product_id = p.id
                     JOIN operations o ON pe.operation_id = o.id
                     JOIN workers w ON pe.worker_id = w.id
                     LEFT JOIN departments dep ON w.department_id = dep.id
                     WHERE (pe.entry_date >= ? AND pe.entry_date <= ?)";
    $lotParams = [$dateFrom, $dateTo];

    if ($lotFilter > 0) {
        $lotLabourSql .= " AND l.id = ?";
        $lotParams[] = $lotFilter;
    }

    $lotLabourSql .= " GROUP BY l.id, l.lot_number, l.status, p.name, pe.worker_id, w.name, w.code, w.worker_type, dep.name, o.name
                       ORDER BY l.id DESC, total_labour DESC";

    $lStmt = $db->prepare($lotLabourSql);
    $lStmt->execute($lotParams);
    $lotEntries = $lStmt->fetchAll();

    foreach ($lotEntries as $le) {
        $lId = $le['lot_id'];
        if (!isset($lotSummaryMap[$lId])) {
            $lotSummaryMap[$lId] = [
                'lot_id' => $lId,
                'lot_number' => $le['lot_number'],
                'product_name' => $le['product_name'],
                'status' => $le['status'],
                'total_pieces' => 0,
                'total_labour' => 0.00,
                'workers' => []
            ];
        }
        $lotSummaryMap[$lId]['total_pieces'] += (int)$le['good_pieces'];
        $lotSummaryMap[$lId]['total_labour'] += (float)$le['total_labour'];
        $lotSummaryMap[$lId]['workers'][] = $le;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">

        <!-- ========================================== -->
        <!-- HEADER & PRINT-FRIENDLY TITLE -->
        <!-- ========================================== -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/workers/index.php" class="text-decoration-none">Workers</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Weekly Payments</li>
                    </ol>
                </nav>
                <h3 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-calendar2-week-fill text-warning"></i>
                    <span>Weekly Worker Payments (ہفتہ وار ورکرز ادائیگیاں)</span>
                </h3>
                <p class="text-muted small mb-0">
                    Settlement Date: <strong><?= !empty($dateTo) ? date('d M Y', strtotime($dateTo)) : 'All Time' ?></strong> 
                    <?php if (!empty($dateFrom)): ?> | Period: <?= date('d M', strtotime($dateFrom)) ?> to <?= date('d M Y', strtotime($dateTo)) ?><?php endif; ?>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2 no-print">
                <button type="button" class="btn btn-outline-dark fw-semibold btn-print-trigger">
                    <i class="bi bi-printer me-1"></i> Print Payroll Sheet (پرنٹ شیٹ)
                </button>
                <a href="<?= BASE_URL ?>modules/payments/create.php" class="btn btn-outline-primary fw-semibold">
                    <i class="bi bi-plus-lg me-1"></i> Single Payment Voucher
                </a>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TOP KPI STAT CARDS -->
        <!-- ========================================== -->
        <div class="row g-3 mb-4 no-print">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 bg-white h-100 border-start border-primary border-4">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small fw-semibold">Visible Workers (ورکرز)</span>
                                <h4 class="fw-bold mb-0 text-dark mt-1" id="kpi_worker_count"><?= count($filteredWorkers) ?></h4>
                            </div>
                            <div class="rounded-circle bg-primary-subtle p-2 text-primary">
                                <i class="bi bi-people-fill fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 bg-white h-100 border-start border-info border-4">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small fw-semibold">Period Earned (ہفتہ وار کمائی)</span>
                                <h4 class="fw-bold mb-0 text-info mt-1"><?= format_currency($totalPeriodEarned) ?></h4>
                            </div>
                            <div class="rounded-circle bg-info-subtle p-2 text-info">
                                <i class="bi bi-graph-up-arrow fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 bg-white h-100 border-start border-secondary border-4">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small fw-semibold">Period Paid (ہفتہ وار ادا شدہ)</span>
                                <h4 class="fw-bold mb-0 text-secondary mt-1"><?= format_currency($totalPeriodPaid) ?></h4>
                            </div>
                            <div class="rounded-circle bg-secondary-subtle p-2 text-secondary">
                                <i class="bi bi-check2-circle fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm rounded-3 bg-white h-100 border-start border-danger border-4">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small fw-semibold">Net Balance (کل واجبات)</span>
                                <h4 class="fw-bold mb-0 text-danger mt-1" id="kpi_outstanding_total"><?= format_currency($totalOutstandingLiability) ?></h4>
                            </div>
                            <div class="rounded-circle bg-danger-subtle p-2 text-danger">
                                <i class="bi bi-wallet2 fs-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- FILTERS & SETTLEMENT DATE TOOLBAR -->
        <!-- ========================================== -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 no-print">
            <div class="card-body p-3 p-md-4">
                <form method="GET" action="<?= BASE_URL ?>modules/payments/weekly.php" id="filterForm">
                    <input type="hidden" name="tab" id="formActiveTab" value="<?= clean($activeTab) ?>">

                    <!-- Quick Date Preset Buttons -->
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 pb-2 border-bottom">
                        <span class="small fw-bold text-dark me-1"><i class="bi bi-calendar-range text-primary me-1"></i>Quick Date Presets:</span>
                        
                        <a href="?date_from=<?= $defaultMonday ?>&date_to=<?= $today ?>&department_id=<?= $deptFilter ?>&wage_type=<?= urlencode($wageTypeFilter) ?>&balance_status=<?= urlencode($balanceFilter) ?>&lot_id=<?= $lotFilter ?>&tab=<?= $activeTab ?>" 
                           class="btn btn-xs btn-outline-primary py-1 px-2 <?= ($dateFrom === $defaultMonday && $dateTo === $today) ? 'active fw-bold' : '' ?>" style="font-size: 12px;">
                            Today (آج کا حساب)
                        </a>

                        <a href="?date_from=<?= $yesterday6 ?>&date_to=<?= $yesterday ?>&department_id=<?= $deptFilter ?>&wage_type=<?= urlencode($wageTypeFilter) ?>&balance_status=<?= urlencode($balanceFilter) ?>&lot_id=<?= $lotFilter ?>&tab=<?= $activeTab ?>" 
                           class="btn btn-xs btn-outline-secondary py-1 px-2 <?= ($dateFrom === $yesterday6 && $dateTo === $yesterday) ? 'active fw-bold' : '' ?>" style="font-size: 12px;">
                            Yesterday (گزشتہ کل)
                        </a>

                        <a href="?date_from=<?= $lastThursday6 ?>&date_to=<?= $lastThursday ?>&department_id=<?= $deptFilter ?>&wage_type=<?= urlencode($wageTypeFilter) ?>&balance_status=<?= urlencode($balanceFilter) ?>&lot_id=<?= $lotFilter ?>&tab=<?= $activeTab ?>" 
                           class="btn btn-xs btn-outline-warning text-dark py-1 px-2 <?= ($dateFrom === $lastThursday6 && $dateTo === $lastThursday) ? 'active fw-bold' : '' ?>" style="font-size: 12px;">
                            Thursday Settlement (گزشتہ جمعرات کا حساب)
                        </a>

                        <a href="?date_from=<?= $lastFriday6 ?>&date_to=<?= $lastFriday ?>&department_id=<?= $deptFilter ?>&wage_type=<?= urlencode($wageTypeFilter) ?>&balance_status=<?= urlencode($balanceFilter) ?>&lot_id=<?= $lotFilter ?>&tab=<?= $activeTab ?>" 
                           class="btn btn-xs btn-outline-secondary py-1 px-2 <?= ($dateFrom === $lastFriday6 && $dateTo === $lastFriday) ? 'active fw-bold' : '' ?>" style="font-size: 12px;">
                            Friday Settlement (گزشتہ جمعہ)
                        </a>

                        <a href="?date_from=<?= $lastMonday ?>&date_to=<?= $lastSunday ?>&department_id=<?= $deptFilter ?>&wage_type=<?= urlencode($wageTypeFilter) ?>&balance_status=<?= urlencode($balanceFilter) ?>&lot_id=<?= $lotFilter ?>&tab=<?= $activeTab ?>" 
                           class="btn btn-xs btn-outline-secondary py-1 px-2 <?= ($dateFrom === $lastMonday && $dateTo === $lastSunday) ? 'active fw-bold' : '' ?>" style="font-size: 12px;">
                            Last Full Week (گزشتہ پورا ہفتہ)
                        </a>

                        <a href="?date_from=<?= $monthStart ?>&date_to=<?= $monthEnd ?>&department_id=<?= $deptFilter ?>&wage_type=<?= urlencode($wageTypeFilter) ?>&balance_status=<?= urlencode($balanceFilter) ?>&lot_id=<?= $lotFilter ?>&tab=<?= $activeTab ?>" 
                           class="btn btn-xs btn-outline-secondary py-1 px-2 <?= ($dateFrom === $monthStart && $dateTo === $monthEnd) ? 'active fw-bold' : '' ?>" style="font-size: 12px;">
                            This Month (موجودہ مہینہ)
                        </a>

                        <a href="?date_from=&date_to=&balance_status=payable&department_id=<?= $deptFilter ?>&wage_type=<?= urlencode($wageTypeFilter) ?>&tab=<?= $activeTab ?>" 
                           class="btn btn-xs btn-outline-danger py-1 px-2 <?= ($balanceFilter === 'payable' && empty($dateFrom)) ? 'active fw-bold' : '' ?>" style="font-size: 12px;">
                            All Outstanding (تمام سابقہ واجبات)
                        </a>
                    </div>

                    <div class="row g-2 align-items-end">
                        <!-- Settlement Cutoff Date (To Date) -->
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label small fw-bold text-dark mb-1 d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-calendar-check text-primary me-1"></i>Settlement Date</span>
                                <small class="text-muted">حساب کی تاریخ</small>
                            </label>
                            <input type="date" name="date_to" id="inp_date_to" class="form-control form-control-sm fw-bold border-primary" value="<?= clean($dateTo) ?>">
                        </div>

                        <!-- From Date -->
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label small fw-bold text-muted mb-1 d-flex justify-content-between align-items-center">
                                <span>From Date</span>
                                <button type="button" class="btn btn-link btn-xs p-0 text-decoration-none" id="btn_quick_7days" title="Set to 7 days ending on Settlement Date" style="font-size: 11px;">
                                    Auto 7 Days
                                </button>
                            </label>
                            <input type="date" name="date_from" id="inp_date_from" class="form-control form-control-sm" value="<?= clean($dateFrom) ?>">
                        </div>

                        <!-- Search -->
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label small fw-bold text-muted mb-1">Search (تلاش ورکر)</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" name="search" id="inp_live_search" class="form-control" placeholder="Name / Code / Phone" value="<?= clean($searchTerm) ?>">
                            </div>
                        </div>

                        <!-- Department -->
                        <div class="col-6 col-sm-6 col-md-2">
                            <label class="form-label small fw-bold text-muted mb-1">Department (شعبہ)</label>
                            <select name="department_id" class="form-select form-select-sm">
                                <option value="">-- All Departments --</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $deptFilter == $d['id'] ? 'selected' : '' ?>>
                                        <?= clean($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Wage Type -->
                        <div class="col-6 col-sm-6 col-md-2">
                            <label class="form-label small fw-bold text-muted mb-1">Wage Type (اجرت کی قسم)</label>
                            <select name="wage_type" class="form-select form-select-sm">
                                <option value="">-- All Wage Types --</option>
                                <option value="Piece Rate" <?= $wageTypeFilter === 'Piece Rate' ? 'selected' : '' ?>>Piece Rate (پر پیس)</option>
                                <option value="Daily Wage" <?= $wageTypeFilter === 'Daily Wage' ? 'selected' : '' ?>>Daily Wage (یومیہ)</option>
                                <option value="Minute Rate" <?= $wageTypeFilter === 'Minute Rate' ? 'selected' : '' ?>>Minute Rate (منٹ)</option>
                                <option value="Monthly Salary" <?= $wageTypeFilter === 'Monthly Salary' ? 'selected' : '' ?>>Monthly Salary (ماہانہ)</option>
                            </select>
                        </div>

                        <!-- Balance Status -->
                        <div class="col-6 col-sm-6 col-md-2">
                            <label class="form-label small fw-bold text-muted mb-1">Status (بیلنس کیفیت)</label>
                            <select name="balance_status" class="form-select form-select-sm">
                                <option value="">-- All Workers --</option>
                                <option value="payable" <?= $balanceFilter === 'payable' ? 'selected' : '' ?>>Payable (&gt; 0 واجب الادا)</option>
                                <option value="settled" <?= $balanceFilter === 'settled' ? 'selected' : '' ?>>Settled (= 0 بیباق)</option>
                                <option value="advance" <?= $balanceFilter === 'advance' ? 'selected' : '' ?>>In Advance (&lt; 0 پیشگی)</option>
                            </select>
                        </div>

                        <!-- Lot Filter -->
                        <div class="col-12 col-md-3 mt-2">
                            <label class="form-label small fw-bold text-muted mb-1">Filter by Specific Lot (لاٹ)</label>
                            <select name="lot_id" class="form-select form-select-sm searchable-lot">
                                <option value="">-- All Lots in Period --</option>
                                <?php foreach ($recentLots as $lt): ?>
                                    <option value="<?= $lt['id'] ?>" <?= $lotFilter == $lt['id'] ? 'selected' : '' ?>>
                                        Lot #<?= clean($lt['lot_number']) ?> (<?= $lt['total_pieces'] ?> pcs)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Buttons -->
                        <div class="col-12 col-md-3 mt-2 ms-auto d-flex gap-2 justify-content-end">
                            <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">
                                <i class="bi bi-funnel-fill me-1"></i> Apply (لاگو کریں)
                            </button>
                            <a href="<?= BASE_URL ?>modules/payments/weekly.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- GLOBAL DISBURSEMENT BAR & TABS -->
        <!-- ============================================================== -->
        <div class="card border-0 shadow-sm rounded-3 mb-3 no-print bg-white">
            <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
                
                <!-- View Tabs -->
                <ul class="nav nav-pills" id="weeklyTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold <?= $activeTab === 'workers' ? 'active' : '' ?>" id="workers-tab" data-bs-toggle="pill" data-bs-target="#workersPane" type="button" role="tab">
                            <i class="bi bi-people-fill me-1"></i> Worker Disbursement Sheet (ورکر وار شیٹ)
                            <span class="badge bg-white text-primary ms-1"><?= count($filteredWorkers) ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold <?= $activeTab === 'lots' ? 'active' : '' ?>" id="lots-tab" data-bs-toggle="pill" data-bs-target="#lotsPane" type="button" role="tab">
                            <i class="bi bi-diagram-3-fill me-1"></i> Lot-Wise Labour (لاٹ وار خلاصہ)
                            <span class="badge bg-white text-secondary ms-1"><?= count($lotSummaryMap) ?></span>
                        </button>
                    </li>
                </ul>

                <!-- Global Payment Controls -->
                <div class="d-flex flex-column gap-2 w-100 mt-2">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 bg-light rounded-3 border">
                        
                        <!-- Global Payment / Voucher Date with Quick Switch & Day Indicator -->
                        <div class="d-flex flex-wrap align-items-center gap-2 bg-white p-2 rounded border shadow-xs">
                            <span class="small fw-bold text-dark text-nowrap" title="Date that will be recorded on payment vouchers and ledgers">
                                <i class="bi bi-calendar-event text-primary me-1"></i>تاریخ ادائیگی (Pay Date):
                            </span>
                            <input type="date" id="global_payment_date" class="form-control form-control-sm py-1 fw-bold border-primary shadow-xs" style="width: 145px; font-size: 13px;" value="<?= clean($paymentDateDefault) ?>">
                            <span id="lbl_pay_day_name" class="badge bg-primary text-white py-1 px-2" style="font-size: 11px;">
                                <?= date('l (D)', strtotime($paymentDateDefault)) ?>
                            </span>

                            <!-- Quick Day Switcher Buttons -->
                            <div class="btn-group btn-group-sm ms-1" role="group">
                                <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 btn-set-pay-date <?= ($paymentDateDefault === $today) ? 'active fw-bold' : '' ?>" data-date="<?= $today ?>">
                                    آج (Today)
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 btn-set-pay-date <?= ($paymentDateDefault === $yesterday) ? 'active fw-bold' : '' ?>" data-date="<?= $yesterday ?>">
                                    گزشتہ کل
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-warning text-dark py-1 px-2 btn-set-pay-date <?= ($paymentDateDefault === $lastThursday) ? 'active fw-bold' : '' ?>" data-date="<?= $lastThursday ?>" title="جمعرات کی تاریخ پر ادائیگی ریکارڈ کریں">
                                    <i class="bi bi-check2-circle text-warning-emphasis"></i> جمعرات (Thu)
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-success py-1 px-2 btn-set-pay-date <?= ($paymentDateDefault === $lastFriday) ? 'active fw-bold' : '' ?>" data-date="<?= $lastFriday ?>">
                                    جمعہ (Fri)
                                </button>
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div class="d-flex align-items-center gap-1 bg-white p-1 px-2 rounded border">
                            <span class="small fw-semibold text-muted text-nowrap">Method:</span>
                            <select id="global_payment_method" class="form-select form-select-sm py-1" style="width: 130px; font-size: 12px;">
                                <option value="Cash">Cash (نقد)</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="JazzCash">JazzCash</option>
                                <option value="Easypaisa">Easypaisa</option>
                            </select>
                        </div>

                        <!-- Cash / Bank Account -->
                        <div class="d-flex align-items-center gap-1 bg-white p-1 px-2 rounded border">
                            <span class="small fw-semibold text-muted text-nowrap">Account:</span>
                            <select id="global_account_id" class="form-select form-select-sm py-1" style="width: 180px; font-size: 12px;">
                                <option value="" data-type="cash">-- Direct Cash Drawer --</option>
                                <?php foreach ($accounts as $acc): 
                                    $isCash = (stripos($acc['account_name'], 'cash') !== false || stripos($acc['bank_name'] ?? '', 'cash') !== false || stripos($acc['bank_name'] ?? '', 'drawer') !== false);
                                    $accType = $isCash ? 'cash' : 'bank';
                                ?>
                                    <option value="<?= $acc['id'] ?>" data-type="<?= $accType ?>"><?= clean($acc['account_name']) ?> (Bal: <?= number_format($acc['current_balance']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Live Total Badge & Pay Selected Button -->
                        <div class="d-flex align-items-center gap-2 ms-lg-2">
                            <div class="text-end">
                                <span class="small text-muted d-block" style="font-size: 11px;">Selected to Pay:</span>
                                <span class="badge bg-success-subtle text-success fs-6 border border-success-subtle py-1 px-2">
                                    <strong id="bar_selected_count"><?= $totalCheckedWorkers ?></strong> workers | <strong id="bar_selected_total"><?= format_currency($totalPayingNowSheet) ?></strong>
                                </span>
                            </div>
                            <button type="button" class="btn btn-sm btn-success fw-bold px-3 shadow-sm" id="btn_bulk_pay">
                                <i class="bi bi-cash-stack me-1"></i> Pay Selected (منتخب ادائیگی)
                            </button>
                        </div>

                    </div>

                    <!-- One-Click Auto-Fill Action Toolbar -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 bg-light-subtle rounded-3 border">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="small fw-bold text-dark"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>One-Click Auto-Fill:</span>
                            <button type="button" class="btn btn-sm btn-primary fw-bold shadow-xs px-3" id="btn_autofill_last_week" title="Auto-fill last week's disbursement amounts into inputs">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Auto-Fill Last Week (گزشتہ ہفتے کے خرچے فل کریں)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold" id="btn_autofill_full_balance" title="Fill full remaining ledger balance for all workers">
                                <i class="bi bi-wallet2 me-1"></i> Fill Full Balance (مکمل واجبات)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger fw-semibold" id="btn_clear_all_amounts" title="Clear all input amounts">
                                <i class="bi bi-x-circle me-1"></i> Clear All (سب خالی کریں)
                            </button>
                        </div>
                        <div class="small text-muted" style="font-size: 11.5px;">
                            <i class="bi bi-info-circle text-primary me-1"></i>
                            رقم آٹو فل ہونے کے بعد صرف اسی ورکر کے آگے <strong>Pay</strong> بٹن دبائیں جس کو ادائیگی دینی ہے (باقی کسی کو خرچہ نہیں ملے گا)۔
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-content" id="weeklyTabsContent">

            <!-- ============================================================== -->
            <!-- TAB 1: WORKER-WISE DISBURSEMENT SHEET -->
            <!-- ============================================================== -->
            <div class="tab-pane fade <?= $activeTab === 'workers' ? 'show active' : '' ?>" id="workersPane" role="tabpanel">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tblWeeklyWorkers">
                            <thead class="table-light text-muted small fw-semibold text-uppercase">
                                <tr>
                                    <th style="width: 38px;" class="text-center no-print">
                                        <input class="form-check-input" type="checkbox" id="chk_select_all" title="Select / Deselect All Workers">
                                    </th>
                                    <th style="width: 45px;">#</th>
                                    <th style="min-width: 190px;">Worker & Dept (کاریگر و شعبہ)</th>
                                    <th style="min-width: 120px;">Lots Worked (لاٹس)</th>
                                    <th class="text-end" style="min-width: 100px; white-space: nowrap;">Period Earned<br><small class="text-muted fw-normal">ہفتہ وار کمائی</small></th>
                                    <th class="text-end" style="min-width: 100px; white-space: nowrap;">Period Paid<br><small class="text-muted fw-normal">ہفتہ وار ادائیگی</small></th>
                                    <th class="text-end" style="min-width: 120px; white-space: nowrap;">Net Balance<br><small class="text-muted fw-normal">کل واجبات</small></th>
                                    <th class="text-end" style="min-width: 110px; white-space: nowrap;">Last Paid<br><small class="text-muted fw-normal">گزشتہ ادائیگی</small></th>
                                    <th class="text-end no-print" style="width: 215px; min-width: 215px;">
                                        Paying Now (ادائیگی رقم) *
                                        <div class="mt-1">
                                            <span class="badge bg-warning text-dark border font-latin" id="hdr_pay_date_badge" style="font-size: 10.5px;">
                                                📅 <strong id="hdr_date_val"><?= date('d M Y (D)', strtotime($paymentDateDefault)) ?></strong>
                                            </span>
                                        </div>
                                    </th>
                                    <th class="no-print" style="width: 165px; min-width: 165px;">Note / Reference</th>
                                    <th class="text-center no-print" style="width: 125px; min-width: 125px;">Action (محفوظ کریں)</th>
                                    <th class="d-none d-print-table-cell text-center" style="width: 140px;">Signature (دستخط)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($filteredWorkers)): ?>
                                    <tr>
                                        <td colspan="12" class="text-center py-5 text-muted">
                                            <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                            <span class="fw-semibold">No workers found matching the selected filters.</span>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $idx = 0;
                                    foreach ($filteredWorkers as $w): 
                                        $idx++;
                                        $wId = (int)$w['id'];
                                        $curBal = (float)$w['current_balance'];
                                        $allTimeBal = (float)$w['all_time_balance'];
                                        $pEarned = (float)$w['period_earned'];
                                        $pPaid = (float)$w['period_paid'];
                                        $wLots = $w['lots_worked'];
                                        $lastPaid = (float)$w['last_paid'];
                                        $lastPayDate = $w['last_pay_date'];
                                        $defaultPayAmount = (float)$w['default_pay_amount'];
                                        $shouldCheck = (bool)$w['should_check'];
                                    ?>
                                        <tr class="worker-row <?= (!$shouldCheck && $defaultPayAmount <= 0) ? 'worker-skipped' : '' ?>" 
                                            id="row_worker_<?= $wId ?>" 
                                            data-worker-id="<?= $wId ?>"
                                            data-worker-name="<?= clean($w['name']) ?>"
                                            data-worker-code="<?= clean($w['code']) ?>"
                                            data-current-balance="<?= $curBal ?>"
                                            data-last-paid="<?= $lastPaid ?>">
                                            
                                            <!-- Select Checkbox -->
                                            <td class="text-center no-print">
                                                <input class="form-check-input chk-worker-select" type="checkbox" 
                                                       value="<?= $wId ?>" 
                                                       <?= $shouldCheck ? 'checked' : '' ?>
                                                       data-default-amount="<?= $defaultPayAmount ?>"
                                                       data-last-paid="<?= $lastPaid ?>"
                                                       data-full-balance="<?= max(0, $curBal) ?>">
                                            </td>

                                            <!-- Index -->
                                            <td class="text-muted small fw-semibold"><?= $idx ?></td>

                                            <!-- Worker Info: Name + Code + Department + Wage Type under Name -->
                                            <td>
                                                <div class="d-flex align-items-center gap-2 worker-name-container">
                                                    <div class="avatar-circle bg-primary-subtle text-primary fw-bold rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px; font-size: 13px;">
                                                        <?= mb_substr($w['name'], 0, 1) ?>
                                                    </div>
                                                    <div>
                                                        <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $wId ?>" class="fw-bold text-dark text-decoration-none worker-name-label">
                                                            <?= clean($w['name']) ?>
                                                        </a>
                                                        <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                                                            <span class="badge bg-light text-secondary border font-monospace" style="font-size: 10px;"><?= clean($w['code']) ?></span>
                                                            <?php if (!empty($w['department_name'])): ?>
                                                                <span class="badge bg-secondary-subtle text-secondary" style="font-size: 10px;"><?= clean($w['department_name']) ?></span>
                                                            <?php endif; ?>
                                                            <?php
                                                            $wt = $w['worker_type'] ?? 'Piece Rate';
                                                            $wtBadge = 'bg-primary-subtle text-primary';
                                                            if (stripos($wt, 'daily') !== false) $wtBadge = 'bg-info-subtle text-info-emphasis';
                                                            elseif (stripos($wt, 'minute') !== false) $wtBadge = 'bg-success-subtle text-success';
                                                            elseif (stripos($wt, 'month') !== false) $wtBadge = 'bg-warning-subtle text-warning-emphasis';
                                                            ?>
                                                            <span class="badge <?= $wtBadge ?> border" style="font-size: 10px;"><?= clean($wt) ?></span>
                                                            <?php if (!$shouldCheck && $defaultPayAmount <= 0): ?>
                                                                <span class="badge bg-secondary-subtle text-secondary small border badge-skipped" style="font-size: 9px;">Skipped (نہیں دینا)</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- Lots Worked (Pills with modal details) -->
                                            <td>
                                                <?php if (empty($wLots)): ?>
                                                    <span class="text-muted small">—</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                                        <?php 
                                                        $shownLots = array_slice($wLots, 0, 3);
                                                        foreach ($shownLots as $l): ?>
                                                            <span class="badge bg-light text-dark border px-2 py-1" style="font-size: 10px;" title="<?= clean($l['product_name']) ?> (<?= $l['pieces'] ?> pcs @ Rs. <?= number_format($l['labour'], 2) ?>)">
                                                                Lot #<?= clean($l['lot_number']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($wLots) > 3): ?>
                                                            <span class="badge bg-secondary text-white" style="font-size: 9px;">+<?= count($wLots) - 3 ?> more</span>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-xs btn-link p-0 text-decoration-none btn-inspect-lots ms-1 no-print" 
                                                                data-worker-id="<?= $wId ?>" 
                                                                data-worker-name="<?= clean($w['name']) ?>"
                                                                title="View Lot breakdown">
                                                            <i class="bi bi-info-circle text-primary"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Period Earned -->
                                            <td class="text-end fw-semibold text-info" style="white-space: nowrap;">
                                                Rs. <?= number_format($pEarned, 2) ?>
                                            </td>

                                            <!-- Period Paid -->
                                            <td class="text-end fw-semibold text-secondary" style="white-space: nowrap;">
                                                Rs. <?= number_format($pPaid, 2) ?>
                                            </td>

                                            <!-- Net Balance as of Settlement Date -->
                                            <td class="text-end" style="white-space: nowrap;">
                                                <?php if ($curBal > 0): ?>
                                                    <span class="fw-bold text-danger fs-6 lbl-net-balance">
                                                        Rs. <?= number_format($curBal, 2) ?>
                                                    </span>
                                                    <span class="badge bg-danger-subtle text-danger d-block small" style="font-size: 9px;">Payable (واجب الادا)</span>
                                                <?php elseif ($curBal < 0): ?>
                                                    <span class="fw-bold text-warning-emphasis fs-6 lbl-net-balance">
                                                        Rs. <?= number_format(abs($curBal), 2) ?>
                                                    </span>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis d-block small" style="font-size: 9px;">Advance (پیشگی زیادہ ادا)</span>
                                                <?php else: ?>
                                                    <span class="fw-semibold text-muted lbl-net-balance">Rs. 0.00</span>
                                                    <span class="badge bg-light text-muted border d-block small" style="font-size: 9px;">Settled (بیباق)</span>
                                                <?php endif; ?>

                                                <?php if (abs($curBal - $allTimeBal) > 0.01 && !empty($dateTo)): ?>
                                                    <small class="text-muted d-block" style="font-size: 9px;" title="Current all-time ledger balance">Latest: Rs. <?= number_format($allTimeBal, 2) ?></small>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Last Paid (گزشتہ ادائیگی) -->
                                            <td class="text-end" style="white-space: nowrap;">
                                                <?php if ($lastPaid > 0): ?>
                                                    <span class="badge bg-light text-dark border fw-bold" style="font-size: 11px;">
                                                        <i class="bi bi-clock-history text-primary me-1"></i>Rs. <?= number_format($lastPaid, 2) ?>
                                                    </span>
                                                    <?php if (!empty($lastPayDate)): ?>
                                                        <small class="text-muted d-block mt-1" style="font-size: 10px;">
                                                            <?= date('d M Y', strtotime($lastPayDate)) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Paying Now Input with Quick Action Buttons -->
                                            <td class="text-end no-print" style="width: 200px; min-width: 200px;">
                                                <div class="input-group input-group-sm mb-1">
                                                    <span class="input-group-text px-2 text-muted fw-semibold" style="font-size: 11px;">Rs.</span>
                                                    <input type="number" step="any" min="0" 
                                                           class="form-control text-end fw-bold inp-pay-now text-primary" 
                                                           id="inp_pay_<?= $wId ?>"
                                                           value="<?= number_format($defaultPayAmount, 2, '.', '') ?>"
                                                           placeholder="0.00"
                                                           data-worker-id="<?= $wId ?>"
                                                           data-last-paid="<?= number_format($lastPaid, 2, '.', '') ?>"
                                                           data-full-balance="<?= number_format(max(0, $curBal), 2, '.', '') ?>"
                                                           style="font-size: 13px;">
                                                </div>

                                                <!-- Quick Helper Buttons: Last Paid, Full Balance, 0 (Skip) -->
                                                <div class="d-flex justify-content-end gap-1">
                                                    <?php if ($lastPaid > 0): ?>
                                                        <button type="button" class="btn btn-xs btn-outline-info py-0 px-1 btn-fill-amount" 
                                                                data-target="inp_pay_<?= $wId ?>" 
                                                                data-amount="<?= number_format($lastPaid, 2, '.', '') ?>" 
                                                                title="Auto-fill last week's payment amount" 
                                                                style="font-size: 10px;">
                                                            Last: <?= number_format($lastPaid) ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($curBal > 0): ?>
                                                        <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1 btn-fill-amount" 
                                                                data-target="inp_pay_<?= $wId ?>" 
                                                                data-amount="<?= number_format($curBal, 2, '.', '') ?>" 
                                                                title="Pay full remaining balance" 
                                                                style="font-size: 10px;">
                                                            Full: <?= number_format($curBal) ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-xs btn-outline-danger py-0 px-1 btn-set-zero" 
                                                            data-target="inp_pay_<?= $wId ?>" 
                                                            data-worker-id="<?= $wId ?>" 
                                                            title="Skip / Set to 0 (اس بار ادائیگی نہ کریں)" 
                                                            style="font-size: 10px;">
                                                        0 (Skip)
                                                    </button>
                                                </div>
                                                <div class="text-end text-muted small mt-1" style="font-size: 10px;">
                                                    تاریخ: <span class="lbl-row-date font-monospace fw-semibold text-primary"><?= date('d M', strtotime($paymentDateDefault)) ?></span>
                                                </div>
                                            </td>

                                            <!-- Notes / Reference -->
                                            <td class="no-print" style="width: 165px; min-width: 165px;">
                                                <input type="text" class="form-control form-control-sm inp-worker-note" 
                                                       id="inp_note_<?= $wId ?>" 
                                                       placeholder="Week ending <?= !empty($dateTo) ? date('d M', strtotime($dateTo)) : date('d M') ?>"
                                                       style="font-size: 11px;">
                                            </td>

                                            <!-- Action: Checkmark Tick Button -->
                                            <td class="text-center no-print" style="width: 125px; min-width: 125px;">
                                                <div class="d-flex align-items-center justify-content-center gap-1 action-wrapper">
                                                    <button type="button" 
                                                            class="btn btn-sm btn-success px-3 fw-bold btn-tick-pay" 
                                                            data-worker-id="<?= $wId ?>"
                                                            data-worker-name="<?= clean($w['name']) ?>"
                                                            title="Save Weekly Payment (ادائیگی محفوظ کریں)">
                                                        <i class="bi bi-check2-circle me-1"></i> Pay
                                                    </button>
                                                    <a href="<?= BASE_URL ?>modules/workers/statement.php?id=<?= $wId ?>" 
                                                        target="_blank" 
                                                        class="btn btn-sm btn-outline-secondary px-2" 
                                                        title="View Full Worker Statement (کھاتہ)">
                                                        <i class="bi bi-file-text"></i>
                                                    </a>
                                                </div>
                                            </td>

                                            <!-- Signature Column for Print -->
                                            <td class="d-none d-print-table-cell text-center text-muted" style="height: 45px; border-bottom: 1px dashed #999;">
                                                <small>____________________</small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- TAB 2: LOT-WISE LABOUR ANALYSIS -->
            <!-- ============================================================== -->
            <div class="tab-pane fade <?= $activeTab === 'lots' ? 'show active' : '' ?>" id="lotsPane" role="tabpanel">
                <?php if (empty($lotSummaryMap)): ?>
                    <div class="card border-0 shadow-sm rounded-3 p-5 text-center text-muted">
                        <i class="bi bi-diagram-3 fs-1 text-secondary mb-2"></i>
                        <h5 class="fw-bold">No Lot Labour Records Found in Selected Period</h5>
                        <p class="small mb-0">Try expanding the date range or removing specific filters.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($lotSummaryMap as $lId => $lot): ?>
                            <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                                <div class="card-header bg-light py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary fs-6 px-2 py-1">Lot #<?= clean($lot['lot_number']) ?></span>
                                        <span class="fw-bold text-dark"><?= clean($lot['product_name']) ?></span>
                                        <span class="badge bg-secondary-subtle text-secondary small"><?= clean($lot['status']) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="small text-muted">Total Pieces: <strong class="text-dark"><?= $lot['total_pieces'] ?></strong></span>
                                        <span class="small text-muted">Total Labour Cost: <strong class="text-danger fs-6">Rs. <?= number_format($lot['total_labour'], 2) ?></strong></span>
                                        <a href="?lot_id=<?= $lId ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&tab=workers" class="btn btn-xs btn-outline-primary py-1 px-2 small no-print">
                                            <i class="bi bi-filter me-1"></i> View & Pay Workers
                                        </a>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="table-light text-muted small">
                                            <tr>
                                                <th>Worker (کاریگر)</th>
                                                <th>Department</th>
                                                <th>Operation (مرحلہ)</th>
                                                <th>Wage Scheme</th>
                                                <th class="text-center">Good Pieces</th>
                                                <th class="text-end">Labour Cost (رقم)</th>
                                                <th class="text-center no-print">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lot['workers'] as $lw): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= clean($lw['worker_name']) ?></div>
                                                        <span class="badge bg-light text-secondary border font-monospace" style="font-size: 10px;"><?= clean($lw['worker_code']) ?></span>
                                                    </td>
                                                    <td><span class="badge bg-secondary-subtle text-secondary small"><?= clean($lw['dept_name'] ?? '—') ?></span></td>
                                                    <td class="fw-semibold text-primary"><?= clean($lw['op_name']) ?></td>
                                                    <td><span class="badge bg-light text-dark border small"><?= clean($lw['worker_type']) ?></span></td>
                                                    <td class="text-center fw-bold"><?= $lw['good_pieces'] ?></td>
                                                    <td class="text-end fw-bold text-danger">Rs. <?= number_format($lw['total_labour'], 2) ?></td>
                                                    <td class="text-center no-print">
                                                        <a href="?search=<?= urlencode($lw['worker_code']) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&tab=workers" class="btn btn-xs btn-outline-primary py-0 px-2" style="font-size: 11px;">
                                                            Disburse
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div> <!-- end tab-content -->

    </div> <!-- end app-content -->

<!-- ============================================================== -->
<!-- CONFIRMATION MODAL (SINGLE WORKER PAYMENT) -->
<!-- ============================================================== -->
<div class="modal fade" id="modalConfirmPay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-shield-check me-2"></i>Confirm Weekly Payment (ادائیگی کی تصدیق)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3">
                    <span class="text-muted small">Disbursing weekly labour payment to:</span>
                    <h4 class="fw-bold text-dark mt-1" id="m_worker_name">—</h4>
                    <span class="badge bg-primary-subtle text-primary border" id="m_worker_code">—</span>
                </div>

                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Current Net Balance (واجبات):</span>
                        <strong class="text-dark" id="m_current_balance">Rs. 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <span class="text-muted small">Payment Amount (رقم ادائیگی):</span>
                        <strong class="text-success fs-5" id="m_pay_amount">Rs. 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">New Estimated Balance:</span>
                        <strong class="text-secondary" id="m_new_balance">Rs. 0.00</strong>
                    </div>
                </div>

                <div id="m_advance_warning" class="alert alert-warning small py-2 d-none">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>Warning:</strong> You are paying more than the remaining balance. The surplus will be recorded as an advance in the worker ledger.
                </div>

                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1 d-flex justify-content-between">
                            <span>Disbursement Date (تاریخ ادائیگی)</span>
                            <span id="m_lbl_day_name" class="badge bg-primary-subtle text-primary border" style="font-size: 10px;"><?= date('l', strtotime($paymentDateDefault)) ?></span>
                        </label>
                        <input type="date" id="m_payment_date" class="form-control form-control-sm fw-bold border-primary" value="<?= clean($paymentDateDefault) ?>">
                        <div class="d-flex gap-1 mt-1">
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" style="font-size: 10px;" onclick="document.getElementById('m_payment_date').value='<?= $today ?>'; document.getElementById('m_payment_date').dispatchEvent(new Event('change'));">آج</button>
                            <button type="button" class="btn btn-xs btn-outline-warning text-dark py-0 px-1 fw-bold" style="font-size: 10px;" onclick="document.getElementById('m_payment_date').value='<?= $lastThursday ?>'; document.getElementById('m_payment_date').dispatchEvent(new Event('change'));">جمعرات</button>
                            <button type="button" class="btn btn-xs btn-outline-success py-0 px-1" style="font-size: 10px;" onclick="document.getElementById('m_payment_date').value='<?= $lastFriday ?>'; document.getElementById('m_payment_date').dispatchEvent(new Event('change'));">جمعہ</button>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1">Payment Method</label>
                        <select id="m_payment_method" class="form-select form-select-sm">
                            <option value="Cash">Cash (نقد)</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="JazzCash">JazzCash</option>
                            <option value="Easypaisa">Easypaisa</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-muted mb-1">Deduct from Cash / Bank Account</label>
                        <select id="m_account_id" class="form-select form-select-sm">
                            <option value="" data-type="cash">-- Direct Factory Cash Drawer --</option>
                            <?php foreach ($accounts as $acc): 
                                $isCash = (stripos($acc['account_name'], 'cash') !== false || stripos($acc['bank_name'] ?? '', 'cash') !== false || stripos($acc['bank_name'] ?? '', 'drawer') !== false);
                                $accType = $isCash ? 'cash' : 'bank';
                            ?>
                                <option value="<?= $acc['id'] ?>" data-type="<?= $accType ?>"><?= clean($acc['account_name']) ?> (Bal: Rs. <?= number_format($acc['current_balance'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-muted mb-1">Notes / Period Remarks</label>
                        <input type="text" id="m_notes" class="form-control form-control-sm" placeholder="e.g. Week ending <?= !empty($dateTo) ? date('d M Y', strtotime($dateTo)) : date('d M Y') ?>">
                    </div>
                </div>

                <input type="hidden" id="m_worker_id" value="">
                <input type="hidden" id="m_raw_amount" value="0">
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel (منسوخ)</button>
                <button type="button" class="btn btn-success btn-sm px-4 fw-bold" id="btn_confirm_disburse">
                    <i class="bi bi-check-lg me-1"></i> Disburse & Save (ادائیگی محفوظ کریں)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- LOT BREAKDOWN DETAILS MODAL -->
<!-- ============================================================== -->
<div class="modal fade" id="modalLotBreakdown" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-diagram-3 me-2"></i>Worker Lot Production Breakdown (لاٹ بریک ڈاؤن)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <h5 class="fw-bold text-dark mb-0" id="lb_worker_name">—</h5>
                        <small class="text-muted">Production entries between <?= clean($dateFrom) ?> and <?= clean($dateTo) ?></small>
                    </div>
                    <div class="text-end">
                        <span class="text-muted small">Total Labour:</span>
                        <h5 class="fw-bold text-danger mb-0" id="lb_total_labour">Rs. 0.00</h5>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light small">
                            <tr>
                                <th>Date</th>
                                <th>Lot #</th>
                                <th>Product</th>
                                <th>Operation</th>
                                <th class="text-center">Good Pcs</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Labour (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody id="lb_table_body">
                            <tr><td colspan="7" class="text-center py-3 text-muted">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- BULK PAYMENT CONFIRMATION MODAL -->
<!-- ============================================================== -->
<div class="modal fade" id="modalBulkPayConfirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-cash-stack me-2"></i>Confirm Bulk Weekly Payment (اجتماعی ادائیگی)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 border mb-3 text-center">
                    <span class="text-muted small">Selected Workers Count:</span>
                    <h3 class="fw-bold text-dark mb-1" id="bulk_count_label">0</h3>
                    <span class="text-muted small">Total Disbursement Amount:</span>
                    <h2 class="fw-bold text-success mb-0" id="bulk_amount_label">Rs. 0.00</h2>
                </div>

                <p class="small text-muted mb-3">
                    Are you sure you want to disburse weekly payments to all selected workers simultaneously? This will generate individual payment vouchers and debit their accounts immediately.
                </p>

                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1">Disbursement Date (تاریخ ادائیگی)</label>
                        <input type="date" id="bulk_payment_date" class="form-control form-control-sm fw-bold border-primary" value="<?= clean($paymentDateDefault) ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1">Payment Method</label>
                        <select id="bulk_payment_method" class="form-select form-select-sm">
                            <option value="Cash">Cash (نقد)</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cheque">Cheque</option>
                            <option value="JazzCash">JazzCash</option>
                            <option value="Easypaisa">Easypaisa</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-muted mb-1">Deduct from Cash / Bank Account</label>
                        <select id="bulk_account_id" class="form-select form-select-sm">
                            <option value="" data-type="cash">-- Direct Factory Cash Drawer --</option>
                            <?php foreach ($accounts as $acc): 
                                $isCash = (stripos($acc['account_name'], 'cash') !== false || stripos($acc['bank_name'] ?? '', 'cash') !== false || stripos($acc['bank_name'] ?? '', 'drawer') !== false);
                                $accType = $isCash ? 'cash' : 'bank';
                            ?>
                                <option value="<?= $acc['id'] ?>" data-type="<?= $accType ?>"><?= clean($acc['account_name']) ?> (Bal: Rs. <?= number_format($acc['current_balance'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm px-4 fw-bold" id="btn_execute_bulk_pay">
                    <i class="bi bi-check2-circle me-1"></i> Disburse All (سب کو ادا کریں)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const BASE_URL = '<?= BASE_URL ?>';
    const periodFrom = '<?= $dateFrom ?>';
    const periodTo = '<?= $dateTo ?>';

    const modalConfirmEl = document.getElementById('modalConfirmPay');
    const modalConfirm = modalConfirmEl ? new bootstrap.Modal(modalConfirmEl) : null;

    const modalLotEl = document.getElementById('modalLotBreakdown');
    const modalLot = modalLotEl ? new bootstrap.Modal(modalLotEl) : null;

    const modalBulkEl = document.getElementById('modalBulkPayConfirm');
    const modalBulk = modalBulkEl ? new bootstrap.Modal(modalBulkEl) : null;

    // Keep active tab state in sync
    const tabButtons = document.querySelectorAll('#weeklyTabs button[data-bs-toggle="pill"]');
    const formActiveTab = document.getElementById('formActiveTab');
    tabButtons.forEach(btn => {
        btn.addEventListener('shown.bs.tab', function(e) {
            const targetId = e.target.getAttribute('id');
            if (formActiveTab) {
                formActiveTab.value = (targetId === 'lots-tab') ? 'lots' : 'workers';
            }
        });
    });

    // Auto 7 Days helper button in filters
    const btnQuick7Days = document.getElementById('btn_quick_7days');
    if (btnQuick7Days) {
        btnQuick7Days.addEventListener('click', function() {
            const toVal = document.getElementById('inp_date_to').value;
            if (toVal) {
                const parts = toVal.split('-');
                if (parts.length === 3) {
                    const d = new Date(parts[0], parts[1] - 1, parts[2]);
                    d.setDate(d.getDate() - 6);
                    const yr = d.getFullYear();
                    const mo = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    document.getElementById('inp_date_from').value = `${yr}-${mo}-${day}`;
                }
            }
        });
    }

    // Live search within table
    const searchInput = document.getElementById('inp_live_search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('#tblWeeklyWorkers tbody tr.worker-row').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
            updateLiveSummary();
        });
    }

    // -------------------------------------------------------------
    // PAYMENT DATE SYNCHRONIZER & QUICK DAY SELECTORS
    // -------------------------------------------------------------
    const dayNamesMap = [
        'Sunday (اتوار)', 
        'Monday (پیر)', 
        'Tuesday (منگل)', 
        'Wednesday (بدھ)', 
        'Thursday (جمعرات)', 
        'Friday (جمعہ)', 
        'Saturday (ہفتہ)'
    ];
    const shortMonthsMap = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function syncPaymentDate(dateStr) {
        if (!dateStr) return;
        const globalDate = document.getElementById('global_payment_date');
        if (globalDate) globalDate.value = dateStr;

        const parts = dateStr.split('-');
        if (parts.length === 3) {
            const d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            const dayName = dayNamesMap[d.getDay()] || '';
            const dayShort = dayName.split(' ')[0];
            const formatted = `${String(d.getDate()).padStart(2, '0')} ${shortMonthsMap[d.getMonth()]} ${d.getFullYear()} (${dayShort})`;

            const lblDay = document.getElementById('lbl_pay_day_name');
            if (lblDay) lblDay.textContent = dayName;

            const hdrVal = document.getElementById('hdr_date_val');
            if (hdrVal) hdrVal.textContent = formatted;

            document.querySelectorAll('.lbl-row-date').forEach(el => {
                el.textContent = `${String(d.getDate()).padStart(2, '0')} ${shortMonthsMap[d.getMonth()]}`;
            });

            const mDate = document.getElementById('m_payment_date');
            if (mDate) mDate.value = dateStr;
            const bulkDate = document.getElementById('bulk_payment_date');
            if (bulkDate) bulkDate.value = dateStr;
        }
    }

    const globalDateInput = document.getElementById('global_payment_date');
    if (globalDateInput) {
        globalDateInput.addEventListener('change', function() {
            syncPaymentDate(this.value);
            document.querySelectorAll('.btn-set-pay-date').forEach(b => {
                b.classList.toggle('active', b.dataset.date === globalDateInput.value);
            });
        });
    }

    document.querySelectorAll('.btn-set-pay-date').forEach(btn => {
        btn.addEventListener('click', function() {
            const d = this.dataset.date;
            if (d) {
                syncPaymentDate(d);
                document.querySelectorAll('.btn-set-pay-date').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });

    // -------------------------------------------------------------
    // ONE-CLICK AUTO-FILL BUTTONS (LAST WEEK PAYMENTS & FULL BALANCES)
    // -------------------------------------------------------------
    const btnAutoFillLastWeek = document.getElementById('btn_autofill_last_week');
    if (btnAutoFillLastWeek) {
        btnAutoFillLastWeek.addEventListener('click', function() {
            let filledCount = 0;
            let totalFilled = 0;
            document.querySelectorAll('#tblWeeklyWorkers tbody tr.worker-row').forEach(tr => {
                const lastPaid = parseFloat(tr.dataset.lastPaid) || 0;
                const wId = tr.dataset.workerId;
                const inp = document.getElementById('inp_pay_' + wId);
                if (inp) {
                    if (lastPaid > 0) {
                        inp.value = lastPaid.toFixed(2);
                        tr.classList.remove('worker-skipped');
                        const badge = tr.querySelector('.badge-skipped');
                        if (badge) badge.remove();
                        filledCount++;
                        totalFilled += lastPaid;
                    } else {
                        inp.value = '0.00';
                    }
                }
            });
            // Crucial: Checkboxes remain unselected so bulk disburse is never accidental.
            // User pays individually by clicking "Pay" on the workers they want to disburse!
            updateLiveSummary();
            if (typeof showToast === 'function') {
                showToast(`گزشتہ ہفتے کے خرچے ${filledCount} ورکرز کے لیے آٹو فل ہو گئے (کل: Rs. ${totalFilled.toLocaleString()})۔ جس کو ادا کرنا ہو صرف اسی کے آگے 'Pay' پر کلک کریں۔`, 'success');
            } else {
                alert(`Last week payment amounts filled for ${filledCount} workers. Click 'Pay' on the workers you want to disburse.`);
            }
        });
    }

    const btnAutoFillFullBal = document.getElementById('btn_autofill_full_balance');
    if (btnAutoFillFullBal) {
        btnAutoFillFullBal.addEventListener('click', function() {
            let filledCount = 0;
            let totalFilled = 0;
            document.querySelectorAll('#tblWeeklyWorkers tbody tr.worker-row').forEach(tr => {
                const curBal = parseFloat(tr.dataset.currentBalance) || 0;
                const wId = tr.dataset.workerId;
                const inp = document.getElementById('inp_pay_' + wId);
                if (inp) {
                    if (curBal > 0) {
                        inp.value = curBal.toFixed(2);
                        tr.classList.remove('worker-skipped');
                        const badge = tr.querySelector('.badge-skipped');
                        if (badge) badge.remove();
                        filledCount++;
                        totalFilled += curBal;
                    } else {
                        inp.value = '0.00';
                    }
                }
            });
            updateLiveSummary();
            if (typeof showToast === 'function') {
                showToast(`مکمل واجبات ${filledCount} ورکرز کے لیے آٹو فل ہو گئے (کل: Rs. ${totalFilled.toLocaleString()})۔`, 'info');
            }
        });
    }

    const btnClearAll = document.getElementById('btn_clear_all_amounts');
    if (btnClearAll) {
        btnClearAll.addEventListener('click', function() {
            document.querySelectorAll('#tblWeeklyWorkers tbody tr.worker-row').forEach(tr => {
                const wId = tr.dataset.workerId;
                const inp = document.getElementById('inp_pay_' + wId);
                if (inp) inp.value = '0.00';
                const chk = tr.querySelector('.chk-worker-select');
                if (chk) chk.checked = false;
                tr.classList.add('worker-skipped');
            });
            const chkSelectAll = document.getElementById('chk_select_all');
            if (chkSelectAll) chkSelectAll.checked = false;
            updateLiveSummary();
            if (typeof showToast === 'function') {
                showToast('تمام رقمیں خالی کر دی گئیں (0.00)۔', 'secondary');
            }
        });
    }

    // -------------------------------------------------------------
    // LIVE SUMMARY COUNTER (REAL-TIME RECALCULATION)
    // -------------------------------------------------------------
    function updateLiveSummary() {
        const checkedBoxes = Array.from(document.querySelectorAll('.chk-worker-select:checked'));
        let totalAmt = 0.00;
        let count = 0;

        checkedBoxes.forEach(chk => {
            const tr = chk.closest('tr');
            if (tr && tr.style.display !== 'none') {
                const wId = chk.value;
                const payInp = document.getElementById('inp_pay_' + wId);
                const amt = parseFloat(payInp ? payInp.value : 0) || 0;
                if (amt > 0) {
                    totalAmt += amt;
                    count++;
                }
            }
        });

        const countEl = document.getElementById('bar_selected_count');
        const totalEl = document.getElementById('bar_selected_total');
        if (countEl) countEl.textContent = count;
        if (totalEl) totalEl.textContent = 'Rs. ' + totalAmt.toLocaleString('en-PK', {minimumFractionDigits: 2});

        const bulkBtn = document.getElementById('btn_bulk_pay');
        if (bulkBtn) {
            if (count === 0 || totalAmt <= 0) {
                bulkBtn.classList.add('disabled');
            } else {
                bulkBtn.classList.remove('disabled');
            }
        }
    }

    // Initial update
    updateLiveSummary();

    // Select All / Deselect All Checkbox
    const chkSelectAll = document.getElementById('chk_select_all');
    if (chkSelectAll) {
        chkSelectAll.addEventListener('change', function() {
            document.querySelectorAll('.chk-worker-select').forEach(chk => {
                const tr = chk.closest('tr');
                if (tr && tr.style.display !== 'none') {
                    chk.checked = chkSelectAll.checked;
                    if (chk.checked) {
                        tr.classList.remove('worker-skipped');
                        const b = tr.querySelector('.badge-skipped');
                        if (b) b.remove();
                        const payInp = tr.querySelector('.inp-pay-now');
                        if (payInp && (parseFloat(payInp.value) || 0) <= 0) {
                            const defAmt = parseFloat(chk.dataset.defaultAmount) || parseFloat(chk.dataset.lastPaid) || parseFloat(chk.dataset.fullBalance) || 0;
                            payInp.value = defAmt.toFixed(2);
                        }
                    } else {
                        tr.classList.add('worker-skipped');
                    }
                }
            });
            updateLiveSummary();
        });
    }

    // -------------------------------------------------------------
    // QUICK SET BUTTONS (LAST WEEK AMOUNT, FULL BALANCE, ZERO / SKIP)
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-fill-amount').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const amt = parseFloat(this.dataset.amount) || 0;
            const inp = document.getElementById(targetId);
            if (inp) {
                inp.value = amt.toFixed(2);
                const tr = inp.closest('tr');
                if (tr) {
                    const chk = tr.querySelector('.chk-worker-select');
                    if (chk) chk.checked = (amt > 0);
                    if (amt > 0) {
                        tr.classList.remove('worker-skipped');
                        const badge = tr.querySelector('.badge-skipped');
                        if (badge) badge.remove();
                    }
                }
                updateLiveSummary();
            }
        });
    });

    document.querySelectorAll('.btn-set-zero').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const inp = document.getElementById(targetId);
            if (inp) {
                inp.value = '0.00';
                const tr = inp.closest('tr');
                if (tr) {
                    const chk = tr.querySelector('.chk-worker-select');
                    if (chk) chk.checked = false;
                    tr.classList.add('worker-skipped');
                    if (!tr.querySelector('.badge-skipped')) {
                        const infoCol = tr.querySelector('.worker-name-container');
                        if (infoCol) {
                            const b = document.createElement('span');
                            b.className = 'badge bg-secondary-subtle text-secondary small border badge-skipped';
                            b.style.fontSize = '9px';
                            b.textContent = 'Skipped (نہیں دینا)';
                            infoCol.querySelector('div')?.appendChild(b);
                        }
                    }
                }
                updateLiveSummary();
            }
        });
    });

    // When typing into Paying Now input field
    document.querySelectorAll('.inp-pay-now').forEach(inp => {
        inp.addEventListener('input', function() {
            const amt = parseFloat(this.value) || 0;
            const tr = this.closest('tr');
            if (tr) {
                const chk = tr.querySelector('.chk-worker-select');
                if (amt > 0) {
                    if (chk) chk.checked = true;
                    tr.classList.remove('worker-skipped');
                    const badge = tr.querySelector('.badge-skipped');
                    if (badge) badge.remove();
                } else {
                    if (chk) chk.checked = false;
                    tr.classList.add('worker-skipped');
                }
            }
            updateLiveSummary();
        });
    });

    // When worker checkbox is toggled
    document.querySelectorAll('.chk-worker-select').forEach(chk => {
        chk.addEventListener('change', function() {
            const tr = this.closest('tr');
            const wId = this.value;
            const inp = document.getElementById('inp_pay_' + wId);
            if (!this.checked) {
                if (tr) tr.classList.add('worker-skipped');
            } else {
                if (tr) {
                    tr.classList.remove('worker-skipped');
                    const badge = tr.querySelector('.badge-skipped');
                    if (badge) badge.remove();
                }
                // If amount is 0, restore default or last paid amount!
                if (inp && (parseFloat(inp.value) || 0) <= 0) {
                    const defAmt = parseFloat(this.dataset.defaultAmount) || parseFloat(this.dataset.lastPaid) || parseFloat(this.dataset.fullBalance) || 0;
                    inp.value = defAmt.toFixed(2);
                }
            }
            updateLiveSummary();
        });
    });

    // -------------------------------------------------------------
    // SINGLE PAY CONFIRMATION & EXECUTION
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-tick-pay').forEach(btn => {
        btn.addEventListener('click', function() {
            const wId = this.dataset.workerId;
            const wName = this.dataset.workerName;
            const tr = document.getElementById('row_worker_' + wId);
            if (!tr) return;

            const wCode = tr.dataset.workerCode || '';
            const curBal = parseFloat(tr.dataset.currentBalance) || 0;
            const payInput = document.getElementById('inp_pay_' + wId);
            const noteInput = document.getElementById('inp_note_' + wId);

            const payAmt = parseFloat(payInput ? payInput.value : 0) || 0;
            if (payAmt <= 0) {
                alert('براہ کرم ادائیگی کی درست رقم (0 سے زیادہ) درج کریں۔');
                if (payInput) payInput.focus();
                return;
            }

            const estimatedNewBal = curBal - payAmt;

            // Fill modal
            document.getElementById('m_worker_id').value = wId;
            document.getElementById('m_worker_name').textContent = wName;
            document.getElementById('m_worker_code').textContent = wCode;
            document.getElementById('m_current_balance').textContent = 'Rs. ' + curBal.toLocaleString('en-PK', {minimumFractionDigits: 2});
            document.getElementById('m_pay_amount').textContent = 'Rs. ' + payAmt.toLocaleString('en-PK', {minimumFractionDigits: 2});
            document.getElementById('m_raw_amount').value = payAmt;

            const newBalEl = document.getElementById('m_new_balance');
            if (estimatedNewBal < 0) {
                newBalEl.textContent = 'Rs. ' + Math.abs(estimatedNewBal).toLocaleString('en-PK', {minimumFractionDigits: 2}) + ' (Advance)';
                newBalEl.className = 'text-warning-emphasis fw-bold';
            } else {
                newBalEl.textContent = 'Rs. ' + estimatedNewBal.toLocaleString('en-PK', {minimumFractionDigits: 2});
                newBalEl.className = 'text-secondary fw-bold';
            }

            const warnEl = document.getElementById('m_advance_warning');
            if (estimatedNewBal < 0) {
                warnEl.classList.remove('d-none');
            } else {
                warnEl.classList.add('d-none');
            }

            // Sync global date, method, account selectors to modal
            const globalDate = document.getElementById('global_payment_date');
            const mDate = document.getElementById('m_payment_date');
            if (globalDate && globalDate.value && mDate) {
                mDate.value = globalDate.value;
                const mParts = mDate.value.split('-');
                if (mParts.length === 3) {
                    const md = new Date(parseInt(mParts[0]), parseInt(mParts[1]) - 1, parseInt(mParts[2]));
                    const mLbl = document.getElementById('m_lbl_day_name');
                    if (mLbl) mLbl.textContent = dayNamesMap[md.getDay()] || '';
                }
            }

            const globalAcc = document.getElementById('global_account_id');
            const globalMeth = document.getElementById('global_payment_method');
            const mAcc = document.getElementById('m_account_id');
            const mMeth = document.getElementById('m_payment_method');
            if (globalAcc && globalAcc.value && mAcc) {
                mAcc.value = globalAcc.value;
                mAcc.dispatchEvent(new Event('change'));
            }
            if (globalMeth && globalMeth.value && mMeth) {
                mMeth.value = globalMeth.value;
                mMeth.dispatchEvent(new Event('change'));
            }
            if (noteInput && noteInput.value) {
                document.getElementById('m_notes').value = noteInput.value;
            }

            if (modalConfirm) modalConfirm.show();
        });
    });

    const btnConfirmDisburse = document.getElementById('btn_confirm_disburse');
    if (btnConfirmDisburse) {
        btnConfirmDisburse.addEventListener('click', function() {
            const wId = document.getElementById('m_worker_id').value;
            const amount = parseFloat(document.getElementById('m_raw_amount').value) || 0;
            const pDate = document.getElementById('m_payment_date').value;
            const pMethod = document.getElementById('m_payment_method').value;
            const accId = document.getElementById('m_account_id').value;
            const notes = document.getElementById('m_notes').value;

            if (!wId || amount <= 0) {
                alert('Invalid payment data.');
                return;
            }

            btnConfirmDisburse.disabled = true;
            btnConfirmDisburse.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

            const formData = new FormData();
            formData.append('action', 'pay_single');
            formData.append('worker_id', wId);
            formData.append('amount', amount);
            formData.append('payment_date', pDate);
            formData.append('payment_method', pMethod);
            formData.append('account_id', accId);
            formData.append('notes', notes);
            formData.append('period_from', periodFrom);
            formData.append('period_to', periodTo);

            fetch(BASE_URL + 'modules/payments/weekly_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btnConfirmDisburse.disabled = false;
                btnConfirmDisburse.innerHTML = '<i class="bi bi-check-lg me-1"></i> Disburse & Save (ادائیگی محفوظ کریں)';

                if (data.status === 'success') {
                    if (modalConfirm) modalConfirm.hide();
                    showToast(data.message, 'success');

                    // Update Row in Real-Time
                    const tr = document.getElementById('row_worker_' + wId);
                    if (tr) {
                        tr.classList.add('table-success');
                        tr.dataset.currentBalance = data.new_balance;

                        // Update Net Balance Column
                        const netBalCell = tr.querySelector('.lbl-net-balance');
                        if (netBalCell) {
                            if (data.new_balance > 0) {
                                netBalCell.textContent = 'Rs. ' + parseFloat(data.new_balance).toLocaleString('en-PK', {minimumFractionDigits: 2});
                                netBalCell.className = 'fw-bold text-danger fs-6 lbl-net-balance';
                            } else if (data.new_balance < 0) {
                                netBalCell.textContent = 'Rs. ' + Math.abs(data.new_balance).toLocaleString('en-PK', {minimumFractionDigits: 2});
                                netBalCell.className = 'fw-bold text-warning-emphasis fs-6 lbl-net-balance';
                            } else {
                                netBalCell.textContent = 'Rs. 0.00';
                                netBalCell.className = 'fw-semibold text-muted lbl-net-balance';
                            }
                        }

                        // Update Pay Input to 0 or remaining
                        const payInp = document.getElementById('inp_pay_' + wId);
                        if (payInp) {
                            payInp.value = data.new_balance > 0 ? parseFloat(data.new_balance).toFixed(2) : '0.00';
                        }

                        // Update Action Button
                        const actWrap = tr.querySelector('.action-wrapper');
                        if (actWrap) {
                            actWrap.innerHTML = `
                                <span class="badge bg-success px-2 py-1 small">
                                    <i class="bi bi-check-circle-fill me-1"></i> Paid (Rs. ${parseFloat(data.amount).toLocaleString('en-PK')})
                                </span>
                                <a href="${data.receipt_url}" target="_blank" class="btn btn-xs btn-outline-primary ms-1" title="Print Payment Receipt">
                                    <i class="bi bi-printer"></i>
                                </a>
                            `;
                        }

                        // Uncheck checkbox
                        const chk = tr.querySelector('.chk-worker-select');
                        if (chk) chk.checked = false;
                    }
                    updateLiveSummary();
                } else {
                    alert('Error: ' + (data.message || 'Payment failed'));
                }
            })
            .catch(err => {
                btnConfirmDisburse.disabled = false;
                btnConfirmDisburse.innerHTML = '<i class="bi bi-check-lg me-1"></i> Disburse & Save (ادائیگی محفوظ کریں)';
                alert('Connection error: ' + err.message);
            });
        });
    }

    // -------------------------------------------------------------
    // LOT BREAKDOWN INSPECTOR MODAL
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-inspect-lots').forEach(btn => {
        btn.addEventListener('click', function() {
            const wId = this.dataset.workerId;
            const wName = this.dataset.workerName;

            document.getElementById('lb_worker_name').textContent = wName;
            const tbody = document.getElementById('lb_table_body');
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Fetching lot details...</td></tr>';

            if (modalLot) modalLot.show();

            fetch(`${BASE_URL}modules/payments/weekly_ajax.php?action=get_worker_lots&worker_id=${wId}&date_from=${encodeURIComponent(periodFrom)}&date_to=${encodeURIComponent(periodTo)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('lb_total_labour').textContent = 'Rs. ' + data.total_labour_formatted;
                        if (!data.entries || data.entries.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3 text-muted">No production lot entries found in this period.</td></tr>';
                            return;
                        }
                        let rows = '';
                        data.entries.forEach(e => {
                            rows += `<tr>
                                <td>${e.entry_date}</td>
                                <td class="fw-bold font-monospace">Lot #${e.lot_number}</td>
                                <td>${e.product_name}</td>
                                <td><span class="badge bg-primary-subtle text-primary">${e.operation_name}</span></td>
                                <td class="text-center fw-bold">${e.good_qty}</td>
                                <td class="text-end">Rs. ${parseFloat(e.rate).toFixed(2)}</td>
                                <td class="text-end fw-bold text-danger">Rs. ${parseFloat(e.labour_amount).toFixed(2)}</td>
                            </tr>`;
                        });
                        tbody.innerHTML = rows;
                    } else {
                        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-3 text-danger">${data.message}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="7" class="text-center py-3 text-danger">Failed to load: ${err.message}</td></tr>`;
                });
        });
    });

    // -------------------------------------------------------------
    // BULK DISBURSEMENT MODAL & EXECUTION
    // -------------------------------------------------------------
    const btnBulkPayTrigger = document.getElementById('btn_bulk_pay');
    if (btnBulkPayTrigger) {
        btnBulkPayTrigger.addEventListener('click', function() {
            const checkedBoxes = Array.from(document.querySelectorAll('.chk-worker-select:checked'));
            if (checkedBoxes.length === 0) {
                alert('براہ کرم فہرست سے کم از کم ایک ورکر منتخب کریں۔');
                return;
            }

            let totalAmt = 0.00;
            let validCount = 0;

            checkedBoxes.forEach(chk => {
                const tr = chk.closest('tr');
                if (tr && tr.style.display !== 'none') {
                    const wId = chk.value;
                    const payInp = document.getElementById('inp_pay_' + wId);
                    const amt = parseFloat(payInp ? payInp.value : 0) || 0;
                    if (amt > 0) {
                        totalAmt += amt;
                        validCount++;
                    }
                }
            });

            if (validCount === 0 || totalAmt <= 0) {
                alert('منتخب ورکرز کی ادائیگی کی رقم 0 سے زیادہ ہونی چاہیے۔');
                return;
            }

            document.getElementById('bulk_count_label').textContent = validCount;
            document.getElementById('bulk_amount_label').textContent = 'Rs. ' + totalAmt.toLocaleString('en-PK', {minimumFractionDigits: 2});

            // Sync global selectors
            const globalDate = document.getElementById('global_payment_date');
            const bDate = document.getElementById('bulk_payment_date');
            if (globalDate && globalDate.value && bDate) {
                bDate.value = globalDate.value;
            }

            const globalAcc = document.getElementById('global_account_id');
            const globalMeth = document.getElementById('global_payment_method');
            const bAcc = document.getElementById('bulk_account_id');
            const bMeth = document.getElementById('bulk_payment_method');
            if (globalAcc && globalAcc.value && bAcc) {
                bAcc.value = globalAcc.value;
                bAcc.dispatchEvent(new Event('change'));
            }
            if (globalMeth && globalMeth.value && bMeth) {
                bMeth.value = globalMeth.value;
                bMeth.dispatchEvent(new Event('change'));
            }

            if (modalBulk) modalBulk.show();
        });
    }

    const btnExecBulk = document.getElementById('btn_execute_bulk_pay');
    if (btnExecBulk) {
        btnExecBulk.addEventListener('click', function() {
            const checkedBoxes = Array.from(document.querySelectorAll('.chk-worker-select:checked'));
            const pDate = document.getElementById('bulk_payment_date').value;
            const pMethod = document.getElementById('bulk_payment_method').value;
            const accId = document.getElementById('bulk_account_id').value;

            const payments = [];
            checkedBoxes.forEach(chk => {
                const tr = chk.closest('tr');
                if (tr && tr.style.display !== 'none') {
                    const wId = chk.value;
                    const payInp = document.getElementById('inp_pay_' + wId);
                    const noteInp = document.getElementById('inp_note_' + wId);
                    const amt = parseFloat(payInp ? payInp.value : 0) || 0;
                    if (amt > 0) {
                        payments.push({
                            worker_id: wId,
                            amount: amt,
                            notes: noteInp ? noteInp.value : ''
                        });
                    }
                }
            });

            if (payments.length === 0) {
                alert('No valid payments to process.');
                return;
            }

            btnExecBulk.disabled = true;
            btnExecBulk.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Disbursing...';

            const formData = new FormData();
            formData.append('action', 'pay_bulk');
            formData.append('payment_date', pDate);
            formData.append('payment_method', pMethod);
            formData.append('account_id', accId);
            formData.append('period_from', periodFrom);
            formData.append('period_to', periodTo);

            payments.forEach((p, idx) => {
                formData.append(`payments[${idx}][worker_id]`, p.worker_id);
                formData.append(`payments[${idx}][amount]`, p.amount);
                formData.append(`payments[${idx}][notes]`, p.notes);
            });

            fetch(BASE_URL + 'modules/payments/weekly_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btnExecBulk.disabled = false;
                btnExecBulk.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Disburse All (سب کو ادا کریں)';

                if (data.status === 'success') {
                    if (modalBulk) modalBulk.hide();
                    showToast(data.message, 'success');

                    // Update all paid rows
                    if (data.results && Array.isArray(data.results)) {
                        data.results.forEach(res => {
                            const tr = document.getElementById('row_worker_' + res.worker_id);
                            if (tr) {
                                tr.classList.add('table-success');
                                tr.dataset.currentBalance = res.new_balance;

                                const netBalCell = tr.querySelector('.lbl-net-balance');
                                if (netBalCell) {
                                    if (res.new_balance > 0) {
                                        netBalCell.textContent = 'Rs. ' + parseFloat(res.new_balance).toLocaleString('en-PK', {minimumFractionDigits: 2});
                                    } else {
                                        netBalCell.textContent = 'Rs. 0.00';
                                        netBalCell.className = 'fw-semibold text-muted lbl-net-balance';
                                    }
                                }

                                const payInp = document.getElementById('inp_pay_' + res.worker_id);
                                if (payInp) {
                                    payInp.value = res.new_balance > 0 ? parseFloat(res.new_balance).toFixed(2) : '0.00';
                                }

                                const actWrap = tr.querySelector('.action-wrapper');
                                if (actWrap) {
                                    actWrap.innerHTML = `
                                        <span class="badge bg-success px-2 py-1 small">
                                            <i class="bi bi-check-circle-fill me-1"></i> Paid
                                        </span>
                                        <a href="${BASE_URL}modules/payments/receipt.php?id=${res.payment_id}" target="_blank" class="btn btn-xs btn-outline-primary ms-1">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    `;
                                }

                                const chk = tr.querySelector('.chk-worker-select');
                                if (chk) chk.checked = false;
                            }
                        });
                    }
                    updateLiveSummary();
                } else {
                    alert('Error: ' + (data.message || 'Bulk disbursement failed'));
                }
            })
            .catch(err => {
                btnExecBulk.disabled = false;
                btnExecBulk.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Disburse All (سب کو ادا کریں)';
                alert('Connection error: ' + err.message);
            });
        });
    }
});
</script>

<style>
#tblWeeklyWorkers {
    min-width: 1100px;
}
#tblWeeklyWorkers .inp-pay-now {
    min-width: 95px;
}
#tblWeeklyWorkers .inp-worker-note {
    min-width: 140px;
}
.worker-row {
    transition: background-color 0.2s ease, opacity 0.2s ease;
}
.worker-skipped {
    background-color: #fcfcfd !important;
    opacity: 0.60;
}
.worker-skipped:hover {
    opacity: 0.95;
}
@media print {
    #tblWeeklyWorkers {
        min-width: 100% !important;
    }
    .no-print, .app-sidebar, .app-topbar, .app-header, .app-footer, .breadcrumb, .nav-pills {
        display: none !important;
    }
    body, .app-wrapper, .app-main, .app-content {
        margin: 0 !important;
        padding: 0 !important;
        height: auto !important;
        overflow: visible !important;
        background: #ffffff !important;
        width: 100% !important;
    }
    .table-responsive {
        overflow: visible !important;
    }
    table {
        font-size: 11px !important;
    }
    .avatar-circle {
        display: none !important;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
