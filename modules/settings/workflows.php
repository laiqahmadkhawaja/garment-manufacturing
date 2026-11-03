<?php
/**
 * Garment Manufacturing ERP - Production Workflow Templates & Sequential Sorting
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Workflow Templates';
$activeNav = 'settings_wf';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // =========================================================================
    // 1. CREATE WORKFLOW TEMPLATE WITH CUSTOM SEQUENCE
    // =========================================================================
    if ($action === 'create_template') {
        $name = trim($_POST['name'] ?? '');
        $productType = trim($_POST['product_type'] ?? 'General');
        $desc = trim($_POST['description'] ?? '');
        // Prioritize explicitly ordered operations from the sequencer
        $selectedOps = $_POST['ordered_operations'] ?? ($_POST['operations'] ?? []);

        if (empty($name) || empty($selectedOps)) {
            set_flash('danger', 'Template name and at least one operation in the execution sequence must be specified.');
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO workflow_templates (name, product_type, description, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$name, $productType, $desc]);
                $templateId = (int)$db->lastInsertId();

                $seq = 1;
                $stepStmt = $db->prepare("INSERT INTO workflow_template_steps (template_id, operation_id, sequence, is_mandatory) VALUES (?, ?, ?, 1)");
                foreach ($selectedOps as $opId) {
                    $opId = (int)$opId;
                    if ($opId > 0) {
                        $stepStmt->execute([$templateId, $opId, $seq++]);
                    }
                }
                $db->commit();
                log_audit('CREATE', 'workflow_templates', $templateId, null, ['name' => $name, 'steps_count' => count($selectedOps)]);
                redirect('modules/settings/workflows.php', "Workflow '$name' created with " . count($selectedOps) . " sequenced operations!");
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('danger', 'Error saving workflow: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // 2. UPDATE WORKFLOW TEMPLATE & RE-SORT SEQUENCE
    // =========================================================================
    if ($action === 'update_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $productType = trim($_POST['product_type'] ?? 'General');
        $desc = trim($_POST['description'] ?? '');
        $selectedOps = $_POST['ordered_operations'] ?? ($_POST['operations'] ?? []);

        if ($templateId <= 0 || empty($name) || empty($selectedOps)) {
            set_flash('danger', 'Template name and at least one operation in the sequence must be provided.');
        } else {
            $db->beginTransaction();
            try {
                // Update template meta
                $stmt = $db->prepare("UPDATE workflow_templates SET name = ?, product_type = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $productType, $desc, $templateId]);

                // Replace steps in the new user-sorted sequence
                $db->prepare("DELETE FROM workflow_template_steps WHERE template_id = ?")->execute([$templateId]);

                $seq = 1;
                $stepStmt = $db->prepare("INSERT INTO workflow_template_steps (template_id, operation_id, sequence, is_mandatory) VALUES (?, ?, ?, 1)");
                foreach ($selectedOps as $opId) {
                    $opId = (int)$opId;
                    if ($opId > 0) {
                        $stepStmt->execute([$templateId, $opId, $seq++]);
                    }
                }

                $db->commit();
                log_audit('UPDATE', 'workflow_templates', $templateId, null, ['name' => $name, 'steps_count' => count($selectedOps)]);
                redirect('modules/settings/workflows.php', "Workflow '$name' sequence updated successfully!");
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('danger', 'Error updating workflow: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // 3. DELETE WORKFLOW TEMPLATE
    // =========================================================================
    if ($action === 'delete_template') {
        $templateId = (int)($_POST['template_id'] ?? 0);

        if ($templateId <= 0) {
            set_flash('danger', 'Invalid template selected for deletion.');
        } else {
            // Check if assigned to any product or design
            $pCount = (int)$db->query("SELECT COUNT(*) FROM products WHERE default_workflow_template_id = $templateId")->fetchColumn();
            $dCount = (int)$db->query("SELECT COUNT(*) FROM designs WHERE workflow_template_id = $templateId")->fetchColumn();

            if ($pCount > 0 || $dCount > 0) {
                set_flash('danger', "Cannot delete workflow template: It is currently assigned to $pCount product(s) and $dCount design(s).");
            } else {
                $db->beginTransaction();
                try {
                    $db->prepare("DELETE FROM workflow_template_steps WHERE template_id = ?")->execute([$templateId]);
                    $db->prepare("DELETE FROM workflow_templates WHERE id = ?")->execute([$templateId]);
                    $db->commit();
                    log_audit('DELETE', 'workflow_templates', $templateId, null, []);
                    redirect('modules/settings/workflows.php', "Workflow template deleted successfully.");
                } catch (Exception $e) {
                    $db->rollBack();
                    set_flash('danger', 'Error deleting workflow: ' . $e->getMessage());
                }
            }
        }
    }
}

// Fetch all templates with steps and lot usage
$templates = $db->query("SELECT wt.*, 
                         (SELECT COUNT(*) FROM workflow_template_steps WHERE template_id = wt.id) AS steps_count,
                         (SELECT COUNT(*) FROM lots l JOIN products p ON l.product_id = p.id LEFT JOIN designs d ON l.design_id = d.id WHERE COALESCE(d.workflow_template_id, p.default_workflow_template_id) = wt.id) AS lots_count
                         FROM workflow_templates wt
                         ORDER BY wt.id ASC")->fetchAll();

// Fetch all active operations with department names
$allOps = $db->query("SELECT o.*, dep.name AS dept_name 
                      FROM operations o 
                      LEFT JOIN departments dep ON o.department_id = dep.id 
                      WHERE o.is_active = 1 
                      ORDER BY o.sequence ASC, o.name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production Workflow Templates</h4>
                <p class="text-muted small mb-0">Define, sort, and manage sequential operations for each garment type</p>
            </div>
            <div>
                <a href="#createWorkflowCard" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-plus-circle"></i>
                    <span>Create New Workflow</span>
                </a>
            </div>
        </div>

        <!-- Add Workflow Template with Interactive Sequencer -->
        <div class="card-modern mb-4 shadow-sm" id="createWorkflowCard">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary p-2 rounded-2"><i class="bi bi-diagram-3-fill text-white fs-6"></i></span>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">Create New Workflow Template</h6>
                        <span class="text-muted small">Select operations and arrange their exact execution sequence</span>
                    </div>
                </div>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="" id="createWorkflowForm">
                    <input type="hidden" name="action" value="create_template">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold">Template Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Cardigan Knitwear Workflow" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Garment Type</label>
                            <input type="text" name="product_type" class="form-control" placeholder="Sweater, Coat, Shirt, etc.">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" name="description" class="form-control" placeholder="Workflow characteristics or specs">
                        </div>
                    </div>

                    <!-- Interactive Sequencer Dual-Panel -->
                    <div class="row g-3">
                        <!-- Left Panel: Available Operations -->
                        <div class="col-12 col-lg-5">
                            <div class="card border rounded-3 bg-light h-100">
                                <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-dark small"><i class="bi bi-list-check me-1 text-primary"></i> 1. Available Factory Operations</span>
                                    <span class="badge bg-secondary-subtle text-secondary small"><?= count($allOps) ?> Available</span>
                                </div>
                                <div class="card-body p-2" style="max-height: 380px; overflow-y: auto;">
                                    <div class="list-group list-group-flush gap-1">
                                        <?php foreach ($allOps as $op): ?>
                                            <div class="list-group-item list-group-item-action border rounded-2 p-2 d-flex justify-content-between align-items-center bg-white" id="op_item_<?= $op['id'] ?>">
                                                <div>
                                                    <div class="fw-semibold text-dark small"><?= clean($op['name']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.73rem;">
                                                        <?= clean($op['dept_name'] ?: 'General') ?> &bull; Rs. <?= number_format($op['default_rate'], 2) ?>
                                                    </div>
                                                </div>
                                                <button type="button" class="btn btn-outline-primary btn-sm py-1 px-2 btn-add-op" 
                                                        data-id="<?= $op['id'] ?>" 
                                                        data-name="<?= htmlspecialchars($op['name'], ENT_QUOTES) ?>" 
                                                        data-dept="<?= htmlspecialchars($op['dept_name'] ?: 'General', ENT_QUOTES) ?>" 
                                                        data-rate="<?= number_format($op['default_rate'], 2) ?>">
                                                    <i class="bi bi-plus-lg me-1"></i> Add
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top py-2 px-3">
                                    <button type="button" class="btn btn-sm btn-link text-decoration-none p-0" id="btnAddAllOps">
                                        <i class="bi bi-check2-all me-1"></i> Add All in Default Sequence
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Right Panel: Execution Sequence Pipeline -->
                        <div class="col-12 col-lg-7">
                            <div class="card border border-primary-subtle rounded-3 h-100 bg-white">
                                <div class="card-header bg-primary-subtle py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-bold text-primary small"><i class="bi bi-sort-numeric-down me-1"></i> 2. Execution Sequence Pipeline *</span>
                                        <div class="text-muted" style="font-size: 0.75rem;">Use ▲ and ▼ to order operations as they run on the factory floor</div>
                                    </div>
                                    <span class="badge bg-primary text-white" id="createPipelineCount">0 Steps</span>
                                </div>
                                <div class="card-body p-2" style="max-height: 380px; overflow-y: auto;">
                                    <div id="createPipelineList" class="d-flex flex-column gap-2">
                                        <!-- Placeholder when empty -->
                                        <div id="createPipelineEmpty" class="text-center py-5 text-muted">
                                            <i class="bi bi-arrow-left-circle display-6 text-secondary opacity-50 mb-2 d-block"></i>
                                            <h6 class="fw-semibold text-dark mb-1">No Operations Added Yet</h6>
                                            <p class="small text-muted mb-0">Click <strong>+ Add</strong> on operations from the left panel to configure this workflow's execution sequence.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-light border-top py-2 px-3 d-flex justify-content-between align-items-center">
                                    <span class="text-muted small" style="font-size: 0.75rem;">Operations are executed strictly in order 1 &rarr; 2 &rarr; 3</span>
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" id="btnClearPipeline" style="font-size: 0.8rem;">
                                        <i class="bi bi-trash me-1"></i> Clear
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end pt-2">
                            <button type="submit" class="btn btn-primary px-4 py-2 fw-bold shadow-sm" id="btnSaveNewWorkflow">
                                <i class="bi bi-check2-circle me-1"></i> Save Workflow Template
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Templates List -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-dark mb-0">Configured Workflow Templates (<?= count($templates) ?>)</h6>
            <span class="text-muted small">Assigned automatically to garment lots at creation</span>
        </div>

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
                    <div class="card-modern h-100 shadow-sm d-flex flex-column">
                        <div class="card-modern-header d-flex justify-content-between align-items-start pb-2">
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><?= clean($tmpl['name']) ?></h6>
                                <span class="badge bg-light text-dark border small"><?= clean($tmpl['product_type'] ?: 'General') ?></span>
                                <?php if ($tmpl['lots_count'] > 0): ?>
                                    <span class="badge bg-info-subtle text-info border small"><?= $tmpl['lots_count'] ?> lot(s)</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-1">
                                <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 btn-edit-template" 
                                        data-template='<?= htmlspecialchars(json_encode([
                                            'id' => $tmpl['id'],
                                            'name' => $tmpl['name'],
                                            'product_type' => $tmpl['product_type'],
                                            'description' => $tmpl['description'],
                                            'steps' => $steps
                                        ]), ENT_QUOTES, 'UTF-8') ?>' 
                                        title="Edit & Re-sort Workflow Sequence">
                                    <i class="bi bi-pencil-square me-1"></i> Sort / Edit
                                </button>
                                <?php if ($tmpl['lots_count'] == 0): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Delete workflow template \'<?= clean($tmpl['name']) ?>\'?');" class="d-inline">
                                        <input type="hidden" name="action" value="delete_template">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="template_id" value="<?= $tmpl['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Delete Template">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-modern-body flex-grow-1 pt-2">
                            <p class="text-muted small mb-3"><?= clean($tmpl['description'] ?: 'Sequential factory production line') ?></p>
                            
                            <div class="workflow-steps-list">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small fw-semibold text-secondary text-uppercase" style="font-size: 0.72rem;">Sequential Operations Pipeline</span>
                                    <span class="badge bg-primary-subtle text-primary"><?= count($steps) ?> Steps</span>
                                </div>
                                <ol class="list-group list-group-numbered list-group-flush small border rounded-2 bg-light">
                                    <?php foreach ($steps as $st): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 bg-transparent">
                                            <div class="ms-2 me-auto">
                                                <div class="fw-semibold text-dark"><?= clean($st['op_name']) ?></div>
                                                <span class="text-muted" style="font-size: 0.73rem;"><?= clean($st['dept_name'] ?: 'Production') ?></span>
                                            </div>
                                            <span class="badge bg-white text-dark border">
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

<!-- ========================================================================= -->
<!-- EDIT & RE-SORT WORKFLOW MODAL -->
<!-- ========================================================================= -->
<div class="modal fade" id="editWorkflowModal" tabindex="-1" aria-labelledby="editWorkflowModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="" id="editWorkflowForm">
                <input type="hidden" name="action" value="update_template">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="template_id" id="edit_template_id" value="">

                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title fw-bold" id="editWorkflowModalLabel">
                        <i class="bi bi-sort-numeric-down me-2"></i> Edit & Sort Workflow Sequence
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold">Template Name *</label>
                            <input type="text" name="name" id="edit_template_name" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Garment Type</label>
                            <input type="text" name="product_type" id="edit_template_product_type" class="form-control">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" name="description" id="edit_template_description" class="form-control">
                        </div>
                    </div>

                    <!-- Add Operation Dropdown for Modal -->
                    <div class="p-3 bg-light border rounded-3 mb-3">
                        <label class="form-label fw-semibold small mb-1">Add Operation to this Workflow:</label>
                        <div class="input-group">
                            <select id="editOpSelector" class="form-select">
                                <option value="">-- Choose Operation to Add --</option>
                                <?php foreach ($allOps as $op): ?>
                                    <option value="<?= $op['id'] ?>" 
                                            data-name="<?= htmlspecialchars($op['name'], ENT_QUOTES) ?>" 
                                            data-dept="<?= htmlspecialchars($op['dept_name'] ?: 'General', ENT_QUOTES) ?>" 
                                            data-rate="<?= number_format($op['default_rate'], 2) ?>">
                                        <?= clean($op['name']) ?> (<?= clean($op['dept_name'] ?: 'General') ?>) — Rs. <?= number_format($op['default_rate'], 2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-primary" id="btnEditAddOp">
                                <i class="bi bi-plus-lg me-1"></i> Add Step
                            </button>
                        </div>
                    </div>

                    <!-- Modal Sequenced Pipeline List -->
                    <div class="border rounded-3 p-3 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-dark small"><i class="bi bi-diagram-3 text-primary me-1"></i> Production Execution Pipeline</span>
                            <span class="badge bg-primary-subtle text-primary" id="editPipelineCount">0 Steps</span>
                        </div>
                        <p class="text-muted small mb-2" style="font-size: 0.75rem;">Click ▲ Move Up and ▼ Move Down to re-sort steps into the exact order for production.</p>
                        
                        <div id="editPipelineList" class="d-flex flex-column gap-2" style="max-height: 320px; overflow-y: auto;">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Save Updated Sequence
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Master list of all operations from PHP
    const allOperations = <?= json_encode($allOps) ?>;

    // =========================================================================
    // SEQUENCER HELPER FUNCTIONS
    // =========================================================================
    function createStepElement(opId, opName, opDept, opRate) {
        const item = document.createElement('div');
        item.className = 'pipeline-step-item border rounded-3 p-2 bg-white d-flex align-items-center justify-content-between shadow-sm';
        item.dataset.opId = opId;
        item.innerHTML = `
            <div class="d-flex align-items-center gap-2">
                <span class="step-num-badge badge bg-primary text-white rounded-pill px-2 py-1" style="font-size: 0.8rem; min-width: 26px;">1</span>
                <div>
                    <div class="fw-bold text-dark small step-title">${opName}</div>
                    <div class="text-muted" style="font-size: 0.72rem;">${opDept} &bull; Rs. ${opRate}</div>
                </div>
                <input type="hidden" name="ordered_operations[]" value="${opId}">
            </div>
            <div class="d-flex align-items-center gap-1">
                <button type="button" class="btn btn-light btn-sm border py-0 px-2 btn-step-up" title="Move Step Up" style="font-size: 0.85rem;">
                    <i class="bi bi-arrow-up text-primary"></i>
                </button>
                <button type="button" class="btn btn-light btn-sm border py-0 px-2 btn-step-down" title="Move Step Down" style="font-size: 0.85rem;">
                    <i class="bi bi-arrow-down text-primary"></i>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm border py-0 px-2 btn-step-remove ms-1" title="Remove Step" style="font-size: 0.85rem;">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;

        // Wire Up, Down, Remove buttons
        item.querySelector('.btn-step-up').addEventListener('click', function() {
            const prev = item.previousElementSibling;
            if (prev && !prev.classList.contains('pipeline-placeholder')) {
                item.parentNode.insertBefore(item, prev);
                renumberPipeline(item.parentNode);
            }
        });

        item.querySelector('.btn-step-down').addEventListener('click', function() {
            const next = item.nextElementSibling;
            if (next) {
                item.parentNode.insertBefore(next, item);
                renumberPipeline(item.parentNode);
            }
        });

        item.querySelector('.btn-step-remove').addEventListener('click', function() {
            const container = item.parentNode;
            item.remove();
            renumberPipeline(container);
        });

        return item;
    }

    function renumberPipeline(container) {
        const items = container.querySelectorAll('.pipeline-step-item');
        let count = 0;
        items.forEach((it, idx) => {
            count++;
            const badge = it.querySelector('.step-num-badge');
            if (badge) badge.textContent = (idx + 1);
        });

        // Update badge counts & empty state
        if (container.id === 'createPipelineList') {
            const emptyEl = document.getElementById('createPipelineEmpty');
            const countBadge = document.getElementById('createPipelineCount');
            if (emptyEl) emptyEl.style.display = count === 0 ? 'block' : 'none';
            if (countBadge) countBadge.textContent = count + ' Step' + (count === 1 ? '' : 's');
        } else if (container.id === 'editPipelineList') {
            const countBadge = document.getElementById('editPipelineCount');
            if (countBadge) countBadge.textContent = count + ' Step' + (count === 1 ? '' : 's');
        }
    }

    // =========================================================================
    // 1. CREATE WORKFLOW BUILDER LOGIC
    // =========================================================================
    const createPipelineList = document.getElementById('createPipelineList');
    
    // Add single operation to create pipeline
    document.querySelectorAll('.btn-add-op').forEach(btn => {
        btn.addEventListener('click', function() {
            const opId = this.dataset.id;
            const opName = this.dataset.name;
            const opDept = this.dataset.dept;
            const opRate = this.dataset.rate;

            // Check if already in pipeline
            const existing = createPipelineList.querySelector(`.pipeline-step-item[data-op-id="${opId}"]`);
            if (existing) {
                // Highlight existing
                existing.classList.add('border-warning');
                setTimeout(() => existing.classList.remove('border-warning'), 800);
                return;
            }

            const stepEl = createStepElement(opId, opName, opDept, opRate);
            createPipelineList.appendChild(stepEl);
            renumberPipeline(createPipelineList);
        });
    });

    // Add all operations in default sequence
    const btnAddAllOps = document.getElementById('btnAddAllOps');
    if (btnAddAllOps) {
        btnAddAllOps.addEventListener('click', function() {
            allOperations.forEach(op => {
                const existing = createPipelineList.querySelector(`.pipeline-step-item[data-op-id="${op.id}"]`);
                if (!existing) {
                    const stepEl = createStepElement(op.id, op.name, op.dept_name || 'General', parseFloat(op.default_rate).toFixed(2));
                    createPipelineList.appendChild(stepEl);
                }
            });
            renumberPipeline(createPipelineList);
        });
    }

    // Clear create pipeline
    const btnClearPipeline = document.getElementById('btnClearPipeline');
    if (btnClearPipeline) {
        btnClearPipeline.addEventListener('click', function() {
            createPipelineList.querySelectorAll('.pipeline-step-item').forEach(it => it.remove());
            renumberPipeline(createPipelineList);
        });
    }

    // Validate create form submission
    document.getElementById('createWorkflowForm').addEventListener('submit', function(e) {
        const count = createPipelineList.querySelectorAll('.pipeline-step-item').length;
        if (count === 0) {
            e.preventDefault();
            alert('Please add at least one operation to the execution sequence pipeline.');
        }
    });

    // =========================================================================
    // 2. EDIT / RE-SORT WORKFLOW MODAL LOGIC
    // =========================================================================
    const editModalEl = document.getElementById('editWorkflowModal');
    const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;
    const editPipelineList = document.getElementById('editPipelineList');
    const editOpSelector = document.getElementById('editOpSelector');
    const btnEditAddOp = document.getElementById('btnEditAddOp');

    document.querySelectorAll('.btn-edit-template').forEach(btn => {
        btn.addEventListener('click', function() {
            const data = JSON.parse(this.dataset.template);
            document.getElementById('edit_template_id').value = data.id;
            document.getElementById('edit_template_name').value = data.name;
            document.getElementById('edit_template_product_type').value = data.product_type || '';
            document.getElementById('edit_template_description').value = data.description || '';

            // Populate existing steps in sequence
            editPipelineList.innerHTML = '';
            if (data.steps && data.steps.length > 0) {
                data.steps.forEach(st => {
                    const stepEl = createStepElement(st.operation_id, st.op_name, st.dept_name || 'General', parseFloat(st.default_rate).toFixed(2));
                    editPipelineList.appendChild(stepEl);
                });
            }
            renumberPipeline(editPipelineList);

            if (editModal) editModal.show();
        });
    });

    // Add operation from dropdown in Edit Modal
    if (btnEditAddOp) {
        btnEditAddOp.addEventListener('click', function() {
            const opt = editOpSelector.options[editOpSelector.selectedIndex];
            if (!opt || !opt.value) return;

            const opId = opt.value;
            const opName = opt.dataset.name;
            const opDept = opt.dataset.dept;
            const opRate = opt.dataset.rate;

            const existing = editPipelineList.querySelector(`.pipeline-step-item[data-op-id="${opId}"]`);
            if (existing) {
                existing.classList.add('border-warning');
                setTimeout(() => existing.classList.remove('border-warning'), 800);
                return;
            }

            const stepEl = createStepElement(opId, opName, opDept, opRate);
            editPipelineList.appendChild(stepEl);
            renumberPipeline(editPipelineList);
            editOpSelector.value = '';
        });
    }

    // Validate edit form submission
    document.getElementById('editWorkflowForm').addEventListener('submit', function(e) {
        const count = editPipelineList.querySelectorAll('.pipeline-step-item').length;
        if (count === 0) {
            e.preventDefault();
            alert('Please include at least one operation in the execution sequence pipeline.');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
