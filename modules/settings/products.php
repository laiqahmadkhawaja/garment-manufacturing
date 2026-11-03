<?php
/**
 * Garment Manufacturing ERP - Products, Designs, Colors & Sizes Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Products & Designs Management';
$activeNav = 'settings_prod';
$db = get_db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_product') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $productType = trim($_POST['product_type'] ?? 'Coat');
        $desc = trim($_POST['description'] ?? '');
        $templateId = !empty($_POST['default_workflow_template_id']) ? (int)$_POST['default_workflow_template_id'] : null;

        if (empty($code) || empty($name)) {
            set_flash('danger', 'Product code and name are required.');
        } else {
            $stmt = $db->prepare("INSERT INTO products (code, name, category_id, product_type, description, default_workflow_template_id, is_active) 
                                  VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$code, $name, $categoryId, $productType, $desc, $templateId]);
            log_audit('CREATE', 'products', (int)$db->lastInsertId(), null, ['name' => $name, 'code' => $code]);
            redirect('modules/settings/products.php', "Product '$name' added successfully!");
        }
    }

    if ($action === 'create_design') {
        $designNumber = trim($_POST['design_number'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $productId = (int)($_POST['product_id'] ?? 0);
        $specs = trim($_POST['specifications'] ?? '');
        $templateId = !empty($_POST['workflow_template_id']) ? (int)$_POST['workflow_template_id'] : null;

        $elemNames = $_POST['element_name'] ?? [];
        $elemQtys = $_POST['element_qty'] ?? [];
        $elemNotes = $_POST['element_notes'] ?? [];

        if (empty($designNumber) || empty($name) || $productId <= 0) {
            set_flash('danger', 'Design number, name, and parent product are required.');
        } else {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("INSERT INTO designs (design_number, name, product_id, specifications, workflow_template_id, is_active) 
                                      VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$designNumber, $name, $productId, $specs, $templateId]);
                $newDesignId = (int)$db->lastInsertId();

                // Save design pattern elements
                $savedCount = 0;
                if (is_array($elemNames) && !empty($elemNames)) {
                    $insElem = $db->prepare("INSERT INTO design_elements (design_id, element_name, quantity, notes, sort_order) VALUES (?, ?, ?, ?, ?)");
                    $sort = 1;
                    foreach ($elemNames as $idx => $eName) {
                        $eName = trim($eName);
                        if ($eName === '') continue;
                        $q = max(1, (int)($elemQtys[$idx] ?? 1));
                        $notes = trim($elemNotes[$idx] ?? '');
                        $insElem->execute([$newDesignId, $eName, $q, $notes, $sort++]);
                        $savedCount++;
                    }
                }

                $db->commit();
                log_audit('CREATE', 'designs', $newDesignId, null, ['design_number' => $designNumber, 'name' => $name, 'elements' => $savedCount]);
                redirect('modules/settings/products.php#designs', "Design #$designNumber added successfully with $savedCount garment elements!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Error saving design: ' . $e->getMessage());
            }
        }
    }

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

// Fetch all data
$products = $db->query("SELECT p.*, c.name AS category_name, wt.name AS template_name,
                        (SELECT COUNT(*) FROM designs WHERE product_id = p.id) AS design_count
                        FROM products p
                        LEFT JOIN product_categories c ON p.category_id = c.id
                        LEFT JOIN workflow_templates wt ON p.default_workflow_template_id = wt.id
                        ORDER BY p.id DESC")->fetchAll();

$designs = $db->query("SELECT d.*, p.name AS product_name, p.code AS product_code, wt.name AS template_name 
                       FROM designs d
                       JOIN products p ON d.product_id = p.id
                       LEFT JOIN workflow_templates wt ON d.workflow_template_id = wt.id
                       ORDER BY d.id DESC")->fetchAll();

$allElements = $db->query("SELECT * FROM design_elements ORDER BY design_id ASC, sort_order ASC, id ASC")->fetchAll();
$designElementsMap = [];
foreach ($allElements as $el) {
    $designElementsMap[$el['design_id']][] = $el;
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
                <h4 class="fw-bold mb-1 text-dark">Products, Designs & Variants</h4>
                <p class="text-muted small mb-0">Define products, designs, color codes and size matrix</p>
            </div>
        </div>

        <!-- Navigation Tabs for Sections -->
        <ul class="nav nav-pills mb-4 gap-2" id="prodTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-semibold" data-bs-toggle="pill" data-bs-target="#productsTab" type="button">
                    <i class="bi bi-tag-fill me-1"></i> Products (<?= count($products) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#categoriesTab" type="button">
                    <i class="bi bi-folder-fill me-1"></i> Categories (<?= count($categories) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#designsTab" type="button">
                    <i class="bi bi-stars me-1"></i> Designs (<?= count($designs) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#variantsTab" type="button">
                    <i class="bi bi-palette-fill me-1"></i> Colors & Sizes
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- PRODUCTS TAB -->
            <div class="tab-pane fade show active" id="productsTab">
                <!-- Inline Expandable Form for Creating Product -->
                <div class="card-modern mb-4">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Add New Product</h6>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_product">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            
                            <div class="row g-3">
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Product Code *</label>
                                    <input type="text" name="code" class="form-control text-uppercase" placeholder="e.g. PRD-SW-02" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Product Name *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Mens Cardigan Sweater" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label mb-0">Category</label>
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
                                    <label class="form-label">Product Type</label>
                                    <select name="product_type" class="form-select">
                                        <option value="Coat">Coat</option>
                                        <option value="Sweater">Sweater</option>
                                        <option value="Jacket">Jacket</option>
                                        <option value="Cardigan">Cardigan</option>
                                        <option value="Vest">Vest</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Default Workflow Template</label>
                                    <select name="default_workflow_template_id" class="form-select">
                                        <option value="">-- Select Workflow --</option>
                                        <?php foreach ($templates as $tmpl): ?>
                                            <option value="<?= $tmpl['id'] ?>"><?= clean($tmpl['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Description / Specifications</label>
                                    <input type="text" name="description" class="form-control" placeholder="Fabric blend, styling details, neck type">
                                </div>
                                <div class="col-12 col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                                        Save Product
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Configured Products</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Designs</th>
                                        <th>Default Workflow</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $p): ?>
                                        <tr>
                                            <td class="fw-bold text-primary"><?= clean($p['code']) ?></td>
                                            <td class="fw-semibold text-dark"><?= clean($p['name']) ?></td>
                                            <td><?= clean($p['category_name'] ?? 'General') ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= clean($p['product_type']) ?></span></td>
                                            <td><span class="badge bg-info-subtle text-info fw-bold"><?= $p['design_count'] ?> designs</span></td>
                                            <td class="small text-muted"><?= clean($p['template_name'] ?? 'Standard') ?></td>
                                            <td>
                                                <span class="badge-status <?= $p['is_active'] ? 'badge-completed' : 'badge-draft' ?>">
                                                    <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CATEGORIES TAB -->
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
                                    <input type="text" name="description" class="form-control" placeholder="Brief description of product category">
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
                                    <?php if (empty($categories)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">No product categories created yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($categories as $cat): ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= $cat['id'] ?></td>
                                                <td class="fw-bold text-dark">
                                                    <i class="bi bi-folder2 text-primary me-1"></i>
                                                    <?= clean($cat['name']) ?>
                                                </td>
                                                <td class="text-muted"><?= clean($cat['description'] ?: '—') ?></td>
                                                <td>
                                                    <span class="badge bg-primary-subtle text-primary fw-semibold">
                                                        <?= $cat['product_count'] ?? 0 ?> products
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success-subtle text-success fw-semibold">Active</span>
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

            <!-- DESIGNS TAB -->
            <div class="tab-pane fade" id="designsTab">
                <!-- Inline Expandable Form for Creating Design -->
                <div class="card-modern mb-4">
                    <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Add New Design</h6>
                            <span class="text-muted small">Specify master design details & define garment pattern elements in real-time</span>
                        </div>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="" id="addDesignForm">
                            <input type="hidden" name="action" value="create_design">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            
                            <div class="row g-3 mb-4">
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Design Number *</label>
                                    <input type="text" name="design_number" class="form-control fw-bold text-primary" placeholder="e.g. 904" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Parent Product *</label>
                                    <select name="product_id" class="form-select fw-semibold" required>
                                        <option value="">-- Select Product --</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= clean($p['name']) ?> (<?= clean($p['code']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Design Name *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Classic Slim Fit 904" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Workflow Override</label>
                                    <select name="workflow_template_id" class="form-select">
                                        <option value="">-- Inherit from Product --</option>
                                        <?php foreach ($templates as $tmpl): ?>
                                            <option value="<?= $tmpl['id'] ?>"><?= clean($tmpl['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Design Specifications & Styling Notes</label>
                                    <input type="text" name="specifications" class="form-control" placeholder="Cuff style, pocket cut, stitch density, rib trim">
                                </div>
                            </div>

                            <!-- Real-Time Garment Elements / Pattern Components Builder -->
                            <div class="border rounded-3 p-3 bg-white mb-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">
                                            <i class="bi bi-puzzle-fill text-warning me-1"></i> Garment Elements / Pattern Breakdown (اجزاء اور کٹنگ کے حصے)
                                        </h6>
                                        <span class="text-muted small">Specify garment pieces per unit (e.g. 1 Front, 1 Back, 2 Sleeves, 2 Pockets, 1 Collar)</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="small text-muted fw-semibold">Presets:</span>
                                        <button type="button" class="btn btn-xs btn-outline-primary py-1 px-2" id="btnAddPresetJacket">🧥 Jacket</button>
                                        <button type="button" class="btn btn-xs btn-outline-warning py-1 px-2" id="btnAddPresetVest">🦺 Vest (No Sleeves)</button>
                                        <button type="button" class="btn btn-xs btn-outline-info py-1 px-2" id="btnAddPresetSweater">👕 Sweater</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary py-1 px-2" id="btnAddClearElements">Clear</button>
                                    </div>
                                </div>

                                <!-- Quick Add 1-Click Chips -->
                                <div class="p-2 bg-light border rounded-2 mb-3">
                                    <div class="small fw-bold text-dark text-uppercase mb-2" style="font-size: 0.72rem;">
                                        <i class="bi bi-plus-circle me-1 text-primary"></i> 1-Click Quick Add Common Garment Parts:
                                    </div>
                                    <div class="d-flex flex-wrap gap-1" id="addQuickChips">
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Front" data-qty="1" data-notes="Main front body">+ Front (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Back" data-qty="1" data-notes="Main back body">+ Back (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Sleeves" data-qty="2" data-notes="Left & right sleeves">+ Sleeves (2)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Pockets" data-qty="2" data-notes="Side welt / patch pockets">+ Pockets (2)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Collar / Neck" data-qty="1" data-notes="Ribbed collar / stand neck">+ Collar/Neck (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Hood" data-qty="1" data-notes="Detachable hood">+ Hood (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Cuffs" data-qty="2" data-notes="Wrist cuffs">+ Cuffs (2)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Bottom Hem" data-qty="1" data-notes="Elasticated rib / border">+ Bottom Hem (1)</button>
                                        <button type="button" class="btn btn-sm btn-white border shadow-xs py-1 px-2 quick-add-btn" data-name="Inner Lining" data-qty="1" data-notes="Inner silk/poly lining">+ Inner Lining (1)</button>
                                    </div>
                                </div>

                                <!-- Dynamic Elements Repeater Table -->
                                <div class="table-responsive border rounded-2 mb-2">
                                    <table class="table table-bordered align-middle mb-0" id="addElementsTable">
                                        <thead class="table-light small">
                                            <tr>
                                                <th style="width: 45px;" class="text-center">#</th>
                                                <th style="min-width: 200px;">Element / Component Name *</th>
                                                <th style="width: 150px;" class="text-center">Qty Per Piece *</th>
                                                <th>Pattern / Fabric Notes</th>
                                                <th style="width: 50px;" class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="addElementsTbody">
                                            <!-- Default rows loaded via JS or chips -->
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" id="btnAddElementRow">
                                        <i class="bi bi-plus-circle me-1"></i> + Add Custom Element Row
                                    </button>
                                    <div class="d-flex align-items-center gap-3 small text-muted">
                                        <span>Components: <strong id="lblAddElementsCount" class="text-dark">0</strong></span>
                                        <span class="vr"></span>
                                        <span>Total Pieces / Unit: <strong id="lblAddPiecesCount" class="text-primary fw-bold">0 pcs</strong></span>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary px-5 py-2 fw-bold shadow-sm">
                                    <i class="bi bi-check2-circle me-1"></i> Save Design with Elements
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Designs Table -->
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">Configured Designs</h6>
                        <span class="badge bg-light text-secondary border"><?= count($designs) ?> Designs</span>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Design #</th>
                                        <th>Design Name</th>
                                        <th>Parent Product</th>
                                        <th>Garment Elements / Parts (اجزاء)</th>
                                        <th>Workflow</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($designs)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">No designs created yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($designs as $d): 
                                            $dElems = $designElementsMap[$d['id']] ?? [];
                                            $elemPcsSum = array_sum(array_column($dElems, 'quantity'));
                                        ?>
                                            <tr>
                                                <td class="fw-bold text-primary">#<?= clean($d['design_number']) ?></td>
                                                <td>
                                                    <span class="fw-semibold text-dark"><?= clean($d['name']) ?></span>
                                                    <?php if ($d['specifications']): ?>
                                                        <div class="small text-muted"><?= clean($d['specifications']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-secondary"><?= clean($d['product_name']) ?></span>
                                                    <div class="small text-muted"><?= clean($d['product_code']) ?></div>
                                                </td>
                                                <td>
                                                    <?php if (empty($dElems)): ?>
                                                        <span class="text-muted small fst-italic">No elements specified</span>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-wrap gap-1 align-items-center">
                                                            <?php foreach ($dElems as $de): ?>
                                                                <span class="badge bg-light text-dark border" style="font-size: 0.73rem;" title="<?= clean($de['notes'] ?? '') ?>">
                                                                    <?= clean($de['element_name']) ?> <strong class="text-primary">×<?= (int)$de['quantity'] ?></strong>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <div class="small text-muted mt-1" style="font-size: 0.72rem;">
                                                            <?= count($dElems) ?> components (<?= $elemPcsSum ?> pcs / garment)
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small"><?= clean($d['template_name'] ?: 'Inherited') ?></td>
                                                <td>
                                                    <span class="badge-status <?= $d['is_active'] ? 'badge-completed' : 'badge-draft' ?>">
                                                        <?= $d['is_active'] ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= BASE_URL ?>modules/settings/design_edit.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-2 fw-semibold" title="Edit Design & Garment Elements">
                                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                                    </a>
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

            <!-- VARIANTS: COLORS & SIZES -->
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
                                        <input type="color" name="hex_code" class="form-control form-control-color" value="#2563eb" title="Pick color">
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
                                                        <span class="d-inline-block rounded-circle border shadow-sm" style="width: 22px; height: 22px; background-color: <?= clean($c['hex_code']) ?>;"></span>
                                                    </td>
                                                    <td class="fw-semibold text-dark"><?= clean($c['name']) ?></td>
                                                    <td class="text-muted small"><code><?= clean($c['hex_code']) ?></code></td>
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
                                            <input type="text" name="name" class="form-control" placeholder="Size (e.g. 3XL)" required>
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
    // 1. URL Hash-based Tab Switching (#designs, #variants, #categories)
    const hash = window.location.hash;
    if (hash) {
        const triggerEl = document.querySelector(`button[data-bs-target="${hash}Tab"]`);
        if (triggerEl) {
            const tab = new bootstrap.Tab(triggerEl);
            tab.show();
        }
    }

    // 2. Real-Time Dynamic Garment Elements Repeater
    const tbody = document.getElementById('addElementsTbody');
    const lblCount = document.getElementById('lblAddElementsCount');
    const lblPcs = document.getElementById('lblAddPiecesCount');
    const btnAddRow = document.getElementById('btnAddElementRow');

    function createAddRow(name = '', qty = 1, notes = '') {
        const tr = document.createElement('tr');
        tr.className = 'add-element-row';
        tr.innerHTML = `
            <td class="text-center fw-bold text-muted add-row-idx">1</td>
            <td>
                <input type="text" name="element_name[]" class="form-control form-control-sm fw-semibold" value="${escapeHtml(name)}" placeholder="e.g. Front, Sleeves, Pockets" list="garmentElementsDatalist" required>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary btn-el-minus"><i class="bi bi-dash"></i></button>
                    <input type="number" name="element_qty[]" class="form-control text-center fw-bold el-qty-val" value="${qty}" min="1" max="50" required>
                    <button type="button" class="btn btn-outline-secondary btn-el-plus"><i class="bi bi-plus"></i></button>
                </div>
            </td>
            <td>
                <input type="text" name="element_notes[]" class="form-control form-control-sm" value="${escapeHtml(notes)}" placeholder="e.g. Cut 2 panels, ribbed cuffs, welt finish">
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 btn-el-remove" title="Remove component">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
        bindAddRowEvents(tr);
        recalcAddTotals();
    }

    function bindAddRowEvents(tr) {
        const minus = tr.querySelector('.btn-el-minus');
        const plus = tr.querySelector('.btn-el-plus');
        const qty = tr.querySelector('.el-qty-val');
        const remove = tr.querySelector('.btn-el-remove');

        minus.addEventListener('click', function() {
            let v = parseInt(qty.value) || 1;
            if (v > 1) {
                qty.value = v - 1;
                recalcAddTotals();
            }
        });

        plus.addEventListener('click', function() {
            let v = parseInt(qty.value) || 1;
            qty.value = v + 1;
            recalcAddTotals();
        });

        qty.addEventListener('input', recalcAddTotals);

        remove.addEventListener('click', function() {
            tr.remove();
            recalcAddTotals();
        });
    }

    function recalcAddTotals() {
        const rows = tbody.querySelectorAll('.add-element-row');
        let totalPcs = 0;
        rows.forEach((r, i) => {
            r.querySelector('.add-row-idx').textContent = i + 1;
            totalPcs += parseInt(r.querySelector('.el-qty-val').value) || 0;
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

    if (btnAddRow) {
        btnAddRow.addEventListener('click', function() {
            createAddRow('', 1, '');
            const inputs = tbody.querySelectorAll('input[name="element_name[]"]');
            if (inputs.length) inputs[inputs.length - 1].focus();
        });
    }

    // Quick Add Chips
    document.querySelectorAll('.quick-add-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const name = this.getAttribute('data-name');
            const qty = parseInt(this.getAttribute('data-qty')) || 1;
            const notes = this.getAttribute('data-notes') || '';
            createAddRow(name, qty, notes);
        });
    });

    // Clear Elements
    const btnClear = document.getElementById('btnAddClearElements');
    if (btnClear) {
        btnClear.addEventListener('click', function() {
            tbody.innerHTML = '';
            recalcAddTotals();
        });
    }

    // Presets
    const pJacket = document.getElementById('btnAddPresetJacket');
    if (pJacket) {
        pJacket.addEventListener('click', function() {
            tbody.innerHTML = '';
            createAddRow('Front Panels', 2, 'Left & right overlap panels');
            createAddRow('Back', 1, 'Full back body');
            createAddRow('Sleeves', 2, 'Left and right sleeves');
            createAddRow('Pockets', 2, 'Front side welt pockets');
            createAddRow('Collar', 1, 'Stand / lapel collar');
            createAddRow('Inner Lining', 1, 'Silk polyester lining');
        });
    }

    const pVest = document.getElementById('btnAddPresetVest');
    if (pVest) {
        pVest.addEventListener('click', function() {
            tbody.innerHTML = '';
            createAddRow('Front Panels', 2, 'Left & right vest panels');
            createAddRow('Back', 1, 'Back panel with adjuster');
            createAddRow('Pockets', 2, 'Waist welt pockets');
            createAddRow('V-Neck Collar', 1, 'V-neck border');
            createAddRow('Inner Lining', 1, 'Breathable lining');
        });
    }

    const pSweater = document.getElementById('btnAddPresetSweater');
    if (pSweater) {
        pSweater.addEventListener('click', function() {
            tbody.innerHTML = '';
            createAddRow('Front', 1, 'Main knit front pattern');
            createAddRow('Back', 1, 'Main knit back');
            createAddRow('Sleeves', 2, 'Left and right knit sleeves');
            createAddRow('Neck Rib', 1, 'Neck trim collar');
            createAddRow('Bottom Hem Rib', 1, 'Elastic waist ribbing');
        });
    }

    // Load default jacket preset so new design starts with ready rows
    if (tbody && tbody.children.length === 0) {
        createAddRow('Front', 1, 'Main front body');
        createAddRow('Back', 1, 'Main back body');
        createAddRow('Sleeves', 2, 'Left & right sleeves');
        createAddRow('Pockets', 2, 'Side pockets');
        createAddRow('Collar / Neck', 1, 'Collar / Neck trim');
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
