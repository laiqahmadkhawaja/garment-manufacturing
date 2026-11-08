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
    $biStmt = $db->prepare("SELECT bi.*, m.name AS material_name, u.code AS unit_of_measure, u.code AS unit_code 
                            FROM bom_items bi 
                            JOIN materials m ON bi.material_id = m.id 
                            JOIN units u ON m.unit_id = u.id 
                            WHERE bi.bom_id = ?");
    $biStmt->execute([$recipe['id']]);
    $recipeItems = $biStmt->fetchAll();
}

// Fetch active machine operators
$operatorSql = "SELECT DISTINCT w.id, w.code, w.name, w.worker_type 
                FROM workers w 
                LEFT JOIN departments d ON w.department_id = d.id 
                WHERE w.is_active = 1 
                  AND (
                      d.name LIKE '%Operator%' 
                      OR d.name LIKE '%Knitt%' 
                      OR d.code = 'DEP-KNIT'
                      OR w.id IN (
                          SELECT wo.worker_id 
                          FROM worker_operations wo 
                          JOIN operations o ON wo.operation_id = o.id 
                          WHERE o.code = 'OP-OPERATOR' OR o.name LIKE '%Operator%'
                      )
                      OR w.name LIKE '%Operator%'
                      OR w.worker_type LIKE '%Operator%'
                  )
                ORDER BY w.name ASC";
$operators = $db->query($operatorSql)->fetchAll();

// Include currently assigned operator(s) if not already in list
$assignedOpIds = array_filter([(int)($lot['operator_id'] ?? 0), (int)($lot['night_operator_id'] ?? 0)]);
if (!empty($assignedOpIds)) {
    $existingOpIds = array_column($operators, 'id');
    foreach ($assignedOpIds as $aoId) {
        if (!in_array($aoId, $existingOpIds)) {
            $eOp = $db->prepare("SELECT id, code, name, worker_type FROM workers WHERE id = ?");
            $eOp->execute([$aoId]);
            $oRow = $eOp->fetch();
            if ($oRow) {
                $operators[] = $oRow;
            }
        }
    }
}

