<?php
/**
 * Garment Manufacturing ERP - Edit Design & Garment Elements
 * Manage design specifications and real-time structural pattern elements (Front, Back, Sleeves, Pockets, Collar, etc.)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Edit Design & Pattern Elements';
$activeNav = 'settings_prod';
$db = get_db();

$designId = (int)($_GET['id'] ?? $_POST['design_id'] ?? 0);

if ($designId <= 0) {
    redirect('modules/settings/products.php#designs', 'Invalid design ID provided.', 'danger');
}

// Fetch design
$stmt = $db->prepare("SELECT d.*, p.name AS product_name, p.code AS product_code 
                      FROM designs d 
                      JOIN products p ON d.product_id = p.id 
                      WHERE d.id = ?");
$stmt->execute([$designId]);
$design = $stmt->fetch();

if (!$design) {
    redirect('modules/settings/products.php#designs', 'Design record not found.', 'danger');
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_design') {
    check_csrf();

    $designNumber = trim($_POST['design_number'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $productId = (int)($_POST['product_id'] ?? 0);
    $specs = trim($_POST['specifications'] ?? '');
    $templateId = !empty($_POST['workflow_template_id']) ? (int)$_POST['workflow_template_id'] : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $elemNames = $_POST['element_name'] ?? [];
    $elemQtys = $_POST['element_qty'] ?? [];
    $elemNotes = $_POST['element_notes'] ?? [];

    if (empty($designNumber) || empty($name) || $productId <= 0) {
        $error = 'Design number, name, and parent product are required.';
    } else {
        try {
            $db->beginTransaction();

            // 1. Update Design Master
            $uStmt = $db->prepare("UPDATE designs SET design_number = ?, name = ?, product_id = ?, specifications = ?, workflow_template_id = ?, is_active = ? WHERE id = ?");
            $uStmt->execute([$designNumber, $name, $productId, $specs, $templateId, $isActive, $designId]);

            // 2. Synchronize Design Elements
            $db->prepare("DELETE FROM design_elements WHERE design_id = ?")->execute([$designId]);

            $insElem = $db->prepare("INSERT INTO design_elements (design_id, element_name, quantity, notes, sort_order) VALUES (?, ?, ?, ?, ?)");
            $savedCount = 0;
            if (is_array($elemNames) && !empty($elemNames)) {
                $sort = 1;
                foreach ($elemNames as $idx => $eName) {
                    $eName = trim($eName);
                    if ($eName === '') continue;
                    $q = max(1, (int)($elemQtys[$idx] ?? 1));
                    $notes = trim($elemNotes[$idx] ?? '');
                    $insElem->execute([$designId, $eName, $q, $notes, $sort++]);
                    $savedCount++;
                }
            }

            $db->commit();
            log_audit('UPDATE', 'designs', $designId, null, ['design_number' => $designNumber, 'name' => $name, 'elements_count' => $savedCount]);
            redirect('modules/settings/products.php#designs', "Design #$designNumber updated successfully with $savedCount pattern elements!");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Failed to update design: ' . $e->getMessage();
        }
    }
}

// Fetch products and workflow templates
$products = $db->query("SELECT * FROM products WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$templates = $db->query("SELECT * FROM workflow_templates WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch current elements
$eStmt = $db->prepare("SELECT * FROM design_elements WHERE design_id = ? ORDER BY sort_order ASC, id ASC");
$eStmt->execute([$designId]);
$elements = $eStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Breadcrumb & Top Bar -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/settings/products.php#designs" class="text-decoration-none">Products & Designs</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit Design #<?= clean($design['design_number']) ?></li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-scissors text-primary me-2"></i>Edit Design & Garment Elements
                </h4>
                <p class="text-muted small mb-0">Configure design details and customize structural pattern components (Front, Back, Sleeves, Pockets, Collar, etc.)</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>modules/settings/products.php#designs" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-arrow-left"></i>
                    <span>Back to Designs</span>
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="editDesignForm">
            <input type="hidden" name="action" value="update_design">
            <input type="hidden" name="design_id" value="<?= $design['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <!-- Section 1: Design Master Information -->
            <div class="card-modern shadow-sm mb-4">
                <div class="card-modern-header d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-tag-fill text-primary me-2"></i>Design Master Specifications
                    </h6>
                    <span class="badge bg-light text-primary border fw-bold">ID #<?= $design['id'] ?></span>
                </div>
                <div class="card-modern-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Design Number *</label>
                            <input type="text" name="design_number" class="form-control fw-bold text-primary" value="<?= clean($design['design_number']) ?>" placeholder="e.g. 904" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Parent Product *</label>
                            <select name="product_id" class="form-select fw-semibold" required>
                                <option value="">-- Select Product --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $design['product_id'] ? 'selected' : '' ?>>
                                        <?= clean($p['name']) ?> (<?= clean($p['code']) ?>) [<?= clean($p['product_type']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold">Design Name *</label>
                            <input type="text" name="name" class="form-control" value="<?= clean($design['name']) ?>" placeholder="e.g. Classic Slim Fit Jacket 904" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Workflow Template Override</label>
                            <select name="workflow_template_id" class="form-select">
                                <option value="">-- Inherit from Product --</option>
                                <?php foreach ($templates as $tmpl): ?>
                                    <option value="<?= $tmpl['id'] ?>" <?= $tmpl['id'] == $design['workflow_template_id'] ? 'selected' : '' ?>>
                                        <?= clean($tmpl['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small">Leave empty to use the parent product's default workflow.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Styling & Cut Specifications</label>
                            <input type="text" name="specifications" class="form-control" value="<?= clean($design['specifications'] ?? '') ?>" placeholder="e.g. Ribbed cuffs, double welt pocket, lapel collar">
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-center">
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= $design['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="is_active">Active Design</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Real-Time Garment Elements / Pattern Components -->
            <div class="card-modern shadow-sm mb-4">
                <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-puzzle-fill text-warning me-2"></i>Garment Elements / Pattern Breakdown (اجزاء اور کٹنگ کے حصے)
                        </h6>
                        <span class="text-muted small">Specify garment pieces per unit (e.g. 1 Front, 1 Back, 2 Sleeves, 2 Pockets, 1 Collar) for cutting masters and tailors</span>
                    </div>

                    <!-- Preset Loaders -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="small text-muted fw-semibold">Quick Presets:</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnPresetJacket" title="Standard Jacket with sleeves & pockets">
                            🧥 Jacket
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btnPresetVest" title="Sleeveless Vest / Waistcoat">
                            🦺 Sleeveless Vest
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info" id="btnPresetSweater" title="Full-sleeve Knit Sweater">
                            👕 Sweater
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearAllElements" title="Clear all rows">
                            <i class="bi bi-trash"></i> Clear All
                        </button>
                    </div>
                </div>

                <div class="card-modern-body">
                    <!-- Quick Add Chips -->
                    <div class="p-3 bg-light border rounded-3 mb-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <span class="small fw-bold text-dark text-uppercase" style="font-size: 0.75rem;">
                                <i class="bi bi-plus-circle me-1 text-primary"></i> 1-Click Quick Add Common Garment Parts:
                            </span>
                            <span class="small text-muted">Click any chip to add immediately</span>
                        </div>
                        <div class="d-flex flex-wrap gap-2" id="quickChipsContainer">
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Front" data-qty="1" data-notes="Main front body">
                                + Front / فرنٹ (1)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Back" data-qty="1" data-notes="Main back body">
                                + Back / بیک (1)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Sleeves" data-qty="2" data-notes="Left & right sleeves">
                                + Sleeves / بازو (2)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Pockets" data-qty="2" data-notes="Side welt / patch pockets">
                                + Pockets / پاکٹ (2)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Collar / Neck" data-qty="1" data-notes="Ribbed collar / stand neck">
                                + Collar/Neck / گلا (1)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Hood" data-qty="1" data-notes="Detachable or attached hood">
                                + Hood / ہڈ (1)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Cuffs" data-qty="2" data-notes="Wrist ribbed cuffs">
                                + Cuffs / کف (2)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Bottom Hem" data-qty="1" data-notes="Elasticated rib / border">
                                + Bottom Hem / دامن پٹی (1)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Inner Lining" data-qty="1" data-notes="Silk or polyester lining">
                                + Inner Lining / استر (1)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Zipper Placket" data-qty="1" data-notes="Center zip fly placket">
                                + Placket / زپ پٹی (1)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Belt" data-qty="1" data-notes="Waist belt with buckle">
                                + Belt / بیلٹ (1)
                            </button>
                        </div>
                    </div>

                    <!-- Elements Table Repeater -->
                    <div class="table-responsive border rounded-3 mb-3">
                        <table class="table table-bordered align-middle mb-0" id="elementsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;" class="text-center">#</th>
                                    <th style="min-width: 220px;">Element / Component Name *</th>
                                    <th style="width: 160px;" class="text-center">Qty Per Garment *</th>
                                    <th>Cutting & Pattern Specifications / Notes</th>
                                    <th style="width: 70px;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="elementsTbody">
                                <?php if (!empty($elements)): ?>
                                    <?php foreach ($elements as $idx => $el): ?>
                                        <tr class="element-row">
                                            <td class="text-center fw-bold text-muted row-index"><?= $idx + 1 ?></td>
                                            <td>
                                                <input type="text" name="element_name[]" class="form-control form-control-sm fw-semibold el-name-input" value="<?= clean($el['element_name']) ?>" placeholder="e.g. Front, Sleeves, Pockets" list="garmentElementsDatalist" required>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-qty-minus"><i class="bi bi-dash"></i></button>
                                                    <input type="number" name="element_qty[]" class="form-control text-center fw-bold el-qty-input" value="<?= (int)$el['quantity'] ?>" min="1" max="50" required>
                                                    <button type="button" class="btn btn-outline-secondary btn-qty-plus"><i class="bi bi-plus"></i></button>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" name="element_notes[]" class="form-control form-control-sm el-notes-input" value="<?= clean($el['notes'] ?? '') ?>" placeholder="e.g. Cut 2 panels, ribbed cuffs, welt finish">
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 btn-remove-row" title="Remove this component">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Empty State placeholder when no rows exist -->
                    <div id="noElementsNotice" class="text-center py-4 text-muted border border-dashed rounded-3 mb-3 <?= !empty($elements) ? 'd-none' : '' ?>">
                        <i class="bi bi-puzzle display-6 text-secondary opacity-50 mb-2 d-block"></i>
                        <h6 class="fw-semibold text-dark mb-1">No garment elements defined yet</h6>
                        <p class="small text-muted mb-3">Add components like Front, Back, Sleeves, Pockets using the quick chips above or click "Add Custom Element".</p>
                        <button type="button" class="btn btn-sm btn-primary" id="btnEmptyAdd">
                            <i class="bi bi-plus-lg me-1"></i> Add First Component
                        </button>
                    </div>

                    <!-- Action Bar & Live Statistics -->
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 p-3 bg-light border rounded-3">
                        <button type="button" class="btn btn-outline-primary btn-sm fw-semibold d-inline-flex align-items-center gap-1" id="btnAddRow">
                            <i class="bi bi-plus-circle-fill"></i>
                            <span>+ Add Custom Element Row</span>
                        </button>

                        <!-- Live Summary Counter -->
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-muted small">
                                Total Components: <strong id="lblTotalElements" class="text-dark">0</strong>
                            </div>
                            <div class="vr"></div>
                            <div class="text-muted small">
                                Total Pieces Per Garment: <strong id="lblTotalPieces" class="text-primary fs-6">0 pcs</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Section -->
            <div class="d-flex justify-content-end align-items-center gap-2 mb-5">
                <a href="<?= BASE_URL ?>modules/settings/products.php#designs" class="btn btn-light border px-4">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm">
                    <i class="bi bi-check2-circle me-1"></i> Save Design & Elements
                </button>
            </div>
        </form>
    </div>

<!-- Datalist for autocomplete -->
<datalist id="garmentElementsDatalist">
    <option value="Front">
    <option value="Front Panels">
    <option value="Back">
    <option value="Sleeves">
    <option value="Long Sleeves">
    <option value="Short Sleeves">
    <option value="Raglan Sleeves">
    <option value="Pockets">
    <option value="Chest Pocket">
    <option value="Side Pockets">
    <option value="Collar / Neck">
    <option value="Stand Collar">
    <option value="V-Neck Trim">
    <option value="Round Neck Rib">
    <option value="Hood">
    <option value="Detachable Hood">
    <option value="Cuffs">
    <option value="Bottom Hem">
    <option value="Inner Lining">
    <option value="Zipper Placket">
    <option value="Button Placket">
    <option value="Waistband">
    <option value="Belt">
    <option value="Shoulder Flaps">
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('elementsTbody');
    const noNotice = document.getElementById('noElementsNotice');
    const lblElements = document.getElementById('lblTotalElements');
    const lblPieces = document.getElementById('lblTotalPieces');
    const btnAddRow = document.getElementById('btnAddRow');
    const btnEmptyAdd = document.getElementById('btnEmptyAdd');
    const btnClearAll = document.getElementById('btnClearAllElements');

    // Preset buttons
    const btnJacket = document.getElementById('btnPresetJacket');
    const btnVest = document.getElementById('btnPresetVest');
    const btnSweater = document.getElementById('btnPresetSweater');

    function createRow(name = '', qty = 1, notes = '') {
        const tr = document.createElement('tr');
        tr.className = 'element-row';
        tr.innerHTML = `
            <td class="text-center fw-bold text-muted row-index">1</td>
            <td>
                <input type="text" name="element_name[]" class="form-control form-control-sm fw-semibold el-name-input" value="${escapeHtml(name)}" placeholder="e.g. Front, Sleeves, Pockets" list="garmentElementsDatalist" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary btn-qty-minus"><i class="bi bi-dash"></i></button>
                    <input type="number" name="element_qty[]" class="form-control text-center fw-bold el-qty-input" value="${qty}" min="1" max="50" required>
                    <button type="button" class="btn btn-outline-secondary btn-qty-plus"><i class="bi bi-plus"></i></button>
                </div>
            </td>
            <td>
                <input type="text" name="element_notes[]" class="form-control form-control-sm el-notes-input" value="${escapeHtml(notes)}" placeholder="e.g. Cut notes, ribbed cuffs, welt finish">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 btn-remove-row" title="Remove this component">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
        bindRowEvents(tr);
        recalcTotals();
    }

    function bindRowEvents(tr) {
        const minusBtn = tr.querySelector('.btn-qty-minus');
        const plusBtn = tr.querySelector('.btn-qty-plus');
        const qtyInput = tr.querySelector('.el-qty-input');
        const removeBtn = tr.querySelector('.btn-remove-row');

        minusBtn.addEventListener('click', function() {
            let v = parseInt(qtyInput.value) || 1;
            if (v > 1) {
                qtyInput.value = v - 1;
                recalcTotals();
            }
        });

        plusBtn.addEventListener('click', function() {
            let v = parseInt(qtyInput.value) || 1;
            qtyInput.value = v + 1;
            recalcTotals();
        });

        qtyInput.addEventListener('input', recalcTotals);

        removeBtn.addEventListener('click', function() {
            tr.remove();
            recalcTotals();
        });
    }

    function recalcTotals() {
        const rows = tbody.querySelectorAll('.element-row');
        let totalQty = 0;
        let count = rows.length;

        rows.forEach((r, idx) => {
            r.querySelector('.row-index').textContent = idx + 1;
            const q = parseInt(r.querySelector('.el-qty-input').value) || 0;
            totalQty += q;
        });

        lblElements.textContent = count;
        lblPieces.textContent = totalQty + ' pcs';

        if (count === 0) {
            noNotice.classList.remove('d-none');
        } else {
            noNotice.classList.add('d-none');
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>"']/g, function(m) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[m];
        });
    }

    // Bind existing rows
    tbody.querySelectorAll('.element-row').forEach(bindRowEvents);
    recalcTotals();

    // Add row button
    btnAddRow.addEventListener('click', function() {
        createRow('', 1, '');
        const inputs = tbody.querySelectorAll('.el-name-input');
        if (inputs.length) inputs[inputs.length - 1].focus();
    });

    if (btnEmptyAdd) {
        btnEmptyAdd.addEventListener('click', function() {
            createRow('Front', 1, '');
        });
    }

    // Quick Add Chips
    document.querySelectorAll('.quick-add-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            const name = this.getAttribute('data-name');
            const qty = parseInt(this.getAttribute('data-qty')) || 1;
            const notes = this.getAttribute('data-notes') || '';
            createRow(name, qty, notes);
        });
    });

    // Clear All
    btnClearAll.addEventListener('click', function() {
        if (confirm('Clear all garment element rows?')) {
            tbody.innerHTML = '';
            recalcTotals();
        }
    });

    // Presets
    btnJacket.addEventListener('click', function() {
        if (tbody.children.length > 0 && !confirm('Replace current components with Jacket preset?')) return;
        tbody.innerHTML = '';
        createRow('Front Panels', 2, 'Left & right overlap panels with zipper');
        createRow('Back', 1, 'Full back body');
        createRow('Sleeves', 2, 'Left and right sleeves');
        createRow('Pockets', 2, 'Front side welt pockets');
        createRow('Collar', 1, 'Stand / lapel collar');
        createRow('Inner Lining', 1, 'Silk polyester inner body');
    });

    btnVest.addEventListener('click', function() {
        if (tbody.children.length > 0 && !confirm('Replace current components with Sleeveless Vest preset?')) return;
        tbody.innerHTML = '';
        createRow('Front Panels', 2, 'Left & right front vest panels');
        createRow('Back', 1, 'Back panel with buckle adjuster');
        createRow('Pockets', 2, 'Waist welt pockets');
        createRow('V-Neck Collar', 1, 'V-neck border');
        createRow('Inner Lining', 1, 'Breathable lining');
    });

    btnSweater.addEventListener('click', function() {
        if (tbody.children.length > 0 && !confirm('Replace current components with Sweater preset?')) return;
        tbody.innerHTML = '';
        createRow('Front', 1, 'Main knit front pattern');
        createRow('Back', 1, 'Main knit back');
        createRow('Sleeves', 2, 'Left and right knit sleeves');
        createRow('Neck Rib', 1, 'Neck trim collar');
        createRow('Bottom Hem Rib', 1, 'Elastic waist ribbing');
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
