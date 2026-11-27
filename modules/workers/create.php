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
$operations = $db->query("SELECT o.*, 
                                 COALESCE(o.department_id, p_op.department_id) AS effective_dept_id,
                                 d.name AS dept_name
                          FROM operations o
                          LEFT JOIN operations p_op ON o.parent_id = p_op.id
                          LEFT JOIN departments d ON COALESCE(o.department_id, p_op.department_id) = d.id
                          WHERE o.is_active = 1 
                          ORDER BY o.sequence ASC, o.id ASC")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    (code, name, phone, address, cnic, worker_type, rate_basis, department_id, default_rate, rate_per_minute, joining_date, opening_balance, notes, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([
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

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-dark">Wage / Worker Type *</label>
                            <?php $selType = $_POST['worker_type'] ?? 'Piece Rate'; ?>
                            <select name="worker_type" id="wrk_type" class="form-select fw-semibold border-primary">
                                <option value="Piece Rate" <?= $selType === 'Piece Rate' ? 'selected' : '' ?>>👕 Piece Rate (Cutting, Tailoring, Finishing)</option>
                                <option value="Minute Rate" <?= $selType === 'Minute Rate' ? 'selected' : '' ?>>⏱️ Minute Rate (Knitting Machine Operator)</option>
                                <option value="Daily Wage" <?= $selType === 'Daily Wage' ? 'selected' : '' ?>>📅 Daily Wage (Fixed Rate Per Day)</option>
                                <option value="Monthly Salary" <?= $selType === 'Monthly Salary' ? 'selected' : '' ?>>💼 Monthly Salary (Fixed Per Month)</option>
                                <option value="Contractor" <?= $selType === 'Contractor' ? 'selected' : '' ?>>🤝 Contractor</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Primary Department</label>
                            <select name="department_id" id="wrk_dept" class="form-select">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" data-name="<?= strtolower($dept['name']) ?>" <?= (isset($_POST['department_id']) && $_POST['department_id'] == $dept['id']) ? 'selected' : '' ?>><?= clean($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-bold text-dark">Rate & Work Basis *</label>
                            <select name="rate_basis" id="wrk_rate_basis" class="form-select fw-semibold border-primary">
                                <option value="whole_sweater" <?= (isset($_POST['rate_basis']) && $_POST['rate_basis'] === 'whole_sweater') ? 'selected' : '' ?>>👕 Whole Sweaters (Tailoring, Buttons, Pressing)</option>
                                <option value="element_wise" <?= (isset($_POST['rate_basis']) && $_POST['rate_basis'] === 'element_wise') ? 'selected' : '' ?>>🧩 Garment Elements (Operator Knitting, Cutting)</option>
                            </select>
                            <div class="form-text small" style="font-size: 11px;">Workflow and rate calculation in Production Entry will be based on this setting.</div>
                        </div>

                        <!-- Piece Rate -->
                        <div class="col-12 col-md-3" id="grp_piece_rate">
                            <label class="form-label fw-bold text-primary" id="lbl_piece_rate">Default Piece Rate *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-primary text-white fw-bold">Rs.</span>
                                <input type="number" name="default_rate" id="wrk_piece_rate" class="form-control fw-bold text-primary" step="any" min="0" value="<?= clean($_POST['default_rate'] ?? '0.00') ?>" placeholder="0.00">
                                <span class="input-group-text" id="unit_piece_rate">/pc</span>
                            </div>
                            <div class="form-text small" id="hint_piece_rate" style="font-size: 11px;">Fixed standard piece rate for piece or cutting.</div>
                        </div>

                        <!-- Minute Rate -->
                        <div class="col-12 col-md-3" id="grp_min_rate">
                            <label class="form-label fw-semibold text-success">Minute Rate *</label>
                            <div class="input-group">
                                <span class="input-group-text bg-success text-white fw-bold">Rs.</span>
                                <input type="number" name="rate_per_minute" id="wrk_min_rate" class="form-control fw-bold text-success" step="any" min="0" value="<?= clean($_POST['rate_per_minute'] ?? '2.50') ?>" required>
                                <span class="input-group-text">/min</span>
                            </div>
                            <div class="form-text small" style="font-size: 11px;">Standard time and rate benchmark for machine operators.</div>
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

                        <!-- Assigned Operations Checklist (Dynamic based on selected Department) -->
                        <div class="col-12">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <div>
                                    <label class="form-label fw-bold text-dark mb-0">
                                        <i class="bi bi-diagram-3-fill text-primary me-1"></i> Authorized / Skilled Operations
                                    </label>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-2" id="lbl_op_count">0 selected</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-xs btn-outline-primary py-1 px-2 small" id="btn_select_all_ops" style="font-size: 11px;">
                                        <i class="bi bi-check-all me-1"></i> Select All
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2 small" id="btn_deselect_all_ops" style="font-size: 11px;">
                                        <i class="bi bi-x-lg me-1"></i> Deselect All
                                    </button>
                                    <div class="form-check form-switch mb-0 ms-2" title="Show all factory operations regardless of department">
                                        <input class="form-check-input" type="checkbox" id="chk_show_all_ops">
                                        <label class="form-check-label small text-muted" for="chk_show_all_ops" style="font-size: 11px;">Show All</label>
                                    </div>
                                </div>
                            </div>

                            <div class="p-3 bg-light rounded-3 border">
                                <div id="msg_select_dept" class="alert alert-info py-2 small mb-2 d-none">
                                    <i class="bi bi-info-circle me-1"></i> Select a department above to auto-display and check its related operations.
                                </div>
                                <div id="msg_no_dept_ops" class="alert alert-warning py-2 small mb-2 d-none">
                                    <i class="bi bi-exclamation-triangle me-1"></i> No operations found under this department. Enable "Show All" to view all operations.
                                </div>

                                <div class="row g-2" id="operations_container">
                                    <?php 
                                    $postAssigned = $_POST['operations'] ?? [];
                                    $hasPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
                                    foreach ($operations as $op): 
                                        $isAssigned = in_array($op['id'], $postAssigned);
                                    ?>
                                        <div class="col-12 col-sm-6 col-md-4 op-item-wrapper" 
                                             data-op-id="<?= $op['id'] ?>" 
                                             data-dept-id="<?= $op['effective_dept_id'] ?? '' ?>" 
                                             data-dept-name="<?= strtolower($op['dept_name'] ?? '') ?>"
                                             data-op-name="<?= strtolower($op['name']) ?>"
                                             data-init-assigned="<?= $isAssigned ? '1' : '0' ?>">
                                            <div class="form-check p-2 rounded-2 border border-light-subtle bg-white h-100 op-check-card">
                                                <input class="form-check-input wrk-op-checkbox ms-1 me-2" type="checkbox" name="operations[]" value="<?= $op['id'] ?>" id="wrk_op_<?= $op['id'] ?>" <?= $isAssigned ? 'checked' : '' ?>>
                                                <label class="form-check-label small fw-semibold text-dark cursor-pointer d-block" for="wrk_op_<?= $op['id'] ?>">
                                                    <span class="badge bg-light text-secondary border me-1"><?= clean($op['sequence']) ?></span>
                                                    <?= clean($op['name']) ?>
                                                    <?php if (!empty($op['dept_name'])): ?>
                                                        <span class="badge bg-light text-muted border ms-1 fw-normal d-inline-block" style="font-size: 10px;"><?= clean($op['dept_name']) ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const deptSelect = document.getElementById('wrk_dept');
    const rateBasisSelect = document.getElementById('wrk_rate_basis');
    const typeSelect = document.getElementById('wrk_type');
    const lblPieceRate = document.getElementById('lbl_piece_rate');
    const unitPieceRate = document.getElementById('unit_piece_rate');
    const hintPieceRate = document.getElementById('hint_piece_rate');
    const inpPieceRate = document.getElementById('wrk_piece_rate');

    const showAllOpsChk = document.getElementById('chk_show_all_ops');
    const opCountBadge = document.getElementById('lbl_op_count');
    const msgSelectDept = document.getElementById('msg_select_dept');
    const msgNoDeptOps = document.getElementById('msg_no_dept_ops');
    const btnSelectAll = document.getElementById('btn_select_all_ops');
    const btnDeselectAll = document.getElementById('btn_deselect_all_ops');
    const opWrappers = document.querySelectorAll('.op-item-wrapper');

    function syncWageTypeUI() {
        if (!typeSelect) return;
        const val = typeSelect.value;
        if (val === 'Piece Rate') {
            if (lblPieceRate) lblPieceRate.textContent = 'Default Piece Rate *';
            if (unitPieceRate) unitPieceRate.textContent = '/pc';
            if (hintPieceRate) hintPieceRate.textContent = 'Fixed standard piece rate for piece or cutting.';
            if (rateBasisSelect) rateBasisSelect.value = 'whole_sweater';
        } else if (val === 'Minute Rate') {
            if (lblPieceRate) lblPieceRate.textContent = 'Standard Piece Rate Equivalent';
            if (unitPieceRate) unitPieceRate.textContent = '/pc';
            if (hintPieceRate) hintPieceRate.textContent = 'Operator base rate is calculated from Minute Rate below (SAM × Rate/min).';
            if (rateBasisSelect) rateBasisSelect.value = 'element_wise';
        } else if (val === 'Daily Wage') {
            if (lblPieceRate) lblPieceRate.textContent = 'Daily Wage Rate *';
            if (unitPieceRate) unitPieceRate.textContent = '/day';
            if (hintPieceRate) hintPieceRate.textContent = 'Fixed wage per shift / day.';
        } else if (val === 'Monthly Salary') {
            if (lblPieceRate) lblPieceRate.textContent = 'Monthly Fixed Salary *';
            if (unitPieceRate) unitPieceRate.textContent = '/month';
            if (hintPieceRate) hintPieceRate.textContent = 'Fixed monthly salary.';
        } else if (val === 'Contractor') {
            if (lblPieceRate) lblPieceRate.textContent = 'Contract Piece Rate *';
            if (unitPieceRate) unitPieceRate.textContent = '/pc';
            if (hintPieceRate) hintPieceRate.textContent = 'Contractor agreed piece rate.';
        }
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncWageTypeUI);
        syncWageTypeUI();
    }

    function isOpMatchingDept(opEl, selectedDeptId, selectedDeptName) {
        if (!selectedDeptId) return false;
        const opDeptId = String(opEl.dataset.deptId || '').trim();
        const opDeptName = String(opEl.dataset.deptName || '').toLowerCase();
        const opName = String(opEl.dataset.opName || '').toLowerCase();

        // 1. Direct ID match
        if (opDeptId && opDeptId === String(selectedDeptId).trim()) {
            return true;
        }

        // 2. Domain / Keyword match for synonym or multi-name departments
        if (selectedDeptName.includes('cutt')) {
            return opDeptName.includes('cutt') || opName.includes('cutt') || opDeptId === '1' || opDeptId === '9';
        }
        if (selectedDeptName.includes('operat') || selectedDeptName.includes('knit')) {
            return opDeptName.includes('operat') || opDeptName.includes('knit') || opName.includes('operat') || opName.includes('knit') || opDeptId === '8' || opDeptId === '11';
        }
        if (selectedDeptName.includes('tailor') || selectedDeptName.includes('stitch')) {
            return opDeptName.includes('tailor') || opDeptName.includes('stitch') || opName.includes('tailor') || opName.includes('stitch') || opDeptId === '2' || opDeptId === '3';
        }
        if (selectedDeptName.includes('safe') || selectedDeptName.includes('overlock')) {
            return opDeptName.includes('safe') || opDeptName.includes('overlock') || opName.includes('safe') || opName.includes('overlock') || opDeptId === '4';
        }
        if (selectedDeptName.includes('finish') || selectedDeptName.includes('button')) {
            return opDeptName.includes('finish') || opDeptName.includes('button') || opName.includes('button') || opName.includes('kaj') || opDeptId === '5';
        }
        if (selectedDeptName.includes('press') || selectedDeptName.includes('iron')) {
            return opDeptName.includes('press') || opDeptName.includes('iron') || opName.includes('press') || opDeptId === '6';
        }
        if (selectedDeptName.includes('pack')) {
            return opDeptName.includes('pack') || opName.includes('pack') || opDeptId === '7';
        }
        if (selectedDeptName.includes('wash') || selectedDeptName.includes('dye')) {
            return opDeptName.includes('wash') || opDeptName.includes('dye') || opDeptId === '10';
        }

        return false;
    }

    function updateOpSummaryBadge() {
        let checkedCount = 0;
        let visibleCount = 0;
        opWrappers.forEach(wrap => {
            const isVisible = !wrap.classList.contains('d-none');
            const chk = wrap.querySelector('.wrk-op-checkbox');
            if (isVisible) visibleCount++;
            if (chk && chk.checked) checkedCount++;
        });

        if (opCountBadge) {
            opCountBadge.textContent = `${checkedCount} selected (${visibleCount} visible)`;
        }
    }

    function syncOperationsWithDepartment(isUserChange = false) {
        const selectedDeptId = deptSelect ? deptSelect.value : '';
        const deptOpt = deptSelect ? deptSelect.selectedOptions[0] : null;
        const selectedDeptName = deptOpt ? (deptOpt.dataset.name || deptOpt.textContent.toLowerCase()) : '';
        const showAll = showAllOpsChk ? showAllOpsChk.checked : false;

        if (!selectedDeptId && !showAll) {
            if (msgSelectDept) msgSelectDept.classList.remove('d-none');
            if (msgNoDeptOps) msgNoDeptOps.classList.add('d-none');
            opWrappers.forEach(wrap => {
                wrap.classList.add('d-none');
                if (isUserChange) {
                    const chk = wrap.querySelector('.wrk-op-checkbox');
                    if (chk) chk.checked = false;
                }
            });
            updateOpSummaryBadge();
            return;
        }

        if (msgSelectDept) msgSelectDept.classList.add('d-none');

        let matchCount = 0;
        opWrappers.forEach(wrap => {
            const chk = wrap.querySelector('.wrk-op-checkbox');
            const matches = showAll || isOpMatchingDept(wrap, selectedDeptId, selectedDeptName);

            if (matches) {
                wrap.classList.remove('d-none');
                matchCount++;
                if (isUserChange) {
                    // Automatically check all operations under this department!
                    if (chk) chk.checked = true;
                } else if (!isUserChange && selectedDeptId && !<?= !empty($postAssigned) ? 'true' : 'false' ?>) {
                    // On initial load if department is selected and no prior post, auto-check!
                    if (chk) chk.checked = true;
                }
            } else {
                wrap.classList.add('d-none');
                if (isUserChange) {
                    if (chk) chk.checked = false;
                }
            }
        });

        if (msgNoDeptOps) {
            if (matchCount === 0 && selectedDeptId && !showAll) {
                msgNoDeptOps.classList.remove('d-none');
            } else {
                msgNoDeptOps.classList.add('d-none');
            }
        }

        updateOpSummaryBadge();
    }

    if (deptSelect) {
        deptSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const deptName = opt ? (opt.dataset.name || opt.textContent.toLowerCase()) : '';
            if (rateBasisSelect) {
                if (deptName.includes('operat') || deptName.includes('cutt') || deptName.includes('knitt')) {
                    rateBasisSelect.value = 'element_wise';
                } else if (deptName) {
                    rateBasisSelect.value = 'whole_sweater';
                }
            }

            syncOperationsWithDepartment(true);
        });
    }

    if (btnSelectAll) {
        btnSelectAll.addEventListener('click', function() {
            opWrappers.forEach(wrap => {
                if (!wrap.classList.contains('d-none')) {
                    const chk = wrap.querySelector('.wrk-op-checkbox');
                    if (chk) chk.checked = true;
                }
            });
            updateOpSummaryBadge();
        });
    }

    if (btnDeselectAll) {
        btnDeselectAll.addEventListener('click', function() {
            opWrappers.forEach(wrap => {
                if (!wrap.classList.contains('d-none')) {
                    const chk = wrap.querySelector('.wrk-op-checkbox');
                    if (chk) chk.checked = false;
                }
            });
            updateOpSummaryBadge();
        });
    }

    if (showAllOpsChk) {
        showAllOpsChk.addEventListener('change', function() {
            syncOperationsWithDepartment(false);
        });
    }

    document.querySelectorAll('.wrk-op-checkbox').forEach(chk => {
        chk.addEventListener('change', updateOpSummaryBadge);
    });

    // Initialize operations on load
    syncOperationsWithDepartment(false);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
