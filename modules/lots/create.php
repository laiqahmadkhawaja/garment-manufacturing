<?php
/**
 * Garment Manufacturing ERP - Create New Production Lot
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Create New Lot';
$activeNav = 'create_lot';
$db = get_db();

// Auto-generated Lot Number suggestion
$suggestedLotNumber = generate_doc_number('LOT-', 'lots', 'lot_number');

// Fetch products, colors, sizes, workflows
$products = $db->query("SELECT p.*, c.name AS category_name, wt.name AS template_name 
                        FROM products p 
                        LEFT JOIN product_categories c ON p.category_id = c.id 
                        LEFT JOIN workflow_templates wt ON p.default_workflow_template_id = wt.id
                        WHERE p.is_active = 1 ORDER BY p.name ASC")->fetchAll();

$colors = $db->query("SELECT * FROM colors ORDER BY name ASC")->fetchAll();
$sizes = $db->query("SELECT * FROM sizes ORDER BY sort_order ASC")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lotNumber = trim($_POST['lot_number'] ?? '');
    $lotDate = trim($_POST['lot_date'] ?? date('Y-m-d'));
    $productId = (int)($_POST['product_id'] ?? 0);
    $designNumber = trim($_POST['design_number'] ?? '');
    $designId = !empty($_POST['design_id']) ? (int)$_POST['design_id'] : null;
    $supervisor = trim($_POST['supervisor_name'] ?? '');
    $expectedDate = !empty($_POST['expected_date']) ? trim($_POST['expected_date']) : null;
    $instructions = trim($_POST['instructions'] ?? '');
    
    $entryMode = trim($_POST['entry_mode'] ?? 'itemized');
    $matrix = $_POST['matrix'] ?? [];
    $matrixRows = $_POST['matrix_rows'] ?? [];
    $items = $_POST['items'] ?? [];

    if (empty($lotNumber) || $productId <= 0) {
        $error = 'Lot number and product are required.';
    } else {
        // Check uniqueness of lot_number
        $stmt = $db->prepare("SELECT id FROM lots WHERE lot_number = ?");
        $stmt->execute([$lotNumber]);
        if ($stmt->fetch()) {
            $error = "Lot number '$lotNumber' already exists. Please choose a unique lot number.";
        } else {
            $totalPieces = 0;
            $usedColors = [];
            $usedSizes = [];
            $aggregated = [];

            // 1. Process Itemized Rows (Row has Color dropdown + Size dropdown + Quantity)
            if (!empty($items) && is_array($items)) {
                foreach ($items as $item) {
                    $cId = (int)($item['color_id'] ?? 0);
                    $sId = (int)($item['size_id'] ?? 0);
                    $q = (int)($item['quantity'] ?? 0);

                    if ($cId > 0 && $sId > 0 && $q > 0) {
                        $key = $cId . '_' . $sId;
                        if (isset($aggregated[$key])) {
                            $aggregated[$key]['quantity'] += $q;
                        } else {
                            $aggregated[$key] = [
                                'color_id' => $cId,
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

            // 2. Process Dynamic Matrix Rows (Row has Color dropdown + Size columns)
            if (!empty($matrixRows) && is_array($matrixRows)) {
                foreach ($matrixRows as $row) {
                    $cId = (int)($row['color_id'] ?? 0);
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

            // 3. Fallback to Classic Matrix Grid (matrix[color_id][size_id])
            if (empty($aggregated) && !empty($matrix) && is_array($matrix)) {
                foreach ($matrix as $cId => $sizesArr) {
                    $cId = (int)$cId;
                    if (!is_array($sizesArr) || $cId <= 0) continue;
                    foreach ($sizesArr as $sId => $qty) {
                        $sId = (int)$sId;
                        $q = (int)$qty;
                        if ($sId > 0 && $q > 0) {
                            $key = $cId . '_' . $sId;
                            if (isset($aggregated[$key])) {
                                $aggregated[$key]['quantity'] += $q;
                            } else {
                                $aggregated[$key] = [
                                    'color_id' => $cId,
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

            $validVariants = array_values($aggregated);

            if ($totalPieces <= 0 || empty($validVariants)) {
                $error = 'Please enter valid planned quantities for at least one color and size variant.';
            } else {
                $db->beginTransaction();
                try {
                    $totalColors = count($usedColors);
                    $totalSizes = count($usedSizes);

                    // Insert Lot Master
                    $stmt = $db->prepare("INSERT INTO lots 
                        (lot_number, lot_date, product_id, design_number, design_id, supervisor_name, expected_date, instructions, total_pieces, total_colors, total_sizes, status, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?)");
                    $stmt->execute([
                        $lotNumber,
                        $lotDate,
                        $productId,
                        $designNumber ?: null,
                        $designId,
                        $supervisor,
                        $expectedDate,
                        $instructions,
                        $totalPieces,
                        $totalColors,
                        $totalSizes,
                        $_SESSION['user_id'] ?? null
                    ]);
                    $lotId = (int)$db->lastInsertId();

                    // Insert Lot Variants
                    $vStmt = $db->prepare("INSERT INTO lot_variants (lot_id, color_id, size_id, quantity) VALUES (?, ?, ?, ?)");
                    foreach ($validVariants as $var) {
                        $vStmt->execute([$lotId, $var['color_id'], $var['size_id'], $var['quantity']]);
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

                    // Audit Log & Status History
                    log_audit('CREATE_LOT', 'lots', $lotId, null, ['lot_number' => $lotNumber, 'pieces' => $totalPieces]);
                    $statStmt = $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, NULL, 'Draft', 'Lot created', ?)");
                    $statStmt->execute([$lotId, $_SESSION['user_id'] ?? null]);

                    $db->commit();
                    redirect("modules/lots/view.php?id=$lotId", "Lot #$lotNumber created successfully with $totalPieces pieces!");
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Failed to create lot: ' . $e->getMessage();
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
                <h4 class="fw-bold mb-1 text-dark">Create New Production Lot</h4>
                <p class="text-muted small mb-0">Initial batch setup with planned color/size variants & workflow</p>
            </div>
            <a href="<?= BASE_URL ?>modules/lots/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Lots
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="lotForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="entry_mode" id="entry_mode" value="itemized">

            <!-- Lot Master Details Card -->
            <div class="card-modern mb-4 shadow-sm">
                <div class="card-modern-header">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-info-circle me-1 text-primary"></i> 1. Lot Master Information</h6>
                </div>
                <div class="card-modern-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Lot Number *</label>
                            <input type="text" name="lot_number" id="lot_number" class="form-control fw-bold text-primary" value="<?= clean($_POST['lot_number'] ?? $suggestedLotNumber) ?>" required>
                            <div class="form-text small">Unique factory batch number</div>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Lot Date *</label>
                            <input type="date" name="lot_date" class="form-control" value="<?= clean($_POST['lot_date'] ?? date('Y-m-d')) ?>" required>
                        </div>

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

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">
                                Design Number <span class="badge bg-light text-secondary border fw-normal">Optional</span>
                            </label>
                            <input type="text" name="design_number" id="lot_design_number" class="form-control" placeholder="e.g. 904 or D-101 (Optional)" value="<?= clean($_POST['design_number'] ?? '') ?>">
                            <div class="form-text small">Enter design # or article code (can be edited anytime)</div>
                        </div>

                        <!-- Live Product Pattern Elements & BOM Recipe Preview -->
                        <div class="col-12 d-none" id="productSpecsPreview">
                            <div class="row g-2">
                                <!-- Elements preview -->
                                <div class="col-12 col-lg-6">
                                    <div class="p-3 bg-light border border-primary-subtle rounded-3 h-100">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="small fw-bold text-dark"><i class="bi bi-puzzle-fill text-primary me-1"></i> Product Cutting Elements (اجزاء):</span>
                                            <span class="small text-muted" id="lblProdElementsBadge">0 parts</span>
                                        </div>
                                        <div class="d-flex flex-wrap gap-1 align-items-center" id="prodElementsBadges"></div>
                                    </div>
                                </div>
                                <!-- Recipe / Estimated Raw Materials preview -->
                                <div class="col-12 col-lg-6">
                                    <div class="p-3 bg-light border border-warning-subtle rounded-3 h-100">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="small fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet-fill text-warning me-1"></i> Recipe / Raw Materials Consumption:</span>
                                            <span class="small text-muted" id="lblRecipeStatus"></span>
                                        </div>
                                        <div id="prodRecipeDetails">
                                            <span class="text-muted small fst-italic">Select a product to view recipe requirements</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
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
                            <input type="text" name="instructions" class="form-control" placeholder="Thread color, wash specs, packaging notes" value="<?= clean($_POST['instructions'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Color & Size Quantity Section -->
            <div class="card-modern mb-4 shadow-sm">
                <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary p-2 rounded-2"><i class="bi bi-grid-3x3-gap-fill text-white fs-6"></i></span>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">2. Color & Size Planned Quantities</h6>
                            <span class="text-muted small">Select which colors and sizes you are producing for this batch</span>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <!-- Mode toggle buttons -->
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-primary fw-semibold" id="btnModeItemized">
                                <i class="bi bi-list-check me-1"></i> Row Selection
                            </button>
                            <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnModeMatrix">
                                <i class="bi bi-grid-3x3-gap me-1"></i> Color Grid Matrix
                            </button>
                        </div>
                        <div class="badge bg-primary fs-6 px-3 py-2 shadow-sm">
                            Total: <span id="matrixGrandTotal">0</span> Pieces
                        </div>
                    </div>
                </div>

                <!-- ============================================================= -->
                <!-- MODE 1: ITEMIZED ROWS WITH COLOR & SIZE DROPDOWNS (DEFAULT) -->
                <!-- ============================================================= -->
                <div id="itemizedContainer" class="card-modern-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-center mb-0" id="itemizedTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th class="text-start" style="width: 35%;">Select Color *</th>
                                    <th class="text-start" style="width: 35%;">Select Size *</th>
                                    <th style="width: 20%;">Planned Quantity *</th>
                                    <th style="width: 10%;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="itemizedTbody">
                                <!-- Default Row 1 -->
                                <tr class="variant-row" data-row-index="0">
                                    <td class="text-center fw-bold text-muted row-num">1</td>
                                    <td>
                                        <div class="input-group">
                                            <span class="input-group-text p-1 px-2 color-preview">
                                                <span class="d-inline-block rounded-circle border swatch-dot" style="width: 14px; height: 14px; background-color: #adb5bd;"></span>
                                            </span>
                                            <select name="items[0][color_id]" class="form-select color-select fw-semibold">
                                                <option value="">-- Choose Color --</option>
                                                <?php foreach ($colors as $c): ?>
                                                    <option value="<?= $c['id'] ?>" data-hex="<?= clean($c['hex_code']) ?>">
                                                        <?= clean($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <td>
                                        <select name="items[0][size_id]" class="form-select size-select fw-semibold">
                                            <option value="">-- Choose Size --</option>
                                            <?php foreach ($sizes as $s): ?>
                                                <option value="<?= $s['id'] ?>" data-code="<?= clean($s['code']) ?>">
                                                    <?= clean($s['name']) ?> (<?= clean($s['code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="items[0][quantity]" class="form-control text-center fw-bold row-qty-input" min="1" placeholder="0">
                                            <span class="input-group-text small text-muted">pcs</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-row" title="Remove Row">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- Default Row 2 -->
                                <tr class="variant-row" data-row-index="1">
                                    <td class="text-center fw-bold text-muted row-num">2</td>
                                    <td>
                                        <div class="input-group">
                                            <span class="input-group-text p-1 px-2 color-preview">
                                                <span class="d-inline-block rounded-circle border swatch-dot" style="width: 14px; height: 14px; background-color: #adb5bd;"></span>
                                            </span>
                                            <select name="items[1][color_id]" class="form-select color-select fw-semibold">
                                                <option value="">-- Choose Color --</option>
                                                <?php foreach ($colors as $c): ?>
                                                    <option value="<?= $c['id'] ?>" data-hex="<?= clean($c['hex_code']) ?>">
                                                        <?= clean($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <td>
                                        <select name="items[1][size_id]" class="form-select size-select fw-semibold">
                                            <option value="">-- Choose Size --</option>
                                            <?php foreach ($sizes as $s): ?>
                                                <option value="<?= $s['id'] ?>" data-code="<?= clean($s['code']) ?>">
                                                    <?= clean($s['name']) ?> (<?= clean($s['code']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" name="items[1][quantity]" class="form-control text-center fw-bold row-qty-input" min="1" placeholder="0">
                                            <span class="input-group-text small text-muted">pcs</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-row" title="Remove Row">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Toolbar to Add Rows -->
                    <div class="p-3 bg-light border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-primary btn-sm fw-semibold shadow-sm" id="btnAddRow">
                                <i class="bi bi-plus-circle me-1"></i> Add Row
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold" id="btnDuplicateRow">
                                <i class="bi bi-copy me-1"></i> Duplicate Last Color
                            </button>
                        </div>
                        <span class="text-muted small">Select your target color & size; 2 rows shown by default.</span>
                    </div>
                </div>

                <!-- ============================================================= -->
                <!-- MODE 2: DYNAMIC MATRIX WITH COLOR DROPDOWNS (2 ROWS DEFAULT) -->
                <!-- ============================================================= -->
                <div id="matrixContainer" class="card-modern-body p-0 d-none">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-center mb-0" id="dynamicMatrixTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 45px;">#</th>
                                    <th class="text-start" style="min-width: 200px;">Select Color *</th>
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
                                <!-- Default Matrix Row 1 -->
                                <tr class="matrix-row" data-row-index="0">
                                    <td class="text-center fw-bold text-muted mrow-num">1</td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text p-1 px-2">
                                                <span class="d-inline-block rounded-circle border mswatch-dot" style="width: 14px; height: 14px; background-color: #adb5bd;"></span>
                                            </span>
                                            <select name="matrix_rows[0][color_id]" class="form-select form-select-sm mcolor-select fw-semibold">
                                                <option value="">-- Choose Color --</option>
                                                <?php foreach ($colors as $c): ?>
                                                    <option value="<?= $c['id'] ?>" data-hex="<?= clean($c['hex_code']) ?>">
                                                        <?= clean($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
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

                                <!-- Default Matrix Row 2 -->
                                <tr class="matrix-row" data-row-index="1">
                                    <td class="text-center fw-bold text-muted mrow-num">2</td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text p-1 px-2">
                                                <span class="d-inline-block rounded-circle border mswatch-dot" style="width: 14px; height: 14px; background-color: #adb5bd;"></span>
                                            </span>
                                            <select name="matrix_rows[1][color_id]" class="form-select form-select-sm mcolor-select fw-semibold">
                                                <option value="">-- Choose Color --</option>
                                                <?php foreach ($colors as $c): ?>
                                                    <option value="<?= $c['id'] ?>" data-hex="<?= clean($c['hex_code']) ?>">
                                                        <?= clean($c['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </td>
                                    <?php foreach ($sizes as $s): ?>
                                        <td>
                                            <input type="number" 
                                                   name="matrix_rows[1][sizes][<?= $s['id'] ?>]" 
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

                    <!-- Toolbar to Add Matrix Rows -->
                    <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-primary btn-sm fw-semibold shadow-sm" id="btnAddMatrixRow">
                            <i class="bi bi-plus-circle me-1"></i> Add Color Row
                        </button>
                        <span class="text-muted small">Choose color for each row and enter piece counts across sizes.</span>
                    </div>
                </div>
            </div>

            <!-- Summary & Actions Bar -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 bg-white border rounded-3 shadow-sm mb-4">
                <div class="d-flex align-items-center gap-4 text-muted small">
                    <div>Selected Colors: <strong id="summaryColors" class="text-dark">0</strong></div>
                    <div>Selected Sizes: <strong id="summarySizes" class="text-dark">0</strong></div>
                    <div>Total Planned Pieces: <strong id="summaryPieces" class="text-primary fs-5">0</strong></div>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>modules/lots/index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm" id="btnSubmitLot">
                        Create Lot #<span id="btnLotNum"><?= clean($suggestedLotNumber) ?></span>
                    </button>
                </div>
            </div>
        </form>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const productSelect = document.getElementById('product_id');
    const designSelect = document.getElementById('design_id');
    const lotNumberInput = document.getElementById('lot_number');
    const btnLotNum = document.getElementById('btnLotNum');
    const entryModeInput = document.getElementById('entry_mode');

    // Mode Toggle Elements
    const btnModeItemized = document.getElementById('btnModeItemized');
    const btnModeMatrix = document.getElementById('btnModeMatrix');
    const itemizedContainer = document.getElementById('itemizedContainer');
    const matrixContainer = document.getElementById('matrixContainer');

    lotNumberInput.addEventListener('input', function() {
        btnLotNum.textContent = this.value || '...';
    });

    // Dynamic Product Elements & BOM Recipe Loader
    let currentProductData = null;

    function updateProductSpecsPreview() {
        const previewBox = document.getElementById('productSpecsPreview');
        const elementsContainer = document.getElementById('prodElementsBadges');
        const elementsBadge = document.getElementById('lblProdElementsBadge');
        const recipeContainer = document.getElementById('prodRecipeDetails');
        const recipeStatus = document.getElementById('lblRecipeStatus');

        if (!currentProductData || !previewBox) {
            if (previewBox) previewBox.classList.add('d-none');
            return;
        }

        const elements = currentProductData.elements || [];
        const recipeItems = currentProductData.recipe_items || [];
        const currentTotalPcs = parseInt(document.getElementById('grandPiecesTotal').textContent.replace(/,/g, '')) || 0;

        // 1. Render Elements
        elementsContainer.innerHTML = '';
        let totalPcsPerUnit = 0;
        let hasSleeves = false;

        if (elements.length > 0) {
            elements.forEach(el => {
                const q = parseInt(el.quantity) || 1;
                totalPcsPerUnit += q;
                if (el.element_name.toLowerCase().includes('sleeve') || el.element_name.includes('بازو')) {
                    hasSleeves = true;
                }
                const badge = document.createElement('span');
                badge.className = 'badge bg-white text-dark border p-1 shadow-xs';
                badge.style.fontSize = '0.78rem';
                badge.innerHTML = `<i class="bi bi-puzzle text-primary me-1"></i>${escapeHtml(el.element_name)}: <strong class="text-primary">×${q}</strong>`;
                elementsContainer.appendChild(badge);
            });

            const pill = document.createElement('span');
            pill.className = hasSleeves ? 'badge bg-primary-subtle text-primary border p-1 ms-auto' : 'badge bg-warning-subtle text-warning-emphasis border p-1 ms-auto';
            pill.style.fontSize = '0.75rem';
            pill.innerHTML = hasSleeves ? '<i class="bi bi-check-circle-fill me-1"></i>With Sleeves' : '<i class="bi bi-dash-circle-fill me-1"></i>Sleeveless';
            elementsContainer.appendChild(pill);

            elementsBadge.textContent = `${elements.length} components (${totalPcsPerUnit} pcs/unit)`;
        } else {
            elementsContainer.innerHTML = '<span class="text-muted small fst-italic">No pattern elements specified for this product.</span>';
            elementsBadge.textContent = '0 components';
        }

        // 2. Render Recipe (BOM) Requirements
        recipeContainer.innerHTML = '';
        if (recipeItems.length > 0) {
            recipeStatus.innerHTML = `<span class="badge bg-success-subtle text-success border">Recipe Active</span>`;
            let recHtml = '<div class="d-flex flex-wrap gap-1">';
            recipeItems.forEach(it => {
                const perPiece = parseFloat(it.quantity_per_piece) || 0;
                const batchEst = currentTotalPcs > 0 ? (perPiece * currentTotalPcs).toFixed(currentTotalPcs % 1 === 0 && perPiece >= 1 ? 0 : 2) : 0;
                recHtml += `
                    <div class="badge bg-white text-dark border p-1 shadow-xs" style="font-size: 0.78rem;">
                        <span class="text-secondary">${escapeHtml(it.material_name)}:</span>
                        <strong class="text-primary">${perPiece} ${escapeHtml(it.unit_of_measure)}/pc</strong>
                        ${currentTotalPcs > 0 ? '<span class="text-success ms-1">[' + batchEst + ' ' + escapeHtml(it.unit_of_measure) + ' for batch]</span>' : ''}
                    </div>
                `;
            });
            recHtml += '</div>';
            recipeContainer.innerHTML = recHtml;
        } else {
            recipeStatus.innerHTML = `<span class="badge bg-secondary-subtle text-secondary border">No Recipe</span>`;
            recipeContainer.innerHTML = '<span class="text-muted small fst-italic">No raw material recipe configured for this product.</span>';
        }

        previewBox.classList.remove('d-none');
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function loadProductDetails(productId) {
        currentProductData = null;
        updateProductSpecsPreview();
        if (!productId) return;

        fetch('<?= BASE_URL ?>modules/lots/ajax.php?action=get_product_details&product_id=' + productId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.product) {
                    currentProductData = data.product;
                    updateProductSpecsPreview();
                }
            })
            .catch(err => console.error(err));
    }

    productSelect.addEventListener('change', function() {
        loadProductDetails(this.value);
    });

    if (productSelect.value) {
        loadProductDetails(productSelect.value);
    }

    // Toggle Modes
    function setMode(mode) {
        entryModeInput.value = mode;
        if (mode === 'itemized') {
            btnModeItemized.className = 'btn btn-primary fw-semibold';
            btnModeMatrix.className = 'btn btn-outline-secondary fw-semibold';
            itemizedContainer.classList.remove('d-none');
            matrixContainer.classList.add('d-none');
            itemizedContainer.querySelectorAll('input, select').forEach(el => el.disabled = false);
            matrixContainer.querySelectorAll('input, select').forEach(el => el.disabled = true);
            recalculateItemized();
        } else {
            btnModeMatrix.className = 'btn btn-primary fw-semibold';
            btnModeItemized.className = 'btn btn-outline-secondary fw-semibold';
            matrixContainer.classList.remove('d-none');
            itemizedContainer.classList.add('d-none');
            matrixContainer.querySelectorAll('input, select').forEach(el => el.disabled = false);
            itemizedContainer.querySelectorAll('input, select').forEach(el => el.disabled = true);
            recalculateMatrixRows();
        }
    }

    btnModeItemized.addEventListener('click', function() { setMode('itemized'); });
    btnModeMatrix.addEventListener('click', function() { setMode('matrix'); });

    // Common Templates
    const colorOptionsHtml = `<?php foreach ($colors as $c): ?><option value="<?= $c['id'] ?>" data-hex="<?= clean($c['hex_code']) ?>"><?= clean($c['name']) ?></option><?php endforeach; ?>`;
    const sizeOptionsHtml = `<?php foreach ($sizes as $s): ?><option value="<?= $s['id'] ?>" data-code="<?= clean($s['code']) ?>"><?= clean($s['name']) ?> (<?= clean($s['code']) ?>)</option><?php endforeach; ?>`;

    // =========================================================================
    // 1. ITEMIZED ROWS ENGINE
    // =========================================================================
    const itemizedTbody = document.getElementById('itemizedTbody');
    const btnAddRow = document.getElementById('btnAddRow');
    const btnDuplicateRow = document.getElementById('btnDuplicateRow');

    function createItemizedRow(colorId = '', sizeId = '', qty = '') {
        const rowCount = itemizedTbody.querySelectorAll('.variant-row').length;
        const newIndex = Date.now() + Math.floor(Math.random() * 1000);

        const tr = document.createElement('tr');
        tr.className = 'variant-row';
        tr.dataset.rowIndex = newIndex;
        tr.innerHTML = `
            <td class="text-center fw-bold text-muted row-num">${rowCount + 1}</td>
            <td>
                <div class="input-group">
                    <span class="input-group-text p-1 px-2 color-preview">
                        <span class="d-inline-block rounded-circle border swatch-dot" style="width: 14px; height: 14px; background-color: #adb5bd;"></span>
                    </span>
                    <select name="items[${newIndex}][color_id]" class="form-select color-select fw-semibold">
                        <option value="">-- Choose Color --</option>
                        ${colorOptionsHtml}
                    </select>
                </div>
            </td>
            <td>
                <select name="items[${newIndex}][size_id]" class="form-select size-select fw-semibold">
                    <option value="">-- Choose Size --</option>
                    ${sizeOptionsHtml}
                </select>
            </td>
            <td>
                <div class="input-group">
                    <input type="number" name="items[${newIndex}][quantity]" class="form-control text-center fw-bold row-qty-input" min="1" placeholder="0" value="${qty}">
                    <span class="input-group-text small text-muted">pcs</span>
                </div>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm btn-remove-row" title="Remove Row">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        if (colorId) {
            const cSel = tr.querySelector('.color-select');
            cSel.value = colorId;
            updateSwatch(cSel);
        }
        if (sizeId) {
            tr.querySelector('.size-select').value = sizeId;
        }

        bindItemizedRowEvents(tr);
        itemizedTbody.appendChild(tr);
        renumberItemizedRows();
        recalculateItemized();
    }

    function updateSwatch(colorSelect) {
        const selected = colorSelect.options[colorSelect.selectedIndex];
        const hex = selected ? selected.dataset.hex : '';
        const dot = colorSelect.closest('.input-group').querySelector('.swatch-dot');
        if (dot) {
            dot.style.backgroundColor = hex || '#adb5bd';
        }
    }

    function bindItemizedRowEvents(row) {
        const colorSel = row.querySelector('.color-select');
        const sizeSel = row.querySelector('.size-select');
        const qtyInp = row.querySelector('.row-qty-input');
        const removeBtn = row.querySelector('.btn-remove-row');

        colorSel.addEventListener('change', function() {
            updateSwatch(this);
            recalculateItemized();
        });

        sizeSel.addEventListener('change', recalculateItemized);
        qtyInp.addEventListener('input', recalculateItemized);

        removeBtn.addEventListener('click', function() {
            const rows = itemizedTbody.querySelectorAll('.variant-row');
            if (rows.length <= 1) {
                alert('At least one color and size row is required.');
                return;
            }
            row.remove();
            renumberItemizedRows();
            recalculateItemized();
        });
    }

    function renumberItemizedRows() {
        const rows = itemizedTbody.querySelectorAll('.variant-row');
        rows.forEach((r, idx) => {
            const numEl = r.querySelector('.row-num');
            if (numEl) numEl.textContent = idx + 1;
        });
    }

    itemizedTbody.querySelectorAll('.variant-row').forEach(row => {
        bindItemizedRowEvents(row);
        const cSel = row.querySelector('.color-select');
        if (cSel) updateSwatch(cSel);
    });

    btnAddRow.addEventListener('click', function() {
        createItemizedRow();
    });

    btnDuplicateRow.addEventListener('click', function() {
        const rows = itemizedTbody.querySelectorAll('.variant-row');
        if (rows.length > 0) {
            const lastRow = rows[rows.length - 1];
            const lastColor = lastRow.querySelector('.color-select').value;
            createItemizedRow(lastColor, '', '');
        } else {
            createItemizedRow();
        }
    });

    function recalculateItemized() {
        if (entryModeInput.value !== 'itemized') return;

        let totalPcs = 0;
        const activeColors = new Set();
        const activeSizes = new Set();

        itemizedTbody.querySelectorAll('.variant-row').forEach(row => {
            const cVal = row.querySelector('.color-select').value;
            const sVal = row.querySelector('.size-select').value;
            const qVal = parseInt(row.querySelector('.row-qty-input').value) || 0;

            if (cVal) activeColors.add(cVal);
            if (sVal) activeSizes.add(sVal);
            if (qVal > 0) totalPcs += qVal;
        });

        document.getElementById('matrixGrandTotal').textContent = totalPcs.toLocaleString();
        document.getElementById('summaryColors').textContent = activeColors.size;
        document.getElementById('summarySizes').textContent = activeSizes.size;
        document.getElementById('summaryPieces').textContent = totalPcs.toLocaleString();
    }

    // =========================================================================
    // 2. DYNAMIC MATRIX ROWS ENGINE (Color Dropdown per Row)
    // =========================================================================
    const matrixGridTbody = document.getElementById('matrixGridTbody');
    const btnAddMatrixRow = document.getElementById('btnAddMatrixRow');
    const allSizesList = <?= json_encode($sizes) ?>;

    function createMatrixRow(colorId = '') {
        const rowCount = matrixGridTbody.querySelectorAll('.matrix-row').length;
        const newIndex = Date.now() + Math.floor(Math.random() * 1000);

        let sizeColsHtml = '';
        allSizesList.forEach(s => {
            sizeColsHtml += `
                <td>
                    <input type="number" 
                           name="matrix_rows[${newIndex}][sizes][${s.id}]" 
                           class="form-control form-control-sm text-center mcell-input" 
                           min="0" 
                           placeholder="0"
                           data-size="${s.id}">
                </td>`;
        });

        const tr = document.createElement('tr');
        tr.className = 'matrix-row';
        tr.dataset.rowIndex = newIndex;
        tr.innerHTML = `
            <td class="text-center fw-bold text-muted mrow-num">${rowCount + 1}</td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text p-1 px-2">
                        <span class="d-inline-block rounded-circle border mswatch-dot" style="width: 14px; height: 14px; background-color: #adb5bd;"></span>
                    </span>
                    <select name="matrix_rows[${newIndex}][color_id]" class="form-select form-select-sm mcolor-select fw-semibold">
                        <option value="">-- Choose Color --</option>
                        ${colorOptionsHtml}
                    </select>
                </div>
            </td>
            ${sizeColsHtml}
            <td class="table-active fw-bold text-primary mrow-total">0</td>
            <td>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 btn-remove-mrow" title="Remove Color Row">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        if (colorId) {
            const sel = tr.querySelector('.mcolor-select');
            sel.value = colorId;
            updateMatrixSwatch(sel);
        }

        bindMatrixRowEvents(tr);
        matrixGridTbody.appendChild(tr);
        renumberMatrixRows();
        recalculateMatrixRows();
    }

    function updateMatrixSwatch(colorSelect) {
        const selected = colorSelect.options[colorSelect.selectedIndex];
        const hex = selected ? selected.dataset.hex : '';
        const dot = colorSelect.closest('.input-group').querySelector('.mswatch-dot');
        if (dot) {
            dot.style.backgroundColor = hex || '#adb5bd';
        }
    }

    function bindMatrixRowEvents(row) {
        const colorSel = row.querySelector('.mcolor-select');
        const removeBtn = row.querySelector('.btn-remove-mrow');

        colorSel.addEventListener('change', function() {
            updateMatrixSwatch(this);
            recalculateMatrixRows();
        });

        row.querySelectorAll('.mcell-input').forEach(inp => {
            inp.addEventListener('input', recalculateMatrixRows);
        });

        removeBtn.addEventListener('click', function() {
            const rows = matrixGridTbody.querySelectorAll('.matrix-row');
            if (rows.length <= 1) {
                alert('At least one color row is required in the matrix.');
                return;
            }
            row.remove();
            renumberMatrixRows();
            recalculateMatrixRows();
        });
    }

    function renumberMatrixRows() {
        const rows = matrixGridTbody.querySelectorAll('.matrix-row');
        rows.forEach((r, idx) => {
            const numEl = r.querySelector('.mrow-num');
            if (numEl) numEl.textContent = idx + 1;
        });
    }

    matrixGridTbody.querySelectorAll('.matrix-row').forEach(row => {
        bindMatrixRowEvents(row);
        const cSel = row.querySelector('.mcolor-select');
        if (cSel) updateMatrixSwatch(cSel);
    });

    btnAddMatrixRow.addEventListener('click', function() {
        createMatrixRow();
    });

    function recalculateMatrixRows() {
        if (entryModeInput.value !== 'matrix') return;

        let grandTotal = 0;
        const activeSizes = new Set();
        const activeColors = new Set();
        const sizeTotals = {};

        allSizesList.forEach(s => {
            sizeTotals[s.id] = 0;
        });

        matrixGridTbody.querySelectorAll('.matrix-row').forEach(row => {
            const cVal = row.querySelector('.mcolor-select').value;
            let rowTotal = 0;

            row.querySelectorAll('.mcell-input').forEach(inp => {
                const val = parseInt(inp.value) || 0;
                const sizeId = inp.dataset.size;
                if (val > 0) {
                    rowTotal += val;
                    activeSizes.add(sizeId);
                    sizeTotals[sizeId] = (sizeTotals[sizeId] || 0) + val;
                }
            });

            if (cVal && rowTotal > 0) {
                activeColors.add(cVal);
            }

            row.querySelector('.mrow-total').textContent = rowTotal;
            grandTotal += rowTotal;
        });

        for (const [sizeId, total] of Object.entries(sizeTotals)) {
            const cell = document.getElementById('msize_total_' + sizeId);
            if (cell) cell.textContent = total;
        }

        document.getElementById('matrixGrandTotal').textContent = grandTotal.toLocaleString();
        document.getElementById('mfooterGrandTotal').textContent = grandTotal.toLocaleString();
        document.getElementById('summaryColors').textContent = activeColors.size;
        document.getElementById('summarySizes').textContent = activeSizes.size;
        document.getElementById('summaryPieces').textContent = grandTotal.toLocaleString();
    }

    // Form submit validation
    document.getElementById('lotForm').addEventListener('submit', function(e) {
        const mode = entryModeInput.value;
        let total = 0;
        let hasIncompleteRow = false;

        if (mode === 'itemized') {
            itemizedTbody.querySelectorAll('.variant-row').forEach(row => {
                const q = parseInt(row.querySelector('.row-qty-input').value) || 0;
                const c = row.querySelector('.color-select').value;
                const s = row.querySelector('.size-select').value;
                if (c && s && q > 0) {
                    total += q;
                } else if ((c || s || q > 0) && (!c || !s || q <= 0)) {
                    hasIncompleteRow = true;
                }
            });

            if (hasIncompleteRow) {
                e.preventDefault();
                alert('Please select both Color, Size and enter a quantity > 0 for all active rows.');
                return;
            }
        } else {
            matrixGridTbody.querySelectorAll('.matrix-row').forEach(row => {
                const c = row.querySelector('.mcolor-select').value;
                let rowSum = 0;
                row.querySelectorAll('.mcell-input').forEach(inp => {
                    const q = parseInt(inp.value) || 0;
                    if (q > 0) rowSum += q;
                });
                if (rowSum > 0 && !c) {
                    hasIncompleteRow = true;
                }
                if (c && rowSum > 0) {
                    total += rowSum;
                }
            });

            if (hasIncompleteRow) {
                e.preventDefault();
                alert('Please select a Color for every row with planned quantities in the matrix.');
                return;
            }
        }

        if (total <= 0) {
            e.preventDefault();
            alert('Please enter at least 1 planned piece before creating the lot.');
        }
    });

    // Initialize mode
    setMode('<?= clean($entryMode) ?>' === 'matrix' ? 'matrix' : 'itemized');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
