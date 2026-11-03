<?php
/**
 * Garment Manufacturing ERP - Worker Registration
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Register Worker';
$activeNav = 'workers';
$db = get_db();

$suggestedCode = generate_doc_number('WRK-', 'workers', 'code');
$departments = $db->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$operations = $db->query("SELECT * FROM operations WHERE is_active = 1 ORDER BY sequence ASC")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $cnic = trim($_POST['cnic'] ?? '');
    $workerType = trim($_POST['worker_type'] ?? 'Piece Rate');
    $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $defaultRate = (float)($_POST['default_rate'] ?? 0.00);
    $ratePerMinute = (float)($_POST['rate_per_minute'] ?? 2.50);
    if ($ratePerMinute <= 0) $ratePerMinute = 2.50; // default benchmark
    $joiningDate = !empty($_POST['joining_date']) ? trim($_POST['joining_date']) : date('Y-m-d');
    $openingBalance = (float)($_POST['opening_balance'] ?? 0.00); // positive means factory owes worker
    $notes = trim($_POST['notes'] ?? '');
    $assignedOps = $_POST['operations'] ?? [];

    if (empty($code) || empty($name)) {
        $error = 'Worker code and name are required.';
    } else {
        $stmt = $db->prepare("SELECT id FROM workers WHERE code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            $error = "Worker code '$code' already exists. Choose a unique code.";
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO workers 
                    (code, name, phone, address, cnic, worker_type, department_id, default_rate, rate_per_minute, joining_date, opening_balance, notes, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([
                    $code,
                    $name,
                    $phone,
                    $address,
                    $cnic,
                    $workerType,
                    $deptId,
                    $defaultRate,
                    $ratePerMinute,
                    $joiningDate,
                    $openingBalance,
                    $notes
                ]);
                $workerId = (int)$db->lastInsertId();

                // Assign operations
                if (!empty($assignedOps)) {
                    $opStmt = $db->prepare("INSERT INTO worker_operations (worker_id, operation_id) VALUES (?, ?)");
                    foreach ($assignedOps as $opId) {
                        $opStmt->execute([$workerId, (int)$opId]);
                    }
                }

                // If opening balance > 0, post to ledger
                if ($openingBalance != 0) {
                    $isPayable = ($openingBalance > 0);
                    $credit = $isPayable ? $openingBalance : 0.00;
                    $debit = !$isPayable ? abs($openingBalance) : 0.00;

                    $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
                        (worker_id, transaction_date, transaction_type, reference_type, debit, credit, running_balance, description, created_by) 
                        VALUES (?, ?, 'Opening Balance', 'OPENING', ?, ?, ?, 'Initial opening balance', ?)");
                    $ledgStmt->execute([$workerId, $joiningDate, $debit, $credit, $openingBalance, $_SESSION['user_id'] ?? null]);
                }

                log_audit('CREATE_WORKER', 'workers', $workerId, null, ['code' => $code, 'name' => $name]);
                $db->commit();
                redirect("modules/workers/view.php?id=$workerId", "Worker '$name' registered successfully!");
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to register worker: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Register New Worker / Artisan</h4>
                <p class="text-muted small mb-0">Record personal details, skill operations, default piece rates and opening balance</p>
            </div>
            <a href="<?= BASE_URL ?>modules/workers/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Workers List
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="card-modern" style="max-width: 900px;">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Artisan Profile Information</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Worker Code *</label>
                            <input type="text" name="code" class="form-control fw-bold text-primary" value="<?= clean($_POST['code'] ?? $suggestedCode) ?>" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Latif Stitcher" value="<?= clean($_POST['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="0300-1234567" value="<?= clean($_POST['phone'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Worker Type</label>
                            <select name="worker_type" class="form-select">
                                <option value="Piece Rate">Piece Rate (Per Piece)</option>
                                <option value="Daily Wage">Daily Wage</option>
                                <option value="Operation Based">Operation Based</option>
                                <option value="Fixed Salary">Fixed Salary</option>
                                <option value="Contractor">Contractor / External</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Primary Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= clean($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Minute Rate (روپے فی منٹ) *</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" name="rate_per_minute" class="form-control fw-bold text-success" step="0.05" min="0" value="<?= clean($_POST['rate_per_minute'] ?? '2.50') ?>" required>
                                <span class="input-group-text">/min</span>
                            </div>
                            <div class="form-text small" style="font-size: 11px;">طے شدہ ریٹ (مثلاً 2.50 روپے فی منٹ)۔</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">CNIC Number</label>
                            <input type="text" name="cnic" class="form-control" placeholder="35201-XXXXXXX-X" value="<?= clean($_POST['cnic'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Joining Date</label>
                            <input type="date" name="joining_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Opening Balance (Rs.)</label>
                            <input type="number" name="opening_balance" class="form-control" step="1" value="0" placeholder="Positive = Factory Owes Worker">
                            <div class="form-text" style="font-size: 11px;">+ Amount = Payable to worker | - Amount = Advance</div>
                        </div>

                        <div class="col-12 col-md-8">
                            <label class="form-label">Residential Address</label>
                            <input type="text" name="address" class="form-control" placeholder="Factory quarters or local address" value="<?= clean($_POST['address'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Skill specialization, references">
                        </div>

                        <!-- Assigned Operations Checklist -->
                        <div class="col-12">
                            <label class="form-label fw-bold text-dark">Authorized / Skilled Operations</label>
                            <div class="row g-2 p-3 bg-light rounded-3 border">
                                <?php foreach ($operations as $op): ?>
                                    <div class="col-6 col-sm-4 col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="operations[]" value="<?= $op['id'] ?>" id="wrk_op_<?= $op['id'] ?>">
                                            <label class="form-check-label small fw-semibold" for="wrk_op_<?= $op['id'] ?>">
                                                <?= clean($op['name']) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-3 border-top">
                            <a href="<?= BASE_URL ?>modules/workers/index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary px-5 fw-bold">Register Worker</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
