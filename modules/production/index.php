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
$preselectedMode = trim($_GET['mode'] ?? '');
$error = '';

// Handle Production Work Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $entryMode = trim($_POST['entry_mode'] ?? 'matrix');

    // =========================================================================
    // 1. OPERATOR INITIAL PRODUCTION ENTRY MODE (FIRST STAGE - NO COLOR REQUIRED)
    // =========================================================================
    if ($entryMode === 'operator_initial') {
        $lotId = (int)($_POST['lot_id'] ?? 0);
        $operationId = (int)($_POST['operation_id'] ?? 0);
        $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
        $shiftType = in_array($_POST['shift_type'] ?? '', ['Single Shift', 'Double Shift']) ? $_POST['shift_type'] : 'Single Shift';
        $notes = trim($_POST['notes'] ?? 'Operator initial production run');

        // Day Operator
        $dayWorkerId = (int)($_POST['day_worker_id'] ?? 0);
        $dayPieces = (int)($_POST['day_pieces'] ?? 0);
        $dayRate = (float)($_POST['day_rate'] ?? 0.00);
        $dayMinRate = (float)($_POST['day_worker_min_rate'] ?? 2.50);
        $dayMins = (float)($_POST['day_minutes_per_piece'] ?? 0.00);
        if ($dayRate <= 0 && $dayMins > 0) {
            $dayRate = round($dayMins * $dayMinRate, 2);
        }

        // Night Operator (Double Shift)
        $nightWorkerId = (int)($_POST['night_worker_id'] ?? 0);
        $nightPieces = (int)($_POST['night_pieces'] ?? 0);
        $nightRate = (float)($_POST['night_rate'] ?? 0.00);
        $nightMinRate = (float)($_POST['night_worker_min_rate'] ?? 2.50);
        $nightMins = (float)($_POST['night_minutes_per_piece'] ?? 0.00);
        if ($nightRate <= 0 && $nightMins > 0) {
            $nightRate = round($nightMins * $nightMinRate, 2);
        }

        // Sizing
        $sizeMode = trim($_POST['size_mode'] ?? 'Single Size');
        $singleSizeId = !empty($_POST['single_size_id']) ? (int)$_POST['single_size_id'] : null;
        $daySizes = $_POST['day_sizes'] ?? [];
        $nightSizes = $_POST['night_sizes'] ?? [];

        if ($lotId <= 0) {
            $error = 'Please select a production lot.';
        } else {
            // Verify lot & enforce shift type
            $lQuery = $db->prepare("SELECT lot_number, status, total_pieces, single_size_id, shift_type FROM lots WHERE id = ?");
            $lQuery->execute([$lotId]);
            $lotRow = $lQuery->fetch();

            if (!$lotRow) {
                $error = 'Selected lot not found.';
            } else {
                if (!empty($lotRow['shift_type'])) {
                    $shiftType = $lotRow['shift_type'];
                }

                // If Single Shift, strictly enforce no night operator data
                if ($shiftType === 'Single Shift') {
                    $nightWorkerId = null;
                    $nightPieces = 0;
                    $nightRate = 0.00;
                    $nightSizes = [];
                }

                if ($dayWorkerId <= 0) {
                    $error = 'Please select Day Operator.';
                } elseif ($shiftType === 'Double Shift' && $nightWorkerId <= 0) {
                    $error = 'Please select Night Operator for Double Shift lot.';
                } else {
                    $lotNum = $lotRow['lot_number'];
                    if (!$singleSizeId) $singleSizeId = $lotRow['single_size_id'];
                    if (!$singleSizeId) {
                        $singleSizeId = (int)$db->query("SELECT id FROM sizes ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
                    }

                // If operation not passed, resolve first operation
                if ($operationId <= 0) {
                    $opQuery = $db->prepare("SELECT operation_id FROM lot_workflow_steps WHERE lot_id = ? ORDER BY sequence ASC LIMIT 1");
                    $opQuery->execute([$lotId]);
                    $operationId = (int)$opQuery->fetchColumn();
                    if ($operationId <= 0) {
                        $operationId = (int)$db->query("SELECT id FROM operations ORDER BY sequence ASC LIMIT 1")->fetchColumn();
                    }
                }

                $db->beginTransaction();
                try {
                    $baseDocNum = generate_doc_number('PE-', 'production_entries', 'entry_number');

                    // Calculate breakdown from elements if provided (element-level production & rates)
                    $elementsData = $_POST['elements'] ?? [];
                    $calcElemDayLabour = 0.00;
                    $calcElemNightLabour = 0.00;
                    $calcElemDayMins = 0.00;
                    $calcElemNightMins = 0.00;
                    $calcElemDayPcsMax = 0;
                    $calcElemNightPcsMax = 0;
                    $elementNotesArr = [];

                    if (!empty($elementsData)) {
                        foreach ($elementsData as $elId => $vals) {
                            $p1 = max(0, (int)($vals['op1_pieces'] ?? 0));
                            $p2 = ($shiftType === 'Double Shift') ? max(0, (int)($vals['op2_pieces'] ?? 0)) : 0;
                            $sam = max(0, (float)($vals['sam_minutes'] ?? $dayMins));
                            $rate = max(0, (float)($vals['rate'] ?? $dayRate));
                            $elName = trim($vals['name'] ?? ('Part #' . $elId));

                            if ($p1 > 0) {
                                $calcElemDayLabour += round($p1 * $rate, 2);
                                $calcElemDayMins += round($p1 * $sam, 2);
                                $calcElemDayPcsMax = max($calcElemDayPcsMax, $p1);
                                $elementNotesArr[] = "$elName: $p1 pcs @ Rs. " . number_format($rate, 2);
                            }
                            if ($p2 > 0) {
                                $calcElemNightLabour += round($p2 * $rate, 2);
                                $calcElemNightMins += round($p2 * $sam, 2);
                                $calcElemNightPcsMax = max($calcElemNightPcsMax, $p2);
                            }
                        }
                    }

                    // 1. Day Operator Entries
                    $dayTotalPcs = 0;
                    $dayTotalLabour = 0.00;
                    $firstDayId = null;

                    if ($calcElemDayLabour > 0) {
                        $dayTotalLabour = $calcElemDayLabour;
                        $minsTotal = $calcElemDayMins;
                        if ($dayPieces <= 0 && $calcElemDayPcsMax > 0) {
                            $dayPieces = $calcElemDayPcsMax;
                        }
                        $dayTotalPcs = $dayPieces;
                        $effectiveDayRate = ($dayTotalPcs > 0) ? round($dayTotalLabour / $dayTotalPcs, 2) : $dayRate;
                        $effectiveDayMins = ($dayTotalPcs > 0) ? round($minsTotal / $dayTotalPcs, 2) : $dayMins;
                        if (!empty($elementNotesArr)) {
                            $notes = ($notes ? $notes . ' | ' : '') . implode(', ', $elementNotesArr);
                        }

                        $entryNum = $baseDocNum . '-D';
                        $ins = $db->prepare("INSERT INTO production_entries 
                            (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, 'Day Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $dayWorkerId, $singleSizeId, $dayPieces, $dayPieces, $effectiveDayRate, $effectiveDayMins, $dayMinRate, $minsTotal, $dayTotalLabour, $notes, $_SESSION['user_id'] ?? null]);
                        $firstDayId = (int)$db->lastInsertId();
                    } elseif ($sizeMode === 'Multi Size' && !empty($daySizes)) {
                        $seq = 1;
                        foreach ($daySizes as $sId => $sQty) {
                            $sQty = (int)$sQty;
                            if ($sQty <= 0) continue;
                            $entryNum = $baseDocNum . '-D' . $seq++;
                            $labour = round($sQty * $dayRate, 2);
                            $minsTotal = round($sQty * $dayMins, 2);
                            $ins = $db->prepare("INSERT INTO production_entries 
                                (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, 'Day Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $dayWorkerId, $sId, $sQty, $sQty, $dayRate, $dayMins, $dayMinRate, $minsTotal, $labour, $notes, $_SESSION['user_id'] ?? null]);
                            $newId = (int)$db->lastInsertId();
                            if (!$firstDayId) $firstDayId = $newId;
                            $dayTotalPcs += $sQty;
                            $dayTotalLabour += $labour;
                        }
                    } else {
                        $entryNum = $baseDocNum . '-D';
                        $dayTotalPcs = $dayPieces;
                        $dayTotalLabour = round($dayPieces * $dayRate, 2);
                        $minsTotal = round($dayPieces * $dayMins, 2);
                        $ins = $db->prepare("INSERT INTO production_entries 
                            (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, 'Day Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $dayWorkerId, $singleSizeId, $dayPieces, $dayPieces, $dayRate, $dayMins, $dayMinRate, $minsTotal, $dayTotalLabour, $notes, $_SESSION['user_id'] ?? null]);
                        $firstDayId = (int)$db->lastInsertId();
                    }

                    // Day Operator Ledger Update
                    if ($dayTotalLabour > 0 && $firstDayId) {
                        $wStmt = $db->prepare("SELECT COALESCE((SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1), (SELECT opening_balance FROM workers WHERE id = ?))");
                        $wStmt->execute([$dayWorkerId, $dayWorkerId]);
                        $lastBal = (float)$wStmt->fetchColumn();
                        $newBal = $lastBal + $dayTotalLabour;
                        $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                                      VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)")
                           ->execute([$dayWorkerId, $entryDate, $firstDayId, $lotId, $operationId, $dayTotalLabour, $newBal, "Day Operator initial batch on Lot #$lotNum ($dayTotalPcs pcs @ Rs. $dayRate)", $_SESSION['user_id'] ?? null]);
                    }

                    // 2. Night Operator Entries (if Double Shift)
                    $nightTotalPcs = 0;
                    if ($shiftType === 'Double Shift' && $nightWorkerId > 0) {
                        $nightTotalLabour = 0.00;
                        $firstNightId = null;

                        if ($calcElemNightLabour > 0) {
                            $nightTotalLabour = $calcElemNightLabour;
                            $nightMinsTotal = $calcElemNightMins;
                            if ($nightPieces <= 0 && $calcElemNightPcsMax > 0) {
                                $nightPieces = $calcElemNightPcsMax;
                            }
                            $nightTotalPcs = $nightPieces;
                            $effectiveNightRate = ($nightTotalPcs > 0) ? round($nightTotalLabour / $nightTotalPcs, 2) : $nightRate;
                            $effectiveNightMins = ($nightTotalPcs > 0) ? round($nightMinsTotal / $nightTotalPcs, 2) : $nightMins;

                            $entryNum = $baseDocNum . '-N';
                            $ins = $db->prepare("INSERT INTO production_entries 
                                (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, 'Night Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $nightWorkerId, $singleSizeId, $nightPieces, $nightPieces, $effectiveNightRate, $effectiveNightMins, $nightMinRate, $nightMinsTotal, $nightTotalLabour, $notes, $_SESSION['user_id'] ?? null]);
                            $firstNightId = (int)$db->lastInsertId();
                        } elseif ($sizeMode === 'Multi Size' && !empty($nightSizes)) {
                            $seq = 1;
                            foreach ($nightSizes as $sId => $sQty) {
                                $sQty = (int)$sQty;
                                if ($sQty <= 0) continue;
                                $entryNum = $baseDocNum . '-N' . $seq++;
                                $labour = round($sQty * $nightRate, 2);
                                $minsTotal = round($sQty * $nightMins, 2);
                                $ins = $db->prepare("INSERT INTO production_entries 
                                    (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                                    VALUES (?, ?, ?, ?, ?, 'Night Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $nightWorkerId, $sId, $sQty, $sQty, $nightRate, $nightMins, $nightMinRate, $minsTotal, $labour, $notes, $_SESSION['user_id'] ?? null]);
                                $newId = (int)$db->lastInsertId();
                                if (!$firstNightId) $firstNightId = $newId;
                                $nightTotalPcs += $sQty;
                                $nightTotalLabour += $labour;
                            }
                        } else {
                            $entryNum = $baseDocNum . '-N';
                            $nightTotalPcs = $nightPieces;
                            $nightTotalLabour = round($nightPieces * $nightRate, 2);
                            $minsTotal = round($nightPieces * $nightMins, 2);
                            $ins = $db->prepare("INSERT INTO production_entries 
                                (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, 'Night Shift', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([$entryNum, $entryDate, $lotId, $operationId, $nightWorkerId, $singleSizeId, $nightPieces, $nightPieces, $nightRate, $nightMins, $nightMinRate, $minsTotal, $nightTotalLabour, $notes, $_SESSION['user_id'] ?? null]);
                            $firstNightId = (int)$db->lastInsertId();
                        }

                        // Night Operator Ledger Update
                        if ($nightTotalLabour > 0 && $firstNightId) {
                            $wStmt = $db->prepare("SELECT COALESCE((SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1), (SELECT opening_balance FROM workers WHERE id = ?))");
                            $wStmt->execute([$nightWorkerId, $nightWorkerId]);
                            $lastBal = (float)$wStmt->fetchColumn();
                            $newBal = $lastBal + $nightTotalLabour;
                            $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                                          VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)")
                               ->execute([$nightWorkerId, $entryDate, $firstNightId, $lotId, $operationId, $nightTotalLabour, $newBal, "Night Operator initial batch on Lot #$lotNum ($nightTotalPcs pcs @ Rs. $nightRate)", $_SESSION['user_id'] ?? null]);
                        }
                    }

                    // Update Lot Status
                    if ($lotRow['status'] === 'Draft') {
                        $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
                        $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, 'Draft', 'In Production', 'Operator initial production run submitted', ?)")
                           ->execute([$lotId, $_SESSION['user_id'] ?? null]);
                    }

                    // Sync lot_operator_element_entries (Cumulative additions)
                    if (!empty($elementsData)) {
                        foreach ($elementsData as $elId => $vals) {
                            $elId = (int)$elId;
                            $req = (int)($vals['required'] ?? $lotRow['total_pieces']);
                            $p1 = max(0, (int)($vals['op1_pieces'] ?? 0));
                            $p2 = ($shiftType === 'Double Shift') ? max(0, (int)($vals['op2_pieces'] ?? 0)) : 0;
                            $sam = max(0, (float)($vals['sam_minutes'] ?? 0));
                            $spentMins = round(($p1 + $p2) * $sam, 2);

                            $chkEl = $db->prepare("SELECT id, operator1_pieces, operator2_pieces, total_pieces, minutes_spent FROM lot_operator_element_entries WHERE lot_id = ? AND element_id = ?");
                            $chkEl->execute([$lotId, $elId]);
                            $existingEl = $chkEl->fetch();

                            if ($existingEl) {
                                $newP1 = (int)$existingEl['operator1_pieces'] + $p1;
                                $newP2 = (int)$existingEl['operator2_pieces'] + $p2;
                                $newTot = $newP1 + $newP2;
                                if ($newTot > $req) $newTot = $req;
                                $newSpentMins = (float)$existingEl['minutes_spent'] + $spentMins;

                                $db->prepare("UPDATE lot_operator_element_entries 
                                              SET entry_date = ?, 
                                                  operator1_id = ?, 
                                                  operator1_pieces = ?, 
                                                  operator2_id = ?, 
                                                  operator2_pieces = ?, 
                                                  total_pieces = ?, 
                                                  required_pieces = ?,
                                                  minutes_spent = ?
                                              WHERE id = ?")
                                   ->execute([$entryDate, $dayWorkerId, $newP1, $nightWorkerId, $newP2, $newTot, $req, $newSpentMins, $existingEl['id']]);
                            } else {
                                $newTot = $p1 + $p2;
                                if ($newTot > $req) $newTot = $req;
                                $db->prepare("INSERT INTO lot_operator_element_entries 
                                              (lot_id, element_id, entry_date, operator1_id, operator1_pieces, operator2_id, operator2_pieces, total_pieces, required_pieces, minutes_spent, created_by) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                   ->execute([$lotId, $elId, $entryDate, $dayWorkerId, $p1, $nightWorkerId, $p2, $newTot, $req, $spentMins, $_SESSION['user_id'] ?? null]);
                            }
                        }
                    } else {
                        $peList = $db->prepare("SELECT id, multiplier, quantity FROM product_elements WHERE product_id = (SELECT product_id FROM lots WHERE id = ?)");
                        $peList->execute([$lotId]);
                        foreach ($peList->fetchAll() as $pel) {
                            $m = (int)($pel['quantity'] ?: $pel['multiplier'] ?: 1);
                            $elId = (int)$pel['id'];
                            $req = $m * (int)$lotRow['total_pieces'];
                            $p1 = $m * $dayTotalPcs;
                            $p2 = $m * $nightTotalPcs;

                            $chkEl = $db->prepare("SELECT id, operator1_pieces, operator2_pieces, total_pieces FROM lot_operator_element_entries WHERE lot_id = ? AND element_id = ?");
                            $chkEl->execute([$lotId, $elId]);
                            $existingEl = $chkEl->fetch();

                            if ($existingEl) {
                                $newP1 = (int)$existingEl['operator1_pieces'] + $p1;
                                $newP2 = (int)$existingEl['operator2_pieces'] + $p2;
                                $newTot = $newP1 + $newP2;
                                if ($newTot > $req) $newTot = $req;
                                $db->prepare("UPDATE lot_operator_element_entries 
                                              SET entry_date = ?, operator1_id = ?, operator1_pieces = ?, operator2_id = ?, operator2_pieces = ?, total_pieces = ?, required_pieces = ? 
                                              WHERE id = ?")
                                   ->execute([$entryDate, $dayWorkerId, $newP1, $nightWorkerId, $newP2, $newTot, $req, $existingEl['id']]);
                            } else {
                                $newTot = $p1 + $p2;
                                if ($newTot > $req) $newTot = $req;
                                $db->prepare("INSERT INTO lot_operator_element_entries 
                                              (lot_id, element_id, entry_date, operator1_id, operator1_pieces, operator2_id, operator2_pieces, total_pieces, required_pieces, created_by) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                   ->execute([$lotId, $elId, $entryDate, $dayWorkerId, $p1, $nightWorkerId, $p2, $newTot, $req, $_SESSION['user_id'] ?? null]);
                            }
                        }
                    }

                    // Update workflow step status (check if elements complete)
                    $checkComp = $db->prepare("SELECT COUNT(*) FROM product_elements pe 
                                               JOIN lots l ON pe.product_id = l.product_id 
                                               LEFT JOIN lot_operator_element_entries loe ON loe.element_id = pe.id AND loe.lot_id = l.id
                                               WHERE l.id = ? AND COALESCE(loe.total_pieces, 0) < (pe.quantity * l.total_pieces)");
                    $checkComp->execute([$lotId]);
                    $incompleteCount = (int)$checkComp->fetchColumn();
                    $stepStatus = ($incompleteCount === 0) ? 'Completed' : 'In Progress';
                    $db->prepare("UPDATE lot_workflow_steps SET status = ? WHERE lot_id = ? AND operation_id = ?")
                       ->execute([$stepStatus, $lotId, $operationId]);


                    $db->commit();
                    $msg = "Operator Initial Production recorded: Day Operator ($dayTotalPcs pcs)";
                    if ($shiftType === 'Double Shift') {
                        $msg .= " + Night Operator ($nightTotalPcs pcs) = Total " . ($dayTotalPcs + $nightTotalPcs) . " pcs!";
                    }

                    // Auto-advance to next workflow step
                    $nextOp = get_next_lot_workflow_operation($db, $lotId, $operationId);
                    if ($nextOp) {
                        $nextOpId = (int)$nextOp['operation_id'];
                        $nextMode = $nextOp['default_mode'] ?? 'cutting';
                        $msg .= " — اگلا مرحلہ لوڈ ہو گیا: {$nextOp['op_name']}";
                        redirect("modules/production/index.php?lot_id=$lotId&operation_id=$nextOpId&mode=$nextMode", $msg);
                    } else {
                        redirect("modules/production/index.php?lot_id=$lotId", $msg);
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Error saving operator entry: ' . $e->getMessage();
                }
            }
        }
    }
}

    // =========================================================================
    // 1.5 CUTTING PRODUCTION ENTRY MODE (WHOLE SWEATERS OR INDIVIDUAL ELEMENTS)
    // =========================================================================
    if ($entryMode === 'cutting') {
        $lotId = (int)($_POST['lot_id'] ?? 0);
        $operationId = (int)($_POST['operation_id'] ?? 0);
        $workerId = (int)($_POST['cutting_worker_id'] ?? $_POST['worker_id'] ?? 0);
        $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
        $shift = in_array($_POST['cutting_shift'] ?? $_POST['shift'] ?? '', ['Day Shift', 'Night Shift', 'General']) ? ($_POST['cutting_shift'] ?? $_POST['shift']) : 'Day Shift';
        $rate = (float)($_POST['rate'] ?? 0.00);
        $wholePieces = (int)($_POST['cutting_whole_pieces'] ?? 0);
        $elementsCut = $_POST['elements_cut'] ?? [];
        $notes = trim($_POST['notes'] ?? 'Cutting master element output');

        if ($lotId <= 0 || $workerId <= 0) {
            $error = 'Please select Lot and Cutting Master.';
        } else {
            $lStmt = $db->prepare("SELECT lot_number, total_pieces, single_size_id, status FROM lots WHERE id = ?");
            $lStmt->execute([$lotId]);
            $lotRow = $lStmt->fetch();

            if (!$lotRow) {
                $error = 'Selected lot not found.';
            } else {
                if ($operationId <= 0) {
                    $opCutStmt = $db->query("SELECT id FROM operations WHERE name LIKE '%Cut%' LIMIT 1");
                    $operationId = (int)$opCutStmt->fetchColumn() ?: 1;
                }

                if ($rate <= 0) {
                    $rStmt = $db->prepare("SELECT default_rate FROM operations WHERE id = ?");
                    $rStmt->execute([$operationId]);
                    $rate = (float)$rStmt->fetchColumn() ?: 5.00;
                }

                $db->beginTransaction();
                try {
                    // Check available operator pieces for each element to prevent cutting more than operator ready
                    $elemStmt = $db->prepare("SELECT pe.id, pe.element_name, pe.quantity, pe.multiplier,
                                                     COALESCE(SUM(loe.total_pieces), 0) AS operator_total,
                                                     COALESCE(SUM(loe.cut_pieces), 0) AS cut_pieces
                                              FROM product_elements pe
                                              JOIN lots l ON pe.product_id = l.product_id
                                              LEFT JOIN lot_operator_element_entries loe ON loe.element_id = pe.id AND loe.lot_id = l.id
                                              WHERE l.id = ?
                                              GROUP BY pe.id, pe.element_name, pe.quantity, pe.multiplier");
                    $elemStmt->execute([$lotId]);
                    $dbLotElems = $elemStmt->fetchAll();

                    $maxWholeReadyToCut = null;
                    $elemAvailMap = [];
                    foreach ($dbLotElems as $de) {
                        $m = (int)($de['quantity'] ?: $de['multiplier'] ?: 1);
                        if ($m <= 0) $m = 1;
                        $avail = max(0, (int)$de['operator_total'] - (int)$de['cut_pieces']);
                        $elemAvailMap[(int)$de['id']] = [
                            'name' => $de['element_name'],
                            'avail' => $avail,
                            'mult' => $m
                        ];
                        $wholeForEl = (int)floor($avail / $m);
                        if ($maxWholeReadyToCut === null || $wholeForEl < $maxWholeReadyToCut) {
                            $maxWholeReadyToCut = $wholeForEl;
                        }
                    }
                    if ($maxWholeReadyToCut === null) {
                        $opCutStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries pe JOIN operations o ON pe.operation_id = o.id WHERE pe.lot_id = ? AND (o.name LIKE '%Operator%' OR o.name LIKE '%Knitt%')");
                        $opCutStmt->execute([$lotId]);
                        $maxWholeReadyToCut = (int)$opCutStmt->fetchColumn();
                    }

                    // Strict check: each element cut cannot exceed available ready pieces
                    foreach ($elementsCut as $elId => $cutQty) {
                        $elId = (int)$elId;
                        $cutQty = (int)$cutQty;
                        if ($cutQty <= 0) continue;
                        if (isset($elemAvailMap[$elId])) {
                            $avail = $elemAvailMap[$elId]['avail'];
                            if ($cutQty > $avail) {
                                throw new Exception("کٹنگ تعداد برائے {$elemAvailMap[$elId]['name']} ($cutQty پیسز) آپریٹر کے تیار کردہ پیسز ($avail پیسز) سے زیادہ درج نہیں ہو سکتی۔");
                            }
                        }
                    }

                    // Check matrix total good pieces
                    $matrixData = $_POST['matrix'] ?? [];
                    $matrixCheckGood = 0;
                    if (!empty($matrixData)) {
                        foreach ($matrixData as $cId => $sizes) {
                            if (!is_array($sizes)) continue;
                            foreach ($sizes as $sId => $vals) {
                                $matrixCheckGood += (int)($vals['good'] ?? 0);
                            }
                        }
                    }

                    if ($matrixCheckGood > 0 && $matrixCheckGood > $maxWholeReadyToCut) {
                        throw new Exception("رنگ و سائز میٹرکس کٹنگ تعداد ($matrixCheckGood پیسز) آپریٹر کے تیار کردہ پیسز ($maxWholeReadyToCut پیسز) سے زیادہ نہیں ہو سکتی۔");
                    }
                    if ($matrixCheckGood === 0 && $wholePieces > $maxWholeReadyToCut) {
                        throw new Exception("مکمل سویٹر کٹنگ تعداد ($wholePieces پیسز) آپریٹر کے تیار کردہ پیسز ($maxWholeReadyToCut پیسز) سے زیادہ نہیں ہو سکتی۔");
                    }

                    // Update cut_pieces in lot_operator_element_entries
                    $totalCutSum = 0;
                    $elementsCount = 0;
                    foreach ($elementsCut as $elId => $cutQty) {
                        $elId = (int)$elId;
                        $cutQty = (int)$cutQty;
                        if ($cutQty <= 0) continue;
                        $totalCutSum += $cutQty;
                        $elementsCount++;

                        $chkEl = $db->prepare("SELECT id, cut_pieces FROM lot_operator_element_entries WHERE lot_id = ? AND element_id = ?");
                        $chkEl->execute([$lotId, $elId]);
                        $existingEl = $chkEl->fetch();

                        if ($existingEl) {
                            $upEl = $db->prepare("UPDATE lot_operator_element_entries SET cut_pieces = cut_pieces + ? WHERE id = ?");
                            $upEl->execute([$cutQty, $existingEl['id']]);
                        } else {
                            $peMultStmt = $db->prepare("SELECT multiplier, quantity FROM product_elements WHERE id = ?");
                            $peMultStmt->execute([$elId]);
                            $multRow = $peMultStmt->fetch();
                            $mult = $multRow ? (int)($multRow['quantity'] ?: $multRow['multiplier'] ?: 1) : 1;
                            $req = $mult * (int)$lotRow['total_pieces'];

                            $insEl = $db->prepare("INSERT INTO lot_operator_element_entries 
                                (lot_id, element_id, entry_date, required_pieces, cut_pieces, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, 'Cutting direct entry', ?)");
                            $insEl->execute([$lotId, $elId, $entryDate, $req, $cutQty, $_SESSION['user_id'] ?? null]);
                        }
                    }

                    // Color x Size Matrix Processing for Cutting
                    $matrixData = $_POST['matrix'] ?? [];
                    $matrixTotalGood = 0;
                    $matrixTotalRej = 0;
                    $matrixTotalRew = 0;
                    $matrixTotalLabour = 0.00;
                    $matrixSavedCount = 0;
                    $lastPeId = null;

                    $cutMins = (float)($_POST['cutting_minutes'] ?? 2.0);
                    $cutMinRate = (float)($_POST['cutting_min_rate'] ?? 2.50);

                    if (!empty($matrixData)) {
                        $baseDocNum = generate_doc_number('PE-CUT-', 'production_entries', 'entry_number');
                        $seq = 1;

                        foreach ($matrixData as $cId => $sizes) {
                            $cId = (int)$cId;
                            if (!is_array($sizes)) continue;

                            foreach ($sizes as $sId => $vals) {
                                $sId = (int)$sId;
                                $good = isset($vals['good']) ? (int)$vals['good'] : 0;
                                $rej = isset($vals['rejected']) ? (int)$vals['rejected'] : 0;
                                $rew = isset($vals['rework']) ? (int)$vals['rework'] : 0;

                                if ($good <= 0 && $rej <= 0 && $rew <= 0) continue;

                                $received = $good + $rej + $rew;
                                $cellLabour = round($good * $rate, 2);
                                $minsTotal = round($good * $cutMins, 2);
                                $entryNum = $baseDocNum . '-' . $seq++;

                                $insPE = $db->prepare("INSERT INTO production_entries 
                                    (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $insPE->execute([
                                    $entryNum, $entryDate, $lotId, $operationId, $workerId, $shift,
                                    $cId, $sId, $received, $good, $rej, $rew,
                                    $rate, $cutMins, $cutMinRate, $minsTotal, $cellLabour,
                                    $notes, $_SESSION['user_id'] ?? null
                                ]);
                                $lastPeId = (int)$db->lastInsertId();

                                // Track Rejections with specific color & size
                                if ($rej > 0) {
                                    $db->prepare("INSERT INTO production_rejections 
                                        (production_entry_id, lot_id, operation_id, worker_id, color_id, size_id, quantity, reason, created_by)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Cutting defect / damage', ?)")
                                       ->execute([$lastPeId, $lotId, $operationId, $workerId, $cId, $sId, $rej, $_SESSION['user_id'] ?? null]);
                                }

                                // Track Rework with specific color & size
                                if ($rew > 0) {
                                    $db->prepare("INSERT INTO production_rework 
                                        (production_entry_id, lot_id, operation_id, worker_id, color_id, size_id, quantity, rework_notes, created_by)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Recut / touchup required', ?)")
                                       ->execute([$lastPeId, $lotId, $operationId, $workerId, $cId, $sId, $rew, $_SESSION['user_id'] ?? null]);
                                }

                                $matrixTotalGood += $good;
                                $matrixTotalRej += $rej;
                                $matrixTotalRew += $rew;
                                $matrixTotalLabour += $cellLabour;
                                $matrixSavedCount++;
                            }
                        }

                        if ($matrixSavedCount > 0) {
                            $wholePieces = $matrixTotalGood;
                            $labourAmount = $matrixTotalLabour;
                        }
                    }

                    // Fallback single whole pieces entry if matrix was empty
                    if ($matrixSavedCount === 0 && $wholePieces > 0) {
                        $labourAmount = round($wholePieces * $rate, 2);
                        $entryNum = generate_doc_number('PE-CUT-', 'production_entries', 'entry_number');

                        $insPE = $db->prepare("INSERT INTO production_entries 
                            (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $insPE->execute([$entryNum, $entryDate, $lotId, $operationId, $workerId, $shift, $lotRow['single_size_id'], $wholePieces, $wholePieces, $rate, $cutMins, $cutMinRate, round($wholePieces * $cutMins, 2), $labourAmount, $notes, $_SESSION['user_id'] ?? null]);
                        $lastPeId = (int)$db->lastInsertId();
                    }

                    // Credit Cutting Master Ledger
                    if ($labourAmount > 0 && $lastPeId) {
                        $wStmt = $db->prepare("SELECT COALESCE((SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1), (SELECT opening_balance FROM workers WHERE id = ?))");
                        $wStmt->execute([$workerId, $workerId]);
                        $lastBal = (float)$wStmt->fetchColumn();
                        $newBal = $lastBal + $labourAmount;

                        $db->prepare("INSERT INTO worker_ledger (worker_id, transaction_date, transaction_type, reference_type, reference_id, lot_id, operation_id, debit, credit, running_balance, description, created_by)
                                      VALUES (?, ?, 'Labour', 'PRODUCTION_ENTRY', ?, ?, ?, 0.00, ?, ?, ?, ?)")
                           ->execute([$workerId, $entryDate, $lastPeId, $lotId, $operationId, $labourAmount, $newBal, "Cutting master batch on Lot #{$lotRow['lot_number']} ($wholePieces pcs @ Rs. $rate)", $_SESSION['user_id'] ?? null]);
                    }

                    if ($lotRow['status'] === 'Draft') {
                        $db->prepare("UPDATE lots SET status = 'In Production' WHERE id = ?")->execute([$lotId]);
                    }
                    $db->prepare("UPDATE lot_workflow_steps SET status = 'In Progress' WHERE lot_id = ? AND operation_id = ? AND status = 'Pending'")
                       ->execute([$lotId, $operationId]);

                    $db->commit();
                    $cutSuccessMsg = "Cutting entry saved successfully! $wholePieces pieces cut (Labour: " . format_currency($labourAmount) . ").";

                    // Auto-advance to next workflow step
                    $nextOp = get_next_lot_workflow_operation($db, $lotId, $operationId);
                    if ($nextOp) {
                        $nextOpId = (int)$nextOp['operation_id'];
                        $nextMode = $nextOp['default_mode'] ?? 'matrix';
                        $cutSuccessMsg .= " — اگلا مرحلہ لوڈ ہو گیا: {$nextOp['op_name']}";
                        redirect("modules/production/index.php?lot_id=$lotId&operation_id=$nextOpId&mode=$nextMode", $cutSuccessMsg);
                    } else {
                        redirect("modules/production/index.php?lot_id=$lotId", $cutSuccessMsg);
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Error saving cutting entry: ' . $e->getMessage();
                }
            }
        }
    }


    $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $lotId = (int)($_POST['lot_id'] ?? 0);
    $operationId = (int)($_POST['operation_id'] ?? 0);
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $minutesPerPiece = (float)($_POST['minutes_per_piece'] ?? 0.00);
    $workerMinuteRate = (float)($_POST['worker_minute_rate'] ?? 2.50);
    $rate = (float)($_POST['rate'] ?? 0.00);

    // Fallback: If rate was 0 but minutes & worker minute rate were provided, calculate rate
    if ($rate <= 0 && $minutesPerPiece > 0 && $workerMinuteRate > 0) {
        $rate = round($minutesPerPiece * $workerMinuteRate, 2);
    }

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
                $lQuery = $db->prepare("SELECT id, lot_number, status, total_pieces, product_id, design_id FROM lots WHERE id = ?");
                $lQuery->execute([$lotId]);
                $lotRow = $lQuery->fetch();
                if (!$lotRow) {
                    $error = 'Selected lot does not exist.';
                } else {
                    $lotNum = $lotRow['lot_number'] ?? '';
                    $currentLotStatus = $lotRow['status'] ?? '';
                    $lotTotalPieces = (int)$lotRow['total_pieces'];

                    // Check if current operation is Packing (Unified Workflow Packing)
                    $isPackingOp = false;
                    $opCheck = $db->prepare("SELECT code, name FROM operations WHERE id = ?");
                    $opCheck->execute([$operationId]);
                    $opRow = $opCheck->fetch();
                    if ($opRow && ($opRow['code'] === 'OP-PACK' || stripos($opRow['name'], 'Pack') !== false)) {
                        $isPackingOp = true;
                    }

                    // 1. Stage-level capacity check: cumulative good pieces in this stage cannot exceed lot.total_pieces
                    $stageGoodStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
                    $stageGoodStmt->execute([$lotId, $operationId]);
                    $currentStageGood = (int)$stageGoodStmt->fetchColumn();

                    if (($currentStageGood + $totalGood) > $lotTotalPieces) {
                        $overPcs = ($currentStageGood + $totalGood) - $lotTotalPieces;
                        $allowed = max(0, $lotTotalPieces - $currentStageGood);
                        $error = "Production quantity exceeds lot capacity! Lot #$lotNum allows a maximum of $lotTotalPieces pieces. Already produced: $currentStageGood pcs. Maximum pieces allowed to record now is $allowed pcs (attempted to add: $totalGood pcs, exceeding by $overPcs pcs).";
                    } else {
                        // 2. Strict Stage-to-Stage Workflow Variant & Color Capacity Enforcement
                        $allowanceData = get_lot_variant_stage_allowance($lotId, $operationId);
                        $variantAllowances = $allowanceData['variants'];
                        $colorAllowances = $allowanceData['colors'];
                        $prevOpName = $allowanceData['prev_operation_name'];

                        // Track attempted quantities by color
                        $colorAttemptedMap = [];

                        foreach ($entriesToSave as $entry) {
                            $vKey = $entry['color_id'] . '_' . $entry['size_id'];
                            $vInfo = $variantAllowances[$vKey] ?? null;
                            $cId = $entry['color_id'];
                            $colorAttemptedMap[$cId] = ($colorAttemptedMap[$cId] ?? 0) + $entry['good_qty'];

                            $cName = $vInfo['color_name'] ?? ('Color #' . $cId);
                            $cCode = $vInfo['color_code'] ?? $cName;
                            $sName = $vInfo['size_name'] ?? ('Size #' . $entry['size_id']);

                            $allowable = $vInfo['allowable'] ?? 0;
                            $alreadyDone = $vInfo['current_done'] ?? 0;
                            $attemptedGood = $entry['good_qty'];

                            if (($alreadyDone + $attemptedGood) > $allowable) {
                                $overVar = ($alreadyDone + $attemptedGood) - $allowable;
                                $varRemaining = max(0, $allowable - $alreadyDone);
                                if ($allowanceData['has_prev_color_stage']) {
                                    $prevRej = $vInfo['prev_stage_rejected'] ?? 0;
                                    $rejNote = $prevRej > 0 ? " ($prevRej piece(s) were rejected in $prevOpName)" : "";
                                    $error = "Variant quantity limit exceeded for [{$cCode}] {$cName} ({$sName})! Preceding stage ({$prevOpName}) passed only {$allowable} good pieces{$rejNote}. Already recorded in this stage: {$alreadyDone} pcs. Remaining allowed: {$varRemaining} pcs (attempted: {$attemptedGood} pcs, exceeding by {$overVar} pcs).";
                                } else {
                                    $error = "Variant quantity limit exceeded for [{$cCode}] {$cName} ({$sName})! Planned: {$allowable} pcs, Already produced: {$alreadyDone} pcs, Remaining: {$varRemaining} pcs (attempted: {$attemptedGood} pcs, exceeding by {$overVar} pcs).";
                                }
                                break;
                            }
                        }

                        // Also verify cumulative color limit
                        if (empty($error)) {
                            foreach ($colorAttemptedMap as $cId => $cAttempted) {
                                $cInfo = $colorAllowances[$cId] ?? null;
                                if ($cInfo) {
                                    $cAllowable = $cInfo['allowable'];
                                    $cDone = $cInfo['current_done'];
                                    $cCode = $cInfo['color_code'] ?: $cInfo['color_name'];
                                    if (($cDone + $cAttempted) > $cAllowable) {
                                        $cOver = ($cDone + $cAttempted) - $cAllowable;
                                        $cRem = max(0, $cAllowable - $cDone);
                                        if ($allowanceData['has_prev_color_stage']) {
                                            $prevRej = $cInfo['prev_stage_rejected'];
                                            $error = "Color limit exceeded for [{$cCode}] {$cInfo['color_name']}! Preceding stage ({$prevOpName}) only passed {$cAllowable} good pieces ({$prevRej} piece(s) rejected). You cannot shift rejected pieces to other colors. Remaining allowed: {$cRem} pcs (attempted: {$cAttempted} pcs, exceeding by {$cOver} pcs).";
                                        } else {
                                            $error = "Color limit exceeded for [{$cCode}] {$cInfo['color_name']}! Planned: {$cAllowable} pcs, Already produced: {$cDone} pcs, Remaining allowed: {$cRem} pcs (attempted: {$cAttempted} pcs, exceeding by {$cOver} pcs).";
                                        }
                                        break;
                                    }
                                }
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
                            (entry_number, entry_date, lot_id, operation_id, worker_id, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
                        $entryTotalMinutes = round($entry['good_qty'] * $minutesPerPiece, 2);
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
                            $minutesPerPiece,
                            $workerMinuteRate,
                            $entryTotalMinutes,
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

                        // If this operation is Packing (Unified Workflow Packing), automatically record into packing_entries & finished goods stock
                        if ($isPackingOp && $entry['good_qty'] > 0) {
                            $packNum = generate_doc_number('PKG-', 'packing_entries', 'packing_number');
                            $packageNo = 'SET-LOT' . $lotId . '-' . $entry['color_id'] . '-' . $entry['size_id'];
                            $pkStmt = $db->prepare("INSERT INTO packing_entries 
                                (packing_number, packing_date, lot_id, product_id, design_id, color_id, size_id, packed_qty, packing_worker_id, packing_rate, labour_amount, package_type, package_no, remarks, warehouse_id, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Master Set', ?, ?, 1, ?)");
                            $pkStmt->execute([
                                $packNum,
                                $entryDate,
                                $lotId,
                                $lotRow['product_id'] ?? null,
                                $lotRow['design_id'] ?? null,
                                $entry['color_id'],
                                $entry['size_id'],
                                $entry['good_qty'],
                                $workerId,
                                $rate,
                                $entry['labour_amount'],
                                $packageNo,
                                'Workflow Packing Matrix Operation',
                                $_SESSION['user_id'] ?? null
                            ]);
                            $packingId = (int)$db->lastInsertId();

                            // Update Finished Goods Stock
                            $finWhId = get_default_warehouse_id('finished');
                            $stkCheck = $db->prepare("SELECT id FROM finished_goods_stock WHERE lot_id = ? AND color_id = ? AND size_id = ? AND (warehouse_id = ? OR (warehouse_id IS NULL AND ? IS NULL)) LIMIT 1");
                            $stkCheck->execute([$lotId, $entry['color_id'], $entry['size_id'], $finWhId, $finWhId]);
                            $existingStkId = $stkCheck->fetchColumn();
                            if ($existingStkId) {
                                $db->prepare("UPDATE finished_goods_stock SET current_stock = current_stock + ?, updated_at = NOW() WHERE id = ?")
                                   ->execute([$entry['good_qty'], $existingStkId]);
                            } else {
                                $db->prepare("INSERT INTO finished_goods_stock (product_id, design_id, color_id, size_id, lot_id, warehouse_id, current_stock, dispatched_stock, updated_at) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())")
                                   ->execute([
                                       $lotRow['product_id'] ?? null,
                                       $lotRow['design_id'] ?? null,
                                       $entry['color_id'],
                                       $entry['size_id'],
                                       $lotId,
                                       $finWhId,
                                       $entry['good_qty']
                                   ]);
                            }

                            // Record Finished Goods Inward Inventory Movement
                            $db->prepare("INSERT INTO inventory_transactions 
                                (transaction_date, item_type, product_id, design_id, color_id, size_id, lot_id, warehouse_id, transaction_type, quantity_in, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                                VALUES (?, 'Finished Goods', ?, ?, ?, ?, ?, ?, 'Packing', ?, 0.00, 0.00, 'WORKFLOW_PACKING', ?, ?, ?)")
                               ->execute([
                                   $entryDate,
                                   $lotRow['product_id'] ?? null,
                                   $lotRow['design_id'] ?? null,
                                   $entry['color_id'],
                                   $entry['size_id'],
                                   $lotId,
                                   $finWhId,
                                   $entry['good_qty'],
                                   $packingId,
                                   "Auto-packed via Production Entry (Workflow Step Completed)",
                                   $_SESSION['user_id'] ?? null
                               ]);
                        }
                    }

                    // Auto-transition lot status on Packing operation
                    if ($isPackingOp) {
                        $totPackedStmt = $db->prepare("SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = ?");
                        $totPackedStmt->execute([$lotId]);
                        $totPackedForLot = (int)$totPackedStmt->fetchColumn();
                        if ($totPackedForLot >= $lotTotalPieces && $currentLotStatus !== 'Ready Stock') {
                            $db->prepare("UPDATE lots SET status = 'Ready Stock' WHERE id = ?")->execute([$lotId]);
                            $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, ?, 'Ready Stock', 'Lot fully packed into Finished Goods Stock via workflow', ?)")
                               ->execute([$lotId, $currentLotStatus, $_SESSION['user_id'] ?? null]);
                        } elseif ($totPackedForLot > 0 && $currentLotStatus !== 'Ready Stock' && $currentLotStatus !== 'Partially Packed') {
                            $db->prepare("UPDATE lots SET status = 'Partially Packed' WHERE id = ?")->execute([$lotId]);
                            $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, ?, 'Partially Packed', 'Lot partially packed into Finished Goods Stock via workflow', ?)")
                               ->execute([$lotId, $currentLotStatus, $_SESSION['user_id'] ?? null]);
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
                    // Auto-advance to next workflow step
                    $nextOp = get_next_lot_workflow_operation($db, $lotId, $operationId);
                    if ($nextOp) {
                        $nextOpId = (int)$nextOp['operation_id'];
                        $nextMode = $nextOp['default_mode'] ?? 'matrix';
                        $feedbackMsg .= " — اگلا مرحلہ لوڈ ہو گیا: {$nextOp['op_name']}";
                        redirect("modules/production/index.php?lot_id=$lotId&operation_id=$nextOpId&mode=$nextMode", $feedbackMsg);
                    } else {
                        $feedbackMsg .= " — اس لاٹ کے تمام ورک فلو مراحل مکمل ہو گئے!";
                        redirect("modules/production/index.php?lot_id=$lotId", $feedbackMsg);
                    }
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
            $lQuery = $db->prepare("SELECT id, lot_number, status, total_pieces, product_id, design_id FROM lots WHERE id = ?");
            $lQuery->execute([$lotId]);
            $lotRow = $lQuery->fetch();
            $lotTotalPieces = (int)($lotRow['total_pieces'] ?? 0);
            $lotNum = $lotRow['lot_number'] ?? '';
            $currentLotStatus = $lotRow['status'] ?? '';

            // Check if current operation is Packing
            $isPackingOp = false;
            $opCheck = $db->prepare("SELECT code, name FROM operations WHERE id = ?");
            $opCheck->execute([$operationId]);
            $opRow = $opCheck->fetch();
            if ($opRow && ($opRow['code'] === 'OP-PACK' || stripos($opRow['name'], 'Pack') !== false)) {
                $isPackingOp = true;
            }

            // 1. Stage-level capacity check: cumulative good pieces in this stage cannot exceed lot.total_pieces
            $stageGoodStmt = $db->prepare("SELECT COALESCE(SUM(good_qty), 0) FROM production_entries WHERE lot_id = ? AND operation_id = ?");
            $stageGoodStmt->execute([$lotId, $operationId]);
            $currentStageGood = (int)$stageGoodStmt->fetchColumn();

            // 2. Strict Stage-to-Stage Workflow Variant & Color Capacity Enforcement
            $allowanceData = get_lot_variant_stage_allowance($lotId, $operationId);
            $vKey = $colorId . '_' . $sizeId;
            $vInfo = $allowanceData['variants'][$vKey] ?? null;
            $cInfo = $allowanceData['colors'][$colorId] ?? null;
            $prevOpName = $allowanceData['prev_operation_name'];

            $variantAllowable = $vInfo ? $vInfo['allowable'] : 0;
            $previouslyDone = $vInfo ? $vInfo['current_done'] : 0;
            $cName = $vInfo['color_name'] ?? ('Color #' . $colorId);
            $cCode = $vInfo['color_code'] ?? $cName;
            $sName = $vInfo['size_name'] ?? ('Size #' . $sizeId);

            if (($currentStageGood + $goodQty) > $lotTotalPieces) {
                $overPcs = ($currentStageGood + $goodQty) - $lotTotalPieces;
                $allowed = max(0, $lotTotalPieces - $currentStageGood);
                $error = "Production quantity exceeds lot capacity! Lot #$lotNum allows up to $lotTotalPieces pieces. Already produced: $currentStageGood pcs. Maximum pieces allowed to record now is $allowed pcs (attempted: $goodQty pcs, exceeding by $overPcs pcs).";
            } elseif (($previouslyDone + $goodQty) > $variantAllowable) {
                $overVar = ($previouslyDone + $goodQty) - $variantAllowable;
                $varRemaining = max(0, $variantAllowable - $previouslyDone);
                if ($allowanceData['has_prev_color_stage']) {
                    $prevRej = $vInfo['prev_stage_rejected'] ?? 0;
                    $rejNote = $prevRej > 0 ? " ($prevRej piece(s) were rejected in $prevOpName)" : "";
                    $error = "Production quantity exceeds passed quantity for [{$cCode}] {$cName} ({$sName})! Preceding stage ({$prevOpName}) passed only {$variantAllowable} good pieces{$rejNote}. Already recorded: {$previouslyDone} pcs, Remaining: {$varRemaining} pcs (attempted: {$goodQty} pcs).";
                } else {
                    $error = "Production quantity exceeds variant capacity! Planned: {$variantAllowable} pcs, Already produced: {$previouslyDone} pcs, Remaining: {$varRemaining} pcs (attempted: {$goodQty} pcs, exceeding by {$overVar} pcs).";
                }
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
                    (entry_number, entry_date, lot_id, operation_id, worker_id, color_id, size_id, qty_received, good_qty, rejected_qty, rework_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $totalSingleMinutes = round($goodQty * $minutesPerPiece, 2);
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
                    $minutesPerPiece,
                    $workerMinuteRate,
                    $totalSingleMinutes,
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

                // If operation is Packing, create packing record and deposit into finished goods stock
                if ($isPackingOp && $goodQty > 0) {
                    $packNum = generate_doc_number('PKG-', 'packing_entries', 'packing_number');
                    $packageNo = 'SET-LOT' . $lotId . '-' . $colorId . '-' . $sizeId;
                    $pkStmt = $db->prepare("INSERT INTO packing_entries 
                        (packing_number, packing_date, lot_id, product_id, design_id, color_id, size_id, packed_qty, packing_worker_id, packing_rate, labour_amount, package_type, package_no, remarks, warehouse_id, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Master Set', ?, ?, 1, ?)");
                    $pkStmt->execute([
                        $packNum,
                        $entryDate,
                        $lotId,
                        $lotRow['product_id'] ?? null,
                        $lotRow['design_id'] ?? null,
                        $colorId,
                        $sizeId,
                        $goodQty,
                        $workerId,
                        $rate,
                        $labourAmount,
                        $packageNo,
                        'Workflow Packing Single Variant Operation',
                        $_SESSION['user_id'] ?? null
                    ]);
                    $packingId = (int)$db->lastInsertId();

                    // Update Finished Goods Stock
                    $finWhId = get_default_warehouse_id('finished');
                    $stkCheck = $db->prepare("SELECT id FROM finished_goods_stock WHERE lot_id = ? AND color_id = ? AND size_id = ? AND (warehouse_id = ? OR (warehouse_id IS NULL AND ? IS NULL)) LIMIT 1");
                    $stkCheck->execute([$lotId, $colorId, $sizeId, $finWhId, $finWhId]);
                    $existingStkId = $stkCheck->fetchColumn();
                    if ($existingStkId) {
                        $db->prepare("UPDATE finished_goods_stock SET current_stock = current_stock + ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$goodQty, $existingStkId]);
                    } else {
                        $db->prepare("INSERT INTO finished_goods_stock (product_id, design_id, color_id, size_id, lot_id, warehouse_id, current_stock, dispatched_stock, updated_at) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())")
                           ->execute([
                               $lotRow['product_id'] ?? null,
                               $lotRow['design_id'] ?? null,
                               $colorId,
                               $sizeId,
                               $lotId,
                               $finWhId,
                               $goodQty
                           ]);
                    }

                    // Record Inventory Movement
                    $db->prepare("INSERT INTO inventory_transactions 
                        (transaction_date, item_type, product_id, design_id, color_id, size_id, lot_id, warehouse_id, transaction_type, quantity_in, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                        VALUES (?, 'Finished Goods', ?, ?, ?, ?, ?, ?, 'Packing', ?, 0.00, 0.00, 'WORKFLOW_PACKING', ?, ?, ?)")
                       ->execute([
                           $entryDate,
                           $lotRow['product_id'] ?? null,
                           $lotRow['design_id'] ?? null,
                           $colorId,
                           $sizeId,
                           $lotId,
                           $finWhId,
                           $goodQty,
                           $packingId,
                           "Workflow packing completed into finished goods inventory",
                           $_SESSION['user_id'] ?? null
                       ]);

                    // Auto-transition lot status
                    $totPackedStmt = $db->prepare("SELECT COALESCE(SUM(packed_qty), 0) FROM packing_entries WHERE lot_id = ?");
                    $totPackedStmt->execute([$lotId]);
                    $totPackedForLot = (int)$totPackedStmt->fetchColumn();
                    if ($totPackedForLot >= $lotTotalPieces && $currentLotStatus !== 'Ready Stock') {
                        $db->prepare("UPDATE lots SET status = 'Ready Stock' WHERE id = ?")->execute([$lotId]);
                        $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, ?, 'Ready Stock', 'Lot fully packed into Finished Goods Stock via workflow', ?)")
                           ->execute([$lotId, $currentLotStatus, $_SESSION['user_id'] ?? null]);
                    } elseif ($totPackedForLot > 0 && $currentLotStatus !== 'Ready Stock' && $currentLotStatus !== 'Partially Packed') {
                        $db->prepare("UPDATE lots SET status = 'Partially Packed' WHERE id = ?")->execute([$lotId]);
                        $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, ?, 'Partially Packed', 'Lot partially packed into Finished Goods Stock via workflow', ?)")
                           ->execute([$lotId, $currentLotStatus, $_SESSION['user_id'] ?? null]);
                    }
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

                // Auto-advance to next workflow step
                $nextOp = get_next_lot_workflow_operation($db, $lotId, $operationId);
                if ($nextOp) {
                    $nextOpId = (int)$nextOp['operation_id'];
                    $nextMode = $nextOp['default_mode'] ?? 'single';
                    $feedbackMsg .= " — اگلا مرحلہ لوڈ ہو گیا: {$nextOp['op_name']}";
                    redirect("modules/production/index.php?lot_id=$lotId&operation_id=$nextOpId&mode=$nextMode", $feedbackMsg);
                } else {
                    $feedbackMsg .= " — اس لاٹ کے تمام ورک فلو مراحل مکمل ہو گئے!";
                    redirect("modules/production/index.php?lot_id=$lotId", $feedbackMsg);
                }
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

// Fetch cutting-specific workers for cutting master selector
$cuttingWorkers = $db->query("SELECT w.*, dep.name AS department_name 
                              FROM workers w 
                              LEFT JOIN departments dep ON w.department_id = dep.id 
                              WHERE w.is_active = 1 
                                AND (dep.name LIKE '%Cut%' OR w.name LIKE '%Cut%' OR w.code LIKE '%CUT%' 
                                     OR w.id IN (SELECT worker_id FROM worker_operations wo JOIN operations o ON wo.operation_id = o.id WHERE o.name LIKE '%Cut%'))
                              ORDER BY w.name ASC")->fetchAll();
if (empty($cuttingWorkers)) {
    $cuttingWorkers = $workers;
}

// Fetch cutting-specific workers for cutting master selector
$cuttingWorkers = $db->query("SELECT w.*, dep.name AS department_name 
                              FROM workers w 
                              LEFT JOIN departments dep ON w.department_id = dep.id 
                              WHERE w.is_active = 1 
                                AND (dep.name LIKE '%Cut%' OR w.name LIKE '%Cut%' OR w.code LIKE '%CUT%' 
                                     OR w.id IN (SELECT worker_id FROM worker_operations wo JOIN operations o ON wo.operation_id = o.id WHERE o.name LIKE '%Cut%'))
                              ORDER BY w.name ASC")->fetchAll();
if (empty($cuttingWorkers)) {
    $cuttingWorkers = $workers;
}

// Recent 10 entries for live history panel
$recentEntries = $db->query("SELECT pe.*, l.lot_number, o.name AS op_name, w.name AS worker_name, c.name AS color_name, s.name AS size_name 
                            FROM production_entries pe 
                            JOIN lots l ON pe.lot_id = l.id 
                            JOIN operations o ON pe.operation_id = o.id 
                            JOIN workers w ON pe.worker_id = w.id 
                            LEFT JOIN colors c ON pe.color_id = c.id 
                            LEFT JOIN sizes s ON pe.size_id = s.id 
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

        <form method="POST" action="" id="prodEntryForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="entry_mode" id="pe_entry_mode" value="operator_initial">

            <!-- ROW 1: TOP CONTROL BAR & COMPACT RECENT SUBMISSIONS (SIDE BY SIDE AT TOP) -->
            <div class="row g-3 mb-3 align-items-stretch">
                <!-- Top Left: Work Entry Setup (Date, Lot, Stage Operation) -->
                <div class="col-12 col-xl-8">
                    <div class="card-modern shadow-sm h-100">
                        <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 px-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary p-2 rounded-2"><i class="bi bi-lightning-charge-fill text-white fs-6"></i></span>
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark">Work Entry Form</h6>
                                    <span class="text-muted small" style="font-size: 0.75rem;">Auto-calculates labour and verifies batch quantities</span>
                                </div>
                            </div>

                            <!-- Mode Switcher -->
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-primary fw-semibold" id="btnModeOperator">
                                    <i class="bi bi-person-workspace me-1"></i> 🧵 1. Operator (اعضاء و شفٹ)
                                </button>
                                <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnModeCutting">
                                    <i class="bi bi-scissors me-1"></i> ✂️ 2. Cutting (کٹنگ)
                                </button>
                                <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnModeMatrix">
                                    <i class="bi bi-grid-3x3-gap-fill me-1"></i> ⚡ 3. Fast Matrix (دیگر مراحل)
                                </button>
                                <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnModeSingle">
                                    <i class="bi bi-list-check me-1"></i> 4. Single Item
                                </button>
                            </div>
                        </div>
                        <div class="card-modern-body p-3">
                            <!-- UNIVERSAL TOP FIELDS: ENTRY DATE, PRODUCTION LOT, STAGE OPERATION -->
                            <div class="row g-3 mb-2">
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-semibold small mb-1">Entry Date *</label>
                                    <input type="date" name="entry_date" id="pe_entry_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label fw-semibold small mb-1">Select Production Lot *</label>
                                    <select name="lot_id" id="pe_lot_id" class="form-select form-select-sm fw-bold text-primary">
                                        <option value="">-- Choose Factory Lot --</option>
                                        <?php foreach ($lots as $l): ?>
                                            <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id'] || (isset($_POST['lot_id']) && $_POST['lot_id'] == $l['id'])) ? 'selected' : '' ?>>
                                                LOT #<?= clean($l['lot_number']) ?> — <?= clean($l['product_name']) ?> (<?= $l['design_number'] ? 'Design #' . clean($l['design_number']) : 'No Design' ?>) [<?= $l['total_pieces'] ?> pcs]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold small mb-1">Stage Operation *</label>
                                    <select name="operation_id" id="pe_operation_id" class="form-select form-select-sm fw-bold text-dark">
                                        <option value="">-- Select Lot First --</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Universal Lot Info Banner -->
                            <div id="opLotInfoCard" class="alert alert-light border shadow-sm p-2 px-3 rounded-3 mb-0 d-none">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div>
                                        <span class="fs-6 fw-bold text-dark me-2" id="opLotTitle">LOT #---</span>
                                        <span class="badge bg-primary" id="opLotPiecesBadge">0 pcs planned</span>
                                        <span class="badge bg-secondary ms-1" id="opLotShiftBadge">Single Shift</span>
                                        <span class="badge bg-info-subtle text-info-emphasis ms-1" id="opLotSizeBadge">Single Size</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="small fw-semibold text-muted">Current Stage:</span>
                                        <span class="badge bg-dark" id="opStageBadge">Initial Output</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Right: Compact Recent Submissions (Restricted to Top Height) -->
                <div class="col-12 col-xl-4">
                    <div class="card-modern shadow-sm h-100 d-flex flex-column" style="max-height: 230px;">
                        <div class="card-modern-header py-2 px-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark small"><i class="bi bi-clock-history text-primary me-1"></i> Recent Submissions</h6>
                                <span class="text-muted" style="font-size: 0.72rem;">Live factory entries</span>
                            </div>
                            <a href="<?= BASE_URL ?>modules/production/history.php" class="btn btn-xs btn-outline-secondary py-0 px-2" style="font-size: 0.72rem;">Full Log</a>
                        </div>
                        <div class="card-modern-body p-0 flex-grow-1 overflow-auto" style="max-height: 175px;">
                            <table class="table table-sm table-modern align-middle mb-0" style="font-size: 0.78rem;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Lot / Op</th>
                                        <th>Worker</th>
                                        <th class="text-center">Good</th>
                                        <th class="text-end">Labour</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentEntries)): ?>
                                        <tr><td colspan="4" class="text-center py-3 text-muted">No entries yet today.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recentEntries as $re): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold text-primary">#<?= clean($re['lot_number']) ?></span>
                                                    <div><?= clean($re['op_name']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.7rem;"><?= !empty($re['color_name']) ? clean($re['color_name']) . ' (' . clean($re['size_name']) . ')' : '<span class="text-secondary opacity-75">Batch / All</span>' ?></div>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold text-dark"><?= clean($re['worker_name']) ?></span>
                                                    <div class="text-muted" style="font-size: 0.7rem;"><?= format_date($re['entry_date']) ?></div>
                                                </td>
                                                <td class="text-center fw-bold text-success">
                                                    <?= number_format($re['good_qty']) ?>
                                                    <?php if ($re['rejected_qty'] > 0): ?>
                                                        <div class="text-danger small" style="font-size: 0.68rem;"><?= $re['rejected_qty'] ?> rej</div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark">
                                                    <?= format_currency($re['labour_amount']) ?>
                                                    <div>
                                                        <a href="<?= BASE_URL ?>modules/production/edit.php?id=<?= $re['id'] ?>" class="text-primary text-decoration-none small" style="font-size: 0.72rem;" title="Edit Entry">
                                                            <i class="bi bi-pencil-square"></i> Edit
                                                        </a>
                                                    </div>
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

            <!-- ROW 2: FULL WIDTH MAIN PRODUCTION WORKSPACE (100% WIDTH FOR MAX SPACE) -->
            <div class="row g-3">
                <div class="col-12">
                    <div class="card-modern shadow-sm p-3">

                            <!-- ================================================================= -->
                            <!-- SECTION 0: OPERATOR INITIAL PRODUCTION RUN (NO COLORS NEEDED) -->
                            <!-- ================================================================= -->
                            <div id="operatorSection" class="mt-2">
                                <!-- Hidden state inputs for operator initial entry -->
                                <input type="hidden" name="shift_type" id="op_shift_type" value="Single Shift">
                                <input type="hidden" name="size_mode" id="op_size_mode" value="Single Size">
                                <input type="hidden" name="single_size_id" id="op_single_size_id" value="">

                                <!-- Placeholder if no lot selected -->
                                <div id="operatorPlaceholder" class="p-5 text-center text-muted border border-dashed rounded-3 mb-3">
                                    <i class="bi bi-person-workspace display-5 text-primary opacity-50 mb-2 d-block"></i>
                                    <h6 class="fw-semibold text-dark mb-1">Select Lot for Operator Production Entry</h6>
                                    <p class="small text-muted mb-0">Choose a production batch above to load the assigned operator(s) and auto-balance produced pieces.</p>
                                </div>

                                <!-- Active Operator Entry Cards (Loaded when Lot selected) -->
                                <div id="operatorActiveCards" class="d-none">
                                    <!-- SINGLE SHIFT OPERATOR CARD -->
                                    <div id="singleShiftCard" class="card border border-primary-subtle bg-light p-3 rounded-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-bold text-dark"><i class="bi bi-sun-fill text-warning me-1"></i> ☀️ Day Shift Operator (ڈے آپریٹر)</span>
                                            <span class="badge bg-success-subtle text-success border" id="opSingleRateBadge">Rate: Rs. 0.00 / pc</span>
                                        </div>
                                        <div class="row g-3 align-items-center">
                                            <div class="col-12 col-md-6">
                                                <label class="form-label small fw-semibold text-muted mb-1">
                                                    <i class="bi bi-person-badge text-primary me-1"></i> Assigned Operator (مشین آپریٹر) *
                                                </label>
                                                <select name="day_worker_id" id="op_single_day_worker" class="form-select fw-semibold" required>
                                                    <option value="">-- Choose Operator --</option>
                                                    <?php foreach ($workers as $w): ?>
                                                        <option value="<?= $w['id'] ?>" data-minrate="<?= $w['rate_per_minute'] ?>"><?= clean($w['name']) ?> (<?= clean($w['code']) ?>)</option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="text-muted small mt-1">
                                                    <i class="bi bi-info-circle me-1"></i> تمام پارٹس (Front, Back, Sleeves) کی انٹری، اوقات اور ریٹس نیچے تفصیلی ٹیبل میں درج کریں۔
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <div class="p-3 bg-light rounded-3 border">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="text-muted small fw-semibold">Worker Minute Rate (ریٹ فی منٹ):</span>
                                                        <span class="badge bg-success-subtle text-success border fw-bold px-2 py-1" id="lblSingleMinRateBadge">Rs. 2.50/m</span>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                                        <span class="text-dark small fw-bold">Total Earned Labour (آج کی کل کمائی):</span>
                                                        <strong class="text-success fs-4" id="lblSingleDayLabour">Rs. 0.00</strong>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Hidden fallback inputs for backend calculations -->
                                        <input type="hidden" name="day_pieces" id="op_single_day_pieces" value="0">
                                        <input type="hidden" name="day_minutes_per_piece" id="op_single_minutes" value="0.0">
                                        <input type="hidden" name="day_worker_min_rate" id="op_single_min_rate" value="2.50">
                                        <input type="hidden" name="day_rate" id="op_single_rate" value="0.00">
                                    </div>

                                    <!-- DOUBLE SHIFT OPERATORS CARD (DAY + NIGHT AUTO-BALANCE) -->
                                    <div id="doubleShiftCard" class="d-none">
                                        <div class="row g-3 mb-3">
                                            <!-- Day Shift Operator Card -->
                                            <div class="col-12 col-md-6">
                                                <div class="card h-100 border border-warning-subtle bg-light p-3 rounded-3 shadow-sm">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="fw-bold text-dark"><i class="bi bi-sun-fill text-warning me-1"></i> ☀️ 1. Day Shift Operator</span>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border" id="lblDoubleDayMinRateBadge">Rs. 2.50/m</span>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small fw-semibold text-muted mb-1">Day Operator (ڈے آپریٹر) *</label>
                                                        <select name="day_worker_id" id="op_double_day_worker" class="form-select fw-semibold">
                                                            <option value="">-- Select Day Operator --</option>
                                                            <?php foreach ($workers as $w): ?>
                                                                <option value="<?= $w['id'] ?>" data-minrate="<?= $w['rate_per_minute'] ?>"><?= clean($w['name']) ?> (<?= clean($w['code']) ?>)</option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
                                                        <span class="text-muted small">Day Shift Labour:</span>
                                                        <strong class="text-success fs-5" id="lblDoubleDayLabour">Rs. 0.00</strong>
                                                    </div>
                                                    <input type="hidden" name="day_pieces" id="op_double_day_pieces" value="0">
                                                    <input type="hidden" name="day_minutes_per_piece" id="op_double_day_minutes" value="0.0">
                                                    <input type="hidden" name="day_worker_min_rate" id="op_double_day_min_rate" value="2.50">
                                                    <input type="hidden" name="day_rate" id="op_double_day_rate" value="0.00">
                                                </div>
                                            </div>

                                            <!-- Night Shift Operator Card -->
                                            <div class="col-12 col-md-6">
                                                <div class="card h-100 border border-primary-subtle bg-light p-3 rounded-3 shadow-sm">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="fw-bold text-dark"><i class="bi bi-moon-stars-fill text-primary me-1"></i> 🌙 2. Night Shift Operator</span>
                                                        <span class="badge bg-primary-subtle text-primary border" id="lblDoubleNightMinRateBadge">Rs. 2.50/m</span>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small fw-semibold text-muted mb-1">Night Operator (نائٹ آپریٹر) *</label>
                                                        <select name="night_worker_id" id="op_double_night_worker" class="form-select fw-semibold">
                                                            <option value="">-- Select Night Operator --</option>
                                                            <?php foreach ($workers as $w): ?>
                                                                <option value="<?= $w['id'] ?>" data-minrate="<?= $w['rate_per_minute'] ?>"><?= clean($w['name']) ?> (<?= clean($w['code']) ?>)</option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
                                                        <span class="text-muted small">Night Shift Labour:</span>
                                                        <strong class="text-success fs-5" id="lblDoubleNightLabour">Rs. 0.00</strong>
                                                    </div>
                                                    <input type="hidden" name="night_pieces" id="op_double_night_pieces" value="0">
                                                    <input type="hidden" name="night_minutes_per_piece" id="op_double_night_minutes" value="0.0">
                                                    <input type="hidden" name="night_worker_min_rate" id="op_double_night_min_rate" value="2.50">
                                                    <input type="hidden" name="night_rate" id="op_double_night_rate" value="0.00">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                        <!-- Quick Auto-Distribution Helper Toolbar -->
                                        <div class="p-2 bg-light border rounded-3 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                            <span class="small fw-semibold text-muted"><i class="bi bi-magic me-1"></i> Quick Auto-Balance Helpers:</span>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-secondary" id="btnSplit5050">⚖️ 50/50 Equal Split</button>
                                                <button type="button" class="btn btn-outline-secondary" id="btnSet100Day">☀️ 100% Day</button>
                                                <button type="button" class="btn btn-outline-secondary" id="btnSet100Night">🌙 100% Night</button>
                                            </div>
                                        </div>

                                    <!-- MULTI-SIZE BREAKDOWN (If Lot has multiple sizes) -->
                                    <div id="opMultiSizeSection" class="border rounded-3 p-3 bg-white mb-3 d-none">
                                        <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-grid-3x3 me-1 text-primary"></i> Multi-Size Operator Distribution (سائز وائز تقسیم)</h6>
                                        <div class="table-responsive">
                                            <table class="table table-bordered align-middle text-center mb-0" id="opMultiSizeTable">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Size</th>
                                                        <th>Planned Qty</th>
                                                        <th id="thDaySizeQty">☀️ Day Operator Pieces</th>
                                                        <th id="thNightSizeQty">🌙 Night Operator Pieces</th>
                                                        <th>Total Entered</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="opMultiSizeTbody"></tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Math Match Status Banner -->
                                    <div id="opMatchStatusBanner" class="alert alert-success py-2 px-3 small fw-semibold text-center mb-3">
                                        ✓ Total Pieces: <span id="lblOpSumTotal">0</span> / <span id="lblOpLotPlanned">0</span> pcs
                                    </div>

                                    <!-- GARMENT ELEMENTS BREAKDOWN TABLE (WHOLE SWEATER OR INDIVIDUAL PIECES) -->
                                    <div id="opElementsSection" class="border rounded-3 p-3 bg-white mb-3 shadow-sm">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                            <div>
                                                <h6 class="fw-bold mb-0 text-dark">
                                                    <i class="bi bi-puzzle-fill text-primary me-1"></i> Garment Elements Breakdown (حصوں کی تفصیلی انٹری — سویٹر یا الگ الگ حصے)
                                                </h6>
                                                <span class="text-muted small">Record specific pieces (e.g. only Fronts or Sleeves today) with custom average time & element rates</span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-light text-primary border"><i class="bi bi-calculator me-1"></i> Real-time Labour & Time Calculation</span>
                                            </div>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-hover align-middle text-center mb-0" id="opElementsTable" style="font-size: 0.85rem;">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="text-start" style="min-width: 140px;">Element (حصہ)</th>
                                                        <th style="min-width: 75px;">Target</th>
                                                        <th style="min-width: 75px;">Done</th>
                                                        <th style="min-width: 80px;">Pending</th>
                                                        <th id="thOpElDay" style="min-width: 130px;" class="table-primary">☀️ Today Produced *</th>
                                                        <th id="thOpElNight" style="min-width: 130px;" class="table-info d-none">🌙 Night Produced</th>
                                                        <th style="min-width: 110px;">⏱️ SAM (منٹ)</th>
                                                        <th style="min-width: 95px;">⚡ Min Rate</th>
                                                        <th style="min-width: 115px;">🏷️ Rate / pc</th>
                                                        <th style="min-width: 120px;" class="text-end">💰 Labour</th>
                                                        <th style="min-width: 105px;">Balance</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="opElementsTbody">
                                                    <tr><td colspan="11" class="text-muted py-4">Select a lot to view element details...</td></tr>
                                                </tbody>
                                                <tfoot class="table-light fw-bold" id="opElementsTfoot" style="display: none;">
                                                    <tr>
                                                        <td class="text-start">Total (خلاصہ):</td>
                                                        <td id="lblFootTarget" class="text-muted">-</td>
                                                        <td id="lblFootDone" class="text-info-emphasis">-</td>
                                                        <td id="lblFootPending" class="text-warning-emphasis">-</td>
                                                        <td id="lblFootTodayDay" class="text-primary">-</td>
                                                        <td id="lblFootTodayNight" class="text-info d-none">-</td>
                                                        <td colspan="3" class="text-end text-muted small">Total Labour:</td>
                                                        <td id="lblFootTotalLabour" class="text-end text-success fs-6">Rs. 0.00</td>
                                                        <td id="lblFootBalance">-</td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Action Button -->
                                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm" id="btnSubmitOperatorEntry">
                                        <i class="bi bi-check2-circle me-1"></i> Save Operator Production Entry (آپریٹر انٹری محفوظ کریں)
                                    </button>
                                </div>
                            </div>

                            <!-- ================================================================= -->
                            <!-- SECTION 1: CUTTING MASTER PRODUCTION ENTRY (STAGE 2) -->
                            <!-- ================================================================= -->
                            <div id="cuttingSection" class="mt-2 d-none">
                                <div class="card border border-warning-subtle bg-light p-3 rounded-3 mb-3 shadow-sm">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="fw-bold text-dark fs-6"><i class="bi bi-scissors text-danger me-1"></i> ✂️ Stage 2: Cutting Master Production (کٹنگ ماسٹر کی انٹری)</span>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border" id="cutRateBadge">Rate: Rs. 5.00/pc</span>
                                    </div>
                                    <div class="row g-3 align-items-center mb-3">
                                        <div class="col-12 col-md-5">
                                            <label class="form-label small fw-semibold text-muted mb-1">Cutting Master (کٹنگ ماسٹر) *</label>
                                            <select name="cutting_worker_id" id="cut_worker_id" class="form-select fw-semibold">
                                                <option value="">-- Choose Cutting Master --</option>
                                                <?php foreach ($cuttingWorkers as $w): ?>
                                                    <option value="<?= $w['id'] ?>" data-minrate="<?= $w['rate_per_minute'] ?>" <?= (stripos($w['name'], 'Cut') !== false ? 'selected' : '') ?>><?= clean($w['name']) ?> (<?= clean($w['code']) ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label small fw-semibold text-muted mb-1">Shift (شفٹ)</label>
                                            <select name="cutting_shift" id="cut_shift" class="form-select fw-semibold">
                                                <option value="Day Shift">☀️ Day Shift</option>
                                                <option value="Night Shift">🌙 Night Shift</option>
                                                <option value="General">General</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label small fw-semibold text-muted mb-1">Whole Sweaters Cut (مکمل سویٹر کٹنگ) *</label>
                                            <div class="input-group">
                                                <input type="number" name="cutting_whole_pieces" id="cut_whole_pieces" class="form-control form-control-lg fw-bold text-center text-primary fs-4" min="0" placeholder="0">
                                                <span class="input-group-text fw-bold">pcs</span>
                                            </div>
                                            <div class="small fw-semibold text-primary mt-1" id="lblCutWholeMaxHint">
                                                ✓ تیار موصولہ پیسز: <strong id="lblCutWholeMaxCount">0</strong> (زیادہ سے زیادہ کٹنگ کی گنجائش)
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Real-time SAM Minutes & Rate Calculation Scheme for Cutting -->
                                    <div class="p-3 bg-white border rounded-2 mb-3 shadow-sm">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label small fw-bold text-dark mb-0">
                                                <i class="bi bi-calculator text-primary me-1"></i> Rate & Time Scheme for Specific Lot (ریئل ٹائم ریٹ و وقت کا تعین)
                                            </label>
                                            <span class="badge bg-light text-muted border small" id="lblCutFormula">Minutes × Rate/min = Piece Rate</span>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small fw-semibold text-muted mb-1">وقت فی پیس (SAM Minutes)</label>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="cutting_minutes" id="cut_minutes" class="form-control fw-bold text-center text-primary" step="0.1" min="0" value="2.0">
                                                    <span class="input-group-text px-2 small text-muted">min</span>
                                                </div>
                                                <div class="form-text small" style="font-size: 10px;">لاٹ کٹنگ کا معیاری وقت</div>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small fw-semibold text-muted mb-1">کاریگر ریٹ فی منٹ (Rs/min)</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text px-2 small text-muted">Rs</span>
                                                    <input type="number" name="cutting_min_rate" id="cut_min_rate" class="form-control fw-bold text-center text-success" step="0.05" min="0" value="2.50">
                                                    <span class="input-group-text px-2 small text-muted">/m</span>
                                                </div>
                                                <div class="form-text small" style="font-size: 10px;">ماسٹر پروفائل ریٹ فی منٹ</div>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small fw-semibold text-muted mb-1">فائنل پیس ریٹ (Piece Rate)</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text px-2 small text-muted">Rs</span>
                                                    <input type="number" name="rate" id="cut_rate" class="form-control fw-bold text-end text-dark" step="0.25" min="0" value="5.00">
                                                    <span class="input-group-text px-2 small text-muted">/pc</span>
                                                </div>
                                                <div class="form-text small" style="font-size: 10px;">ریئل ٹائم خودکار حساب</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                        <span class="text-muted small">Earned Labour: <strong class="text-success fs-5 ms-1" id="lblCutLabour">Rs. 0.00</strong></span>
                                        <span class="small text-muted" id="cutLotPlannedNotice">Batch: 0 pcs</span>
                                    </div>
                                </div>

                                <!-- Color x Size Cutting Distribution Matrix -->
                                <div class="card border border-warning-subtle rounded-3 bg-white mb-3 shadow-sm" id="cutMatrixCard">
                                    <div class="card-header bg-warning-subtle border-warning-subtle d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark">
                                                <i class="bi bi-grid-3x3-gap-fill text-warning me-1"></i> Color & Size Cutting Distribution (رنگ اور سائز وائز کٹنگ کی گنتی)
                                            </h6>
                                            <span class="small text-muted">🟢 کٹ گئے (Good) | 🔴 خراب پیس (Rejected) | 🟡 ری ورک (Rework)</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-dark fw-bold" id="btnFillAllCutting">
                                                <i class="bi bi-lightning-charge-fill text-warning me-1"></i> ⚡ 1-Click Fill Planned
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearCutting">Clear</button>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm align-middle text-center mb-0" id="cutMatrixTable" style="font-size: 0.85rem;">
                                                <thead class="table-light" id="cutMatrixThead">
                                                    <tr><th>Select a lot to load color & size distribution...</th></tr>
                                                </thead>
                                                <tbody id="cutMatrixTbody">
                                                </tbody>
                                                <tfoot class="table-light fw-bold" id="cutMatrixTfoot">
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Elements Cutting Progress Table -->
                                <div class="card border p-3 rounded-3 bg-white mb-3 shadow-sm">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="fw-bold mb-0 text-dark">
                                            <i class="bi bi-puzzle-fill text-danger me-1"></i> Garment Elements Cutting Progress (اعضاء کی کٹنگ - موصولہ بمقابلہ کٹ گئے)
                                        </h6>
                                        <span class="small text-muted">Enter whole sweaters above OR edit individual parts below</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle text-center mb-0" id="cutElementsTable" style="font-size: 0.85rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th class="text-start">Element (حصہ)</th>
                                                    <th>Ratio</th>
                                                    <th>Ready from Operator</th>
                                                    <th>Already Cut</th>
                                                    <th style="width: 140px;">Now Cutting (اب کٹ رہے ہیں)</th>
                                                    <th>Remaining to Cut</th>
                                                </tr>
                                            </thead>
                                            <tbody id="cutElementsTbody">
                                                <tr><td colspan="6" class="text-muted">Select a lot to view cutting progress...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-warning btn-lg w-100 fw-bold shadow-sm" id="btnSubmitCutting">
                                    <i class="bi bi-scissors me-1"></i> Save Cutting Production Entry (کٹنگ انٹری محفوظ کریں)
                                </button>
                            </div>

                            <!-- ================================================================= -->
                            <!-- DOWNSTREAM FIELDS CONTAINER (FOR MATRIX & SINGLE ITEM MODES) -->
                            <!-- ================================================================= -->
                            <div id="downstreamCommonFields" class="row g-3 d-none">
                                <!-- Worker & Piece Rate Scheme -->
                                <div class="col-12 col-md-5">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0">Worker / Operator *</label>
                                        <span id="workerCountBadge" class="badge bg-light text-secondary border small d-none">0 matching</span>
                                    </div>
                                    <select name="worker_id" id="pe_worker_id" class="form-select fw-semibold">
                                        <option value="">-- Select Operation First --</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-7">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0">Rate Calculation Scheme (منٹ ریٹ کا حساب)</label>
                                        <span class="text-success small fw-bold" id="lblFormulaBadge">
                                            <i class="bi bi-calculator me-1"></i>Minutes × Rate/min = Piece Rate
                                        </span>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-4">
                                            <div class="input-group">
                                                <input type="number" name="minutes_per_piece" id="pe_minutes" class="form-control fw-bold text-center text-primary" step="0.1" min="0" value="0.0" placeholder="SAM Min" title="Operation Standard Time (SAM Minutes per piece)">
                                                <span class="input-group-text px-1 small text-muted">min</span>
                                            </div>
                                            <div class="form-text text-center small" style="font-size: 10px;">وقت فی پیس</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="input-group">
                                                <span class="input-group-text px-1 small text-muted">Rs</span>
                                                <input type="number" name="worker_minute_rate" id="pe_min_rate" class="form-control fw-bold text-center text-success" step="0.05" min="0" value="2.50" placeholder="Rs/min" title="Worker's agreed rate per minute">
                                                <span class="input-group-text px-1 small text-muted">/m</span>
                                            </div>
                                            <div class="form-text text-center small" style="font-size: 10px;">ریٹ فی منٹ</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="input-group">
                                                <span class="input-group-text px-1 small text-muted">Rs</span>
                                                <input type="number" name="rate" id="pe_rate" class="form-control fw-bold text-end text-dark" step="0.25" min="0" value="0.00" title="Final Piece Rate">
                                                <span class="input-group-text px-1 small text-muted">/pc</span>
                                            </div>
                                            <div class="form-text text-center small" style="font-size: 10px;">پیس ریٹ</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dynamic Scheme Indicator Banner -->
                                <div class="col-12 mt-2">
                                    <div class="alert alert-light border py-2 px-3 d-flex flex-wrap justify-content-between align-items-center small mb-0 rounded-2 text-muted">
                                        <div>
                                            <i class="bi bi-info-circle text-primary me-1"></i>
                                            <span id="rateBreakdownText">
                                                <span class="fw-bold text-primary" id="lblSamMins">0.0</span> منٹ فی پیس &times; 
                                                <span class="fw-bold text-success" id="lblWorkerMinRate">Rs. 2.50</span> فی منٹ = 
                                                <strong class="text-dark fs-6">Rs. <span id="lblCalculatedPieceRate">0.00</span></strong> فی پیس
                                            </span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span id="lblTotalBatchTime" class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                                <i class="bi bi-clock-history me-1"></i>Batch Work: 0 mins
                                            </span>
                                        </div>
                                    </div>
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
                                        <button type="button" id="btnPackSets" class="btn btn-outline-info btn-sm d-flex align-items-center gap-1 shadow-sm d-none" title="Pack assorted color sets (1 of each color per size)">
                                            <i class="bi bi-box-seam-fill"></i>
                                            <span>📦 Pack Color Sets (سیٹ پیک)</span>
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

                    </div>
                </div>
            </div>
        </form>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const lotSelect = document.getElementById('pe_lot_id');
    const opSelect = document.getElementById('pe_operation_id');
    const workerSelect = document.getElementById('pe_worker_id');
    const workerBadge = document.getElementById('workerCountBadge');
    const rateInput = document.getElementById('pe_rate');
    const minInput = document.getElementById('pe_minutes');
    const minRateInput = document.getElementById('pe_min_rate');
    const lblSamMins = document.getElementById('lblSamMins');
    const lblWorkerMinRate = document.getElementById('lblWorkerMinRate');
    const lblCalculatedPieceRate = document.getElementById('lblCalculatedPieceRate');
    const lblTotalBatchTime = document.getElementById('lblTotalBatchTime');
    const preselectedOpId = <?= (int)$preselectedOpId ?>;
    const preselectedMode = <?= json_encode($preselectedMode) ?>;

    // Mode Toggle Elements (3 Modes)
    const btnModeOperator = document.getElementById('btnModeOperator');
    const btnModeMatrix = document.getElementById('btnModeMatrix');
    const btnModeSingle = document.getElementById('btnModeSingle');
    const modeInput = document.getElementById('pe_entry_mode');
    const operatorSection = document.getElementById('operatorSection');
    const downstreamCommonFields = document.getElementById('downstreamCommonFields');
    const matrixSection = document.getElementById('matrixSection');
    const singleSection = document.getElementById('singleSection');

    // Operator Mode Elements (mapped to unified top selectors)
    const opLotSelect = lotSelect;
    const opOperationSelect = opSelect;
    const opPlaceholder = document.getElementById('operatorPlaceholder');
    const opActiveCards = document.getElementById('operatorActiveCards');
    const opLotInfoCard = document.getElementById('opLotInfoCard');
    const opLotTitle = document.getElementById('opLotTitle');
    const opLotPiecesBadge = document.getElementById('opLotPiecesBadge');
    const opLotShiftBadge = document.getElementById('opLotShiftBadge');
    const opLotSizeBadge = document.getElementById('opLotSizeBadge');
    const opStageBadge = document.getElementById('opStageBadge');

    const singleShiftCard = document.getElementById('singleShiftCard');
    const doubleShiftCard = document.getElementById('doubleShiftCard');

    const opSingleWorker = document.getElementById('op_single_day_worker');
    const opSinglePieces = document.getElementById('op_single_day_pieces');
    const opSingleRateBadge = document.getElementById('opSingleRateBadge');
    const lblSingleDayLabour = document.getElementById('lblSingleDayLabour');
    const opSingleMinutes = document.getElementById('op_single_minutes');
    const opSingleMinRate = document.getElementById('op_single_min_rate');
    const opSingleRate = document.getElementById('op_single_rate');

    const opDoubleDayWorker = document.getElementById('op_double_day_worker');
    const opDoubleDayPieces = document.getElementById('op_double_day_pieces');
    const opDoubleDayRateBadge = document.getElementById('opDoubleDayRateBadge');
    const lblDoubleDayLabour = document.getElementById('lblDoubleDayLabour');
    const opDoubleDayMinutes = document.getElementById('op_double_day_minutes');
    const opDoubleDayMinRate = document.getElementById('op_double_day_min_rate');
    const opDoubleDayRate = document.getElementById('op_double_day_rate');

    const opDoubleNightWorker = document.getElementById('op_double_night_worker');
    const opDoubleNightPieces = document.getElementById('op_double_night_pieces');
    const opDoubleNightRateBadge = document.getElementById('opDoubleNightRateBadge');
    const lblDoubleNightLabour = document.getElementById('lblDoubleNightLabour');
    const opDoubleNightMinutes = document.getElementById('op_double_night_minutes');
    const opDoubleNightMinRate = document.getElementById('op_double_night_min_rate');
    const opDoubleNightRate = document.getElementById('op_double_night_rate');

    const btnSplit5050 = document.getElementById('btnSplit5050');
    const btnSet100Day = document.getElementById('btnSet100Day');
    const btnSet100Night = document.getElementById('btnSet100Night');

    const opMultiSizeSection = document.getElementById('opMultiSizeSection');
    const opMultiSizeTbody = document.getElementById('opMultiSizeTbody');
    const lblOpSumTotal = document.getElementById('lblOpSumTotal');
    const lblOpLotPlanned = document.getElementById('lblOpLotPlanned');
    const opMatchStatusBanner = document.getElementById('opMatchStatusBanner');

    let currentOpLotData = null;

    const btnModeCutting = document.getElementById('btnModeCutting');
    const cuttingSection = document.getElementById('cuttingSection');
    const cutWorker = document.getElementById('cut_worker_id');
    const cutWholePcs = document.getElementById('cut_whole_pieces');
    const lblCutLabour = document.getElementById('lblCutLabour');
    const cutElementsTbody = document.getElementById('cutElementsTbody');
    const opElementsTbody = document.getElementById('opElementsTbody');

    function getMaxWholeReadyToCut() {
        if (!currentOpLotData || !currentOpLotData.elements || currentOpLotData.elements.length === 0) {
            return (currentOpLotData && currentOpLotData.max_whole_ready_to_cut !== undefined)
                ? parseInt(currentOpLotData.max_whole_ready_to_cut, 10)
                : 0;
        }
        let minWhole = null;
        currentOpLotData.elements.forEach(el => {
            const mult = parseInt(el.quantity || el.multiplier || 1) || 1;
            const ready = parseInt(el.operator_total || 0) || 0;
            const alreadyCut = parseInt(el.cut_pieces || 0) || 0;
            const avail = Math.max(0, ready - alreadyCut);
            const wholeThisEl = Math.floor(avail / mult);
            if (minWhole === null || wholeThisEl < minWhole) {
                minWhole = wholeThisEl;
            }
        });
        return minWhole !== null ? minWhole : 0;
    }

    function switchMode(mode) {
        modeInput.value = mode;

        if (btnModeOperator) btnModeOperator.className = 'btn btn-outline-secondary fw-semibold';
        if (btnModeCutting) btnModeCutting.className = 'btn btn-outline-secondary fw-semibold';
        if (btnModeMatrix) btnModeMatrix.className = 'btn btn-outline-secondary fw-semibold';
        if (btnModeSingle) btnModeSingle.className = 'btn btn-outline-secondary fw-semibold';

        const goodInput = document.getElementById('pe_good_qty');
        const varSelect = document.getElementById('pe_variant_key');
        if (goodInput) goodInput.removeAttribute('required');
        if (varSelect) varSelect.removeAttribute('required');

        if (mode === 'operator_initial') {
            if (btnModeOperator) btnModeOperator.className = 'btn btn-primary fw-semibold';
            operatorSection.classList.remove('d-none');
            if (cuttingSection) cuttingSection.classList.add('d-none');
            downstreamCommonFields.classList.add('d-none');
            matrixSection.classList.add('d-none');
            singleSection.classList.add('d-none');

            // Select operator operation in dropdown if available (and not already set)
            const curSelectedText = opSelect.options[opSelect.selectedIndex] ? opSelect.options[opSelect.selectedIndex].textContent.toLowerCase() : '';
            const alreadyMatches = curSelectedText.includes('operator') || curSelectedText.includes('knitt');
            if (!alreadyMatches) {
                for (let opt of opSelect.options) {
                    if (!opt.value) continue;
                    const t = opt.textContent.toLowerCase();
                    if (t.includes('operator') || t.includes('knitt')) {
                        opSelect.value = opt.value;
                        break;
                    }
                }
            }

            if (lotSelect && lotSelect.value) {
                if (opPlaceholder) opPlaceholder.classList.add('d-none');
                if (opActiveCards) opActiveCards.classList.remove('d-none');
                if (opLotInfoCard) opLotInfoCard.classList.remove('d-none');
                updateOperatorRatesAndQuantities();
            } else {
                if (opPlaceholder) opPlaceholder.classList.remove('d-none');
                if (opActiveCards) opActiveCards.classList.add('d-none');
                if (opLotInfoCard) opLotInfoCard.classList.add('d-none');
            }
        } else if (mode === 'cutting') {
            if (btnModeCutting) btnModeCutting.className = 'btn btn-warning fw-bold text-dark';
            operatorSection.classList.add('d-none');
            if (cuttingSection) cuttingSection.classList.remove('d-none');
            downstreamCommonFields.classList.add('d-none');
            matrixSection.classList.add('d-none');
            singleSection.classList.add('d-none');

            // Select cutting operation in dropdown if available (and not already set)
            let cuttingOpVal = opSelect.value;
            const curCutText = opSelect.options[opSelect.selectedIndex] ? opSelect.options[opSelect.selectedIndex].textContent.toLowerCase() : '';
            if (!curCutText.includes('cut')) {
                for (let opt of opSelect.options) {
                    if (!opt.value) continue;
                    if (opt.textContent.toLowerCase().includes('cut')) {
                        opSelect.value = opt.value;
                        cuttingOpVal = opt.value;
                        break;
                    }
                }
            }

            updateCuttingRate(false);

            const maxWhole = getMaxWholeReadyToCut();
            if (cutWholePcs) {
                cutWholePcs.max = maxWhole;
                if (!cutWholePcs.dataset.userEdited || parseInt(cutWholePcs.value, 10) > maxWhole || parseInt(cutWholePcs.value, 10) <= 0) {
                    cutWholePcs.value = maxWhole > 0 ? maxWhole : 0;
                }
            }
            const lblHint = document.getElementById('lblCutWholeMaxCount');
            if (lblHint) lblHint.textContent = maxWhole;

            const plannedNotice = document.getElementById('cutLotPlannedNotice');
            if (plannedNotice) {
                const totPlanned = (currentOpLotData && currentOpLotData.lot) ? currentOpLotData.lot.total_pieces : 0;
                plannedNotice.innerHTML = `Ready: <strong class="text-primary">${maxWhole}</strong> / Planned: ${totPlanned} pcs`;
            }

            if (lotSelect.value && cuttingOpVal) {
                loadCuttingMatrixGrid(lotSelect.value, cuttingOpVal);
            }

            if (currentOpLotData && currentOpLotData.elements) {
                renderCuttingElements(currentOpLotData.elements, parseInt(cutWholePcs ? cutWholePcs.value : 0) || 0);
            }
        } else if (mode === 'matrix') {
            if (btnModeMatrix) btnModeMatrix.className = 'btn btn-primary fw-semibold';
            operatorSection.classList.add('d-none');
            if (cuttingSection) cuttingSection.classList.add('d-none');
            downstreamCommonFields.classList.remove('d-none');
            matrixSection.classList.remove('d-none');
            singleSection.classList.add('d-none');

            // If current selected operation is operator or cutting, switch to first downstream
            const curText = opSelect.options[opSelect.selectedIndex] ? opSelect.options[opSelect.selectedIndex].textContent.toLowerCase() : '';
            if (curText.includes('operat') || curText.includes('knitt') || curText.includes('cut')) {
                for (let opt of opSelect.options) {
                    const t = opt.textContent.toLowerCase();
                    if (opt.value && !t.includes('operat') && !t.includes('knitt') && !t.includes('cut')) {
                        opSelect.value = opt.value;
                        opSelect.dispatchEvent(new Event('change'));
                        break;
                    }
                }
            }
        } else if (mode === 'single') {
            if (btnModeSingle) btnModeSingle.className = 'btn btn-primary fw-semibold';
            operatorSection.classList.add('d-none');
            if (cuttingSection) cuttingSection.classList.add('d-none');
            downstreamCommonFields.classList.remove('d-none');
            matrixSection.classList.add('d-none');
            singleSection.classList.remove('d-none');

            if (goodInput) goodInput.setAttribute('required', 'required');
            if (varSelect) varSelect.setAttribute('required', 'required');
            checkBalance();
        }
    }

    if (btnModeCutting) {
        btnModeCutting.addEventListener('click', () => switchMode('cutting'));
    }

    function renderOperatorElements(elements, dayPcs, nightPcs) {
        if (!opElementsTbody) return;
        const isDouble = (currentOpLotData && currentOpLotData.lot && currentOpLotData.lot.shift_type === 'Double Shift');
        const thOpElDay = document.getElementById('thOpElDay');
        const thOpElNight = document.getElementById('thOpElNight');
        const lblFootTodayNight = document.getElementById('lblFootTodayNight');
        const tfoot = document.getElementById('opElementsTfoot');

        if (thOpElNight) {
            if (isDouble) {
                thOpElNight.classList.remove('d-none');
                if (lblFootTodayNight) lblFootTodayNight.classList.remove('d-none');
                if (thOpElDay) thOpElDay.innerHTML = '☀️ Day Produced *';
            } else {
                thOpElNight.classList.add('d-none');
                if (lblFootTodayNight) lblFootTodayNight.classList.add('d-none');
                if (thOpElDay) thOpElDay.innerHTML = '☀️ Today Produced *';
            }
        }

        if (!elements || elements.length === 0) {
            const colSpan = isDouble ? 11 : 10;
            opElementsTbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-muted py-3">No anatomical elements defined for this product.</td></tr>`;
            if (tfoot) tfoot.style.display = 'none';
            return;
        }

        // Determine current worker minute rate
        const workerOpt = isDouble ? (opDoubleDayWorker?.selectedOptions[0]) : (opSingleWorker?.selectedOptions[0]);
        const workerMinRate = workerOpt ? (parseFloat(workerOpt.dataset.minrate) || 2.50) : 2.50;
        const selectedOpOpt = opOperationSelect ? opOperationSelect.selectedOptions[0] : null;
        const opDefRate = selectedOpOpt ? (parseFloat(selectedOpOpt.dataset.rate) || 5.0) : 5.0;

        let html = '';
        elements.forEach(el => {
            const mult = parseInt(el.quantity || el.multiplier || 1);
            const req = parseInt(el.required_pieces) || 0;
            const done = parseInt(el.operator_total || el.operator1_pieces || 0);
            const pending = Math.max(0, req - done);
            const sam = parseFloat(el.worker_custom_sam !== null && el.worker_custom_sam !== undefined && el.worker_custom_sam !== '' ? el.worker_custom_sam : el.default_sam_minutes) || 0;
            let pieceRate = opDefRate;
            if (el.worker_custom_rate !== null && el.worker_custom_rate !== undefined && el.worker_custom_rate !== '') {
                pieceRate = parseFloat(el.worker_custom_rate) || 0;
            } else if (sam > 0 && workerMinRate > 0) {
                pieceRate = sam * workerMinRate;
            }

            // Default today's pieces: if dayPcs passed, scale by multiplier capped at pending
            let defDay = pending > 0 ? (dayPcs > 0 ? Math.min(pending, dayPcs * mult) : pending) : 0;
            let defNight = isDouble ? (nightPcs > 0 ? Math.min(Math.max(0, pending - defDay), nightPcs * mult) : 0) : 0;
            const todayTot = defDay + defNight;
            const newBal = Math.max(0, pending - todayTot);
            const elLabour = todayTot * pieceRate;

            html += `
                <tr data-elid="${el.id}" data-mult="${mult}" data-req="${req}" data-done="${done}" data-pending="${pending}">
                    <td class="text-start fw-bold text-dark">
                        <input type="hidden" name="elements[${el.id}][name]" value="${el.element_name}">
                        <input type="hidden" name="elements[${el.id}][required]" value="${req}">
                        <input type="hidden" name="elements[${el.id}][previous_done]" value="${done}">
                        <span class="text-primary me-1">🧵</span> ${el.element_name} 
                        <span class="badge bg-light text-muted border">×${mult}</span>
                    </td>
                    <td class="fw-bold text-muted">${req}</td>
                    <td class="fw-semibold text-info-emphasis">${done}</td>
                    <td class="fw-bold text-warning-emphasis">${pending}</td>
                    <td style="width: 125px;">
                        <input type="number" name="elements[${el.id}][op1_pieces]" class="form-control form-control-sm text-center fw-bold op-el-day text-primary" value="${defDay}" min="0" max="${pending}" title="Pending: ${pending}">
                    </td>
                    ${isDouble ? `
                    <td style="width: 125px;">
                        <input type="number" name="elements[${el.id}][op2_pieces]" class="form-control form-control-sm text-center fw-bold op-el-night text-info" value="${defNight}" min="0" max="${pending}" title="Pending: ${pending}">
                    </td>
                    ` : `
                    <input type="hidden" name="elements[${el.id}][op2_pieces]" class="op-el-night" value="0">
                    `}
                    <td style="width: 110px;">
                        <div class="input-group input-group-sm">
                            <input type="number" name="elements[${el.id}][sam_minutes]" class="form-control form-control-sm text-center fw-bold text-primary op-el-sam" value="${sam.toFixed(1)}" step="0.1" min="0">
                            <span class="input-group-text px-1 small text-muted">m</span>
                        </div>
                    </td>
                    <td class="text-muted small op-el-minrate">
                        Rs. ${workerMinRate.toFixed(2)}/m
                    </td>
                    <td style="width: 115px;">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text px-1 small text-muted">Rs</span>
                            <input type="number" name="elements[${el.id}][rate]" class="form-control form-control-sm text-center fw-bold text-dark op-el-rate" value="${pieceRate.toFixed(2)}" step="0.25" min="0">
                        </div>
                    </td>
                    <td class="text-end fw-bold text-success op-el-labour">
                        Rs. ${elLabour.toFixed(2)}
                    </td>
                    <td class="fw-bold op-el-bal ${newBal > 0 ? 'text-warning' : 'text-success'}">
                        ${newBal > 0 ? (newBal + ' pending') : '✓ Done'}
                    </td>
                </tr>
            `;
        });
        opElementsTbody.innerHTML = html;
        if (tfoot) tfoot.style.display = '';

        bindOperatorElementRowEvents();
        recalcElementRowTotals();
    }

    function bindOperatorElementRowEvents() {
        if (!opElementsTbody) return;
        const isDouble = (currentOpLotData && currentOpLotData.lot && currentOpLotData.lot.shift_type === 'Double Shift');

        opElementsTbody.querySelectorAll('tr[data-elid]').forEach(tr => {
            const dayInp = tr.querySelector('.op-el-day');
            const nightInp = tr.querySelector('.op-el-night');
            const samInp = tr.querySelector('.op-el-sam');
            const rateInp = tr.querySelector('.op-el-rate');

            function onRowChange(trigger) {
                tr.dataset.customEdited = '1';
                const pending = parseInt(tr.dataset.pending) || 0;
                let d = parseInt(dayInp ? dayInp.value : 0) || 0;
                let n = parseInt(nightInp ? nightInp.value : 0) || 0;
                if (d < 0) { d = 0; if (dayInp) dayInp.value = 0; }
                if (n < 0) { n = 0; if (nightInp) nightInp.value = 0; }

                if (d + n > pending) {
                    if (trigger === 'day') {
                        if (d > pending) { d = pending; dayInp.value = pending; }
                        n = Math.max(0, pending - d);
                        if (nightInp) nightInp.value = n;
                    } else if (trigger === 'night') {
                        if (n > pending) { n = pending; nightInp.value = pending; }
                        d = Math.max(0, pending - n);
                        if (dayInp) dayInp.value = d;
                    }
                }

                const workerOpt = isDouble ? (opDoubleDayWorker?.selectedOptions[0]) : (opSingleWorker?.selectedOptions[0]);
                const workerMinRate = workerOpt ? (parseFloat(workerOpt.dataset.minrate) || 2.50) : 2.50;

                if (trigger === 'sam') {
                    const sam = parseFloat(samInp?.value) || 0;
                    if (rateInp) {
                        rateInp.value = (sam * workerMinRate).toFixed(2);
                    }
                }

                const curRate = parseFloat(rateInp ? rateInp.value : 0) || 0;
                const rowLabour = (d + n) * curRate;
                const newBal = Math.max(0, pending - (d + n));

                const labourTd = tr.querySelector('.op-el-labour');
                if (labourTd) labourTd.textContent = 'Rs. ' + rowLabour.toFixed(2);

                const balTd = tr.querySelector('.op-el-bal');
                if (balTd) {
                    balTd.textContent = newBal > 0 ? (newBal + ' pending') : '✓ Done';
                    balTd.className = 'fw-bold op-el-bal ' + (newBal > 0 ? 'text-warning' : 'text-success');
                }

                recalcElementRowTotals();
            }

            if (dayInp) dayInp.addEventListener('input', () => onRowChange('day'));
            if (nightInp) nightInp.addEventListener('input', () => onRowChange('night'));
            if (samInp) samInp.addEventListener('input', () => onRowChange('sam'));
            if (rateInp) rateInp.addEventListener('input', () => onRowChange('rate'));
        });
    }

    function recalcElementRowTotals() {
        if (!opElementsTbody) return;
        const isDouble = (currentOpLotData && currentOpLotData.lot && currentOpLotData.lot.shift_type === 'Double Shift');

        let totTarget = 0, totDone = 0, totPending = 0, totDay = 0, totNight = 0, totLabour = 0, totBal = 0;
        let dayLabourSum = 0, nightLabourSum = 0;

        opElementsTbody.querySelectorAll('tr[data-elid]').forEach(tr => {
            const req = parseInt(tr.dataset.req) || 0;
            const done = parseInt(tr.dataset.done) || 0;
            const pending = parseInt(tr.dataset.pending) || 0;
            const d = parseInt(tr.querySelector('.op-el-day')?.value) || 0;
            const n = parseInt(tr.querySelector('.op-el-night')?.value) || 0;
            const rate = parseFloat(tr.querySelector('.op-el-rate')?.value) || 0;

            const rowDayLab = d * rate;
            const rowNightLab = n * rate;
            const newBal = Math.max(0, pending - (d + n));

            totTarget += req;
            totDone += done;
            totPending += pending;
            totDay += d;
            totNight += n;
            totLabour += (rowDayLab + rowNightLab);
            dayLabourSum += rowDayLab;
            nightLabourSum += rowNightLab;
            totBal += newBal;
        });

        const fTarget = document.getElementById('lblFootTarget');
        const fDone = document.getElementById('lblFootDone');
        const fPending = document.getElementById('lblFootPending');
        const fTodayDay = document.getElementById('lblFootTodayDay');
        const fTodayNight = document.getElementById('lblFootTodayNight');
        const fTotalLabour = document.getElementById('lblFootTotalLabour');
        const fBalance = document.getElementById('lblFootBalance');

        if (fTarget) fTarget.textContent = totTarget;
        if (fDone) fDone.textContent = totDone;
        if (fPending) fPending.textContent = totPending;
        if (fTodayDay) fTodayDay.textContent = totDay + ' pcs';
        if (fTodayNight) fTodayNight.textContent = totNight + ' pcs';
        if (fTotalLabour) fTotalLabour.textContent = 'Rs. ' + totLabour.toFixed(2);
        if (fBalance) fBalance.textContent = totBal > 0 ? (totBal + ' pending') : '✓ Done';

        if (!isDouble) {
            if (lblSingleDayLabour) lblSingleDayLabour.textContent = 'Rs. ' + dayLabourSum.toFixed(2);
        } else {
            if (lblDoubleDayLabour) lblDoubleDayLabour.textContent = 'Rs. ' + dayLabourSum.toFixed(2);
            if (lblDoubleNightLabour) lblDoubleNightLabour.textContent = 'Rs. ' + nightLabourSum.toFixed(2);
        }
    }

    function syncElementsFromOperatorPieces() {
        if (!opElementsTbody) return;
        const isDouble = (currentOpLotData && currentOpLotData.lot && currentOpLotData.lot.shift_type === 'Double Shift');
        const dayPcs = isDouble 
            ? (parseInt(opDoubleDayPieces ? opDoubleDayPieces.value : 0) || 0)
            : (parseInt(opSinglePieces ? opSinglePieces.value : 0) || 0);
        const nightPcs = isDouble 
            ? (parseInt(opDoubleNightPieces ? opDoubleNightPieces.value : 0) || 0)
            : 0;

        const rows = opElementsTbody.querySelectorAll('tr[data-elid]');
        if (rows.length === 0 && currentOpLotData && currentOpLotData.elements) {
            renderOperatorElements(currentOpLotData.elements, dayPcs, nightPcs);
            return;
        }

        rows.forEach(tr => {
            if (tr.dataset.customEdited === '1') return;
            const mult = parseInt(tr.dataset.mult) || 1;
            const pending = parseInt(tr.dataset.pending) || 0;

            let d = Math.min(pending, dayPcs * mult);
            let n = isDouble ? Math.min(Math.max(0, pending - d), nightPcs * mult) : 0;
            if (d + n > pending) {
                n = Math.max(0, pending - d);
            }

            const dayInp = tr.querySelector('.op-el-day');
            const nightInp = tr.querySelector('.op-el-night');

            if (dayInp) dayInp.value = d;
            if (nightInp) nightInp.value = n;

            const rate = parseFloat(tr.querySelector('.op-el-rate')?.value) || 0;
            const rowLabour = (d + n) * rate;
            const newBal = Math.max(0, pending - (d + n));

            const labourTd = tr.querySelector('.op-el-labour');
            if (labourTd) labourTd.textContent = 'Rs. ' + rowLabour.toFixed(2);

            const balTd = tr.querySelector('.op-el-bal');
            if (balTd) {
                balTd.textContent = newBal > 0 ? (newBal + ' pending') : '✓ Done';
                balTd.className = 'fw-bold op-el-bal ' + (newBal > 0 ? 'text-warning' : 'text-success');
            }
        });
        recalcElementRowTotals();
    }

    function renderCuttingElements(elements, wholeCutPcs) {
        if (!cutElementsTbody) return;
        if (!elements || elements.length === 0) {
            cutElementsTbody.innerHTML = '<tr><td colspan="6" class="text-muted py-3">No anatomical elements defined for this product.</td></tr>';
            return;
        }
        const maxWhole = getMaxWholeReadyToCut();
        const wholeNum = parseInt(wholeCutPcs, 10);

        let html = '';
        elements.forEach(el => {
            const mult = parseInt(el.quantity || el.multiplier || 1) || 1;
            const ready = parseInt(el.operator_total || 0) || 0;
            const alreadyCut = parseInt(el.cut_pieces || 0) || 0;
            const availToCut = Math.max(0, ready - alreadyCut);

            let nowCut = 0;
            if (!isNaN(wholeNum) && wholeNum > 0) {
                nowCut = Math.min(availToCut, wholeNum * mult);
            } else if (isNaN(wholeNum) || (wholeNum === 0 && (!cutWholePcs || !cutWholePcs.dataset.userEdited))) {
                nowCut = Math.min(availToCut, maxWhole * mult);
            } else {
                nowCut = 0;
            }
            const rem = Math.max(0, availToCut - nowCut);

            html += `
                <tr data-elid="${el.id}" data-mult="${mult}" data-ready="${ready}" data-cut="${alreadyCut}" data-avail="${availToCut}">
                    <td class="text-start fw-bold text-dark">${el.element_name}</td>
                    <td><span class="badge bg-light text-muted border">×${mult}</span></td>
                    <td class="fw-bold text-primary">${ready}</td>
                    <td class="fw-bold text-secondary">${alreadyCut}</td>
                    <td>
                        <input type="number" name="elements_cut[${el.id}]" class="form-control form-control-sm text-center fw-bold text-danger border-warning cut-el-input" value="${nowCut}" min="0" max="${availToCut}">
                        <div class="small text-muted" style="font-size: 10px;">Max Ready: ${availToCut}</div>
                    </td>
                    <td class="fw-bold cut-el-rem ${rem > 0 ? 'text-warning' : (availToCut > 0 && nowCut >= availToCut ? 'text-success' : 'text-muted')}">
                        ${availToCut === 0 ? '✓ No Ready Stock' : (rem > 0 ? (rem + ' remaining to cut') : '✓ All Ready Cut')}
                    </td>
                </tr>
            `;
        });
        cutElementsTbody.innerHTML = html;

        cutElementsTbody.querySelectorAll('.cut-el-input').forEach(inp => {
            inp.addEventListener('input', function() {
                const tr = this.closest('tr');
                const avail = parseInt(tr.dataset.avail) || 0;
                let now = parseInt(this.value) || 0;
                if (now < 0) {
                    this.value = 0;
                    now = 0;
                }
                if (now > avail) {
                    alert('اس حصہ کے صرف ' + avail + ' پیس آپریٹر سے تیار موصول ہوئے ہیں۔ آپ ' + avail + ' سے زیادہ کٹنگ درج نہیں کر سکتے۔');
                    this.value = avail;
                    now = avail;
                }
                const rem = Math.max(0, avail - now);
                const remTd = tr.querySelector('.cut-el-rem');
                remTd.textContent = avail === 0 ? '✓ No Ready Stock' : (rem > 0 ? (rem + ' remaining to cut') : '✓ All Ready Cut');
                remTd.className = 'fw-bold cut-el-rem ' + (rem > 0 ? 'text-warning' : (avail > 0 && now >= avail ? 'text-success' : 'text-muted'));

                syncWholeSweatersFromElements();
            });
        });
    }

    function syncWholeSweatersFromElements() {
        if (!cutElementsTbody) return;
        let minWhole = null;
        cutElementsTbody.querySelectorAll('tr[data-elid]').forEach(tr => {
            const mult = parseInt(tr.dataset.mult) || 1;
            const inp = tr.querySelector('.cut-el-input');
            const now = parseInt(inp ? inp.value : 0) || 0;
            const wholeThisEl = Math.floor(now / mult);
            if (minWhole === null || wholeThisEl < minWhole) {
                minWhole = wholeThisEl;
            }
        });
        if (minWhole !== null && cutWholePcs) {
            cutWholePcs.value = minWhole;
            cutWholePcs.dataset.userEdited = '1';
            updateCuttingRate(false);
        }
    }

    const cutMinutesInput = document.getElementById('cut_minutes');
    const cutMinRateInput = document.getElementById('cut_min_rate');
    const cutRateInput = document.getElementById('cut_rate');
    const cutRateBadge = document.getElementById('cutRateBadge');
    const cutMatrixThead = document.getElementById('cutMatrixThead');
    const cutMatrixTbody = document.getElementById('cutMatrixTbody');
    const cutMatrixTfoot = document.getElementById('cutMatrixTfoot');
    const btnFillAllCutting = document.getElementById('btnFillAllCutting');
    const btnClearCutting = document.getElementById('btnClearCutting');

    function updateCuttingRate(fromDirectRate = false) {
        const mins = parseFloat(cutMinutesInput ? cutMinutesInput.value : 0) || 0;
        const minRate = parseFloat(cutMinRateInput ? cutMinRateInput.value : 0) || 2.50;

        if (!fromDirectRate && cutRateInput) {
            if (mins > 0 && minRate > 0) {
                cutRateInput.value = (mins * minRate).toFixed(2);
            }
        }

        const currentRate = parseFloat(cutRateInput ? cutRateInput.value : 0) || 5.00;
        if (cutRateBadge) {
            cutRateBadge.textContent = 'Rate: Rs. ' + currentRate.toFixed(2) + '/pc (' + mins.toFixed(1) + 'm @ ' + minRate.toFixed(2) + '/m)';
        }

        const whole = parseInt(cutWholePcs ? cutWholePcs.value : 0) || 0;
        if (lblCutLabour) {
            lblCutLabour.textContent = 'Rs. ' + (whole * currentRate).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }

    if (cutWorker) {
        cutWorker.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.dataset.minrate && cutMinRateInput) {
                cutMinRateInput.value = parseFloat(opt.dataset.minrate).toFixed(2);
                updateCuttingRate(false);
            }
        });
    }

    if (cutMinutesInput) cutMinutesInput.addEventListener('input', () => updateCuttingRate(false));
    if (cutMinRateInput) cutMinRateInput.addEventListener('input', () => updateCuttingRate(false));
    if (cutRateInput) cutRateInput.addEventListener('input', () => updateCuttingRate(true));

    if (cutWholePcs) {
        cutWholePcs.addEventListener('input', function() {
            const maxWhole = getMaxWholeReadyToCut();
            let whole = parseInt(this.value) || 0;
            if (whole < 0) {
                this.value = 0;
                whole = 0;
            }
            if (whole > maxWhole) {
                alert('آپریٹر سے صرف ' + maxWhole + ' پیس تیار موصول ہوئے ہیں۔ آپ ' + maxWhole + ' سے زیادہ کٹنگ درج نہیں کر سکتے۔');
                this.value = maxWhole;
                whole = maxWhole;
            }
            this.dataset.userEdited = '1';
            updateCuttingRate(false);
            if (currentOpLotData && currentOpLotData.elements) {
                renderCuttingElements(currentOpLotData.elements, whole);
            }
        });
    }

    function loadCuttingMatrixGrid(lotId, opId) {
        if (!lotId || !opId || !cutMatrixTbody) return;
        fetch(`<?= BASE_URL ?>modules/production/ajax.php?action=get_lot_stage_matrix&lot_id=${lotId}&operation_id=${opId}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    renderCuttingMatrixTable(data);
                }
            })
            .catch(err => console.error('Cutting matrix load error:', err));
    }

    function renderCuttingMatrixTable(data) {
        if (!cutMatrixThead || !cutMatrixTbody) return;
        const colors = data.colors || [];
        const sizes = data.sizes || [];
        const grid = data.grid || {};
        const maxWhole = getMaxWholeReadyToCut();

        if (colors.length === 0 || sizes.length === 0) {
            cutMatrixThead.innerHTML = '<tr><th class="p-2 text-muted">No variants defined for this lot.</th></tr>';
            cutMatrixTbody.innerHTML = '';
            return;
        }

        let thHtml = '<tr><th class="text-start py-2 px-3" style="min-width: 150px;">Color (رنگ و کوڈ)</th>';
        sizes.forEach(sz => {
            thHtml += `<th class="py-2 px-2" style="min-width: 140px;">${sz.name} (${sz.code})</th>`;
        });
        thHtml += '<th class="py-2 px-3" style="min-width: 100px;">Color Total</th></tr>';
        cutMatrixThead.innerHTML = thHtml;

        let tbodyHtml = '';
        colors.forEach(c => {
            tbodyHtml += `<tr data-color-id="${c.id}">
                <td class="text-start py-2 px-3 fw-bold">
                    <span class="d-inline-block rounded-circle me-1 border" style="width: 12px; height: 12px; background: ${c.hex_code || '#6c757d'}; vertical-align: middle;"></span>
                    ${c.name} <span class="badge bg-light text-dark border ms-1">[${c.color_code}]</span>
                </td>`;

            sizes.forEach(sz => {
                const cell = (grid[c.id] && grid[c.id][sz.id]) ? grid[c.id][sz.id] : { planned: 0, pending: 0 };
                const planned = parseInt(cell.planned) || 0;
                tbodyHtml += `
                    <td class="p-2 border">
                        <div class="small fw-semibold text-muted mb-1">Plan: <strong>${planned}</strong> pcs</div>
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text px-1 bg-success-subtle text-success fw-bold small" title="Good Cut Pieces">🟢</span>
                            <input type="number" name="matrix[${c.id}][${sz.id}][good]" class="form-control form-control-sm text-center fw-bold cut-cell-good" data-planned="${planned}" value="0" min="0" placeholder="0">
                        </div>
                        <div class="d-flex gap-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text px-1 bg-danger-subtle text-danger small" title="Rejected / Defect Cut Pieces">🔴</span>
                                <input type="number" name="matrix[${c.id}][${sz.id}][rejected]" class="form-control form-control-sm text-center cut-cell-rej" value="0" min="0" placeholder="0">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text px-1 bg-warning-subtle text-warning-emphasis small" title="Rework Pieces">🟡</span>
                                <input type="number" name="matrix[${c.id}][${sz.id}][rework]" class="form-control form-control-sm text-center cut-cell-rew" value="0" min="0" placeholder="0">
                            </div>
                        </div>
                    </td>
                `;
            });

            tbodyHtml += `<td class="py-2 px-3 fw-bold text-primary cut-row-total">0 pcs</td></tr>`;
        });
        cutMatrixTbody.innerHTML = tbodyHtml;

        // Footer Totals
        let tfootHtml = `<tr>
            <td class="text-start py-2 px-3">Total Cutting Output:</td>`;
        sizes.forEach(sz => {
            tfootHtml += `<td class="py-2 text-center cut-col-total" data-size-id="${sz.id}">0</td>`;
        });
        tfootHtml += `<td class="py-2 px-3 text-success fs-6" id="cutMatrixGrandTotal">0 pcs</td></tr>`;
        if (cutMatrixTfoot) cutMatrixTfoot.innerHTML = tfootHtml;

        // Attach real-time input event listeners
        cutMatrixTbody.querySelectorAll('input').forEach(inp => {
            inp.addEventListener('input', recalculateCuttingMatrixTotals);
        });

        // Auto fill planned pieces button (bounded strictly by operator ready pieces)
        if (btnFillAllCutting) {
            btnFillAllCutting.innerHTML = `<i class="bi bi-lightning-charge-fill text-warning me-1"></i> ⚡ 1-Click Fill Ready (${maxWhole} pcs)`;
            btnFillAllCutting.onclick = function() {
                let remainingToDistribute = maxWhole;
                cutMatrixTbody.querySelectorAll('.cut-cell-good').forEach(inp => {
                    const plan = parseInt(inp.dataset.planned) || 0;
                    const fill = Math.min(plan, remainingToDistribute);
                    inp.value = fill;
                    remainingToDistribute -= fill;
                });
                cutMatrixTbody.querySelectorAll('.cut-cell-rej, .cut-cell-rew').forEach(inp => {
                    inp.value = 0;
                });
                recalculateCuttingMatrixTotals();
            };
        }

        // Clear cutting matrix button
        if (btnClearCutting) {
            btnClearCutting.onclick = function() {
                cutMatrixTbody.querySelectorAll('input').forEach(inp => {
                    inp.value = 0;
                });
                recalculateCuttingMatrixTotals();
            };
        }
    }

    function recalculateCuttingMatrixTotals() {
        if (!cutMatrixTbody) return;
        const maxWhole = getMaxWholeReadyToCut();
        let grandGood = 0;
        let grandRej = 0;
        let grandRew = 0;

        cutMatrixTbody.querySelectorAll('tr[data-color-id]').forEach(tr => {
            let rowGood = 0;
            tr.querySelectorAll('.cut-cell-good').forEach(inp => {
                rowGood += parseInt(inp.value) || 0;
            });
            tr.querySelectorAll('.cut-cell-rej').forEach(inp => {
                grandRej += parseInt(inp.value) || 0;
            });
            tr.querySelectorAll('.cut-cell-rew').forEach(inp => {
                grandRew += parseInt(inp.value) || 0;
            });
            grandGood += rowGood;
            const rowTotTd = tr.querySelector('.cut-row-total');
            if (rowTotTd) rowTotTd.textContent = rowGood + ' pcs';
        });

        if (maxWhole > 0 && grandGood > maxWhole) {
            alert('کل کٹنگ تعداد (' + grandGood + ' pcs) آپریٹر کے تیار کردہ پیسز (' + maxWhole + ' pcs) سے زیادہ نہیں ہو سکتی۔');
            if (document.activeElement && document.activeElement.classList.contains('cut-cell-good')) {
                const excess = grandGood - maxWhole;
                document.activeElement.value = Math.max(0, (parseInt(document.activeElement.value) || 0) - excess);
                return recalculateCuttingMatrixTotals();
            }
        }

        // Update column totals
        if (cutMatrixTfoot) {
            cutMatrixTfoot.querySelectorAll('.cut-col-total').forEach(td => {
                const sId = td.dataset.sizeId;
                let colGood = 0;
                cutMatrixTbody.querySelectorAll(`input[name*="[${sId}][good]"]`).forEach(inp => {
                    colGood += parseInt(inp.value) || 0;
                });
                td.textContent = colGood;
            });
            const grandEl = document.getElementById('cutMatrixGrandTotal');
            if (grandEl) grandEl.textContent = grandGood + ' pcs';
        }

        // Auto-update Whole Sweaters Cut input and labour
        if (cutWholePcs) {
            cutWholePcs.value = grandGood;
            cutWholePcs.dataset.userEdited = '1';
            const rate = parseFloat(cutRateInput ? cutRateInput.value : 0) || 5.00;
            if (lblCutLabour) {
                lblCutLabour.textContent = 'Rs. ' + (grandGood * rate).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        }

        // Sync Garment Elements Cutting Progress
        if (currentOpLotData && currentOpLotData.elements) {
            renderCuttingElements(currentOpLotData.elements, grandGood);
        }
    }

    function onOperatorPiecesChanged() {
        const day = parseInt(opDoubleDayPieces ? opDoubleDayPieces.value : 0) || 0;
        const night = parseInt(opDoubleNightPieces ? opDoubleNightPieces.value : 0) || 0;
        if (currentOpLotData && currentOpLotData.elements) {
            renderOperatorElements(currentOpLotData.elements, day, night);
        }
    }
    if (opDoubleDayPieces) opDoubleDayPieces.addEventListener('input', onOperatorPiecesChanged);
    if (opDoubleNightPieces) opDoubleNightPieces.addEventListener('input', onOperatorPiecesChanged);


    btnModeOperator.addEventListener('click', () => switchMode('operator_initial'));
    btnModeMatrix.addEventListener('click', () => switchMode('matrix'));
    btnModeSingle.addEventListener('click', () => switchMode('single'));

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

    function updateRateCalculation(fromDirectRate = false) {
        const mins = parseFloat(minInput.value) || 0;
        const minRate = parseFloat(minRateInput.value) || 2.50;

        if (!fromDirectRate) {
            if (mins > 0 && minRate > 0) {
                rateInput.value = (mins * minRate).toFixed(2);
            }
        }

        const currentRate = parseFloat(rateInput.value) || 0;
        if (lblSamMins) lblSamMins.textContent = mins.toFixed(1);
        if (lblWorkerMinRate) lblWorkerMinRate.textContent = 'Rs. ' + minRate.toFixed(2);
        if (lblCalculatedPieceRate) lblCalculatedPieceRate.textContent = currentRate.toFixed(2);

        calculateSingleLabour();
        recalculateMatrixTotals();
    }

    if (minInput) minInput.addEventListener('input', () => updateRateCalculation(false));
    if (minRateInput) minRateInput.addEventListener('input', () => updateRateCalculation(false));

    // Mode buttons already wired via switchMode()

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
        updateRateCalculation(true);
    });

    // Load operations and variants for selected lot
    function loadLotData(lotId, callback) {
        if (!lotId) {
            currentOpLotData = null;
            if (opPlaceholder) opPlaceholder.classList.remove('d-none');
            if (opActiveCards) opActiveCards.classList.add('d-none');
            if (opLotInfoCard) opLotInfoCard.classList.add('d-none');
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
                    currentOpLotData = data;
                    const lot = data.lot || {};
                    const totalPieces = parseInt(lot.total_pieces, 10) || 0;
                    const isDouble = (lot.shift_type === 'Double Shift');
                    const isMultiSize = (lot.size_mode === 'Multi Size');

                    // Populate Top Lot Info Banner
                    if (opLotTitle) opLotTitle.textContent = `LOT #${lot.lot_number || '---'}`;
                    if (opLotPiecesBadge) opLotPiecesBadge.textContent = `${totalPieces} pcs planned`;
                    if (opLotShiftBadge) {
                        opLotShiftBadge.textContent = isDouble ? '🌙 Double Shift' : '☀️ Single Shift';
                        opLotShiftBadge.className = isDouble ? 'badge bg-primary ms-1' : 'badge bg-warning-subtle text-warning-emphasis ms-1 border';
                    }
                    if (opLotSizeBadge) {
                        opLotSizeBadge.textContent = isMultiSize ? 'Multi-Size Batch' : `Single Size (${lot.single_size_name || 'Standard'})`;
                    }

                    // Hidden fields for operator initial submission
                    if (document.getElementById('op_shift_type')) document.getElementById('op_shift_type').value = lot.shift_type || 'Single Shift';
                    if (document.getElementById('op_size_mode')) document.getElementById('op_size_mode').value = lot.size_mode || 'Single Size';
                    if (document.getElementById('op_single_size_id')) document.getElementById('op_single_size_id').value = lot.single_size_id || '';

                    // Unhide active cards and info card
                    if (opPlaceholder) opPlaceholder.classList.add('d-none');
                    if (opActiveCards) opActiveCards.classList.remove('d-none');
                    if (opLotInfoCard) opLotInfoCard.classList.remove('d-none');

                    // Populate operations
                    opSelect.innerHTML = '<option value="">-- Choose Operation --</option>';
                    data.operations.forEach(op => {
                        const opt = document.createElement('option');
                        opt.value = op.operation_id;
                        opt.dataset.rate = op.default_rate;
                        opt.dataset.minutes = op.estimated_minutes || 0;
                        opt.dataset.mins = op.estimated_minutes || 0;
                        opt.dataset.goodDone = op.good_done || 0;
                        const goodDone = parseInt(op.good_done) || 0;
                        const lotTotal = parseInt(data.lot.total_pieces) || 0;
                        const isCompleted = (goodDone >= lotTotal && lotTotal > 0);
                        const isSub = op.parent_id ? true : false;
                        const prefix = isSub ? '  ↳ ' : '';
                        const minInfo = (parseFloat(op.estimated_minutes) > 0 ? ' (' + parseFloat(op.estimated_minutes) + ' min)' : '');
                        opt.textContent = prefix + op.sequence + '. ' + op.op_name + minInfo + ' [' + op.dept_name + '] - Rs. ' + parseFloat(op.default_rate).toFixed(2) + 
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

                    // Resolve which operation to select
                    let targetOpId = preselectedOpId;

                    if (!targetOpId) {
                        // If no specific operation was requested in URL:
                        // Check if Operator is completed or if another operation is actively pending in sequence
                        const opObj = data.operations.find(o => {
                            const n = (o.op_name || '').toLowerCase();
                            return n.includes('operator') || n.includes('knitt');
                        });
                        const opDone = opObj ? (parseInt(opObj.good_done, 10) || 0) : 0;
                        const totalLot = parseInt(data.lot.total_pieces, 10) || 0;

                        if (opObj && opDone < totalLot) {
                            targetOpId = opObj.operation_id;
                        } else {
                            // Find first operation in workflow where good_done < totalLot
                            const nextPending = data.operations.find(o => (parseInt(o.good_done, 10) || 0) < totalLot);
                            if (nextPending) {
                                targetOpId = nextPending.operation_id;
                            } else if (data.operations.length > 0) {
                                targetOpId = data.operations[0].operation_id;
                            }
                        }
                    }

                    if (targetOpId && data.operations.some(op => op.operation_id == targetOpId)) {
                        opSelect.value = targetOpId;
                    }

                    // Compute good done and remaining pieces for this lot's initial operation
                    const opObj = (targetOpId ? data.operations.find(o => o.operation_id == targetOpId) : null) || data.operations.find(o => {
                        const n = (o.op_name || '').toLowerCase();
                        return n.includes('operator') || n.includes('knitt');
                    }) || (data.operations.length > 0 ? data.operations[0] : null);

                    const opGoodDone = opObj ? (parseInt(opObj.good_done, 10) || 0) : 0;
                    const remainingLotPieces = Math.max(0, totalPieces - opGoodDone);

                    if (opLotPiecesBadge) {
                        opLotPiecesBadge.textContent = opGoodDone > 0 
                            ? `${remainingLotPieces} pcs remaining (${opGoodDone} done / ${totalPieces} planned)` 
                            : `${totalPieces} pcs planned`;
                    }

                    if (opSinglePieces && (!opSinglePieces.dataset.userEdited || !opSinglePieces.value || parseInt(opSinglePieces.value, 10) > remainingLotPieces || parseInt(opSinglePieces.value, 10) === totalPieces)) {
                        opSinglePieces.value = remainingLotPieces;
                    }

                    if (isDouble && opDoubleDayPieces && opDoubleNightPieces && (!opDoubleDayPieces.dataset.userEdited && !opDoubleNightPieces.dataset.userEdited)) {
                        const dayHalf = Math.ceil(remainingLotPieces / 2);
                        opDoubleDayPieces.value = dayHalf;
                        opDoubleNightPieces.value = Math.max(0, remainingLotPieces - dayHalf);
                    }

                    if (data.elements) {
                        const dayInit = isDouble
                            ? (parseInt(opDoubleDayPieces && opDoubleDayPieces.dataset.userEdited ? opDoubleDayPieces.value : 0) || Math.ceil(remainingLotPieces / 2))
                            : (parseInt(opSinglePieces ? opSinglePieces.value : 0) || remainingLotPieces);
                        const nightInit = isDouble
                            ? (parseInt(opDoubleNightPieces && opDoubleNightPieces.dataset.userEdited ? opDoubleNightPieces.value : 0) || Math.max(0, remainingLotPieces - dayInit))
                            : 0;
                        renderOperatorElements(data.elements, dayInit, nightInit);

                        const maxWhole = getMaxWholeReadyToCut();
                        if (cutWholePcs) {
                            cutWholePcs.max = maxWhole;
                            if (!cutWholePcs.dataset.userEdited || parseInt(cutWholePcs.value, 10) > maxWhole || parseInt(cutWholePcs.value, 10) <= 0) {
                                cutWholePcs.value = maxWhole > 0 ? maxWhole : 0;
                            }
                        }
                        const lblHint = document.getElementById('lblCutWholeMaxCount');
                        if (lblHint) lblHint.textContent = maxWhole;
                        const plannedNotice = document.getElementById('cutLotPlannedNotice');
                        if (plannedNotice) {
                            plannedNotice.innerHTML = `Ready: <strong class="text-primary">${maxWhole}</strong> / Planned: ${totalPieces} pcs`;
                        }

                        renderCuttingElements(data.elements, parseInt(cutWholePcs ? cutWholePcs.value : 0) || 0);
                    }

                    if (targetOpId && data.operations.some(op => op.operation_id == targetOpId)) {
                        const selectedOpt = opSelect.options[opSelect.selectedIndex];
                        if (selectedOpt) {
                            if (minInput && (selectedOpt.dataset.minutes || selectedOpt.dataset.mins)) {
                                minInput.value = parseFloat(selectedOpt.dataset.minutes || selectedOpt.dataset.mins).toFixed(1);
                            }
                            if (selectedOpt.dataset.rate) {
                                rateInput.value = selectedOpt.dataset.rate;
                            }
                            updateRateCalculation(false);
                        }
                        loadWorkersForOperation(targetOpId);
                        loadMatrixGrid(lotId, targetOpId);
                        checkBalance();

                        if (preselectedMode) {
                            switchMode(preselectedMode);
                        } else {
                            const selText = selectedOpt ? selectedOpt.textContent.toLowerCase() : '';
                            if (selText.includes('cut')) {
                                switchMode('cutting');
                            } else if (selText.includes('operator') || selText.includes('knitt')) {
                                switchMode('operator_initial');
                            } else {
                                switchMode('matrix');
                            }
                        }
                    } else if (preselectedMode) {
                        switchMode(preselectedMode);
                    } else if (isOpMode) {
                        switchMode('operator_initial');
                    }

                    // Sync operator quantities and rates
                    updateOperatorRatesAndQuantities();

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
                        opt.dataset.minRate = w.rate_per_minute || 2.50;
                        opt.dataset.rateBasis = w.rate_basis || 'whole_sweater';
                        const minRateTxt = parseFloat(w.rate_per_minute || 2.50).toFixed(2);
                        const basisBadge = w.rate_basis === 'element_wise' ? ' [اعضاء وار]' : ' [سویٹر]';
                        opt.textContent = w.name + ' (' + w.code + ')' + basisBadge + ' — Rs. ' + minRateTxt + '/min';
                        workerSelect.appendChild(opt);
                    });

                    // Auto-select if exactly 1 matching artisan
                    if (count === 1) {
                        workerSelect.value = data.workers[0].id;
                        workerSelect.dispatchEvent(new Event('change'));
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
        if (opt && opt.value) {
            const basis = opt.dataset.rateBasis || 'whole_sweater';
            if (workerBadge) {
                if (basis === 'element_wise') {
                    workerBadge.innerHTML = '<i class="bi bi-puzzle me-1"></i>اعضاء وار (Elements)';
                    workerBadge.className = 'badge bg-primary-subtle text-primary border small';
                } else {
                    workerBadge.innerHTML = '<i class="bi bi-layers me-1"></i>مکمل سویٹر (Whole)';
                    workerBadge.className = 'badge bg-success-subtle text-success border small';
                }
                workerBadge.classList.remove('d-none');
            }
            if (minRateInput && opt.dataset.minRate) {
                minRateInput.value = parseFloat(opt.dataset.minRate).toFixed(2);
            }
            if (opt.dataset.rate && parseFloat(opt.dataset.rate) > 0) {
                rateInput.value = parseFloat(opt.dataset.rate).toFixed(2);
                updateRateCalculation(true);
            } else {
                updateRateCalculation(false);
            }
        }
    });

    // When operation changes, update SAM minutes, load workers, and load matrix grid!
    opSelect.addEventListener('change', function() {
        const selectedText = this.options[this.selectedIndex] ? this.options[this.selectedIndex].textContent.toLowerCase() : '';
        if (selectedText.includes('cut')) {
            switchMode('cutting');
        } else if (selectedText.includes('operator') || selectedText.includes('knitt')) {
            switchMode('operator_initial');
        } else if (this.value) {
            switchMode('matrix');
        }
        const selectedOpt = this.options[this.selectedIndex];
        if (selectedOpt) {
            if (minInput && (selectedOpt.dataset.minutes || selectedOpt.dataset.mins)) {
                minInput.value = parseFloat(selectedOpt.dataset.minutes || selectedOpt.dataset.mins).toFixed(1);
            }
            if (selectedOpt.dataset.rate) {
                rateInput.value = selectedOpt.dataset.rate;
            }
            updateRateCalculation(false);
        }
        if (modeInput.value === 'operator_initial') {
            updateOperatorRatesAndQuantities();
        } else {
            loadWorkersForOperation(this.value);
            loadMatrixGrid(lotSelect.value, this.value);
            checkBalance();
        }
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

        const opSelect = document.getElementById('pe_operation_id');
        const selectedOpText = opSelect ? (opSelect.options[opSelect.selectedIndex]?.text || '') : '';
        const isPacking = selectedOpText.toLowerCase().includes('pack');
        const btnPackSets = document.getElementById('btnPackSets');
        if (btnPackSets) {
            if (isPacking && !isStageFullyComplete) {
                btnPackSets.classList.remove('d-none');
            } else {
                btnPackSets.classList.add('d-none');
            }
        }

        if (isStageFullyComplete) {
            stageNoticeEl.className = 'alert alert-success d-flex flex-wrap align-items-center justify-content-between p-3 mb-3 border rounded-3 shadow-sm';
            stageNoticeEl.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill text-success fs-3"></i>
                    <div>
                        <div class="fw-bold text-dark">Stage 100% Completed (مرحلہ مکمل ہو چکا ہے)</div>
                        <div class="small text-muted">All ${totals.allowable} available pieces for this stage have been produced. Further production entries are restricted.</div>
                    </div>
                </div>
                <span class="badge bg-success fs-6 px-3 py-2">${totals.good_done} / ${totals.allowable} Pcs</span>
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
        } else if (data.prev_operation_name && totals.allowable === 0) {
            stageNoticeEl.className = 'alert alert-warning d-flex flex-wrap align-items-center justify-content-between p-3 mb-3 border border-warning rounded-3 shadow-sm';
            stageNoticeEl.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-hourglass-split text-warning fs-3"></i>
                    <div>
                        <div class="fw-bold text-dark">Waiting for Preceding Stage (${data.prev_operation_name})</div>
                        <div class="small text-muted">پچھلے مرحلے (<strong>${data.prev_operation_name}</strong>) سے ابھی کوئی پیس تیار ہو کر موصول نہیں ہوا۔ پہلے پچھلا مرحلہ مکمل کریں۔</div>
                    </div>
                </div>
                <span class="badge bg-secondary fs-6 px-3 py-2">0 Available</span>
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
        } else if (data.prev_operation_name && totals.allowable < totals.planned) {
            stageNoticeEl.className = 'alert alert-info d-flex flex-wrap align-items-center justify-content-between p-3 mb-3 border border-info rounded-3 shadow-sm';
            stageNoticeEl.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-arrow-right-circle text-info fs-3"></i>
                    <div>
                        <div class="fw-bold text-dark">Preceding Stage Output Enforced: ${data.prev_operation_name} (${totals.allowable} Pcs Available)</div>
                        <div class="small text-muted">
                            سابقہ مرحلہ (<strong>${data.prev_operation_name}</strong>) سے ${totals.allowable} پیس موصول ہوئے۔ اس مرحلے میں زیادہ سے زیادہ <strong>${totals.allowable}</strong> پیسز کی انٹری کی جا سکتی ہے (${totals.pending} پیسز باقی ہیں)۔
                        </div>
                    </div>
                </div>
                <span class="badge bg-primary fs-6 px-3 py-2">${totals.good_done} / ${totals.allowable} Done</span>
            `;
            stageNoticeEl.classList.remove('d-none');
            const fillBtn = document.getElementById('btnFillAllPending');
            const subBtn = document.getElementById('btnSubmitBatch');
            if (fillBtn) fillBtn.disabled = (totals.pending <= 0);
            if (subBtn) {
                subBtn.disabled = (totals.pending <= 0);
                if (totals.pending > 0) {
                    subBtn.classList.add('btn-primary');
                    subBtn.classList.remove('btn-secondary');
                }
            }
        } else if (data.has_prev_color_stage && data.total_prev_rejected > 0) {
            stageNoticeEl.className = 'alert alert-warning d-flex flex-wrap align-items-center justify-content-between p-3 mb-3 border border-warning rounded-3 shadow-sm';
            stageNoticeEl.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-shield-exclamation text-warning fs-3"></i>
                    <div>
                        <div class="fw-bold text-dark">Stage Reconciliation: Preceding Defect Exclusion Enforced</div>
                        <div class="small text-muted">
                            Preceding stage (<strong>${data.prev_operation_name}</strong>) had ${data.total_prev_rejected} rejected piece(s). 
                            In this stage, quantities are strictly restricted to good pieces passed per color. Defective pieces cannot be shifted to other colors.
                        </div>
                    </div>
                </div>
                <span class="badge bg-warning text-dark fs-6 px-3 py-2">${totals.allowable} / ${totals.planned} Passed</span>
            `;
            stageNoticeEl.classList.remove('d-none');
            const fillBtn = document.getElementById('btnFillAllPending');
            if (fillBtn) fillBtn.disabled = false;
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
            let colorAllowableTotal = 0;
            tbHtml += `<tr>
                <td class="text-start ps-3 fw-semibold text-dark">
                    <span class="d-inline-block rounded-circle me-1 border" style="width: 12px; height: 12px; background-color: ${c.hex_code || '#6c757d'}; vertical-align: middle;"></span>
                    ${c.name}
                </td>`;

            sizes.forEach(s => {
                const cell = (grid[c.id] && grid[c.id][s.id]) ? grid[c.id][s.id] : null;
                if (cell && cell.planned > 0) {
                    const effectiveAllowable = (cell.allowable !== undefined ? cell.allowable : cell.planned);
                    colorAllowableTotal += effectiveAllowable;
                    if (cell.pending > 0) {
                        tbHtml += `<td class="p-2 position-relative cell-variant" data-color="${c.id}" data-size="${s.id}" data-pending="${cell.pending}" data-planned="${effectiveAllowable}">
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
                                           data-planned="${effectiveAllowable}" 
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
                                           data-planned="${effectiveAllowable}" 
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
                                           data-planned="${effectiveAllowable}" 
                                           value="0" placeholder="0">
                                </div>
                            </div>

                            <div class="cell-status small text-muted mt-1" style="font-size: 0.72rem; line-height: 1.1;">
                                ${cell.has_prev_stage && cell.allowable < cell.planned ? 
                                    `<span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="${cell.prev_rejected} rejected in ${cell.prev_stage_name}">
                                        <i class="bi bi-shield-exclamation"></i> Max: <strong>${cell.pending}</strong>/${cell.allowable} (${cell.prev_rejected} rej in ${cell.prev_stage_name})
                                    </span>` : 
                                    `<span class="badge bg-light text-secondary border">Pending: <strong>${cell.pending}</strong>/${effectiveAllowable}</span>`
                                }
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
                                   data-planned="${effectiveAllowable}" 
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

            tbHtml += `<td class="fw-bold text-primary bg-light" id="row_tot_${c.id}" data-planned="${colorAllowableTotal}">
                <span class="row-sum">0</span> <small class="text-muted fw-normal">/ ${colorAllowableTotal}</small>
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

        const opMins = minInput ? (parseFloat(minInput.value) || 0) : 0;
        const totalBatchMins = grandGood * opMins;
        const batchHours = (totalBatchMins / 60).toFixed(1);
        if (lblTotalBatchTime) {
            lblTotalBatchTime.innerHTML = '<i class="bi bi-clock-history me-1"></i>Batch Work: ' + totalBatchMins.toFixed(1) + ' mins (' + batchHours + ' hrs)';
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

    // Pack Assorted Color Sets Button (Unified Workflow Packing Mode)
    const btnPackSets = document.getElementById('btnPackSets');
    if (btnPackSets) {
        btnPackSets.addEventListener('click', function() {
            // Group cells by size ID to find minimum pending across colors (balanced assorted color sets)
            const sizeCells = {};
            document.querySelectorAll('.cell-variant').forEach(cell => {
                const sId = cell.dataset.size;
                if (!sizeCells[sId]) sizeCells[sId] = [];
                sizeCells[sId].push(cell);
            });

            Object.keys(sizeCells).forEach(sId => {
                const cells = sizeCells[sId];
                let minPending = 999999;
                cells.forEach(cell => {
                    const pend = parseInt(cell.dataset.pending) || 0;
                    if (pend < minPending) minPending = pend;
                });
                if (minPending === 999999 || minPending < 0) minPending = 0;

                cells.forEach(cell => {
                    const goodInp = cell.querySelector('.cell-good');
                    if (goodInp && !goodInp.readOnly) {
                        goodInp.value = minPending;
                    }
                });
            });
            recalculateMatrixTotals();
        });
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

    // =========================================================================
    // OPERATOR INITIAL PRODUCTION ENTRY JAVASCRIPT LOGIC
    // =========================================================================
    if (opOperationSelect) {
        opOperationSelect.addEventListener('change', updateOperatorRatesAndQuantities);
    }

    function updateOperatorRatesAndQuantities() {
        if (!currentOpLotData || !currentOpLotData.lot) return;
        const lot = currentOpLotData.lot;
        const totalPieces = parseInt(lot.total_pieces, 10) || 0;
        const isDouble = (lot.shift_type === 'Double Shift');
        const isMultiSize = (lot.size_mode === 'Multi Size');

        if (opPlaceholder) opPlaceholder.classList.add('d-none');
        if (opActiveCards) opActiveCards.classList.remove('d-none');
        if (opLotInfoCard) opLotInfoCard.classList.remove('d-none');

        const selectedOpOpt = opOperationSelect ? opOperationSelect.selectedOptions[0] : null;
        const opMins = selectedOpOpt ? (parseFloat(selectedOpOpt.dataset.minutes || selectedOpOpt.dataset.mins) || 0) : 0;
        const opDefRate = selectedOpOpt ? (parseFloat(selectedOpOpt.dataset.rate) || 5.0) : 5.0;
        const opGoodDone = selectedOpOpt ? (parseInt(selectedOpOpt.dataset.goodDone, 10) || 0) : 0;
        const remainingLotPieces = Math.max(0, totalPieces - opGoodDone);

        if (opStageBadge && selectedOpOpt) {
            opStageBadge.textContent = selectedOpOpt.textContent;
        }

        if (lblOpLotPlanned) lblOpLotPlanned.textContent = totalPieces;
        if (opLotPiecesBadge) {
            opLotPiecesBadge.textContent = opGoodDone > 0 
                ? `${remainingLotPieces} pcs remaining (${opGoodDone} done / ${totalPieces} planned)` 
                : `${totalPieces} pcs planned`;
        }

        // Calculate total SAM from product elements if available, otherwise from operation estimated minutes
        let totalElemSam = 0;
        if (currentOpLotData && currentOpLotData.elements) {
            currentOpLotData.elements.forEach(el => {
                const sam = parseFloat(el.default_sam_minutes) || 0;
                const mult = parseInt(el.quantity || el.multiplier || 1);
                totalElemSam += (sam * mult);
            });
        }
        const finalMins = totalElemSam > 0 ? totalElemSam : opMins;

        if (!isDouble) {
            // SINGLE SHIFT
            if (singleShiftCard) {
                singleShiftCard.classList.remove('d-none');
                singleShiftCard.querySelectorAll('input, select').forEach(el => el.disabled = false);
            }
            if (doubleShiftCard) {
                doubleShiftCard.classList.add('d-none');
                doubleShiftCard.querySelectorAll('input, select').forEach(el => el.disabled = true);
            }

            if (lot.operator_id && opSingleWorker && !opSingleWorker.value) {
                opSingleWorker.value = lot.operator_id;
            }
            const workerOpt = opSingleWorker ? opSingleWorker.selectedOptions[0] : null;
            const workerMinRate = workerOpt ? (parseFloat(workerOpt.dataset.minrate) || 2.50) : 2.50;
            const pieceRate = finalMins > 0 ? (finalMins * workerMinRate) : opDefRate;

            if (opSingleMinutes && !opSingleMinutes.dataset.userEdited) opSingleMinutes.value = finalMins > 0 ? finalMins.toFixed(1) : (opMins > 0 ? opMins.toFixed(1) : '0.0');
            if (opSingleMinRate && !opSingleMinRate.dataset.userEdited) opSingleMinRate.value = workerMinRate.toFixed(2);
            if (opSingleRate && !opSingleRate.dataset.userEdited) opSingleRate.value = pieceRate.toFixed(2);

            if (opSingleRateBadge) {
                const curR = parseFloat(opSingleRate ? opSingleRate.value : pieceRate) || 0;
                const curM = parseFloat(opSingleMinutes ? opSingleMinutes.value : finalMins) || 0;
                const curMR = parseFloat(opSingleMinRate ? opSingleMinRate.value : workerMinRate) || 0;
                opSingleRateBadge.textContent = curM > 0 ? `Rate: Rs. ${curR.toFixed(2)}/pc (${curM}m × Rs. ${curMR.toFixed(2)}/m)` : `Rate: Rs. ${curR.toFixed(2)}/pc (Direct Rate)`;
            }

            if (document.getElementById('lblSingleMinRateBadge')) {
                document.getElementById('lblSingleMinRateBadge').textContent = `Rs. ${workerMinRate.toFixed(2)}/m`;
            }

            if (opSinglePieces && (!opSinglePieces.dataset.userEdited || !opSinglePieces.value || parseInt(opSinglePieces.value, 10) > remainingLotPieces || parseInt(opSinglePieces.value, 10) === totalPieces)) {
                opSinglePieces.value = remainingLotPieces;
            }
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        } else {
            // DOUBLE SHIFT
            if (singleShiftCard) {
                singleShiftCard.classList.add('d-none');
                singleShiftCard.querySelectorAll('input, select').forEach(el => el.disabled = true);
            }
            if (doubleShiftCard) {
                doubleShiftCard.classList.remove('d-none');
                doubleShiftCard.querySelectorAll('input, select').forEach(el => el.disabled = false);
            }

            if (lot.operator_id && opDoubleDayWorker && !opDoubleDayWorker.value) opDoubleDayWorker.value = lot.operator_id;
            if (lot.night_operator_id && opDoubleNightWorker && !opDoubleNightWorker.value) opDoubleNightWorker.value = lot.night_operator_id;

            const dayWorkerOpt = opDoubleDayWorker ? opDoubleDayWorker.selectedOptions[0] : null;
            const dayMinRate = dayWorkerOpt ? (parseFloat(dayWorkerOpt.dataset.minrate) || 2.50) : 2.50;
            const dayPieceRate = finalMins > 0 ? (finalMins * dayMinRate) : opDefRate;

            const nightWorkerOpt = opDoubleNightWorker ? opDoubleNightWorker.selectedOptions[0] : null;
            const nightMinRate = nightWorkerOpt ? (parseFloat(nightWorkerOpt.dataset.minrate) || 2.50) : 2.50;
            const nightPieceRate = finalMins > 0 ? (finalMins * nightMinRate) : opDefRate;

            if (document.getElementById('lblDoubleDayMinRateBadge')) {
                document.getElementById('lblDoubleDayMinRateBadge').textContent = `Rs. ${dayMinRate.toFixed(2)}/m`;
            }
            if (document.getElementById('lblDoubleNightMinRateBadge')) {
                document.getElementById('lblDoubleNightMinRateBadge').textContent = `Rs. ${nightMinRate.toFixed(2)}/m`;
            }

            if (opDoubleDayMinutes && !opDoubleDayMinutes.dataset.userEdited) opDoubleDayMinutes.value = finalMins > 0 ? finalMins.toFixed(1) : (opMins > 0 ? opMins.toFixed(1) : '0.0');
            if (opDoubleDayMinRate && !opDoubleDayMinRate.dataset.userEdited) opDoubleDayMinRate.value = dayMinRate.toFixed(2);
            if (opDoubleDayRate && !opDoubleDayRate.dataset.userEdited) opDoubleDayRate.value = dayPieceRate.toFixed(2);

            if (opDoubleNightMinutes && !opDoubleNightMinutes.dataset.userEdited) opDoubleNightMinutes.value = finalMins > 0 ? finalMins.toFixed(1) : (opMins > 0 ? opMins.toFixed(1) : '0.0');
            if (opDoubleNightMinRate && !opDoubleNightMinRate.dataset.userEdited) opDoubleNightMinRate.value = nightMinRate.toFixed(2);
            if (opDoubleNightRate && !opDoubleNightRate.dataset.userEdited) opDoubleNightRate.value = nightPieceRate.toFixed(2);

            if (opDoubleDayRateBadge) {
                const curR = parseFloat(opDoubleDayRate ? opDoubleDayRate.value : dayPieceRate) || 0;
                const curM = parseFloat(opDoubleDayMinutes ? opDoubleDayMinutes.value : finalMins) || 0;
                const curMR = parseFloat(opDoubleDayMinRate ? opDoubleDayMinRate.value : dayMinRate) || 0;
                opDoubleDayRateBadge.textContent = curM > 0 ? `Rs. ${curR.toFixed(2)}/pc (${curM}m @ ${curMR.toFixed(2)}/m)` : `Rs. ${curR.toFixed(2)}/pc`;
            }
            if (opDoubleNightRateBadge) {
                const curR = parseFloat(opDoubleNightRate ? opDoubleNightRate.value : nightPieceRate) || 0;
                const curM = parseFloat(opDoubleNightMinutes ? opDoubleNightMinutes.value : finalMins) || 0;
                const curMR = parseFloat(opDoubleNightMinRate ? opDoubleNightMinRate.value : nightMinRate) || 0;
                opDoubleNightRateBadge.textContent = curM > 0 ? `Rs. ${curR.toFixed(2)}/pc (${curM}m @ ${curMR.toFixed(2)}/m)` : `Rs. ${curR.toFixed(2)}/pc`;
            }

            // Auto-balance default (e.g. 50/50 of remaining pieces)
            if (opDoubleDayPieces && opDoubleNightPieces && (!opDoubleDayPieces.dataset.userEdited && !opDoubleNightPieces.dataset.userEdited)) {
                const dayHalf = Math.ceil(remainingLotPieces / 2);
                opDoubleDayPieces.value = dayHalf;
                opDoubleNightPieces.value = Math.max(0, remainingLotPieces - dayHalf);
            }
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        }

        // Multi-Size handling
        if (isMultiSize && currentOpLotData.sizes && currentOpLotData.sizes.length > 0) {
            opMultiSizeSection.classList.remove('d-none');
            buildOpMultiSizeTable(isDouble);
        } else {
            opMultiSizeSection.classList.add('d-none');
        }
    }

    function syncWorkerCustomRates(workerId) {
        if (!workerId || !currentOpLotData || !currentOpLotData.elements) return;
        fetch(`<?= BASE_URL ?>modules/production/ajax.php?action=get_worker_element_rates&worker_id=${workerId}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const worker = data.worker || {};
                    const ratesMap = {};
                    (data.rates || []).forEach(r => {
                        ratesMap[r.element_id] = r;
                    });
                    currentOpLotData.elements.forEach(el => {
                        if (ratesMap[el.id]) {
                            el.worker_custom_sam = ratesMap[el.id].custom_sam_minutes;
                            el.worker_custom_rate = ratesMap[el.id].custom_piece_rate;
                        } else {
                            el.worker_custom_sam = null;
                            el.worker_custom_rate = null;
                        }
                    });
                    const dayInit = parseInt(opSinglePieces ? opSinglePieces.value : 0) || 0;
                    renderOperatorElements(currentOpLotData.elements, dayInit, 0);
                }
            })
            .catch(err => console.error(err));
    }

    if (opSingleWorker) {
        opSingleWorker.addEventListener('change', function() {
            updateOperatorRatesAndQuantities();
            syncWorkerCustomRates(this.value);
        });
    }
    if (opDoubleDayWorker) {
        opDoubleDayWorker.addEventListener('change', updateOperatorRatesAndQuantities);
    }
    if (opDoubleNightWorker) {
        opDoubleNightWorker.addEventListener('change', updateOperatorRatesAndQuantities);
    }

    // Auto-balance Day and Night pieces in real-time & sync element breakdown
    if (opDoubleDayPieces) {
        opDoubleDayPieces.addEventListener('input', function() {
            if (!currentOpLotData) return;
            const total = parseInt(currentOpLotData.lot.total_pieces, 10) || 0;
            const dayVal = parseInt(this.value, 10) || 0;
            const nightVal = Math.max(0, total - dayVal);
            if (opDoubleNightPieces) opDoubleNightPieces.value = nightVal;
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        });
    }

    if (opDoubleNightPieces) {
        opDoubleNightPieces.addEventListener('input', function() {
            if (!currentOpLotData) return;
            const total = parseInt(currentOpLotData.lot.total_pieces, 10) || 0;
            const nightVal = parseInt(this.value, 10) || 0;
            const dayVal = Math.max(0, total - nightVal);
            if (opDoubleDayPieces) opDoubleDayPieces.value = dayVal;
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        });
    }

    if (opSinglePieces) {
        opSinglePieces.addEventListener('input', function() {
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        });
    }

    // Helper buttons
    if (btnSplit5050) {
        btnSplit5050.addEventListener('click', function() {
            if (!currentOpLotData) return;
            const total = parseInt(currentOpLotData.lot.total_pieces, 10) || 0;
            const half = Math.ceil(total / 2);
            opDoubleDayPieces.value = half;
            opDoubleNightPieces.value = total - half;
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        });
    }

    if (btnSet100Day) {
        btnSet100Day.addEventListener('click', function() {
            if (!currentOpLotData) return;
            const total = parseInt(currentOpLotData.lot.total_pieces, 10) || 0;
            opDoubleDayPieces.value = total;
            opDoubleNightPieces.value = 0;
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        });
    }

    if (btnSet100Night) {
        btnSet100Night.addEventListener('click', function() {
            if (!currentOpLotData) return;
            const total = parseInt(currentOpLotData.lot.total_pieces, 10) || 0;
            opDoubleDayPieces.value = 0;
            opDoubleNightPieces.value = total;
            recalcOperatorTotals();
            syncElementsFromOperatorPieces();
        });
    }

    function recalcOperatorTotals() {
        if (!currentOpLotData) return;
        const lot = currentOpLotData.lot;
        const totalPlanned = parseInt(lot.total_pieces, 10) || 0;
        const isDouble = (lot.shift_type === 'Double Shift');

        let totalEntered = 0;
        if (!isDouble) {
            const dayPcs = parseInt(opSinglePieces.value, 10) || 0;
            const rate = parseFloat(opSingleRate ? opSingleRate.value : 0) || 0;
            if (lblSingleDayLabour) lblSingleDayLabour.textContent = `Rs. ${(dayPcs * rate).toFixed(2)}`;
            totalEntered = dayPcs;
        } else {
            const dayPcs = parseInt(opDoubleDayPieces.value, 10) || 0;
            const dayRate = parseFloat(opDoubleDayRate ? opDoubleDayRate.value : 0) || 0;
            if (lblDoubleDayLabour) lblDoubleDayLabour.textContent = `Rs. ${(dayPcs * dayRate).toFixed(2)}`;

            const nightPcs = parseInt(opDoubleNightPieces.value, 10) || 0;
            const nightRate = parseFloat(opDoubleNightRate ? opDoubleNightRate.value : 0) || 0;
            if (lblDoubleNightLabour) lblDoubleNightLabour.textContent = `Rs. ${(nightPcs * nightRate).toFixed(2)}`;

            totalEntered = dayPcs + nightPcs;
        }

        const selectedOpOpt = opOperationSelect ? opOperationSelect.selectedOptions[0] : null;
        const opGoodDone = selectedOpOpt ? (parseInt(selectedOpOpt.dataset.goodDone, 10) || 0) : 0;
        const remainingLotPieces = Math.max(0, totalPlanned - opGoodDone);

        if (lblOpSumTotal) lblOpSumTotal.textContent = totalEntered;
        if (opMatchStatusBanner) {
            if (opGoodDone > 0) {
                if (totalEntered === remainingLotPieces) {
                    opMatchStatusBanner.className = 'alert alert-success py-2 px-3 small fw-semibold text-center mb-3';
                    opMatchStatusBanner.innerHTML = `✓ Total Today: <strong>${totalEntered} pcs</strong> (${opGoodDone} previously completed, completing full ${totalPlanned} planned pieces)`;
                } else {
                    const remainingAfter = Math.max(0, remainingLotPieces - totalEntered);
                    opMatchStatusBanner.className = 'alert alert-warning py-2 px-3 small fw-semibold text-center mb-3';
                    opMatchStatusBanner.innerHTML = `⚠️ Total Today: <strong>${totalEntered} pcs</strong> (${opGoodDone} done previously, ${remainingAfter} remaining out of ${totalPlanned} planned)`;
                }
            } else {
                if (totalEntered === totalPlanned && totalPlanned > 0) {
                    opMatchStatusBanner.className = 'alert alert-success py-2 px-3 small fw-semibold text-center mb-3';
                    opMatchStatusBanner.innerHTML = `✓ Total Pieces: ${totalEntered} / ${totalPlanned} pcs (100% of Lot Planned Pieces)`;
                } else {
                    opMatchStatusBanner.className = 'alert alert-warning py-2 px-3 small fw-semibold text-center mb-3';
                    opMatchStatusBanner.innerHTML = `⚠️ Total Pieces: ${totalEntered} / ${totalPlanned} pcs (${totalPlanned - totalEntered} pending / remaining)`;
                }
            }
        }
    }

    // Dynamic real-time rate & SAM listeners for Operator cards
    function wireRateSchemeListeners(minsEl, minRateEl, rateEl, badgeEl, recalcFn) {
        if (minsEl) {
            minsEl.addEventListener('input', function() {
                this.dataset.userEdited = '1';
                const m = parseFloat(this.value) || 0;
                const mr = parseFloat(minRateEl ? minRateEl.value : 0) || 0;
                if (rateEl) {
                    rateEl.value = (m * mr).toFixed(2);
                    rateEl.dataset.userEdited = '1';
                }
                if (badgeEl) {
                    badgeEl.textContent = m > 0 ? `Rate: Rs. ${(m * mr).toFixed(2)}/pc (${m}m × Rs. ${mr.toFixed(2)}/m)` : `Rate: Rs. ${(m * mr).toFixed(2)}/pc`;
                }
                recalcFn();
            });
        }
        if (minRateEl) {
            minRateEl.addEventListener('input', function() {
                this.dataset.userEdited = '1';
                const mr = parseFloat(this.value) || 0;
                const m = parseFloat(minsEl ? minsEl.value : 0) || 0;
                if (rateEl) {
                    rateEl.value = (m * mr).toFixed(2);
                    rateEl.dataset.userEdited = '1';
                }
                if (badgeEl) {
                    badgeEl.textContent = m > 0 ? `Rate: Rs. ${(m * mr).toFixed(2)}/pc (${m}m × Rs. ${mr.toFixed(2)}/m)` : `Rate: Rs. ${(m * mr).toFixed(2)}/pc`;
                }
                recalcFn();
            });
        }
        if (rateEl) {
            rateEl.addEventListener('input', function() {
                this.dataset.userEdited = '1';
                const r = parseFloat(this.value) || 0;
                if (badgeEl) {
                    badgeEl.textContent = `Rate: Rs. ${r.toFixed(2)}/pc (Custom Rate)`;
                }
                recalcFn();
            });
        }
    }

    wireRateSchemeListeners(opSingleMinutes, opSingleMinRate, opSingleRate, opSingleRateBadge, recalcOperatorTotals);
    wireRateSchemeListeners(opDoubleDayMinutes, opDoubleDayMinRate, opDoubleDayRate, opDoubleDayRateBadge, recalcOperatorTotals);
    wireRateSchemeListeners(opDoubleNightMinutes, opDoubleNightMinRate, opDoubleNightRate, opDoubleNightRateBadge, recalcOperatorTotals);

    function buildOpMultiSizeTable(isDouble) {
        if (!opMultiSizeTbody) return;
        opMultiSizeTbody.innerHTML = '';
        const thDay = document.getElementById('thDaySizeQty');
        const thNight = document.getElementById('thNightSizeQty');
        if (thNight) {
            if (isDouble) {
                thNight.classList.remove('d-none');
                if (thDay) thDay.innerHTML = '<i class="bi bi-sun-fill text-warning me-1"></i> ☀️ Day Operator Pieces';
            } else {
                thNight.classList.add('d-none');
                if (thDay) thDay.innerHTML = '<i class="bi bi-person-badge me-1"></i> ☀️ Pieces Produced (تیار شدہ پیسز)';
            }
        }

        const sizes = currentOpLotData.sizes || [];
        sizes.forEach(sz => {
            const planned = parseInt(sz.planned_qty, 10) || 0;
            const half = isDouble ? Math.ceil(planned / 2) : planned;
            const rem = isDouble ? (planned - half) : 0;

            const tr = document.createElement('tr');
            if (isDouble) {
                tr.innerHTML = `
                    <td class="fw-bold">${sz.name} (${sz.code})</td>
                    <td class="fw-semibold text-muted">${planned} pcs</td>
                    <td>
                        <input type="number" name="day_sizes[${sz.id}]" class="form-control form-control-sm text-center fw-bold op-msize-day" data-planned="${planned}" value="${half}" min="0">
                    </td>
                    <td>
                        <input type="number" name="night_sizes[${sz.id}]" class="form-control form-control-sm text-center fw-bold op-msize-night" data-planned="${planned}" value="${rem}" min="0">
                    </td>
                    <td class="fw-bold text-primary op-msize-total">${planned}</td>
                `;
            } else {
                tr.innerHTML = `
                    <td class="fw-bold">${sz.name} (${sz.code})</td>
                    <td class="fw-semibold text-muted">${planned} pcs</td>
                    <td>
                        <input type="number" name="day_sizes[${sz.id}]" class="form-control form-control-sm text-center fw-bold op-msize-day" data-planned="${planned}" value="${half}" min="0">
                        <input type="hidden" name="night_sizes[${sz.id}]" class="op-msize-night" value="0">
                    </td>
                    <td class="fw-bold text-primary op-msize-total">${planned}</td>
                `;
            }
            opMultiSizeTbody.appendChild(tr);

            const dayInp = tr.querySelector('.op-msize-day');
            const nightInp = tr.querySelector('.op-msize-night');

            if (dayInp && isDouble && nightInp) {
                dayInp.addEventListener('input', function() {
                    const p = parseInt(this.dataset.planned, 10) || 0;
                    const d = parseInt(this.value, 10) || 0;
                    nightInp.value = Math.max(0, p - d);
                    tr.querySelector('.op-msize-total').textContent = d + parseInt(nightInp.value, 10);
                });
                nightInp.addEventListener('input', function() {
                    const p = parseInt(this.dataset.planned, 10) || 0;
                    const n = parseInt(this.value, 10) || 0;
                    dayInp.value = Math.max(0, p - n);
                    tr.querySelector('.op-msize-total').textContent = n + parseInt(dayInp.value, 10);
                });
            } else if (dayInp && !isDouble) {
                dayInp.addEventListener('input', function() {
                    const d = parseInt(this.value, 10) || 0;
                    tr.querySelector('.op-msize-total').textContent = d;
                });
            }
        });
    }

    // Initialize mode according to URL or default to Operator Initial Entry
    if (preselectedMode) {
        switchMode(preselectedMode);
    } else if (!preselectedOpId) {
        switchMode('operator_initial');
    }

    // Trigger lot load on page load if preselected lot exists
    if (lotSelect && lotSelect.value) {
        loadLotData(lotSelect.value);
    }

    // Intercept form submission for validation
    document.getElementById('prodEntryForm').addEventListener('submit', function(e) {
        const mode = document.getElementById('pe_entry_mode').value;

        if (mode === 'operator_initial') {
            const lotVal = opLotSelect ? opLotSelect.value : '';
            if (!lotVal) {
                e.preventDefault();
                alert('Please select a production lot first.');
                opLotSelect.focus();
                return;
            }
            const shiftType = document.getElementById('op_shift_type').value;
            if (shiftType === 'Double Shift') {
                const dayW = opDoubleDayWorker.value;
                const nightW = opDoubleNightWorker.value;
                if (!dayW || !nightW) {
                    e.preventDefault();
                    alert('Please select both Day Operator and Night Operator for Double Shift lot.');
                    return;
                }
            } else {
                const dayW = opSingleWorker.value;
                if (!dayW) {
                    e.preventDefault();
                    alert('Please select Day Operator.');
                    opSingleWorker.focus();
                    return;
                }
            }
            return;
        }

        if (mode === 'cutting') {
            const cutWorker = document.getElementById('cut_worker_id');
            if (!cutWorker || !cutWorker.value) {
                e.preventDefault();
                alert('Please select a Cutting Master before saving.');
                if (cutWorker) cutWorker.focus();
                return;
            }
            const whole = parseInt(cutWholePcs ? cutWholePcs.value : 0) || 0;
            if (whole <= 0) {
                e.preventDefault();
                alert('Please enter at least 1 cut piece before saving, or click "⚡ 1-Click Fill Ready".');
                if (cutWholePcs) cutWholePcs.focus();
                return;
            }
            const maxWhole = getMaxWholeReadyToCut();
            if (maxWhole > 0 && whole > maxWhole) {
                e.preventDefault();
                alert('کل کٹنگ تعداد (' + whole + ' pcs) آپریٹر کے تیار کردہ پیسز (' + maxWhole + ' pcs) سے زیادہ درج نہیں ہو سکتی۔');
                if (cutWholePcs) cutWholePcs.focus();
                return;
            }

            let elExceeded = false;
            document.querySelectorAll('.cut-el-input').forEach(inp => {
                const val = parseInt(inp.value, 10) || 0;
                const max = parseInt(inp.max, 10);
                if (!isNaN(max) && val > max) {
                    elExceeded = true;
                    inp.classList.add('is-invalid');
                }
            });
            if (elExceeded) {
                e.preventDefault();
                alert('کچھ اعضاء کی کٹنگ تعداد آپریٹر کے تیار کردہ پیسز سے زیادہ ہے۔ برائے مہربانی درست کریں۔');
                return;
            }
            return;
        }

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