if (empty($operators)) {
    $operators = $db->query("SELECT id, code, name, worker_type FROM workers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
}

// Existing anatomy breakdown
$anatomyData = !empty($lot['anatomy_breakdown']) ? json_decode($lot['anatomy_breakdown'], true) : [];

// Retrieve lot measurements with fallback to defaults
$lotMeasurements = !empty($lot['measurements_json']) ? json_decode($lot['measurements_json'], true) : [];
$curPlaLen = $lotMeasurements['pla']['length'] ?? '41';
$curPlaWid = $lotMeasurements['pla']['width'] ?? '15';
$curPlaBor = $lotMeasurements['pla']['border'] ?? '46';
$curPlaMins = $lotMeasurements['pla']['mins'] ?? ($lot['estimated_minutes'] ?? '7');

$curBazoLen = $lotMeasurements['bazo']['length'] ?? '48';
$curBazoWid = $lotMeasurements['bazo']['width'] ?? '14';
$curBazoBor = $lotMeasurements['bazo']['border'] ?? '42';
$curBazoMins = $lotMeasurements['bazo']['mins'] ?? ($lot['estimated_minutes'] ?? '7');

$curScheme = $lotMeasurements['scheme'] ?? [
    '1' => ['length' => '41', 'width' => '45', 'border' => '46', 'mins' => '43'],
    '2' => ['length' => '', 'width' => '', 'border' => '', 'mins' => ''],
    '3' => ['length' => '', 'width' => '', 'border' => '', 'mins' => ''],
    '4' => ['length' => '', 'width' => '', 'border' => '', 'mins' => ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $designNumber = trim($_POST['design_number'] ?? '');
    $supervisor = trim($_POST['supervisor_name'] ?? '');
    $machineName = trim($_POST['machine_name'] ?? 'CMS 430');
    $expectedDate = !empty($_POST['expected_date']) ? trim($_POST['expected_date']) : null;
    $instructions = trim($_POST['instructions'] ?? '');
    $shiftType = in_array($_POST['shift_type'] ?? '', ['Single Shift', 'Double Shift']) ? $_POST['shift_type'] : 'Single Shift';
    $operatorId = !empty($_POST['operator_id']) ? (int)$_POST['operator_id'] : null;
    $nightOperatorId = ($shiftType === 'Double Shift' && !empty($_POST['night_operator_id'])) ? (int)$_POST['night_operator_id'] : null;

    $hasAnatomy = !empty($_POST['has_anatomy_variants']) ? 1 : 0;
    $sleevedQty = (int)($_POST['with_sleeves_qty'] ?? 0);
    $sleevelessQty = (int)($_POST['sleeveless_qty'] ?? 0);
    $anatomyBreakdown = null;
    if ($hasAnatomy) {
        $anatomyBreakdown = json_encode([
            'with_sleeves' => $sleevedQty,
            'sleeveless' => $sleevelessQty,
            'notes' => trim($_POST['anatomy_notes'] ?? '')
        ]);
    }

    // Pattern Measurements
    $plaLen = trim($_POST['pla_length'] ?? '');
    $plaWid = trim($_POST['pla_width'] ?? '');
    $plaBor = trim($_POST['pla_border'] ?? '');
    $plaMins = trim($_POST['pla_mins'] ?? '');

    $bazoLen = trim($_POST['bazo_length'] ?? '');
    $bazoWid = trim($_POST['bazo_width'] ?? '');
    $bazoBor = trim($_POST['bazo_border'] ?? '');
    $bazoMins = trim($_POST['bazo_mins'] ?? '');

    $scheme = [];
    for ($i = 1; $i <= 4; $i++) {
        $scheme[$i] = [
            'length' => trim($_POST["scheme_{$i}_length"] ?? ''),
            'width' => trim($_POST["scheme_{$i}_width"] ?? ''),
            'border' => trim($_POST["scheme_{$i}_border"] ?? ''),
            'mins' => trim($_POST["scheme_{$i}_mins"] ?? '')
        ];
    }

    $measurementsArray = [
        'pla' => ['length' => $plaLen, 'width' => $plaWid, 'border' => $plaBor, 'mins' => $plaMins],
        'bazo' => ['length' => $bazoLen, 'width' => $bazoWid, 'border' => $bazoBor, 'mins' => $bazoMins],
        'scheme' => $scheme
    ];
    $measurementsJson = json_encode($measurementsArray, JSON_UNESCAPED_UNICODE);

    // Garment Photo / Sketch Upload Handling
    $sketchImagePath = $lot['sketch_image'] ?? null;
    if (!empty($_POST['remove_sketch'])) {
        if (!empty($sketchImagePath) && file_exists(__DIR__ . '/../../' . $sketchImagePath)) {
            @unlink(__DIR__ . '/../../' . $sketchImagePath);
        }
        $sketchImagePath = null;
    }
    if (isset($_FILES['sketch_image']) && $_FILES['sketch_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['sketch_image'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExts)) {
            $uploadDir = __DIR__ . '/../../uploads/lots';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $fileName = 'sketch_' . $lotId . '_' . time() . '.' . $ext;
            $dest = $uploadDir . '/' . $fileName;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $sketchImagePath = 'uploads/lots/' . $fileName;
            }
        }
    }

    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE lots SET design_number = ?, supervisor_name = ?, expected_date = ?, instructions = ?, 
                            shift_type = ?, machine_name = ?, operator_id = ?, night_operator_id = ?, sketch_image = ?, has_anatomy_variants = ?, anatomy_breakdown = ?, measurements_json = ? 
                            WHERE id = ?");
        $up->execute([$designNumber ?: null, $supervisor, $expectedDate, $instructions, $shiftType, $machineName, $operatorId, $nightOperatorId, $sketchImagePath, $hasAnatomy, $anatomyBreakdown, $measurementsJson, $lotId]);

        log_audit('EDIT_LOT', 'lots', $lotId, [
            'design_number' => $lot['design_number'],
            'supervisor' => $lot['supervisor_name'],
            'shift_type' => $lot['shift_type'],
            'machine_name' => $lot['machine_name'] ?? null
        ], [
            'design_number' => $designNumber,
            'supervisor' => $supervisor,
            'shift_type' => $shiftType,
            'machine_name' => $machineName,
            'operator_id' => $operatorId,
            'night_operator_id' => $nightOperatorId,
            'sketch_image' => $sketchImagePath,
            'measurements' => $measurementsArray
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
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Design Number / Article Code</label>
                            <input type="text" name="design_number" class="form-control fw-bold text-primary" value="<?= clean($lot['design_number'] ?? '') ?>" placeholder="e.g. 904 or D-101 (Optional)">
                            <div class="form-text small">Enter freeform design number. Leave blank if not yet assigned.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Machine(s) / مشین</label>
                            <input type="text" name="machine_name" class="form-control font-monospace fw-bold" value="<?= clean($lot['machine_name'] ?? 'CMS 430') ?>" placeholder="e.g. CMS 430 or CMS 430 / CMS 530">
                            <div class="form-text small">Knitting machine number(s). Prints dynamically on Design Sheet.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Assigned Supervisor</label>
                            <input type="text" name="supervisor_name" class="form-control" value="<?= clean($lot['supervisor_name']) ?>" placeholder="e.g. Master Shabbir">
                        </div>

                        <!-- Garment Diagram / Sketch Photo (خاکہ / تصویر) -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Garment Diagram / Sketch Photo (خاکہ / تصویر)</label>
                            <input type="file" name="sketch_image" class="form-control" accept="image/*">
                            <div class="form-text small">Upload actual completed garment photo or sketch diagram.</div>
                            <?php if (!empty($lot['sketch_image']) && file_exists(__DIR__ . '/../../' . $lot['sketch_image'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-3 p-2 border rounded bg-white">
                                    <img src="<?= BASE_URL . clean($lot['sketch_image']) ?>" alt="Sketch" style="height: 55px; max-width: 85px; object-fit: contain;" class="rounded border">
                                    <div>
                                        <div class="fw-bold small text-success"><i class="bi bi-image me-1"></i> Current Photo Uploaded</div>
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" name="remove_sketch" id="remove_sketch" value="1">
                                            <label class="form-check-label text-danger small fw-semibold" for="remove_sketch">Remove / Revert to SVG Wireframe</label>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Shift Handling -->
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Shift Handling</label>
                            <select name="shift_type" id="edit_shift_type" class="form-select fw-semibold">
                                <option value="Single Shift" <?= ($lot['shift_type'] ?? '') === 'Single Shift' ? 'selected' : '' ?>>☀️ Single Shift (Day)</option>
                                <option value="Double Shift" <?= ($lot['shift_type'] ?? '') === 'Double Shift' ? 'selected' : '' ?>>🌙 Double Shift (Day & Night)</option>
                            </select>
                        </div>

                        <!-- Day Operator -->
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Day Shift Operator</label>
                            <select name="operator_id" class="form-select fw-semibold">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($operators as $op): ?>
                                    <option value="<?= $op['id'] ?>" <?= ($lot['operator_id'] == $op['id']) ? 'selected' : '' ?>>
                                        <?= clean($op['name']) ?> (<?= clean($op['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Night Operator -->
                        <div class="col-12 col-md-4" id="editNightOpContainer">
                            <label class="form-label fw-semibold">Night Shift Operator</label>
                            <select name="night_operator_id" class="form-select fw-semibold">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($operators as $op): ?>
                                    <option value="<?= $op['id'] ?>" <?= ($lot['night_operator_id'] == $op['id']) ? 'selected' : '' ?>>
                                        <?= clean($op['name']) ?> (<?= clean($op['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Expected Completion Date</label>
                            <input type="date" name="expected_date" class="form-control" value="<?= clean($lot['expected_date']) ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Product Type</label>
                            <input type="text" class="form-control bg-light" value="<?= clean($lot['product_type']) ?>" readonly>
                        </div>

                        <!-- Style Anatomy Breakdown -->
                        <div class="col-12">
                            <div class="p-3 bg-light border rounded-3">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="has_anatomy_variants" id="edit_has_anatomy" value="1" <?= !empty($lot['has_anatomy_variants']) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark" for="edit_has_anatomy">
                                        <i class="bi bi-diagram-3-fill text-primary me-1"></i> Style Anatomy Breakdown (بازو والے vs بغیر بازو / واسکٹ)
                                    </label>
                                </div>
                                <div class="row g-2 mt-2">
                                    <div class="col-6 col-sm-4">
                                        <label class="form-label small text-muted">With Sleeves Qty (بازو والے)</label>
                                        <input type="number" name="with_sleeves_qty" class="form-control form-control-sm fw-bold" value="<?= (int)($anatomyData['with_sleeves'] ?? 0) ?>">
                                    </div>
                                    <div class="col-6 col-sm-4">
                                        <label class="form-label small text-muted">Sleeveless Qty (بغیر بازو / واسکٹ)</label>
                                        <input type="number" name="sleeveless_qty" class="form-control form-control-sm fw-bold" value="<?= (int)($anatomyData['sleeveless'] ?? 0) ?>">
                                    </div>
                                </div>
                            </div>
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

                        <!-- Garment Size & Pattern Measurements (لمبائی، چوڑائی، بارڈر، منٹ) -->
                        <div class="col-12">
                            <div class="card border border-warning shadow-sm">
                                <div class="card-header bg-warning-subtle py-2 d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold text-dark">
                                        <i class="bi bi-rulers me-1 text-warning-emphasis"></i> Garment Pattern Size & Cutting Measurements (سائز اور کٹنگ پیمائش: لمبائی، چوڑائی، بارڈر)
                                    </h6>
                                    <span class="badge bg-warning text-dark fw-bold">Lot Design Sheet Dimensions</span>
                                </div>
                                <div class="card-body p-3 bg-light">
                                    <p class="text-muted small mb-3">Enter or adjust the pattern measurements for Body Panel (پلہ), Sleeves (بازو), and the 4 Size/Color Scheme columns. These reflect directly on the Lot Design Sheet (لاٹ پرچہ).</p>
                                    
                                    <!-- Palla & Bazo Row -->
                                    <div class="row g-3 mb-3">
                                        <!-- Palla -->
                                        <div class="col-12 col-md-6">
                                            <div class="card border h-100 shadow-sm">
                                                <div class="card-header bg-primary text-white py-1">
                                                    <strong class="small"><i class="bi bi-square me-1"></i> پلہ پیمائش (Body Panel)</strong>
                                                </div>
                                                <div class="card-body p-2 bg-white">
                                                    <div class="row g-2">
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">لمبائی (Length)</label>
                                                            <input type="text" name="pla_length" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curPlaLen) ?>" placeholder="41">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">چوڑائی (Width)</label>
                                                            <input type="text" name="pla_width" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curPlaWid) ?>" placeholder="15">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">بارڈر (Border)</label>
                                                            <input type="text" name="pla_border" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curPlaBor) ?>" placeholder="46">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">منٹ (SAM Mins)</label>
                                                            <input type="text" name="pla_mins" class="form-control form-control-sm text-center font-monospace fw-bold text-success" value="<?= clean($curPlaMins) ?>" placeholder="7">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Bazo -->
                                        <div class="col-12 col-md-6">
                                            <div class="card border h-100 shadow-sm">
                                                <div class="card-header bg-info text-white py-1">
                                                    <strong class="small"><i class="bi bi-diagram-2 me-1"></i> بازو پیمائش (Sleeves)</strong>
                                                </div>
                                                <div class="card-body p-2 bg-white">
                                                    <div class="row g-2">
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">لمبائی (Length)</label>
                                                            <input type="text" name="bazo_length" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curBazoLen) ?>" placeholder="48">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">چوڑائی (Width)</label>
                                                            <input type="text" name="bazo_width" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curBazoWid) ?>" placeholder="14">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">بارڈر (Border)</label>
                                                            <input type="text" name="bazo_border" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curBazoBor) ?>" placeholder="42">
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label small fw-bold mb-1">منٹ (SAM Mins)</label>
                                                            <input type="text" name="bazo_mins" class="form-control form-control-sm text-center font-monospace fw-bold text-success" value="<?= clean($curBazoMins) ?>" placeholder="7">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Size / Color Scheme Columns 1, 2, 3, 4 -->
                                    <div class="card border shadow-sm">
                                        <div class="card-header bg-white py-1 d-flex justify-content-between align-items-center">
                                            <strong class="small text-dark"><i class="bi bi-palette me-1 text-warning"></i> سائز / کلر سکیم کالمز (Scheme Columns 1, 2, 3, 4)</strong>
                                            <span class="badge bg-secondary-subtle text-secondary small">Columns 1 to 4</span>
                                        </div>
                                        <div class="card-body p-2 bg-white">
                                            <div class="table-responsive">
                                                <table class="table table-bordered align-middle text-center mb-0" style="font-size: 0.85rem;">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th style="width: 20%;">پیمائش</th>
                                                            <th style="width: 20%;">کالم 1</th>
                                                            <th style="width: 20%;">کالم 2</th>
                                                            <th style="width: 20%;">کالم 3</th>
                                                            <th style="width: 20%;">کالم 4</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td class="fw-bold text-start ps-2">لمبائی (Length)</td>
                                                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                                                <td>
                                                                    <input type="text" name="scheme_<?= $i ?>_length" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curScheme[$i]['length'] ?? '') ?>" placeholder="-">
                                                                </td>
                                                            <?php endfor; ?>
                                                        </tr>
                                                        <tr>
                                                            <td class="fw-bold text-start ps-2">چوڑائی (Width)</td>
                                                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                                                <td>
                                                                    <input type="text" name="scheme_<?= $i ?>_width" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curScheme[$i]['width'] ?? '') ?>" placeholder="-">
                                                                </td>
                                                            <?php endfor; ?>
                                                        </tr>
                                                        <tr>
                                                            <td class="fw-bold text-start ps-2">بارڈر (Border)</td>
                                                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                                                <td>
                                                                    <input type="text" name="scheme_<?= $i ?>_border" class="form-control form-control-sm text-center font-monospace fw-bold" value="<?= clean($curScheme[$i]['border'] ?? '') ?>" placeholder="-">
                                                                </td>
                                                            <?php endfor; ?>
                                                        </tr>
                                                        <tr>
                                                            <td class="fw-bold text-start ps-2">منٹ (SAM Mins)</td>
                                                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                                                <td>
                                                                    <input type="text" name="scheme_<?= $i ?>_mins" class="form-control form-control-sm text-center font-monospace fw-bold text-success" value="<?= clean($curScheme[$i]['mins'] ?? '') ?>" placeholder="-">
                                                                </td>
                                                            <?php endfor; ?>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

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
