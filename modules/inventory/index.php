<?php
/**
 * Garment Manufacturing ERP - Finished Goods Inventory Dashboard
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Finished Goods Inventory';
$activeNav = 'finished_goods';
$db = get_db();

// Filters
$prodFilter = (int)($_GET['product_id'] ?? 0);
$lotFilter = (int)($_GET['lot_id'] ?? 0);
$whFilter = (int)($_GET['warehouse_id'] ?? 0);

$sql = "SELECT fg.*, p.name AS product_name, p.code AS product_code, d.design_number, 
               c.name AS color_name, c.hex_code, s.name AS size_name, s.code AS size_code,
               l.lot_number, wh.name AS warehouse_name 
        FROM finished_goods_stock fg 
        JOIN products p ON fg.product_id = p.id 
        LEFT JOIN designs d ON fg.design_id = d.id 
        JOIN colors c ON fg.color_id = c.id 
        JOIN sizes s ON fg.size_id = s.id 
        JOIN lots l ON fg.lot_id = l.id 
        JOIN warehouses wh ON fg.warehouse_id = wh.id 
        WHERE 1=1";

$params = [];
if ($prodFilter > 0) {
    $sql .= " AND fg.product_id = ?";
    $params[] = $prodFilter;
}
if ($lotFilter > 0) {
    $sql .= " AND fg.lot_id = ?";
    $params[] = $lotFilter;
}
if ($whFilter > 0) {
    $sql .= " AND fg.warehouse_id = ?";
    $params[] = $whFilter;
}

$sql .= " ORDER BY p.name ASC, c.name ASC, s.sort_order ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$stocks = $stmt->fetchAll();

$totalAvailable = 0;
$totalDispatched = 0;
foreach ($stocks as $s) {
    $totalAvailable += (int)$s['current_stock'];
    $totalDispatched += (int)$s['dispatched_stock'];
}

$products = $db->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll();
$warehouses = $db->query("SELECT id, name FROM warehouses ORDER BY name ASC")->fetchAll();
$lots = $db->query("SELECT id, lot_number FROM lots ORDER BY id DESC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Finished Goods Inventory</h4>
                <p class="text-muted small mb-0">Variant-wise stock traceable to originating production batch, color and size</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/inventory/ledger.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-clock-history"></i>
                    <span>Stock Movement Ledger</span>
                </a>
                <a href="<?= BASE_URL ?>modules/dispatch/create.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-truck me-1"></i>
                    <span>Create Customer Dispatch</span>
                </a>
            </div>
        </div>

        <!-- Metrics Row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-sm">
                    <div class="text-muted small text-uppercase fw-semibold">Available Ready Stock</div>
                    <div class="fs-3 fw-bold text-success"><?= number_format($totalAvailable) ?> <span class="fs-6 fw-normal text-muted">pcs</span></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-sm">
                    <div class="text-muted small text-uppercase fw-semibold">Dispatched Goods</div>
                    <div class="fs-3 fw-bold text-dark"><?= number_format($totalDispatched) ?> <span class="fs-6 fw-normal text-muted">pcs</span></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-sm">
                    <div class="text-muted small text-uppercase fw-semibold">Active Product Lines</div>
                    <div class="fs-3 fw-bold text-primary"><?= count($products) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 bg-white border rounded-3 shadow-sm">
                    <div class="text-muted small text-uppercase fw-semibold">Warehouse Locations</div>
                    <div class="fs-3 fw-bold text-secondary"><?= count($warehouses) ?></div>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card-modern mb-4">
            <div class="card-modern-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-12 col-md-3">
                        <select name="product_id" class="form-select form-select-sm">
                            <option value="">-- All Products --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $prodFilter === $p['id'] ? 'selected' : '' ?>><?= clean($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="lot_id" class="form-select form-select-sm">
                            <option value="">-- All Lots --</option>
                            <?php foreach ($lots as $l): ?>
                                <option value="<?= $l['id'] ?>" <?= $lotFilter === $l['id'] ? 'selected' : '' ?>>LOT #<?= clean($l['lot_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="warehouse_id" class="form-select form-select-sm">
                            <option value="">-- All Warehouses --</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $whFilter === $wh['id'] ? 'selected' : '' ?>><?= clean($wh['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-secondary w-100">Filter Stock</button>
                        <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stock Table -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Finished Products Inventory Matrix (<?= count($stocks) ?> Items)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Design #</th>
                                <th>Color</th>
                                <th>Size</th>
                                <th>Originating Batch</th>
                                <th>Warehouse Location</th>
                                <th class="text-end">Available Stock</th>
                                <th class="text-end">Dispatched</th>
                                <th class="text-end">Quick Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stocks)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No finished goods in stock. Once lots are packed, finished stock appears here automatically.</td></tr>
                            <?php else: ?>
                                <?php foreach ($stocks as $s): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold text-dark"><?= clean($s['product_name']) ?></span>
                                            <div class="text-muted small"><?= clean($s['product_code']) ?></div>
                                        </td>
                                        <td>
                                            <?= $s['design_number'] ? '<span class="fw-semibold text-primary">#' . clean($s['design_number']) . '</span>' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td>
                                            <span class="d-inline-block rounded-circle border me-1 align-middle" style="width: 14px; height: 14px; background-color: <?= clean($s['hex_code']) ?>;"></span>
                                            <?= clean($s['color_name']) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= clean($s['size_name']) ?> (<?= clean($s['size_code']) ?>)</span>
                                        </td>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $s['lot_id'] ?>" class="fw-semibold text-decoration-none">
                                                LOT #<?= clean($s['lot_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= clean($s['warehouse_name']) ?></td>
                                        <td class="text-end fw-bold fs-6 text-success">
                                            <?= number_format($s['current_stock']) ?> pcs
                                        </td>
                                        <td class="text-end text-muted fw-semibold">
                                            <?= number_format($s['dispatched_stock']) ?> pcs
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>modules/dispatch/create.php?lot_id=<?= $s['lot_id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2">
                                                <i class="bi bi-truck me-1"></i> Dispatch
                                            </a>
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
