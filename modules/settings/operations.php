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
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $sequence = (int)($_POST['sequence'] ?? 10);
        $rate = (float)($_POST['default_rate'] ?? 0.00);
        $unit = trim($_POST['rate_unit'] ?? 'Per piece');
        $mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

        if (empty($code) || empty($name)) {
            set_flash('danger', 'Operation code and name are required.');
        } else {
            $stmt = $db->prepare("INSERT INTO operations (code, name, department_id, sequence, is_mandatory, default_rate, rate_unit, is_active) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$code, $name, $deptId, $sequence, $mandatory, $rate, $unit]);
            log_audit('CREATE', 'operations', (int)$db->lastInsertId(), null, ['code' => $code, 'name' => $name, 'rate' => $rate]);
            redirect('modules/settings/operations.php', "Operation '$name' created successfully!");
        }
    }

    if ($action === 'update_rate') {
        $opId = (int)($_POST['operation_id'] ?? 0);
        $newRate = (float)($_POST['default_rate'] ?? 0.00);
        if ($opId > 0) {
            $stmt = $db->prepare("UPDATE operations SET default_rate = ? WHERE id = ?");
            $stmt->execute([$newRate, $opId]);
            log_audit('UPDATE_RATE', 'operations', $opId, null, ['new_default_rate' => $newRate]);
            redirect('modules/settings/operations.php', 'Operation default rate updated.');
        }
    }
}

$operations = $db->query("SELECT o.*, d.name AS department_name,
                          (SELECT COUNT(*) FROM worker_operations WHERE operation_id = o.id) AS assigned_workers
                          FROM operations o
                          LEFT JOIN departments dep ON o.department_id = dep.id
                          LEFT JOIN departments d ON o.department_id = d.id
                          ORDER BY o.sequence ASC")->fetchAll();

$departments = $db->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

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

        <!-- Add Operation Inline Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Create Dynamic Operation</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_operation">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label">Operation Code *</label>
                            <input type="text" name="code" class="form-control text-uppercase" placeholder="OP-TRIM" required>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <label class="form-label">Operation Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Thread Trimming" required>
                        </div>
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= clean($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-md-1">
                            <label class="form-label">Sequence</label>
                            <input type="number" name="sequence" class="form-control" value="50" min="1">
                        </div>
                        <div class="col-12 col-sm-6 col-md-2">
                            <label class="form-label">Default Rate (Rs.)</label>
                            <input type="number" name="default_rate" class="form-control" step="0.25" value="0.00">
                        </div>
                        <div class="col-12 col-sm-6 col-md-2">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="is_mandatory" checked>
                                <label class="form-check-label small fw-semibold" for="is_mandatory">Mandatory Stage</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 fw-semibold">
                                Add Operation
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Operations List -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Active Factory Operations & Standard Rates</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 70px;">Seq</th>
                                <th>Code</th>
                                <th>Operation Name</th>
                                <th>Department</th>
                                <th>Type</th>
                                <th>Assigned Artisans</th>
                                <th class="text-end">Standard Rate</th>
                                <th class="text-end">Update Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($operations as $op): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-light text-dark border fw-bold"><?= $op['sequence'] ?></span>
                                    </td>
                                    <td class="fw-bold text-primary"><?= clean($op['code']) ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= clean($op['name']) ?></div>
                                        <div class="text-muted small">Unit: <?= clean($op['rate_unit']) ?></div>
                                    </td>
                                    <td><?= clean($op['department_name'] ?? 'General') ?></td>
                                    <td>
                                        <?php if ($op['is_mandatory']): ?>
                                            <span class="badge bg-danger-subtle text-danger fw-semibold">Mandatory</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary fw-semibold">Optional</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary fw-semibold">
                                            <?= $op['assigned_workers'] ?> workers
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold fs-6 text-dark">
                                        <?= format_currency($op['default_rate']) ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="" class="d-inline-flex gap-1 justify-content-end align-items-center">
                                            <input type="hidden" name="action" value="update_rate">
                                            <input type="hidden" name="operation_id" value="<?= $op['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="number" name="default_rate" class="form-control form-control-sm text-end" style="width: 90px;" step="0.25" value="<?= $op['default_rate'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Save Rate">
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

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
