<?php
/**
 * Garment Manufacturing ERP - Raw Materials Stock & Registration
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Raw Materials Stock';
$activeNav = 'raw_materials';
$db = get_db();

// Handle Form Submissions: Create Material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_material') {
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? 'Yarn');
    $unitId = (int)($_POST['unit_id'] ?? 1);
    $minStock = (float)($_POST['min_stock'] ?? 10.00);
    $openingStock = (float)($_POST['opening_stock'] ?? 0.00);
    $costPrice = (float)($_POST['cost_price'] ?? 0.00);
    $supplierId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $location = trim($_POST['storage_location'] ?? '');

    if (empty($code) || empty($name)) {
        set_flash('danger', 'Material code and name are required.');
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO materials 
                (code, name, category, unit_id, min_stock, opening_stock, cost_price, current_stock, supplier_id, storage_location, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([
                $code,
                $name,
                $category,
                $unitId,
                $minStock,
                $openingStock,
                $costPrice,
                $openingStock, // initial current stock
                $supplierId,
                $location
            ]);
            $matId = (int)$db->lastInsertId();

            // Record initial opening stock in inventory transactions
            if ($openingStock > 0) {
                $tx = $db->prepare("INSERT INTO inventory_transactions 
                    (transaction_date, item_type, material_id, warehouse_id, transaction_type, quantity_in, unit_cost, reference_type, remarks, created_by) 
                    VALUES (CURRENT_DATE(), 'Material', ?, 2, 'Purchase', ?, ?, 'OPENING_STOCK', 'Initial opening stock', ?)");
                $tx->execute([$matId, $openingStock, $costPrice, $_SESSION['user_id'] ?? null]);
            }

            log_audit('CREATE_MATERIAL', 'materials', $matId, null, ['code' => $code, 'name' => $name]);
            $db->commit();
            redirect('modules/raw_materials/index.php', "Material '$name' added successfully!");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error adding material: ' . $e->getMessage());
        }
    }
}

// Filters
$categoryFilter = trim($_GET['category'] ?? '');
$filterLow = ($_GET['filter'] ?? '') === 'low_stock';

$sql = "SELECT m.*, u.code AS unit_code, u.name AS unit_name, s.name AS supplier_name 
        FROM materials m 
        JOIN units u ON m.unit_id = u.id 
        LEFT JOIN suppliers s ON m.supplier_id = s.id 
        WHERE m.is_active = 1";

$params = [];
if (!empty($categoryFilter)) {
    $sql .= " AND m.category = ?";
    $params[] = $categoryFilter;
}
if ($filterLow) {
    $sql .= " AND m.current_stock <= m.min_stock";
}

$sql .= " ORDER BY m.category ASC, m.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$materials = $stmt->fetchAll();

$units = $db->query("SELECT * FROM units ORDER BY name ASC")->fetchAll();
$suppliers = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$categories = ['Yarn', 'Fabric', 'Thread', 'Buttons', 'Zips', 'Labels', 'Packaging Material', 'Accessories', 'Chemicals'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Raw Materials Inventory</h4>
                <p class="text-muted small mb-0">Fabric, yarns, accessories stock levels, purchase costs & minimum stock alerts</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/raw_materials/purchases.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-cart-plus"></i>
                    <span>Purchase Stock</span>
                </a>
                <a href="<?= BASE_URL ?>modules/raw_materials/issues.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Issue to Lot</span>
                </a>
            </div>
        </div>

        <!-- Add Material Inline Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Register New Raw Material</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_material">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-2">
                            <label class="form-label">Material Code *</label>
                            <input type="text" name="code" class="form-control text-uppercase" placeholder="MAT-YARN-02" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Material Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. 100% Cotton Combed Yarn" required>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat ?>"><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Stock Unit *</label>
                            <select name="unit_id" class="form-select" required>
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= clean($u['name']) ?> (<?= clean($u['code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">-- Direct Market / None --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= clean($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-label">Opening Stock</label>
                            <input type="number" name="opening_stock" class="form-control" step="0.01" value="0.00">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Min Stock Alert</label>
                            <input type="number" name="min_stock" class="form-control" step="0.01" value="10.00">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Cost Price (Rs.)</label>
                            <input type="number" name="cost_price" class="form-control" step="0.01" value="0.00">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Storage Location / Rack</label>
                            <input type="text" name="storage_location" class="form-control" placeholder="Rack Y-3, Shelf 2">
                        </div>
                        <div class="col-12 col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100 fw-semibold">Save Material</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Materials Table -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Raw Materials Catalog (<?= count($materials) ?>)</h6>
                <div class="d-flex gap-2">
                    <?php if ($filterLow): ?>
                        <a href="<?= BASE_URL ?>modules/raw_materials/index.php" class="btn btn-sm btn-outline-secondary">Show All Stock</a>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>modules/raw_materials/index.php?filter=low_stock" class="btn btn-sm btn-outline-danger">
                            <span class="pulse-indicator me-1"></span> Filter Low Stock
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Material Name</th>
                                <th>Category</th>
                                <th class="text-end">Current Stock</th>
                                <th class="text-end">Min Stock</th>
                                <th class="text-end">Unit Cost</th>
                                <th class="text-end">Total Valuation</th>
                                <th>Supplier / Rack</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($materials)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No materials found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($materials as $m): 
                                    $curr = (float)$m['current_stock'];
                                    $min = (float)$m['min_stock'];
                                    $cost = (float)$m['cost_price'];
                                    $val = $curr * $cost;
                                    $isLow = ($curr <= $min);
                                ?>
                                    <tr class="<?= $isLow ? 'table-warning bg-opacity-25' : '' ?>">
                                        <td class="fw-bold text-primary"><?= clean($m['code']) ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= clean($m['name']) ?></div>
                                            <div class="text-muted small">Unit: <?= clean($m['unit_name']) ?></div>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= clean($m['category']) ?></span></td>
                                        <td class="text-end fw-bold fs-6 <?= $isLow ? 'text-danger' : 'text-success' ?>">
                                            <?php if ($isLow): ?><span class="pulse-indicator me-1"></span><?php endif; ?>
                                            <?= number_format($curr, 2) ?> <?= clean($m['unit_code']) ?>
                                        </td>
                                        <td class="text-end text-muted"><?= number_format($min, 2) ?> <?= clean($m['unit_code']) ?></td>
                                        <td class="text-end">Rs. <?= number_format($cost, 2) ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency($val) ?></td>
                                        <td>
                                            <div class="small fw-semibold"><?= clean($m['supplier_name'] ?: 'Local') ?></div>
                                            <div class="text-muted small"><?= clean($m['storage_location'] ?: '-') ?></div>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>modules/raw_materials/issues.php?material_id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="Issue to Production Lot">
                                                <i class="bi bi-box-arrow-right"></i> Issue
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
