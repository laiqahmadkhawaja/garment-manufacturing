<?php
/**
 * Garment Manufacturing ERP - Production AJAX Endpoints
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = get_db();

if ($action === 'get_lot_operations_and_variants') {
    $lotId = (int)($_GET['lot_id'] ?? 0);

    // Lot info with assigned operators
    $stmt = $db->prepare("SELECT l.*, p.name AS product_name, d.design_number, d.name AS design_name,
                                 w_day.name AS day_operator_name, w_day.code AS day_operator_code, w_day.rate_per_minute AS day_operator_min_rate,
                                 w_night.name AS night_operator_name, w_night.code AS night_operator_code, w_night.rate_per_minute AS night_operator_min_rate,
                                 sz.name AS single_size_name, sz.code AS single_size_code
                          FROM lots l 
                          JOIN products p ON l.product_id = p.id 
                          LEFT JOIN designs d ON l.design_id = d.id 
                          LEFT JOIN workers w_day ON l.operator_id = w_day.id
                          LEFT JOIN workers w_night ON l.night_operator_id = w_night.id
                          LEFT JOIN sizes sz ON l.single_size_id = sz.id
                          WHERE l.id = ?");
    $stmt->execute([$lotId]);
    $lot = $stmt->fetch();

    if (!$lot) {
        json_response(['status' => 'error', 'message' => 'Lot not found'], 404);
    }

    // Applicable operations and their sub-operations for this lot
    $opStmt = $db->prepare("SELECT o.id AS operation_id, 
                            COALESCE(lws.sequence, (COALESCE(p_op.sequence, 10) + (o.sequence / 100))) AS effective_seq,
                            o.name AS op_name, o.code AS op_code, o.default_rate, o.estimated_minutes,
                            o.parent_id, p_op.name AS parent_op_name, dep.name AS dept_name,
                            (SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = o.id) AS good_done
                            FROM operations o
                            LEFT JOIN lot_workflow_steps lws ON lws.operation_id = o.id AND lws.lot_id = ?
                            LEFT JOIN operations p_op ON o.parent_id = p_op.id
                            LEFT JOIN departments dep ON COALESCE(o.department_id, p_op.department_id) = dep.id
                            WHERE (lws.lot_id = ? OR o.parent_id IN (SELECT operation_id FROM lot_workflow_steps WHERE lot_id = ?))
                              AND o.is_active = 1
                            ORDER BY effective_seq ASC, o.id ASC");
    $opStmt->execute([$lotId, $lotId, $lotId, $lotId]);
    $operations = $opStmt->fetchAll();

    // Lot variants
    $vStmt = $db->prepare("SELECT lv.*, c.name AS color_name, c.hex_code, s.name AS size_name, s.code AS size_code 
                           FROM lot_variants lv 
                           JOIN colors c ON lv.color_id = c.id 
                           JOIN sizes s ON lv.size_id = s.id 
                           WHERE lv.lot_id = ? 
                           ORDER BY c.name ASC, s.sort_order ASC");
    $vStmt->execute([$lotId]);
    $variants = $vStmt->fetchAll();

    // Distinct sizes for this lot
    $sStmt = $db->prepare("SELECT DISTINCT s.id, s.name, s.code, s.sort_order,
                                  (SELECT SUM(quantity) FROM lot_variants WHERE lot_id = ? AND size_id = s.id) AS planned_qty
                           FROM lot_variants lv
                           JOIN sizes s ON lv.size_id = s.id
                           WHERE lv.lot_id = ?
                           ORDER BY s.sort_order ASC, s.id ASC");
    $sStmt->execute([$lotId, $lotId]);
    $lotSizes = $sStmt->fetchAll();

    json_response([
        'status' => 'success',
        'lot' => $lot,
        'operations' => $operations,
        'variants' => $variants,
        'sizes' => $lotSizes
    ]);
    ]);
}

if ($action === 'get_variant_stage_balance') {
    $lotId = (int)($_GET['lot_id'] ?? 0);
    $opId = (int)($_GET['operation_id'] ?? 0);
    $colorId = (int)($_GET['color_id'] ?? 0);
    $sizeId = (int)($_GET['size_id'] ?? 0);

    // Get planned qty for this variant
    $vStmt = $db->prepare("SELECT quantity FROM lot_variants WHERE lot_id = ? AND color_id = ? AND size_id = ?");
    $vStmt->execute([$lotId, $colorId, $sizeId]);
    $planned = (int)$vStmt->fetchColumn();

    // Completed good qty for this variant & operation
    $doneStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) AS good_done, 
                                     COALESCE(SUM(rejected_qty), 0) AS rejected_done, 
                                     COALESCE(SUM(rework_qty), 0) AS rework_done 
                              FROM production_entries 
                              WHERE lot_id = ? AND operation_id = ? AND color_id = ? AND size_id = ?");
    $doneStmt->execute([$lotId, $opId, $colorId, $sizeId]);
    $counts = $doneStmt->fetch();

    $goodDone = (int)$counts['good_done'];
    $rejectedDone = (int)$counts['rejected_done'];
    $reworkDone = (int)$counts['rework_done'];
    $pending = max(0, $planned - $goodDone);

    // Get default rate for operation
    $rStmt = $db->prepare("SELECT default_rate FROM operations WHERE id = ?");
    $rStmt->execute([$opId]);
    $defaultRate = (float)$rStmt->fetchColumn();

    json_response([
        'status' => 'success',
        'planned' => $planned,
        'good_done' => $goodDone,
        'rejected_done' => $rejectedDone,
        'rework_done' => $reworkDone,
        'pending' => $pending,
        'default_rate' => $defaultRate
    ]);
}

if ($action === 'get_lot_stage_matrix') {
    $lotId = (int)($_GET['lot_id'] ?? 0);
    $opId = (int)($_GET['operation_id'] ?? 0);

    // Fetch lot
    $lotStmt = $db->prepare("SELECT id, lot_number, total_pieces FROM lots WHERE id = ?");
    $lotStmt->execute([$lotId]);
    $lot = $lotStmt->fetch();
    if (!$lot) {
        json_response(['status' => 'error', 'message' => 'Lot not found'], 404);
    }

    // Fetch all variants with completed good & rejected qty for this operation
    $sql = "SELECT lv.color_id, lv.size_id, lv.quantity AS planned,
                   c.name AS color_name, c.hex_code,
                   s.name AS size_name, s.code AS size_code, s.sort_order,
                   COALESCE((
                       SELECT SUM(good_qty) FROM production_entries 
                       WHERE lot_id = lv.lot_id AND operation_id = ? AND color_id = lv.color_id AND size_id = lv.size_id
                   ), 0) AS good_done,
                   COALESCE((
                       SELECT SUM(rejected_qty) FROM production_entries 
                       WHERE lot_id = lv.lot_id AND operation_id = ? AND color_id = lv.color_id AND size_id = lv.size_id
                   ), 0) AS rejected_done
            FROM lot_variants lv
            JOIN colors c ON lv.color_id = c.id
            JOIN sizes s ON lv.size_id = s.id
            WHERE lv.lot_id = ?
            ORDER BY c.name ASC, s.sort_order ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$opId, $opId, $lotId]);
    $rawVariants = $stmt->fetchAll();

    $colors = [];
    $sizes = [];
    $grid = [];
    $totalPlanned = 0;
    $totalDone = 0;
    $totalPending = 0;

    foreach ($rawVariants as $v) {
        $cId = (int)$v['color_id'];
        $sId = (int)$v['size_id'];
        $planned = (int)$v['planned'];
        $goodDone = (int)$v['good_done'];
        $pending = max(0, $planned - $goodDone);

        $colors[$cId] = [
            'id' => $cId,
            'name' => $v['color_name'],
            'hex_code' => $v['hex_code']
        ];

        $sizes[$sId] = [
            'id' => $sId,
            'name' => $v['size_name'],
            'code' => $v['size_code'],
            'sort_order' => (int)$v['sort_order']
        ];

        $grid[$cId][$sId] = [
            'planned' => $planned,
            'good_done' => $goodDone,
            'rejected_done' => (int)$v['rejected_done'],
            'pending' => $pending
        ];

        $totalPlanned += $planned;
        $totalDone += $goodDone;
        $totalPending += $pending;
    }

    json_response([
        'status' => 'success',
        'lot' => $lot,
        'colors' => array_values($colors),
        'sizes' => array_values($sizes),
        'grid' => $grid,
        'totals' => [
            'planned' => $totalPlanned,
            'good_done' => $totalDone,
            'pending' => $totalPending
        ]
    ]);
}

if ($action === 'get_operation_workers') {
    $opId = (int)($_GET['operation_id'] ?? 0);

    // Fetch operation details
    $opStmt = $db->prepare("SELECT id, name, parent_id, department_id, default_rate, estimated_minutes FROM operations WHERE id = ?");
    $opStmt->execute([$opId]);
    $operation = $opStmt->fetch();

    if (!$operation) {
        json_response(['status' => 'error', 'message' => 'Operation not found'], 404);
    }

    $parentDeptId = null;
    if ($operation['parent_id']) {
        $pStmt = $db->prepare("SELECT department_id FROM operations WHERE id = ?");
        $pStmt->execute([$operation['parent_id']]);
        $parentDeptId = $pStmt->fetchColumn();
    }
    $effectiveDeptId = $operation['department_id'] ?: $parentDeptId;
    $estMins = (float)($operation['estimated_minutes'] ?? 0.00);

    // Workers mapped via worker_operations OR via department
    $stmt = $db->prepare("SELECT w.id, w.name, w.code, w.worker_type, w.rate_per_minute, dep.name AS department_name,
                          COALESCE(
                              (SELECT op_r.rate FROM operation_rates op_r WHERE op_r.operation_id = ? AND op_r.worker_id = w.id ORDER BY op_r.id DESC LIMIT 1),
                              CASE 
                                  WHEN ? > 0 AND w.rate_per_minute > 0 THEN ROUND(? * w.rate_per_minute, 2)
                                  ELSE ?
                              END
                          ) AS effective_rate
                          FROM workers w
                          LEFT JOIN worker_operations wo ON wo.worker_id = w.id AND (wo.operation_id = ? OR wo.operation_id = ?)
                          LEFT JOIN departments dep ON w.department_id = dep.id
                          WHERE w.is_active = 1 
                            AND (wo.worker_id IS NOT NULL OR w.department_id = ? OR w.department_id = ?)
                          ORDER BY (wo.worker_id IS NOT NULL) DESC, w.name ASC");
    $stmt->execute([
        $opId,
        $estMins, $estMins, $operation['default_rate'],
        $opId, $operation['parent_id'] ?? 0,
        $operation['department_id'], $effectiveDeptId
    ]);
    $relatedWorkers = $stmt->fetchAll();

    // Fallback: If no workers match the operation or department, return all active workers
    if (empty($relatedWorkers)) {
        $allStmt = $db->prepare("SELECT w.id, w.name, w.code, w.worker_type, w.rate_per_minute, dep.name AS department_name,
                                 CASE 
                                     WHEN ? > 0 AND w.rate_per_minute > 0 THEN ROUND(? * w.rate_per_minute, 2)
                                     ELSE ?
                                 END AS effective_rate
                                 FROM workers w
                                 LEFT JOIN departments dep ON w.department_id = dep.id
                                 WHERE w.is_active = 1
                                 ORDER BY w.name ASC");
        $allStmt->execute([$estMins, $estMins, $operation['default_rate']]);
        $relatedWorkers = $allStmt->fetchAll();
    }

    json_response([
        'status' => 'success',
        'operation' => $operation,
        'workers' => $relatedWorkers
    ]);
}

json_response(['status' => 'error', 'message' => 'Unknown action'], 400);
