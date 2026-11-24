<?php
/**
 * Garment Manufacturing ERP - Dynamic Warehouses Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Warehouses Management';
$activeNav = 'settings_wh';
$db = get_db();

$error = '';
$success = '';

// Handle Actions: Create, Edit, Toggle Status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_warehouse') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($code) || empty($name)) {
            $error = 'Warehouse Code and Name are required.';
        } else {
            // Check unique code
            $chk = $db->prepare("SELECT id FROM warehouses WHERE code = ?");
            $chk->execute([$code]);
            if ($chk->fetch()) {
                $error = "Warehouse code '$code' already exists. Choose a unique code.";
            } else {
                $stmt = $db->prepare("INSERT INTO warehouses (code, name, location, is_active) VALUES (?, ?, ?, ?)");
                $stmt->execute([$code, $name, $location, $isActive]);
                $whId = (int)$db->lastInsertId();
                log_audit('CREATE_WAREHOUSE', 'warehouses', $whId, null, ['code' => $code, 'name' => $name, 'location' => $location]);
                redirect('modules/settings/warehouses.php', "Warehouse '$name' ($code) created successfully!");
            }
        }
    }

    if ($action === 'update_warehouse') {
        $id = (int)($_POST['warehouse_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || empty($name)) {
            $error = 'Valid Warehouse ID and Name are required.';
        } else {
            $up = $db->prepare("UPDATE warehouses SET name = ?, location = ?, is_active = ? WHERE id = ?");
            $up->execute([$name, $location, $isActive, $id]);
            log_audit('UPDATE_WAREHOUSE', 'warehouses', $id, null, ['name' => $name, 'location' => $location, 'is_active' => $isActive]);
            redirect('modules/settings/warehouses.php', "Warehouse '$name' updated successfully!");
        }
    }
}

// Fetch all warehouses with live finished stock and dispatch counts
$warehouses = $db->query("SELECT wh.*,
                                 COALESCE((SELECT SUM(current_stock) FROM finished_goods_stock WHERE warehouse_id = wh.id), 0) AS finished_stock_pcs,
                                 COALESCE((SELECT COUNT(*) FROM dispatches WHERE warehouse_id = wh.id), 0) AS dispatch_count,
                                 COALESCE((SELECT COUNT(*) FROM warehouse_transfers WHERE from_warehouse_id = wh.id OR to_warehouse_id = wh.id), 0) AS transfer_count
                          FROM warehouses wh 
                          ORDER BY wh.id ASC")->fetchAll();

$totalStockPcs = (int)$db->query("SELECT COALESCE(SUM(current_stock), 0) FROM finished_goods_stock")->fetchColumn();
$totalTransfers = (int)$db->query("SELECT COUNT(*) FROM warehouse_transfers")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Warehouses & Storage Locations</h4>
                <p class="text-muted small mb-0">Configure dynamic factory warehouses, storage sections, finished goods depots & branch locations (گودام و اسٹوریج کا نظام)</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/inventory/transfer.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-arrow-left-right"></i>
                    <span>Transfer Finished Goods</span>
                </a>
                <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAddWarehouse">
                    <i class="bi bi-plus-circle me-1"></i>
                    <span>Add New Warehouse</span>
                </button>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Summary Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-xs">
                    <div class="text-muted small fw-semibold text-uppercase">Configured Warehouses</div>
                    <div class="fs-3 fw-bold text-dark"><?= count($warehouses) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-xs">
                    <div class="text-muted small fw-semibold text-uppercase">Finished Goods In Stock</div>
                    <div class="fs-3 fw-bold text-success"><?= number_format($totalStockPcs) ?> <span class="fs-6 fw-normal text-muted">pcs</span></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-xs">
                    <div class="text-muted small fw-semibold text-uppercase">Warehouse Transfers Done</div>
                    <div class="fs-3 fw-bold text-primary"><?= number_format($totalTransfers) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-xs">
                    <div class="text-muted small fw-semibold text-uppercase">Active Locations</div>
                    <div class="fs-3 fw-bold text-info"><?= count(array_filter($warehouses, fn($w) => $w['is_active'] == 1)) ?></div>
                </div>
            </div>
        </div>

        <!-- Warehouses Table -->
        <div class="card-modern shadow-sm border-0 mb-4">
            <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-building text-primary me-2"></i> Factory Storage Locations & Depots (<?= count($warehouses) ?>)
                </h6>
                <span class="text-muted small">پیکنگ اور کسٹمر ڈسپیچ کے دوران ان میں سے کسی بھی گودام کا انتخاب کیا جا سکتا ہے</span>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 70px;">ID</th>
                                <th style="width: 140px;">Code</th>
                                <th>Warehouse Name</th>
                                <th>Physical Location / Address</th>
                                <th class="text-center" style="width: 140px;">Finished Stock</th>
                                <th class="text-center" style="width: 120px;">Dispatches</th>
                                <th class="text-center" style="width: 100px;">Status</th>
                                <th class="text-end" style="width: 160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($warehouses)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <div class="mb-2"><i class="bi bi-building-slash fs-1 text-secondary opacity-50"></i></div>
                                        <div class="fw-semibold text-dark">No warehouses or storage locations found (کوئی گودام موجود نہیں)</div>
                                        <div class="small text-muted mb-3">Add factory floors, finished goods stores, or branch depots to start managing inventory.</div>
                                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAddWarehouse">
                                            <i class="bi bi-plus-circle me-1"></i> Add New Warehouse
                                        </button>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($warehouses as $wh): ?>
                                    <tr>
                                        <td class="text-muted fw-bold">#<?= $wh['id'] ?></td>
                                        <td>
                                            <span class="badge bg-light text-primary border fw-bold fs-6">
                                                <?= clean($wh['code']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark fs-6"><?= clean($wh['name']) ?></div>
                                        </td>
                                        <td>
                                            <span class="text-secondary"><?= clean($wh['location'] ?: 'Factory Main Premises') ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $wh['finished_stock_pcs'] > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-light text-muted border' ?> fw-bold fs-6 px-2 py-1">
                                                <?= number_format($wh['finished_stock_pcs']) ?> pcs
                                            </span>
                                        </td>
                                        <td class="text-center text-muted fw-semibold">
                                            <?= number_format($wh['dispatch_count']) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($wh['is_active']): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border fw-semibold">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="<?= BASE_URL ?>modules/inventory/index.php?warehouse_id=<?= $wh['id'] ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" title="View Finished Stock">
                                                    <i class="bi bi-box-seam me-1"></i> Stock
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 btn-edit-wh"
                                                        data-id="<?= $wh['id'] ?>"
                                                        data-code="<?= clean($wh['code']) ?>"
                                                        data-name="<?= clean($wh['name']) ?>"
                                                        data-location="<?= clean($wh['location']) ?>"
                                                        data-active="<?= $wh['is_active'] ?>"
                                                        title="Edit Warehouse">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </div>
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

<!-- Modal: Add New Warehouse -->
<div class="modal fade" id="modalAddWarehouse" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="" class="modal-content">
            <input type="hidden" name="action" value="create_warehouse">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="modal-header">
                <h6 class="modal-title fw-bold text-dark">
                    <i class="bi bi-building text-primary me-2"></i> Add New Factory Warehouse / Depot
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Warehouse Code *</label>
                    <input type="text" name="code" class="form-control text-uppercase fw-bold text-primary" placeholder="e.g. WH-SHOWROOM or WH-DEPOT4" required>
                    <div class="form-text small">Unique code for identification across inventory movements.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Warehouse Name *</label>
                    <input type="text" name="name" class="form-control fw-bold" placeholder="e.g. City Showroom Depot (شہر کا شوروم)" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Location / Floor / Address</label>
                    <input type="text" name="location" class="form-control" placeholder="e.g. Building B, 2nd Floor / Anarkali Market Lahore">
                </div>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="is_active" id="add_is_active" value="1" checked>
                    <label class="form-check-label fw-semibold text-dark small" for="add_is_active">Active Warehouse (فعال گودام)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold shadow-sm">Save Warehouse</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Warehouse -->
<div class="modal fade" id="modalEditWarehouse" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="" class="modal-content">
            <input type="hidden" name="action" value="update_warehouse">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="warehouse_id" id="edit_wh_id">
            <div class="modal-header">
                <h6 class="modal-title fw-bold text-dark">
                    <i class="bi bi-pencil-square text-primary me-2"></i> Edit Warehouse Details
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Warehouse Code</label>
                    <input type="text" id="edit_wh_code" class="form-control text-uppercase fw-bold text-primary bg-light" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Warehouse Name *</label>
                    <input type="text" name="name" id="edit_wh_name" class="form-control fw-bold" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Location / Address</label>
                    <input type="text" name="location" id="edit_wh_location" class="form-control">
                </div>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                    <label class="form-check-label fw-semibold text-dark small" for="edit_is_active">Active Warehouse (فعال گودام)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold shadow-sm">Update Warehouse</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editButtons = document.querySelectorAll('.btn-edit-wh');
    const modalEl = document.getElementById('modalEditWarehouse');
    let editModal = null;
    if (modalEl) {
        editModal = new bootstrap.Modal(modalEl);
    }

    editButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_wh_id').value = this.dataset.id;
            document.getElementById('edit_wh_code').value = this.dataset.code;
            document.getElementById('edit_wh_name').value = this.dataset.name;
            document.getElementById('edit_wh_location').value = this.dataset.location || '';
            document.getElementById('edit_is_active').checked = (this.dataset.active == '1');
            if (editModal) editModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
