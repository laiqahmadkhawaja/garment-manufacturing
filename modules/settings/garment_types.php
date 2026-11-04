<?php
/**
 * Garment Manufacturing ERP - Dynamic Garment Types (Cutting Variations)
 * Configurable list of styles for the Cutting Master Section (e.g. Jersey, Coat, Full Zip, Gold Gala, etc.)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Garment Types & Cutting Styles';
$activeNav = 'settings_garment_types';
$db = get_db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_garment_type') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('danger', 'Garment type name is required.');
        } else {
            $stmt = $db->prepare("INSERT INTO garment_types (name, code, sort_order, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$name, $code ?: strtoupper(str_replace(' ', '_', $name)), $sortOrder]);
            log_audit('CREATE', 'garment_types', (int)$db->lastInsertId(), null, ['name' => $name, 'code' => $code]);
            redirect('modules/settings/garment_types.php', "Garment style '$name' added successfully!");
        }
    }

    if ($action === 'update_garment_type') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0 && !empty($name)) {
            $stmt = $db->prepare("UPDATE garment_types SET name = ?, code = ?, sort_order = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $code, $sortOrder, $isActive, $id]);
            log_audit('UPDATE', 'garment_types', $id, null, ['name' => $name, 'code' => $code, 'is_active' => $isActive]);
            redirect('modules/settings/garment_types.php', "Garment style '$name' updated successfully!");
        }
    }

    if ($action === 'delete_garment_type') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM garment_types WHERE id = ?");
            $stmt->execute([$id]);
            log_audit('DELETE', 'garment_types', $id, null, []);
            redirect('modules/settings/garment_types.php', 'Garment style removed.');
        }
    }
}

// Fetch all garment types
$garmentTypes = $db->query("SELECT * FROM garment_types ORDER BY sort_order ASC, id ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-scissors text-primary me-2"></i>Garment Types & Cutting Styles (کٹائی کی اقسام)
                </h4>
                <p class="text-muted small mb-0">Manage configurable garment styles displayed on the printed Lot Design Sheet and Job Card (Jersey, Coat, Full Zip, Gold Gala, etc.)</p>
            </div>
            <button type="button" class="btn btn-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAddGarmentType">
                <i class="bi bi-plus-circle me-1"></i> Add New Style (+ نئی قسم)
            </button>
        </div>

        <div class="row g-4">
            <!-- Left: Garment Types List -->
            <div class="col-12 col-lg-8">
                <div class="card-modern shadow-sm">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-list-columns-reverse text-primary me-1"></i> Active Cutting Variations (<?= count($garmentTypes) ?>)
                        </h6>
                        <span class="badge bg-light text-secondary border">Rendered dynamically on Cutting Master Grid</span>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 60px;" class="text-center">Order</th>
                                        <th>Style / Variation Name (قسم کا نام)</th>
                                        <th>Short Code</th>
                                        <th class="text-center" style="width: 100px;">Status</th>
                                        <th class="text-end" style="width: 120px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($garmentTypes)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">No garment types configured yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($garmentTypes as $gt): ?>
                                            <tr>
                                                <td class="text-center fw-bold text-muted"><?= (int)$gt['sort_order'] ?></td>
                                                <td>
                                                    <span class="fw-bold text-dark fs-6"><?= clean($gt['name']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-secondary border font-monospace"><?= clean($gt['code']) ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($gt['is_active']): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 btn-edit-gt" 
                                                            data-id="<?= $gt['id'] ?>"
                                                            data-name="<?= clean($gt['name']) ?>"
                                                            data-code="<?= clean($gt['code']) ?>"
                                                            data-order="<?= (int)$gt['sort_order'] ?>"
                                                            data-active="<?= (int)$gt['is_active'] ?>"
                                                            title="Edit Style">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('Delete this garment type?');">
                                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="action" value="delete_garment_type">
                                                        <input type="hidden" name="id" value="<?= $gt['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
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

            <!-- Right: Preview on Physical Form -->
            <div class="col-12 col-lg-4">
                <div class="card-modern shadow-sm h-100">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-file-earmark-text-fill text-warning me-1"></i> Preview on Lot Design Sheet
                        </h6>
                    </div>
                    <div class="card-modern-body">
                        <p class="small text-muted mb-3">
                            These styles automatically appear as columns in the <strong>Cutting Master (کٹائی ماسٹر)</strong> section of your 4-copy printout:
                        </p>
                        <div class="p-3 bg-light border rounded-3 text-center">
                            <div class="fw-bold small text-dark mb-2">کٹائی ماسٹر گرڈ (Cutting Master Columns)</div>
                            <div class="d-flex flex-wrap gap-1 justify-content-center">
                                <?php foreach ($garmentTypes as $gt): ?>
                                    <?php if ($gt['is_active']): ?>
                                        <span class="badge bg-white text-dark border px-2 py-1 shadow-sm">
                                            <?= clean($gt['name']) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="alert alert-info py-2 small mt-3 mb-0">
                            <i class="bi bi-info-circle-fill me-1"></i> Never hardcoded. When you add a new style here, it immediately reflects across all cutting logs and print templates.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- end app-content -->

<!-- Modal: Add Garment Type -->
<div class="modal fade" id="modalAddGarmentType" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create_garment_type">
                
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-plus-circle me-1"></i> Add New Garment Style (نئی کٹائی قسم)
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Style Name (قسم کا نام) *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. فل زپ گول گلا or V-Neck Coat" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Short Code</label>
                        <input type="text" name="code" class="form-control" placeholder="e.g. FULL_ZIP_GG">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Display Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= count($garmentTypes) + 1 ?>" min="1">
                    </div>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold">Save Style</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Garment Type -->
<div class="modal fade" id="modalEditGarmentType" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_garment_type">
                <input type="hidden" name="id" id="editGtId" value="">
                
                <div class="modal-header bg-dark text-white">
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-pencil me-1"></i> Edit Garment Style
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Style Name (قسم کا نام) *</label>
                        <input type="text" name="name" id="editGtName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Short Code</label>
                        <input type="text" name="code" id="editGtCode" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Display Sort Order</label>
                        <input type="number" name="sort_order" id="editGtOrder" class="form-control" min="1">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="editGtActive" value="1">
                        <label class="form-check-label fw-semibold small" for="editGtActive">Active & Display on Cutting Grids</label>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold">Update Style</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editModal = new bootstrap.Modal(document.getElementById('modalEditGarmentType'));
    document.querySelectorAll('.btn-edit-gt').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('editGtId').value = this.dataset.id;
            document.getElementById('editGtName').value = this.dataset.name;
            document.getElementById('editGtCode').value = this.dataset.code;
            document.getElementById('editGtOrder').value = this.dataset.order;
            document.getElementById('editGtActive').checked = (this.dataset.active == '1');
            editModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
