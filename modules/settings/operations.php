<?php
/**
 * Garment Manufacturing ERP - Dynamic Production Operations & Rates
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Operations & Labour Rates';
$activeNav = 'settings_ops';
$db = get_db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_operation') {
        check_csrf();
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $sequence = (int)($_POST['sequence'] ?? 10);
        $minutes = (float)($_POST['estimated_minutes'] ?? 0.00);
        $rate = (float)($_POST['default_rate'] ?? 0.00);
        if ($rate <= 0 && $minutes > 0) {
            $rate = round($minutes * 2.50, 2); // 2.50 Rs/min standard factory default
        }
        $unit = trim($_POST['rate_unit'] ?? 'Per piece');
        $mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

        if (empty($code) || empty($name)) {
            set_flash('danger', 'Operation code and name are required.');
        } else {
            $stmt = $db->prepare("INSERT INTO operations (parent_id, code, name, department_id, sequence, estimated_minutes, is_mandatory, default_rate, rate_unit, is_active) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$parentId, $code, $name, $deptId, $sequence, $minutes, $mandatory, $rate, $unit]);
            log_audit('CREATE', 'operations', (int)$db->lastInsertId(), null, ['code' => $code, 'name' => $name, 'rate' => $rate, 'minutes' => $minutes]);
            redirect('modules/settings/operations.php', "Operation '$name' created successfully!");
        }
    }

    if ($action === 'update_rate') {
        check_csrf();
        $opId = (int)($_POST['operation_id'] ?? 0);
        $minutes = (float)($_POST['estimated_minutes'] ?? 0.00);
        $newRate = (float)($_POST['default_rate'] ?? 0.00);
        if ($opId > 0) {
            $stmt = $db->prepare("UPDATE operations SET estimated_minutes = ?, default_rate = ? WHERE id = ?");
            $stmt->execute([$minutes, $newRate, $opId]);
            log_audit('UPDATE_RATE', 'operations', $opId, null, ['estimated_minutes' => $minutes, 'new_default_rate' => $newRate]);
            redirect('modules/settings/operations.php', 'Operation SAM minutes and default rate updated.');
        }
    }

    if ($action === 'create_department') {
        check_csrf();
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) {
            set_flash('danger', 'Department name is required.');
        } else {
            try {
                // Check if name already exists
                $chk = $db->prepare("SELECT id FROM departments WHERE LOWER(TRIM(name)) = LOWER(?)");
                $chk->execute([$name]);
                if ($chk->fetch()) {
                    set_flash('danger', "Department '$name' already exists.");
                } else {
                    // Generate clean unique code
                    $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
                    $code = !empty($baseCode) ? substr($baseCode, 0, 20) : 'DEP';
                    $cChk = $db->prepare("SELECT id FROM departments WHERE code = ?");
                    $cChk->execute([$code]);
                    if ($cChk->fetch()) {
                        $code = substr($code, 0, 15) . '-' . time();
                    }

                    $stmt = $db->prepare("INSERT INTO departments (code, name, description, is_active) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$code, $name, $desc]);
                    log_audit('CREATE', 'departments', (int)$db->lastInsertId(), null, ['name' => $name, 'code' => $code]);
                    redirect('modules/settings/operations.php#departments', "Department '$name' created successfully!");
                }
            } catch (PDOException $e) {
                set_flash('danger', 'Error saving department: ' . $e->getMessage());
            }
        }
    }
}

$operations = $db->query("SELECT o.*, p_op.name AS parent_operation_name, p_op.code AS parent_operation_code, d.name AS department_name,
                          (SELECT COUNT(*) FROM worker_operations WHERE operation_id = o.id) AS assigned_workers,
                          (SELECT COUNT(*) FROM operations sub WHERE sub.parent_id = o.id) AS sub_ops_count
                          FROM operations o
                          LEFT JOIN operations p_op ON o.parent_id = p_op.id
                          LEFT JOIN departments d ON o.department_id = d.id
                          ORDER BY COALESCE(o.parent_id, o.id) ASC, (o.parent_id IS NOT NULL) ASC, o.sequence ASC")->fetchAll();

$parentOperations = $db->query("SELECT id, name, code, department_id FROM operations WHERE parent_id IS NULL AND is_active = 1 ORDER BY sequence ASC, name ASC")->fetchAll();

$departments = $db->query("SELECT d.*, 
                           (SELECT COUNT(*) FROM operations WHERE department_id = d.id) AS ops_count,
                           (SELECT COUNT(*) FROM workers WHERE department_id = d.id) AS workers_count
                           FROM departments d 
                           WHERE d.is_active = 1 
                           ORDER BY d.name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production Operations & Labour Rates</h4>
                <p class="text-muted small mb-0">Dynamic operations, piece rates, workflow sequence & departments</p>
            </div>
        </div>

        <!-- Navigation Tabs for Sections -->
        <ul class="nav nav-pills mb-4 gap-2" id="opsTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-semibold" data-bs-toggle="pill" data-bs-target="#operationsTab" type="button">
                    <i class="bi bi-gear-wide-connected me-1"></i> Operations (<?= count($operations) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#departmentsTab" type="button">
                    <i class="bi bi-building me-1"></i> Departments (<?= count($departments) ?>)
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- OPERATIONS TAB -->
            <div class="tab-pane fade show active" id="operationsTab">
                <!-- Add Operation Inline Form -->
                <div class="card-modern mb-4">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Create Dynamic Operation / Sub-Operation</h6>
                        <span class="badge bg-light text-muted border">Standard Factory Benchmark: Rs. 2.50 / Minute</span>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="" id="opForm">
                            <input type="hidden" name="action" value="create_operation">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="row g-3">
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Parent Stage (مین آپریشن)</label>
                                    <select name="parent_id" id="parent_id" class="form-select">
                                        <option value="">-- Main Primary Operation (No Parent) --</option>
                                        <?php foreach ($parentOperations as $pop): ?>
                                            <option value="<?= $pop['id'] ?>" data-dept="<?= $pop['department_id'] ?>">
                                                <?= clean($pop['name']) ?> (<?= clean($pop['code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small" style="font-size: 11px;">Select parent if this is a sub-part (e.g. Sleeves/Pockets under Tailoring).</div>
                                </div>

                                <div class="col-12 col-sm-6 col-md-2">
                                    <label class="form-label fw-semibold">Operation Code *</label>
                                    <input type="text" name="code" id="op_code" class="form-control text-uppercase fw-bold" placeholder="OP-TAIL-SLV" required>
                                </div>

                                <div class="col-12 col-sm-6 col-md-4">
                                    <label class="form-label fw-semibold">Operation / Part Name *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Attach Sleeves (بازو لگانا)" required>
                                </div>

                                <div class="col-12 col-sm-6 col-md-3">
                                    <label class="form-label fw-semibold">Department</label>
                                    <select name="department_id" id="department_id" class="form-select">
                                        <option value="">-- General / Production --</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?= $dept['id'] ?>"><?= clean($dept['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-sm-4 col-md-2">
                                    <label class="form-label fw-semibold">Standard Time (SAM) *</label>
                                    <div class="input-group">
                                        <input type="number" name="estimated_minutes" id="estimated_minutes" class="form-control fw-bold text-primary" step="0.1" min="0" value="2.0" required>
                                        <span class="input-group-text">min</span>
                                    </div>
                                    <div class="form-text small" style="font-size: 11px;">وقت منٹوں میں (فی پیس)</div>
                                </div>

                                <div class="col-12 col-sm-4 col-md-2">
                                    <label class="form-label fw-semibold">Default Rate (Rs.) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" name="default_rate" id="default_rate" class="form-control fw-bold text-success" step="0.25" min="0" value="5.00" required>
                                    </div>
                                    <div class="form-text small" style="font-size: 11px;">منٹس × 2.50 روپے یا مینوئل ریٹ</div>
                                </div>

                                <div class="col-12 col-sm-4 col-md-2">
                                    <label class="form-label fw-semibold">Workflow Sequence</label>
                                    <input type="number" name="sequence" class="form-control" value="25" min="1">
                                </div>

                                <div class="col-12 col-sm-6 col-md-3">
                                    <label class="form-label fw-semibold">Rate Unit</label>
                                    <select name="rate_unit" class="form-select">
                                        <option value="Per piece">Per piece (فی پیس)</option>
                                        <option value="Per dozen">Per dozen (فی درجن)</option>
                                    </select>
                                </div>

                                <div class="col-12 col-sm-6 col-md-3 d-flex align-items-end justify-content-between gap-2">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="is_mandatory" checked>
                                        <label class="form-check-label small fw-semibold" for="is_mandatory">Mandatory</label>
                                    </div>
                                    <button type="submit" class="btn btn-primary fw-semibold px-4 flex-grow-1">
                                        <i class="bi bi-plus-lg me-1"></i> Add Operation
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Operations List -->
                <div class="card-modern">
                    <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Active Factory Operations, Sub-Parts & SAM Rates</h6>
                            <span class="text-muted small">Sequential manufacturing stages with Standard Allowed Minutes (SAM) and benchmark rates</span>
                        </div>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Seq</th>
                                        <th>Code</th>
                                        <th>Operation / Sub-Part Name</th>
                                        <th>Hierarchy</th>
                                        <th>Department</th>
                                        <th class="text-center" style="width: 140px;">Standard Time (SAM)</th>
                                        <th class="text-end" style="width: 130px;">Base Rate</th>
                                        <th>Artisans</th>
                                        <th class="text-end" style="width: 260px;">Quick Update (Time & Rate)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($operations as $op): 
                                        $isSub = !empty($op['parent_id']);
                                    ?>
                                        <tr class="<?= $isSub ? 'bg-light bg-opacity-25' : '' ?>">
                                            <td>
                                                <span class="badge <?= $isSub ? 'bg-white text-muted border' : 'bg-light text-dark border fw-bold' ?>"><?= $op['sequence'] ?></span>
                                            </td>
                                            <td class="fw-bold <?= $isSub ? 'text-secondary small' : 'text-primary' ?>"><?= clean($op['code']) ?></td>
                                            <td>
                                                <?php if ($isSub): ?>
                                                    <div class="ms-3 fw-bold text-dark d-flex align-items-center gap-1">
                                                        <span class="text-primary fs-6">↳</span>
                                                        <span><?= clean($op['name']) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="fw-bold text-dark fs-6"><?= clean($op['name']) ?></div>
                                                <?php endif; ?>
                                                <div class="text-muted small ms-<?= $isSub ? '4' : '0' ?>">Unit: <?= clean($op['rate_unit']) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($isSub): ?>
                                                    <span class="badge bg-info-subtle text-info border border-info-subtle">
                                                        <i class="bi bi-diagram-3 me-1"></i>Under: <?= clean($op['parent_operation_name'] ?? 'Main') ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">
                                                        Main Stage <?= $op['sub_ops_count'] > 0 ? "({$op['sub_ops_count']} sub-parts)" : '' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= clean($op['department_name'] ?? 'General') ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-light text-primary border fs-6 px-2 py-1">
                                                    <i class="bi bi-stopwatch me-1"></i><?= (float)$op['estimated_minutes'] ?> min
                                                </span>
                                            </td>
                                            <td class="text-end fw-bold fs-6 text-dark">
                                                <?= format_currency($op['default_rate']) ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-secondary fw-semibold">
                                                    <?= $op['assigned_workers'] ?> workers
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" action="" class="d-inline-flex gap-1 justify-content-end align-items-center">
                                                    <input type="hidden" name="action" value="update_rate">
                                                    <input type="hidden" name="operation_id" value="<?= $op['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    
                                                    <div class="input-group input-group-sm" style="width: 105px;" title="Estimated Minutes">
                                                        <input type="number" name="estimated_minutes" class="form-control text-center" step="0.1" min="0" value="<?= (float)$op['estimated_minutes'] ?>" placeholder="Min">
                                                        <span class="input-group-text px-1 small">m</span>
                                                    </div>

                                                    <div class="input-group input-group-sm" style="width: 105px;" title="Default Piece Rate">
                                                        <span class="input-group-text px-1 small">Rs</span>
                                                        <input type="number" name="default_rate" class="form-control text-end" step="0.25" min="0" value="<?= $op['default_rate'] ?>">
                                                    </div>

                                                    <button type="submit" class="btn btn-sm btn-outline-primary py-1 px-2" title="Save Time & Rate">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div> <!-- end operationsTab -->

    <!-- DEPARTMENTS TAB -->
    <div class="tab-pane fade" id="departmentsTab">
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Create Factory Department</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_department">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Department Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Embroidery / Printing" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Description / Scope</label>
                            <input type="text" name="description" class="form-control" placeholder="Responsibilities and equipment of department">
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="submit" class="btn btn-primary w-100 fw-semibold">
                                Add Department
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Configured Factory Departments</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th>Department Name</th>
                                <th>Description</th>
                                <th>Associated Operations</th>
                                <th>Active Artisans</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($departments)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No departments configured yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($departments as $dept): ?>
                                    <tr>
                                        <td class="text-muted fw-bold">#<?= $dept['id'] ?></td>
                                        <td class="fw-bold text-dark">
                                            <i class="bi bi-building text-primary me-1"></i>
                                            <?= clean($dept['name']) ?>
                                        </td>
                                        <td class="text-muted"><?= clean($dept['description'] ?: '—') ?></td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary fw-semibold">
                                                <?= $dept['ops_count'] ?? 0 ?> operations
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info-subtle text-info fw-semibold">
                                                <?= $dept['workers_count'] ?? 0 ?> workers
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success fw-semibold">Active</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div> <!-- end departmentsTab -->
</div> <!-- end tab-content -->

    </div> <!-- end app-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const minInput = document.getElementById('estimated_minutes');
    const rateInput = document.getElementById('default_rate');
    const parentSelect = document.getElementById('parent_id');
    const deptSelect = document.getElementById('department_id');

    // Auto-update department if parent has one
    if (parentSelect && deptSelect) {
        parentSelect.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const deptId = selected.dataset.dept;
            if (deptId) {
                deptSelect.value = deptId;
            }
        });
    }

    // Dynamic rate calculation: Minutes * 2.50 Rs/min
    if (minInput && rateInput) {
        minInput.addEventListener('input', function() {
            const mins = parseFloat(this.value) || 0;
            rateInput.value = (mins * 2.50).toFixed(2);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
