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
                            COALESCE(lws.sequence, o.sequence) AS sequence,
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

    // Garment elements with operator & cutting progress for this lot
    $assignedWorkerId = (int)($_GET['worker_id'] ?? $lot['operator_id'] ?? 0);
    $elemStmt = $db->prepare("SELECT pe.id, pe.element_name, pe.quantity, pe.multiplier, COALESCE(pe.default_sam_minutes, 0.00) AS default_sam_minutes,
                                     COALESCE(MAX(loe.required_pieces), (pe.quantity * l.total_pieces)) AS required_pieces,
                                     COALESCE(SUM(loe.operator1_pieces), 0) AS operator1_pieces,
                                     COALESCE(SUM(loe.operator2_pieces), 0) AS operator2_pieces,
                                     COALESCE(SUM(loe.total_pieces), 0) AS operator_total,
                                     COALESCE(SUM(loe.cut_pieces), 0) AS cut_pieces,
                                     wer.custom_sam_minutes AS worker_custom_sam,
                                     wer.custom_piece_rate AS worker_custom_rate
                              FROM product_elements pe
                              JOIN lots l ON pe.product_id = l.product_id
                              LEFT JOIN lot_operator_element_entries loe ON loe.element_id = pe.id AND loe.lot_id = l.id
                              LEFT JOIN worker_element_rates wer ON wer.element_id = pe.id AND wer.worker_id = ?
                              WHERE l.id = ?
                              GROUP BY pe.id, pe.element_name, pe.quantity, pe.multiplier, pe.default_sam_minutes, pe.sort_order, l.total_pieces, wer.custom_sam_minutes, wer.custom_piece_rate
                              ORDER BY pe.sort_order ASC, pe.id ASC");
    $elemStmt->execute([$assignedWorkerId, $lotId]);
    $lotElements = $elemStmt->fetchAll();

    // Calculate maximum complete whole units ready from operator for cutting
    $maxWholeReadyToCut = null;
    foreach ($lotElements as $el) {
        $m = (int)($el['quantity'] ?: $el['multiplier'] ?: 1);
        if ($m <= 0) $m = 1;
        $avail = max(0, (int)$el['operator_total'] - (int)$el['cut_pieces']);
        $w = (int)floor($avail / $m);
        if ($maxWholeReadyToCut === null || $w < $maxWholeReadyToCut) {
            $maxWholeReadyToCut = $w;
        }
    }
    if ($maxWholeReadyToCut === null) {
        $opGoodStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries pe JOIN operations o ON pe.operation_id = o.id WHERE pe.lot_id = ? AND (o.name LIKE '%Operator%' OR o.name LIKE '%Knitt%')");
        $opGoodStmt->execute([$lotId]);
        $maxWholeReadyToCut = (int)$opGoodStmt->fetchColumn();
    }

    json_response([
        'status' => 'success',
        'lot' => $lot,
        'operations' => $operations,
        'variants' => $variants,
        'sizes' => $lotSizes,
        'elements' => $lotElements,
        'max_whole_ready_to_cut' => $maxWholeReadyToCut
    ]);
}

if ($action === 'get_worker_element_rates') {
    $workerId = (int)($_GET['worker_id'] ?? 0);
    $wStmt = $db->prepare("SELECT id, name, code, rate_per_minute FROM workers WHERE id = ?");
    $wStmt->execute([$workerId]);
    $worker = $wStmt->fetch();

    if (!$worker) {
        json_response(['status' => 'error', 'message' => 'Worker not found'], 404);
    }

    $rStmt = $db->prepare("SELECT element_id, custom_sam_minutes, custom_piece_rate FROM worker_element_rates WHERE worker_id = ?");
    $rStmt->execute([$workerId]);
    $rates = $rStmt->fetchAll();

    json_response([
        'status' => 'success',
        'worker' => $worker,
        'rates' => $rates
    ]);
}

