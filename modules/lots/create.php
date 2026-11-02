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
    $designId = !empty($_POST['design_id']) ? (int)$_POST['design_id'] : null;
    $supervisor = trim($_POST['supervisor_name'] ?? '');
    $expectedDate = !empty($_POST['expected_date']) ? trim($_POST['expected_date']) : null;
    $instructions = trim($_POST['instructions'] ?? '');
    
    // Matrix quantities: variants[color_id][size_id] = qty
    $matrix = $_POST['matrix'] ?? [];

    if (empty($lotNumber) || $productId <= 0) {
        $error = 'Lot number and product are required.';
    } else {
        // Check uniqueness of lot_number
        $stmt = $db->prepare("SELECT id FROM lots WHERE lot_number = ?");
        $stmt->execute([$lotNumber]);
        if ($stmt->fetch()) {
            $error = "Lot number '$lotNumber' already exists. Please choose a unique lot number.";
        } else {
            // Calculate total pieces, total colors, total sizes
            $totalPieces = 0;
            $usedColors = [];
            $usedSizes = [];
            $validVariants = [];

            foreach ($matrix as $cId => $sizesArr) {
                foreach ($sizesArr as $sId => $qty) {
                    $q = (int)$qty;
                    if ($q > 0) {
                        $totalPieces += $q;
                        $usedColors[$cId] = true;
                        $usedSizes[$sId] = true;
                        $validVariants[] = [
                            'color_id' => (int)$cId,
                            'size_id' => (int)$sId,
                            'quantity' => $q
                        ];
                    }
                }
            }

            if ($totalPieces <= 0 || empty($validVariants)) {
                $error = 'Please enter valid quantities for at least one color and size variant.';
            } else {
                $db->beginTransaction();
                try {
                    $totalColors = count($usedColors);
                    $totalSizes = count($usedSizes);

                    // Insert Lot Master
                    $stmt = $db->prepare("INSERT INTO lots 
                        (lot_number, lot_date, product_id, design_id, supervisor_name, expected_date, instructions, total_pieces, total_colors, total_sizes, status, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?)");
                    $stmt->execute([
                        $lotNumber,
                        $lotDate,
                        $productId,
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
                    // Check if design has workflow, else product default, else standard template 1
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
                        // Fallback to all operations
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
                <p class="text-muted small mb-0">Initial batch setup with color/size matrix & workflow</p>
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

            <!-- Lot Master Details Card -->
            <div class="card-modern mb-4">
                <div class="card-modern-header">
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-info-circle me-1 text-primary"></i> 1. Lot Master Information</h6>
                </div>
                <div class="card-modern-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Lot Number *</label>
                            <input type="text" name="lot_number" id="lot_number" class="form-control fw-bold text-primary" value="<?= clean($_POST['lot_number'] ?? $suggestedLotNumber) ?>" required>
                            <div class="form-text">Unique factory batch number</div>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">Lot Date *</label>
                            <input type="date" name="lot_date" class="form-control" value="<?= clean($_POST['lot_date'] ?? date('Y-m-d')) ?>" required>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">Product *</label>
                            <select name="product_id" id="product_id" class="form-select" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= (isset($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : '' ?>>
                                        <?= clean($p['name']) ?> (<?= clean($p['product_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label">
                                Design Number <span class="badge bg-light text-secondary border fw-normal">Optional</span>
                            </label>
                            <select name="design_id" id="design_id" class="form-select">
                                <option value="">-- No Design / Add Later --</option>
                                <!-- Populated dynamically or options -->
                            </select>
                            <div class="form-text">Can be assigned or updated anytime later</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Assigned Supervisor</label>
                            <input type="text" name="supervisor_name" class="form-control" placeholder="e.g. Master Shabbir" value="<?= clean($_POST['supervisor_name'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Expected Completion Date</label>
                            <input type="date" name="expected_date" class="form-control" value="<?= clean($_POST['expected_date'] ?? '') ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Production Instructions / Notes</label>
                            <input type="text" name="instructions" class="form-control" placeholder="Thread color, wash instructions, packaging details" value="<?= clean($_POST['instructions'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Color & Size Quantity Matrix Card -->
            <div class="card-modern mb-4">
                <div class="card-modern-header d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-grid-3x3-gap-fill me-1 text-primary"></i> 2. Color & Size Planned Quantities (Matrix)</h6>
                        <span class="text-muted small">Enter quantities for each color and size variant</span>
                    </div>
                    <div class="badge bg-primary fs-6 px-3 py-2 shadow-sm">
                        Total: <span id="matrixGrandTotal">0</span> Pieces
                    </div>
                </div>
                <div class="card-modern-body p-0">
                    <div class="table-responsive">
                        <table class="table table-modern table-bordered align-middle text-center mb-0">
                            <thead>
                                <tr class="table-light">
                                    <th class="text-start" style="min-width: 160px;">Color</th>
                                    <?php foreach ($sizes as $s): ?>
                                        <th style="min-width: 100px;">
                                            <div><?= clean($s['name']) ?></div>
                                            <span class="badge bg-secondary-subtle text-secondary"><?= clean($s['code']) ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                    <th style="min-width: 110px;" class="table-active">Color Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($colors as $c): ?>
                                    <tr data-color-id="<?= $c['id'] ?>">
                                        <td class="text-start fw-semibold text-dark">
                                            <span class="d-inline-block rounded-circle border me-2 align-middle" style="width: 14px; height: 14px; background-color: <?= clean($c['hex_code']) ?>;"></span>
                                            <?= clean($c['name']) ?>
                                        </td>
                                        <?php foreach ($sizes as $s): ?>
                                            <td>
                                                <input type="number" 
                                                       name="matrix[<?= $c['id'] ?>][<?= $s['id'] ?>]" 
                                                       class="form-control form-control-sm text-center matrix-input" 
                                                       min="0" 
                                                       placeholder="0"
                                                       data-color="<?= $c['id'] ?>"
                                                       data-size="<?= $s['id'] ?>"
                                                       value="<?= clean($_POST['matrix'][$c['id']][$s['id']] ?? '') ?>">
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="table-active fw-bold text-primary color-row-total">0</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td class="text-start">Size Totals</td>
                                    <?php foreach ($sizes as $s): ?>
                                        <td id="size_total_<?= $s['id'] ?>">0</td>
                                    <?php endforeach; ?>
                                    <td class="table-primary text-primary fs-6" id="footerGrandTotal">0</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Summary & Actions -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 bg-white border rounded-3 shadow-sm">
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
    // Dynamic designs loader when product is chosen
    const productSelect = document.getElementById('product_id');
    const designSelect = document.getElementById('design_id');
    const lotNumberInput = document.getElementById('lot_number');
    const btnLotNum = document.getElementById('btnLotNum');

    lotNumberInput.addEventListener('input', function() {
        btnLotNum.textContent = this.value || '...';
    });

    function loadDesigns(productId, selectedDesign = '') {
        designSelect.innerHTML = '<option value="">-- No Design / Add Later --</option>';
        if (!productId) return;

        fetch('<?= BASE_URL ?>modules/lots/ajax.php?action=get_designs&product_id=' + productId)
            .then(res => res.json())
            .then(data => {
                if (data.designs && data.designs.length > 0) {
                    data.designs.forEach(d => {
                        const opt = document.createElement('option');
                        opt.value = d.id;
                        opt.textContent = 'Design #' + d.design_number + ' - ' + d.name;
                        if (selectedDesign && selectedDesign == d.id) opt.selected = true;
                        designSelect.appendChild(opt);
                    });
                }
            })
            .catch(err => console.error(err));
    }

    productSelect.addEventListener('change', function() {
        loadDesigns(this.value);
    });

    if (productSelect.value) {
        loadDesigns(productSelect.value, '<?= clean($_POST['design_id'] ?? '') ?>');
    }

    // Matrix calculations
    const inputs = document.querySelectorAll('.matrix-input');
    function recalculateMatrix() {
        let grandTotal = 0;
        let colorCount = 0;
        const activeSizes = new Set();
        const activeColors = new Set();
        const sizeTotals = {};

        <?php foreach ($sizes as $s): ?>
            sizeTotals[<?= $s['id'] ?>] = 0;
        <?php endforeach; ?>

        document.querySelectorAll('tr[data-color-id]').forEach(row => {
            const colorId = row.dataset.colorId;
            let rowTotal = 0;

            row.querySelectorAll('.matrix-input').forEach(input => {
                const val = parseInt(input.value) || 0;
                const sizeId = input.dataset.size;
                if (val > 0) {
                    rowTotal += val;
                    activeColors.add(colorId);
                    activeSizes.add(sizeId);
                    sizeTotals[sizeId] = (sizeTotals[sizeId] || 0) + val;
                }
            });

            row.querySelector('.color-row-total').textContent = rowTotal;
            grandTotal += rowTotal;
        });

        // Update size totals in footer
        for (const [sizeId, total] of Object.entries(sizeTotals)) {
            const cell = document.getElementById('size_total_' + sizeId);
            if (cell) cell.textContent = total;
        }

        document.getElementById('matrixGrandTotal').textContent = grandTotal;
        document.getElementById('footerGrandTotal').textContent = grandTotal;
        document.getElementById('summaryColors').textContent = activeColors.size;
        document.getElementById('summarySizes').textContent = activeSizes.size;
        document.getElementById('summaryPieces').textContent = grandTotal;
    }

    inputs.forEach(inp => {
        inp.addEventListener('input', recalculateMatrix);
    });

    recalculateMatrix();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
