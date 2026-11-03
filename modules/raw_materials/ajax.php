<?php
/**
 * Garment Manufacturing ERP - Raw Materials AJAX Endpoints
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = get_db();

if ($action === 'get_lot_issued_materials') {
    $lotId = (int)($_GET['lot_id'] ?? 0);

    if ($lotId <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid lot ID'], 400);
    }

    // Query all materials issued to this lot, minus any previously returned
    $sql = "SELECT m.id, m.name, m.code, m.cost_price, u.code AS unit_code,
                   COALESCE((
                       SELECT SUM(mii.quantity)
                       FROM material_issues mi
                       JOIN material_issue_items mii ON mi.id = mii.issue_id
                       WHERE mi.lot_id = ? AND mii.material_id = m.id
                   ), 0) AS total_issued,
                   COALESCE((
                       SELECT SUM(mri.quantity)
                       FROM material_returns mr
                       JOIN material_return_items mri ON mr.id = mri.return_id
                       WHERE mr.lot_id = ? AND mri.material_id = m.id
                   ), 0) AS total_returned
            FROM materials m
            JOIN units u ON m.unit_id = u.id
            WHERE m.id IN (
                SELECT DISTINCT mii.material_id
                FROM material_issues mi
                JOIN material_issue_items mii ON mi.id = mii.issue_id
                WHERE mi.lot_id = ?
            )
            ORDER BY m.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute([$lotId, $lotId, $lotId]);
    $rawMaterials = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $materials = [];
    foreach ($rawMaterials as $mat) {
        $issued = (float)$mat['total_issued'];
        $returned = (float)$mat['total_returned'];
        $available = max(0.00, round($issued - $returned, 2));

        $materials[] = [
            'id' => (int)$mat['id'],
            'name' => $mat['name'],
            'code' => $mat['code'],
            'unit_code' => $mat['unit_code'],
            'cost_price' => (float)$mat['cost_price'],
            'total_issued' => $issued,
            'total_returned' => $returned,
            'available_return' => $available
        ];
    }

    json_response([
        'status' => 'success',
        'lot_id' => $lotId,
        'materials' => $materials
    ]);
}
