<?php
/**
 * Garment Manufacturing ERP - Products, Elements & Recipes Management
 * Features Product Master, Pattern Elements, and Raw Material Consumption Recipes (BOM)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Products & Recipes Management';
$activeNav = 'settings_prod';
$db = get_db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // =========================================================================
    // 1. CREATE PRODUCT WITH REAL-TIME PATTERN ELEMENTS
    // =========================================================================
    if ($action === 'create_product') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $productType = trim($_POST['product_type'] ?? 'Coat');
        $desc = trim($_POST['description'] ?? '');
        $templateId = !empty($_POST['default_workflow_template_id']) ? (int)$_POST['default_workflow_template_id'] : null;

        $elemNames = $_POST['element_name'] ?? [];
        $elemQtys = $_POST['element_qty'] ?? [];
        $elemNotes = $_POST['element_notes'] ?? [];

        if (empty($code) || empty($name)) {
            set_flash('danger', 'Product code and name are required.');
        } else {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("INSERT INTO products (code, name, category_id, product_type, description, default_workflow_template_id, is_active) 
                                      VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$code, $name, $categoryId, $productType, $desc, $templateId]);
                $newProductId = (int)$db->lastInsertId();

                // Save product pattern elements (Front, Back, Sleeves, etc.)
                $savedElemCount = 0;
                if (is_array($elemNames) && !empty($elemNames)) {
                    $insElem = $db->prepare("INSERT INTO product_elements (product_id, element_name, quantity, notes, sort_order) VALUES (?, ?, ?, ?, ?)");
                    $sort = 1;
                    foreach ($elemNames as $idx => $eName) {
                        $eName = trim($eName);
                        if ($eName === '') continue;
                        $q = max(1, (int)($elemQtys[$idx] ?? 1));
                        $notes = trim($elemNotes[$idx] ?? '');
                        $insElem->execute([$newProductId, $eName, $q, $notes, $sort++]);
                        $savedElemCount++;
                    }
                }

                $db->commit();
                log_audit('CREATE', 'products', $newProductId, null, ['name' => $name, 'code' => $code, 'elements' => $savedElemCount]);
                redirect('modules/settings/products.php#products', "Product '$name' created successfully with $savedElemCount garment elements!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Error creating product: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // 2. SAVE PRODUCT RECIPE / BOM (RAW MATERIAL CONSUMPTION PER PIECE)
    // =========================================================================
    if ($action === 'save_recipe') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $wastage = (float)($_POST['wastage_percent'] ?? 0.00);
        $materialIds = $_POST['material_id'] ?? [];
        $quantities = $_POST['quantity_per_piece'] ?? [];

        if ($productId <= 0) {
            set_flash('danger', 'Please select a product for the recipe.');
        } else {
            try {
                $db->beginTransaction();

                // Check existing active recipe header
                $bStmt = $db->prepare("SELECT id FROM bom_headers WHERE product_id = ? AND is_active = 1 LIMIT 1");
                $bStmt->execute([$productId]);
                $existingBomId = (int)$bStmt->fetchColumn();

                if ($existingBomId > 0) {
                    $uStmt = $db->prepare("UPDATE bom_headers SET description = ?, wastage_percent = ? WHERE id = ?");
                    $uStmt->execute([$desc ?: 'Standard Production Recipe', $wastage, $existingBomId]);
                    $bomId = $existingBomId;
                    $db->prepare("DELETE FROM bom_items WHERE bom_id = ?")->execute([$bomId]);
                } else {
                    $iStmt = $db->prepare("INSERT INTO bom_headers (product_id, description, wastage_percent, is_active) VALUES (?, ?, ?, 1)");
                    $iStmt->execute([$productId, $desc ?: 'Standard Production Recipe', $wastage]);
                    $bomId = (int)$db->lastInsertId();
                }

                $insItem = $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, ?, ?)");
                $savedItems = 0;
                if (is_array($materialIds)) {
                    foreach ($materialIds as $idx => $mId) {
                        $mId = (int)$mId;
                        $q = (float)($quantities[$idx] ?? 0);
                        if ($mId > 0 && $q > 0) {
                            $insItem->execute([$bomId, $mId, $q]);
                            $savedItems++;
                        }
                    }
                }

                $db->commit();
                log_audit('SAVE_RECIPE', 'bom_headers', $bomId, null, ['product_id' => $productId, 'items_count' => $savedItems]);
                redirect('modules/settings/products.php#recipes', "Product recipe saved successfully with $savedItems raw material components!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Error saving recipe: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // 3. CATEGORIES, COLORS & SIZES
    // =========================================================================
    if ($action === 'create_color') {
        $name = trim($_POST['name'] ?? '');
        $hex = trim($_POST['hex_code'] ?? '#cccccc');
        if (!empty($name)) {
            $stmt = $db->prepare("INSERT INTO colors (name, hex_code) VALUES (?, ?) ON DUPLICATE KEY UPDATE hex_code = VALUES(hex_code)");
            $stmt->execute([$name, $hex]);
            redirect('modules/settings/products.php#variants', "Color '$name' saved!");
        }
    }

    if ($action === 'create_size') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $sort = (int)($_POST['sort_order'] ?? 0);
        if (!empty($name) && !empty($code)) {
            $stmt = $db->prepare("INSERT INTO sizes (name, code, sort_order) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE code = VALUES(code), sort_order = VALUES(sort_order)");
            $stmt->execute([$name, $code, $sort]);
            redirect('modules/settings/products.php#variants', "Size '$name' saved!");
        }
    }

    if ($action === 'create_category') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) {
            set_flash('danger', 'Category name is required.');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO product_categories (name, description, is_active) VALUES (?, ?, 1)");
                $stmt->execute([$name, $desc]);
                log_audit('CREATE', 'product_categories', (int)$db->lastInsertId(), null, ['name' => $name]);
                redirect('modules/settings/products.php#categories', "Category '$name' created successfully!");
            } catch (PDOException $e) {
                set_flash('danger', 'Category name already exists or could not be saved.');
            }
        }
    }
}

// =============================================================================
// FETCH ALL DATA
// =============================================================================
$products = $db->query("SELECT p.*, c.name AS category_name, wt.name AS template_name,
                        (SELECT COUNT(*) FROM bom_headers WHERE product_id = p.id AND is_active = 1) AS recipe_count
                        FROM products p
                        LEFT JOIN product_categories c ON p.category_id = c.id
                        LEFT JOIN workflow_templates wt ON p.default_workflow_template_id = wt.id
                        ORDER BY p.id DESC")->fetchAll();

// Product Elements Map
$allProductElements = $db->query("SELECT * FROM product_elements ORDER BY product_id ASC, sort_order ASC, id ASC")->fetchAll();
$productElementsMap = [];
foreach ($allProductElements as $el) {
    $productElementsMap[$el['product_id']][] = $el;
}

// Active Raw Materials
$materials = $db->query("SELECT * FROM materials WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Product Recipes / BOMs
$recipes = $db->query("SELECT b.*, p.name AS product_name, p.code AS product_code, p.product_type,
                       (SELECT COUNT(*) FROM bom_items WHERE bom_id = b.id) AS material_count
                       FROM bom_headers b
                       JOIN products p ON b.product_id = p.id
                       WHERE b.is_active = 1
                       ORDER BY p.name ASC")->fetchAll();

// Recipe Items Map
$recipeItems = $db->query("SELECT bi.*, m.name AS material_name, m.code AS material_code, m.unit_of_measure, m.cost_price 
                           FROM bom_items bi 
                           JOIN materials m ON bi.material_id = m.id 
                           ORDER BY bi.bom_id ASC, m.name ASC")->fetchAll();
$recipeItemsMap = [];
foreach ($recipeItems as $ri) {
    $recipeItemsMap[$ri['bom_id']][] = $ri;
}

$categories = $db->query("SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) AS product_count FROM product_categories c ORDER BY c.name ASC")->fetchAll();
$templates = $db->query("SELECT * FROM workflow_templates WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$colors = $db->query("SELECT * FROM colors ORDER BY name ASC")->fetchAll();
$sizes = $db->query("SELECT * FROM sizes ORDER BY sort_order ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Products, Pattern Elements & Recipes</h4>
                <p class="text-muted small mb-0">Define products, structural cutting components, and raw material consumption recipes (BOM)</p>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-pills mb-4 gap-2" id="prodTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-semibold" data-bs-toggle="pill" data-bs-target="#productsTab" type="button">
                    <i class="bi bi-tag-fill me-1"></i> Products & Elements (<?= count($products) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#recipesTab" type="button">
                    <i class="bi bi-file-earmark-spreadsheet-fill text-warning me-1"></i> Product Recipes / BOM (<?= count($recipes) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#categoriesTab" type="button">
                    <i class="bi bi-folder-fill me-1"></i> Categories (<?= count($categories) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#variantsTab" type="button">
                    <i class="bi bi-palette-fill me-1"></i> Colors & Sizes
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- =============================================================== -->
            <!-- TAB 1: PRODUCTS & REAL-TIME PATTERN ELEMENTS                    -->
            <!-- =============================================================== -->
            <div class="tab-pane fade show active" id="productsTab">
                <!-- Add New Product Form with Elements Builder -->
                <div class="card-modern mb-4">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Add New Product & Garment Elements</h6>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="" id="newProductForm">
                            <input type="hidden" name="action" value="create_product">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            
                            <!-- Master Attributes -->
                            <div class="row g-3 mb-4">
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Product Code *</label>
                                    <input type="text" name="code" class="form-control text-uppercase fw-bold" placeholder="e.g. PRD-JK-02" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Product Name *</label>
                                    <input type="text" name="name" class="form-control fw-bold" placeholder="e.g. Mens Quilted Winter Jacket" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label mb-0 fw-semibold">Category</label>
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                            <i class="bi bi-plus-circle"></i> New
                                        </button>
                                    </div>
                                    <select name="category_id" class="form-select">
                                        <option value="">-- Select Category --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat['id'] ?>"><?= clean($cat['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label fw-semibold">Product Type</label>
                                    <select name="product_type" class="form-select">
                                        <option value="Coat">Coat</option>
                                        <option value="Sweater">Sweater</option>
                                        <option value="Jacket" selected>Jacket</option>
                                        <option value="Cardigan">Cardigan</option>
                                        <option value="Vest">Vest / کوٹی</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Default Workflow Template</label>
                                    <select name="default_workflow_template_id" class="form-select">
                                        <option value="">-- Select Workflow --</option>
                                        <?php foreach ($templates as $tmpl): ?>
                                            <option value="<?= $tmpl['id'] ?>"><?= clean($tmpl['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label fw-semibold">Description / Styling Specifications</label>
                                    <input type="text" name="description" class="form-control" placeholder="Fabric blend, styling details, pockets, collar type">
                                </div>
                            </div>

                            <!-- Real-Time Pattern Elements / Components Builder -->
                            <div class="border rounded-3 p-3 bg-white mb-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">
                                            <i class="bi bi-puzzle-fill text-warning me-1"></i> Garment Elements / Pattern Breakdown (کٹنگ اور سلائی کے اجزاء)
                                        </h6>
                                        <span class="text-muted small">Specify garment pieces per unit (e.g. 1 Front, 1 Back, 2 Sleeves, 2 Pockets, 1 Collar) for floor artisans</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="small text-muted fw-semibold">Presets:</span>
                                        <button type="button" class="btn btn-xs btn-outline-primary py-1 px-2" id="btnProductPresetJacket">🧥 Jacket</button>
                                        <button type="button" class="btn btn-xs btn-outline-warning py-1 px-2" id="btnProductPresetVest">🦺 Vest (No Sleeves)</button>
                                        <button type="button" class="btn btn-xs btn-outline-info py-1 px-2" id="btnProductPresetSweater">👕 Sweater</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" id="btnProductClearElements">Clear</button>
                                    </div>
                                </div>

                                <!-- Quick Add 1-Click Chips -->
                                <div class="p-2 bg-light border rounded-2 mb-3">
                                    <div class="small fw-bold text-dark text-uppercase mb-2" style="font-size: 0.72rem;">
                                        <i class="bi bi-plus-circle me-1 text-primary"></i> 1-Click Quick Add Common Garment Parts:
                                    </div>
                                    <div class="d-flex flex-wrap gap-1" id="prodQuickChips">
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Front" data-qty="1" data-notes="Main front body">+ Front (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Back" data-qty="1" data-notes="Main back body">+ Back (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Sleeves" data-qty="2" data-notes="Left & right sleeves">+ Sleeves (2)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Pockets" data-qty="2" data-notes="Side welt / patch pockets">+ Pockets (2)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Collar / Neck" data-qty="1" data-notes="Ribbed collar / stand neck">+ Collar/Neck (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Hood" data-qty="1" data-notes="Detachable hood">+ Hood (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Cuffs" data-qty="2" data-notes="Wrist cuffs">+ Cuffs (2)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Bottom Hem" data-qty="1" data-notes="Elasticated rib / border">+ Bottom Hem (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 prod-quick-chip" data-name="Inner Lining" data-qty="1" data-notes="Inner silk/poly lining">+ Inner Lining (1)</button>
                                    </div>
                                </div>

                                <!-- Dynamic Elements Repeater Table -->
                                <div class="table-responsive border rounded-2 mb-2">
                                    <table class="table table-bordered align-middle mb-0" id="prodElementsTable">
                                        <thead class="table-light small">
                                            <tr>
                                                <th style="width: 45px;" class="text-center">#</th>
                                                <th style="min-width: 200px;">Element / Component Name *</th>
                                                <th style="width: 150px;" class="text-center">Qty Per Piece *</th>
                                                <th>Pattern / Fabric Notes</th>
                                                <th style="width: 50px;" class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="prodElementsTbody">
                                            <!-- Dynamically filled -->
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" id="btnAddProdElementRow">
                                        <i class="bi bi-plus-circle me-1"></i> + Add Custom Element Row
                                    </button>
                                    <div class="d-flex align-items-center gap-3 small text-muted">
                                        <span>Components: <strong id="lblProdElementsCount" class="text-dark">0</strong></span>
                                        <span class="vr"></span>
                                        <span>Total Pieces / Unit: <strong id="lblProdPiecesCount" class="text-primary fw-bold">0 pcs</strong></span>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary px-5 py-2 fw-bold shadow-sm">
                                    <i class="bi bi-check2-circle me-1"></i> Save Product with Elements
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Configured Products Table -->
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">Configured Products & Pattern Elements</h6>
                        <span class="badge bg-light text-secondary border"><?= count($products) ?> Products</span>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Product Name</th>
                                        <th>Category / Type</th>
                                        <th>Garment Elements / Pattern Parts (اجزاء)</th>
                                        <th>Default Workflow</th>
                                        <th>Recipe Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $p): 
                                        $pElems = $productElementsMap[$p['id']] ?? [];
                                        $elemSum = array_sum(array_column($pElems, 'quantity'));
                                    ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= clean($p['code']) ?></td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= clean($p['name']) ?></div>
                                                <?php if ($p['description']): ?>
                                                    <div class="small text-muted"><?= clean($p['description']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?= clean($p['product_type']) ?></span>
                                                <div class="small text-muted mt-1"><?= clean($p['category_name'] ?? 'General') ?></div>
                                            </td>
                                            <td>
                                                <?php if (empty($pElems)): ?>
                                                    <span class="text-muted small fst-italic">No elements specified</span>
                                                <?php else: ?>
                                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                                        <?php foreach ($pElems as $pe): ?>
                                                            <span class="badge bg-light text-dark border" style="font-size: 0.73rem;" title="<?= clean($pe['notes'] ?? '') ?>">
                                                                <?= clean($pe['element_name']) ?> <strong class="text-primary">×<?= (int)$pe['quantity'] ?></strong>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="small text-muted mt-1" style="font-size: 0.72rem;">
                                                        <?= count($pElems) ?> components (<?= $elemSum ?> pcs / unit)
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted"><?= clean($p['template_name'] ?? 'Standard') ?></td>
                                            <td>
                                                <?php if ($p['recipe_count'] > 0): ?>
                                                    <span class="badge bg-success-subtle text-success border fw-semibold">
                                                        <i class="bi bi-check-circle me-1"></i>Recipe Configured
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border fw-semibold">
                                                        <i class="bi bi-exclamation-circle me-1"></i>No Recipe
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?= BASE_URL ?>modules/settings/product_edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="Edit Product & Elements">
                                                        <i class="bi bi-pencil-square me-1"></i>Edit
                                                    </a>
                                                    <button type="button" class="btn btn-outline-warning btn-recipe-trigger" data-product-id="<?= $p['id'] ?>" data-product-name="<?= clean($p['name']) ?>" title="Configure Raw Material Recipe (BOM)">
                                                        <i class="bi bi-receipt me-1"></i>Recipe
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =============================================================== -->
            <!-- TAB 2: PRODUCT RECIPES / BOM (RAW MATERIAL CONSUMPTION)        -->
            <!-- =============================================================== -->
            <div class="tab-pane fade" id="recipesTab">
                <!-- Configure Recipe Form -->
                <div class="card-modern mb-4">
                    <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="bi bi-file-earmark-spreadsheet-fill text-warning me-1"></i> Product Recipe / Bill of Materials (BOM)
                            </h6>
                            <span class="text-muted small">Define required raw materials (Yarn, Buttons, Zipper, Thread, Lining) & estimated quantity per 1 piece</span>
                        </div>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="" id="recipeForm">
                            <input type="hidden" name="action" value="save_recipe">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="row g-3 mb-4">
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Select Target Product *</label>
                                    <select name="product_id" id="recipe_product_id" class="form-select fw-bold text-primary" required>
                                        <option value="">-- Choose Product --</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?= $p['id'] ?>">
                                                <?= clean($p['name']) ?> (<?= clean($p['code']) ?>) [<?= clean($p['product_type']) ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label fw-semibold">Recipe Name / Description</label>
                                    <input type="text" name="description" id="recipe_description" class="form-control" placeholder="e.g. Standard Production Recipe (Buttons, Yarn, Lining)">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Wastage / Buffer Allowance (%)</label>
                                    <div class="input-group">
                                        <input type="number" name="wastage_percent" id="recipe_wastage" class="form-control" step="0.5" min="0" max="30" value="3.0">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <div class="form-text small">Estimated cutting/stitching wastage</div>
                                </div>
                            </div>

                            <!-- Raw Materials Consumption Table -->
                            <div class="border rounded-3 p-3 bg-white mb-3">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 pb-2 border-bottom">
                                    <h6 class="fw-bold mb-0 text-dark">
                                        <i class="bi bi-boxes text-primary me-1"></i> Raw Material Components Per Finished Garment Unit
                                    </h6>
                                    <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" id="btnClearRecipeRows">Clear</button>
                                </div>

                                <!-- 1-Click Quick Material Chips -->
                                <div class="p-2 bg-light border rounded-2 mb-3">
                                    <div class="small fw-bold text-dark text-uppercase mb-2" style="font-size: 0.72rem;">
                                        <i class="bi bi-plus-circle me-1 text-primary"></i> 1-Click Quick Add Common Raw Materials:
                                    </div>
                                    <div class="d-flex flex-wrap gap-1" id="matQuickChips">
                                        <?php foreach ($materials as $m): ?>
                                            <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 mat-quick-chip" 
                                                    data-id="<?= $m['id'] ?>" 
                                                    data-name="<?= clean($m['name']) ?>" 
                                                    data-unit="<?= clean($m['unit_of_measure']) ?>">
                                                + <?= clean($m['name']) ?> (<?= clean($m['unit_of_measure']) ?>)
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="table-responsive border rounded-2 mb-2">
                                    <table class="table table-bordered align-middle mb-0" id="recipeTable">
                                        <thead class="table-light small">
                                            <tr>
                                                <th style="width: 45px;" class="text-center">#</th>
                                                <th style="min-width: 250px;">Raw Material *</th>
                                                <th style="width: 140px;" class="text-center">Unit of Measure</th>
                                                <th style="width: 200px;" class="text-center">Qty / Piece *</th>
                                                <th style="width: 60px;" class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="recipeTbody">
                                            <!-- Dynamically added rows -->
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-between align-items-center pt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" id="btnAddRecipeRow">
                                        <i class="bi bi-plus-circle me-1"></i> + Add Material Component
                                    </button>
                                    <span class="small text-muted">Total Recipe Materials: <strong id="lblRecipeMatCount" class="text-dark">0</strong></span>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-warning px-5 py-2 fw-bold shadow-sm text-dark">
                                    <i class="bi bi-check2-circle me-1"></i> Save Product Recipe (BOM)
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Configured Recipes Table -->
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">Configured Product Recipes / BOMs</h6>
                        <span class="badge bg-light text-secondary border"><?= count($recipes) ?> Recipes</span>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Recipe Description</th>
                                        <th class="text-center">Wastage %</th>
                                        <th>Raw Material Components & Consumption / Piece</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recipes)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">No product recipes configured yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recipes as $r): 
                                            $items = $recipeItemsMap[$r['id']] ?? [];
                                        ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-dark"><?= clean($r['product_name']) ?></span>
                                                    <div class="small text-muted"><?= clean($r['product_code']) ?> • <?= clean($r['product_type']) ?></div>
                                                </td>
                                                <td><?= clean($r['description'] ?: 'Standard BOM') ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-light text-dark border"><?= (float)$r['wastage_percent'] ?>%</span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                                        <?php foreach ($items as $it): ?>
                                                            <span class="badge bg-light text-dark border" style="font-size: 0.75rem;">
                                                                <?= clean($it['material_name']) ?>: <strong class="text-primary"><?= (float)$it['quantity_per_piece'] ?> <?= clean($it['unit_of_measure']) ?></strong>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-warning btn-edit-recipe" 
                                                            data-product-id="<?= $r['product_id'] ?>" 
                                                            data-desc="<?= clean($r['description']) ?>" 
                                                            data-wastage="<?= (float)$r['wastage_percent'] ?>" 
                                                            data-items='<?= json_encode($items) ?>'>
                                                        <i class="bi bi-pencil-square me-1"></i>Edit Recipe
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =============================================================== -->
            <!-- TAB 3: CATEGORIES                                              -->
            <!-- =============================================================== -->
            <div class="tab-pane fade" id="categoriesTab">
                <div class="card-modern mb-4">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Add Product Category</h6>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_category">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            
                            <div class="row g-3 align-items-end">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Category Name *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Knitwear & Sweaters" required>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Description</label>
                                    <input type="text" name="description" class="form-control" placeholder="Brief description of category">
                                </div>
                                <div class="col-12 col-md-2">
                                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                                        Save Category
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Configured Product Categories</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">ID</th>
                                        <th>Category Name</th>
                                        <th>Description</th>
                                        <th>Associated Products</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $cat): ?>
                                        <tr>
                                            <td class="text-muted fw-bold">#<?= $cat['id'] ?></td>
                                            <td class="fw-bold text-dark"><i class="bi bi-folder2 text-primary me-1"></i><?= clean($cat['name']) ?></td>
                                            <td class="text-muted"><?= clean($cat['description'] ?: '—') ?></td>
                                            <td><span class="badge bg-primary-subtle text-primary fw-semibold"><?= $cat['product_count'] ?? 0 ?> products</span></td>
                                            <td><span class="badge bg-success-subtle text-success fw-semibold">Active</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =============================================================== -->
            <!-- TAB 4: VARIANTS: COLORS & SIZES                                 -->
            <!-- =============================================================== -->
            <div class="tab-pane fade" id="variantsTab">
                <div class="row g-4">
                    <!-- Colors Column -->
                    <div class="col-12 col-md-6">
                        <div class="card-modern mb-3">
                            <div class="card-modern-header">
                                <h6 class="fw-bold mb-0 text-dark">Add Color</h6>
                            </div>
                            <div class="card-modern-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="create_color">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <div class="input-group">
                                        <input type="text" name="name" class="form-control" placeholder="Color Name (e.g. Navy Blue)" required>
                                        <input type="color" name="hex_code" class="form-control form-control-color" value="#2563eb" title="Choose color">
                                        <button type="submit" class="btn btn-primary fw-semibold">Add Color</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card-modern">
                            <div class="card-modern-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-modern mb-0">
                                        <thead>
                                            <tr>
                                                <th>Preview</th>
                                                <th>Color Name</th>
                                                <th>Hex Code</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($colors as $c): ?>
                                                <tr>
                                                    <td>
                                                        <span class="d-inline-block rounded-circle border" style="width: 20px; height: 20px; background-color: <?= clean($c['hex_code']) ?>;"></span>
                                                    </td>
                                                    <td class="fw-semibold text-dark"><?= clean($c['name']) ?></td>
                                                    <td class="text-muted font-monospace small"><?= clean($c['hex_code']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sizes Column -->
                    <div class="col-12 col-md-6">
                        <div class="card-modern mb-3">
                            <div class="card-modern-header">
                                <h6 class="fw-bold mb-0 text-dark">Add Size</h6>
                            </div>
                            <div class="card-modern-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="create_size">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <div class="row g-2">
                                        <div class="col-5">
                                            <input type="text" name="name" class="form-control" placeholder="Size Name (XX-Large)" required>
                                        </div>
                                        <div class="col-4">
                                            <input type="text" name="code" class="form-control" placeholder="Code (3XL)" required>
                                        </div>
                                        <div class="col-3">
                                            <button type="submit" class="btn btn-primary w-100 fw-semibold">Add</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card-modern">
                            <div class="card-modern-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-modern mb-0">
                                        <thead>
                                            <tr>
                                                <th>Sort</th>
                                                <th>Size Name</th>
                                                <th>Short Code</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sizes as $s): ?>
                                                <tr>
                                                    <td><span class="badge bg-light text-dark border"><?= $s['sort_order'] ?></span></td>
                                                    <td class="fw-semibold text-dark"><?= clean($s['name']) ?></td>
                                                    <td class="fw-bold text-primary"><?= clean($s['code']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
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

<!-- Quick Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_category">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-folder-plus text-primary me-2"></i>Add Product Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Knitwear & Sweaters" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Category notes or details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Datalist for autocomplete -->
<datalist id="garmentElementsDatalist">
    <option value="Front">
    <option value="Front Panels">
    <option value="Back">
    <option value="Sleeves">
    <option value="Long Sleeves">
    <option value="Short Sleeves">
    <option value="Pockets">
    <option value="Side Pockets">
    <option value="Collar / Neck">
    <option value="Stand Collar">
    <option value="V-Neck Trim">
    <option value="Round Neck Rib">
    <option value="Hood">
    <option value="Cuffs">
    <option value="Bottom Hem">
    <option value="Inner Lining">
    <option value="Zipper Placket">
    <option value="Waistband">
    <option value="Belt">
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. URL Hash-based Tab Switching (#products, #recipes, #variants, #categories)
    const hash = window.location.hash;
    if (hash) {
        const cleanHash = hash.replace('#', '');
        const triggerEl = document.querySelector(`button[data-bs-target="#${cleanHash}Tab"]`);
        if (triggerEl) {
            const tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }

    // 2. Real-Time Dynamic Garment Elements Repeater (Product Form)
    const pTbody = document.getElementById('prodElementsTbody');
    const lblCount = document.getElementById('lblProdElementsCount');
    const lblPcs = document.getElementById('lblProdPiecesCount');
    const btnAddProdRow = document.getElementById('btnAddProdElementRow');

    function createProdRow(name = '', qty = 1, notes = '') {
        const tr = document.createElement('tr');
        tr.className = 'prod-element-row';
        tr.innerHTML = `
            <td class="text-center fw-bold text-muted p-row-idx">1</td>
            <td>
                <input type="text" name="element_name[]" class="form-control form-control-sm fw-semibold" value="${escapeHtml(name)}" placeholder="e.g. Front, Sleeves, Pockets" list="garmentElementsDatalist" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary btn-pel-minus"><i class="bi bi-dash"></i></button>
                    <input type="number" name="element_qty[]" class="form-control text-center fw-bold pel-qty-val" value="${qty}" min="1" max="50" required>
                    <button type="button" class="btn btn-outline-secondary btn-pel-plus"><i class="bi bi-plus"></i></button>
                </div>
            </td>
            <td>
                <input type="text" name="element_notes[]" class="form-control form-control-sm" value="${escapeHtml(notes)}" placeholder="e.g. Cut 2 panels, ribbed cuffs, welt finish">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 btn-pel-remove" title="Remove component">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        pTbody.appendChild(tr);
        bindProdRowEvents(tr);
        recalcProdTotals();
    }

    function bindProdRowEvents(tr) {
        const minus = tr.querySelector('.btn-pel-minus');
        const plus = tr.querySelector('.btn-pel-plus');
        const qty = tr.querySelector('.pel-qty-val');
        const remove = tr.querySelector('.btn-pel-remove');

        minus.addEventListener('click', function() {
            let v = parseInt(qty.value) || 1;
            if (v > 1) {
                qty.value = v - 1;
                recalcProdTotals();
            }
        });

        plus.addEventListener('click', function() {
            let v = parseInt(qty.value) || 1;
            qty.value = v + 1;
            recalcProdTotals();
        });

        qty.addEventListener('input', recalcProdTotals);

        remove.addEventListener('click', function() {
            tr.remove();
            recalcProdTotals();
        });
    }

    function recalcProdTotals() {
        const rows = pTbody.querySelectorAll('.prod-element-row');
        let totalPcs = 0;
        rows.forEach((r, i) => {
            r.querySelector('.p-row-idx').textContent = i + 1;
            totalPcs += parseInt(r.querySelector('.pel-qty-val').value) || 0;
        });
        lblCount.textContent = rows.length;
        lblPcs.textContent = totalPcs + ' pcs';
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    if (btnAddProdRow) {
        btnAddProdRow.addEventListener('click', function() {
            createProdRow('', 1, '');
            const inputs = pTbody.querySelectorAll('input[name="element_name[]"]');
            if (inputs.length) inputs[inputs.length - 1].focus();
        });
    }

    // Quick Add Chips for Products
    document.querySelectorAll('.prod-quick-chip').forEach(btn => {
        btn.addEventListener('click', function() {
            const name = this.getAttribute('data-name');
            const qty = parseInt(this.getAttribute('data-qty')) || 1;
            const notes = this.getAttribute('data-notes') || '';
            createProdRow(name, qty, notes);
        });
    });

    // Clear Elements
    const btnClearProd = document.getElementById('btnProductClearElements');
    if (btnClearProd) {
        btnClearProd.addEventListener('click', function() {
            pTbody.innerHTML = '';
            recalcProdTotals();
        });
    }

    // Presets
    const pJacket = document.getElementById('btnProductPresetJacket');
    if (pJacket) {
        pJacket.addEventListener('click', function() {
            pTbody.innerHTML = '';
            createProdRow('Front Panels', 2, 'Left & right overlap panels with zipper');
            createProdRow('Back', 1, 'Full back body');
            createProdRow('Sleeves', 2, 'Left and right sleeves');
            createProdRow('Pockets', 2, 'Front side welt pockets');
            createProdRow('Collar', 1, 'Stand / lapel collar');
            createProdRow('Inner Lining', 1, 'Silk polyester inner body');
        });
    }

    const pVest = document.getElementById('btnProductPresetVest');
    if (pVest) {
        pVest.addEventListener('click', function() {
            pTbody.innerHTML = '';
            createProdRow('Front Panels', 2, 'Left & right front vest panels');
            createProdRow('Back', 1, 'Back panel with buckle adjuster');
            createProdRow('Pockets', 2, 'Waist welt pockets');
            createProdRow('V-Neck Collar', 1, 'V-neck border');
            createProdRow('Inner Lining', 1, 'Breathable lining');
        });
    }

    const pSweater = document.getElementById('btnProductPresetSweater');
    if (pSweater) {
        pSweater.addEventListener('click', function() {
            pTbody.innerHTML = '';
            createProdRow('Front', 1, 'Main knit front pattern');
            createProdRow('Back', 1, 'Main knit back');
            createProdRow('Sleeves', 2, 'Left and right knit sleeves');
            createProdRow('Neck Rib', 1, 'Neck trim collar');
            createProdRow('Bottom Hem Rib', 1, 'Elastic waist ribbing');
        });
    }

    // Load initial default rows for product
    if (pTbody && pTbody.children.length === 0) {
        createProdRow('Front', 1, 'Main front body');
        createProdRow('Back', 1, 'Main back body');
        createProdRow('Sleeves', 2, 'Left & right sleeves');
        createProdRow('Pockets', 2, 'Side pockets');
        createProdRow('Collar / Neck', 1, 'Collar / Neck trim');
    }

    // =========================================================================
    // 3. PRODUCT RECIPE / BOM BUILDER SCRIPT
    // =========================================================================
    const rawMaterialsList = <?= json_encode($materials) ?>;
    const rTbody = document.getElementById('recipeTbody');
    const lblMatCount = document.getElementById('lblRecipeMatCount');
    const btnAddRecipeRow = document.getElementById('btnAddRecipeRow');
    const btnClearRecipe = document.getElementById('btnClearRecipeRows');
    const recipeProdSelect = document.getElementById('recipe_product_id');

    function createRecipeRow(matId = '', qty = '', unit = '') {
        const tr = document.createElement('tr');
        tr.className = 'recipe-row';

        let matOptions = '<option value="">-- Choose Raw Material --</option>';
        rawMaterialsList.forEach(m => {
            const sel = (matId && matId == m.id) ? 'selected' : '';
            matOptions += `<option value="${m.id}" data-unit="${escapeHtml(m.unit_of_measure)}" ${sel}>${escapeHtml(m.name)} (${escapeHtml(m.unit_of_measure)}) [Stock: ${m.current_stock}]</option>`;
        });

        tr.innerHTML = `
            <td class="text-center fw-bold text-muted r-idx">1</td>
            <td>
                <select name="material_id[]" class="form-select form-select-sm fw-semibold sel-recipe-mat" required>
                    ${matOptions}
                </select>
            </td>
            <td class="text-center">
                <span class="badge bg-light text-dark border lbl-mat-unit">${unit || '—'}</span>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="quantity_per_piece[]" class="form-control text-center fw-bold inp-recipe-qty" value="${qty}" step="0.0001" min="0.0001" placeholder="e.g. 0.450 or 6" required>
                    <span class="input-group-text small lbl-input-unit">${unit || 'Qty'}</span>
                </div>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 btn-remove-recipe" title="Remove material">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        rTbody.appendChild(tr);
        bindRecipeRowEvents(tr);
        recalcRecipeTotals();
    }

    function bindRecipeRowEvents(tr) {
        const sel = tr.querySelector('.sel-recipe-mat');
        const unitBadge = tr.querySelector('.lbl-mat-unit');
        const inputUnit = tr.querySelector('.lbl-input-unit');
        const remove = tr.querySelector('.btn-remove-recipe');

        sel.addEventListener('change', function() {
            const selectedOpt = this.options[this.selectedIndex];
            const u = selectedOpt.getAttribute('data-unit') || '—';
            unitBadge.textContent = u;
            inputUnit.textContent = u;
        });

        remove.addEventListener('click', function() {
            tr.remove();
            recalcRecipeTotals();
        });
    }

    function recalcRecipeTotals() {
        const rows = rTbody.querySelectorAll('.recipe-row');
        rows.forEach((r, i) => {
            r.querySelector('.r-idx').textContent = i + 1;
        });
        lblMatCount.textContent = rows.length;
    }

    if (btnAddRecipeRow) {
        btnAddRecipeRow.addEventListener('click', function() {
            createRecipeRow();
        });
    }

    if (btnClearRecipe) {
        btnClearRecipe.addEventListener('click', function() {
            rTbody.innerHTML = '';
            recalcRecipeTotals();
        });
    }

    // Material quick chips in Recipe tab
    document.querySelectorAll('.mat-quick-chip').forEach(btn => {
        btn.addEventListener('click', function() {
            const mId = this.getAttribute('data-id');
            const unit = this.getAttribute('data-unit');
            createRecipeRow(mId, '', unit);
        });
    });

    // Action button on Product table: "Recipe"
    document.querySelectorAll('.btn-recipe-trigger').forEach(btn => {
        btn.addEventListener('click', function() {
            const pId = this.getAttribute('data-product-id');
            const pName = this.getAttribute('data-product-name');
            const recipeTabBtn = document.querySelector('button[data-bs-target="#recipesTab"]');
            if (recipeTabBtn) {
                new bootstrap.Tab(recipeTabBtn).show();
            }
            recipeProdSelect.value = pId;
            document.getElementById('recipe_description').value = `Production Recipe for ${pName}`;
            // If already has rows or edit, trigger change
        });
    });

    // Action button on Recipe table: "Edit Recipe"
    document.querySelectorAll('.btn-edit-recipe').forEach(btn => {
        btn.addEventListener('click', function() {
            const pId = this.getAttribute('data-product-id');
            const desc = this.getAttribute('data-desc');
            const wastage = this.getAttribute('data-wastage');
            const items = JSON.parse(this.getAttribute('data-items') || '[]');

            recipeProdSelect.value = pId;
            document.getElementById('recipe_description').value = desc;
            document.getElementById('recipe_wastage').value = wastage;

            rTbody.innerHTML = '';
            items.forEach(it => {
                createRecipeRow(it.material_id, it.quantity_per_piece, it.unit_of_measure);
            });

            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
