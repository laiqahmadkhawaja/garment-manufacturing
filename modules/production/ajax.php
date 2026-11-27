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
                            o.name AS op_name, o.code AS op_code, o.description, o.default_rate, o.rate_unit, o.estimated_minutes,
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
    $elemStmt = $db->prepare("SELECT pe.id, pe.element_name, pe.quantity, pe.multiplier, pe.pieces_per_palla, pe.pallas_per_garment, pe.rate_per_palla, COALESCE(pe.default_sam_minutes, 0.00) AS default_sam_minutes,
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
                              GROUP BY pe.id, pe.element_name, pe.quantity, pe.multiplier, pe.pieces_per_palla, pe.pallas_per_garment, pe.rate_per_palla, pe.default_sam_minutes, pe.sort_order, l.total_pieces, wer.custom_sam_minutes, wer.custom_piece_rate
                              ORDER BY pe.sort_order ASC, pe.id ASC");
    $elemStmt->execute([$assignedWorkerId, $lotId]);
    $lotElements = $elemStmt->fetchAll();

    // Calculate maximum complete whole units ready from operator for cutting
    $maxWholeReadyToCut = null;
    foreach ($lotElements as $el) {
        $m = (float)($el['quantity'] ?: $el['multiplier'] ?: 1);
        if ($m <= 0) $m = 1.0;
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
        $m = (float)($mel['quantity'] ?: $mel['multiplier'] ?: 1);
        if ($m <= 0) $m = 1.0;
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
    $opStmt = $db->prepare("SELECT id, name, code, description, parent_id, department_id, default_rate, rate_unit, estimated_minutes FROM operations WHERE id = ?");
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

// =============================================================================
// FAST PRODUCTION DESK - GET WORKER ACTIVE LOTS & OPERATIONS
// =============================================================================
if ($action === 'get_fast_sheet_data') {
    $workerId = (int)($_GET['worker_id'] ?? 0);
    $operationFilter = (int)($_GET['operation_id'] ?? 0);
    $lotFilter = (int)($_GET['lot_id'] ?? 0);
    $includeCompleted = !empty($_GET['include_completed']);

    // 1. Worker Profile & Assigned Operations
    $worker = null;
    $assignedOpIds = [];
    if ($workerId > 0) {
        $wStmt = $db->prepare("SELECT w.*, dep.name AS department_name 
                               FROM workers w 
                               LEFT JOIN departments dep ON w.department_id = dep.id 
                               WHERE w.id = ?");
        $wStmt->execute([$workerId]);
        $worker = $wStmt->fetch();

        if ($worker) {
            // Direct assigned operations
            $woStmt = $db->prepare("SELECT operation_id FROM worker_operations WHERE worker_id = ?");
            $woStmt->execute([$workerId]);
            $assignedOpIds = $woStmt->fetchAll(PDO::FETCH_COLUMN);

            // Department operations fallback/supplement
            if (!empty($worker['department_id'])) {
                $deptOpsStmt = $db->prepare("SELECT id FROM operations WHERE department_id = ? AND is_active = 1");
                $deptOpsStmt->execute([$worker['department_id']]);
                $deptOpIds = $deptOpsStmt->fetchAll(PDO::FETCH_COLUMN);
                $assignedOpIds = array_values(array_unique(array_merge($assignedOpIds, $deptOpIds)));
            }
        }
    }

    // 2. Fetch all active workers for row worker changer
    $allWorkers = $db->query("SELECT w.id, w.name, w.code, w.worker_type, w.department_id, dep.name AS department_name, w.default_rate, w.rate_per_minute 
                             FROM workers w 
                             LEFT JOIN departments dep ON w.department_id = dep.id 
                             WHERE w.is_active = 1 
                             ORDER BY w.name ASC")->fetchAll();

    // 3. Fetch active operations for filter dropdown
    $allOps = $db->query("SELECT o.id, o.name, o.code, o.default_rate, o.estimated_minutes, o.department_id, dep.name AS dept_name 
                         FROM operations o 
                         LEFT JOIN departments dep ON o.department_id = dep.id 
                         WHERE o.is_active = 1 
                         ORDER BY o.sequence ASC, o.id ASC")->fetchAll();

    // 4. Query active lots with workflow steps
    $lotSql = "SELECT l.id AS lot_id, l.lot_number, l.lot_date, l.total_pieces, l.status AS lot_status,
                      l.product_id, p.name AS product_name, d.design_number, sz.name AS single_size_name
               FROM lots l
               JOIN products p ON l.product_id = p.id
               LEFT JOIN designs d ON l.design_id = d.id
               LEFT JOIN sizes sz ON l.single_size_id = sz.id
               WHERE l.status NOT IN ('Closed', 'Cancelled', 'Delivered')";

    if ($lotFilter > 0) {
        $lotSql .= " AND l.id = " . (int)$lotFilter;
    }
    $lotSql .= " ORDER BY l.id DESC";

    $activeLots = $db->query($lotSql)->fetchAll();

    $sheetData = [];
    foreach ($activeLots as $l) {
        $lotId = (int)$l['lot_id'];
        $totPcs = (int)$l['total_pieces'];

        // Get operations for this lot
        $stepSql = "SELECT lws.sequence, o.id AS operation_id, o.name AS op_name, o.code AS op_code,
                           o.default_rate, o.estimated_minutes, dep.name AS dept_name, lws.status AS step_status
                    FROM lot_workflow_steps lws
                    JOIN operations o ON lws.operation_id = o.id
                    LEFT JOIN departments dep ON o.department_id = dep.id
                    WHERE lws.lot_id = ?
                    ORDER BY lws.sequence ASC, o.id ASC";
        $stepStmt = $db->prepare($stepSql);
        $stepStmt->execute([$lotId]);
        $steps = $stepStmt->fetchAll();

        // If no workflow steps snapshot, fetch all operations
        if (empty($steps)) {
            $steps = $allOps;
        }

        $lotOpRows = [];
        foreach ($steps as $st) {
            $opId = (int)($st['operation_id'] ?? $st['id'] ?? 0);
            $opName = $st['op_name'] ?? $st['name'];
            $deptName = $st['dept_name'] ?? '';

            // Filter by operation if selected
            if ($operationFilter > 0 && $opId !== $operationFilter) {
                continue;
            }

            // Filter by worker's assigned operations if worker is selected and has assigned ops
            if ($workerId > 0 && !empty($assignedOpIds) && !in_array($opId, $assignedOpIds) && $operationFilter <= 0) {
                continue;
            }

            // Get progress
            $progStmt = $db->prepare("SELECT 
                COALESCE(SUM(good_qty), 0) AS good_done,
                COALESCE(SUM(rejected_qty), 0) AS rej_done,
                COALESCE(SUM(rework_qty), 0) AS rew_done
                FROM production_entries 
                WHERE lot_id = ? AND operation_id = ?");
            $progStmt->execute([$lotId, $opId]);
            $prog = $progStmt->fetch();

            $goodDone = (int)$prog['good_done'];
            $rejDone = (int)$prog['rej_done'];
            $rewDone = (int)$prog['rew_done'];
            $pendingBal = max(0, $totPcs - $goodDone);

            // Skip completed rows if includeCompleted is false
            if (!$includeCompleted && $pendingBal <= 0) {
                continue;
            }

            // Calculate effective rate for this worker and operation
            $effRate = 0.00;
            if ($workerId > 0) {
                $opRStmt = $db->prepare("SELECT rate FROM operation_rates WHERE operation_id = ? AND worker_id = ? ORDER BY id DESC LIMIT 1");
                $opRStmt->execute([$opId, $workerId]);
                $custRate = $opRStmt->fetchColumn();
                if ($custRate !== false && $custRate !== null) {
                    $effRate = (float)$custRate;
                } elseif (!empty($worker['default_rate']) && (float)$worker['default_rate'] > 0) {
                    $effRate = (float)$worker['default_rate'];
                } elseif (!empty($st['default_rate']) && (float)$st['default_rate'] > 0) {
                    $effRate = (float)$st['default_rate'];
                } elseif (!empty($worker['rate_per_minute']) && (float)$worker['rate_per_minute'] > 0 && !empty($st['estimated_minutes'])) {
                    $effRate = round((float)$st['estimated_minutes'] * (float)$worker['rate_per_minute'], 2);
                }
            } else {
                $effRate = (float)($st['default_rate'] ?? 0.00);
            }

            // Check if Operator / Cutting elements are present
            $seqAllowance = get_lot_stage_sequence_allowance($lotId, $opId);
            $maxAvailable = $seqAllowance['max_available'];
            $isBlocked = $seqAllowance['is_blocked'];
            $suggestedQty = $seqAllowance['suggested_qty'];
            $pred = $seqAllowance['predecessor'];

            $isCuttingOp = (stripos($opName, 'Cut') !== false || stripos($opName, 'Katai') !== false || stripos($deptName, 'Cut') !== false || stripos($deptName, 'Katai') !== false);
            $isOpOrCut = ($isCuttingOp || stripos($opName, 'Operator') !== false || stripos($deptName, 'Operator') !== false);
            $elements = [];
            if ($isOpOrCut) {
                $elemStmt = $db->prepare("SELECT pe.id, pe.element_name, pe.quantity, pe.multiplier, pe.pieces_per_palla, pe.pallas_per_garment, pe.rate_per_palla,
                                                 loe.total_pieces AS op_pieces, loe.cut_pieces
                                          FROM product_elements pe
                                          LEFT JOIN lot_operator_element_entries loe ON loe.element_id = pe.id AND loe.lot_id = ?
                                          WHERE pe.product_id = ?
                                          ORDER BY pe.sort_order ASC, pe.id ASC");
                $elemStmt->execute([$lotId, $l['product_id']]);
                $elemRows = $elemStmt->fetchAll();

                foreach ($elemRows as $er) {
                    $m = (float)($er['quantity'] ?: $er['multiplier'] ?: 1.0);
                    $reqPcs = (int)ceil($m * $totPcs);
                    $donePcs = $isCuttingOp ? (int)$er['cut_pieces'] : (int)$er['op_pieces'];
                    $elPending = max(0, $reqPcs - $donePcs);
                    $ppp = (float)$er['pieces_per_palla'] > 0 ? (float)$er['pieces_per_palla'] : 1.0;
                    $rpp = (float)$er['rate_per_palla'];
                    $elRate = ($rpp > 0 && $ppp > 0) ? round($rpp / $ppp, 2) : $effRate;

                    $elements[] = [
                        'id' => (int)$er['id'],
                        'element_name' => $er['element_name'],
                        'multiplier' => $m,
                        'pieces_per_palla' => $ppp,
                        'pallas_per_garment' => (float)$er['pallas_per_garment'],
                        'rate_per_palla' => $rpp,
                        'element_rate' => $elRate,
                        'required_pieces' => $reqPcs,
                        'done_pieces' => $donePcs,
                        'pending_pieces' => $elPending,
                        'suggested_today' => min($elPending, (int)ceil($maxAvailable * $m))
                    ];
                }
            }

            $lotOpRows[] = [
                'operation_id' => $opId,
                'op_name' => $opName,
                'op_code' => $st['op_code'] ?? '',
                'sequence' => (int)($st['sequence'] ?? 1),
                'dept_name' => $deptName,
                'required_qty' => $totPcs,
                'good_done' => $goodDone,
                'rejected_done' => $rejDone,
                'rework_done' => $rewDone,
                'pending_balance' => $pendingBal,
                'max_available' => $maxAvailable,
                'is_blocked' => $isBlocked,
                'suggested_qty' => $suggestedQty,
                'predecessor_op_id' => $pred ? (int)$pred['operation_id'] : null,
                'predecessor_op_name' => $pred ? $pred['op_name'] : null,
                'predecessor_sequence' => $pred ? (float)$pred['sequence'] : null,
                'predecessor_good_done' => $pred ? (int)$pred['good_done'] : $totPcs,
                'default_rate' => $effRate,
                'is_operator_or_cutting' => $isOpOrCut,
                'elements' => $elements
            ];
        }

        if (!empty($lotOpRows)) {
            $sheetData[] = [
                'lot_id' => $lotId,
                'lot_number' => $l['lot_number'],
                'lot_date' => $l['lot_date'],
                'total_pieces' => $totPcs,
                'lot_status' => $l['lot_status'],
                'product_id' => (int)$l['product_id'],
                'product_name' => $l['product_name'],
                'design_number' => $l['design_number'],
                'single_size_name' => $l['single_size_name'],
                'operations' => $lotOpRows
            ];
        }
    }

    json_response([
        'status' => 'success',
        'worker' => $worker,
        'assigned_operation_ids' => $assignedOpIds,
        'all_workers' => $allWorkers,
        'all_operations' => $allOps,
        'sheet' => $sheetData,
        'total_rows' => array_sum(array_map(fn($lt) => count($lt['operations']), $sheetData))
    ]);
}

// =============================================================================
// FAST PRODUCTION DESK - ROW-WISE REAL-TIME ENTRY SAVE
// =============================================================================
if ($action === 'save_fast_row_entry') {
    // Read POST or JSON body
    $input = $_POST;
    if (empty($input)) {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $input = json_decode($raw, true) ?: [];
        }
    }

    $lotId = (int)($input['lot_id'] ?? 0);
    $operationId = (int)($input['operation_id'] ?? 0);
    $workerId = (int)($input['worker_id'] ?? 0);
    $entryDate = trim($input['entry_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
        $entryDate = date('Y-m-d');
    }
    $shift = trim($input['shift'] ?? 'Day Shift');
    if (!in_array($shift, ['Day Shift', 'Night Shift', 'Single Shift'])) {
        $shift = 'Day Shift';
    }

    $goodQty = (int)($input['good_qty'] ?? 0);
    $rejectedQty = max(0, (int)($input['rejected_qty'] ?? 0));
    $reworkQty = max(0, (int)($input['rework_qty'] ?? 0));
    $rate = (float)($input['rate'] ?? 0.00);
    $notes = trim($input['notes'] ?? 'Fast Desk Production Entry');
    $elements = $input['elements'] ?? [];
    if (is_string($elements)) {
        $elements = json_decode($elements, true) ?: [];
    }

    // Basic Validations
    if ($lotId <= 0 || $operationId <= 0 || $workerId <= 0) {
        json_response(['status' => 'error', 'message' => 'براہ کرم لاٹ، آپریشن اور کاریگر کو منتخب کریں (Missing required fields)'], 400);
    }

    if ($goodQty <= 0 && $rejectedQty <= 0 && $reworkQty <= 0) {
        json_response(['status' => 'error', 'message' => 'براہ کرم کم از کم 1 پیس درج کریں'], 400);
    }

    // Verify Lot
    $lotStmt = $db->prepare("SELECT l.*, p.name AS product_name, d.design_number 
                             FROM lots l 
                             JOIN products p ON l.product_id = p.id 
                             LEFT JOIN designs d ON l.design_id = d.id 
                             WHERE l.id = ?");
    $lotStmt->execute([$lotId]);
    $lot = $lotStmt->fetch();
    if (!$lot) {
        json_response(['status' => 'error', 'message' => 'منتخب کردہ لاٹ موجود نہیں ہے'], 404);
    }

    // Verify Operation
    $opStmt = $db->prepare("SELECT o.*, dep.name AS dept_name FROM operations o LEFT JOIN departments dep ON o.department_id = dep.id WHERE o.id = ?");
    $opStmt->execute([$operationId]);
    $op = $opStmt->fetch();
    if (!$op) {
        json_response(['status' => 'error', 'message' => 'منتخب کردہ آپریشن موجود نہیں ہے'], 404);
    }

    // Verify Worker
    $wStmt = $db->prepare("SELECT * FROM workers WHERE id = ?");
    $wStmt->execute([$workerId]);
    $worker = $wStmt->fetch();
    if (!$worker) {
        json_response(['status' => 'error', 'message' => 'منتخب کردہ کاریگر موجود نہیں ہے'], 404);
    }

    // Validate Sequential Workflow Stage Allowance & Capacity
    $seqAllowance = get_lot_stage_sequence_allowance($lotId, $operationId);
    $currentGood = (int)$seqAllowance['current_good'];
    $pendingBalance = (int)$seqAllowance['pending_balance'];
    $maxAvailable = (int)$seqAllowance['max_available'];

    if ($goodQty > $maxAvailable) {
        $pred = $seqAllowance['predecessor'];
        if ($pred) {
            $prevOpName = $pred['op_name'];
            $prevDone = (int)$pred['good_done'];
            if ($maxAvailable === 0) {
                json_response([
                    'status' => 'error',
                    'message' => "پچھلا مرحلہ ($prevOpName) ابھی زیرِ تکمیل ہے (صرف $prevDone پیسز تیار ہوئے ہیں)۔ جب تک پچھلے مرحلے سے مزید پیسز مکمل نہیں ہوتے، اس مرحلے ({$op['name']}) کی انٹری محفوظ نہیں ہو سکتی۔"
                ], 400);
            } else {
                json_response([
                    'status' => 'error',
                    'message' => "پچھلے مرحلے ($prevOpName) میں صرف $prevDone پیسز تیار ہوئے ہیں (اور اس مرحلے میں پہلے سے $currentGood ہو چکے ہیں)! آپ اس وقت زیادہ سے زیادہ $maxAvailable پیسز ہی درج کر سکتے ہیں۔"
                ], 400);
            }
        } else {
            json_response([
                'status' => 'error',
                'message' => "تعداد باقی پینڈنگ پیسز ($pendingBalance) سے زیادہ نہیں ہو سکتی! آپ نے $goodQty درج کی ہے۔"
            ], 400);
        }
    }

    try {
        $db->beginTransaction();

        // 1. Calculate Labour
        $workerType = (string)($worker['worker_type'] ?? 'Piece Rate');
        $isDaily = (stripos($workerType, 'daily') !== false || stripos($workerType, 'یومیہ') !== false);
        $isMonthly = (stripos($workerType, 'month') !== false || stripos($workerType, 'ماہانہ') !== false || stripos($workerType, 'salary') !== false);
        $labourAmount = 0.00;

        if ($isDaily) {
            $dwCheck = $db->prepare("SELECT id FROM worker_ledger WHERE worker_id = ? AND transaction_date = ? AND description LIKE '%Daily Wage%'");
            $dwCheck->execute([$workerId, $entryDate]);
            if (!$dwCheck->fetchColumn()) {
                $labourAmount = ($rate > 0) ? $rate : (float)($worker['default_rate'] ?? 0.00);
            }
        } elseif ($isMonthly) {
            $labourAmount = 0.00;
        } else {
            $labourAmount = round($goodQty * $rate, 2);
            // If custom element quantities are provided with palla rates:
            if (!empty($elements) && is_array($elements)) {
                $pElems = $db->prepare("SELECT id, multiplier, pieces_per_palla, rate_per_palla FROM product_elements WHERE product_id = ?");
                $pElems->execute([$lot['product_id']]);
                $peRows = $pElems->fetchAll(PDO::FETCH_ASSOC);
                $hasPallaRates = false;
                $elemLabourSum = 0.00;
                foreach ($peRows as $pel) {
                    $elId = (int)$pel['id'];
                    $rpp = (float)$pel['rate_per_palla'];
                    $ppp = (float)$pel['pieces_per_palla'];
                    if ($rpp > 0 && $ppp > 0) {
                        $hasPallaRates = true;
                        $elQty = isset($elements[$elId]) ? (int)$elements[$elId] : (int)ceil($goodQty * (float)($pel['multiplier'] ?: 1.0));
                        $elemLabourSum += round(($elQty / $ppp) * $rpp, 2);
                    }
                }
                if ($hasPallaRates && $elemLabourSum > 0) {
                    $labourAmount = $elemLabourSum;
                }
            }
        }

        // 2. Insert Production Entry
        $baseDocNum = generate_doc_number('PE-', 'production_entries', 'entry_number');
        $entryNum = ensure_unique_doc_number($db, 'production_entries', 'entry_number', $baseDocNum);
        $estMins = (float)($op['estimated_minutes'] ?? 0.00);
        $minRate = (float)($worker['rate_per_minute'] ?? 2.50);
        $totalMins = round($goodQty * $estMins, 2);
        $qtyReceived = $goodQty + $rejectedQty + $reworkQty;

        $insPe = $db->prepare("INSERT INTO production_entries 
            (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, 
             qty_received, good_qty, rejected_qty, rework_qty, rate, minutes_per_piece, worker_minute_rate, 
             total_minutes, labour_amount, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insPe->execute([
            $entryNum, $entryDate, $lotId, $operationId, $workerId, $shift,
            $lot['single_size_id'] ?: null,
            $qtyReceived, $goodQty, $rejectedQty, $reworkQty,
            $rate, $estMins, $minRate, $totalMins, $labourAmount,
            $notes, $_SESSION['user_id'] ?? null
        ]);
        $entryId = (int)$db->lastInsertId();

        // 3. Insert Rejections
        if ($rejectedQty > 0) {
            $db->prepare("INSERT INTO production_rejections (production_entry_id, lot_id, operation_id, quantity, reason) 
                          VALUES (?, ?, ?, ?, ?)")
               ->execute([$entryId, $lotId, $operationId, $rejectedQty, 'Defective during fast production run']);
        }

        // 4. Insert Rework
        if ($reworkQty > 0) {
            $db->prepare("INSERT INTO production_rework (production_entry_id, lot_id, operation_id, quantity, notes, status) 
                          VALUES (?, ?, ?, ?, ?, 'Pending')")
               ->execute([$entryId, $lotId, $operationId, $reworkQty, 'Requires rework during fast production run']);
        }

        // 5. Worker Ledger Credit
        if ($labourAmount > 0) {
            $balStmt = $db->prepare("SELECT COALESCE(
                (SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1), 
                (SELECT opening_balance FROM workers WHERE id = ?)
            )");
            $balStmt->execute([$workerId, $workerId]);
            $lastBal = (float)$balStmt->fetchColumn();
            $newBal = $lastBal + $labourAmount;

            $ledgerDesc = $isDaily
                ? "Daily shift on Lot #{$lot['lot_number']} - {$op['name']} ($goodQty pcs @ Rs. " . number_format($rate, 2) . "/day Daily Wage)"
                : "Work on Lot #{$lot['lot_number']} - {$op['name']} ($goodQty pcs @ Rs. " . number_format($rate, 2) . ")";

            $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                          VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)")
               ->execute([$workerId, $entryDate, $entryId, $lotId, $operationId, $labourAmount, $newBal, $ledgerDesc, $_SESSION['user_id'] ?? null]);
        }

        // 6. Update Workflow Step Status
        $newGoodTotal = $currentGood + $goodQty;
        $stepStatus = ($newGoodTotal >= (int)$lot['total_pieces']) ? 'Completed' : 'In Progress';
        $db->prepare("UPDATE lot_workflow_steps SET status = ? WHERE lot_id = ? AND operation_id = ?")
           ->execute([$stepStatus, $lotId, $operationId]);

        // 7. Update Lot Status if in Draft
        if ($lot['status'] === 'Draft') {
            $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
            $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, 'Draft', 'In Production', 'Fast production entry submitted', ?)")
               ->execute([$lotId, $_SESSION['user_id'] ?? null]);
        }

        // 8. Elements Sync (for Cutting or Operator stages)
        $isCutting = (stripos($op['name'], 'Cut') !== false || stripos($op['name'], 'Katai') !== false || stripos($op['dept_name'] ?? '', 'Cut') !== false || stripos($op['description'] ?? '', 'Cut') !== false);
        $isOperator = (stripos($op['name'], 'Operator') !== false);

        if ($isCutting || $isOperator) {
            $peRows = $db->prepare("SELECT * FROM product_elements WHERE product_id = ?");
            $peRows->execute([$lot['product_id']]);
            $prodElements = $peRows->fetchAll();

            foreach ($prodElements as $pEl) {
                $elId = (int)$pEl['id'];
                $mult = (float)($pEl['quantity'] ?: $pEl['multiplier'] ?: 1.0);
                $elPcs = isset($elements[$elId]) ? (int)$elements[$elId] : (int)ceil($goodQty * $mult);
                if ($elPcs <= 0) continue;

                $reqPcs = (int)ceil($mult * (int)$lot['total_pieces']);

                $chkEl = $db->prepare("SELECT * FROM lot_operator_element_entries WHERE lot_id = ? AND element_id = ?");
                $chkEl->execute([$lotId, $elId]);
                $existing = $chkEl->fetch();

                if ($existing) {
                    if ($isCutting) {
                        $newCut = min($reqPcs, (int)$existing['cut_pieces'] + $elPcs);
                        $db->prepare("UPDATE lot_operator_element_entries SET cut_pieces = ?, entry_date = ? WHERE id = ?")
                           ->execute([$newCut, $entryDate, $existing['id']]);
                    } else {
                        $p1 = (int)$existing['operator1_pieces'];
                        $p2 = (int)$existing['operator2_pieces'];
                        if ($shift === 'Night Shift') {
                            $p2 += $elPcs;
                            $op2Id = $workerId;
                            $op1Id = $existing['operator1_id'];
                        } else {
                            $p1 += $elPcs;
                            $op1Id = $workerId;
                            $op2Id = $existing['operator2_id'];
                        }
                        $newTot = min($reqPcs, $p1 + $p2);
                        $db->prepare("UPDATE lot_operator_element_entries SET operator1_id = ?, operator1_pieces = ?, operator2_id = ?, operator2_pieces = ?, total_pieces = ?, entry_date = ? WHERE id = ?")
                           ->execute([$op1Id, $p1, $op2Id, $p2, $newTot, $entryDate, $existing['id']]);
                    }
                } else {
                    if ($isCutting) {
                        $db->prepare("INSERT INTO lot_operator_element_entries (lot_id, element_id, entry_date, required_pieces, cut_pieces, total_pieces, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                           ->execute([$lotId, $elId, $entryDate, $reqPcs, $elPcs, $elPcs, $_SESSION['user_id'] ?? null]);
                    } else {
                        $p1 = ($shift === 'Night Shift') ? 0 : $elPcs;
                        $p2 = ($shift === 'Night Shift') ? $elPcs : 0;
                        $op1 = ($shift === 'Night Shift') ? null : $workerId;
                        $op2 = ($shift === 'Night Shift') ? $workerId : null;
                        $db->prepare("INSERT INTO lot_operator_element_entries (lot_id, element_id, entry_date, operator1_id, operator1_pieces, operator2_id, operator2_pieces, total_pieces, required_pieces, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                           ->execute([$lotId, $elId, $entryDate, $op1, $p1, $op2, $p2, ($p1 + $p2), $reqPcs, $_SESSION['user_id'] ?? null]);
                    }
                }
            }
        }

        $db->commit();

        $newPending = max(0, (int)$lot['total_pieces'] - $newGoodTotal);

        json_response([
            'status' => 'success',
            'message' => "لاٹ #{$lot['lot_number']} برائے {$op['name']} کامیابی سے محفوظ ہو گئی! ($goodQty پیسز)",
            'lot_id' => $lotId,
            'operation_id' => $operationId,
            'entry_number' => $entryNum,
            'good_entered' => $goodQty,
            'new_good_done' => $newGoodTotal,
            'new_pending' => $newPending,
            'total_pieces' => (int)$lot['total_pieces'],
            'labour_amount' => $labourAmount,
            'labour_formatted' => format_currency($labourAmount),
            'step_status' => $stepStatus
        ]);

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        json_response(['status' => 'error', 'message' => 'انٹری محفوظ کرنے میں خرابی پیش آگئی: ' . $e->getMessage()], 500);
    }
}

json_response(['status' => 'error', 'message' => 'Unknown action'], 400);
