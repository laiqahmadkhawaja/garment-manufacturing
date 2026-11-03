<?php
/**
 * Garment Manufacturing ERP - Lots AJAX Endpoints
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = get_db();

if ($action === 'get_product_details') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $stmt = $db->prepare("SELECT p.*, c.name AS category_name, wt.name AS template_name 
                          FROM products p 
                          LEFT JOIN product_categories c ON p.category_id = c.id 
                          LEFT JOIN workflow_templates wt ON p.default_workflow_template_id = wt.id 
                          WHERE p.id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        json_response(['status' => 'error', 'message' => 'Product not found'], 404);
    }

    // Get product pattern elements
    $eStmt = $db->prepare("SELECT * FROM product_elements WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $eStmt->execute([$productId]);
    $product['elements'] = $eStmt->fetchAll();

    // Get product BOM recipe
    $bStmt = $db->prepare("SELECT b.* FROM bom_headers b WHERE b.product_id = ? AND b.is_active = 1 ORDER BY b.id DESC LIMIT 1");
    $bStmt->execute([$productId]);
    $recipe = $bStmt->fetch();

    $recipeItems = [];
    if ($recipe) {
        $biStmt = $db->prepare("SELECT bi.*, m.name AS material_name, m.code AS material_code, u.code AS unit_of_measure, u.code AS unit_code, m.cost_price 
                                FROM bom_items bi 
                                JOIN materials m ON bi.material_id = m.id 
                                JOIN units u ON m.unit_id = u.id 
                                WHERE bi.bom_id = ?");
        $biStmt->execute([$recipe['id']]);
        $recipeItems = $biStmt->fetchAll();
    }
    $product['recipe'] = $recipe;
    $product['recipe_items'] = $recipeItems;

    json_response(['status' => 'success', 'product' => $product]);
}

if ($action === 'get_designs') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, design_number, name, specifications FROM designs WHERE product_id = ? AND is_active = 1 ORDER BY design_number ASC");
    $stmt->execute([$productId]);
    $designs = $stmt->fetchAll();

    if (!empty($designs)) {
        $designIds = array_column($designs, 'id');
        $placeholders = implode(',', array_fill(0, count($designIds), '?'));
        $eStmt = $db->prepare("SELECT * FROM design_elements WHERE design_id IN ($placeholders) ORDER BY sort_order ASC, id ASC");
        $eStmt->execute($designIds);
        $elements = $eStmt->fetchAll();

        $elementsMap = [];
        foreach ($elements as $el) {
            $elementsMap[$el['design_id']][] = $el;
        }

        foreach ($designs as &$d) {
            $d['elements'] = $elementsMap[$d['id']] ?? [];
        }
    }

    json_response(['status' => 'success', 'designs' => $designs]);
}

if ($action === 'get_design_details') {
    $designId = (int)($_GET['design_id'] ?? 0);
    $stmt = $db->prepare("SELECT d.*, p.name AS product_name FROM designs d JOIN products p ON d.product_id = p.id WHERE d.id = ?");
    $stmt->execute([$designId]);
    $design = $stmt->fetch();

    if (!$design) {
        json_response(['status' => 'error', 'message' => 'Design not found'], 404);
    }

    $eStmt = $db->prepare("SELECT * FROM design_elements WHERE design_id = ? ORDER BY sort_order ASC, id ASC");
    $eStmt->execute([$designId]);
    $design['elements'] = $eStmt->fetchAll();

    json_response(['status' => 'success', 'design' => $design]);
}

if ($action === 'get_lot_details') {
    $lotId = (int)($_GET['lot_id'] ?? 0);
    $stmt = $db->prepare("SELECT l.*, p.name AS product_name, d.design_number, d.name AS design_name 
                          FROM lots l 
                          JOIN products p ON l.product_id = p.id 
                          LEFT JOIN designs d ON l.design_id = d.id 
                          WHERE l.id = ?");
    $stmt->execute([$lotId]);
    $lot = $stmt->fetch();

    if (!$lot) {
        json_response(['status' => 'error', 'message' => 'Lot not found'], 404);
    }

    // Get variants
    $vStmt = $db->prepare("SELECT lv.*, c.name AS color_name, COALESCE(lv.color_code, c.color_code) AS color_code, s.name AS size_name, s.code AS size_code 
                           FROM lot_variants lv 
                           JOIN colors c ON lv.color_id = c.id 
                           JOIN sizes s ON lv.size_id = s.id 
                           WHERE lv.lot_id = ?");
    $vStmt->execute([$lotId]);
    $variants = $vStmt->fetchAll();

    // Get workflow steps
    $wStmt = $db->prepare("SELECT lws.*, o.name AS op_name, o.code AS op_code, o.default_rate 
                           FROM lot_workflow_steps lws 
                           JOIN operations o ON lws.operation_id = o.id 
                           WHERE lws.lot_id = ? 
                           ORDER BY lws.sequence ASC");
    $wStmt->execute([$lotId]);
    $steps = $wStmt->fetchAll();

    json_response([
        'status' => 'success',
        'lot' => $lot,
        'variants' => $variants,
        'steps' => $steps
    ]);
}

if ($action === 'check_lot_number') {
    $lotNumber = trim($_GET['lot_number'] ?? '');
    $excludeId = (int)($_GET['exclude_id'] ?? 0);
    
    if (empty($lotNumber)) {
        json_response(['status' => 'error', 'message' => 'Lot number required'], 400);
    }
    
    $stmt = $db->prepare("SELECT id FROM lots WHERE lot_number = ? AND id != ?");
    $stmt->execute([$lotNumber, $excludeId]);
    $exists = (bool)$stmt->fetch();
    
    json_response([
        'status' => 'success',
        'lot_number' => $lotNumber,
        'is_unique' => !$exists,
        'message' => $exists ? "Lot # '$lotNumber' is already in use!" : "Lot # '$lotNumber' is unique & available."
    ]);
}

if ($action === 'quick_add_color') {
    $code = trim($_POST['color_code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $hex = trim($_POST['hex_code'] ?? '#4f46e5');
    $yarnStock = (float)($_POST['yarn_stock_kg'] ?? 0.00);

    if (empty($code)) {
        json_response(['status' => 'error', 'message' => 'Color code is required.'], 400);
    }
    if (empty($name)) {
        $name = "Shade " . $code;
    }

    // Check if code or name already exists
    $chk = $db->prepare("SELECT * FROM colors WHERE color_code = ? OR name = ? LIMIT 1");
    $chk->execute([$code, $name]);
    $existing = $chk->fetch();

    if ($existing) {
        json_response(['status' => 'success', 'color' => $existing, 'is_new' => false]);
    }

    $stmt = $db->prepare("INSERT INTO colors (color_code, name, hex_code, yarn_stock_kg) VALUES (?, ?, ?, ?)");
    $stmt->execute([$code, $name, $hex, $yarnStock]);
    $newId = (int)$db->lastInsertId();

    $newColor = [
        'id' => $newId,
        'color_code' => $code,
        'name' => $name,
        'hex_code' => $hex,
        'yarn_stock_kg' => $yarnStock,
        'usage_count' => 0
    ];

    json_response(['status' => 'success', 'color' => $newColor, 'is_new' => true]);
}

json_response(['status' => 'error', 'message' => 'Unknown action'], 400);
