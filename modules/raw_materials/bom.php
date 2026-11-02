<?php
/**
 * Garment Manufacturing ERP - Bill of Materials (BOM)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Bill of Materials (BOM)';
$activeNav = 'raw_materials';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_bom') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $designId = !empty($_POST['design_id']) ? (int)$_POST['design_id'] : null;
    $desc = trim($_POST['description'] ?? '');
    $materials = $_POST['materials'] ?? []; // material_id => qty_per_piece

    if ($productId <= 0 || empty($materials)) {
        set_flash('danger', 'Please choose a product and enter requirements for at least one raw material.');
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO bom_headers (product_id, design_id, description, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$productId, $designId, $desc]);
            $bomId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, ?, ?)");
            foreach ($materials as $matId => $qty) {
                $q = (float)$qty;
                if ($q > 0) {
                    $itemStmt->execute([$bomId, (int)$matId, $q]);
                }
            }

            log_audit('CREATE_BOM', 'bom_headers', $bomId, null, ['product_id' => $productId]);
            $db->commit();
            redirect('modules/raw_materials/bom.php', 'BOM specification saved successfully!');
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error saving BOM: ' . $e->getMessage());
        }
    }
}

$boms = $db->query("SELECT b.*, p.name AS product_name, d.design_number 
                    FROM bom_headers b 
                    JOIN products p ON b.product_id = p.id 
                    LEFT JOIN designs d ON b.design_id = d.id 
                    ORDER BY b.id DESC")->fetchAll();

$products = $db->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$allMaterials = $db->query("SELECT m.id, m.name, m.code, u.code AS unit_code FROM materials m JOIN units u ON m.unit_id = u.id WHERE m.is_active = 1 ORDER BY m.name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Bill of Materials (BOM)</h4>
                <p class="text-muted small mb-0">Define estimated raw material consumption per piece for cost forecasting</p>
            </div>
            <a href="<?= BASE_URL ?>modules/raw_materials/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Materials
            </a>
        </div>

        <!-- Create BOM Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-spreadsheet me-1 text-primary"></i> Configure Product BOM Spec</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_bom">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Product *</label>
                            <select name="product_id" class="form-select" required>
                                <option value="">-- Choose Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= clean($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control" placeholder="e.g. Standard sweater knitwear consumption">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold text-dark">Required Material Quantities Per Garment Piece</label>
                            <div class="row g-2 p-3 bg-light rounded-3 border">
                                <?php foreach ($allMaterials as $m): ?>
                                    <div class="col-12 col-sm-6 col-md-4">
                                        <div class="input-group input-group-sm mb-2">
                                            <span class="input-group-text text-truncate" style="max-width: 170px;" title="<?= clean($m['name']) ?>">
                                                <?= clean($m['name']) ?>
                                            </span>
                                            <input type="number" name="materials[<?= $m['id'] ?>]" class="form-control text-end" step="0.001" min="0" placeholder="0.00">
                                            <span class="input-group-text"><?= clean($m['unit_code']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end pt-2">
                            <button type="submit" class="btn btn-primary px-5 fw-bold">Save BOM Specification</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Existing BOMs -->
        <div class="row g-4">
            <?php foreach ($boms as $b): 
                $items = $db->query("SELECT bi.*, m.name AS material_name, u.code AS unit_code 
                                     FROM bom_items bi 
                                     JOIN materials m ON bi.material_id = m.id 
                                     JOIN units u ON m.unit_id = u.id 
                                     WHERE bi.bom_id = " . $b['id'])->fetchAll();
            ?>
                <div class="col-12 col-md-6">
                    <div class="card-modern h-100">
                        <div class="card-modern-header">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?= clean($b['product_name']) ?></h6>
                                <span class="text-muted small"><?= clean($b['description'] ?: 'Standard BOM') ?></span>
                            </div>
                            <span class="badge bg-primary-subtle text-primary"><?= count($items) ?> Materials</span>
                        </div>
                        <div class="card-modern-body p-0">
                            <table class="table table-modern table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th class="text-end">Consumption / Piece</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $it): ?>
                                        <tr>
                                            <td class="fw-semibold text-dark"><?= clean($it['material_name']) ?></td>
                                            <td class="text-end fw-bold text-primary"><?= number_format($it['quantity_per_piece'], 3) ?> <?= clean($it['unit_code']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
