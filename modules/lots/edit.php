<?php
/**
 * Garment Manufacturing ERP - Edit Lot Details & Design Number
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$db = get_db();
$lotId = (int)($_GET['id'] ?? 0);

if ($lotId <= 0) {
    redirect('modules/lots/index.php', 'Invalid Lot ID.', 'danger');
}

$stmt = $db->prepare("SELECT l.*, p.name AS product_name, p.code AS product_code, p.product_type 
                      FROM lots l
                      JOIN products p ON l.product_id = p.id
                      WHERE l.id = ?");
$stmt->execute([$lotId]);
$lot = $stmt->fetch();

if (!$lot) {
    redirect('modules/lots/index.php', 'Lot not found.', 'danger');
}

$pageTitle = "Edit Lot #" . $lot['lot_number'];
$activeNav = 'lots';

// Fetch product pattern elements
$eStmt = $db->prepare("SELECT * FROM product_elements WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
$eStmt->execute([$lot['product_id']]);
$productElements = $eStmt->fetchAll();

// Fetch product recipe
$bStmt = $db->prepare("SELECT b.* FROM bom_headers b WHERE b.product_id = ? AND b.is_active = 1 LIMIT 1");
$bStmt->execute([$lot['product_id']]);
$recipe = $bStmt->fetch();

$recipeItems = [];
if ($recipe) {
    $biStmt = $db->prepare("SELECT bi.*, m.name AS material_name, m.unit_of_measure 
                            FROM bom_items bi 
                            JOIN materials m ON bi.material_id = m.id 
                            WHERE bi.bom_id = ?");
    $biStmt->execute([$recipe['id']]);
    $recipeItems = $biStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $designNumber = trim($_POST['design_number'] ?? '');
    $supervisor = trim($_POST['supervisor_name'] ?? '');
    $expectedDate = !empty($_POST['expected_date']) ? trim($_POST['expected_date']) : null;
    $instructions = trim($_POST['instructions'] ?? '');

    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE lots SET design_number = ?, supervisor_name = ?, expected_date = ?, instructions = ? WHERE id = ?");
        $up->execute([$designNumber ?: null, $supervisor, $expectedDate, $instructions, $lotId]);

        log_audit('EDIT_LOT', 'lots', $lotId, [
            'design_number' => $lot['design_number'],
            'supervisor' => $lot['supervisor_name']
        ], [
            'design_number' => $designNumber,
            'supervisor' => $supervisor
        ]);

        $db->commit();
        redirect("modules/lots/view.php?id=$lotId", "Lot #{$lot['lot_number']} updated successfully!");
    } catch (Exception $e) {
        $db->rollBack();
        set_flash('danger', 'Failed to update lot: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Edit Lot #<?= clean($lot['lot_number']) ?></h4>
                <p class="text-muted small mb-0">Update design number, supervisor & production instructions</p>
            </div>
            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lot['id'] ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Lot View
            </a>
        </div>

        <div class="card-modern shadow-sm" style="max-width: 800px;">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Lot Specifications (<?= clean($lot['product_name']) ?>)</h6>
                <span class="badge bg-light text-primary border fw-bold"><?= number_format($lot['total_pieces']) ?> Total Pcs</span>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Design Number / Article Code</label>
                            <input type="text" name="design_number" class="form-control fw-bold text-primary" value="<?= clean($lot['design_number'] ?? '') ?>" placeholder="e.g. 904 or D-101 (Optional)">
                            <div class="form-text small">Enter freeform design number. Leave blank if not yet assigned.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Assigned Supervisor</label>
                            <input type="text" name="supervisor_name" class="form-control" value="<?= clean($lot['supervisor_name']) ?>" placeholder="e.g. Master Shabbir">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Expected Completion Date</label>
                            <input type="date" name="expected_date" class="form-control" value="<?= clean($lot['expected_date']) ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Product Type</label>
                            <input type="text" class="form-control bg-light" value="<?= clean($lot['product_type']) ?>" readonly>
                        </div>

                        <!-- Product Pattern Elements Info -->
                        <?php if (!empty($productElements)): ?>
                            <div class="col-12">
                                <div class="p-3 bg-light border border-primary-subtle rounded-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="small fw-bold text-dark"><i class="bi bi-puzzle-fill text-primary me-1"></i> Product Cutting Elements (اجزاء):</span>
                                        <span class="small text-muted"><?= count($productElements) ?> components</span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                        <?php foreach ($productElements as $pe): ?>
                                            <span class="badge bg-white text-dark border p-2">
                                                <?= clean($pe['element_name']) ?>: <strong class="text-primary">×<?= (int)$pe['quantity'] ?></strong>
                                                <?php if ($pe['notes']): ?>
                                                    <small class="text-muted ms-1">(<?= clean($pe['notes']) ?>)</small>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Product Recipe Requirements -->
                        <?php if (!empty($recipeItems)): ?>
                            <div class="col-12">
                                <div class="p-3 bg-light border border-warning-subtle rounded-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="small fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet-fill text-warning me-1"></i> Recipe Material Requirements for this Batch (<?= number_format($lot['total_pieces']) ?> pcs):</span>
                                        <span class="badge bg-success-subtle text-success border">Recipe Active</span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($recipeItems as $ri): 
                                            $batchReq = $ri['quantity_per_piece'] * $lot['total_pieces'];
                                        ?>
                                            <div class="badge bg-white text-dark border p-2">
                                                <span class="text-secondary"><?= clean($ri['material_name']) ?>:</span>
                                                <strong class="text-primary"><?= (float)$ri['quantity_per_piece'] ?> <?= clean($ri['unit_of_measure']) ?>/pc</strong>
                                                <span class="text-success ms-1">[<?= number_format($batchReq, 2) ?> <?= clean($ri['unit_of_measure']) ?> for batch]</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Production Instructions</label>
                            <textarea name="instructions" class="form-control" rows="3" placeholder="Styling notes, color matching, stitching specs"><?= clean($lot['instructions']) ?></textarea>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-3 border-top">
                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lot['id'] ?>" class="btn btn-outline-secondary px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 fw-bold">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
