<?php
/**
 * Garment Manufacturing ERP - Create New Production Lot
 * Includes: Shift Types (Single/Double), Operator Assignments, Unique Lot Number Validation,
 * Smart Color Codes (Double-Sorted by Frequency + Available Yarn Stock),
 * Fast Single-Size vs Multi-Size Mode, and Style Anatomy Breakdown (With Sleeves vs Sleeveless).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Create New Lot';
$activeNav = 'create_lot';
$db = get_db();

// Auto-generated Unique Lot Number suggestion
$suggestedLotNumber = generate_doc_number('LOT-', 'lots', 'lot_number');

// Fetch products
$products = $db->query("SELECT p.*, c.name AS category_name, wt.name AS template_name 
                        FROM products p 
                        LEFT JOIN product_categories c ON p.category_id = c.id 
                        LEFT JOIN workflow_templates wt ON p.default_workflow_template_id = wt.id
                        WHERE p.is_active = 1 ORDER BY p.name ASC")->fetchAll();

// Fetch Operators / Workers
$operators = $db->query("SELECT id, code, name, worker_type, rate_per_minute 
                         FROM workers 
                         WHERE is_active = 1 
                         ORDER BY name ASC")->fetchAll();

// Fetch Colors double-sorted: 1st by Historical Lot Frequency, 2nd by Available Yarn Stock, 3rd by Code
$colorSql = "SELECT c.*, 
                    COALESCE((SELECT COUNT(DISTINCT lv.lot_id) FROM lot_variants lv WHERE lv.color_id = c.id), 0) AS usage_count
             FROM colors c 
             ORDER BY usage_count DESC, c.yarn_stock_kg DESC, c.color_code ASC";
$colors = $db->query($colorSql)->fetchAll();

// Fetch Sizes
$sizes = $db->query("SELECT * FROM sizes ORDER BY sort_order ASC, id ASC")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $lotNumber = trim($_POST['lot_number'] ?? '');
    $lotDate = trim($_POST['lot_date'] ?? date('Y-m-d'));
    $productId = (int)($_POST['product_id'] ?? 0);
    $designNumber = trim($_POST['design_number'] ?? '');
    $designId = !empty($_POST['design_id']) ? (int)$_POST['design_id'] : null;
    
    // Operator & Shift assignment
    $shiftType = in_array($_POST['shift_type'] ?? '', ['Single Shift', 'Double Shift']) ? $_POST['shift_type'] : 'Single Shift';
    $operatorId = !empty($_POST['operator_id']) ? (int)$_POST['operator_id'] : null;
    $nightOperatorId = ($shiftType === 'Double Shift' && !empty($_POST['night_operator_id'])) ? (int)$_POST['night_operator_id'] : null;
    $supervisor = trim($_POST['supervisor_name'] ?? '');
    $expectedDate = !empty($_POST['expected_date']) ? trim($_POST['expected_date']) : null;
    $instructions = trim($_POST['instructions'] ?? '');

    // Sizing Mode
    $sizeMode = ($_POST['size_mode'] ?? 'Single Size') === 'Multi Size' ? 'Multi Size' : 'Single Size';
    $singleSizeId = !empty($_POST['single_size_id']) ? (int)$_POST['single_size_id'] : null;

    // Style Anatomy Breakdown (With Sleeves vs Sleeveless)
    $hasAnatomyVariants = !empty($_POST['has_anatomy_variants']) ? 1 : 0;
    $sleevedQty = (int)($_POST['with_sleeves_qty'] ?? 0);
    $sleevelessQty = (int)($_POST['sleeveless_qty'] ?? 0);
    $anatomyBreakdown = null;
    if ($hasAnatomyVariants) {
        $anatomyBreakdown = json_encode([
            'with_sleeves' => $sleevedQty,
            'sleeveless' => $sleevelessQty,
            'notes' => trim($_POST['anatomy_notes'] ?? '')
        ]);
    }

    $entryMode = trim($_POST['entry_mode'] ?? 'single_fast');
    $matrixRows = $_POST['matrix_rows'] ?? [];
    $items = $_POST['items'] ?? [];
    $singleColors = $_POST['single_colors'] ?? [];

    if (empty($lotNumber) || $productId <= 0) {
        $error = 'Lot number and product are required.';
    } else {
        // Enforce strict uniqueness of lot_number
        $stmt = $db->prepare("SELECT id FROM lots WHERE lot_number = ?");
        $stmt->execute([$lotNumber]);
        if ($stmt->fetch()) {
            $error = "Lot number '$lotNumber' already exists! Please choose or generate a unique lot number.";
        } else {
            $totalPieces = 0;
            $usedColors = [];
            $usedSizes = [];
            $aggregated = [];

            // A. Single Size Mode (95% standard fast workflow)
            if ($sizeMode === 'Single Size') {
                if (!$singleSizeId && !empty($sizes)) {
                    $singleSizeId = (int)$sizes[0]['id'];
                }
                if (!empty($singleColors) && is_array($singleColors)) {
                    foreach ($singleColors as $sc) {
                        $cId = (int)($sc['color_id'] ?? 0);
                        $q = (int)($sc['quantity'] ?? 0);
                        $cCode = trim($sc['color_code'] ?? '');
                        if ($cId > 0 && $q > 0) {
                            $key = $cId . '_' . $singleSizeId;
                            if (isset($aggregated[$key])) {
                                $aggregated[$key]['quantity'] += $q;
                            } else {
                                $aggregated[$key] = [
                                    'color_id' => $cId,
                                    'color_code' => $cCode,
                                    'size_id' => $singleSizeId,
                                    'quantity' => $q
                                ];
                            }
                            $usedColors[$cId] = true;
                            $usedSizes[$singleSizeId] = true;
                            $totalPieces += $q;
                        }
                    }
                }
            } else {
                // B. Multi-Size Mode (Kids / Mixed batches)
                // 1. Process Itemized Rows
                if (!empty($items) && is_array($items)) {
                    foreach ($items as $item) {
                        $cId = (int)($item['color_id'] ?? 0);
                        $sId = (int)($item['size_id'] ?? 0);
                        $q = (int)($item['quantity'] ?? 0);
                        $cCode = trim($item['color_code'] ?? '');

                        if ($cId > 0 && $sId > 0 && $q > 0) {
                            $key = $cId . '_' . $sId;
                            if (isset($aggregated[$key])) {
                                $aggregated[$key]['quantity'] += $q;
                            } else {
                                $aggregated[$key] = [
                                    'color_id' => $cId,
                                    'color_code' => $cCode,
                                    'size_id' => $sId,
                                    'quantity' => $q
                                ];
                            }
                            $usedColors[$cId] = true;
                            $usedSizes[$sId] = true;
                            $totalPieces += $q;
                        }
                    }
                }

                // 2. Process Dynamic Matrix Rows
                if (!empty($matrixRows) && is_array($matrixRows)) {
                    foreach ($matrixRows as $row) {
                        $cId = (int)($row['color_id'] ?? 0);
                        $cCode = trim($row['color_code'] ?? '');
                        $sizesArr = $row['sizes'] ?? [];
                        if ($cId > 0 && is_array($sizesArr)) {
                            foreach ($sizesArr as $sId => $q) {
                                $sId = (int)$sId;
                                $q = (int)$q;
                                if ($sId > 0 && $q > 0) {
                                    $key = $cId . '_' . $sId;
                                    if (isset($aggregated[$key])) {
                                        $aggregated[$key]['quantity'] += $q;
                                    } else {
                                        $aggregated[$key] = [
                                            'color_id' => $cId,
                                            'color_code' => $cCode,
                                            'size_id' => $sId,
                                            'quantity' => $q
                                        ];
                                    }
                                    $usedColors[$cId] = true;
                                    $usedSizes[$sId] = true;
                                    $totalPieces += $q;
                                }
                            }
                        }
                    }
                }
            }

            $validVariants = array_values($aggregated);

            if ($totalPieces <= 0 || empty($validVariants)) {
                $error = 'Please enter valid planned quantities for at least one color.';
            } elseif ($hasAnatomyVariants && ($sleevedQty + $sleevelessQty) !== $totalPieces) {
                $error = "Style Anatomy breakdown sum (" . ($sleevedQty + $sleevelessQty) . " pcs) must match Total Planned Pieces ($totalPieces pcs).";
            } else {
                $db->beginTransaction();
                try {
                    $totalColors = count($usedColors);
                    $totalSizes = count($usedSizes);

                    // Insert Lot Master
                    $stmt = $db->prepare("INSERT INTO lots 
                        (lot_number, lot_date, product_id, design_number, design_id, shift_type, operator_id, night_operator_id, 
                         supervisor_name, expected_date, instructions, total_pieces, total_colors, total_sizes, 
                         size_mode, single_size_id, has_anatomy_variants, anatomy_breakdown, status, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?)");
                    $stmt->execute([
                        $lotNumber,
                        $lotDate,
                        $productId,
                        $designNumber ?: null,
                        $designId,
                        $shiftType,
                        $operatorId,
                        $nightOperatorId,
                        $supervisor,
                        $expectedDate,
                        $instructions,
                        $totalPieces,
                        $totalColors,
                        $totalSizes,
                        $sizeMode,
                        $singleSizeId,
                        $hasAnatomyVariants,
                        $anatomyBreakdown,
                        $_SESSION['user_id'] ?? null
                    ]);
                    $lotId = (int)$db->lastInsertId();

                    // Insert Lot Variants with Color Code
                    $vStmt = $db->prepare("INSERT INTO lot_variants (lot_id, color_id, color_code, size_id, quantity) VALUES (?, ?, ?, ?, ?)");
                    foreach ($validVariants as $var) {
                        // resolve color_code if blank
                        $cCode = $var['color_code'];
                        if (empty($cCode)) {
                            foreach ($colors as $cItem) {
                                if ($cItem['id'] == $var['color_id']) {
                                    $cCode = $cItem['color_code'] ?: $cItem['name'];
                                    break;
                                }
                            }
                        }
                        $vStmt->execute([$lotId, $var['color_id'], $cCode, $var['size_id'], $var['quantity']]);
                    }

                    // Assign Workflow Snapshot
                    $templateId = 1;
                    if ($designId) {
                        $dStmt = $db->prepare("SELECT workflow_template_id FROM designs WHERE id = ?");
                        $dStmt->execute([$designId]);
                        $tId = $dStmt->fetchColumn();
                        if ($tId) $templateId = $tId;
                    }
                    if (!$templateId) {
                        $pStmt = $db->prepare("SELECT default_workflow_template_id FROM products WHERE id = ?");
                        $pStmt->execute([$productId]);
                        $tId = $pStmt->fetchColumn();
                        if ($tId) $templateId = $tId;
                    }

                    // Get template steps
                    $stepQuery = $db->prepare("SELECT * FROM workflow_template_steps WHERE template_id = ? ORDER BY sequence ASC");
                    $stepQuery->execute([$templateId]);
                    $templateSteps = $stepQuery->fetchAll();

                    if (empty($templateSteps)) {
                        $allOps = $db->query("SELECT id, sequence, is_mandatory FROM operations ORDER BY sequence ASC")->fetchAll();
                        $insertStep = $db->prepare("INSERT INTO lot_workflow_steps (lot_id, operation_id, sequence, is_mandatory, status) VALUES (?, ?, ?, ?, 'Pending')");
                        foreach ($allOps as $op) {
                            $insertStep->execute([$lotId, $op['id'], $op['sequence'], $op['is_mandatory']]);
                        }
                    } else {
                        $insertStep = $db->prepare("INSERT INTO lot_workflow_steps (lot_id, operation_id, sequence, is_mandatory, status) VALUES (?, ?, ?, ?, 'Pending')");
                        foreach ($templateSteps as $step) {
                            $insertStep->execute([$lotId, $step['operation_id'], $step['sequence'], $step['is_mandatory']]);
                        }
                    }

                    // Status History Initial Entry
                    $hStmt = $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, 'Draft', 'Draft', 'Lot created with smart operators and color codes', ?)");
                    $hStmt->execute([$lotId, $_SESSION['user_id'] ?? null]);

                    log_audit('CREATE', 'lots', $lotId, null, [
                        'lot_number' => $lotNumber,
                        'total_pieces' => $totalPieces,
                        'shift_type' => $shiftType,
                        'operator_id' => $operatorId,
                        'night_operator_id' => $nightOperatorId
                    ]);

                    $db->commit();

                    // Automatically issue / allocate raw materials based on product recipe
                    $autoResult = auto_issue_lot_materials($lotId);
                    $flashMsg = "Production Lot #$lotNumber created successfully ($totalPieces pieces)!";
                    if (!empty($autoResult['shortages'])) {
                        $flashMsg .= " ⚠️ Note: Raw material stock shortage detected. Please review required materials.";
                        redirect("modules/lots/view.php?id=$lotId", $flashMsg, 'warning');
                    } else {
                        redirect("modules/lots/view.php?id=$lotId", $flashMsg);
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Error creating lot: ' . $e->getMessage();
                }
            }
        }
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
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-layers-fill text-primary me-2"></i>Create New Production Lot
                </h4>
                <p class="text-muted small mb-0">High-speed batch setup with operator assignment, shift management, and yarn-sorted color codes</p>
            </div>
            <a href="<?= BASE_URL ?>modules/lots/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Lots
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <span class="fw-semibold"><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="lotForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="entry_mode" id="entry_mode" value="single_fast">

            <!-- ========================================== -->
            <!-- 1. LOT MASTER & OPERATOR ASSIGNMENT CARD -->
            <!-- ========================================== -->
            <div class="card-modern mb-4 shadow-sm">
                <div class="card-modern-header d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-info-circle-fill text-primary me-1"></i> 1. Lot Master, Shift & Operator Assignment
                    </h6>
                    <span class="badge bg-light text-secondary border">Single or Double Shift Handling</span>
                </div>
                <div class="card-modern-body">
                    <div class="row g-3">
                        <!-- Lot Number with Instant Unique Feedback -->
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold d-flex justify-content-between align-items-center">
                                <span>Lot Number *</span>
                                <span id="lotUniquenessBadge" class="small fw-semibold text-muted">Checking...</span>
                            </label>
                            <div class="input-group">
                                <input type="text" name="lot_number" id="lot_number" 
                                       class="form-control fw-bold text-primary font-monospace" 
                                       value="<?= clean($_POST['lot_number'] ?? $suggestedLotNumber) ?>" 
                                       required placeholder="LOT-2026-0001">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnGenLot" title="Generate New Unique Number">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                            <div class="form-text small" style="font-size: 11px;">Unique factory batch code (Never duplicated).</div>
                        </div>

                        <!-- Lot Date -->
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Lot Date *</label>
                            <input type="date" name="lot_date" class="form-control" value="<?= clean($_POST['lot_date'] ?? date('Y-m-d')) ?>" required>
                        </div>

                        <!-- Product Selector -->
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Product *</label>
                            <select name="product_id" id="product_id" class="form-select fw-semibold" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= (isset($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : '' ?>>
                                        <?= clean($p['name']) ?> (<?= clean($p['product_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Design Number (Optional / Text field) -->
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">
                                Design Number <span class="badge bg-light text-secondary border fw-normal">Optional</span>
                            </label>
                            <input type="text" name="design_number" id="lot_design_number" class="form-control" 
                                   placeholder="e.g. 904 or D-101" 
                                   value="<?= clean($_POST['design_number'] ?? '') ?>">
                            <div class="form-text small" style="font-size: 11px;">Article/Design reference (editable anytime).</div>
                        </div>

                        <!-- Live Product Cutting Elements & Recipe Preview -->
                        <div class="col-12 d-none" id="productSpecsPreview">
                            <div class="row g-2">
                                <div class="col-12 col-lg-6">
                                    <div class="p-3 bg-light border border-primary-subtle rounded-3 h-100">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="small fw-bold text-dark"><i class="bi bi-puzzle-fill text-primary me-1"></i> Product Cutting Elements (اجزاء):</span>
                                            <span class="small text-muted" id="lblProdElementsBadge">0 parts</span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-1 align-items-center" id="prodElementsBadges"></div>
                                    </div>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <div class="p-3 bg-light border border-warning-subtle rounded-3 h-100">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="small fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet-fill text-warning me-1"></i> Recipe / Raw Materials Consumption:</span>
                                            <span class="small text-muted" id="lblRecipeStatus"></span>
                                        </div>
                                        <div id="prodRecipeDetails">
                                            <span class="text-muted small fst-italic">Select a product to view recipe</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Shift Type (Single vs Double Shift) -->
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Shift Handling (شفٹ کی قسم) *</label>
                            <div class="d-flex gap-2">
                                <div class="form-check form-check-inline border rounded-3 px-3 py-2 flex-grow-1 bg-light cursor-pointer">
                                    <input class="form-check-input" type="radio" name="shift_type" id="shiftSingle" value="Single Shift" <?= (!isset($_POST['shift_type']) || $_POST['shift_type'] === 'Single Shift') ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark cursor-pointer" for="shiftSingle">
                                        ☀️ Single Shift (Day)
                                    </label>
                                </div>
                                <div class="form-check form-check-inline border rounded-3 px-3 py-2 flex-grow-1 bg-light cursor-pointer">
                                    <input class="form-check-input" type="radio" name="shift_type" id="shiftDouble" value="Double Shift" <?= (isset($_POST['shift_type']) && $_POST['shift_type'] === 'Double Shift') ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark cursor-pointer" for="shiftDouble">
                                        🌙 Double Shift (Day & Night)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Day Shift Operator -->
                        <div class="col-12 col-md-4" id="dayOperatorContainer">
                            <label class="form-label fw-semibold">
                                <span class="text-warning">☀️</span> Day Shift Operator (ڈے آپریٹر)
                            </label>
                            <select name="operator_id" id="operator_id" class="form-select fw-semibold">
                                <option value="">-- Choose Operator --</option>
                                <?php foreach ($operators as $op): ?>
                                    <option value="<?= $op['id'] ?>" <?= (isset($_POST['operator_id']) && $_POST['operator_id'] == $op['id']) ? 'selected' : '' ?>>
                                        <?= clean($op['name']) ?> (<?= clean($op['code']) ?> - <?= clean($op['worker_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small" style="font-size: 11px;">Primary operator in charge of batch.</div>
                        </div>

                        <!-- Night Shift Operator (Shown when Double Shift is selected) -->
                        <div class="col-12 col-md-4 <?= (isset($_POST['shift_type']) && $_POST['shift_type'] === 'Double Shift') ? '' : 'd-none' ?>" id="nightOperatorContainer">
                            <label class="form-label fw-semibold">
                                <span class="text-primary">🌙</span> Night Shift Operator (نائٹ آپریٹر)
                            </label>
                            <select name="night_operator_id" id="night_operator_id" class="form-select fw-semibold">
                                <option value="">-- Choose Night Operator --</option>
                                <?php foreach ($operators as $op): ?>
                                    <option value="<?= $op['id'] ?>" <?= (isset($_POST['night_operator_id']) && $_POST['night_operator_id'] == $op['id']) ? 'selected' : '' ?>>
                                        <?= clean($op['name']) ?> (<?= clean($op['code']) ?> - <?= clean($op['worker_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small" style="font-size: 11px;">Operator handling the night shift.</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Assigned Supervisor</label>
                            <input type="text" name="supervisor_name" class="form-control" placeholder="e.g. Master Shabbir" value="<?= clean($_POST['supervisor_name'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Expected Completion Date</label>
                            <input type="date" name="expected_date" class="form-control" value="<?= clean($_POST['expected_date'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Production Instructions / Notes</label>
                            <input type="text" name="instructions" class="form-control" placeholder="Thread shade, special wash, packaging notes" value="<?= clean($_POST['instructions'] ?? '') ?>">
                        </div>

                        <!-- Style Anatomy Breakdown Option (Sleeves vs Sleeveless) -->
                        <div class="col-12">
                            <div class="p-3 bg-light border rounded-3">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="has_anatomy_variants" id="has_anatomy_variants" value="1" <?= !empty($_POST['has_anatomy_variants']) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold text-dark" for="has_anatomy_variants">
                                        <i class="bi bi-diagram-3-fill text-primary me-1"></i> Style Anatomy Breakdown (بازو والے vs بغیر بازو / واسکٹ)
                                    </label>
                                    <span class="badge bg-secondary-subtle text-secondary ms-2">Optional</span>
                                </div>
                                <div id="anatomyBreakdownInputs" class="<?= !empty($_POST['has_anatomy_variants']) ? '' : 'd-none' ?> mt-3 pt-3 border-top">
                                    <div class="row g-3 align-items-center">
                                        <div class="col-12 col-sm-4">
                                            <label class="form-label fw-semibold small text-muted">With Sleeves Quantity (بازو والے پیسز)</label>
                                            <div class="input-group">
                                                <input type="number" name="with_sleeves_qty" id="with_sleeves_qty" class="form-control fw-bold text-primary" min="0" value="<?= clean($_POST['with_sleeves_qty'] ?? '0') ?>">
                                                <span class="input-group-text small">pcs</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-4">
                                            <label class="form-label fw-semibold small text-muted">Sleeveless Quantity (بغیر بازو / واسکٹ)</label>
                                            <div class="input-group">
                                                <input type="number" name="sleeveless_qty" id="sleeveless_qty" class="form-control fw-bold text-secondary" min="0" value="<?= clean($_POST['sleeveless_qty'] ?? '0') ?>">
                                                <span class="input-group-text small">pcs</span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-sm-4">
                                            <label class="form-label fw-semibold small text-muted">Breakdown Validation</label>
                                            <div id="anatomyMatchStatus" class="p-2 rounded bg-white border small fw-semibold text-muted text-center">
                                                Sum: <span id="lblAnatomySum">0</span> pcs
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- 2. SMART COLOR CODES & FAST SIZE QUANTITIES CARD -->
            <!-- ======================================================== -->
            <div class="card-modern mb-4 shadow-sm">
                <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-palette-fill text-primary me-1"></i> 2. Color Codes & Planned Piece Quantities
                        </h6>
                        <span class="text-muted small">Colors double-sorted by usage frequency and available yarn stock</span>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <!-- Sizing Mode Selector -->
                        <div class="btn-group btn-group-sm" role="group">
                            <input type="radio" class="btn-check" name="size_mode" id="modeSingleSize" value="Single Size" <?= (!isset($_POST['size_mode']) || $_POST['size_mode'] === 'Single Size') ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary fw-semibold" for="modeSingleSize">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Single Size (Fast Default)
                            </label>

                            <input type="radio" class="btn-check" name="size_mode" id="modeMultiSize" value="Multi Size" <?= (isset($_POST['size_mode']) && $_POST['size_mode'] === 'Multi Size') ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary fw-semibold" for="modeMultiSize">
                                <i class="bi bi-grid-3x3-gap me-1"></i> Multi-Size (Kids / Mixed)
                            </label>
                        </div>

                        <!-- Grand Total Badge -->
                        <div class="badge bg-primary fs-6 px-3 py-2 shadow-sm">
                            Total: <span id="lotGrandTotal">0</span> Pieces
                        </div>
                    </div>
                </div>

                <!-- ========================================================== -->
                <!-- SECTION A: SINGLE SIZE FAST MODE (95% STANDARD LOTS) -->
                <!-- ========================================================== -->
                <div id="singleSizeContainer" class="card-modern-body p-3">
                    <!-- Lot Size Selection Bar -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between p-3 bg-light border rounded-3 mb-3 gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label fw-bold mb-0 text-dark">Select Lot Size (لاٹ کا سائز):</label>
                            <select name="single_size_id" id="single_size_id" class="form-select form-select-sm fw-bold border-primary text-primary" style="width: auto; min-width: 140px;">
                                <?php foreach ($sizes as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= (isset($_POST['single_size_id']) && $_POST['single_size_id'] == $s['id']) ? 'selected' : '' ?>>
                                        <?= clean($s['name']) ?> (<?= clean($s['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-info-circle me-1"></i> All colors entered below will produce this single size.
                        </div>
                    </div>

                    <!-- Smart Color Code Quick-Picker (Double Sorted) -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-dark">
                                <i class="bi bi-star-fill text-warning me-1"></i> Smart Color Codes (Double-Sorted: Top Lots Used + Yarn In Stock):
                            </span>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="text" id="quickColorCodeInput" class="form-control form-control-sm text-uppercase fw-bold" placeholder="Type Color Code (e.g. 1136) ↵" style="width: 220px;">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="btnQuickAddCustomColor" title="Register New Color Code">
                                    <i class="bi bi-plus-lg"></i> New Code
                                </button>
                            </div>
                        </div>

                        <!-- Double Sorted Color Chips Grid -->
                        <div class="d-flex flex-wrap gap-2 p-2 bg-light border rounded-3" id="colorChipsContainer" style="max-height: 160px; overflow-y: auto;">
                            <?php foreach ($colors as $c): ?>
                                <button type="button" 
                                        class="btn btn-sm btn-outline-dark bg-white d-flex align-items-center gap-2 p-2 color-quick-chip" 
                                        data-color-id="<?= $c['id'] ?>"
                                        data-color-code="<?= clean($c['color_code'] ?: $c['name']) ?>"
                                        data-color-name="<?= clean($c['name']) ?>"
                                        data-hex="<?= clean($c['hex_code']) ?>"
                                        data-yarn="<?= (float)$c['yarn_stock_kg'] ?>">
                                    <span class="rounded-circle border" style="width: 14px; height: 14px; background-color: <?= clean($c['hex_code']) ?>;"></span>
                                    <strong class="font-monospace text-primary"><?= clean($c['color_code'] ?: $c['name']) ?></strong>
                                    <span class="text-secondary small"><?= clean($c['name']) ?></span>
                                    <span class="badge bg-secondary-subtle text-secondary border small" style="font-size: 10px;">
                                        <?= (float)$c['yarn_stock_kg'] ?> KG Yarn
                                    </span>
                                    <?php if ($c['usage_count'] > 0): ?>
                                        <span class="badge bg-success-subtle text-success border small" style="font-size: 10px;">
                                            <?= $c['usage_count'] ?> Lots
                                        </span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Active Colors Table for Single Size -->
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-center mb-0" id="singleColorsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th class="text-start" style="width: 25%;">Color Code (رنگ کا کوڈ)</th>
                                    <th class="text-start" style="width: 30%;">Color Name</th>
                                    <th style="width: 20%;">Available Yarn Stock</th>
                                    <th style="width: 20%;">Planned Pieces (تعداد) *</th>
                                    <th style="width: 50px;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="singleColorsTbody">
                                <!-- Default Row 1: Black 1136 -->
                                <tr class="single-color-row" data-color-id="1">
                                    <td class="text-center fw-bold text-muted srow-num">1</td>
                                    <td class="text-start">
                                        <input type="hidden" name="single_colors[0][color_id]" value="1">
                                        <input type="hidden" name="single_colors[0][color_code]" value="1136">
                                        <span class="badge bg-dark font-monospace fs-6 px-2 py-1">1136</span>
                                    </td>
                                    <td class="text-start">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle border" style="width: 14px; height: 14px; background-color: #1a1a1a;"></span>
                                            <span class="fw-semibold">Black</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">450.00 KG Yarn</span>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="single_colors[0][quantity]" class="form-control text-center fw-bold single-qty-input fs-5" min="1" placeholder="0">
                                            <span class="input-group-text small text-muted">pcs</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-single-row" title="Remove Color">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Default Row 2: Navy 1138 -->
                                <tr class="single-color-row" data-color-id="5">
                                    <td class="text-center fw-bold text-muted srow-num">2</td>
                                    <td class="text-start">
                                        <input type="hidden" name="single_colors[1][color_id]" value="5">
                                        <input type="hidden" name="single_colors[1][color_code]" value="1138">
                                        <span class="badge bg-primary font-monospace fs-6 px-2 py-1">1138</span>
                                    </td>
                                    <td class="text-start">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle border" style="width: 14px; height: 14px; background-color: #0f172a;"></span>
                                            <span class="fw-semibold">Navy</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">380.00 KG Yarn</span>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="single_colors[1][quantity]" class="form-control text-center fw-bold single-qty-input fs-5" min="1" placeholder="0">
                                            <span class="input-group-text small text-muted">pcs</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-single-row" title="Remove Color">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-2 bg-light border-top text-muted small">
                        💡 Click any color chip above to add it to this batch, or type its code and press Enter.
                    </div>
                </div>

                <!-- ========================================================== -->
                <!-- SECTION B: MULTI-SIZE MODE (KIDS & MIXED SIZES) -->
                <!-- ========================================================== -->
                <div id="multiSizeContainer" class="card-modern-body p-0 d-none">
                    <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fw-bold text-dark"><i class="bi bi-grid-3x3-gap-fill text-primary me-1"></i> Multi-Size Matrix Breakdown</span>
                            <span class="text-muted small ms-2">Enter quantities per color across all garment sizes</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" id="btnAddMatrixColorRow">
                            <i class="bi bi-plus-circle me-1"></i> Add Color Row
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-center mb-0" id="dynamicMatrixTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 45px;">#</th>
                                    <th class="text-start" style="min-width: 220px;">Color Code & Name *</th>
                                    <?php foreach ($sizes as $s): ?>
                                        <th style="min-width: 85px;">
                                            <div><?= clean($s['name']) ?></div>
                                            <span class="badge bg-secondary-subtle text-secondary"><?= clean($s['code']) ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                    <th style="min-width: 100px;" class="table-active">Color Total</th>
                                    <th style="width: 50px;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="matrixGridTbody">
                                <tr class="matrix-row" data-row-index="0">
                                    <td class="text-center fw-bold text-muted mrow-num">1</td>
                                    <td>
                                        <select name="matrix_rows[0][color_id]" class="form-select form-select-sm mcolor-select fw-semibold">
                                            <option value="">-- Choose Color Code --</option>
                                            <?php foreach ($colors as $c): ?>
                                                <option value="<?= $c['id'] ?>" data-code="<?= clean($c['color_code']) ?>" data-hex="<?= clean($c['hex_code']) ?>">
                                                    <?= clean($c['color_code'] ?: $c['name']) ?> - <?= clean($c['name']) ?> (<?= (float)$c['yarn_stock_kg'] ?> KG)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <?php foreach ($sizes as $s): ?>
                                        <td>
                                            <input type="number" 
                                                   name="matrix_rows[0][sizes][<?= $s['id'] ?>]" 
                                                   class="form-control form-control-sm text-center mcell-input" 
                                                   min="0" 
                                                   placeholder="0"
                                                   data-size="<?= $s['id'] ?>">
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="table-active fw-bold text-primary mrow-total">0</td>
                                    <td>
                                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 btn-remove-mrow" title="Remove Color Row">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="2" class="text-start ps-3">Size Totals</td>
                                    <?php foreach ($sizes as $s): ?>
                                        <td id="msize_total_<?= $s['id'] ?>">0</td>
                                    <?php endforeach; ?>
                                    <td class="table-primary text-primary fs-6" id="mfooterGrandTotal">0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ========================================== -->
            <!-- 3. ACTIONS & SUBMISSION BAR -->
            <!-- ========================================== -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 bg-white border rounded-3 shadow-sm mb-4">
                <div class="d-flex align-items-center gap-4 text-muted small">
                    <div>Selected Colors: <strong id="summaryColors" class="text-dark">0</strong></div>
                    <div>Selected Sizes: <strong id="summarySizes" class="text-dark">0</strong></div>
                    <div>Total Planned Pieces: <strong id="summaryPieces" class="text-primary fs-5">0</strong></div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= BASE_URL ?>modules/lots/index.php" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" id="btnSubmitLot">
                        <i class="bi bi-check2-circle me-1"></i> Save & Launch Lot
                    </button>
                </div>
            </div>

        </form>
    </div> <!-- end app-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const lotNumberInput = document.getElementById('lot_number');
    const lotUniquenessBadge = document.getElementById('lotUniquenessBadge');
    const btnGenLot = document.getElementById('btnGenLot');
    const shiftSingle = document.getElementById('shiftSingle');
    const shiftDouble = document.getElementById('shiftDouble');
    const nightOperatorContainer = document.getElementById('nightOperatorContainer');
    const hasAnatomySwitch = document.getElementById('has_anatomy_variants');
    const anatomyInputs = document.getElementById('anatomyBreakdownInputs');
    const withSleevesInput = document.getElementById('with_sleeves_qty');
    const sleevelessInput = document.getElementById('sleeveless_qty');
    const lblAnatomySum = document.getElementById('lblAnatomySum');
    const anatomyMatchStatus = document.getElementById('anatomyMatchStatus');
    const modeSingleSize = document.getElementById('modeSingleSize');
    const modeMultiSize = document.getElementById('modeMultiSize');
    const singleSizeContainer = document.getElementById('singleSizeContainer');
    const multiSizeContainer = document.getElementById('multiSizeContainer');
    const entryModeInput = document.getElementById('entry_mode');
    const quickColorCodeInput = document.getElementById('quickColorCodeInput');
    const singleColorsTbody = document.getElementById('singleColorsTbody');
    const productSelect = document.getElementById('product_id');

    // 1. Shift Toggle (Single vs Double Shift)
    function updateShiftView() {
        if (shiftDouble.checked) {
            nightOperatorContainer.classList.remove('d-none');
        } else {
            nightOperatorContainer.classList.add('d-none');
        }
    }
    shiftSingle.addEventListener('change', updateShiftView);
    shiftDouble.addEventListener('change', updateShiftView);

    // 2. Real-time Unique Lot Number Verification (Debounced)
    let lotCheckTimer = null;
    function checkLotUniqueness() {
        const val = lotNumberInput.value.trim();
        if (!val) {
            lotUniquenessBadge.className = 'small fw-semibold text-danger';
            lotUniquenessBadge.textContent = 'Required';
            return;
        }
        lotUniquenessBadge.className = 'small fw-semibold text-muted';
        lotUniquenessBadge.textContent = 'Checking...';

        fetch(`<?= BASE_URL ?>modules/lots/ajax.php?action=check_lot_number&lot_number=${encodeURIComponent(val)}`)
            .then(res => res.json())
            .then(data => {
                if (data.is_unique) {
                    lotUniquenessBadge.className = 'small fw-semibold text-success';
                    lotUniquenessBadge.innerHTML = '<i class="bi bi-check-circle-fill"></i> Available & Unique';
                    lotNumberInput.classList.remove('is-invalid');
                    lotNumberInput.classList.add('is-valid');
                } else {
                    lotUniquenessBadge.className = 'small fw-semibold text-danger';
                    lotUniquenessBadge.innerHTML = '<i class="bi bi-x-circle-fill"></i> Already in use!';
                    lotNumberInput.classList.remove('is-valid');
                    lotNumberInput.classList.add('is-invalid');
                }
            })
            .catch(() => {
                lotUniquenessBadge.textContent = '';
            });
    }

    lotNumberInput.addEventListener('input', function() {
        clearTimeout(lotCheckTimer);
        lotCheckTimer = setTimeout(checkLotUniqueness, 300);
    });
    checkLotUniqueness();

    // Generate New Unique Lot Number
    btnGenLot.addEventListener('click', function() {
        const d = new Date();
        const rand = Math.floor(1000 + Math.random() * 9000);
        lotNumberInput.value = `LOT-${d.getFullYear()}-${rand}`;
        checkLotUniqueness();
    });

    // 3. Sizing Mode Switching (Single Size vs Multi Size)
    function updateSizeMode() {
        if (modeSingleSize.checked) {
            singleSizeContainer.classList.remove('d-none');
            multiSizeContainer.classList.add('d-none');
            entryModeInput.value = 'single_fast';
        } else {
            singleSizeContainer.classList.add('d-none');
            multiSizeContainer.classList.remove('d-none');
            entryModeInput.value = 'multi_matrix';
        }
        recalcAll();
    }
    modeSingleSize.addEventListener('change', updateSizeMode);
    modeMultiSize.addEventListener('change', updateSizeMode);

    // 4. Style Anatomy Breakdown (With Sleeves vs Sleeveless)
    hasAnatomySwitch.addEventListener('change', function() {
        if (this.checked) {
            anatomyInputs.classList.remove('d-none');
            // Default 100% to sleeved if empty
            const total = parseInt(document.getElementById('lotGrandTotal').textContent, 10) || 0;
            if (parseInt(withSleevesInput.value, 10) === 0 && parseInt(sleevelessInput.value, 10) === 0 && total > 0) {
                withSleevesInput.value = total;
            }
        } else {
            anatomyInputs.classList.add('d-none');
        }
        validateAnatomyBreakdown();
    });

    function validateAnatomyBreakdown() {
        if (!hasAnatomySwitch.checked) return;
        const total = parseInt(document.getElementById('lotGrandTotal').textContent, 10) || 0;
        const s1 = parseInt(withSleevesInput.value, 10) || 0;
        const s2 = parseInt(sleevelessInput.value, 10) || 0;
        const sum = s1 + s2;
        lblAnatomySum.textContent = sum;

        if (sum === total && total > 0) {
            anatomyMatchStatus.className = 'p-2 rounded bg-success-subtle text-success border border-success small fw-semibold text-center';
            anatomyMatchStatus.innerHTML = `✓ Sum: ${sum} pcs (Matches Lot Total!)`;
        } else {
            anatomyMatchStatus.className = 'p-2 rounded bg-warning-subtle text-warning-emphasis border border-warning small fw-semibold text-center';
            anatomyMatchStatus.innerHTML = `⚠️ Sum: ${sum} / ${total} pcs (Does not match lot total)`;
        }
    }

    withSleevesInput.addEventListener('input', validateAnatomyBreakdown);
    sleevelessInput.addEventListener('input', validateAnatomyBreakdown);

    // 5. Smart Color Chips One-Click Addition (Double Sorted)
    document.querySelectorAll('.color-quick-chip').forEach(btn => {
        btn.addEventListener('click', function() {
            const cId = this.dataset.colorId;
            const cCode = this.dataset.colorCode;
            const cName = this.dataset.colorName;
            const hex = this.dataset.hex;
            const yarn = this.dataset.yarn;

            // Check if already in table
            const existingRow = singleColorsTbody.querySelector(`tr[data-color-id="${cId}"]`);
            if (existingRow) {
                const qtyInput = existingRow.querySelector('.single-qty-input');
                qtyInput.focus();
                qtyInput.select();
                return;
            }

            // Append new row
            const idx = singleColorsTbody.querySelectorAll('.single-color-row').length;
            const tr = document.createElement('tr');
            tr.className = 'single-color-row';
            tr.dataset.colorId = cId;
            tr.innerHTML = `
                <td class="text-center fw-bold text-muted srow-num">${idx + 1}</td>
                <td class="text-start">
                    <input type="hidden" name="single_colors[${idx}][color_id]" value="${cId}">
                    <input type="hidden" name="single_colors[${idx}][color_code]" value="${cCode}">
                    <span class="badge bg-dark font-monospace fs-6 px-2 py-1">${cCode}</span>
                </td>
                <td class="text-start">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rounded-circle border" style="width: 14px; height: 14px; background-color: ${hex};"></span>
                        <span class="fw-semibold">${cName}</span>
                    </div>
                </td>
                <td>
                    <span class="badge bg-light text-dark border">${parseFloat(yarn).toFixed(2)} KG Yarn</span>
                </td>
                <td>
                    <div class="input-group">
                        <input type="number" name="single_colors[${idx}][quantity]" class="form-control text-center fw-bold single-qty-input fs-5" min="1" placeholder="0">
                        <span class="input-group-text small text-muted">pcs</span>
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-danger btn-sm btn-remove-single-row" title="Remove Color">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
            singleColorsTbody.appendChild(tr);
            renumberSingleRows();
            const input = tr.querySelector('.single-qty-input');
            input.focus();
            recalcAll();
        });
    });

    // Quick Type Color Code & Enter
    quickColorCodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const code = this.value.trim().toUpperCase();
            if (!code) return;

            let matchedChip = null;
            document.querySelectorAll('.color-quick-chip').forEach(chip => {
                if (chip.dataset.colorCode.toUpperCase() === code || chip.dataset.colorName.toUpperCase() === code) {
                    matchedChip = chip;
                }
            });

            if (matchedChip) {
                matchedChip.click();
                this.value = '';
            } else {
                // Register on the fly
                registerCustomColorCode(code);
            }
        }
    });

    document.getElementById('btnQuickAddCustomColor').addEventListener('click', function() {
        const code = prompt('Enter New Yarn / Color Code (e.g. 1140):');
        if (code) {
            registerCustomColorCode(code.trim());
        }
    });

    function registerCustomColorCode(code) {
        const formData = new FormData();
        formData.append('color_code', code);
        formData.append('name', 'Shade ' + code);
        formData.append('yarn_stock_kg', '100.00');

        fetch(`<?= BASE_URL ?>modules/lots/ajax.php?action=quick_add_color`, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success' && res.color) {
                const c = res.color;
                // Add chip and click it
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-dark bg-white d-flex align-items-center gap-2 p-2 color-quick-chip';
                btn.dataset.colorId = c.id;
                btn.dataset.colorCode = c.color_code;
                btn.dataset.colorName = c.name;
                btn.dataset.hex = c.hex_code;
                btn.dataset.yarn = c.yarn_stock_kg;
                btn.innerHTML = `
                    <span class="rounded-circle border" style="width: 14px; height: 14px; background-color: ${c.hex_code};"></span>
                    <strong class="font-monospace text-primary">${c.color_code}</strong>
                    <span class="text-secondary small">${c.name}</span>
                    <span class="badge bg-secondary-subtle text-secondary border small">${parseFloat(c.yarn_stock_kg).toFixed(2)} KG</span>
                `;
                document.getElementById('colorChipsContainer').prepend(btn);
                btn.click();
                quickColorCodeInput.value = '';
            }
        });
    }

    // Remove single row
    singleColorsTbody.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-remove-single-row');
        if (btn) {
            btn.closest('tr').remove();
            renumberSingleRows();
            recalcAll();
        }
    });

    function renumberSingleRows() {
        singleColorsTbody.querySelectorAll('.single-color-row').forEach((tr, i) => {
            tr.querySelector('.srow-num').textContent = i + 1;
            const cIdInput = tr.querySelector('input[name*="[color_id]"]');
            const cCodeInput = tr.querySelector('input[name*="[color_code]"]');
            const qInput = tr.querySelector('input[name*="[quantity]"]');
            if (cIdInput) cIdInput.name = `single_colors[${i}][color_id]`;
            if (cCodeInput) cCodeInput.name = `single_colors[${i}][color_code]`;
            if (qInput) qInput.name = `single_colors[${i}][quantity]`;
        });
    }

    // 6. Multi-Size Matrix Handlers
    const matrixGridTbody = document.getElementById('matrixGridTbody');
    document.getElementById('btnAddMatrixColorRow').addEventListener('click', function() {
        const count = matrixGridTbody.querySelectorAll('.matrix-row').length;
        const firstRow = matrixGridTbody.querySelector('.matrix-row');
        const clone = firstRow.cloneNode(true);
        clone.dataset.rowIndex = count;
        clone.querySelector('.mrow-num').textContent = count + 1;
        const sel = clone.querySelector('.mcolor-select');
        sel.name = `matrix_rows[${count}][color_id]`;
        sel.value = '';
        clone.querySelectorAll('.mcell-input').forEach(inp => {
            const sizeId = inp.dataset.size;
            inp.name = `matrix_rows[${count}][sizes][${sizeId}]`;
            inp.value = '';
        });
        clone.querySelector('.mrow-total').textContent = '0';
        matrixGridTbody.appendChild(clone);
        recalcAll();
    });

    matrixGridTbody.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-remove-mrow');
        if (btn) {
            const rows = matrixGridTbody.querySelectorAll('.matrix-row');
            if (rows.length > 1) {
                btn.closest('tr').remove();
                matrixGridTbody.querySelectorAll('.matrix-row').forEach((tr, i) => {
                    tr.querySelector('.mrow-num').textContent = i + 1;
                });
                recalcAll();
            }
        }
    });

    // 7. Global Calculations
    function recalcAll() {
        let grandTotal = 0;
        let colorCount = 0;
        let sizeCount = 0;

        if (modeSingleSize.checked) {
            const rows = singleColorsTbody.querySelectorAll('.single-color-row');
            rows.forEach(tr => {
                const qty = parseInt(tr.querySelector('.single-qty-input').value, 10) || 0;
                if (qty > 0) {
                    grandTotal += qty;
                    colorCount++;
                }
            });
            sizeCount = grandTotal > 0 ? 1 : 0;
        } else {
            const rows = matrixGridTbody.querySelectorAll('.matrix-row');
            const usedSizes = new Set();
            rows.forEach(tr => {
                let rowTotal = 0;
                const cId = tr.querySelector('.mcolor-select').value;
                tr.querySelectorAll('.mcell-input').forEach(inp => {
                    const q = parseInt(inp.value, 10) || 0;
                    if (q > 0) {
                        rowTotal += q;
                        usedSizes.add(inp.dataset.size);
                    }
                });
                tr.querySelector('.mrow-total').textContent = rowTotal;
                if (rowTotal > 0 && cId) {
                    colorCount++;
                }
                grandTotal += rowTotal;
            });
            sizeCount = usedSizes.size;
        }

        document.getElementById('lotGrandTotal').textContent = grandTotal;
        document.getElementById('summaryPieces').textContent = grandTotal;
        document.getElementById('summaryColors').textContent = colorCount;
        document.getElementById('summarySizes').textContent = sizeCount;

        validateAnatomyBreakdown();
    }

    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('single-qty-input') || e.target.classList.contains('mcell-input')) {
            recalcAll();
        }
    });

    // 8. Product Selection Specs Preview
    productSelect.addEventListener('change', function() {
        const pId = this.value;
        const preview = document.getElementById('productSpecsPreview');
        if (!pId) {
            preview.classList.add('d-none');
            return;
        }

        fetch(`<?= BASE_URL ?>modules/lots/ajax.php?action=get_product_details&product_id=${pId}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success' && data.product) {
                    preview.classList.remove('d-none');
                    const p = data.product;
                    const badgesContainer = document.getElementById('prodElementsBadges');
                    badgesContainer.innerHTML = '';
                    const elements = p.elements || [];
                    document.getElementById('lblProdElementsBadge').textContent = `${elements.length} parts configured`;
                    elements.forEach(el => {
                        const b = document.createElement('span');
                        b.className = 'badge bg-white text-dark border px-2 py-1 small';
                        b.innerHTML = `<i class="bi bi-tag text-primary me-1"></i>${el.element_name}: <strong>${el.pieces_per_garment} pcs</strong>`;
                        badgesContainer.appendChild(b);
                    });

                    const recipeContainer = document.getElementById('prodRecipeDetails');
                    const recipe = p.recipe;
                    const items = p.recipe_items || [];
                    if (recipe && items.length > 0) {
                        document.getElementById('lblRecipeStatus').textContent = `Recipe: ${recipe.name}`;
                        let html = '<div class="d-flex flex-wrap gap-2">';
                        items.forEach(it => {
                            html += `<span class="badge bg-white text-dark border px-2 py-1 small">
                                        <i class="bi bi-box-seam text-warning me-1"></i>${it.material_name}: <strong>${it.quantity_required} ${it.unit_code}</strong>/pc
                                     </span>`;
                        });
                        html += '</div>';
                        recipeContainer.innerHTML = html;
                    } else {
                        document.getElementById('lblRecipeStatus').textContent = 'No standard recipe';
                        recipeContainer.innerHTML = '<span class="text-muted small fst-italic">Standard production estimates will apply.</span>';
                    }
                }
            });
    });

    if (productSelect.value) {
        productSelect.dispatchEvent(new Event('change'));
    }

    recalcAll();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>