if ($action === 'get_variant_stage_balance') {
    $lotId = (int)($_GET['lot_id'] ?? 0);
    $opId = (int)($_GET['operation_id'] ?? 0);
    $colorId = (int)($_GET['color_id'] ?? 0);
    $sizeId = (int)($_GET['size_id'] ?? 0);

    $allowanceData = get_lot_variant_stage_allowance($lotId, $opId);
    $vKey = $colorId . '_' . $sizeId;
    $vInfo = $allowanceData['variants'][$vKey] ?? null;

    $planned = $vInfo ? $vInfo['planned'] : 0;
    $allowable = $vInfo ? $vInfo['allowable'] : $planned;
    $goodDone = $vInfo ? $vInfo['current_done'] : 0;
    $rejectedDone = $vInfo ? $vInfo['current_rejected'] : 0;
    $reworkDone = $vInfo ? ($vInfo['current_rework'] ?? 0) : 0;
    $pending = max(0, $allowable - $goodDone);

    // Get default rate for operation
    $rStmt = $db->prepare("SELECT default_rate FROM operations WHERE id = ?");
    $rStmt->execute([$opId]);
    $defaultRate = (float)$rStmt->fetchColumn();

    json_response([
        'status' => 'success',
        'planned' => $planned,
        'allowable' => $allowable,
        'has_prev_color_stage' => $allowanceData['has_prev_color_stage'],
        'prev_operation_name' => $allowanceData['prev_operation_name'],
        'prev_rejected' => $vInfo['prev_stage_rejected'] ?? 0,
        'prev_rework' => $vInfo['prev_stage_rework'] ?? 0,
        'prev_unproduced' => $vInfo['prev_stage_unproduced'] ?? 0,
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

    // Get strict stage-to-stage workflow allowances
    $allowanceData = get_lot_variant_stage_allowance($lotId, $opId);
    $variantAllowances = $allowanceData['variants'];

    // Fetch all variants with completed good, rejected & rework qty for this operation
    $sql = "SELECT lv.color_id, lv.size_id, lv.quantity AS planned,
                   c.name AS color_name, COALESCE(c.color_code, c.name) AS color_code, c.hex_code,
                   s.name AS size_name, s.code AS size_code, s.sort_order,
                   COALESCE((
                       SELECT SUM(good_qty) FROM production_entries 
                       WHERE lot_id = lv.lot_id AND operation_id = ? AND color_id = lv.color_id AND size_id = lv.size_id
                   ), 0) AS good_done,
                   COALESCE((
                       SELECT SUM(rejected_qty) FROM production_entries 
                       WHERE lot_id = lv.lot_id AND operation_id = ? AND color_id = lv.color_id AND size_id = lv.size_id
                   ), 0) AS rejected_done,
                   COALESCE((
                       SELECT SUM(rework_qty) FROM production_entries 
                       WHERE lot_id = lv.lot_id AND operation_id = ? AND color_id = lv.color_id AND size_id = lv.size_id
                   ), 0) AS rework_done
            FROM lot_variants lv
            JOIN colors c ON lv.color_id = c.id
            JOIN sizes s ON lv.size_id = s.id
            WHERE lv.lot_id = ?
            ORDER BY c.name ASC, s.sort_order ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$opId, $opId, $opId, $lotId]);
    $rawVariants = $stmt->fetchAll();

    $colors = [];
    $sizes = [];
    $grid = [];
    $totalPlanned = 0;
    $totalAllowable = 0;
    $totalDone = 0;
    $totalPending = 0;
    $totalPrevRejected = 0;
    $totalPrevRework = 0;
    $totalPrevUnproduced = 0;
    $totalCurRejected = 0;
    $totalCurRework = 0;

    foreach ($rawVariants as $v) {
        $cId = (int)$v['color_id'];
        $sId = (int)$v['size_id'];
        $k = $cId . '_' . $sId;
        $vInfo = $variantAllowances[$k] ?? null;

        $planned = (int)$v['planned'];
        $allowable = $vInfo ? $vInfo['allowable'] : $planned;
        $goodDone = (int)$v['good_done'];
        $rejDone = (int)$v['rejected_done'];
        $rewDone = (int)$v['rework_done'];
        $pending = max(0, $allowable - $goodDone);
        $prevRejected = $vInfo ? (int)$vInfo['prev_stage_rejected'] : 0;
        $prevRework = $vInfo ? (int)($vInfo['prev_stage_rework'] ?? 0) : 0;
        $prevUnproduced = $vInfo ? (int)($vInfo['prev_stage_unproduced'] ?? 0) : 0;

        $totalPrevRejected += $prevRejected;
        $totalPrevRework += $prevRework;
        $totalPrevUnproduced += $prevUnproduced;
        $totalCurRejected += $rejDone;
        $totalCurRework += $rewDone;

        $colors[$cId] = [
            'id' => $cId,
            'name' => $v['color_name'],
            'color_code' => $v['color_code'],
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
            'allowable' => $allowable,
            'has_prev_stage' => $allowanceData['has_prev_color_stage'],
            'prev_stage_name' => $allowanceData['prev_operation_name'],
            'prev_rejected' => $prevRejected,
            'prev_rework' => $prevRework,
            'prev_unproduced' => $prevUnproduced,
            'good_done' => $goodDone,
            'rejected_done' => $rejDone,
            'rework_done' => $rewDone,
            'pending' => $pending
        ];

        $totalPlanned += $planned;
        $totalAllowable += $allowable;
        $totalDone += $goodDone;
        $totalPending += $pending;
    }

    // Maximum whole sweaters ready from operator
    $maxWholeStmt = $db->prepare("SELECT pe.quantity, pe.multiplier,
                                         COALESCE(SUM(loe.total_pieces), 0) AS operator_total,
                                         COALESCE(SUM(loe.cut_pieces), 0) AS cut_pieces
                                  FROM product_elements pe
                                  JOIN lots l ON pe.product_id = l.product_id
                                  LEFT JOIN lot_operator_element_entries loe ON loe.element_id = pe.id AND loe.lot_id = l.id
                                  WHERE l.id = ?
                                  GROUP BY pe.id, pe.quantity, pe.multiplier");
    $maxWholeStmt->execute([$lotId]);
    $mElements = $maxWholeStmt->fetchAll();
    $maxWholeReadyToCut = null;
    foreach ($mElements as $mel) {
        $m = (int)($mel['quantity'] ?: $mel['multiplier'] ?: 1);
        if ($m <= 0) $m = 1;
        $avail = max(0, (int)$mel['operator_total'] - (int)$mel['cut_pieces']);
        $w = (int)floor($avail / $m);
        if ($maxWholeReadyToCut === null || $w < $maxWholeReadyToCut) {
            $maxWholeReadyToCut = $w;
        }
    }
    if ($maxWholeReadyToCut === null) {
        $maxWholeReadyToCut = $allowanceData['prev_stage_total_good'] ?? 0;
    }

    json_response([
        'status' => 'success',
        'lot' => $lot,
        'has_prev_color_stage' => $allowanceData['has_prev_color_stage'],
        'prev_operation_name' => $allowanceData['prev_operation_name'],
        'prev_stage_total_good' => $allowanceData['prev_stage_total_good'] ?? null,
        'max_whole_ready_to_cut' => $maxWholeReadyToCut,
        'total_prev_rejected' => $totalPrevRejected,
        'total_prev_rework' => $totalPrevRework,
        'total_prev_unproduced' => $totalPrevUnproduced,
        'colors' => array_values($colors),
        'sizes' => array_values($sizes),
        'grid' => $grid,
        'totals' => [
            'planned' => $totalPlanned,
            'allowable' => $totalAllowable,
            'good_done' => $totalDone,
            'rejected_done' => $totalCurRejected,
            'rework_done' => $totalCurRework,
            'pending' => $totalPending,
            'prev_rejected' => $totalPrevRejected,
            'prev_rework' => $totalPrevRework,
            'prev_unproduced' => $totalPrevUnproduced
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
    $stmt = $db->prepare("SELECT w.id, w.name, w.code, w.worker_type, w.rate_basis, w.default_rate, w.default_rate AS piece_rate, w.rate_per_minute, dep.name AS department_name,
                          COALESCE(
                              (SELECT op_r.rate FROM operation_rates op_r WHERE op_r.operation_id = ? AND op_r.worker_id = w.id ORDER BY op_r.id DESC LIMIT 1),
                              NULLIF(w.default_rate, 0.00),
                              NULLIF(?, 0.00),
                              CASE 
                                  WHEN ? > 0 AND w.rate_per_minute > 0 THEN ROUND(? * w.rate_per_minute, 2)
                                  ELSE 0.00
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
        $operation['default_rate'],
        $estMins, $estMins,
        $opId, $operation['parent_id'] ?? 0,
        $operation['department_id'], $effectiveDeptId
    ]);
    $relatedWorkers = $stmt->fetchAll();

    // Fallback: If no workers match the operation or department, return all active workers
    if (empty($relatedWorkers)) {
        $allStmt = $db->prepare("SELECT w.id, w.name, w.code, w.worker_type, w.rate_basis, w.default_rate, w.default_rate AS piece_rate, w.rate_per_minute, dep.name AS department_name,
                                 COALESCE(
                                     (SELECT op_r.rate FROM operation_rates op_r WHERE op_r.operation_id = ? AND op_r.worker_id = w.id ORDER BY op_r.id DESC LIMIT 1),
                                     NULLIF(w.default_rate, 0.00),
                                     NULLIF(?, 0.00),
                                     CASE 
                                         WHEN ? > 0 AND w.rate_per_minute > 0 THEN ROUND(? * w.rate_per_minute, 2)
                                         ELSE 0.00
                                     END
                                 ) AS effective_rate
                                 FROM workers w
                                 LEFT JOIN departments dep ON w.department_id = dep.id
                                 WHERE w.is_active = 1
                                 ORDER BY w.name ASC");
        $allStmt->execute([
            $opId,
            $operation['default_rate'],
            $estMins, $estMins
        ]);
        $relatedWorkers = $allStmt->fetchAll();
    }

    json_response([
        'status' => 'success',
        'operation' => $operation,
        'workers' => $relatedWorkers
    ]);
}

if ($action === 'get_lot_recent_entries') {
    $lotId = (int)($_GET['lot_id'] ?? 0);
    if ($lotId <= 0) {
        json_response(['status' => 'error', 'message' => 'Invalid lot ID'], 400);
    }

    $lStmt = $db->prepare("SELECT id, lot_number FROM lots WHERE id = ?");
    $lStmt->execute([$lotId]);
    $lot = $lStmt->fetch();
    if (!$lot) {
        json_response(['status' => 'error', 'message' => 'Lot not found'], 404);
    }

    $entriesStmt = $db->prepare("SELECT pe.*, l.lot_number, o.name AS op_name, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                                 FROM production_entries pe 
                                 JOIN lots l ON pe.lot_id = l.id 
                                 JOIN operations o ON pe.operation_id = o.id 
                                 JOIN workers w ON pe.worker_id = w.id 
                                 LEFT JOIN colors c ON pe.color_id = c.id 
                                 LEFT JOIN sizes s ON pe.size_id = s.id 
                                 WHERE pe.lot_id = ?
                                 ORDER BY pe.id DESC LIMIT 15");
    $entriesStmt->execute([$lotId]);
    $entries = $entriesStmt->fetchAll();

    // Format fields for frontend display
    $formatted = [];
    foreach ($entries as $e) {
        $formatted[] = [
            'id' => (int)$e['id'],
            'entry_number' => $e['entry_number'] ?: ('PE-' . $e['id']),
            'lot_number' => $e['lot_number'],
            'op_name' => $e['op_name'],
            'color_name' => $e['color_name'],
            'size_name' => $e['size_name'],
            'worker_name' => $e['worker_name'],
            'entry_date' => format_date($e['entry_date']),
            'good_qty' => (int)$e['good_qty'],
            'rejected_qty' => (int)$e['rejected_qty'],
            'rework_qty' => (int)$e['rework_qty'],
            'labour_amount' => format_currency((float)$e['labour_amount'])
        ];
    }

    json_response([
        'status' => 'success',
        'lot' => $lot,
        'entries' => $formatted
    ]);
}

json_response(['status' => 'error', 'message' => 'Unknown action'], 400);
