<?php
/**
 * Garment Manufacturing ERP - Edit Lot & Add Design Later
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$lotId = (int)($_GET['id'] ?? 0);
$db = get_db();

$stmt = $db->prepare("SELECT l.*, p.name AS product_name FROM lots l JOIN products p ON l.product_id = p.id WHERE l.id = ?");
$stmt->execute([$lotId]);
$lot = $stmt->fetch();

if (!$lot) {
    redirect('modules/lots/index.php', 'Lot not found.', 'danger');
}

$pageTitle = "Edit Lot #" . $lot['lot_number'];
$activeNav = 'lots';

$designs = $db->prepare("SELECT id, design_number, name FROM designs WHERE product_id = ? AND is_active = 1 ORDER BY design_number ASC");
$designs->execute([$lot['product_id']]);
$availableDesigns = $designs->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $designId = !empty($_POST['design_id']) ? (int)$_POST['design_id'] : null;
    $supervisor = trim($_POST['supervisor_name'] ?? '');
    $expectedDate = !empty($_POST['expected_date']) ? trim($_POST['expected_date']) : null;
    $instructions = trim($_POST['instructions'] ?? '');

    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE lots SET design_id = ?, supervisor_name = ?, expected_date = ?, instructions = ? WHERE id = ?");
        $up->execute([$designId, $supervisor, $expectedDate, $instructions, $lotId]);

        log_audit('EDIT_LOT', 'lots', $lotId, [
            'design_id' => $lot['design_id'],
            'supervisor' => $lot['supervisor_name']
        ], [
            'design_id' => $designId,
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
                <p class="text-muted small mb-0">Add/change design number, supervisor & production instructions</p>
            </div>
            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $lot['id'] ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Lot View
            </a>
        </div>

        <div class="card-modern" style="max-width: 720px;">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Lot Information (Product: <?= clean($lot['product_name']) ?>)</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Assign / Change Design Number</label>
                            <select name="design_id" class="form-select">
                                <option value="">-- No Design (Pending) --</option>
                                <?php foreach ($availableDesigns as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $lot['design_id'] == $d['id'] ? 'selected' : '' ?>>
                                        Design #<?= clean($d['design_number']) ?> - <?= clean($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Existing worker entries and production logs are preserved.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Assigned Supervisor</label>
                            <input type="text" name="supervisor_name" class="form-control" value="<?= clean($lot['supervisor_name']) ?>" placeholder="Supervisor Name">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Expected Completion Date</label>
                            <input type="date" name="expected_date" class="form-control" value="<?= clean($lot['expected_date']) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Production Instructions</label>
                            <textarea name="instructions" class="form-control" rows="3"><?= clean($lot['instructions']) ?></textarea>
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
