<?php
/**
 * Garment Manufacturing ERP - Ultra Fast Production Work Entry
 * Features Color x Size Batch Matrix Grid Entry & Single Variant Item Entry
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Production Work Entry';
$activeNav = 'production_entry';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);
$preselectedOpId = (int)($_GET['operation_id'] ?? 0);
$error = '';

// Handle Production Work Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entryMode = trim($_POST['entry_mode'] ?? 'matrix');
    $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $lotId = (int)($_POST['lot_id'] ?? 0);
    $operationId = (int)($_POST['operation_id'] ?? 0);
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $rate = (float)($_POST['rate'] ?? 0.00);

    if ($lotId <= 0 || $operationId <= 0 || $workerId <= 0) {
        $error = 'Please fill all required fields: Lot, Operation, and Worker.';
    } elseif ($rate < 0) {
        $error = 'Piece rate cannot be negative.';
    } elseif ($entryMode === 'matrix') {
        // =====================================================================
        // FAST BATCH MATRIX ENTRY MODE
        // =====================================================================
        $matrixData = $_POST['matrix'] ?? [];
        $notes = trim($_POST['notes'] ?? '');
        $rejectionReason = trim($_POST['matrix_rejection_reason'] ?? 'Quality inspection rejection');
        $reworkNotes = trim($_POST['matrix_rework_notes'] ?? 'Requires correction/touchup');

        // Collect all non-zero cells (good, rejected, rework)
        $entriesToSave = [];
        $totalGood = 0;
        $totalRejected = 0;
        $totalRework = 0;
        $totalLabour = 0.00;

        foreach ($matrixData as $cId => $sizes) {
            $cId = (int)$cId;
            if (!is_array($sizes)) continue;
            foreach ($sizes as $sId => $item) {
                $sId = (int)$sId;
                $gQty = isset($item['good']) ? (int)$item['good'] : (is_numeric($item) ? (int)$item : 0);
                $rQty = isset($item['rejected']) ? (int)$item['rejected'] : 0;
                $wQty = isset($item['rework']) ? (int)$item['rework'] : 0;

                if ($gQty < 0 || $rQty < 0 || $wQty < 0) {
                    $error = 'Quantities cannot be negative.';
                    break 2;
                }

                $cellReceived = $gQty + $rQty + $wQty;
                if ($cellReceived > 0) {
                    $cellLabour = round($gQty * $rate, 2);
                    $entriesToSave[] = [
                        'color_id' => $cId,
                        'size_id' => $sId,
                        'qty_received' => $cellReceived,
                        'good_qty' => $gQty,
                        'rejected_qty' => $rQty,
                        'rework_qty' => $wQty,
                        'labour_amount' => $cellLabour
                    ];
                    $totalGood += $gQty;
                    $totalRejected += $rQty;
                    $totalRework += $wQty;
                    $totalLabour += $cellLabour;
                }
            }
        }

        if (empty($error)) {
            if (empty($entriesToSave)) {
                $error = 'Please enter pieces for at least one color & size variant in the matrix.';
            } else {
                // Fetch lot details
                $lQuery = $db->prepare("SELECT lot_number, status, total_pieces FROM lots WHERE id = ?");
                $lQuery->execute([$lotId]);
                $lotRow = $lQuery->fetch();
                if (!$lotRow) {
                    $error = 'Selected lot does not exist.';
                } else {
                    $lotNum = $lotRow['lot_number'] ?? '';
                    $currentLotStatus = $lotRow['status'] ?? '';
                    $lotTotalPieces = (int)$lotRow['total_pieces'];

                    // 1. Stage-level capacity check: cumulative good pieces in this stage cannot exceed lot.total_pieces
                    $stageGoodStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
                    $stageGoodStmt->execute([$lotId, $operationId]);
                    $currentStageGood = (int)$stageGoodStmt->fetchColumn();

                    if (($currentStageGood + $totalGood) > $lotTotalPieces) {
                        $overPcs = ($currentStageGood + $totalGood) - $lotTotalPieces;
                        $allowed = max(0, $lotTotalPieces - $currentStageGood);
                        $error = "Production quantity exceeds lot capacity! Lot #$lotNum allows a maximum of $lotTotalPieces pieces. Already produced: $currentStageGood pcs. Maximum pieces allowed to record now is $allowed pcs (attempted to add: $totalGood pcs, exceeding by $overPcs pcs).";
                    } else {
                        // 2. Variant-level capacity check: each variant cannot exceed planned quantity
                        $varPlanStmt = $db->prepare("SELECT color_id, size_id, quantity FROM lot_variants WHERE lot_id = ?");
                        $varPlanStmt->execute([$lotId]);
                        $lotVariantsMap = [];
                        while ($vRow = $varPlanStmt->fetch()) {
                            $lotVariantsMap[$vRow['color_id'] . '_' . $vRow['size_id']] = (int)$vRow['quantity'];
                        }

                        $varDoneStmt = $db->prepare("SELECT color_id, size_id, COALESCE(SUM(good_qty), 0) as done_qty 
                                                     FROM production_entries 
                                                     WHERE lot_id = ? AND operation_id = ? 
                                                     GROUP BY color_id, size_id");
                        $varDoneStmt->execute([$lotId, $operationId]);
                        $varDoneMap = [];
                        while ($dRow = $varDoneStmt->fetch()) {
                            $varDoneMap[$dRow['color_id'] . '_' . $dRow['size_id']] = (int)$dRow['done_qty'];
                        }

                        foreach ($entriesToSave as $entry) {
                            $vKey = $entry['color_id'] . '_' . $entry['size_id'];
                            $plannedQty = $lotVariantsMap[$vKey] ?? 0;
                            $alreadyDone = $varDoneMap[$vKey] ?? 0;
                            $attemptedGood = $entry['good_qty'];

                            if (($alreadyDone + $attemptedGood) > $plannedQty) {
                                $cNameStmt = $db->prepare("SELECT name FROM colors WHERE id = ?");
                                $cNameStmt->execute([$entry['color_id']]);
                                $cName = $cNameStmt->fetchColumn() ?: 'Color #' . $entry['color_id'];

                                $sNameStmt = $db->prepare("SELECT name FROM sizes WHERE id = ?");
                                $sNameStmt->execute([$entry['size_id']]);
                                $sName = $sNameStmt->fetchColumn() ?: 'Size #' . $entry['size_id'];

                                $overVar = ($alreadyDone + $attemptedGood) - $plannedQty;
                                $varRemaining = max(0, $plannedQty - $alreadyDone);
                                $error = "Variant quantity limit exceeded for {$cName} ({$sName})! Planned: $plannedQty pcs, Already produced: $alreadyDone pcs, Remaining: $varRemaining pcs (attempted: $attemptedGood pcs, exceeding by $overVar pcs).";
                                break;
                            }
                        }
                    }
                }
            }

            if (empty($error)) {
                $db->beginTransaction();
                    try {
                        // Fetch operation and worker names
                        $opQuery = $db->prepare("SELECT name FROM operations WHERE id = ?");
                        $opQuery->execute([$operationId]);
                        $opName = $opQuery->fetchColumn();

                        $wQuery = $db->prepare("SELECT name FROM workers WHERE id = ?");
                        $wQuery->execute([$workerId]);
                        $workerName = $wQuery->fetchColumn();

                        // Prepare production entries insert
                        $insertStmt = $db->prepare("INSERT INTO production_entries 
                            (entry_number, entry_date, lot_id, operation_id, worker_id, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, labour_amount, notes, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    // Prepare rejections and rework statements
                    $rejStmt = $db->prepare("INSERT INTO production_rejections 
                        (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, reason) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)");

                    $rewStmt = $db->prepare("INSERT INTO production_rework 
                        (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, notes, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')");

                    // Generate sequential entry numbers
                    $baseDoc = generate_doc_number('PE-', 'production_entries', 'entry_number');
                    preg_match('/\d+$/', $baseDoc, $m);
                    $docSeq = isset($m[0]) ? (int)$m[0] : 101;

                    $firstEntryId = null;
                    foreach ($entriesToSave as $entry) {
                        $entryNum = 'PE-' . str_pad((string)$docSeq++, 4, '0', STR_PAD_LEFT);
                        $insertStmt->execute([
                            $entryNum,
                            $entryDate,
                            $lotId,
                            $operationId,
                            $workerId,
                            $entry['color_id'],
                            $entry['size_id'],
                            $entry['qty_received'],
                            $entry['good_qty'],
                            $entry['rejected_qty'],
                            $entry['rework_qty'],
                            $rate,
                            $entry['labour_amount'],
                            $notes,
                            $_SESSION['user_id'] ?? null
                        ]);
                        $newId = (int)$db->lastInsertId();
                        if ($firstEntryId === null) {
                            $firstEntryId = $newId;
                        }

                        // Track Rejection if any
                        if ($entry['rejected_qty'] > 0) {
                            $rejStmt->execute([
                                $newId,
                                $lotId,
                                $operationId,
                                $entry['color_id'],
                                $entry['size_id'],
                                $entry['rejected_qty'],
                                $rejectionReason
                            ]);
                        }

                        // Track Rework if any
                        if ($entry['rework_qty'] > 0) {
                            $rewStmt->execute([
                                $newId,
                                $lotId,
                                $operationId,
                                $entry['color_id'],
                                $entry['size_id'],
                                $entry['rework_qty'],
                                $reworkNotes
                            ]);
                        }
                    }

                    // Worker Ledger update (double entry: credit earned labour if totalLabour > 0)
                    if ($totalLabour > 0) {
                        $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
                        $balStmt->execute([$workerId]);
                        $lastBal = $balStmt->fetchColumn();
                        if ($lastBal === false) {
                            $wStmt = $db->prepare("SELECT opening_balance FROM workers WHERE id = ?");
                            $wStmt->execute([$workerId]);
                            $lastBal = (float)$wStmt->fetchColumn();
                        } else {
                            $lastBal = (float)$lastBal;
                        }

                        $newBal = $lastBal + $totalLabour;
                        $varCount = count($entriesToSave);
                        $ledgerDesc = "Batch labour on Lot #$lotNum - $opName ($totalGood good pcs";
                        if ($totalRejected > 0) $ledgerDesc .= ", $totalRejected rej";
                        if ($totalRework > 0) $ledgerDesc .= ", $totalRework rew";
                        $ledgerDesc .= " across $varCount variants @ Rs. " . number_format($rate, 2) . ")";

                        $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
                            (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by) 
                            VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)");
                        $ledgStmt->execute([
                            $workerId,
                            $entryDate,
                            $firstEntryId,
                            $lotId,
                            $operationId,
                            $totalLabour,
                            $newBal,
                            $ledgerDesc,
                            $_SESSION['user_id'] ?? null
                        ]);
                    }

                    // Update Lot Status if it was Draft
                    if ($currentLotStatus === 'Draft') {
                        $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
                        $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, 'Draft', 'In Production', 'Batch production entry submitted', ?)")
                           ->execute([$lotId, $_SESSION['user_id'] ?? null]);
                    }

                    // Update step status in lot_workflow_steps
                    $stepStmt = $db->prepare("UPDATE lot_workflow_steps SET status = 'In Progress' WHERE lot_id = ? AND operation_id = ? AND status = 'Pending'");
                    $stepStmt->execute([$lotId, $operationId]);

                    $varCount = count($entriesToSave);
                    log_audit('PRODUCTION_ENTRY_BATCH', 'production_entries', $firstEntryId, null, [
                        'lot_id' => $lotId,
                        'operation' => $opName,
                        'worker' => $workerName,
                        'variants_count' => $varCount,
                        'total_good' => $totalGood,
                        'total_rejected' => $totalRejected,
                        'total_rework' => $totalRework,
                        'total_labour' => $totalLabour
                    ]);

                    $db->commit();

                    $feedbackMsg = "Batch production entry saved! Recorded $totalGood good pieces";
                    if ($totalRejected > 0) $feedbackMsg .= " (+$totalRejected rejected)";
                    if ($totalRework > 0) $feedbackMsg .= " (+$totalRework rework)";
                    $feedbackMsg .= " across $varCount variants. " . format_currency($totalLabour) . " credited to $workerName.";
                    redirect("modules/production/index.php?lot_id=$lotId", $feedbackMsg);
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Error saving batch entry: ' . $e->getMessage();
                }
            }
        }
    } else {
        // =====================================================================
        // SINGLE VARIANT ITEM ENTRY MODE
        // =====================================================================
        $variantKey = trim($_POST['variant_key'] ?? ''); // format: "colorId_sizeId"
        $qtyReceived = (int)($_POST['qty_received'] ?? 0);
        $goodQty = (int)($_POST['good_qty'] ?? 0);
        $rejectedQty = (int)($_POST['rejected_qty'] ?? 0);
        $reworkQty = (int)($_POST['rework_qty'] ?? 0);
        $notes = trim($_POST['notes_single'] ?? ($_POST['notes'] ?? ''));

        // Parse variant key
        $colorId = 0;
        $sizeId = 0;
        if (str_contains($variantKey, '_')) {
            [$colorId, $sizeId] = explode('_', $variantKey);
            $colorId = (int)$colorId;
            $sizeId = (int)$sizeId;
        }

        if ($colorId <= 0 || $sizeId <= 0) {
            $error = 'Please select a valid Color & Size variant.';
        } elseif ($goodQty < 0 || $rejectedQty < 0 || $reworkQty < 0) {
            $error = 'Quantities cannot be negative.';
        } elseif ($goodQty === 0 && $rejectedQty === 0 && $reworkQty === 0) {
            $error = 'Please enter at least one piece under Good, Rejected, or Rework.';
        } else {
            // Fetch lot details
            $lQuery = $db->prepare("SELECT lot_number, status, total_pieces FROM lots WHERE id = ?");
            $lQuery->execute([$lotId]);
            $lotRow = $lQuery->fetch();
            $lotTotalPieces = (int)($lotRow['total_pieces'] ?? 0);
            $lotNum = $lotRow['lot_number'] ?? '';

            // 1. Stage-level capacity check: cumulative good pieces in this stage cannot exceed lot.total_pieces
            $stageGoodStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
            $stageGoodStmt->execute([$lotId, $operationId]);
            $currentStageGood = (int)$stageGoodStmt->fetchColumn();

            // 2. Variant-level capacity check: variant cannot exceed planned quantity
            $pvStmt = $db->prepare("SELECT quantity FROM lot_variants WHERE lot_id = ? AND color_id = ? AND size_id = ?");
            $pvStmt->execute([$lotId, $colorId, $sizeId]);
            $variantPlanned = (int)$pvStmt->fetchColumn();

            // Check existing good qty for this variant & operation
            $curDoneStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = ? AND color_id = ? AND size_id = ?");
            $curDoneStmt->execute([$lotId, $operationId, $colorId, $sizeId]);
            $previouslyDone = (int)$curDoneStmt->fetchColumn();

            if (($currentStageGood + $goodQty) > $lotTotalPieces) {
                $overPcs = ($currentStageGood + $goodQty) - $lotTotalPieces;
                $allowed = max(0, $lotTotalPieces - $currentStageGood);
                $error = "Production quantity exceeds lot capacity! Lot #$lotNum allows up to $lotTotalPieces pieces. Already produced: $currentStageGood pcs. Maximum pieces allowed to record now is $allowed pcs (attempted: $goodQty pcs, exceeding by $overPcs pcs).";
            } elseif (($previouslyDone + $goodQty) > $variantPlanned) {
                $overVar = ($previouslyDone + $goodQty) - $variantPlanned;
                $varRemaining = max(0, $variantPlanned - $previouslyDone);
                $error = "Production quantity exceeds variant planned capacity! Planned: $variantPlanned pcs, Already produced: $previouslyDone pcs, Remaining: $varRemaining pcs (attempted: $goodQty pcs, exceeding by $overVar pcs).";
            } else {
                if ($qtyReceived <= 0) {
                    $qtyReceived = $goodQty + $rejectedQty + $reworkQty;
                }

                $labourAmount = round($goodQty * $rate, 2);
                $cumulativeGood = $previouslyDone + $goodQty;

                $db->beginTransaction();
                try {

                // Generate entry number
                $entryNumber = generate_doc_number('PE-', 'production_entries', 'entry_number');

                // 1. Insert Production Entry
                $stmt = $db->prepare("INSERT INTO production_entries 
                    (entry_number, entry_date, lot_id, operation_id, worker_id, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, labour_amount, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $entryNumber,
                    $entryDate,
                    $lotId,
                    $operationId,
                    $workerId,
                    $colorId,
                    $sizeId,
                    $qtyReceived,
                    $goodQty,
                    $rejectedQty,
                    $reworkQty,
                    $rate,
                    $labourAmount,
                    $notes,
                    $_SESSION['user_id'] ?? null
                ]);
                $entryId = (int)$db->lastInsertId();

                // 2. Track Rejections if any
                if ($rejectedQty > 0) {
                    $rejStmt = $db->prepare("INSERT INTO production_rejections (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $rejStmt->execute([$entryId, $lotId, $operationId, $colorId, $sizeId, $rejectedQty, $notes ?: 'Quality inspection rejection']);
                }

                // 3. Track Rework if any
                if ($reworkQty > 0) {
                    $rewStmt = $db->prepare("INSERT INTO production_rework (production_entry_id, lot_id, operation_id, color_id, size_id, quantity, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')");
                    $rewStmt->execute([$entryId, $lotId, $operationId, $colorId, $sizeId, $reworkQty, $notes ?: 'Requires correction/touchup']);
                }

                // 4. Update Worker Ledger (Double-Entry: Credit worker earnings)
                $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
                $balStmt->execute([$workerId]);
                $lastBal = $balStmt->fetchColumn();
                if ($lastBal === false) {
                    $wStmt = $db->prepare("SELECT opening_balance FROM workers WHERE id = ?");
                    $wStmt->execute([$workerId]);
                    $lastBal = (float)$wStmt->fetchColumn();
                } else {
                    $lastBal = (float)$lastBal;
                }

                $newBal = $lastBal + $labourAmount;

                $lQuery = $db->prepare("SELECT lot_number FROM lots WHERE id = ?");
                $lQuery->execute([$lotId]);
                $lotNum = $lQuery->fetchColumn();

                $opQuery = $db->prepare("SELECT name FROM operations WHERE id = ?");
                $opQuery->execute([$operationId]);
                $opName = $opQuery->fetchColumn();

                $wQuery = $db->prepare("SELECT name FROM workers WHERE id = ?");
                $wQuery->execute([$workerId]);
                $workerName = $wQuery->fetchColumn();

                $ledgerDesc = "Labour earned on Lot #$lotNum - $opName ($goodQty pcs @ Rs. $rate)";
                $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
                    (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by) 
                    VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)");
                $ledgStmt->execute([
                    $workerId,
                    $entryDate,
                    $entryId,
                    $lotId,
                    $operationId,
                    $labourAmount,
                    $newBal,
                    $ledgerDesc,
                    $_SESSION['user_id'] ?? null
                ]);

                // 5. Update Lot Status if it was Draft
                $lotStStmt = $db->prepare("SELECT status FROM lots WHERE id = ?");
                $lotStStmt->execute([$lotId]);
                $currentLotStatus = $lotStStmt->fetchColumn();

                if ($currentLotStatus === 'Draft') {
                    $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
                    $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, 'Draft', 'In Production', 'First production entry submitted', ?)")
                       ->execute([$lotId, $_SESSION['user_id'] ?? null]);
                }

                // Update step status in lot_workflow_steps
                $stepStmt = $db->prepare("UPDATE lot_workflow_steps SET status = 'In Progress' WHERE lot_id = ? AND operation_id = ? AND status = 'Pending'");
                $stepStmt->execute([$lotId, $operationId]);

                log_audit('PRODUCTION_ENTRY', 'production_entries', $entryId, null, [
                    'lot_id' => $lotId,
                    'operation' => $opName,
                    'good_qty' => $goodQty,
                    'labour' => $labourAmount
                ]);

                $db->commit();

                // Construct feedback message
                $feedbackMsg = "Production entry saved! Good: $goodQty pcs | Labour: " . format_currency($labourAmount) . " credited to $workerName.";
                if ($cumulativeGood < $variantPlanned && $opName === 'Cutting') {
                    $missing = $variantPlanned - $cumulativeGood;
                    $feedbackMsg .= " Alert: $missing piece(s) shortage currently pending in Cutting.";
                }

                redirect("modules/production/index.php?lot_id=$lotId", $feedbackMsg);
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Error saving entry: ' . $e->getMessage();
            }
        }
    }
}
}

// Fetch active lots for dropdown
$lots = $db->query("SELECT l.id, l.lot_number, l.total_pieces, p.name AS product_name, d.design_number 
                   FROM lots l 
                   JOIN products p ON l.product_id = p.id 
                   LEFT JOIN designs d ON l.design_id = d.id 
                   WHERE l.status != 'Closed' 
                   ORDER BY l.id DESC")->fetchAll();

// Fetch active workers
$workers = $db->query("SELECT w.*, dep.name AS department_name 
                       FROM workers w 
                       LEFT JOIN departments dep ON w.department_id = dep.id 
                       WHERE w.is_active = 1 
                       ORDER BY w.name ASC")->fetchAll();

// Recent 10 entries for live history panel
$recentEntries = $db->query("SELECT pe.*, l.lot_number, o.name AS op_name, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                            FROM production_entries pe 
                            JOIN lots l ON pe.lot_id = l.id 
                            JOIN operations o ON pe.operation_id = o.id 
                            JOIN workers w ON pe.worker_id = w.id 
                            JOIN colors c ON pe.color_id = c.id 
                            JOIN sizes s ON pe.size_id = s.id 
                            ORDER BY pe.id DESC LIMIT 8")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Production Work Entry</h4>
                <p class="text-muted small mb-0">Record pieces produced by operators, calculate labour, track shortages & rejections</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/production/pending.php" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-exclamation-octagon"></i>
                    <span>Pending Operations</span>
                </a>
                <a href="<?= BASE_URL ?>modules/production/history.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-clock-history"></i>
                    <span>Full Log</span>
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Production Entry Main Form -->
            <div class="col-12 col-xl-8">
                <div class="card-modern shadow-sm">
                    <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary p-2 rounded-2"><i class="bi bi-lightning-charge-fill text-white fs-6"></i></span>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Work Entry Form</h6>
                                <span class="text-muted small">Auto-calculates labour and verifies batch quantities</span>
                            </div>
                        </div>

                        <!-- Mode Switcher -->
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-primary fw-semibold" id="btnModeMatrix">
                                <i class="bi bi-grid-3x3-gap-fill me-1"></i> ⚡ Matrix Grid Entry (Fast)
                            </button>
                            <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnModeSingle">
                                <i class="bi bi-list-check me-1"></i> Single Item
                            </button>
                        </div>
                    </div>
                    <div class="card-modern-body">
                        <form method="POST" action="" id="prodEntryForm">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="entry_mode" id="pe_entry_mode" value="matrix">

                            <div class="row g-3">
                                <!-- Common Fields: Date, Lot, Operation -->
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold">Entry Date *</label>
                                    <input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label fw-semibold">Select Lot Number *</label>
                                    <select name="lot_id" id="pe_lot_id" class="form-select fw-bold text-primary" required>
                                        <option value="">-- Choose Factory Lot --</option>
                                        <?php foreach ($lots as $l): ?>
                                            <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id'] || (isset($_POST['lot_id']) && $_POST['lot_id'] == $l['id'])) ? 'selected' : '' ?>>
                                                LOT #<?= clean($l['lot_number']) ?> — <?= clean($l['product_name']) ?> (<?= $l['design_number'] ? 'Design #' . clean($l['design_number']) : 'No Design' ?>) [<?= $l['total_pieces'] ?> pcs]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Select Operation *</label>
                                    <select name="operation_id" id="pe_operation_id" class="form-select fw-bold text-dark" required>
                                        <option value="">-- Select Lot First --</option>
                                    </select>
                                </div>

                                <!-- Worker & Piece Rate -->
                                <div class="col-12 col-md-6">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0">Worker / Operator *</label>
                                        <span id="workerCountBadge" class="badge bg-light text-secondary border small d-none">0 matching</span>
                                    </div>
                                    <select name="worker_id" id="pe_worker_id" class="form-select fw-semibold" required>
                                        <option value="">-- Select Operation First --</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Piece Rate (Rs.) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">Rs.</span>
                                        <input type="number" name="rate" id="pe_rate" class="form-control fw-bold" step="0.25" min="0" value="0.00" required>
                                    </div>
                                    <div class="form-text small">Auto-populated from operation rate; editable if exception applies.</div>
                                </div>
                            </div>

                            <!-- ================================================================= -->
                            <!-- SECTION 1: FAST COLOR X SIZE MATRIX GRID ENTRY (DEFAULT) -->
                            <!-- ================================================================= -->
                            <div id="matrixSection" class="mt-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-2 bg-light border rounded-3 mb-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-grid-3x3-gap-fill me-1"></i> Color × Size Distribution</span>
                                        <span class="text-muted small">Auto-calculates totals and artisan labour.</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" id="btnToggleQualityMode" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1" title="Toggle Rejection and Rework tracking per variant">
                                            <i class="bi bi-shield-exclamation"></i>
                                            <span id="lblQualityToggleText">Log Rejections & Rework</span>
                                        </button>
                                        <button type="button" id="btnFillAllPending" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                                            <i class="bi bi-lightning-fill"></i>
                                            <span>⚡ 1-Click Fill All Pending</span>
                                        </button>
                                        <button type="button" id="btnClearMatrix" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                                            <i class="bi bi-eraser"></i>
                                            <span>Reset</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Placeholder when lot or operation not selected -->
                                <div id="matrixPlaceholder" class="p-5 text-center text-muted border border-dashed rounded-3">
                                    <i class="bi bi-grid-3x3-gap display-5 text-secondary opacity-50 mb-2 d-block"></i>
                                    <h6 class="fw-semibold text-dark mb-1">Select Lot and Operation</h6>
                                    <p class="small text-muted mb-0">Choose a factory lot and production operation above to load the variant distribution matrix.</p>
                                </div>

                                <!-- Spinner when loading -->
                                <div id="matrixLoading" class="p-5 text-center text-muted border rounded-3 d-none">
                                    <div class="spinner-border text-primary mb-2" role="status"></div>
                                    <div class="small fw-semibold">Loading lot variants matrix...</div>
                                </div>

                                <!-- Matrix Table Container -->
                                <div id="matrixGridWrapper" class="d-none">
                                    <div class="table-responsive border rounded-3 shadow-sm bg-white mb-3" style="max-height: 480px; overflow-y: auto;">
                                        <table class="table table-bordered table-hover align-middle mb-0 text-center" id="matrixTable" style="font-size: 0.85rem;">
                                            <thead class="table-light sticky-top shadow-sm" id="matrixThead"></thead>
                                            <tbody id="matrixTbody"></tbody>
                                            <tfoot class="table-light sticky-bottom fw-bold" id="matrixTfoot"></tfoot>
                                        </table>
                                    </div>

                                    <!-- Live Summary Card -->
                                    <div class="card border border-primary-subtle bg-primary-subtle p-3 rounded-3 mb-3">
                                        <div class="row g-2 align-items-center text-center text-md-start">
                                            <div class="col-12 col-md-4">
                                                <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.72rem;">Batch Pieces To Save</div>
                                                <div class="fs-4 fw-bold text-primary">
                                                    <span id="lblBatchTotalPieces">0</span> <small class="fs-6 fw-normal text-muted">pcs good</small>
                                                    <span id="lblBatchDefectsPills" class="badge bg-danger-subtle text-danger border ms-1 d-none" style="font-size: 0.72rem;">0 Rej</span>
                                                    <span id="lblBatchReworkPills" class="badge bg-warning-subtle text-warning-emphasis border ms-1 d-none" style="font-size: 0.72rem;">0 Rew</span>
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.72rem;">Applied Piece Rate</div>
                                                <div class="fs-5 fw-bold text-dark">Rs. <span id="lblBatchRate">0.00</span> / pc</div>
                                            </div>
                                            <div class="col-12 col-md-4 text-md-end">
                                                <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.72rem;">Calculated Worker Labour</div>
                                                <div class="fs-3 fw-bold text-success" id="lblBatchTotalLabour">Rs. 0.00</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Quality Inspection Details (Activated when Quality Mode is toggled) -->
                                    <div id="qualityDetailsCard" class="card border border-warning-subtle bg-warning-subtle p-3 rounded-3 mb-3 d-none">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <i class="bi bi-shield-exclamation text-warning fs-5"></i>
                                            <h6 class="fw-bold mb-0 text-dark">Quality Inspection Details (Defects & Rework)</h6>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small fw-semibold mb-1">Rejection Reason</label>
                                                <select name="matrix_rejection_reason" id="pe_matrix_rej_reason" class="form-select form-select-sm">
                                                    <option value="Stitching defect / skipped stitches">Stitching defect / skipped stitches</option>
                                                    <option value="Fabric flaw / cut / needle hole">Fabric flaw / cut / needle hole</option>
                                                    <option value="Measurement out of tolerance">Measurement out of tolerance</option>
                                                    <option value="Oil stain / dirt mark">Oil stain / dirt mark</option>
                                                    <option value="Shade variation / color mismatch">Shade variation / color mismatch</option>
                                                    <option value="Ironing / burn mark">Ironing / burn mark</option>
                                                    <option value="Other quality defect">Other quality defect</option>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small fw-semibold mb-1">Rework Instructions</label>
                                                <input type="text" name="matrix_rework_notes" id="pe_matrix_rew_notes" class="form-control form-control-sm" placeholder="e.g. Needs alteration, seam unpicking, or touchup">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold">Batch Remarks / Discrepancy Note</label>
                                        <input type="text" name="notes" id="pe_matrix_notes" class="form-control" placeholder="Optional batch notes or details">
                                    </div>

                                    <div id="matrixValidationAlert" class="alert alert-danger py-2 small d-none mb-3">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <span id="matrixValidationText"></span>
                                    </div>

                                    <button type="submit" class="btn btn-primary btn-lg w-100 py-3 fw-bold shadow-sm" id="btnSubmitBatch">
                                        <i class="bi bi-check2-circle me-1"></i> Save Batch Production Entry (<span id="lblSubmitPcs">0</span> pcs)
                                    </button>
                                </div>
                            </div>

                            <!-- ================================================================= -->
                            <!-- SECTION 2: SINGLE VARIANT ITEM ENTRY (OPTIONAL) -->
                            <!-- ================================================================= -->
                            <div id="singleSection" class="mt-4 d-none">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Color & Size Variant *</label>
                                        <select name="variant_key" id="pe_variant_key" class="form-select">
                                            <option value="">-- Select Operation First --</option>
                                        </select>
                                    </div>

                                    <!-- Live Stage Balance Pill -->
                                    <div class="col-12 d-none" id="stageBalanceAlert">
                                        <div class="p-3 bg-light border rounded-3 d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                                            <div>Planned Variant Qty: <strong id="lblPlanned" class="text-dark">0</strong></div>
                                            <div>Already Good Done: <strong id="lblDone" class="text-success">0</strong></div>
                                            <div>Pending in Stage: <strong id="lblPending" class="text-warning-emphasis fw-bold">0</strong></div>
                                        </div>
                                    </div>

                                    <!-- Quantities breakdown -->
                                    <div class="col-6 col-sm-3">
                                        <label class="form-label text-success fw-bold">Good Produced *</label>
                                        <input type="number" name="good_qty" id="pe_good_qty" class="form-control form-control-lg fw-bold text-success text-center" min="0" placeholder="0">
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <label class="form-label text-danger">Rejected Pcs</label>
                                        <input type="number" name="rejected_qty" id="pe_rejected_qty" class="form-control form-control-lg text-danger text-center" min="0" value="0">
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <label class="form-label text-warning">Rework Pcs</label>
                                        <input type="number" name="rework_qty" id="pe_rework_qty" class="form-control form-control-lg text-warning text-center" min="0" value="0">
                                    </div>
                                    <div class="col-6 col-sm-3">
                                        <label class="form-label text-muted">Total Received</label>
                                        <input type="number" name="qty_received" id="pe_qty_received" class="form-control form-control-lg text-center" min="0" placeholder="Auto">
                                    </div>

                                    <div class="col-12">
                                        <div class="p-2 border rounded-2 bg-light text-end">
                                            <div class="fs-4 fw-bold text-primary" id="lblLabourAmount">Rs. 0.00</div>
                                            <div class="small text-muted">Good Qty × Rate</div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Remarks / Discrepancy Note</label>
                                        <input type="text" name="notes_single" id="pe_notes_single" class="form-control" placeholder="Optional operator remarks or shortage reason">
                                    </div>

                                    <div class="col-12 pt-2">
                                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm" id="btnSaveSingle">
                                            <i class="bi bi-check2-circle me-1"></i> Save Single Production Entry
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Entries Live Panel -->
            <div class="col-12 col-xl-4">
                <div class="card-modern h-100 shadow-sm">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Recent Submissions</h6>
                            <span class="text-muted small">Live factory stream</span>
                        </div>
                        <a href="<?= BASE_URL ?>modules/production/history.php" class="btn btn-sm btn-outline-secondary">View All</a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0" style="font-size: 0.82rem;">
                                <thead>
                                    <tr>
                                        <th>Lot / Op</th>
                                        <th>Worker</th>
                                        <th class="text-center">Good</th>
                                        <th class="text-end">Labour</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentEntries)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No entries yet today.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentEntries as $re): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-primary">#<?= clean($re['lot_number']) ?></span>
                                                    <div><?= clean($re['op_name']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.72rem;"><?= clean($re['color_name']) ?> (<?= clean($re['size_name']) ?>)</div>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark"><?= clean($re['worker_name']) ?></span>
                                                    <div class="text-muted" style="font-size: 0.72rem;"><?= format_date($re['entry_date']) ?></div>
                                                </td>
                                                <td class="text-center fw-bold text-success">
                                                    <?= number_format($re['good_qty']) ?>
                                                    <?php if ($re['rejected_qty'] > 0): ?>
                                                        <div class="text-danger small" style="font-size: 0.7rem;"><?= $re['rejected_qty'] ?> rej</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark">
                                                    <?= format_currency($re['labour_amount']) ?>
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
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const lotSelect = document.getElementById('pe_lot_id');
    const opSelect = document.getElementById('pe_operation_id');
    const workerSelect = document.getElementById('pe_worker_id');
    const workerBadge = document.getElementById('workerCountBadge');
    const rateInput = document.getElementById('pe_rate');
    const preselectedOpId = <?= (int)$preselectedOpId ?>;

    // Mode Toggle Elements
    const btnModeMatrix = document.getElementById('btnModeMatrix');
    const btnModeSingle = document.getElementById('btnModeSingle');
    const modeInput = document.getElementById('pe_entry_mode');
    const matrixSection = document.getElementById('matrixSection');
    const singleSection = document.getElementById('singleSection');

    // Single Mode Elements
    const varSelect = document.getElementById('pe_variant_key');
    const goodInput = document.getElementById('pe_good_qty');
    const rejInput = document.getElementById('pe_rejected_qty');
    const rewInput = document.getElementById('pe_rework_qty');
    const recInput = document.getElementById('pe_qty_received');
    const labourDisplay = document.getElementById('lblLabourAmount');

    // Matrix Mode Elements
    const matrixPlaceholder = document.getElementById('matrixPlaceholder');
    const matrixLoading = document.getElementById('matrixLoading');
    const matrixGridWrapper = document.getElementById('matrixGridWrapper');
    const matrixThead = document.getElementById('matrixThead');
    const matrixTbody = document.getElementById('matrixTbody');
    const matrixTfoot = document.getElementById('matrixTfoot');
    const btnFillAllPending = document.getElementById('btnFillAllPending');
    const btnClearMatrix = document.getElementById('btnClearMatrix');

    // Toggle Modes
    btnModeMatrix.addEventListener('click', function() {
        modeInput.value = 'matrix';
        btnModeMatrix.className = 'btn btn-primary fw-semibold';
        btnModeSingle.className = 'btn btn-outline-secondary fw-semibold';
        matrixSection.classList.remove('d-none');
        singleSection.classList.add('d-none');
        goodInput.removeAttribute('required');
        varSelect.removeAttribute('required');
    });

    btnModeSingle.addEventListener('click', function() {
        modeInput.value = 'single';
        btnModeSingle.className = 'btn btn-primary fw-semibold';
        btnModeMatrix.className = 'btn btn-outline-secondary fw-semibold';
        singleSection.classList.remove('d-none');
        matrixSection.classList.add('d-none');
        goodInput.setAttribute('required', 'required');
        varSelect.setAttribute('required', 'required');
        checkBalance();
    });

    function calculateSingleLabour() {
        const good = parseInt(goodInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        const total = good * rate;
        labourDisplay.textContent = 'Rs. ' + total.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const rej = parseInt(rejInput.value) || 0;
        const rew = parseInt(rewInput.value) || 0;
        if (!recInput.value || parseInt(recInput.value) === 0) {
            recInput.placeholder = good + rej + rew;
        }
    }

    goodInput.addEventListener('input', calculateSingleLabour);
    rejInput.addEventListener('input', calculateSingleLabour);
    rewInput.addEventListener('input', calculateSingleLabour);
    rateInput.addEventListener('input', function() {
        calculateSingleLabour();
        recalculateMatrixTotals();
    });

    // Load operations and variants for selected lot
    function loadLotData(lotId, callback) {
        if (!lotId) {
            opSelect.innerHTML = '<option value="">-- Select Lot First --</option>';
            workerSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
            if (workerBadge) workerBadge.classList.add('d-none');
            varSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
            matrixPlaceholder.classList.remove('d-none');
            matrixLoading.classList.add('d-none');
            matrixGridWrapper.classList.add('d-none');
            return;
        }

        workerSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
        if (workerBadge) workerBadge.classList.add('d-none');

        fetch('<?= BASE_URL ?>modules/production/ajax.php?action=get_lot_operations_and_variants&lot_id=' + lotId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Populate operations
                    opSelect.innerHTML = '<option value="">-- Choose Operation --</option>';
                    data.operations.forEach(op => {
                        const opt = document.createElement('option');
                        opt.value = op.operation_id;
                        opt.dataset.rate = op.default_rate;
                        opt.dataset.goodDone = op.good_done || 0;
                        const goodDone = parseInt(op.good_done) || 0;
                        const lotTotal = parseInt(data.lot.total_pieces) || 0;
                        const isCompleted = (goodDone >= lotTotal && lotTotal > 0);
                        opt.textContent = op.sequence + '. ' + op.op_name + ' (' + op.dept_name + ') - Rs. ' + parseFloat(op.default_rate).toFixed(2) + 
                                          (isCompleted ? ' [✓ Completed: ' + goodDone + '/' + lotTotal + ']' : ' [' + goodDone + '/' + lotTotal + ' pcs]');
                        opSelect.appendChild(opt);
                    });

                    // Populate single-mode variants
                    varSelect.innerHTML = '<option value="">-- Choose Color & Size --</option>';
                    data.variants.forEach(v => {
                        const opt = document.createElement('option');
                        opt.value = v.color_id + '_' + v.size_id;
                        opt.dataset.color = v.color_id;
                        opt.dataset.size = v.size_id;
                        opt.dataset.planned = v.quantity;
                        opt.textContent = v.color_name + ' / ' + v.size_name + ' (' + v.size_code + ') — Planned: ' + v.quantity + ' pcs';
                        varSelect.appendChild(opt);
                    });

                    // Auto-select operation if preselected in URL
                    if (preselectedOpId && data.operations.some(op => op.operation_id == preselectedOpId)) {
                        opSelect.value = preselectedOpId;
                        const selectedOpt = opSelect.options[opSelect.selectedIndex];
                        if (selectedOpt && selectedOpt.dataset.rate) {
                            rateInput.value = selectedOpt.dataset.rate;
                            calculateSingleLabour();
                        }
                        loadWorkersForOperation(preselectedOpId);
                        loadMatrixGrid(lotId, preselectedOpId);
                        checkBalance();
                    }

                    if (callback) callback();
                }
            })
            .catch(err => console.error(err));
    }

    lotSelect.addEventListener('change', function() {
        loadLotData(this.value);
        matrixPlaceholder.classList.remove('d-none');
        matrixLoading.classList.add('d-none');
        matrixGridWrapper.classList.add('d-none');
    });

    if (lotSelect.value) {
        loadLotData(lotSelect.value);
    }

    function loadWorkersForOperation(opId) {
        if (!opId) {
            workerSelect.innerHTML = '<option value="">-- Select Operation First --</option>';
            if (workerBadge) workerBadge.classList.add('d-none');
            return;
        }

        workerSelect.innerHTML = '<option value="">Loading related artisans...</option>';
        if (workerBadge) {
            workerBadge.textContent = 'Loading...';
            workerBadge.className = 'badge bg-light text-secondary border small';
            workerBadge.classList.remove('d-none');
        }

        fetch('<?= BASE_URL ?>modules/production/ajax.php?action=get_operation_workers&operation_id=' + opId)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    workerSelect.innerHTML = '<option value="">-- Choose Artisan --</option>';
                    const count = data.workers ? data.workers.length : 0;
                    if (workerBadge) {
                        workerBadge.textContent = count + ' related artisan' + (count === 1 ? '' : 's');
                        workerBadge.className = 'badge bg-primary-subtle text-primary border small';
                        workerBadge.classList.remove('d-none');
                    }

                    data.workers.forEach(w => {
                        const opt = document.createElement('option');
                        opt.value = w.id;
                        opt.dataset.rate = w.effective_rate || '';
                        opt.textContent = w.name + ' (' + w.code + ') — ' + (w.department_name || 'Worker');
                        workerSelect.appendChild(opt);
                    });

                    // Auto-select if exactly 1 matching artisan
                    if (count === 1) {
                        workerSelect.value = data.workers[0].id;
                        if (data.workers[0].effective_rate && parseFloat(data.workers[0].effective_rate) > 0) {
                            rateInput.value = parseFloat(data.workers[0].effective_rate).toFixed(2);
                            calculateSingleLabour();
                            recalculateMatrixTotals();
                        }
                    }
                }
            })
            .catch(err => {
                console.error(err);
                workerSelect.innerHTML = '<option value="">-- Choose Artisan --</option>';
            });
    }

    workerSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.rate && parseFloat(opt.dataset.rate) > 0) {
            rateInput.value = parseFloat(opt.dataset.rate).toFixed(2);
            calculateSingleLabour();
            recalculateMatrixTotals();
        }
    });

    // When operation changes, update default rate, load workers, and load matrix grid!
    opSelect.addEventListener('change', function() {
        const selectedOpt = this.options[this.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.rate) {
            rateInput.value = selectedOpt.dataset.rate;
            calculateSingleLabour();
        }
        loadWorkersForOperation(this.value);
        loadMatrixGrid(lotSelect.value, this.value);
        checkBalance();
    });

    varSelect.addEventListener('change', function() {
        checkBalance();
    });

    function checkBalance() {
        const lotId = lotSelect.value;
        const opId = opSelect.value;
        const varVal = varSelect.value;

        if (!lotId || !opId || !varVal) {
            document.getElementById('stageBalanceAlert').classList.add('d-none');
            return;
        }

        const [colorId, sizeId] = varVal.split('_');

        fetch(`<?= BASE_URL ?>modules/production/ajax.php?action=get_variant_stage_balance&lot_id=${lotId}&operation_id=${opId}&color_id=${colorId}&size_id=${sizeId}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const balBox = document.getElementById('stageBalanceAlert');
                    balBox.classList.remove('d-none');
                    document.getElementById('lblPlanned').textContent = data.planned + ' pcs';
                    document.getElementById('lblDone').textContent = data.good_done + ' pcs';
                    document.getElementById('lblPending').textContent = data.pending + ' pcs';

                    const btnSaveSingle = document.getElementById('btnSaveSingle');
                    if (data.pending <= 0) {
                        goodInput.value = 0;
                        goodInput.disabled = true;
                        if (btnSaveSingle) {
                            btnSaveSingle.disabled = true;
                            btnSaveSingle.innerHTML = '<i class="bi bi-slash-circle me-1"></i> Variant 100% Completed (Limit Reached)';
                            btnSaveSingle.classList.remove('btn-primary');
                            btnSaveSingle.classList.add('btn-secondary');
                        }
                    } else {
                        goodInput.disabled = false;
                        goodInput.max = data.pending;
                        if (btnSaveSingle) {
                            btnSaveSingle.disabled = false;
                            btnSaveSingle.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save Single Production Entry';
                            btnSaveSingle.classList.remove('btn-secondary');
                            btnSaveSingle.classList.add('btn-primary');
                        }
                        if (!goodInput.value || parseInt(goodInput.value) === 0) {
                            goodInput.placeholder = data.pending;
                        }
                    }
                }
            })
            .catch(err => console.error(err));
    }

    // =========================================================================
    // COLOR X SIZE MATRIX GRID ENGINE & QUALITY (REJECTIONS/REWORK) MODE
    // =========================================================================
    let isQualityModeActive = false;
    const btnToggleQualityMode = document.getElementById('btnToggleQualityMode');

    if (btnToggleQualityMode) {
        btnToggleQualityMode.addEventListener('click', function() {
            isQualityModeActive = !isQualityModeActive;
            updateQualityModeUI();
        });
    }

    function updateQualityModeUI() {
        if (!btnToggleQualityMode) return;
        const qualityDetailsCard = document.getElementById('qualityDetailsCard');
        const qualityToggleText = document.getElementById('lblQualityToggleText');

        if (isQualityModeActive) {
            btnToggleQualityMode.className = 'btn btn-warning btn-sm d-flex align-items-center gap-1 fw-semibold shadow-sm';
            if (qualityToggleText) qualityToggleText.textContent = 'Hide Quality Inputs';
            document.querySelectorAll('.quality-prefix').forEach(el => el.classList.remove('d-none'));
            document.querySelectorAll('.quality-inputs').forEach(el => el.classList.remove('d-none'));
            if (qualityDetailsCard) qualityDetailsCard.classList.remove('d-none');
        } else {
            btnToggleQualityMode.className = 'btn btn-outline-warning btn-sm d-flex align-items-center gap-1';
            if (qualityToggleText) qualityToggleText.textContent = 'Log Rejections & Rework';
            document.querySelectorAll('.quality-prefix').forEach(el => el.classList.add('d-none'));
            document.querySelectorAll('.quality-inputs').forEach(el => el.classList.add('d-none'));

            let hasQualityData = false;
            document.querySelectorAll('.cell-rejected, .cell-rework').forEach(inp => {
                if (parseInt(inp.value) > 0) hasQualityData = true;
            });
            if (qualityDetailsCard && !hasQualityData) {
                qualityDetailsCard.classList.add('d-none');
            }
        }
    }

    function loadMatrixGrid(lotId, opId) {
        if (!lotId || !opId) {
            matrixPlaceholder.classList.remove('d-none');
            matrixLoading.classList.add('d-none');
            matrixGridWrapper.classList.add('d-none');
            return;
        }

        matrixPlaceholder.classList.add('d-none');
        matrixLoading.classList.remove('d-none');
        matrixGridWrapper.classList.add('d-none');

        fetch(`<?= BASE_URL ?>modules/production/ajax.php?action=get_lot_stage_matrix&lot_id=${lotId}&operation_id=${opId}`)
            .then(res => res.json())
            .then(data => {
                matrixLoading.classList.add('d-none');
                if (data.status === 'success') {
                    window.activeLotTotalPieces = data.lot ? (parseInt(data.lot.total_pieces) || 0) : 0;
                    window.activeStageGoodDone = data.totals ? (parseInt(data.totals.good_done) || 0) : 0;
                    renderMatrixTable(data);
                    matrixGridWrapper.classList.remove('d-none');
                } else {
                    matrixPlaceholder.classList.remove('d-none');
                    matrixPlaceholder.innerHTML = `<i class="bi bi-exclamation-triangle text-warning display-5 mb-2 d-block"></i><div class="text-danger fw-semibold">${data.message || 'Failed to load matrix'}</div>`;
                }
            })
            .catch(err => {
                matrixLoading.classList.add('d-none');
                matrixPlaceholder.classList.remove('d-none');
                console.error('Matrix load error:', err);
            });
    }

    function renderMatrixTable(data) {
        const colors = data.colors;
        const sizes = data.sizes;
        const grid = data.grid;
        const totals = data.totals;
        const isStageFullyComplete = (totals.pending <= 0);

        let stageNoticeEl = document.getElementById('matrixStageNotice');
        if (!stageNoticeEl) {
            stageNoticeEl = document.createElement('div');
            stageNoticeEl.id = 'matrixStageNotice';
            const wrapper = document.getElementById('matrixGridWrapper');
            if (wrapper) wrapper.insertBefore(stageNoticeEl, wrapper.firstChild);
        }

        if (isStageFullyComplete) {
            stageNoticeEl.className = 'alert alert-success d-flex flex-wrap align-items-center justify-content-between p-3 mb-3 border rounded-3 shadow-sm';
            stageNoticeEl.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill text-success fs-3"></i>
                    <div>
                        <div class="fw-bold text-dark">Stage 100% Completed</div>
                        <div class="small text-muted">All ${totals.planned} pieces assigned to this lot have been produced. Further production entries are restricted to enforce lot limits.</div>
                    </div>
                </div>
                <span class="badge bg-success fs-6 px-3 py-2">${totals.good_done} / ${totals.planned} Pcs</span>
            `;
            stageNoticeEl.classList.remove('d-none');
            const fillBtn = document.getElementById('btnFillAllPending');
            const subBtn = document.getElementById('btnSubmitBatch');
            if (fillBtn) fillBtn.disabled = true;
            if (subBtn) {
                subBtn.disabled = true;
                subBtn.classList.remove('btn-primary');
                subBtn.classList.add('btn-secondary');
            }
        } else {
            stageNoticeEl.classList.add('d-none');
            const fillBtn = document.getElementById('btnFillAllPending');
            if (fillBtn) fillBtn.disabled = false;
        }

        // Header: Color \ Size | S | M | L ... | Color Total
        let thHtml = '<tr><th class="text-start ps-3 py-2 bg-light text-dark" style="min-width: 140px;">Color \\ Size</th>';
        sizes.forEach(s => {
            thHtml += `<th class="py-2 text-dark" style="min-width: 105px;">
                <span class="badge bg-dark-subtle text-dark border px-2">${s.code}</span>
                <div class="small fw-normal text-muted" style="font-size: 0.75rem;">${s.name}</div>
            </th>`;
        });
        thHtml += '<th class="py-2 bg-light text-primary" style="min-width: 100px;">Color Total</th></tr>';
        matrixThead.innerHTML = thHtml;

        // Body: Rows of Colors
        let tbHtml = '';
        colors.forEach(c => {
            let colorPlannedTotal = 0;
            tbHtml += `<tr>
                <td class="text-start ps-3 fw-semibold text-dark">
                    <span class="d-inline-block rounded-circle me-1 border" style="width: 12px; height: 12px; background-color: ${c.hex_code || '#6c757d'}; vertical-align: middle;"></span>
                    ${c.name}
                </td>`;

            sizes.forEach(s => {
                const cell = (grid[c.id] && grid[c.id][s.id]) ? grid[c.id][s.id] : null;
                if (cell && cell.planned > 0) {
                    colorPlannedTotal += cell.planned;
                    if (cell.pending > 0) {
                        tbHtml += `<td class="p-2 position-relative cell-variant" data-color="${c.id}" data-size="${s.id}" data-pending="${cell.pending}" data-planned="${cell.planned}">
                            <div class="good-wrap">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text px-1 py-0 small bg-success-subtle text-success fw-bold quality-prefix ${isQualityModeActive ? '' : 'd-none'}" title="Good Pieces Produced">G</span>
                                    <input type="number" min="0" max="${cell.pending}" 
                                           class="form-control form-control-sm text-center fw-bold matrix-cell-input cell-good text-success" 
                                           name="matrix[${c.id}][${s.id}][good]" 
                                           data-type="good" 
                                           data-color="${c.id}" 
                                           data-size="${s.id}" 
                                           data-pending="${cell.pending}" 
                                           data-planned="${cell.planned}" 
                                           value="0">
                                </div>
                            </div>
                            
                            <div class="quality-inputs ${isQualityModeActive ? '' : 'd-none'} mt-1 d-flex flex-column gap-1">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text px-1 py-0 small bg-danger-subtle text-danger fw-bold" title="Rejected (Scrap) Pieces">✕</span>
                                    <input type="number" min="0" max="${cell.pending}" 
                                           class="form-control form-control-sm text-center matrix-cell-input cell-rejected text-danger fw-semibold" 
                                           name="matrix[${c.id}][${s.id}][rejected]" 
                                           data-type="rejected" 
                                           data-color="${c.id}" 
                                           data-size="${s.id}" 
                                           data-pending="${cell.pending}" 
                                           data-planned="${cell.planned}" 
                                           value="0" placeholder="0">
                                </div>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text px-1 py-0 small bg-warning-subtle text-warning-emphasis fw-bold" title="Rework Pieces">↻</span>
                                    <input type="number" min="0" max="${cell.pending}" 
                                           class="form-control form-control-sm text-center matrix-cell-input cell-rework text-warning-emphasis fw-semibold" 
                                           name="matrix[${c.id}][${s.id}][rework]" 
                                           data-type="rework" 
                                           data-color="${c.id}" 
                                           data-size="${s.id}" 
                                           data-pending="${cell.pending}" 
                                           data-planned="${cell.planned}" 
                                           value="0" placeholder="0">
                                </div>
                            </div>

                            <div class="cell-status small text-muted mt-1" style="font-size: 0.72rem; line-height: 1.1;">
                                <span class="badge bg-light text-secondary border">Pending: <strong>${cell.pending}</strong>/${cell.planned}</span>
                            </div>
                        </td>`;
                    } else {
                        tbHtml += `<td class="p-2 bg-light-subtle">
                            <input type="number" min="0" max="0" 
                                   class="form-control form-control-sm text-center matrix-cell-input bg-light text-muted border-0" 
                                   name="matrix[${c.id}][${s.id}][good]" 
                                   data-color="${c.id}" 
                                   data-size="${s.id}" 
                                   data-pending="0" 
                                   data-planned="${cell.planned}" 
                                   value="0" readonly>
                            <div class="mt-1" style="font-size: 0.7rem;">
                                <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle-fill"></i> Done (${cell.good_done})</span>
                            </div>
                        </td>`;
                    }
                } else {
                    tbHtml += `<td class="bg-light opacity-50 text-muted">—</td>`;
                }
            });

            tbHtml += `<td class="fw-bold text-primary bg-light" id="row_tot_${c.id}" data-planned="${colorPlannedTotal}">
                <span class="row-sum">0</span> <small class="text-muted fw-normal">/ ${colorPlannedTotal}</small>
            </td></tr>`;
        });
        matrixTbody.innerHTML = tbHtml;

        // Footer: Size Totals & Grand Total
        let tfHtml = '<tr><td class="text-start ps-3 fw-bold bg-light">Size Total</td>';
        sizes.forEach(s => {
            let sizePlannedTotal = 0;
            colors.forEach(c => {
                const cell = (grid[c.id] && grid[c.id][s.id]) ? grid[c.id][s.id] : null;
                if (cell && cell.planned > 0) sizePlannedTotal += cell.planned;
            });
            tfHtml += `<td class="fw-bold text-primary bg-light" id="col_tot_${s.id}" data-planned="${sizePlannedTotal}">
                <span class="col-sum">0</span> <small class="text-muted fw-normal">/ ${sizePlannedTotal}</small>
            </td>`;
        });
        tfHtml += `<td class="fw-bold fs-6 text-primary bg-primary-subtle" id="grand_matrix_cell">
            <span id="grandMatrixSum">0</span> <small class="text-muted fw-normal">/ ${totals.planned}</small>
        </td></tr>`;
        matrixTfoot.innerHTML = tfHtml;

        // Bind interactive events
        document.querySelectorAll('.matrix-cell-input').forEach(inp => {
            inp.addEventListener('input', recalculateMatrixTotals);
            inp.addEventListener('focus', function() { this.select(); });
        });

        recalculateMatrixTotals();
    }

    function recalculateMatrixTotals() {
        let grandGood = 0;
        let grandRej = 0;
        let grandRew = 0;
        let grandTotal = 0;
        let hasError = false;
        let errorMsg = '';

        const rate = parseFloat(rateInput.value) || 0;
        const rateDisplay = document.getElementById('lblBatchRate');
        if (rateDisplay) rateDisplay.textContent = rate.toFixed(2);

        const rowSums = {};
        const colSums = {};

        // Iterate per variant cell
        document.querySelectorAll('.cell-variant').forEach(cell => {
            const pending = parseInt(cell.dataset.pending) || 0;
            const cId = cell.dataset.color;
            const sId = cell.dataset.size;

            const goodInp = cell.querySelector('.cell-good');
            const rejInp = cell.querySelector('.cell-rejected');
            const rewInp = cell.querySelector('.cell-rework');

            const goodVal = Math.max(0, parseInt(goodInp ? goodInp.value : 0) || 0);
            const rejVal = Math.max(0, parseInt(rejInp ? rejInp.value : 0) || 0);
            const rewVal = Math.max(0, parseInt(rewInp ? rewInp.value : 0) || 0);
            const cellTotal = goodVal + rejVal + rewVal;

            if (!rowSums[cId]) rowSums[cId] = 0;
            if (!colSums[sId]) colSums[sId] = 0;

            if (cellTotal > pending && pending > 0) {
                if (goodInp) goodInp.classList.add('is-invalid');
                if (rejInp) rejInp.classList.add('is-invalid');
                if (rewInp) rewInp.classList.add('is-invalid');
                cell.style.backgroundColor = '#ffebee';
                hasError = true;
                errorMsg = `Some cells exceed pending piece limits (highlighted in red).`;
            } else {
                if (goodInp) goodInp.classList.remove('is-invalid');
                if (rejInp) rejInp.classList.remove('is-invalid');
                if (rewInp) rewInp.classList.remove('is-invalid');
                cell.style.backgroundColor = cellTotal > 0 ? '#ecfdf5' : '';
            }

            rowSums[cId] += cellTotal;
            colSums[sId] += cellTotal;
            grandGood += goodVal;
            grandRej += rejVal;
            grandRew += rewVal;
            grandTotal += cellTotal;
        });

        // Overall Stage Capacity check against Lot Total Pieces
        const lotMax = window.activeLotTotalPieces || 0;
        const curDone = window.activeStageGoodDone || 0;
        if (lotMax > 0 && (curDone + grandGood) > lotMax) {
            hasError = true;
            const overPcs = (curDone + grandGood) - lotMax;
            const allowed = Math.max(0, lotMax - curDone);
            errorMsg = `Stage production cannot exceed lot capacity of ${lotMax} pcs! Already completed: ${curDone} pcs. Maximum allowed to add now is ${allowed} pcs (attempted: ${grandGood} pcs, exceeding by ${overPcs} pcs).`;
        }

        // Update row sums
        Object.keys(rowSums).forEach(cId => {
            const cell = document.getElementById('row_tot_' + cId);
            if (cell) {
                const sumEl = cell.querySelector('.row-sum');
                if (sumEl) sumEl.textContent = rowSums[cId];
            }
        });

        // Update col sums
        Object.keys(colSums).forEach(sId => {
            const cell = document.getElementById('col_tot_' + sId);
            if (cell) {
                const sumEl = cell.querySelector('.col-sum');
                if (sumEl) sumEl.textContent = colSums[sId];
            }
        });

        // Update Grand Sum
        const grandSumEl = document.getElementById('grandMatrixSum');
        if (grandSumEl) grandSumEl.textContent = grandTotal;

        // Update Summary Banner
        const batchPieces = document.getElementById('lblBatchTotalPieces');
        const submitPcs = document.getElementById('lblSubmitPcs');
        if (batchPieces) batchPieces.textContent = grandGood.toLocaleString();
        if (submitPcs) submitPcs.textContent = grandGood.toLocaleString();

        // Update Quality Pills in Summary
        const rejPill = document.getElementById('lblBatchDefectsPills');
        const rewPill = document.getElementById('lblBatchReworkPills');
        const qualityDetailsCard = document.getElementById('qualityDetailsCard');

        if (rejPill) {
            if (grandRej > 0) {
                rejPill.textContent = grandRej + ' Rej';
                rejPill.classList.remove('d-none');
            } else {
                rejPill.classList.add('d-none');
            }
        }
        if (rewPill) {
            if (grandRew > 0) {
                rewPill.textContent = grandRew + ' Rew';
                rewPill.classList.remove('d-none');
            } else {
                rewPill.classList.add('d-none');
            }
        }

        // Auto-reveal Quality Details Card if defects/rework are entered
        if ((grandRej > 0 || grandRew > 0) && qualityDetailsCard) {
            qualityDetailsCard.classList.remove('d-none');
        }

        // Worker Labour is calculated ONLY on good pieces
        const batchLabour = grandGood * rate;
        const labourDisplay = document.getElementById('lblBatchTotalLabour');
        if (labourDisplay) {
            labourDisplay.textContent = 'Rs. ' + batchLabour.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        // Validation alert & submit button
        const alertBox = document.getElementById('matrixValidationAlert');
        const alertText = document.getElementById('matrixValidationText');
        const submitBtn = document.getElementById('btnSubmitBatch');

        if (alertBox && alertText && submitBtn) {
            if (hasError) {
                alertBox.classList.remove('d-none');
                alertText.textContent = errorMsg;
                submitBtn.disabled = true;
                submitBtn.classList.remove('btn-primary');
                submitBtn.classList.add('btn-secondary');
            } else {
                alertBox.classList.add('d-none');
                submitBtn.disabled = false;
                submitBtn.classList.remove('btn-secondary');
                submitBtn.classList.add('btn-primary');
            }
        }
    }

    // 1-Click Fill All Pending Button
    btnFillAllPending.addEventListener('click', function() {
        document.querySelectorAll('.cell-variant').forEach(cell => {
            const pending = parseInt(cell.dataset.pending) || 0;
            const rejInp = cell.querySelector('.cell-rejected');
            const rewInp = cell.querySelector('.cell-rework');
            const goodInp = cell.querySelector('.cell-good');
            
            const rejVal = parseInt(rejInp ? rejInp.value : 0) || 0;
            const rewVal = parseInt(rewInp ? rewInp.value : 0) || 0;
            const goodTarget = Math.max(0, pending - rejVal - rewVal);

            if (goodInp && !goodInp.readOnly) {
                goodInp.value = goodTarget;
            }
        });
        recalculateMatrixTotals();
    });

    // Reset All Button
    btnClearMatrix.addEventListener('click', function() {
        document.querySelectorAll('.matrix-cell-input').forEach(inp => {
            if (!inp.readOnly) {
                inp.value = 0;
            }
        });
        recalculateMatrixTotals();
    });

    // Intercept form submission for validation
    document.getElementById('prodEntryForm').addEventListener('submit', function(e) {
        const mode = document.getElementById('pe_entry_mode').value;
        const workerId = document.getElementById('pe_worker_id').value;

        if (!workerId) {
            e.preventDefault();
            alert('Please select a Worker / Operator before saving.');
            document.getElementById('pe_worker_id').focus();
            return;
        }

        if (mode === 'matrix') {
            const grandTotal = parseInt(document.getElementById('grandMatrixSum').textContent.replace(/,/g, '')) || 0;
            if (grandTotal <= 0) {
                e.preventDefault();
                alert('Please enter at least 1 piece to record, or click "⚡ 1-Click Fill All Pending".');
                return;
            }

            const hasInvalid = document.querySelector('.matrix-cell-input.is-invalid');
            if (hasInvalid) {
                e.preventDefault();
                alert('Please fix cells that exceed pending pieces before saving.');
                hasInvalid.focus();
                return;
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
