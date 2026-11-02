<?php
/**
 * Garment Manufacturing ERP - All Production Lots
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Production Lots';
$activeNav = 'lots';
$db = get_db();

// Filter parameters
$statusFilter = trim($_GET['status'] ?? '');
$productFilter = (int)($_GET['product_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$sql = "SELECT l.*, p.name AS product_name, p.code AS product_code, d.design_number, d.name AS design_name,
        (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = l.id) AS completed_good,
        (SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = l.id) AS total_packed,
        (SELECT COALESCE(SUM(quantity), 0) FROM dispatch_items WHERE lot_id = l.id) AS total_dispatched
        FROM lots l
        JOIN products p ON l.product_id = p.id
        LEFT JOIN designs d ON l.design_id = d.id
        WHERE 1=1";

$params = [];
if (!empty($statusFilter)) {
    $sql .= " AND l.status = ?";
    $params[] = $statusFilter;
}
if ($productFilter > 0) {
    $sql .= " AND l.product_id = ?";
    $params[] = $productFilter;
}
if (!empty($search)) {
    $sql .= " AND (l.lot_number LIKE ? OR l.instructions LIKE ? OR d.design_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY l.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$lots = $stmt->fetchAll();

$products = $db->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production Lots Master</h4>
                <p class="text-muted small mb-0">Manage all manufacturing batches and stage progress</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/lots/create.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-plus-lg"></i>
                    <span>Create New Lot</span>
                </a>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card-modern mb-4">
            <div class="card-modern-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-12 col-sm-4 col-md-3">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Lot # or Design..." value="<?= clean($search) ?>">
                    </div>
                    <div class="col-6 col-sm-4 col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">-- All Statuses --</option>
                            <option value="Draft" <?= $statusFilter === 'Draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="In Production" <?= $statusFilter === 'In Production' ? 'selected' : '' ?>>In Production</option>
                            <option value="Production Completed" <?= $statusFilter === 'Production Completed' ? 'selected' : '' ?>>Production Completed</option>
                            <option value="Partially Packed" <?= $statusFilter === 'Partially Packed' ? 'selected' : '' ?>>Partially Packed</option>
                            <option value="Ready Stock" <?= $statusFilter === 'Ready Stock' ? 'selected' : '' ?>>Ready Stock</option>
                            <option value="Partially Dispatched" <?= $statusFilter === 'Partially Dispatched' ? 'selected' : '' ?>>Partially Dispatched</option>
                            <option value="Dispatched" <?= $statusFilter === 'Dispatched' ? 'selected' : '' ?>>Dispatched</option>
                            <option value="Closed" <?= $statusFilter === 'Closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <div class="col-6 col-sm-4 col-md-3">
                        <select name="product_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $productFilter == $p['id'] ? 'selected' : '' ?>><?= clean($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-secondary w-100">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <a href="<?= BASE_URL ?>modules/lots/index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lots Table -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Production Batches (<?= count($lots) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Lot Number</th>
                                <th>Product & Design</th>
                                <th>Supervisor</th>
                                <th class="text-center">Total Pcs</th>
                                <th class="text-center">Packed</th>
                                <th class="text-center">Dispatched</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lots)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        No production lots found matching criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lots as $l): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $l['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                <?= clean($l['lot_number']) ?>
                                            </a>
                                            <div class="text-muted small">Date: <?= format_date($l['lot_date']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= clean($l['product_name']) ?></div>
                                            <div class="text-muted small">
                                                <?= $l['design_number'] ? 'Design: #' . clean($l['design_number']) : '<span class="text-warning fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>Design Pending</span>' ?>
                                            </div>
                                        </td>
                                        <td><?= clean($l['supervisor_name'] ?: '-') ?></td>
                                        <td class="text-center fw-bold fs-6 text-dark">
                                            <?= number_format($l['total_pieces']) ?>
                                            <div class="text-muted small" style="font-size: 0.75rem;"><?= $l['total_colors'] ?> Clr · <?= $l['total_sizes'] ?> Sz</div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border">
                                                <?= number_format($l['total_packed']) ?> / <?= number_format($l['total_pieces']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border">
                                                <?= number_format($l['total_dispatched']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $st = $l['status'];
                                            $badgeClass = match($st) {
                                                'Draft' => 'badge-draft',
                                                'In Production' => 'badge-progress',
                                                'Production Completed' => 'badge-completed',
                                                'Partially Packed' => 'badge-pending',
                                                'Ready Stock' => 'badge-ready',
                                                'Partially Dispatched' => 'badge-pending',
                                                'Dispatched' => 'badge-dispatched',
                                                'Closed' => 'badge-draft',
                                                default => 'badge-draft'
                                            };
                                            ?>
                                            <span class="badge-status <?= $badgeClass ?>">
                                                <?= clean($st) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="Lot Details & Tracking">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="<?= BASE_URL ?>modules/production/index.php?lot_id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-warning py-1 px-2" title="Enter Production Work">
                                                    <i class="bi bi-lightning-charge"></i>
                                                </a>
                                                <a href="<?= BASE_URL ?>modules/lots/print_job_sheet.php?id=<?= $l['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Print Job Sheet">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <a href="<?= BASE_URL ?>modules/lots/edit.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Edit Lot / Add Design">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
