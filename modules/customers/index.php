<?php
/**
 * Garment Manufacturing ERP - Customer Directory & Ledgers
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Customers & Parties';
$activeNav = 'customers';
$db = get_db();

// Handle New Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_customer') {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $openingBal = (float)($_POST['opening_balance'] ?? 0.00);

    if (empty($code) || empty($name)) {
        set_flash('danger', 'Customer code and name are required.');
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO customers (code, name, contact_person, phone, address, city, opening_balance, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$code, $name, $contact, $phone, $address, $city, $openingBal]);
            $custId = (int)$db->lastInsertId();

            if ($openingBal != 0) {
                $isDebit = ($openingBal > 0);
                $d = $isDebit ? $openingBal : 0.00;
                $c = !$isDebit ? abs($openingBal) : 0.00;

                $cLedg = $db->prepare("INSERT INTO customer_ledger (customer_id, transaction_date, transaction_type, reference_type, debit, credit, running_balance, description, created_by) VALUES (?, CURRENT_DATE(), 'Opening Balance', 'OPENING', ?, ?, ?, 'Initial opening balance', ?)");
                $cLedg->execute([$custId, $d, $c, $openingBal, $_SESSION['user_id'] ?? null]);
            }

            log_audit('CREATE_CUSTOMER', 'customers', $custId, null, ['code' => $code, 'name' => $name]);
            $db->commit();
            redirect('modules/customers/index.php', "Customer '$name' registered successfully!");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error registering customer: ' . $e->getMessage());
        }
    }
}

// Fetch customers with ledger totals
$sql = "SELECT c.*, 
               COALESCE((SELECT SUM(debit) FROM customer_ledger WHERE customer_id = c.id), 0) AS total_invoiced,
               COALESCE((SELECT SUM(credit) FROM customer_ledger WHERE customer_id = c.id), 0) AS total_paid,
               (SELECT COUNT(*) FROM dispatches WHERE customer_id = c.id) AS dispatch_count 
        FROM customers c 
        WHERE c.is_active = 1 
        ORDER BY c.name ASC";
$customers = $db->query($sql)->fetchAll();

// Overall Directory Totals
$grandInvoiced = 0;
$grandPaid = 0;
$grandReceivable = 0;
foreach ($customers as $c) {
    $inv = (float)$c['total_invoiced'];
    $pd = (float)$c['total_paid'];
    $grandInvoiced += $inv;
    $grandPaid += $pd;
    $grandReceivable += ($inv - $pd);
}

$suggestedCustCode = generate_doc_number('CUST-', 'customers', 'code');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Customer Parties & Accounts</h4>
                <p class="text-muted small mb-0">Manage boutique buyers, wholesale parties, dispatches and receivable balances</p>
            </div>
        </div>

        <!-- 4 Top KPI Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card-modern p-3 border rounded-3 text-center text-md-start">
                    <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Active Customers</span>
                    <div class="fs-4 fw-bold text-dark mt-1"><?= count($customers) ?> Parties</div>
                    <span class="text-muted small">Registered commercial buyers</span>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-modern p-3 border rounded-3 text-center text-md-start">
                    <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Total Dispatched (Billed)</span>
                    <div class="fs-4 fw-bold text-dark mt-1"><?= format_currency($grandInvoiced) ?></div>
                    <span class="text-muted small">Total receivables invoiced</span>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-modern p-3 border rounded-3 bg-success-subtle border-success-subtle text-center text-md-start">
                    <span class="text-success small text-uppercase fw-semibold" style="font-size: 0.72rem;">Payments Recovered</span>
                    <div class="fs-4 fw-bold text-success mt-1"><?= format_currency($grandPaid) ?></div>
                    <span class="text-success small">Total customer collections</span>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card-modern p-3 border rounded-3 <?= $grandReceivable > 0 ? 'bg-danger-subtle border-danger-subtle' : 'bg-light' ?> text-center text-md-start">
                    <span class="small text-uppercase fw-semibold <?= $grandReceivable > 0 ? 'text-danger' : 'text-muted' ?>" style="font-size: 0.72rem;">Total Outstanding</span>
                    <div class="fs-4 fw-bold mt-1 <?= $grandReceivable > 0 ? 'text-danger' : 'text-primary' ?>"><?= format_currency($grandReceivable) ?></div>
                    <span class="small <?= $grandReceivable > 0 ? 'text-danger' : 'text-muted' ?>">Total net balance due</span>
                </div>
            </div>
        </div>

        <!-- Add Customer Inline Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-person-plus me-1 text-primary"></i> Register New Customer / Party</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_customer">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-2">
                            <label class="form-label">Customer Code *</label>
                            <input type="text" name="code" class="form-control text-uppercase fw-bold" value="<?= clean($suggestedCustCode) ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Customer / Party Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Al-Jannat Boutiques" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control" placeholder="Owner / Manager Name">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="0300-1234567">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">City / Destination</label>
                            <input type="text" name="city" class="form-control" placeholder="Lahore, Karachi, Faisalabad">
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Outlet Address</label>
                            <input type="text" name="address" class="form-control" placeholder="Market shop address">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Opening Receivable (Rs.)</label>
                            <input type="number" name="opening_balance" class="form-control" step="1" value="0">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-primary px-5 fw-bold">Register Customer</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Customer Directory & Balances (<?= count($customers) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Party Name</th>
                                <th>City</th>
                                <th>Contact / Phone</th>
                                <th class="text-center">Total Dispatches</th>
                                <th class="text-end">Total Billed</th>
                                <th class="text-end">Payments Received</th>
                                <th class="text-end">Outstanding Balance</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No customers registered yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($customers as $c): 
                                    $invoiced = (float)$c['total_invoiced'];
                                    $paid = (float)$c['total_paid'];
                                    $net = $invoiced - $paid;
                                ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/customers/view.php?id=<?= $c['id'] ?>" class="fw-bold text-primary text-decoration-none fs-6 d-inline-block hover-underline">
                                                <?= clean($c['name']) ?>
                                            </a>
                                            <div class="text-muted small">Code: <?= clean($c['code']) ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= clean($c['city'] ?: 'Local') ?></span></td>
                                        <td>
                                            <div class="small fw-semibold"><?= clean($c['contact_person'] ?: '-') ?></div>
                                            <div class="text-muted small"><?= clean($c['phone'] ?: '-') ?></div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary-subtle text-primary fw-semibold"><?= $c['dispatch_count'] ?> shipments</span>
                                        </td>
                                        <td class="text-end fw-semibold text-dark"><?= format_currency($invoiced) ?></td>
                                        <td class="text-end fw-semibold text-success"><?= format_currency($paid) ?></td>
                                        <td class="text-end fw-bold fs-6 <?= $net > 0 ? 'text-primary' : 'text-muted' ?>">
                                            <?= format_currency($net) ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="<?= BASE_URL ?>modules/customers/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary py-1 px-2 fw-semibold" title="View Detailed Account Ledger">
                                                <i class="bi bi-journal-text me-1"></i> Ledger
                                            </a>
                                            <a href="<?= BASE_URL ?>modules/dispatch/create.php?customer_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Dispatch Goods">
                                                <i class="bi bi-truck me-1"></i> Dispatch
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
