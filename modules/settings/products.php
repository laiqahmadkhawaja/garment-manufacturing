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

        if (empty($designNumber) || empty($name) || $productId <= 0) {
            set_flash('danger', 'Design number, name, and product are required.');
        } else {
            $stmt = $db->prepare("INSERT INTO designs (design_number, name, product_id, specifications, workflow_template_id, is_active) 
                                  VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$designNumber, $name, $productId, $specs, $templateId]);
            log_audit('CREATE', 'designs', (int)$db->lastInsertId(), null, ['design_number' => $designNumber, 'name' => $name]);
            redirect('modules/settings/products.php#designs', "Design #$designNumber added successfully!");
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

$categories = $db->query("SELECT * FROM product_categories ORDER BY name ASC")->fetchAll();
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
                                    <label class="form-label">Category</label>
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

            <!-- DESIGNS TAB -->
            <div class="tab-pane fade" id="designsTab">
                <!-- Inline Expandable Form for Creating Design -->
                <div class="card-modern mb-4">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-plus-circle me-1 text-primary"></i> Add New Design</h6>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_design">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            
                            <div class="row g-3">
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Design Number *</label>
                                    <input type="text" name="design_number" class="form-control" placeholder="e.g. 904" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Parent Product *</label>
                                    <select name="product_id" class="form-select" required>
                                        <option value="">-- Select Product --</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= clean($p['name']) ?> (<?= clean($p['code']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Design Name *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Classic Slim Fit 904" required>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Workflow Override</label>
                                    <select name="workflow_template_id" class="form-select">
                                        <option value="">-- Inherit from Product --</option>
                                        <?php foreach ($templates as $tmpl): ?>
                                            <option value="<?= $tmpl['id'] ?>"><?= clean($tmpl['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-9">
                                    <label class="form-label">Design Specifications</label>
                                    <input type="text" name="specifications" class="form-control" placeholder="Cuff style, pocket cut, stitch density">
                                </div>
                                <div class="col-12 col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                                        Save Design
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Designs Table -->
                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Configured Designs</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Design #</th>
                                        <th>Design Name</th>
                                        <th>Product</th>
                                        <th>Specifications</th>
                                        <th>Workflow</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($designs as $d): ?>
                                        <tr>
                                            <td class="fw-bold text-primary">#<?= clean($d['design_number']) ?></td>
                                            <td class="fw-semibold text-dark"><?= clean($d['name']) ?></td>
                                            <td><?= clean($d['product_name']) ?></td>
                                            <td class="small text-muted"><?= clean($d['specifications'] ?: '-') ?></td>
                                            <td class="small"><?= clean($d['template_name'] ?: 'Inherited') ?></td>
                                            <td>
                                                <span class="badge-status <?= $d['is_active'] ? 'badge-completed' : 'badge-draft' ?>">
                                                    Active
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
