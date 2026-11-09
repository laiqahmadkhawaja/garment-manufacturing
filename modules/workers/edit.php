<?php
/**
 * Garment Manufacturing ERP - Edit Worker Profile
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$workerId = (int)($_GET['id'] ?? $_GET['worker_id'] ?? 0);
$db = get_db();

// Worker details
$stmt = $db->prepare("SELECT * FROM workers WHERE id = ?");
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker) {
    redirect('modules/workers/index.php', 'Worker not found.', 'danger');
}

$pageTitle = 'Edit Worker: ' . clean($worker['name']);
$activeNav = 'workers';

$departments = $db->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$operations = $db->query("SELECT * FROM operations WHERE is_active = 1 ORDER BY sequence ASC")->fetchAll();

// Currently assigned operations
$curOpsStmt = $db->prepare("SELECT operation_id FROM worker_operations WHERE worker_id = ?");
$curOpsStmt->execute([$workerId]);
$currentOpIds = $curOpsStmt->fetchAll(PDO::FETCH_COLUMN);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $cnic = trim($_POST['cnic'] ?? '');
    $workerType = trim($_POST['worker_type'] ?? 'Piece Rate');
    $rateBasis = trim($_POST['rate_basis'] ?? 'whole_sweater');
    if ($rateBasis !== 'element_wise') $rateBasis = 'whole_sweater';
    $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $defaultRate = (float)($_POST['default_rate'] ?? 0.00);
    $ratePerMinute = (float)($_POST['rate_per_minute'] ?? 2.50);
    if ($ratePerMinute <= 0) $ratePerMinute = 2.50;
    $joiningDate = !empty($_POST['joining_date']) ? trim($_POST['joining_date']) : null;
    $notes = trim($_POST['notes'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $assignedOps = $_POST['operations'] ?? [];

    if (empty($code) || empty($name)) {
        $error = 'Worker code and name are required.';
    } else {
        // Check uniqueness of code excluding self
        $chk = $db->prepare("SELECT id FROM workers WHERE code = ? AND id != ?");
        $chk->execute([$code, $workerId]);
        if ($chk->fetch()) {
            $error = "Worker code '$code' is already in use by another worker.";
        } else {
            $db->beginTransaction();
            try {
                $up = $db->prepare("UPDATE workers SET 
                    code = ?, 
                    name = ?, 
                    phone = ?, 
                    address = ?, 
                    cnic = ?, 
                    worker_type = ?, 
                    rate_basis = ?,
                    department_id = ?, 
                    default_rate = ?,
                    rate_per_minute = ?, 
                    joining_date = ?, 
                    notes = ?, 
                    is_active = ? 
                    WHERE id = ?");
                $up->execute([
                    $code,
                    $name,
                    $phone,
                    $address,
                    $cnic,
                    $workerType,
                    $rateBasis,
                    $deptId,
                    $defaultRate,
                    $ratePerMinute,
                    $joiningDate,
                    $notes,
                    $isActive,
                    $workerId
                ]);

                // Sync worker_operations
                $db->prepare("DELETE FROM worker_operations WHERE worker_id = ?")->execute([$workerId]);
                if (!empty($assignedOps)) {
                    $insOp = $db->prepare("INSERT INTO worker_operations (worker_id, operation_id) VALUES (?, ?)");
                    foreach ($assignedOps as $opId) {
                        $insOp->execute([$workerId, (int)$opId]);
                    }
                }

                log_audit('UPDATE_WORKER', 'workers', $workerId, $worker, [
                    'code' => $code,
                    'name' => $name,
                    'rate_per_minute' => $ratePerMinute,
                    'department_id' => $deptId
                ]);

                $db->commit();
                redirect("modules/workers/view.php?id=$workerId", "Worker profile for '$name' updated successfully!");
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to update worker: ' . $e->getMessage();
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
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/workers/index.php">Artisans</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $worker['id'] ?>"><?= clean($worker['name']) ?></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit Profile</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-0 text-dark">Edit Worker Profile</h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $worker['id'] ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Profile
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= clean($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card-modern shadow-sm border-0">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-pencil-square text-primary me-2"></i> Update Artisan Information
                </h6>
                <span class="badge bg-light text-muted border">ID: #<?= $worker['id'] ?></span>
            </div>
            <div class="card-modern-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold small text-muted">Worker Code *</label>
                            <input type="text" name="code" class="form-control form-control-sm font-monospace fw-bold" required value="<?= clean($_POST['code'] ?? $worker['code']) ?>">
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold small text-muted">Full Name *</label>
                            <input type="text" name="name" class="form-control form-control-sm fw-bold" required value="<?= clean($_POST['name'] ?? $worker['name']) ?>">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-dark small">Wage / Worker Type *</label>
                            <?php 
                            $types = [
                                'Piece Rate' => '👕 Piece Rate (پر پیس ریٹ - کٹنگ، ٹیلرنگ، فنشنگ)',
                                'Minute Rate' => '⏱️ Minute Rate (منٹ ریٹ - مشین آپریٹر ٹائم ریٹ)',
                                'Daily Wage' => '📅 Daily Wage (یومیہ اجرت - فکسڈ فی دن)',
                                'Monthly Salary' => '💼 Monthly Salary (ماہانہ فکسڈ تنخواہ)',
                                'Contractor' => '🤝 Contractor (ٹھیکیدار / کنٹریکٹر)'
                            ];
                            $selType = $_POST['worker_type'] ?? $worker['worker_type'];
                            ?>
                            <select name="worker_type" id="wrk_type" class="form-select form-select-sm fw-semibold border-primary">
                                <?php foreach ($types as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= $selType === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Department</label>
                            <select name="department_id" id="wrk_dept" class="form-select form-select-sm">
                                <option value="">-- General Factory --</option>
                                <?php 
                                $selDept = $_POST['department_id'] ?? $worker['department_id'];
                                foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" data-name="<?= strtolower($d['name']) ?>" <?= (int)$selDept === (int)$d['id'] ? 'selected' : '' ?>><?= clean($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold small text-dark">Rate & Work Basis *</label>
                            <?php $selBasis = $_POST['rate_basis'] ?? ($worker['rate_basis'] ?? 'whole_sweater'); ?>
                            <select name="rate_basis" id="wrk_rate_basis" class="form-select form-select-sm fw-semibold border-primary">
                                <option value="whole_sweater" <?= $selBasis === 'whole_sweater' ? 'selected' : '' ?>>👕 Whole Sweaters (مکمل سویٹر - ٹیلرنگ، بٹن، کاج، پریس)</option>
                                <option value="element_wise" <?= $selBasis === 'element_wise' ? 'selected' : '' ?>>🧩 Garment Elements (اعضاء وار بریک ڈاؤن - آپریٹر، کٹنگ)</option>
                            </select>
                            <div class="form-text small" style="font-size: 11px;">پروڈکشن انٹری میں ورک فلو اور ریٹ کا تعین اس بنیاد پر ہوگا۔</div>
                        </div>

                        <!-- Piece Rate Input (روپے فی پیس) -->
                        <div class="col-12 col-md-3" id="grp_piece_rate">
                            <label class="form-label fw-bold small text-primary" id="lbl_piece_rate">Default Piece Rate (ریٹ فی پیس) *</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-primary text-white fw-bold">Rs</span>
                                <input type="number" name="default_rate" id="wrk_piece_rate" class="form-control form-control-sm fw-bold text-primary text-center" step="0.25" min="0" value="<?= clean($_POST['default_rate'] ?? $worker['default_rate']) ?>" placeholder="0.00">
                                <span class="input-group-text" id="unit_piece_rate">/pc</span>
                            </div>
                            <div class="form-text small" id="hint_piece_rate" style="font-size: 11px;">مکمل سویٹر یا کٹنگ فی پیس طے شدہ اجرت۔</div>
                        </div>

                        <!-- Minute Rate Input (روپے فی منٹ) -->
                        <div class="col-12 col-md-3" id="grp_min_rate">
                            <label class="form-label fw-semibold small text-success">Minute Rate (روپے فی منٹ) *</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-success text-white fw-bold">Rs</span>
                                <input type="number" name="rate_per_minute" id="wrk_min_rate" class="form-control form-control-sm fw-bold text-success text-center" step="0.05" min="0.1" required value="<?= clean($_POST['rate_per_minute'] ?? $worker['rate_per_minute']) ?>">
                                <span class="input-group-text">/m</span>
                            </div>
                            <div class="form-text small text-muted" style="font-size: 11px;">مشین نٹنگ آپریٹر اور SAM وقت کا حساب: Rate = SAM × Rate/min</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Joining Date</label>
                            <input type="date" name="joining_date" class="form-control form-control-sm" value="<?= clean($_POST['joining_date'] ?? $worker['joining_date']) ?>">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Phone Number</label>
                            <input type="text" name="phone" class="form-control form-control-sm" value="<?= clean($_POST['phone'] ?? $worker['phone']) ?>" placeholder="e.g. 0300-1234567">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold small text-muted">CNIC (National ID)</label>
                            <input type="text" name="cnic" class="form-control form-control-sm" value="<?= clean($_POST['cnic'] ?? $worker['cnic']) ?>" placeholder="35201-xxxxxxx-x">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold small text-muted">Account Status</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= ($worker['is_active'] ? 'checked' : '') ?>>
                                <label class="form-check-label fw-semibold text-dark small" for="is_active">Active Worker (کام پر موجود)</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Residential Address</label>
                        <input type="text" name="address" class="form-control form-control-sm" value="<?= clean($_POST['address'] ?? $worker['address']) ?>" placeholder="Worker residential address or colony">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted mb-1">Assigned Operations (ورکر کن مراحل پر کام کرتا ہے)</label>
                        <div class="border rounded-3 p-3 bg-light">
                            <div class="row g-2">
                                <?php foreach ($operations as $op): 
                                    $isAssigned = in_array($op['id'], $currentOpIds);
                                ?>
                                    <div class="col-12 col-sm-6 col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="operations[]" value="<?= $op['id'] ?>" id="op_<?= $op['id'] ?>" <?= $isAssigned ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold text-dark" for="op_<?= $op['id'] ?>">
                                                <?= clean($op['sequence']) ?>. <?= clean($op['name']) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small text-muted">Notes & Special Skills</label>
                        <textarea name="notes" rows="2" class="form-control form-control-sm" placeholder="Any special skills, machines handled, reference notes..."><?= clean($_POST['notes'] ?? $worker['notes']) ?></textarea>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $worker['id'] ?>" class="btn btn-sm btn-outline-secondary px-3">Cancel</a>
                        <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold shadow-sm">
                            <i class="bi bi-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const deptSelect = document.getElementById('wrk_dept');
    const rateBasisSelect = document.getElementById('wrk_rate_basis');
    const typeSelect = document.getElementById('wrk_type');
    const lblPieceRate = document.getElementById('lbl_piece_rate');
    const unitPieceRate = document.getElementById('unit_piece_rate');
    const hintPieceRate = document.getElementById('hint_piece_rate');

    function syncWageTypeUI() {
        if (!typeSelect) return;
        const val = typeSelect.value;
        const grpMin = document.getElementById('grp_min_rate');
        const grpPiece = document.getElementById('grp_piece_rate');

        if (val === 'Piece Rate') {
            if (lblPieceRate) lblPieceRate.textContent = 'Default Piece Rate (ریٹ فی پیس) *';
            if (unitPieceRate) unitPieceRate.textContent = '/pc';
            if (hintPieceRate) hintPieceRate.textContent = 'مکمل سویٹر یا کٹنگ فی پیس طے شدہ اجرت۔';
            if (grpPiece) {
                grpPiece.classList.remove('opacity-50');
                grpPiece.style.borderLeft = '3px solid #0d6efd';
                grpPiece.style.paddingLeft = '8px';
            }
            if (grpMin) {
                grpMin.classList.add('opacity-75');
                grpMin.style.borderLeft = 'none';
                grpMin.style.paddingLeft = '0';
            }
            if (rateBasisSelect && !rateBasisSelect.value) rateBasisSelect.value = 'whole_sweater';
        } else if (val === 'Minute Rate') {
            if (lblPieceRate) lblPieceRate.textContent = 'Standard Piece Rate (اختیاری / ریفرنس)';
            if (unitPieceRate) unitPieceRate.textContent = '/pc';
            if (hintPieceRate) hintPieceRate.textContent = 'کاریگر کا ریٹ منٹ ریٹ اور SAM وقت سے خودکار طے ہوگا۔';
            if (grpMin) {
                grpMin.classList.remove('opacity-75');
                grpMin.style.borderLeft = '3px solid #198754';
                grpMin.style.paddingLeft = '8px';
            }
            if (grpPiece) {
                grpPiece.classList.add('opacity-50');
                grpPiece.style.borderLeft = 'none';
                grpPiece.style.paddingLeft = '0';
            }
            if (rateBasisSelect) rateBasisSelect.value = 'element_wise';
        } else if (val === 'Daily Wage') {
            if (lblPieceRate) lblPieceRate.textContent = 'Daily Wage Rate (یومیہ اجرت) *';
            if (unitPieceRate) unitPieceRate.textContent = '/day';
            if (hintPieceRate) hintPieceRate.textContent = 'ایک دن (شفٹ) کی فکسڈ اجرت۔';
            if (grpPiece) grpPiece.classList.remove('opacity-50');
            if (grpMin) grpMin.classList.add('opacity-75');
        } else if (val === 'Monthly Salary') {
            if (lblPieceRate) lblPieceRate.textContent = 'Monthly Fixed Salary (ماہانہ تنخواہ) *';
            if (unitPieceRate) unitPieceRate.textContent = '/month';
            if (hintPieceRate) hintPieceRate.textContent = 'ماہانہ فکسڈ بنیادی تنخواہ۔';
            if (grpPiece) grpPiece.classList.remove('opacity-50');
            if (grpMin) grpMin.classList.add('opacity-75');
        } else if (val === 'Contractor') {
            if (lblPieceRate) lblPieceRate.textContent = 'Contract Piece Rate (کنٹریکٹ ریٹ) *';
            if (unitPieceRate) unitPieceRate.textContent = '/pc';
            if (hintPieceRate) hintPieceRate.textContent = 'ٹھیکیدار کا طے شدہ ریٹ فی پیس۔';
            if (grpPiece) grpPiece.classList.remove('opacity-50');
            if (grpMin) grpMin.classList.add('opacity-75');
        }
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncWageTypeUI);
        syncWageTypeUI();
    }

    if (deptSelect && rateBasisSelect) {
        deptSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const deptName = opt ? (opt.dataset.name || opt.textContent.toLowerCase()) : '';
            if (deptName.includes('operat') || deptName.includes('cutt') || deptName.includes('knitt')) {
                rateBasisSelect.value = 'element_wise';
                if (typeSelect && (deptName.includes('operat') || deptName.includes('knitt'))) {
                    typeSelect.value = 'Minute Rate';
                    syncWageTypeUI();
                } else if (typeSelect && deptName.includes('cutt')) {
                    typeSelect.value = 'Piece Rate';
                    syncWageTypeUI();
                }
            } else if (deptName) {
                rateBasisSelect.value = 'whole_sweater';
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
