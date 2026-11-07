<?php
/**
 * Garment Manufacturing ERP - Printable Customer Account Statement / Ledger
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$customerId = (int)($_GET['customer_id'] ?? $_GET['id'] ?? 0);
$db = get_db();

$allCustomers = $db->query("SELECT id, name, company_name, phone FROM customers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

if ($customerId <= 0 && !empty($allCustomers)) {
    $customerId = (int)$allCustomers[0]['id'];
}

$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer && !empty($allCustomers)) {
    $customerId = (int)$allCustomers[0]['id'];
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
}

if (!$customer) {
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Customer Not Found</title><link rel='stylesheet' href='" . BASE_URL . "assets/css/bootstrap.min.css'></head><body class='p-5'><div class='container' style='max-width: 600px;'><div class='card shadow-sm border-0'><div class='card-body p-4 text-center'><h4 class='fw-bold'>Customer Account Not Found</h4><p class='text-muted'>The requested customer account was not found.</p><a href='" . BASE_URL . "modules/customers/index.php' class='btn btn-primary fw-bold px-4'>Back to Customers</a></div></div></div></body></html>");
}

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

// Filter params
$filterSql = "WHERE customer_id = ?";
$filterParams = [$customerId];

if (!empty($dateFrom)) {
    $filterSql .= " AND transaction_date >= ?";
    $filterParams[] = $dateFrom;
}
if (!empty($dateTo)) {
    $filterSql .= " AND transaction_date <= ?";
    $filterParams[] = $dateTo;
}

$ledgSql = "SELECT * FROM customer_ledger $filterSql ORDER BY transaction_date ASC, id ASC";
$ledgStmt = $db->prepare($ledgSql);
$ledgStmt->execute($filterParams);
$transactions = $ledgStmt->fetchAll();

// Overall ledger totals
$totStmt = $db->prepare("SELECT 
    COALESCE(SUM(debit), 0) AS total_debit,
    COALESCE(SUM(credit), 0) AS total_credit
    FROM customer_ledger WHERE customer_id = ?");
$totStmt->execute([$customerId]);
$tot = $totStmt->fetch();
$netClosingBalance = (float)$tot['total_debit'] - (float)$tot['total_credit'];

$periodDebit = 0;
$periodCredit = 0;
foreach ($transactions as $t) {
    $periodDebit += (float)$t['debit'];
    $periodCredit += (float)$t['credit'];
}

$companyName = get_setting('company_name', 'Ali Garment Manufacturing Ltd.');
$companyAddress = get_setting('company_address', 'Industrial Area, Karachi / Faisalabad, Pakistan');
$companyPhone = get_setting('company_phone', '+92 300 1234567');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Statement - <?= clean($customer['name']) ?> (<?= clean($customer['code']) ?>)</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <style>
        body { background: #fff !important; color: #000; font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; }
        .statement-box { border: 2px solid #000; padding: 25px; }
        .table-bordered th, .table-bordered td { border: 1px solid #333 !important; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0 !important; }
            .statement-box { border: none !important; padding: 0 !important; }
        }
    </style>
</head>
<body class="p-4">

    <!-- Top Action Bar -->
    <div class="no-print d-flex justify-content-between align-items-center mb-4 p-3 bg-light border rounded">
        <div>
            <h5 class="fw-bold mb-0">Customer Account Statement</h5>
            <span class="text-muted small">Party: <strong><?= clean($customer['name']) ?></strong> (<?= clean($customer['code']) ?>)</span>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary fw-bold">
                <i class="bi bi-printer me-1"></i> Print Statement
            </button>
            <button onclick="closePrintView('view.php?id=<?= $customerId ?>')" class="btn btn-secondary">
                Close
            </button>
        </div>
    </div>

    <!-- Printable Statement Container -->
    <div class="statement-box">
        <!-- Header -->
        <div class="row align-items-start border-bottom pb-3 mb-3">
            <div class="col-7">
                <h3 class="fw-bold text-uppercase mb-1" style="letter-spacing: 0.5px;"><?= clean($companyName) ?></h3>
                <div class="text-muted small"><?= clean($companyAddress) ?> · Tel: <?= clean($companyPhone) ?></div>
                <div class="badge bg-dark text-white text-uppercase mt-2 px-3 py-1 fw-bold" style="font-size: 0.85rem;">Customer Ledger & Account Statement</div>
            </div>
            <div class="col-5 text-end">
                <table class="table table-sm table-borderless text-end mb-0" style="font-size: 0.85rem;">
                    <tr>
                        <td class="text-muted py-0">Statement Date:</td>
                        <td class="fw-bold py-0"><?= date('d M Y') ?></td>
                    </tr>
                    <?php if (!empty($dateFrom) || !empty($dateTo)): ?>
                        <tr>
                            <td class="text-muted py-0">Period:</td>
                            <td class="fw-bold py-0"><?= clean($dateFrom ?: 'Beginning') ?> to <?= clean($dateTo ?: 'Today') ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted py-0">Customer Code:</td>
                        <td class="fw-bold text-primary py-0"><?= clean($customer['code']) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Party Information -->
        <div class="row g-3 mb-4 p-3 bg-light border rounded">
            <div class="col-6">
                <div class="text-muted small text-uppercase fw-bold">Bill To / Party:</div>
                <div class="fs-5 fw-bold text-dark"><?= clean($customer['name']) ?></div>
                <div class="small text-muted mt-1">
                    <?= clean($customer['address'] ?: 'Commercial Outlet') ?>, <?= clean($customer['city'] ?: 'Local') ?>
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="text-muted small text-uppercase fw-bold">Contact & Status:</div>
                <div class="fw-semibold"><?= clean($customer['contact_person'] ?: '-') ?></div>
                <div class="small text-muted"><?= clean($customer['phone'] ?: '-') ?></div>
                <div class="mt-2">
                    <span class="text-muted small me-2">Net Receivable Balance:</span>
                    <strong class="fs-5 <?= $netClosingBalance > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= format_currency($netClosingBalance) ?>
                    </strong>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <table class="table table-bordered table-sm align-middle mb-4">
            <thead class="table-dark text-center" style="font-size: 0.82rem;">
                <tr>
                    <th style="width: 100px;">Date</th>
                    <th style="width: 120px;">Type</th>
                    <th style="width: 130px;">Ref Doc #</th>
                    <th class="text-start">Description / Particulars</th>
                    <th class="text-end" style="width: 120px;">Debit (Rs.)</th>
                    <th class="text-end" style="width: 120px;">Credit (Rs.)</th>
                    <th class="text-end" style="width: 130px;">Balance (Rs.)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No transactions recorded for this customer account.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $t): 
                        $d = (float)$t['debit'];
                        $c = (float)$t['credit'];
                        $rb = (float)$t['running_balance'];
                    ?>
                        <tr>
                            <td class="text-center"><?= format_date($t['transaction_date']) ?></td>
                            <td class="text-center fw-semibold"><?= clean($t['transaction_type']) ?></td>
                            <td class="text-center text-muted small"><?= clean($t['reference_type'] ?: '-') ?></td>
                            <td><?= clean($t['description']) ?></td>
                            <td class="text-end"><?= $d > 0 ? number_format($d, 2) : '—' ?></td>
                            <td class="text-end text-success"><?= $c > 0 ? number_format($c, 2) : '—' ?></td>
                            <td class="text-end fw-bold"><?= number_format($rb, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="4" class="text-end">Summary Period Totals:</td>
                    <td class="text-end"><?= number_format($periodDebit, 2) ?></td>
                    <td class="text-end text-success"><?= number_format($periodCredit, 2) ?></td>
                    <td class="text-end text-primary"><?= number_format($netClosingBalance, 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Summary Note -->
        <div class="row align-items-center mb-5">
            <div class="col-8">
                <div class="p-2 border rounded bg-light small">
                    <strong>Note:</strong> All invoices and payments are subject to periodic audit reconciliation. Please report any discrepancies within 7 days of statement generation.
                </div>
            </div>
            <div class="col-4 text-end">
                <div class="border p-2 rounded bg-light">
                    <span class="text-muted small">Total Outstanding Balance:</span>
                    <div class="fs-4 fw-bold text-dark"><?= format_currency($netClosingBalance) ?></div>
                </div>
            </div>
        </div>

        <!-- Signature Blocks -->
        <div class="row pt-5 mt-4 text-center">
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Prepared By / Accountant</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Commercial Sales Manager</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Party / Buyer Acknowledgment</div>
            </div>
        </div>
    </div>

    <script>
        function closePrintView(fallbackUrl) {
            window.close();
            setTimeout(function() {
                window.location.href = fallbackUrl;
            }, 150);
        }
    </script>
</body>
</html>
