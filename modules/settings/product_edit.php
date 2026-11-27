<?php
/**
 * Garment Manufacturing ERP - Edit Product & Garment Pattern Elements
 * Manage product specifications and structural pattern elements (Front, Back, Sleeves, Pockets, Collar, etc.)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Edit Product & Pattern Elements';
$activeNav = 'settings_prod';
$db = get_db();

$productId = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);

if ($productId <= 0) {
    redirect('modules/settings/products.php', 'Invalid product ID provided.', 'danger');
}

// Fetch product
$stmt = $db->prepare("SELECT p.*, c.name AS category_name, wt.name AS template_name 
                      FROM products p 
                      LEFT JOIN product_categories c ON p.category_id = c.id 
                      LEFT JOIN workflow_templates wt ON p.default_workflow_template_id = wt.id 
                      WHERE p.id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    redirect('modules/settings/products.php', 'Product record not found.', 'danger');
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_product') {
    check_csrf();

    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $productType = trim($_POST['product_type'] ?? 'Coat');
    $desc = trim($_POST['description'] ?? '');
    $templateId = !empty($_POST['default_workflow_template_id']) ? (int)$_POST['default_workflow_template_id'] : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $elemNames = $_POST['element_name'] ?? [];
    $elemQtys = $_POST['element_qty'] ?? [];
    $elemPiecesPerPalla = $_POST['pieces_per_palla'] ?? [];
    $elemPallasPerGarment = $_POST['pallas_per_garment'] ?? [];
    $elemRatePerPalla = $_POST['rate_per_palla'] ?? [];
    $elemSams = $_POST['element_sam'] ?? [];
    $elemNotes = $_POST['element_notes'] ?? [];

    if (empty($code) || empty($name)) {
        $error = 'Product code and name are required.';
    } else {
        try {
            $db->beginTransaction();

            // 1. Update Product Master
            $uStmt = $db->prepare("UPDATE products SET code = ?, name = ?, category_id = ?, product_type = ?, description = ?, default_workflow_template_id = ?, is_active = ? WHERE id = ?");
            $uStmt->execute([$code, $name, $categoryId, $productType, $desc, $templateId, $isActive, $productId]);

            // 2. Synchronize Product Elements
            $db->prepare("DELETE FROM product_elements WHERE product_id = ?")->execute([$productId]);

            $insElem = $db->prepare("INSERT INTO product_elements 
                (product_id, element_name, quantity, multiplier, pieces_per_palla, pallas_per_garment, rate_per_palla, default_sam_minutes, notes, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $savedCount = 0;
            if (is_array($elemNames) && !empty($elemNames)) {
                $sort = 1;
                foreach ($elemNames as $idx => $eName) {
                    $eName = trim($eName);
                    if ($eName === '') continue;
                    $q = max(0, (float)($elemQtys[$idx] ?? 1.0));
                    $ppp = max(0.01, (float)($elemPiecesPerPalla[$idx] ?? 1.0));
                    $ppg = isset($elemPallasPerGarment[$idx]) && $elemPallasPerGarment[$idx] !== '' ? max(0, (float)$elemPallasPerGarment[$idx]) : ($ppp > 0 ? round($q / $ppp, 4) : $q);
                    $rpp = max(0, (float)($elemRatePerPalla[$idx] ?? 0.00));
                    $sam = max(0, (float)($elemSams[$idx] ?? 0.00));
                    $notes = trim($elemNotes[$idx] ?? '');
                    $insElem->execute([$productId, $eName, $q, $q, $ppp, $ppg, $rpp, $sam, $notes, $sort++]);
                    $savedCount++;
                }
            }

            $db->commit();
            log_audit('UPDATE', 'products', $productId, null, ['name' => $name, 'code' => $code, 'elements_count' => $savedCount]);
            redirect('modules/settings/products.php', "Product '$name' updated successfully with $savedCount pattern elements!");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Failed to update product: ' . $e->getMessage();
        }
    }
}

// Fetch categories and workflow templates
$categories = $db->query("SELECT * FROM product_categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$templates = $db->query("SELECT * FROM workflow_templates WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch current elements
$eStmt = $db->prepare("SELECT * FROM product_elements WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
$eStmt->execute([$productId]);
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
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/settings/products.php" class="text-decoration-none">Products</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Edit <?= clean($product['name']) ?></li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-tag-fill text-primary me-2"></i>Edit Product & Garment Elements
                </h4>
                <p class="text-muted small mb-0">Update product specifications and customize structural pattern components (Front, Back, Sleeves, Pockets, Collar, etc.)</p>
            </div>
            <div>
                <a href="<?= BASE_URL ?>modules/settings/products.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-arrow-left"></i>
                    <span>Back to Products</span>
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="editProductForm">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <!-- Section 1: Product Master Information -->
            <div class="card-modern shadow-sm mb-4">
                <div class="card-modern-header d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-info-circle-fill text-primary me-2"></i>Product Master Specifications
                    </h6>
                    <span class="badge bg-light text-primary border fw-bold"><?= clean($product['code']) ?></span>
                </div>
                <div class="card-modern-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Product Code *</label>
                            <input type="text" name="code" class="form-control text-uppercase fw-bold" value="<?= clean($product['code']) ?>" placeholder="e.g. PRD-SW-01" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Product Name *</label>
                            <input type="text" name="name" class="form-control fw-bold" value="<?= clean($product['name']) ?>" placeholder="e.g. Mens Cardigan Sweater" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="category_id" class="form-select">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $product['category_id'] ? 'selected' : '' ?>>
                                        <?= clean($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label fw-semibold">Product Type</label>
                            <select name="product_type" class="form-select">
                                <?php foreach (['Coat', 'Sweater', 'Jacket', 'Cardigan', 'Vest', 'Other'] as $pt): ?>
                                    <option value="<?= $pt ?>" <?= $product['product_type'] === $pt ? 'selected' : '' ?>><?= $pt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Default Workflow Template</label>
                            <select name="default_workflow_template_id" class="form-select">
                                <option value="">-- Select Workflow --</option>
                                <?php foreach ($templates as $tmpl): ?>
                                    <option value="<?= $tmpl['id'] ?>" <?= $tmpl['id'] == $product['default_workflow_template_id'] ? 'selected' : '' ?>>
                                        <?= clean($tmpl['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Description / Styling Specifications</label>
                            <input type="text" name="description" class="form-control" value="<?= clean($product['description'] ?? '') ?>" placeholder="Fabric blend, neck type, styling details">
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-center">
                            <div class="form-check form-switch mt-3">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= $product['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="is_active">Active</label>
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
                            <i class="bi bi-puzzle-fill text-warning me-2"></i>Garment Structural Elements
                        </h6>
                        <span class="text-muted small">Specify garment pieces per unit (e.g. 1 Front, 1 Back, 2 Sleeves, 2 Pockets, 1 Collar) for cutting masters and tailors</span>
                    </div>

                    <!-- Preset Loaders -->
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="small text-muted fw-semibold">Presets:</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnPresetJacket" title="Standard Jacket with sleeves & pockets">
                            🧥 Jacket
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btnPresetVest" title="Sleeveless Vest">
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
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Front Panel" data-qty="1" data-ppp="1" data-ppg="1" data-sam="3.5" data-rpp="20" data-notes="1 panel = 1 front body">
                                + Front Panel (1 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Back Panel" data-qty="1" data-ppp="1" data-ppg="1" data-sam="3.5" data-rpp="20" data-notes="1 panel = 1 back body">
                                + Back Panel (1 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Bazo / Sleeves" data-qty="2" data-ppp="2" data-ppg="1" data-sam="4.0" data-rpp="20" data-notes="1 panel yields 2 sleeves">
                                + Sleeves (2 pcs · 1 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Sleeves (3 per Palla)" data-qty="2" data-ppp="3" data-ppg="0.67" data-sam="4.0" data-rpp="20" data-notes="1 panel yields 3 sleeves">
                                + Sleeves (3 pcs · 0.67 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Pockets" data-qty="2" data-ppp="4" data-ppg="0.5" data-sam="0.5" data-rpp="20" data-notes="1 panel yields 4 pockets (0.5 Palla)">
                                + Pockets (4 pcs/Palla · 0.5 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Single Pocket" data-qty="1" data-ppp="2" data-ppg="0.5" data-sam="0.5" data-rpp="20" data-notes="1 panel yields 2 pockets (0.5 Palla)">
                                + Single Pocket (2 pcs · 0.5 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Gol Gala" data-qty="1" data-ppp="1" data-ppg="1" data-sam="1.5" data-rpp="10" data-notes="Ribbed collar / stand neck">
                                + Round Neck (1 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Bon" data-qty="2" data-ppp="2" data-ppg="1" data-sam="2.0" data-rpp="15" data-notes="Ribbed bon">
                                + Bon (2 pcs · 1 Palla)
                            </button>
                            <button type="button" class="btn btn-sm btn-white border shadow-xs quick-add-chip" data-name="Moday Tape" data-qty="2" data-ppp="2" data-ppg="1" data-sam="1.0" data-rpp="10" data-notes="Armhole tape">
                                + Armhole Tape (2 pcs · 1 Palla)
                            </button>
                        </div>
                    </div>

                    <!-- Elements Table Repeater -->
                    <div class="table-responsive border rounded-3 mb-3">
                        <table class="table table-bordered align-middle mb-0" id="elementsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;" class="text-center">#</th>
                                    <th style="min-width: 180px;">Element / Component Name *</th>
                                    <th style="width: 125px;" class="text-center" title="Number of cut pieces required per finished garment">Pcs / Garment *<br><span class="text-muted fw-normal" style="font-size: 0.72rem;">(Pcs / Garment)</span></th>
                                    <th style="width: 135px;" class="text-center" title="How many cut pieces yielded from 1 knitted panel (Yield pieces per Palla)">Pcs / Palla *<br><span class="text-muted fw-normal" style="font-size: 0.72rem;">(Yield / Palla)</span></th>
                                    <th style="width: 130px;" class="text-center" title="Knitted panels required per garment = Pcs ÷ Yield">Pallas / Garment<br><span class="text-muted fw-normal" style="font-size: 0.72rem;">(Pallas / Garment)</span></th>
                                    <th style="width: 140px;" class="text-center" title="Knitting operator rate per Palla">Rate / Palla (Rs.)<br><span class="text-muted fw-normal" style="font-size: 0.72rem;">(Rate / Palla)</span></th>
                                    <th style="width: 105px;" class="text-center">⏱️ Est. SAM<br><span class="text-muted fw-normal" style="font-size: 0.72rem;">(mins)</span></th>
                                    <th>Specifications & Notes</th>
                                    <th style="width: 60px;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="elementsTbody">
                                <?php if (!empty($elements)): ?>
                                    <?php foreach ($elements as $idx => $el): ?>
                                        <?php 
                                            $pppVal = max(0.01, (float)($el['pieces_per_palla'] ?? 1.0));
                                            $qVal = (float)$el['quantity'];
                                            $ppgVal = isset($el['pallas_per_garment']) ? (float)$el['pallas_per_garment'] : ($pppVal > 0 ? round($qVal / $pppVal, 4) : $qVal);
                                            $rppVal = (float)($el['rate_per_palla'] ?? 0.0);
                                            $pcRate = ($pppVal > 0 && $rppVal > 0) ? round($rppVal / $pppVal, 2) : 0;
                                        ?>
                                        <tr class="element-row">
                                            <td class="text-center fw-bold text-muted row-index"><?= $idx + 1 ?></td>
                                            <td>
                                                <input type="text" name="element_name[]" class="form-control form-control-sm fw-semibold el-name-input" value="<?= clean($el['element_name']) ?>" placeholder="e.g. Front, Sleeves, Pockets" list="garmentElementsDatalist" required>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary btn-qty-minus"><i class="bi bi-dash"></i></button>
                                                    <input type="number" step="any" min="0" name="element_qty[]" class="form-control text-center fw-bold el-qty-input" value="<?= $qVal ?>" placeholder="1" required title="Pieces per garment">
                                                    <button type="button" class="btn btn-outline-secondary btn-qty-plus"><i class="bi bi-plus"></i></button>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" step="any" min="0.01" name="pieces_per_palla[]" class="form-control text-center fw-bold el-ppp-input" value="<?= $pppVal ?>" placeholder="1" required title="Cut pieces yielded from 1 Palla">
                                                    <span class="input-group-text px-1 small text-muted">pcs</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" step="any" min="0" name="pallas_per_garment[]" class="form-control text-center fw-bold text-primary bg-light el-ppg-input" value="<?= round($ppgVal, 3) ?>" placeholder="1.0" title="Pallas required per garment">
                                                    <span class="input-group-text px-1 small text-primary fw-bold">Palla</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text px-1 small">Rs</span>
                                                    <input type="number" step="any" min="0" name="rate_per_palla[]" class="form-control text-center fw-bold text-success el-rpp-input" value="<?= $rppVal ?>" placeholder="0.00" title="Rate per knitted Palla">
                                                </div>
                                                <div class="text-muted text-center el-piece-rate-badge" style="font-size: 0.70rem;">
                                                    <?= $pcRate > 0 ? "Rs. " . number_format($pcRate, 2) . "/pc" : "—" ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" step="any" min="0" name="element_sam[]" class="form-control text-center fw-bold text-primary el-sam-input" value="<?= (float)($el['default_sam_minutes'] ?? 0.0) ?>" placeholder="0.0">
                                                    <span class="input-group-text px-1 small text-muted">m</span>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" name="element_notes[]" class="form-control form-control-sm el-notes-input" value="<?= clean($el['notes'] ?? '') ?>" placeholder="e.g. 1 panel yields 2 sleeves">
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

                    <!-- Empty Notice -->
                    <div id="noElementsNotice" class="text-center py-4 text-muted border border-dashed rounded-3 mb-3 <?= !empty($elements) ? 'd-none' : '' ?>">
                        <i class="bi bi-puzzle display-6 text-secondary opacity-50 mb-2 d-block"></i>
                        <h6 class="fw-semibold text-dark mb-1">No garment elements defined yet</h6>
                        <p class="small text-muted mb-3">Add components like Front, Back, Sleeves, Pockets using the quick chips above.</p>
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
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="text-muted small">
                                Total Components: <strong id="lblTotalElements" class="text-dark">0</strong>
                            </div>
                            <div class="vr"></div>
                            <div class="text-muted small">
                                Pieces/Garment: <strong id="lblTotalPieces" class="text-primary fs-6">0 pcs</strong>
                            </div>
                            <div class="vr"></div>
                            <div class="text-muted small">
                                Pallas/Garment: <strong id="lblTotalPallas" class="text-dark fw-bold fs-6">0.0 Palla</strong>
                            </div>
                            <div class="vr"></div>
                            <div class="text-muted small">
                                Palla Labour Cost: <strong id="lblTotalPallaCost" class="text-success fw-bold fs-6">Rs. 0.00</strong>
                            </div>
                            <div class="vr"></div>
                            <div class="text-muted small">
                                Total SAM: <strong id="lblTotalSam" class="text-info fw-bold">0.0 mins</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Section -->
            <div class="d-flex justify-content-end align-items-center gap-2 mb-5">
                <a href="<?= BASE_URL ?>modules/settings/products.php" class="btn btn-light border px-4">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm">
                    <i class="bi bi-check2-circle me-1"></i> Save Product & Elements
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
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('elementsTbody');
    const noNotice = document.getElementById('noElementsNotice');
    const lblElements = document.getElementById('lblTotalElements');
    const lblPieces = document.getElementById('lblTotalPieces');
    const lblPallas = document.getElementById('lblTotalPallas');
    const lblPallaCost = document.getElementById('lblTotalPallaCost');
    const lblSam = document.getElementById('lblTotalSam');
    const btnAddRow = document.getElementById('btnAddRow');
    const btnEmptyAdd = document.getElementById('btnEmptyAdd');
    const btnClearAll = document.getElementById('btnClearAllElements');

    // Preset buttons
    const btnJacket = document.getElementById('btnPresetJacket');
    const btnVest = document.getElementById('btnPresetVest');
    const btnSweater = document.getElementById('btnPresetSweater');

    function createRow(name = '', qty = 1, notes = '', sam = 0.0, ppp = 1.0, ppg = null, rpp = 0.0) {
        if (ppg === null || ppg === undefined) {
            ppg = (ppp > 0) ? (Math.round((qty / ppp) * 1000) / 1000) : qty;
        }
        const pcRate = (ppp > 0 && rpp > 0) ? (Math.round((rpp / ppp) * 100) / 100) : 0;
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
                    <input type="number" step="any" min="0" name="element_qty[]" class="form-control text-center fw-bold el-qty-input" value="${qty}" placeholder="1" required title="Pieces per garment">
                    <button type="button" class="btn btn-outline-secondary btn-qty-plus"><i class="bi bi-plus"></i></button>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" step="any" min="0.01" name="pieces_per_palla[]" class="form-control text-center fw-bold el-ppp-input" value="${ppp}" placeholder="1" required title="Cut pieces yielded from 1 Palla">
                    <span class="input-group-text px-1 small text-muted">pcs</span>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" step="any" min="0" name="pallas_per_garment[]" class="form-control text-center fw-bold text-primary bg-light el-ppg-input" value="${ppg}" placeholder="1.0" title="Pallas required per garment">
                    <span class="input-group-text px-1 small text-primary fw-bold">Palla</span>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text px-1 small">Rs</span>
                    <input type="number" step="any" min="0" name="rate_per_palla[]" class="form-control text-center fw-bold text-success el-rpp-input" value="${rpp}" placeholder="0.00" title="Rate per knitted Palla">
                </div>
                <div class="text-muted text-center el-piece-rate-badge" style="font-size: 0.70rem;">
                    ${pcRate > 0 ? "Rs. " + pcRate.toFixed(2) + "/pc" : "—"}
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" step="any" min="0" name="element_sam[]" class="form-control text-center fw-bold text-primary el-sam-input" value="${sam}" placeholder="0.0">
                    <span class="input-group-text px-1 small text-muted">m</span>
                </div>
            </td>
            <td>
                <input type="text" name="element_notes[]" class="form-control form-control-sm el-notes-input" value="${escapeHtml(notes)}" placeholder="e.g. Cut 2 sleeves from 1 panel">
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
        const pppInput = tr.querySelector('.el-ppp-input');
        const ppgInput = tr.querySelector('.el-ppg-input');
        const rppInput = tr.querySelector('.el-rpp-input');
        const samInput = tr.querySelector('.el-sam-input');
        const removeBtn = tr.querySelector('.btn-remove-row');
        const badge = tr.querySelector('.el-piece-rate-badge');

        function updateRowPallaCalc(skipPpg = false) {
            const q = parseFloat(qtyInput ? qtyInput.value : 1) || 0;
            const ppp = parseFloat(pppInput ? pppInput.value : 1) || 1;
            const rpp = parseFloat(rppInput ? rppInput.value : 0) || 0;

            if (!skipPpg && ppp > 0) {
                ppgInput.value = Math.round((q / ppp) * 1000) / 1000;
            }
            const pcRate = (ppp > 0 && rpp > 0) ? (Math.round((rpp / ppp) * 100) / 100) : 0;
            if (badge) {
                badge.textContent = pcRate > 0 ? "Rs. " + pcRate.toFixed(2) + "/pc" : "—";
            }
            recalcTotals();
        }

        minusBtn.addEventListener('click', function() {
            let v = parseFloat(qtyInput.value) || 0;
            if (v > 1) {
                qtyInput.value = Math.round((v - 1) * 100) / 100;
            } else if (v > 0.5) {
                qtyInput.value = 0.5;
            } else if (v > 0.1) {
                qtyInput.value = Math.max(0, Math.round((v - 0.1) * 100) / 100);
            }
            updateRowPallaCalc();
        });

        plusBtn.addEventListener('click', function() {
            let v = parseFloat(qtyInput.value) || 0;
            if (v >= 0 && v < 1) {
                qtyInput.value = Math.round((v + 0.5) * 100) / 100;
            } else {
                qtyInput.value = Math.round((v + 1) * 100) / 100;
            }
            updateRowPallaCalc();
        });

        qtyInput.addEventListener('input', () => updateRowPallaCalc());
        if (pppInput) pppInput.addEventListener('input', () => updateRowPallaCalc());
        if (rppInput) rppInput.addEventListener('input', () => updateRowPallaCalc());

        if (ppgInput) {
            ppgInput.addEventListener('input', function() {
                const ppg = parseFloat(this.value) || 0;
                const q = parseFloat(qtyInput.value) || 0;
                if (ppg > 0 && q > 0) {
                    pppInput.value = Math.round((q / ppg) * 100) / 100;
                }
                updateRowPallaCalc(true);
            });
        }

        if (samInput) samInput.addEventListener('input', recalcTotals);

        removeBtn.addEventListener('click', function() {
            tr.remove();
            recalcTotals();
        });
    }

    function recalcTotals() {
        const rows = tbody.querySelectorAll('.element-row');
        let totalQty = 0;
        let totalSam = 0;
        let totalPallas = 0;
        let totalPallaCost = 0;
        let count = rows.length;

        rows.forEach((r, idx) => {
            r.querySelector('.row-index').textContent = idx + 1;
            const q = parseFloat(r.querySelector('.el-qty-input').value) || 0;
            const s = parseFloat(r.querySelector('.el-sam-input') ? r.querySelector('.el-sam-input').value : 0) || 0;
            const ppg = parseFloat(r.querySelector('.el-ppg-input') ? r.querySelector('.el-ppg-input').value : 0) || 0;
            const rpp = parseFloat(r.querySelector('.el-rpp-input') ? r.querySelector('.el-rpp-input').value : 0) || 0;

            totalQty += q;
            totalSam += (q * s);
            totalPallas += ppg;
            totalPallaCost += (ppg * rpp);
        });

        if (lblElements) lblElements.textContent = count;
        if (lblPieces) lblPieces.textContent = (Math.round(totalQty * 100) / 100) + ' pcs';
        if (lblPallas) lblPallas.textContent = (Math.round(totalPallas * 100) / 100) + ' Palla';
        if (lblPallaCost) lblPallaCost.textContent = 'Rs. ' + totalPallaCost.toFixed(2);
        if (lblSam) lblSam.textContent = totalSam.toFixed(1) + ' mins';

        if (noNotice) {
            if (count === 0) {
                noNotice.classList.remove('d-none');
            } else {
                noNotice.classList.add('d-none');
            }
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
        createRow('', 1, '', 0.0, 1.0, 1.0, 0.0);
        const inputs = tbody.querySelectorAll('.el-name-input');
        if (inputs.length) inputs[inputs.length - 1].focus();
    });

    if (btnEmptyAdd) {
        btnEmptyAdd.addEventListener('click', function() {
            createRow('Front Panel', 1, 'Main front body', 3.5, 1.0, 1.0, 20.0);
        });
    }

    // Quick Add Chips
    document.querySelectorAll('.quick-add-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            const name = this.getAttribute('data-name');
            const qty = parseFloat(this.getAttribute('data-qty')) || 1;
            const ppp = parseFloat(this.getAttribute('data-ppp')) || 1;
            const ppg = parseFloat(this.getAttribute('data-ppg')) || null;
            const rpp = parseFloat(this.getAttribute('data-rpp')) || 0;
            const sam = parseFloat(this.getAttribute('data-sam')) || 0.0;
            const notes = this.getAttribute('data-notes') || '';
            createRow(name, qty, notes, sam, ppp, ppg, rpp);
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
        createRow('Front Panel', 1, '1 panel = 1 front', 3.5, 1.0, 1.0, 20.0);
        createRow('Back Panel', 1, '1 panel = 1 back', 3.5, 1.0, 1.0, 20.0);
        createRow('Bazo / Sleeves', 2, '1 panel yields 2 sleeves', 4.0, 2.0, 1.0, 20.0);
        createRow('Pockets', 2, '1 panel yields 4 pockets (0.5 Palla)', 0.5, 4.0, 0.5, 20.0);
        createRow('Bon', 2, 'Ribbed bon finish', 2.0, 2.0, 1.0, 15.0);
        createRow('Gol Gala', 1, 'Ribbed neck collar', 1.5, 1.0, 1.0, 10.0);
    });

    btnVest.addEventListener('click', function() {
        if (tbody.children.length > 0 && !confirm('Replace current components with Sleeveless Vest preset?')) return;
        tbody.innerHTML = '';
        createRow('Front Panel', 1, '1 panel = 1 front', 3.5, 1.0, 1.0, 20.0);
        createRow('Back Panel', 1, '1 panel = 1 back', 3.5, 1.0, 1.0, 20.0);
        createRow('Pockets', 2, '1 panel yields 4 pockets (0.5 Palla)', 0.5, 4.0, 0.5, 20.0);
        createRow('Gol Gala', 1, 'V-neck / round collar', 1.5, 1.0, 1.0, 10.0);
        createRow('Moday Tape', 2, 'Armhole ribs', 1.0, 2.0, 1.0, 10.0);
    });

    btnSweater.addEventListener('click', function() {
        if (tbody.children.length > 0 && !confirm('Replace current components with Sweater preset?')) return;
        tbody.innerHTML = '';
        createRow('Front Panel', 1, '1 panel = 1 front', 3.5, 1.0, 1.0, 20.0);
        createRow('Back Panel', 1, '1 panel = 1 back', 3.5, 1.0, 1.0, 20.0);
        createRow('Bazo / Sleeves', 2, '1 panel yields 2 sleeves', 4.0, 2.0, 1.0, 20.0);
        createRow('Gol Gala', 1, 'Round neck rib', 1.5, 1.0, 1.0, 10.0);
        createRow('Bottom Hem', 1, 'Elasticated waist rib', 1.0, 1.0, 1.0, 10.0);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
