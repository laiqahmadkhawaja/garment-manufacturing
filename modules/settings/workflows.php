<?php
/**
 * Garment Manufacturing ERP - Production Workflow Templates
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Workflow Templates';
$activeNav = 'settings_wf';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_template') {
        $name = trim($_POST['name'] ?? '');
        $productType = trim($_POST['product_type'] ?? 'General');
        $desc = trim($_POST['description'] ?? '');
        $selectedOps = $_POST['operations'] ?? [];

        if (empty($name) || empty($selectedOps)) {
            set_flash('danger', 'Template name and at least one operation must be selected.');
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO workflow_templates (name, product_type, description, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$name, $productType, $desc]);
                $templateId = (int)$db->lastInsertId();

                $seq = 1;
                $stepStmt = $db->prepare("INSERT INTO workflow_template_steps (template_id, operation_id, sequence, is_mandatory) VALUES (?, ?, ?, 1)");
                foreach ($selectedOps as $opId) {
                    $stepStmt->execute([$templateId, (int)$opId, $seq++]);
                }
                $db->commit();
                log_audit('CREATE', 'workflow_templates', $templateId, null, ['name' => $name, 'steps_count' => count($selectedOps)]);
                redirect('modules/settings/workflows.php', "Workflow '$name' configured successfully!");
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('danger', 'Error saving workflow: ' . $e->getMessage());
            }
        }
    }
}

$templates = $db->query("SELECT wt.*, 
                         (SELECT COUNT(*) FROM workflow_template_steps WHERE template_id = wt.id) AS steps_count,
                         (SELECT COUNT(*) FROM lots l JOIN products p ON l.product_id = p.id LEFT JOIN designs d ON l.design_id = d.id WHERE COALESCE(d.workflow_template_id, p.default_workflow_template_id) = wt.id) AS lots_count
                         FROM workflow_templates wt
                         ORDER BY wt.id ASC")->fetchAll();

$allOps = $db->query("SELECT * FROM operations WHERE is_active = 1 ORDER BY sequence ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production Workflow Templates</h4>
                <p class="text-muted small mb-0">Customizable sequential operations per garment type</p>
            </div>
        </div>

        <!-- Add Workflow Template Inline Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Create New Workflow Template</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_template">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-5">
                            <label class="form-label">Template Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Cardigan Knitwear Workflow" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Garment Type</label>
                            <input type="text" name="product_type" class="form-control" placeholder="Sweater, Coat, etc.">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control" placeholder="Workflow characteristics">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold text-dark">Select Operations in Execution Sequence *</label>
                            <p class="text-muted small mb-2">Check the operations applicable to this garment (non-applicable operations will be excluded from pending queues):</p>
                            
                            <div class="row g-2 p-3 bg-light rounded-3 border">
                                <?php foreach ($allOps as $op): ?>
                                    <div class="col-6 col-sm-4 col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="operations[]" value="<?= $op['id'] ?>" id="op_<?= $op['id'] ?>">
                                            <label class="form-check-label fw-semibold small" for="op_<?= $op['id'] ?>">
                                                <?= clean($op['name']) ?>
                                                <span class="text-muted fw-normal">(Rs. <?= number_format($op['default_rate'], 2) ?>)</span>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-4 fw-semibold">
                                Save Workflow Template
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Templates List -->
        <div class="row g-4">
            <?php foreach ($templates as $tmpl): 
                $stepsStmt = $db->prepare("SELECT wts.*, o.name AS op_name, o.code AS op_code, o.default_rate, dep.name AS dept_name 
                                          FROM workflow_template_steps wts
                                          JOIN operations o ON wts.operation_id = o.id
                                          LEFT JOIN departments dep ON o.department_id = dep.id
                                          WHERE wts.template_id = ?
                                          ORDER BY wts.sequence ASC");
                $stepsStmt->execute([$tmpl['id']]);
                $steps = $stepsStmt->fetchAll();
            ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card-modern h-100">
                        <div class="card-modern-header">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?= clean($tmpl['name']) ?></h6>
                                <span class="badge bg-light text-dark border small"><?= clean($tmpl['product_type']) ?></span>
                            </div>
                            <span class="badge bg-primary-subtle text-primary fw-semibold"><?= count($steps) ?> Operations</span>
                        </div>
                        <div class="card-modern-body">
                            <p class="text-muted small mb-3"><?= clean($tmpl['description'] ?: 'Standard sequential factory workflow') ?></p>
                            
                            <div class="workflow-steps-list">
                                <ol class="list-group list-group-numbered list-group-flush small">
                                    <?php foreach ($steps as $st): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-0 bg-transparent">
                                            <div class="ms-2 me-auto">
                                                <div class="fw-semibold text-dark"><?= clean($st['op_name']) ?></div>
                                                <span class="text-muted" style="font-size: 0.75rem;"><?= clean($st['dept_name']) ?></span>
                                            </div>
                                            <span class="badge bg-light text-dark border">
                                                Rs. <?= number_format($st['default_rate'], 2) ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
