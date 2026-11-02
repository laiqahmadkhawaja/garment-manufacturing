<?php
/**
 * Garment Manufacturing ERP - Lots AJAX Endpoints
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = get_db();

if ($action === 'get_designs') {
    $productId = (int)($_GET['product_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, design_number, name FROM designs WHERE product_id = ? AND is_active = 1 ORDER BY design_number ASC");
    $stmt->execute([$productId]);
    $designs = $stmt->fetchAll();
    json_response(['status' => 'success', 'designs' => $designs]);
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
    $vStmt = $db->prepare("SELECT lv.*, c.name AS color_name, s.name AS size_name, s.code AS size_code 
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

json_response(['status' => 'error', 'message' => 'Unknown action'], 400);
