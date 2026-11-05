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

// Fetch ONLY Machine Operators for Lot Assignment
$operatorSql = "SELECT DISTINCT w.id, w.code, w.name, w.worker_type, w.rate_per_minute 
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

// Fallback to all active workers only if no specific operator is configured yet
if (empty($operators)) {
    $operators = $db->query("SELECT id, code, name, worker_type, rate_per_minute FROM workers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
}

// Fetch Colors double-sorted: 1st by Historical Lot Frequency, 2nd by Available Yarn Stock, 3rd by Code
$colorSql = "SELECT c.*, 
                    COALESCE((SELECT COUNT(DISTINCT lv.lot_id) FROM lot_variants lv WHERE lv.color_id = c.id), 0) AS usage_count
             FROM colors c 
             ORDER BY usage_count DESC, c.yarn_stock_kg DESC, c.color_code ASC";
$colors = $db->query($colorSql)->fetchAll();

// Fetch Sizes
$sizes = $db->query("SELECT * FROM sizes ORDER BY sort_order ASC, id ASC")->fetchAll();

// Prefetch all product elements for instant real-time anatomy and cutting calculation
$allProductElements = $db->query("SELECT * FROM product_elements ORDER BY product_id ASC, sort_order ASC, id ASC")->fetchAll();
$productElementsMap = [];
foreach ($allProductElements as $pe) {
    $productElementsMap[$pe['product_id']][] = [
        'id' => (int)$pe['id'],
        'name' => $pe['element_name'],
        'quantity' => (int)$pe['quantity'],
        'notes' => $pe['notes'] ?? ''
    ];
}

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

            // 1. Process Itemized Rows (Color Dropdown + Size Dropdown per row)
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

            // 2. Fallback: Process Single Colors (if items was empty)
            if (empty($aggregated) && !empty($singleColors) && is_array($singleColors)) {
                $fallbackSizeId = !empty($singleSizeId) ? $singleSizeId : (!empty($sizes) ? (int)$sizes[0]['id'] : 1);
                foreach ($singleColors as $sc) {
                    $cId = (int)($sc['color_id'] ?? 0);
                    $q = (int)($sc['quantity'] ?? 0);
                    $cCode = trim($sc['color_code'] ?? '');
                    if ($cId > 0 && $q > 0) {
                        $key = $cId . '_' . $fallbackSizeId;
                        if (isset($aggregated[$key])) {
                            $aggregated[$key]['quantity'] += $q;
                        } else {
                            $aggregated[$key] = [
                                'color_id' => $cId,
                                'color_code' => $cCode,
                                'size_id' => $fallbackSizeId,
                                'quantity' => $q
                            ];
                        }
                        $usedColors[$cId] = true;
                        $usedSizes[$fallbackSizeId] = true;
                        $totalPieces += $q;
                    }
                }
            }

            // 3. Fallback: Process Dynamic Matrix Rows (if items was empty)
            if (empty($aggregated) && !empty($matrixRows) && is_array($matrixRows)) {
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

            $validVariants = array_values($aggregated);
            $totalColors = count($usedColors);
            $totalSizes = count($usedSizes);
            if ($totalSizes === 1) {
                $sizeMode = 'Single Size';
                $singleSizeId = (int)array_key_first($usedSizes);
            } else {
                $sizeMode = 'Multi Size';
                $singleSizeId = null;
            }

            if ($totalPieces <= 0 || empty($validVariants)) {
                $error = 'Please enter valid planned quantities for at least one color.';
            } elseif ($hasAnatomyVariants && ($sleevedQty + $sleevelessQty) !== $totalPieces) {
                $error = "Style Anatomy breakdown sum (" . ($sleevedQty + $sleevelessQty) . " pcs) must match Total Planned Pieces ($totalPieces pcs).";
            } else {
                // Compute calculated element requirements for permanent lot tracking
                if ($hasAnatomyVariants) {
                    $prodElements = $productElementsMap[$productId] ?? [];
                    $calcReq = calculate_lot_element_requirements(
                        $prodElements,
                        $totalPieces,
                        true,
                        ['with_sleeves' => $sleevedQty, 'sleeveless' => $sleevelessQty]
                    );
                    $anatomyBreakdown = json_encode([
                        'with_sleeves' => $sleevedQty,
                        'sleeveless' => $sleevelessQty,
                        'notes' => trim($_POST['anatomy_notes'] ?? ''),
                        'elements' => $calcReq['elements'],
                        'total_cut_pieces' => $calcReq['total_cut_pieces']
                    ], JSON_UNESCAPED_UNICODE);
                }

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

                                    <!-- Real-time Automatic Cutting Elements Calculation -->
                                    <div id="cuttingPartsLivePreview" class="mt-3 pt-3 border-top">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                            <div class="fw-bold small text-dark d-flex align-items-center gap-1">
                                                <i class="bi bi-scissors text-primary"></i>
                                                <span>خودکار کٹنگ کے اجزاء کا حساب (Automatic Cutting Components Allocation)</span>
                                            </div>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="lblTotalCutPcsBadge">
                                                Total Parts to Cut: 0 pcs
                                            </span>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered align-middle mb-0 bg-white" style="font-size: 0.82rem;">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Component / حصہ</th>
                                                        <th class="text-center" style="width: 100px;">فی پیس تعداد</th>
                                                        <th class="text-center" style="width: 130px;">ٹارگٹ پیسز</th>
                                                        <th class="text-center" style="width: 160px;">بیچ کے لیے کٹنگ</th>
                                                        <th>حساب کا طریقہ (Formula)</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="tblCuttingPartsBody">
                                                    <tr><td colspan="5" class="text-center text-muted py-2">پروڈکٹ منتخب کریں اور تعداد درج کریں تاکہ کٹنگ کا حساب خودکار ظاہر ہو۔</td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- 2. PLANNED COLORS & SIZES CARD (DROPDOWN-BASED SELECTION) -->
            <!-- ======================================================== -->
            <div class="card-modern mb-4 shadow-sm">
                <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-palette-fill text-primary me-1"></i> 2. Planned Colors & Sizes (رنگ اور سائز کی تعداد)
                        </h6>
                        <span class="text-muted small">Select the specific colors and sizes to produce for this lot</span>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <!-- Quick Size for All -->
                        <div class="d-flex align-items-center gap-1">
                            <label class="small text-muted mb-0 fw-semibold">Quick Set Size:</label>
                            <select id="quickSetAllSize" class="form-select form-select-sm" style="width: auto; min-width: 140px;">
                                <option value="">-- Apply to All Rows --</option>
                                <?php foreach ($sizes as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= clean($s['name']) ?> (<?= clean($s['code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Grand Total Badge -->
                        <div class="badge bg-primary fs-6 px-3 py-2 shadow-sm">
                            Total: <span id="lotGrandTotal">0</span> Pieces
                        </div>
                    </div>
                </div>

                <div class="card-modern-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0" id="lotItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 50px;">#</th>
                                    <th style="min-width: 280px;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold text-dark">Color / Shade (رنگ کا انتخاب) *</span>
                                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" id="btnQuickAddCustomColor" style="font-size: 11px;" title="Register New Color Code">
                                                <i class="bi bi-plus-lg"></i> New Color Code
                                            </button>
                                        </div>
                                    </th>
                                    <th style="min-width: 180px;" class="fw-bold text-dark">Size (سائز منتخب کریں) *</th>
                                    <th class="text-center" style="width: 150px;" class="fw-bold text-dark">Available Yarn Stock</th>
                                    <th class="text-center" style="width: 190px;" class="fw-bold text-dark">Planned Pieces (تعداد) *</th>
                                    <th class="text-center" style="width: 60px;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="lotItemsTbody">
                                <?php
                                $defaultRow1Color = $colors[0]['id'] ?? 0;
                                $defaultRow2Color = $colors[1]['id'] ?? ($colors[0]['id'] ?? 0);
                                $defaultSize = $sizes[0]['id'] ?? 0;
                                ?>
                                <!-- Default Row 1 -->
                                <tr class="lot-item-row" data-row-index="0">
                                    <td class="text-center fw-bold text-muted row-num">1</td>
                                    <td>
                                        <select name="items[0][color_id]" class="form-select fw-semibold item-color-select" required>
                                            <option value="">-- Choose Color --</option>
                                            <?php foreach ($colors as $c): ?>
                                                <option value="<?= $c['id'] ?>" 
                                                        data-code="<?= clean($c['color_code'] ?: $c['name']) ?>" 
                                                        data-hex="<?= clean($c['hex_code']) ?>" 
                                                        data-yarn="<?= (float)$c['yarn_stock_kg'] ?>"
                                                        <?= ($c['id'] == $defaultRow1Color) ? 'selected' : '' ?>>
                                                    <?= clean($c['color_code'] ?: $c['name']) ?> - <?= clean($c['name']) ?> (<?= (float)$c['yarn_stock_kg'] ?> KG)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="items[0][color_code]" class="item-color-code" value="<?= clean($colors[0]['color_code'] ?? '') ?>">
                                    </td>
                                    <td>
                                        <select name="items[0][size_id]" class="form-select fw-semibold item-size-select" required>
                                            <option value="">-- Choose Size --</option>
                                            <?php foreach ($sizes as $s): ?>
                                                <option value="<?= $s['id'] ?>" <?= ($s['id'] == $defaultSize) ? 'selected' : '' ?>>
                                                    <?= clean($s['name']) ?> (<?= clean($s['code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <div class="item-yarn-badge d-inline-flex align-items-center gap-2 px-2 py-1 bg-light border rounded">
                                            <span class="item-color-dot rounded-circle border" style="width: 14px; height: 14px; background-color: <?= clean($colors[0]['hex_code'] ?? '#ccc') ?>;"></span>
                                            <span class="item-yarn-text fw-semibold small text-dark"><?= (float)($colors[0]['yarn_stock_kg'] ?? 0) ?> KG</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="items[0][quantity]" class="form-control text-center fw-bold item-qty-input fs-5" min="1" placeholder="0" required>
                                            <span class="input-group-text small text-muted">pcs</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-item-row" title="Remove Row">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Default Row 2 -->
                                <tr class="lot-item-row" data-row-index="1">
                                    <td class="text-center fw-bold text-muted row-num">2</td>
                                    <td>
                                        <select name="items[1][color_id]" class="form-select fw-semibold item-color-select" required>
                                            <option value="">-- Choose Color --</option>
                                            <?php foreach ($colors as $c): ?>
                                                <option value="<?= $c['id'] ?>" 
                                                        data-code="<?= clean($c['color_code'] ?: $c['name']) ?>" 
                                                        data-hex="<?= clean($c['hex_code']) ?>" 
                                                        data-yarn="<?= (float)$c['yarn_stock_kg'] ?>"
                                                        <?= ($c['id'] == $defaultRow2Color) ? 'selected' : '' ?>>
                                                    <?= clean($c['color_code'] ?: $c['name']) ?> - <?= clean($c['name']) ?> (<?= (float)$c['yarn_stock_kg'] ?> KG)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="items[1][color_code]" class="item-color-code" value="<?= clean($colors[1]['color_code'] ?? ($colors[0]['color_code'] ?? '')) ?>">
                                    </td>
                                    <td>
                                        <select name="items[1][size_id]" class="form-select fw-semibold item-size-select" required>
                                            <option value="">-- Choose Size --</option>
                                            <?php foreach ($sizes as $s): ?>
                                                <option value="<?= $s['id'] ?>" <?= ($s['id'] == $defaultSize) ? 'selected' : '' ?>>
                                                    <?= clean($s['name']) ?> (<?= clean($s['code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <div class="item-yarn-badge d-inline-flex align-items-center gap-2 px-2 py-1 bg-light border rounded">
                                            <span class="item-color-dot rounded-circle border" style="width: 14px; height: 14px; background-color: <?= clean($colors[1]['hex_code'] ?? ($colors[0]['hex_code'] ?? '#ccc')) ?>;"></span>
                                            <span class="item-yarn-text fw-semibold small text-dark"><?= (float)($colors[1]['yarn_stock_kg'] ?? ($colors[0]['yarn_stock_kg'] ?? 0)) ?> KG</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="items[1][quantity]" class="form-control text-center fw-bold item-qty-input fs-5" min="1" placeholder="0" required>
                                            <span class="input-group-text small text-muted">pcs</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-item-row" title="Remove Row">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold text-dark">
                                        Total Planned Batch Quantity:
                                    </td>
                                    <td class="text-center">
                                        <div class="fw-bold fs-5 text-primary">
                                            <span id="tblFooterTotalPieces">0</span> <span class="fs-6 text-muted font-normal">pcs</span>
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Bottom Bar with Add Row Button -->
                    <div class="p-3 bg-light border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <button type="button" class="btn btn-primary fw-semibold shadow-sm" id="btnAddItemRow">
                            <i class="bi bi-plus-circle me-1"></i> Add Row (+ نئی رو شامل کریں)
                        </button>
                        <div class="text-muted small">
                            <i class="bi bi-lightbulb text-warning me-1"></i> Select only the colors and sizes you are producing for this batch. Add as many rows as needed.
                        </div>
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
    const productElementsData = <?= json_encode($productElementsMap) ?>;
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

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text ? String(text).replace(/[&<>"']/g, m => map[m]) : '';
    }

    function updateCuttingPartsPreview() {
        const prodId = parseInt(productSelect.value, 10) || 0;
        const total = parseInt(document.getElementById('lotGrandTotal').textContent, 10) || 0;
        const hasAnatomy = hasAnatomySwitch.checked;
        const s1 = parseInt(withSleevesInput.value, 10) || 0;
        const s2 = parseInt(sleevelessInput.value, 10) || 0;
        const tbody = document.getElementById('tblCuttingPartsBody');
        const badge = document.getElementById('lblTotalCutPcsBadge');
        if (!tbody) return;

        const elements = productElementsData[prodId] || [];
        if (elements.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-2">اس پروڈکٹ کے لیے کوئی پیٹرن اجزاء تشکیل نہیں دیے گئے۔</td></tr>`;
            if (badge) badge.textContent = `Total Parts to Cut: 0 pcs`;
            return;
        }

        let totalCutPcs = 0;
        let html = '';

        elements.forEach(el => {
            const isSleeve = /(sleeve|بازو|bazu|aasteen|آستین)/i.test(el.name);
            let target = total;
            let basis = `تمام ${total} پیسز کے لیے (${el.quantity} × ${total})`;

            if (hasAnatomy && isSleeve) {
                target = s1;
                basis = `<span class="badge bg-primary-subtle text-primary border border-primary">${s1} بازو والے پیسز (${el.quantity} × ${s1})</span>`;
            }

            const batchCut = el.quantity * target;
            totalCutPcs += batchCut;

            html += `
                <tr>
                    <td class="fw-bold text-dark">
                        <i class="bi bi-scissors text-primary me-1"></i> ${escapeHtml(el.name)}
                    </td>
                    <td class="text-center fw-semibold text-muted">${el.quantity} pcs</td>
                    <td class="text-center fw-bold ${hasAnatomy && isSleeve ? 'text-primary' : 'text-dark'}">${target} pcs</td>
                    <td class="text-center fw-bold text-success fs-6">${batchCut.toLocaleString()} pcs</td>
                    <td class="small text-muted">${basis}</td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        if (badge) {
            badge.textContent = `Total Parts to Cut: ${totalCutPcs.toLocaleString()} pcs`;
        }
    }

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

    // 3. Colors and Sizes Data for Dynamic Dropdowns
    const colorsList = <?= json_encode($colors) ?>;
    const sizesList = <?= json_encode($sizes) ?>;

    function buildColorOptionsHtml(selectedId = '') {
        let html = '<option value="">-- Choose Color --</option>';
        colorsList.forEach(c => {
            const sel = (c.id == selectedId) ? 'selected' : '';
            const code = c.color_code || c.name;
            const yarn = parseFloat(c.yarn_stock_kg || 0).toFixed(2);
            html += `<option value="${c.id}" data-code="${escapeHtml(code)}" data-hex="${escapeHtml(c.hex_code)}" data-yarn="${yarn}" ${sel}>
                ${escapeHtml(code)} - ${escapeHtml(c.name)} (${yarn} KG)
            </option>`;
        });
        return html;
    }

    function buildSizeOptionsHtml(selectedId = '') {
        let html = '<option value="">-- Choose Size --</option>';
        sizesList.forEach(s => {
            const sel = (s.id == selectedId) ? 'selected' : '';
            html += `<option value="${s.id}" ${sel}>
                ${escapeHtml(s.name)} (${escapeHtml(s.code)})
            </option>`;
        });
        return html;
    }

    // 4. Style Anatomy Breakdown (With Sleeves vs Sleeveless)
    hasAnatomySwitch.addEventListener('change', function() {
        if (this.checked) {
            anatomyInputs.classList.remove('d-none');
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
        const total = parseInt(document.getElementById('lotGrandTotal').textContent, 10) || 0;
        if (hasAnatomySwitch.checked) {
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
        updateCuttingPartsPreview();
    }

    withSleevesInput.addEventListener('input', validateAnatomyBreakdown);
    sleevelessInput.addEventListener('input', validateAnatomyBreakdown);

    // 5. Dynamic Row Addition (+ Add Row)
    const btnAddItemRow = document.getElementById('btnAddItemRow');
    const lotItemsTbody = document.getElementById('lotItemsTbody');

    if (btnAddItemRow && lotItemsTbody) {
        btnAddItemRow.addEventListener('click', function() {
            const rows = lotItemsTbody.querySelectorAll('.lot-item-row');
            const newIdx = rows.length;

            // Default size to previous row's selected size
            let defaultSizeId = '';
            if (rows.length > 0) {
                const lastRow = rows[rows.length - 1];
                const sSel = lastRow.querySelector('.item-size-select');
                if (sSel && sSel.value) defaultSizeId = sSel.value;
            }
            if (!defaultSizeId && sizesList.length > 0) {
                defaultSizeId = sizesList[0].id;
            }

            const tr = document.createElement('tr');
            tr.className = 'lot-item-row';
            tr.dataset.rowIndex = newIdx;
            tr.innerHTML = `
                <td class="text-center fw-bold text-muted row-num">${newIdx + 1}</td>
                <td>
                    <select name="items[${newIdx}][color_id]" class="form-select fw-semibold item-color-select" required>
                        ${buildColorOptionsHtml('')}
                    </select>
                    <input type="hidden" name="items[${newIdx}][color_code]" class="item-color-code" value="">
                </td>
                <td>
                    <select name="items[${newIdx}][size_id]" class="form-select fw-semibold item-size-select" required>
                        ${buildSizeOptionsHtml(defaultSizeId)}
                    </select>
                </td>
                <td class="text-center">
                    <div class="item-yarn-badge d-inline-flex align-items-center gap-2 px-2 py-1 bg-light border rounded">
                        <span class="item-color-dot rounded-circle border" style="width: 14px; height: 14px; background-color: #ccc;"></span>
                        <span class="item-yarn-text fw-semibold small text-dark">—</span>
                    </div>
                </td>
                <td>
                    <div class="input-group">
                        <input type="number" name="items[${newIdx}][quantity]" class="form-control text-center fw-bold item-qty-input fs-5" min="1" placeholder="0" required>
                        <span class="input-group-text small text-muted">pcs</span>
                    </div>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-outline-danger btn-sm btn-remove-item-row" title="Remove Row">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
            lotItemsTbody.appendChild(tr);
            renumberRows();
            tr.querySelector('.item-color-select').focus();
            recalcAll();
        });

        // Dynamic Row Removal
        lotItemsTbody.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-remove-item-row');
            if (!btn) return;
            const rows = lotItemsTbody.querySelectorAll('.lot-item-row');
            if (rows.length > 1) {
                btn.closest('tr').remove();
                renumberRows();
                recalcAll();
            } else {
                // Clear row instead of deleting last row
                const row = rows[0];
                row.querySelector('.item-color-select').value = '';
                row.querySelector('.item-qty-input').value = '';
                row.querySelector('.item-color-dot').style.backgroundColor = '#ccc';
                row.querySelector('.item-yarn-text').textContent = '—';
                recalcAll();
            }
        });

        // Color & Size change inside rows
        lotItemsTbody.addEventListener('change', function(e) {
            if (e.target.classList.contains('item-color-select')) {
                const sel = e.target;
                const row = sel.closest('tr');
                const opt = sel.options[sel.selectedIndex];
                const code = opt ? (opt.dataset.code || '') : '';
                const hex = opt ? (opt.dataset.hex || '#ccc') : '#ccc';
                const yarn = opt ? (opt.dataset.yarn || '0') : '0';

                const codeInp = row.querySelector('.item-color-code');
                if (codeInp) codeInp.value = code;

                const dot = row.querySelector('.item-color-dot');
                if (dot) dot.style.backgroundColor = hex;

                const yarnText = row.querySelector('.item-yarn-text');
                if (yarnText) yarnText.textContent = sel.value ? `${yarn} KG` : '—';

                recalcAll();
            } else if (e.target.classList.contains('item-size-select')) {
                recalcAll();
            }
        });
    }

    function renumberRows() {
        document.querySelectorAll('#lotItemsTbody tr.lot-item-row').forEach((tr, i) => {
            tr.dataset.rowIndex = i;
            const numCol = tr.querySelector('.row-num');
            if (numCol) numCol.textContent = i + 1;

            const cSel = tr.querySelector('.item-color-select');
            const cCode = tr.querySelector('.item-color-code');
            const sSel = tr.querySelector('.item-size-select');
            const qInp = tr.querySelector('.item-qty-input');

            if (cSel) cSel.name = `items[${i}][color_id]`;
            if (cCode) cCode.name = `items[${i}][color_code]`;
            if (sSel) sSel.name = `items[${i}][size_id]`;
            if (qInp) qInp.name = `items[${i}][quantity]`;
        });
    }

    // 6. Quick Set Size for All Rows
    const quickSetAllSize = document.getElementById('quickSetAllSize');
    if (quickSetAllSize) {
        quickSetAllSize.addEventListener('change', function() {
            const val = this.value;
            if (!val) return;
            document.querySelectorAll('#lotItemsTbody .item-size-select').forEach(sel => {
                sel.value = val;
            });
            this.value = '';
            recalcAll();
        });
    }

    // Register New Color Code On the Fly
    const btnQuickAddCustomColor = document.getElementById('btnQuickAddCustomColor');
    if (btnQuickAddCustomColor) {
        btnQuickAddCustomColor.addEventListener('click', function() {
            const code = prompt('Enter New Yarn / Color Code (e.g. 910 or 823):');
            if (!code || !code.trim()) return;
            const cleanCode = code.trim();
            const name = prompt('Enter Color Name:', 'Shade ' + cleanCode);

            const formData = new FormData();
            formData.append('color_code', cleanCode);
            formData.append('name', name ? name.trim() : ('Shade ' + cleanCode));
            formData.append('yarn_stock_kg', '100.00');

            fetch(`<?= BASE_URL ?>modules/lots/ajax.php?action=quick_add_color`, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success' && res.color) {
                    const c = res.color;
                    colorsList.unshift(c);

                    // Add option to all existing dropdowns
                    document.querySelectorAll('.item-color-select').forEach(sel => {
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.dataset.code = c.color_code || c.name;
                        opt.dataset.hex = c.hex_code || '#6366f1';
                        opt.dataset.yarn = parseFloat(c.yarn_stock_kg || 0).toFixed(2);
                        opt.textContent = `${c.color_code || c.name} - ${c.name} (${opt.dataset.yarn} KG)`;
                        sel.add(opt, 1);
                    });

                    // Select it in the last row
                    const rows = document.querySelectorAll('#lotItemsTbody .lot-item-row');
                    if (rows.length > 0) {
                        const lastRow = rows[rows.length - 1];
                        const sel = lastRow.querySelector('.item-color-select');
                        sel.value = c.id;
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                } else {
                    alert(res.message || 'Error creating color code');
                }
            })
            .catch(() => alert('Network error while registering color.'));
        });
    }

    // 7. Global Calculations
    function recalcAll() {
        let grandTotal = 0;
        const usedColors = new Set();
        const usedSizes = new Set();

        document.querySelectorAll('#lotItemsTbody tr.lot-item-row').forEach(tr => {
            const cSel = tr.querySelector('.item-color-select');
            const sSel = tr.querySelector('.item-size-select');
            const qInp = tr.querySelector('.item-qty-input');

            const cId = cSel ? cSel.value : '';
            const sId = sSel ? sSel.value : '';
            const qty = qInp ? (parseInt(qInp.value, 10) || 0) : 0;

            if (qty > 0) {
                grandTotal += qty;
                if (cId) usedColors.add(cId);
                if (sId) usedSizes.add(sId);
            }
        });

        document.getElementById('lotGrandTotal').textContent = grandTotal;
        const tblFooterTotalPieces = document.getElementById('tblFooterTotalPieces');
        if (tblFooterTotalPieces) tblFooterTotalPieces.textContent = grandTotal.toLocaleString();
        document.getElementById('summaryPieces').textContent = grandTotal;
        document.getElementById('summaryColors').textContent = usedColors.size;
        document.getElementById('summarySizes').textContent = usedSizes.size;

        validateAnatomyBreakdown();
    }

    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('item-qty-input')) {
            recalcAll();
        }
    });

    // 8. Product Selection Specs Preview
    productSelect.addEventListener('change', function() {
        const pId = this.value;
        updateCuttingPartsPreview();
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
                        const pcs = el.pieces_per_garment || el.quantity || 1;
                        b.innerHTML = `<i class="bi bi-tag text-primary me-1"></i>${el.element_name}: <strong>${pcs} pcs</strong>`;
                        badgesContainer.appendChild(b);
                    });

                    const recipeContainer = document.getElementById('prodRecipeDetails');
                    const recipe = p.recipe;
                    const items = p.recipe_items || [];
                    if (recipe && items.length > 0) {
                        const recipeTitle = recipe.description || recipe.name || 'Standard Production Recipe';
                        const wastage = recipe.wastage_percent ? ` (${parseFloat(recipe.wastage_percent)}% buffer)` : '';
                        document.getElementById('lblRecipeStatus').textContent = `Recipe: ${recipeTitle}${wastage}`;
                        let html = '<div class="d-flex flex-wrap gap-2">';
                        items.forEach(it => {
                            const qty = parseFloat(it.quantity_per_piece !== undefined ? it.quantity_per_piece : (it.quantity_required !== undefined ? it.quantity_required : 0));
                            const unit = it.unit_code || it.unit_of_measure || '';
                            html += `<span class="badge bg-white text-dark border px-2 py-1 small">
                                        <i class="bi bi-box-seam text-warning me-1"></i>${it.material_name}: <strong>${qty} ${unit}</strong>/pc
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